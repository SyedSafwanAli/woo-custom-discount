<?php
/**
 * Plugin settings, stored in a single option.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings.
 *
 * Everything that could change what a customer sees defaults to off. The store
 * owner turns each piece on deliberately, one at a time.
 */
class Settings {

	public const OPTION = 'wcd_settings';

	/**
	 * Cached settings for this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Safe defaults. Read the "false" values as "does nothing yet".
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Master switches. All off on a fresh install.
			'engine_enabled'    => false,
			'filters_enabled'   => false,
			'countdown_enabled' => false,
			'hide_expired'      => false,

			// How a discounted price is rounded. 'down' always favours the customer.
			'rounding'          => 'down',

			// Which filter groups appear, in this order.
			//
			// 'sort' is left out on purpose: the shop's own sort dropdown now
			// carries the two new orderings, so a second sort control in the
			// panel would only duplicate it. Add it back for a standalone
			// shortcode placement where no dropdown exists.
			'filter_groups'     => array( 'discount', 'expiry', 'category', 'price', 'stock' ),

			// Discount buckets. Empty until the owner creates them, or the
			// importer suggests a set based on the real discounts in the store.
			'discount_buckets'  => array(),

			// Expiry months offered in the filter, as YYYYMM strings.
			'expiry_months'     => array(),

			// How price is filtered.
			//   slider  a two-handled range the shopper sets themselves
			//   bands    fixed bands, configured below
			'price_mode'        => 'slider',

			// Price buckets as [min, max]; max of 0 means "and above".
			// Used in bands mode, and as the no-JavaScript fallback for slider
			// mode — a continuous range cannot be expressed as a plain link.
			'price_buckets'     => array(),

			// Product categories allowed in the category filter, as term IDs.
			// Empty means "none chosen yet" — the filter group stays hidden.
			'filter_categories' => array(),

			// Where the countdown sits on a shop grid card.
			//   overlay  across the bottom of the product image
			//   below    under the price, as a separate block
			'countdown_in_loop' => 'overlay',

			// A "Save Rs 2,697" chip under the price. Off by default because
			// this shop already writes the saving into its product images.
			'show_savings'      => false,

			// Show a product count beside each filter option.
			'show_counts'       => true,

			// Hide filter options that currently match nothing.
			'hide_empty'        => true,

			// Where the filter is injected on the shop page.
			// none | above_grid
			'filter_position'   => 'none',

			// How the filter presents itself.
			//   drawer  a button that slides a panel in from the right
			//   panel   always-open, for a sidebar column
			//   auto    panel on wide screens, drawer on narrow ones
			'filter_display'    => 'drawer',

			// Which side the trigger button sits on: left | center | right.
			// Right suits a row that already has a heading on the left.
			'filter_align'      => 'left',

			// Keep rules and settings when the plugin is deleted.
			'purge_on_uninstall' => false,
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( self::$cache === null ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Convenience wrapper for the boolean switches.
	 */
	public static function is_on( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * Writes one or more settings.
	 *
	 * @param array<string,mixed> $values Values to merge in.
	 */
	public static function update( array $values ): void {
		$settings = array_merge( self::all(), $values );

		update_option( self::OPTION, $settings, false );

		self::$cache = $settings;
	}

	/**
	 * Writes the defaults on activation without overwriting an existing config.
	 */
	public static function seed_defaults(): void {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			update_option( self::OPTION, self::defaults(), false );
			self::$cache = null;
		}
	}

	/**
	 * Forgets the request cache. Used after an import writes settings directly.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}
