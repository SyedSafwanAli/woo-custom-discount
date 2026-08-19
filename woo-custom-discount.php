<?php
/**
 * Plugin Name:       Woo Custom Discount
 * Plugin URI:        https://github.com/SyedSafwanAli/woo-custom-discount
 * Description:       Discounts, expiry batches, shop filters and countdowns for importedvitamins.com - in one plugin, with no dependency on third-party discount plugins.
 * Version:           0.16.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Syed Safwan Ali
 * License:           GPL-2.0-or-later
 * Text Domain:       woo-custom-discount
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'WCD_VERSION', '0.16.0' );
define( 'WCD_FILE', __FILE__ );
define( 'WCD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCD_URL', plugin_dir_url( __FILE__ ) );
define( 'WCD_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader. WCD\Price_Engine -> includes/class-price-engine.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'WCD\\' ) ) {
			return;
		}

		$name = substr( $class, 4 );
		$file = WCD_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'WCD\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCD\Install', 'deactivate' ) );

/**
 * Declare compatibility with WooCommerce HPOS so WooCommerce does not warn.
 * The plugin never touches order tables, so this is safe either way.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCD_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'WCD\Plugin', 'boot' ), 20 );
