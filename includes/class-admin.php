<?php
/**
 * Admin menu and the status screen.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin screens.
 *
 * Only the status screen is built out so far. It exists first on purpose: the
 * first thing to verify on the live site is that the plugin is installed and
 * doing nothing at all.
 */
class Admin {

	public const CAPABILITY = 'manage_woocommerce';
	public const SLUG       = 'wcd-status';

	/**
	 * Hooks the admin pieces.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'conflict_notice' ) );
		add_filter( 'plugin_action_links_' . WCD_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the menu under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Custom Discount', 'woo-custom-discount' ),
			__( 'Custom Discount', 'woo-custom-discount' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_status' )
		);
	}

	/**
	 * Adds a settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Status', 'woo-custom-discount' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Warns when a conflicting discount plugin is active while our engine is on.
	 *
	 * This is the guard that keeps the live switchover safe. If both plugins are
	 * running, the other one would apply its percentage on top of the sale price
	 * we wrote, so the engine parks itself and says so loudly.
	 */
	public static function conflict_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$conflicts = Plugin::active_conflicts();

		if ( $conflicts === array() || ! Settings::is_on( 'engine_enabled' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Woo Custom Discount: the price engine is switched on but parked.', 'woo-custom-discount' );
		echo '</strong></p><p>';
		printf(
			/* translators: %s: list of conflicting plugin files. */
			esc_html__( 'Another discount plugin is still active (%s). Running both would apply a second discount on top of ours, so no prices have been changed. Deactivate the other plugin to let the engine run.', 'woo-custom-discount' ),
			'<code>' . esc_html( implode( '</code>, <code>', $conflicts ) ) . '</code>'
		);
		echo '</p></div>';
	}

	/**
	 * The status screen: what is on, what is off, and what has been touched.
	 */
	public static function render_status(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-custom-discount' ) );
		}

		$counts    = Rules::counts();
		$conflicts = Plugin::active_conflicts();
		$owned     = count( Price_Engine::owned_product_ids() );

		$switches = array(
			'engine_enabled'    => __( 'Price engine', 'woo-custom-discount' ),
			'filters_enabled'   => __( 'Shop filters', 'woo-custom-discount' ),
			'countdown_enabled' => __( 'Countdowns', 'woo-custom-discount' ),
			'hide_expired'      => __( 'Hide expired products', 'woo-custom-discount' ),
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Woo Custom Discount', 'woo-custom-discount' ) . '</h1>';

		echo '<p class="description">';
		printf(
			/* translators: %s: plugin version. */
			esc_html__( 'Version %s. Nothing is applied to the store until a switch below is turned on.', 'woo-custom-discount' ),
			esc_html( WCD_VERSION )
		);
		echo '</p>';

		// --- Feature switches ------------------------------------------------
		echo '<h2>' . esc_html__( 'Features', 'woo-custom-discount' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px">';
		echo '<thead><tr><th>' . esc_html__( 'Feature', 'woo-custom-discount' ) . '</th><th>' . esc_html__( 'State', 'woo-custom-discount' ) . '</th></tr></thead><tbody>';

		foreach ( $switches as $key => $label ) {
			$on = Settings::is_on( $key );

			echo '<tr><td>' . esc_html( $label ) . '</td><td>';
			echo $on
				? '<span style="color:#0b6e63;font-weight:600">' . esc_html__( 'On', 'woo-custom-discount' ) . '</span>'
				: '<span style="color:#777">' . esc_html__( 'Off', 'woo-custom-discount' ) . '</span>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		// --- Health ----------------------------------------------------------
		echo '<h2>' . esc_html__( 'Health', 'woo-custom-discount' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';

		self::row(
			__( 'Database tables', 'woo-custom-discount' ),
			Install::tables_exist()
				? __( 'Created', 'woo-custom-discount' )
				: __( 'Missing — deactivate and activate the plugin again', 'woo-custom-discount' ),
			Install::tables_exist()
		);

		self::row(
			__( 'Conflicting discount plugin', 'woo-custom-discount' ),
			$conflicts === array()
				? __( 'None active', 'woo-custom-discount' )
				: implode( ', ', $conflicts ),
			$conflicts === array()
		);

		self::row(
			__( 'Engine allowed to run', 'woo-custom-discount' ),
			Plugin::engine_can_run()
				? __( 'Yes', 'woo-custom-discount' )
				: __( 'No — switched off, or a conflicting plugin is active', 'woo-custom-discount' ),
			Plugin::engine_can_run()
		);

		self::row(
			__( 'Campaigns', 'woo-custom-discount' ),
			(string) $counts[ Rules::TYPE_CAMPAIGN ],
			null
		);

		self::row(
			__( 'Expiry batches', 'woo-custom-discount' ),
			(string) $counts[ Rules::TYPE_BATCH ],
			null
		);

		self::row(
			__( 'Products with a sale price we own', 'woo-custom-discount' ),
			(string) $owned,
			null
		);

		self::row(
			__( 'Site timezone', 'woo-custom-discount' ),
			self::timezone_label(),
			null
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Next', 'woo-custom-discount' ) . '</h2>';
		echo '<p>' . esc_html__( 'Campaigns, expiry batches, the importer, filters and countdowns are being built. This screen will always show what the plugin is currently doing to the store.', 'woo-custom-discount' ) . '</p>';

		echo '</div>';
	}

	/**
	 * One row of the health table.
	 *
	 * @param string    $label Row label.
	 * @param string    $value Row value.
	 * @param bool|null $good  True/false to colour it, null to leave plain.
	 */
	private static function row( string $label, string $value, ?bool $good ): void {
		$colour = '';

		if ( $good === true ) {
			$colour = 'color:#0b6e63;font-weight:600';
		} elseif ( $good === false ) {
			$colour = 'color:#a02a21;font-weight:600';
		}

		echo '<tr><td style="width:52%">' . esc_html( $label ) . '</td>';
		echo '<td><span style="' . esc_attr( $colour ) . '">' . esc_html( $value ) . '</span></td></tr>';
	}

	/**
	 * A readable timezone, with a nudge when the store is still on UTC.
	 */
	private static function timezone_label(): string {
		$tz  = wp_timezone_string();
		$now = wp_date( 'd M Y, H:i' );

		$label = sprintf( '%s — %s', $tz, (string) $now );

		if ( in_array( $tz, array( 'UTC', '+00:00' ), true ) ) {
			$label .= ' ' . __( '(store sells in PKR; Pakistan is UTC+5, so sale end times will be 5 hours late)', 'woo-custom-discount' );
		}

		return $label;
	}
}
