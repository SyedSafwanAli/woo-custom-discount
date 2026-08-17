<?php
/**
 * Decides which rule applies to a product.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that answers "what discount does this product get?".
 *
 * The order below is the whole business logic, and nothing else in the plugin
 * is allowed to second-guess it:
 *
 *   1. In an expiry batch?      Batch percent. Stop.
 *   2. Own campaign?            That percent.
 *   3. Category campaign?       That percent.
 *   4. Store-wide campaign?     That percent.
 *   5. Otherwise                No discount.
 *
 * Discounts never stack. A product on 60% does not also collect the store-wide
 * 10% — it gets 60%, not 70% and not 64%. That matches how the store already
 * behaved before this plugin, so importing changes nothing a customer sees.
 */
class Resolver {

	/**
	 * Rules cache for this request, so a shop page of 12 products does not run
	 * the same queries 12 times.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private static ?array $rules_cache = null;

	/**
	 * Product IDs sitting in an enabled batch.
	 *
	 * @var array<int,true>|null
	 */
	private static ?array $batch_members = null;

	/**
	 * Resolved outcome per product for this request.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private static array $resolved = array();

	/**
	 * What applies to one product.
	 *
	 * @param int $product_id Product ID.
	 * @return array{
	 *     rule_id:int,
	 *     type:string,
	 *     percent:float,
	 *     expiry_ym:?string,
	 *     ends_at:?int,
	 *     countdown:bool
	 * }|null Null when the product gets no discount.
	 */
	public static function resolve( int $product_id ): ?array {
		if ( array_key_exists( $product_id, self::$resolved ) ) {
			return self::$resolved[ $product_id ];
		}

		$outcome = self::resolve_uncached( $product_id );

		self::$resolved[ $product_id ] = $outcome;

		return $outcome;
	}

	/**
	 * The actual decision, without the cache.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function resolve_uncached( int $product_id ): ?array {
		// --- 1. Expiry batch -------------------------------------------------
		// Batch membership is checked before anything else, and a product that
		// belongs to a batch is invisible to campaigns even when its batch has
		// already expired. Expired stock must not quietly fall back to the
		// store-wide discount — it should be pulled, not re-priced.
		if ( self::in_any_batch( $product_id ) ) {
			$batch = self::active_batch_for( $product_id );

			if ( $batch === null ) {
				return null;
			}

			return array(
				'rule_id'   => $batch['id'],
				'type'      => Rules::TYPE_BATCH,
				'percent'   => $batch['discount_percent'],
				'expiry_ym' => $batch['expiry_ym'] ? (string) $batch['expiry_ym'] : null,
				'ends_at'   => Rules::expiry_end_timestamp( (string) $batch['expiry_ym'] ),
				'countdown' => $batch['countdown_enabled'],
			);
		}

		// --- 2, 3, 4. Campaigns, most specific first -------------------------
		$term_ids = self::product_term_ids( $product_id );

		$levels = array(
			// Scope, and the test for "does this rule cover the product".
			array(
				Rules::SCOPE_PRODUCTS,
				static fn( array $rule ): bool => in_array( $product_id, $rule['products'], true ),
			),
			array(
				Rules::SCOPE_CATEGORIES,
				static fn( array $rule ): bool => (bool) array_intersect( $rule['categories'], $term_ids ),
			),
			array(
				Rules::SCOPE_ALL,
				static fn( array $rule ): bool => true,
			),
		);

		foreach ( $levels as [ $scope, $matches ] ) {
			$winner = null;

			foreach ( self::active_campaigns( $scope ) as $rule ) {
				// An exclusion beats the scope. The store keeps a handful of
				// products out of the blanket discount on purpose, and those
				// must stay at full price rather than picking it up here.
				if ( in_array( $product_id, $rule['excluded'], true ) ) {
					continue;
				}

				if ( ! $matches( $rule ) ) {
					continue;
				}

				// Two rules on the same level: the bigger discount wins. The
				// customer gets the better deal, and the outcome is easy to
				// predict without reading priority numbers.
				if ( $winner === null || $rule['discount_percent'] > $winner['discount_percent'] ) {
					$winner = $rule;
				}
			}

			if ( $winner !== null ) {
				return array(
					'rule_id'   => $winner['id'],
					'type'      => Rules::TYPE_CAMPAIGN,
					'percent'   => $winner['discount_percent'],
					'expiry_ym' => null,
					'ends_at'   => self::campaign_end_timestamp( $winner ),
					'countdown' => $winner['countdown_enabled'],
				);
			}
		}

		return null;
	}

	/**
	 * The batch a product belongs to and which has not expired yet.
	 *
	 * Only one batch per product is expected. If a product somehow sits in two,
	 * the one expiring soonest wins — the most urgent stock to move, and the
	 * safer figure to show a customer.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function active_batch_for( int $product_id ): ?array {
		$best = null;

		foreach ( self::rules( Rules::TYPE_BATCH ) as $rule ) {
			if ( ! in_array( $product_id, $rule['products'], true ) ) {
				continue;
			}

			if ( Rules::is_batch_expired( $rule ) ) {
				continue;
			}

			if ( $best === null || (string) $rule['expiry_ym'] < (string) $best['expiry_ym'] ) {
				$best = $rule;
			}
		}

		return $best;
	}

	/**
	 * The batch a product belongs to whether or not it has expired. Used by the
	 * hiding logic, which needs to know about expired batches specifically.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function batch_for( int $product_id ): ?array {
		$best = null;

		foreach ( self::rules( Rules::TYPE_BATCH ) as $rule ) {
			if ( ! in_array( $product_id, $rule['products'], true ) ) {
				continue;
			}

			if ( $best === null || (string) $rule['expiry_ym'] < (string) $best['expiry_ym'] ) {
				$best = $rule;
			}
		}

		return $best;
	}

	/**
	 * True when the product is in any enabled batch, expired or not.
	 */
	public static function in_any_batch( int $product_id ): bool {
		if ( self::$batch_members === null ) {
			self::$batch_members = array_fill_keys( Rules::batch_product_ids(), true );
		}

		return isset( self::$batch_members[ $product_id ] );
	}

