<?php
/**
 * The bands the filter offers, and how they are suggested from real data.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the discount and price bands shown in the filter.
 *
 * These are configurable rather than fixed for a concrete reason. The obvious
 * bands — 60%+, 40-59%, 20-39% — happen to miss most of this shop: the majority
 * of products sit at 10% or 15%, so those three bands would leave the filter
 * looking almost empty while a customer wondered where everything went.
 *
 * So the shop owner defines the bands, and "Suggest from my products" builds a
 * set from what is actually in the catalogue, with nothing left uncovered.
 */
class Buckets {

	/**
	 * The discount bands currently configured.
	 *
	 * @return array<int,array{key:string,label:string,min:float,max:float}>
	 */
	public static function discount_buckets(): array {
		return self::normalise( (array) Settings::get( 'discount_buckets', array() ), 100.0 );
	}

	/**
	 * The price bands currently configured.
	 *
	 * @return array<int,array{key:string,label:string,min:float,max:float}>
	 */
	public static function price_buckets(): array {
		return self::normalise( (array) Settings::get( 'price_buckets', array() ), 99999999.0 );
	}

	/**
	 * One discount band by key.
	 *
	 * @return array{key:string,label:string,min:float,max:float}|null
	 */
	public static function discount_bucket( string $key ): ?array {
		foreach ( self::discount_buckets() as $bucket ) {
			if ( $bucket['key'] === $key ) {
				return $bucket;
			}
		}

		return null;
	}

	/**
	 * One price band by key.
	 *
	 * @return array{key:string,label:string,min:float,max:float}|null
	 */
	public static function price_bucket( string $key ): ?array {
		foreach ( self::price_buckets() as $bucket ) {
			if ( $bucket['key'] === $key ) {
				return $bucket;
			}
		}

		return null;
	}

	/**
	 * Fills in keys, labels and an open-ended upper bound.
	 *
	 * @param array<int,array<string,mixed>> $raw     Stored bands.
	 * @param float                          $open_max Value to use when a band has no ceiling.
	 * @return array<int,array{key:string,label:string,min:float,max:float}>
	 */
	private static function normalise( array $raw, float $open_max ): array {
		$out = array();

		foreach ( $raw as $index => $bucket ) {
			if ( ! is_array( $bucket ) ) {
				continue;
			}

			$min = (float) ( $bucket['min'] ?? 0 );
			$max = (float) ( $bucket['max'] ?? 0 );

			if ( $max <= 0 ) {
				$max = $open_max;
			}

			$key = (string) ( $bucket['key'] ?? '' );

			if ( $key === '' ) {
				$key = 'b' . $index;
			}

			$out[] = array(
				'key'   => sanitize_key( $key ),
				'label' => (string) ( $bucket['label'] ?? '' ),
				'min'   => $min,
				'max'   => $max,
			);
		}

		return $out;
	}

