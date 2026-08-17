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
 *
 * Prices go into WooCommerce's own `_sale_price` rather than being calculated
 * on the fly. That is what makes sorting by price correct, keeps product feeds
 * honest, and lets page caching work normally.
 */
class Price_Engine {

	/** Marker: this sale price was written by us, so we may change or clear it. */
	public const META_OWNED = '_wcd_owns_sale_price';

	/** Whatever sat in _sale_price before we touched it. */
	public const META_PREVIOUS = '_wcd_prev_sale_price';

	/** Effective discount percent. The discount filter and sorting read this. */
	public const META_PERCENT = '_wcd_discount_percent';

	/** Expiry as YYYYMM. The expiry filter, sorting and hiding read this. */
	public const META_EXPIRY = '_wcd_expiry_ym';

	/** Which rule produced the current price. */
	public const META_RULE = '_wcd_rule_id';

	/**
	 * Product types the engine will write a sale price for.
	 *
	 * Only simple products. Variable products keep their prices on each
	 * variation, and the store's three variable products were deliberately left
	 * out of the discount scheme — so the engine reports them as skipped
	 * instead of guessing.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_TYPES = array( 'simple' );

	/**
	 * True while this class is in the middle of saving a product.
	 *
	 * Saving a product fires `woocommerce_update_product`, which is how the
	 * engine keeps sale prices in step when someone edits a regular price. But
	 * that means our own save re-enters the engine, and a re-entrant pass would
	 * read the price we just wrote and record it as the product's *original*
	 * price — destroying the very value that makes deactivation reversible.
	 *
	 * So writes are wrapped in this flag, and the save hook stands down while it
	 * is set.
	 */
	private static bool $busy = false;

	/**
	 * Whether a write is in progress, so hooks can stand down.
	 */
	public static function is_busy(): bool {
		return self::$busy;
	}

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
	 * Every published product the engine considers.
	 *
	 * @return int[]
	 */
	public static function target_product_ids(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE post_type = 'product'
			    AND post_status IN ('publish','private')
			  ORDER BY ID ASC"
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * What the engine would do to one product, without changing anything.
	 *
	 * This is what the preview screen renders. It has to be side-effect free:
	 * the whole point is to look before anything is touched.
	 *
	 * @return array{
	 *     product_id:int,
	 *     name:string,
	 *     type:string,
	 *     status:string,
	 *     regular:float,
	 *     new_price:float,
	 *     percent:float,
	 *     rule_id:int,
	 *     rule_title:string,
	 *     expiry_ym:?string
	 * }
	 */
	public static function plan_product( int $product_id ): array {
		$plan = array(
			'product_id' => $product_id,
			'name'       => '',
			'type'       => '',
			'status'     => 'missing',
			'regular'    => 0.0,
			'new_price'  => 0.0,
			'percent'    => 0.0,
			'rule_id'    => 0,
			'rule_title' => '',
			'expiry_ym'  => null,
		);

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return $plan;
		}

		$plan['name'] = $product->get_name();
		$plan['type'] = $product->get_type();

		// Expiry comes from batch membership, not from the resolved discount.
		// An expired batch yields no discount but the product still needs its
		// expiry recorded, otherwise the hiding logic would lose track of it.
		$batch = Resolver::batch_for( $product_id );

		if ( $batch !== null && ! empty( $batch['expiry_ym'] ) ) {
			$plan['expiry_ym'] = (string) $batch['expiry_ym'];
		}

		if ( ! in_array( $product->get_type(), self::SUPPORTED_TYPES, true ) ) {
			$plan['status'] = 'skipped_type';

			return $plan;
		}

		$regular         = (float) $product->get_regular_price();
		$plan['regular'] = $regular;

		if ( $regular <= 0 ) {
			$plan['status'] = 'no_regular_price';

			return $plan;
		}

		$outcome = Resolver::resolve( $product_id );

		if ( $outcome === null || $outcome['percent'] <= 0 ) {
			$plan['status'] = 'no_discount';

			return $plan;
		}

		$rule = Rules::get( $outcome['rule_id'] );

		$plan['status']     = 'discount';
		$plan['percent']    = $outcome['percent'];
		$plan['new_price']  = self::discounted_price( $regular, $outcome['percent'] );
		$plan['rule_id']    = $outcome['rule_id'];
		$plan['rule_title'] = $rule ? (string) $rule['title'] : '';

		return $plan;
	}

	/**
	 * Applies the resolved discount to one product, or clears ours if it no
	 * longer qualifies.
	 *
	 * @return string One of: discount, cleared, skipped, unchanged.
	 */
	public static function apply_product( int $product_id ): string {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return 'skipped';
		}

		$owned = get_post_meta( $product_id, self::META_OWNED, true ) === '1';

		if ( ! in_array( $product->get_type(), self::SUPPORTED_TYPES, true ) ) {
			// If we somehow own a price on an unsupported type, hand it back.
			return $owned ? ( self::clear_product( $product_id ) ? 'cleared' : 'skipped' ) : 'skipped';
		}

		$regular = (float) $product->get_regular_price();
		$outcome = Resolver::resolve( $product_id );

		// Expiry is recorded from batch membership regardless of whether the
		// batch still yields a discount, so hiding keeps working after expiry.
		self::sync_expiry_meta( $product_id );

		if ( $regular <= 0 || $outcome === null || $outcome['percent'] <= 0 ) {
			if ( $owned ) {
				self::clear_product( $product_id, false );

				return 'cleared';
			}

			delete_post_meta( $product_id, self::META_PERCENT );
			delete_post_meta( $product_id, self::META_RULE );

			return 'skipped';
		}

