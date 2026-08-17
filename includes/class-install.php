<?php
/**
 * Activation, deactivation and database schema.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin tables on activation and cleans up prices on deactivation.
 */
class Install {

	/**
	 * Bumped whenever the schema changes, so upgrades can run dbDelta again.
	 */
	public const DB_VERSION = 1;

	/**
	 * Table name without prefix => rules (campaigns and expiry batches).
	 */
	public const TABLE_RULES = 'wcd_rules';

	/**
	 * Table name without prefix => which products/categories belong to a rule.
	 */
	public const TABLE_ITEMS = 'wcd_rule_items';

	/**
	 * Fully qualified table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Runs on activation. Creates tables and seeds safe defaults.
	 *
	 * Every feature is deliberately left OFF here. Activating the plugin must
	 * never change a single price or hide a single product until the store
	 * owner explicitly turns the engine on.
	 */
	public static function activate(): void {
		self::create_tables();

		Settings::seed_defaults();

		update_option( 'wcd_db_version', self::DB_VERSION, false );
		update_option( 'wcd_version', WCD_VERSION, false );

		if ( ! get_option( 'wcd_activated_at' ) ) {
			update_option( 'wcd_activated_at', time(), false );
		}

		// A nightly pass catches anything the exact-moment jobs missed, such as
		// a rule that ended while the site had no traffic to fire cron.
		if ( ! wp_next_scheduled( 'wcd_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wcd_daily_maintenance' );
		}
	}

	/**
	 * Runs on deactivation.
	 *
	 * Removes every sale price this plugin wrote, restoring whatever was there
	 * before, so the store falls back to original prices. Rules and settings are
	 * left untouched — reactivating re-applies everything automatically.
	 */
	public static function deactivate(): void {
		// Only meaningful if WooCommerce is present. If it is not, there is
		// nothing we could have written in the first place.
		if ( function_exists( 'wc_get_product' ) ) {
			Price_Engine::clear_all();
		}

		wp_clear_scheduled_hook( 'wcd_daily_maintenance' );

		self::flush_caches();
	}

	/**
	 * Runs the schema through dbDelta. Safe to call repeatedly.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$rules   = self::table( self::TABLE_RULES );
		$items   = self::table( self::TABLE_ITEMS );

		// `type` separates the two things the plugin knows about:
		//   campaign -> an ordinary discount, never hides products
		//   batch    -> an expiry batch, hides its products once expired
		$sql_rules = "CREATE TABLE {$rules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'campaign',
			title varchar(191) NOT NULL DEFAULT '',
			enabled tinyint(1) NOT NULL DEFAULT 0,
			discount_percent decimal(5,2) NOT NULL DEFAULT 0.00,
			scope varchar(20) NOT NULL DEFAULT 'products',
			expiry_ym char(6) DEFAULT NULL,
			ends_at datetime DEFAULT NULL,
			countdown_enabled tinyint(1) NOT NULL DEFAULT 0,
			priority int(11) NOT NULL DEFAULT 10,
			source varchar(40) NOT NULL DEFAULT 'manual',
			notes text NULL,
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			date_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY type_enabled (type,enabled),
			KEY expiry_ym (expiry_ym),
			KEY priority (priority)
		) {$charset};";

		$sql_items = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			item_type varchar(20) NOT NULL DEFAULT 'product',
			item_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rule_item (rule_id,item_type,item_id),
			KEY item_lookup (item_type,item_id)
		) {$charset};";

		dbDelta( $sql_rules );
		dbDelta( $sql_items );
	}

	/**
	 * True when both tables exist. Used to show an admin notice if activation
	 * somehow failed, rather than throwing SQL errors on every page.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::TABLE_RULES, self::TABLE_ITEMS ) as $name ) {
			$table = self::table( $name );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clears WooCommerce and page caches so price changes are visible at once.
	 * LiteSpeed on the live server needs the explicit purge action.
	 */
	public static function flush_caches(): void {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			delete_transient( 'wc_products_onsale' );
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' );

		// WP Super Cache / W3TC style hook, harmless if nothing listens.
		do_action( 'wcd_flush_caches' );
	}
}
