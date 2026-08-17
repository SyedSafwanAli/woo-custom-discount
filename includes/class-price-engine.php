<?php
/**
 * Writes and clears the sale prices this plugin owns.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every price this plugin puts into the database.
 *
 * Two rules govern this class, and nothing here may break them:
 *
 *  1. It only ever touches a sale price it wrote itself. A sale price the shop
 *     owner set by hand carries no marker, so it is left completely alone.
 *  2. Before overwriting anything, it stores what was there, so the original
 *     value can always be put back.
 */
class Price_Engine {

	/** Marker: this sale price was written by us, so we may change or clear it. */
	public const META_OWNED = '_wcd_owns_sale_price';

	/** Whatever sat in _sale_price before we touched it. */
	public const META_PREVIOUS = '_wcd_prev_sale_price';

	/** Effective discount percent. The discount filter and sorting read this. */
	public const META_PERCENT = '_wcd_discount_percent';

	/** Expiry as YYYYMM. The expiry filter and sorting read this. */
	public const META_EXPIRY = '_wcd_expiry_ym';

	/** Which rule produced the current price. */
	public const META_RULE = '_wcd_rule_id';

	/**
	 * Rounds a discounted price according to the configured rounding mode.
	 *
	 * The store sells in PKR with no decimals, so a 15% discount on 6,995
	 * lands on 5,945.75. Rounding down gives 5,945 — always in the customer's
	 * favour, and never a price ending in a fraction of a rupee.
	 */
	public static function round_price( float $price ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$mode     = (string) Settings::get( 'rounding', 'down' );
		$factor   = 10 ** $decimals;

		$rounded = match ( $mode ) {
			'up'    => ceil( $price * $factor ) / $factor,
			'near'  => round( $price * $factor ) / $factor,
			default => floor( $price * $factor ) / $factor,
		};

		return max( 0.0, (float) $rounded );
	}

	/**
	 * Applies a percentage to a regular price and returns the rounded result.
	 */
	public static function discounted_price( float $regular, float $percent ): float {
		if ( $regular <= 0 || $percent <= 0 ) {
			return 0.0;
		}

		$percent = min( $percent, 100.0 );

		return self::round_price( $regular * ( 1 - ( $percent / 100 ) ) );
	}

	/**
	 * Every product ID currently carrying our marker.
	 *
	 * @return int[]
	 */
	public static function owned_product_ids(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				self::META_OWNED
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Removes every sale price this plugin wrote, restoring the previous value
	 * where there was one, and drops the fields the filters read.
	 *
	 * Called on deactivation and from the "Remove all discounts" button.
	 *
	 * @return int Number of products restored.
	 */
	public static function clear_all(): int {
		$ids     = self::owned_product_ids();
		$cleared = 0;

		// Chunked so a store far larger than this one could not time out.
		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			foreach ( $chunk as $product_id ) {
				if ( self::clear_product( $product_id ) ) {
					++$cleared;
				}
			}
		}

		Install::flush_caches();

		return $cleared;
	}

	/**
	 * Restores one product to the price it had before this plugin touched it.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool True when the product was found and restored.
	 */
	public static function clear_product( int $product_id ): bool {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			// The product is gone; clean up the leftover meta so it is not
			// counted again on the next pass.
			self::delete_our_meta( $product_id );

			return false;
		}

		$previous = get_post_meta( $product_id, self::META_PREVIOUS, true );

		// An empty string is a real value here: it means "there was no sale
		// price before we arrived", which is exactly what we restore.
		$product->set_sale_price( $previous === '' || $previous === null ? '' : (string) $previous );
		$product->set_date_on_sale_from( null );
		$product->set_date_on_sale_to( null );
		$product->save();

		self::delete_our_meta( $product_id );

		return true;
	}

	/**
	 * Drops all meta this plugin owns for one product.
	 */
	private static function delete_our_meta( int $product_id ): void {
		foreach ( array( self::META_OWNED, self::META_PREVIOUS, self::META_PERCENT, self::META_EXPIRY, self::META_RULE ) as $key ) {
			delete_post_meta( $product_id, $key );
		}
	}
}