		$new_price = self::discounted_price( $regular, $outcome['percent'] );
		$current   = (string) $product->get_sale_price();

		// Remember the original sale price the first time we take over, and
		// claim ownership *before* saving — a re-entrant pass must find the
		// marker already in place rather than mistake our price for the
		// original one.
		if ( ! $owned ) {
			update_post_meta( $product_id, self::META_PREVIOUS, $current );
		}

		update_post_meta( $product_id, self::META_OWNED, '1' );
		update_post_meta( $product_id, self::META_PERCENT, (string) round( $outcome['percent'], 2 ) );
		update_post_meta( $product_id, self::META_RULE, (string) $outcome['rule_id'] );

		$changed = $current === '' || abs( (float) $current - $new_price ) > 0.0001;

		$product->set_sale_price( (string) $new_price );
		$product->set_date_on_sale_from( null );

		// Handing the end date to WooCommerce means it expires the sale itself,
		// on the same moment the countdown reaches zero.
		$product->set_date_on_sale_to( $outcome['ends_at'] ? (string) $outcome['ends_at'] : null );

		self::save( $product );

		return $changed ? 'discount' : 'unchanged';
	}

	/**
	 * Runs the engine across the whole catalogue.
	 *
	 * @return array<string,int> Counts keyed by outcome.
	 */
	public static function apply_all(): array {
		Resolver::flush();

		$stats = array(
			'discount'  => 0,
			'unchanged' => 0,
			'cleared'   => 0,
			'skipped'   => 0,
		);

		foreach ( array_chunk( self::target_product_ids(), 50 ) as $chunk ) {
			foreach ( $chunk as $product_id ) {
				$result = self::apply_product( $product_id );

				if ( isset( $stats[ $result ] ) ) {
					++$stats[ $result ];
				}
			}
		}

		self::schedule_rule_endings();

		// Prices moved, so every cached filter count is now suspect, and the
		// slider's ends are worked out from the cheapest and dearest price.
		Filter_Query::bump_counts_version();
		Buckets::flush_price_bounds();

		Install::flush_caches();

		return $stats;
	}

	/**
	 * Records a product's expiry month, taken from its batch.
	 */
	public static function sync_expiry_meta( int $product_id ): void {
		$batch = Resolver::batch_for( $product_id );

		if ( $batch !== null && ! empty( $batch['expiry_ym'] ) ) {
			update_post_meta( $product_id, self::META_EXPIRY, (string) $batch['expiry_ym'] );

			return;
		}

		delete_post_meta( $product_id, self::META_EXPIRY );
	}

	/**
	 * Books a one-off job at the exact moment each active rule ends.
	 *
	 * WooCommerce clears expired sale prices once a day, which would leave a
	 * discount live for up to 24 hours after its countdown hit zero. Scheduling
	 * the moment itself closes that gap.
	 */
	public static function schedule_rule_endings(): void {
		$now = time();

		foreach ( array( Rules::TYPE_CAMPAIGN, Rules::TYPE_BATCH ) as $type ) {
			foreach ( Rules::query( array( 'type' => $type, 'enabled' => true ) ) as $rule ) {
				$ends = $type === Rules::TYPE_BATCH
					? Rules::expiry_end_timestamp( (string) $rule['expiry_ym'] )
					: Resolver::campaign_end_timestamp( $rule );

				if ( $ends === null || $ends <= $now ) {
					continue;
				}

				$args = array( $rule['id'] );

				if ( ! wp_next_scheduled( 'wcd_rule_ended', $args ) ) {
					wp_schedule_single_event( $ends + 5, 'wcd_rule_ended', $args );
				}
			}
		}
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

		Filter_Query::bump_counts_version();
		Buckets::flush_price_bounds();

		Install::flush_caches();

		return $cleared;
	}

	/**
	 * Restores one product to the price it had before this plugin touched it.
	 *
	 * @param int  $product_id   Product or variation ID.
	 * @param bool $drop_expiry  Whether to remove the expiry field too. The
	 *                           engine keeps it while a product is still in a
	 *                           batch; a full clear removes everything.
	 * @return bool True when the product was found and restored.
	 */
	public static function clear_product( int $product_id, bool $drop_expiry = true ): bool {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			// The product is gone; clean up the leftover meta so it is not
			// counted again on the next pass.
			self::delete_our_meta( $product_id, true );

			return false;
		}

		$previous = get_post_meta( $product_id, self::META_PREVIOUS, true );

		// Release ownership before saving, for the same reason we claim it
		// before saving: the save hook must not find a half-finished state.
		self::delete_our_meta( $product_id, $drop_expiry );

		// An empty string is a real value here: it means "there was no sale
		// price before we arrived", which is exactly what we restore.
		$product->set_sale_price( $previous === '' || $previous === null ? '' : (string) $previous );
		$product->set_date_on_sale_from( null );
		$product->set_date_on_sale_to( null );

		self::save( $product );

		return true;
	}

	/**
	 * Saves a product with the re-entrancy flag raised.
	 *
	 * @param \WC_Product $product Product to save.
	 */
	private static function save( $product ): void {
		self::$busy = true;

		try {
			$product->save();
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Drops the meta this plugin owns for one product.
	 */
	private static function delete_our_meta( int $product_id, bool $drop_expiry ): void {
		$keys = array( self::META_OWNED, self::META_PREVIOUS, self::META_PERCENT, self::META_RULE );

		if ( $drop_expiry ) {
			$keys[] = self::META_EXPIRY;
		}

		foreach ( $keys as $key ) {
			delete_post_meta( $product_id, $key );
		}
	}
}