	/**
	 * Enabled campaigns of one scope whose end date has not passed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_campaigns( string $scope ): array {
		$now = time();
		$out = array();

		foreach ( self::rules( Rules::TYPE_CAMPAIGN ) as $rule ) {
			if ( $rule['scope'] !== $scope ) {
				continue;
			}

			if ( $rule['discount_percent'] <= 0 ) {
				continue;
			}

			$ends = self::campaign_end_timestamp( $rule );

			if ( $ends !== null && $now > $ends ) {
				continue;
			}

			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * A campaign's end moment as a timestamp, or null when it never ends.
	 */
	public static function campaign_end_timestamp( array $rule ): ?int {
		if ( empty( $rule['ends_at'] ) ) {
			return null;
		}

		$stamp = strtotime( (string) $rule['ends_at'] . ' ' . wp_timezone_string() );

		return $stamp ?: null;
	}

	/**
	 * Enabled rules of one type, cached per request.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rules( string $type ): array {
		if ( self::$rules_cache === null ) {
			self::$rules_cache = array(
				Rules::TYPE_CAMPAIGN => Rules::query(
					array(
						'type'    => Rules::TYPE_CAMPAIGN,
						'enabled' => true,
					)
				),
				Rules::TYPE_BATCH    => Rules::query(
					array(
						'type'    => Rules::TYPE_BATCH,
						'enabled' => true,
					)
				),
			);
		}

		return self::$rules_cache[ $type ] ?? array();
	}

	/**
	 * Product category term IDs, including ancestors so a rule on a parent
	 * category also covers products filed only under its children.
	 *
	 * @return int[]
	 */
	private static function product_term_ids( int $product_id ): array {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$all = array_map( 'intval', $terms );

		foreach ( $all as $term_id ) {
			$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
			$all       = array_merge( $all, array_map( 'intval', $ancestors ) );
		}

		return array_values( array_unique( $all ) );
	}

	/**
	 * Drops the caches. Called after rules change.
	 */
	public static function flush(): void {
		self::$rules_cache   = null;
		self::$batch_members = null;
		self::$resolved      = array();
	}
}