	/**
	 * How many published products sit at each discount percentage.
	 *
	 * @return array<string,int> Percentage as string => product count.
	 */
	public static function discount_distribution(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS pct, COUNT(*) AS total
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND p.post_type = 'product'
				    AND p.post_status = 'publish'
				  GROUP BY pm.meta_value
				  ORDER BY CAST(pm.meta_value AS DECIMAL(5,2)) ASC",
				Price_Engine::META_PERCENT
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$pct = (float) $row['pct'];

			if ( $pct <= 0 ) {
				continue;
			}

			$out[ (string) $pct ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Builds a set of discount bands from what the catalogue actually holds.
	 *
	 * With a handful of distinct discounts, one band each is the clearest thing
	 * to offer — every product is reachable and no band is empty. Only when
	 * there are many distinct values does it fall back to ranges.
	 *
	 * @return array<int,array{key:string,label:string,min:float,max:float}>
	 */
	public static function suggest_discount_buckets(): array {
		$distribution = self::discount_distribution();

		if ( $distribution === array() ) {
			return array();
		}

		$levels = array_map( 'floatval', array_keys( $distribution ) );

		if ( count( $levels ) <= 8 ) {
			$buckets = array();

			foreach ( $levels as $percent ) {
				$rounded = (int) round( $percent );

				$buckets[] = array(
					'key'   => 'off' . $rounded,
					'label' => sprintf(
						/* translators: %d: discount percentage. */
						__( '%d%% off', 'woo-custom-discount' ),
						$rounded
					),
					'min'   => $percent,
					'max'   => $percent,
				);
			}

			return $buckets;
		}

		// Many distinct values: fall back to ten-point ranges, skipping any
		// range that would come out empty.
		$buckets = array();

		for ( $start = 0; $start < 100; $start += 10 ) {
			$end   = $start + 9;
			$count = 0;

			foreach ( $distribution as $pct => $total ) {
				if ( (float) $pct >= $start && (float) $pct <= $end ) {
					$count += $total;
				}
			}

			if ( $count === 0 ) {
				continue;
			}

			$buckets[] = array(
				'key'   => 'r' . $start,
				'label' => $start >= 60
					? sprintf( __( '%d%% and above', 'woo-custom-discount' ), $start )
					: sprintf( __( '%1$d%% – %2$d%%', 'woo-custom-discount' ), $start, $end ),
				'min'   => (float) $start,
				'max'   => $start >= 60 ? 100.0 : (float) $end,
			);
		}

		return $buckets;
	}

	/**
	 * Four price bands spanning the catalogue's actual range.
	 *
	 * @return array<int,array{key:string,label:string,min:float,max:float}>
	 */
	public static function suggest_price_buckets(): array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT MIN(CAST(meta_value AS DECIMAL(12,2))) AS min_price,
			        MAX(CAST(meta_value AS DECIMAL(12,2))) AS max_price
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_price'
			    AND pm.meta_value <> ''
			    AND p.post_type = 'product'
			    AND p.post_status = 'publish'",
			ARRAY_A
		);

		$min = (float) ( $row['min_price'] ?? 0 );
		$max = (float) ( $row['max_price'] ?? 0 );

		if ( $max <= $min ) {
			return array();
		}

		// Round the boundaries to something a shopper would recognise rather
		// than exposing raw quartiles like "6,247".
		$step  = self::round_step( ( $max - $min ) / 4 );
		$edges = array();
		$edge  = self::round_step( $min + $step );

		for ( $i = 0; $i < 3; $i++ ) {
			$edges[] = $edge;
			$edge   += $step;
		}

		$buckets  = array();
		$previous = 0.0;

		foreach ( $edges as $index => $boundary ) {
			$buckets[] = array(
				'key'   => 'p' . $index,
				'label' => $previous <= 0
					? sprintf( __( 'Under %s', 'woo-custom-discount' ), self::money( $boundary ) )
					: sprintf( __( '%1$s – %2$s', 'woo-custom-discount' ), self::money( $previous ), self::money( $boundary ) ),
				'min'   => $previous,
				'max'   => $boundary,
			);

			$previous = $boundary;
		}

		$buckets[] = array(
			'key'   => 'p' . count( $edges ),
			'label' => sprintf( __( '%s and above', 'woo-custom-discount' ), self::money( $previous ) ),
			'min'   => $previous,
			'max'   => 0.0,
		);

		return $buckets;
	}

	/**
	 * Rounds a step up to a tidy figure: 500, 1000, 2500 and so on.
	 */
	private static function round_step( float $value ): float {
		if ( $value <= 0 ) {
			return 0.0;
		}

		$magnitude = 10 ** max( 0, (int) floor( log10( $value ) ) );
		$rounded   = ceil( $value / ( $magnitude / 2 ) ) * ( $magnitude / 2 );

		return (float) max( 1, $rounded );
	}

	/**
	 * A plain price string for labels, without currency markup.
	 */
	private static function money( float $amount ): string {
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';

		return trim( html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) . ' ' . number_format( $amount ) );
	}

	/**
	 * Product categories offered in the filter.
	 *
	 * Only the ones the shop owner ticked. The housekeeping categories the shop
	 * used before this plugin existed — "60% off", "Expiry 08-2026",
	 * "Special Discounts" — would duplicate the discount and expiry groups, so
	 * they are excluded from the suggestion.
	 *
	 * @return array<int,\WP_Term>
	 */
	public static function filter_categories(): array {
		$chosen = array_map( 'intval', (array) Settings::get( 'filter_categories', array() ) );

		if ( $chosen === array() ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'include'    => $chosen,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Category IDs worth offering, judged from their names and product counts.
	 *
	 * @return int[]
	 */
	public static function suggest_filter_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();

		foreach ( $terms as $term ) {
			if ( self::is_housekeeping_category( $term->name ) ) {
				continue;
			}

			$out[] = (int) $term->term_id;
		}

		return $out;
	}

	/**
	 * True for the categories that were standing in for this plugin's job.
	 */
	public static function is_housekeeping_category( string $name ): bool {
		$patterns = array(
			'/^\s*expiry\b/i',
			'/^\s*\d+\s*%\s*off\b/i',
			'/^\s*special\s+discounts?\b/i',
			'/^\s*uncategori[sz]ed\s*$/i',
			'/^\s*trending\s+products?\s*$/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $name ) ) {
				return true;
			}
		}

		return false;
	}
}
