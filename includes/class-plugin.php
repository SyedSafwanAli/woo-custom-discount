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

		if ( is_admin() ) {
			Admin::init();
		}

		// Price writing, filters and countdowns each wait for their own switch.
		// Nothing below runs on a fresh install.
		if ( self::engine_can_run() ) {
			// Increment 2 wires the resolver and the writing side here.
			do_action( 'wcd_engine_ready' );
		}

		if ( Settings::is_on( 'filters_enabled' ) ) {
			do_action( 'wcd_filters_ready' );
		}

		if ( Settings::is_on( 'countdown_enabled' ) ) {
			do_action( 'wcd_countdown_ready' );
		}
	}

	/**
	 * WooCommerce has to be there before any of this makes sense.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
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
