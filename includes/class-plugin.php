<?php
/**
 * Bootstrap. Decides what actually runs.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the pieces the settings have switched on, and refuses to touch prices
 * while a conflicting discount plugin is still active.
 */
class Plugin {

	/**
	 * Plugins that also rewrite product prices at runtime. Running one of these
	 * alongside our engine would stack a second discount on top of the sale
	 * price we wrote, so the engine stays parked until they are gone.
	 *
	 * @var string[]
	 */
	private const CONFLICTING_PLUGINS = array(
		'woo-discount-rules/woo-discount-rules.php',
	);

	/**
	 * Entry point, hooked on plugins_loaded.
	 */
	public static function boot(): void {
		if ( ! self::woocommerce_active() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_woocommerce' ) );

			return;
		}

		load_plugin_textdomain( 'woo-custom-discount', false, dirname( WCD_BASENAME ) . '/languages' );

		self::maybe_upgrade();
		self::hook_maintenance();

		if ( is_admin() ) {
			Admin::init();
		}

		// Each feature waits for its own switch. Nothing below runs on a fresh
		// install, so putting the plugin on a live site changes nothing until
		// somebody deliberately turns a switch on.
		if ( self::engine_can_run() ) {
			self::hook_engine();
		}

		if ( Settings::is_on( 'hide_expired' ) ) {
			Expiry::init();
		}

		if ( Settings::is_on( 'filters_enabled' ) ) {
			Filter_Query::init();
			Filter_UI::init();
		}

		if ( Settings::is_on( 'countdown_enabled' ) ) {
			Countdown::init();
		}
	}

	/**
	 * WooCommerce has to be there before any of this makes sense.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the page being viewed places a given shortcode itself.
	 *
	 * Anything this plugin adds automatically has to stand down when it has been
	 * placed by hand, or it appears twice. Two places to look: the post's own
	 * content, where Divi keeps a page's layout, and the Theme Builder template
	 * covering the request, which is a separate post entirely.
	 *
	 * @param string $tag Shortcode tag, without brackets.
	 */
	public static function page_has_shortcode( string $tag ): bool {
		static $cache = array();

		if ( isset( $cache[ $tag ] ) ) {
			return $cache[ $tag ];
		}

		$found = false;
		$post  = get_post();

		if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, $tag ) ) {
			$found = true;
		}

		if ( ! $found && function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			$layouts = et_theme_builder_get_template_layouts();

			if ( is_array( $layouts ) ) {
				foreach ( $layouts as $layout ) {
					if ( ! is_array( $layout ) || empty( $layout['id'] ) ) {
						continue;
					}

					$content = (string) get_post_field( 'post_content', (int) $layout['id'] );

					if ( $content !== '' && has_shortcode( $content, $tag ) ) {
						$found = true;

						break;
					}
				}
			}
		}

		$cache[ $tag ] = $found;

		return $found;
	}

	/**
	 * The engine runs only when it is switched on AND no conflicting discount
	 * plugin is active. The second half is the guard that makes the live
	 * switchover safe: even if someone reactivates the old plugin by mistake,
	 * prices cannot be discounted twice.
	 */
	public static function engine_can_run(): bool {
		if ( ! Settings::is_on( 'engine_enabled' ) ) {
			return false;
		}

		return self::active_conflicts() === array();
	}

	/**
	 * Conflicting plugins that are currently active.
	 *
	 * @return string[] Plugin basenames.
	 */
	public static function active_conflicts(): array {
		$active = array();

		foreach ( self::CONFLICTING_PLUGINS as $basename ) {
			if ( self::is_plugin_active( $basename ) ) {
				$active[] = $basename;
			}
		}

		return $active;
	}

	/**
	 * Keeps prices in step with the product catalogue.
	 */
	private static function hook_engine(): void {
		// A product's regular price can change at any time, and the sale price
		// has to follow it. Without this, editing 6,995 to 7,995 would leave
		// yesterday's discounted figure in place.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_saved' ), 20 );
	}

	/**
	 * Re-prices one product after it is saved.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function on_product_saved( $product_id ): void {
		// The engine saves products itself, and that save lands right back here.
		// Standing down while it is working is what stops a re-entrant pass from
		// mistaking a freshly written sale price for the product's original one.
		if ( Price_Engine::is_busy() ) {
			return;
		}

		Resolver::flush();
		Price_Engine::apply_product( (int) $product_id );
	}

	/**
	 * Scheduled jobs that keep prices and expiry honest over time.
	 */
	private static function hook_maintenance(): void {
		add_action( 'wcd_daily_maintenance', array( __CLASS__, 'run_maintenance' ) );
		add_action( 'wcd_rule_ended', array( __CLASS__, 'run_maintenance' ) );
	}

	/**
	 * Re-runs the engine and forgets the cached expiry list.
	 *
	 * Called once a day, and again at the exact moment any rule ends — that
	 * second job is what stops a discount outliving its own countdown.
	 */
	public static function run_maintenance(): void {
		Expiry::flush_cache();
		Resolver::flush();

		if ( self::engine_can_run() ) {
			Price_Engine::apply_all();
		}
	}

	/**
	 * is_plugin_active() lives in an admin-only file, so check the option
	 * directly and stay usable on the front end too.
	 */
	private static function is_plugin_active( string $basename ): bool {
		$active = (array) get_option( 'active_plugins', array() );

		if ( in_array( $basename, $active, true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );

			return isset( $network[ $basename ] );
		}

		return false;
	}

	/**
	 * Runs the schema again after an update that changed it.
	 */
	private static function maybe_upgrade(): void {
		$stored = (int) get_option( 'wcd_db_version', 0 );

		if ( $stored === Install::DB_VERSION ) {
			return;
		}

		Install::create_tables();
		update_option( 'wcd_db_version', Install::DB_VERSION, false );
		update_option( 'wcd_version', WCD_VERSION, false );
	}

	/**
	 * Shown when WooCommerce is missing.
	 */
	public static function notice_no_woocommerce(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Woo Custom Discount needs WooCommerce to be active. Nothing has been changed.', 'woo-custom-discount' );
		echo '</p></div>';
	}
}
