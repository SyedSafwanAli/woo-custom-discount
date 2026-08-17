<?php
/**
 * Runs when the plugin is deleted from WordPress.
 *
 * Rules and settings survive deletion unless the store owner explicitly ticked
 * "delete everything on uninstall". A mis-click on Delete should never destroy
 * the product lists someone spent hours building.
 *
 * Sale prices are a different matter: those are already cleared by the
 * deactivation hook, which always runs before deletion.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wcd_settings = get_option( 'wcd_settings', array() );

if ( ! is_array( $wcd_settings ) || empty( $wcd_settings['purge_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Product meta this plugin wrote. Deactivation normally clears these; this is
// the belt-and-braces pass for a site where the files were removed by hand.
$wcd_meta_keys = array(
	'_wcd_owns_sale_price',
	'_wcd_prev_sale_price',
	'_wcd_discount_percent',
	'_wcd_expiry_ym',
	'_wcd_rule_id',
);

foreach ( $wcd_meta_keys as $wcd_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $wcd_key ) );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcd_rule_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcd_rules" );

delete_option( 'wcd_settings' );
delete_option( 'wcd_db_version' );
delete_option( 'wcd_version' );
delete_option( 'wcd_activated_at' );
