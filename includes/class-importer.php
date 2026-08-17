<?php
/**
 * One-off import from Discount Rules for WooCommerce.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * The only file in this plugin that knows anything about the old discount
 * plugin, and it runs once.
 *
 * That isolation is deliberate. Reading another plugin's tables means depending
 * on a schema nobody promised to keep. Keeping it in one file means an update
 * over there can break the import and nothing else — the filters, countdowns
 * and prices all read this plugin's own data and carry on working.
 *
 * Two things get imported:
 *
 *   Rules      The eight enabled rules, with their product lists, exclusions
 *              and percentages.
 *   Batches    The store had been hand-maintaining "Expiry 08-2026" style
 *              product categories. Those become real expiry batches with real
 *              dates, so nobody re-types what is already known.
 */
class Importer {

	private const SOURCE_TABLE = 'wdr_rules';

	/** Marks rules created by this importer, so a re-run can replace them. */
	public const SOURCE_TAG = 'wdr_import';

	/** Category names of the form "Expiry 08-2026". */
	private const EXPIRY_CATEGORY_PATTERN = '/^expiry\s*[:\-]?\s*(\d{1,2})\s*[\-\/\.]\s*(\d{4})$/i';

	/**
	 * True when the old plugin's rules table is present and readable.
	 */
	public static function source_available(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::SOURCE_TABLE;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Reads and normalises the old plugin's rules.
	 *
	 * Only enabled, undeleted rules that actually carry a percentage are
	 * returned. Priority order is preserved because that is how the old plugin
	 * decided ties, and matching it keeps prices identical after the switch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function read_source_rules(): array {
		global $wpdb;

		if ( ! self::source_available() ) {
			return array();
		}

		$table = $wpdb->prefix . self::SOURCE_TABLE;

		$rows = $wpdb->get_results(
			"SELECT id, title, priority, filters, product_adjustments, bulk_adjustments, date_from, date_to
			   FROM {$table}
			  WHERE enabled = 1 AND deleted = 0
			  ORDER BY priority ASC, id ASC",
			ARRAY_A
		);

		$parsed = array();

		foreach ( (array) $rows as $row ) {
			$rule = self::parse_source_rule( $row );

			if ( $rule !== null ) {
				$parsed[] = $rule;
			}
		}

		return $parsed;
	}

	/**
	 * Turns one raw row into the shape this plugin understands.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>|null Null when the row carries no usable percentage.
	 */
	private static function parse_source_rule( array $row ): ?array {
		$percent = self::extract_percent( $row );

		if ( $percent <= 0 ) {
			return null;
		}

		$filters  = json_decode( (string) $row['filters'], true );
		$scope    = Rules::SCOPE_PRODUCTS;
		$products = array();
		$cats     = array();
		$excluded = array();

		if ( is_array( $filters ) ) {
			foreach ( $filters as $filter ) {
				if ( ! is_array( $filter ) || empty( $filter['type'] ) ) {
					continue;
				}

				$method = (string) ( $filter['method'] ?? 'in_list' );
				$values = array_map( 'intval', (array) ( $filter['value'] ?? array() ) );

				switch ( (string) $filter['type'] ) {
					case 'all_products':
						$scope = Rules::SCOPE_ALL;
						break;

					case 'products':
						if ( $method === 'not_in_list' ) {
							$excluded = array_merge( $excluded, $values );
						} else {
							$products = array_merge( $products, $values );

							if ( $scope !== Rules::SCOPE_ALL ) {
								$scope = Rules::SCOPE_PRODUCTS;
							}
						}
						break;

					case 'product_categories':
					case 'categories':
						if ( $method === 'not_in_list' ) {
							break;
						}

						$cats = array_merge( $cats, $values );

						if ( $scope !== Rules::SCOPE_ALL ) {
							$scope = Rules::SCOPE_CATEGORIES;
						}
						break;
				}
			}
		}

		return array(
			'source_id' => (int) $row['id'],
			// Titles arrive HTML-encoded ("Bundles &amp; Twin Packs"), so decode
			// once here rather than leaking entities into every screen.
			'title'     => html_entity_decode( (string) $row['title'], ENT_QUOTES, 'UTF-8' ),
			'priority'  => (int) $row['priority'],
			'percent'   => $percent,
			'scope'     => $scope,
			'products'  => array_values( array_unique( $products ) ),
			'categories' => array_values( array_unique( $cats ) ),
			'excluded'  => array_values( array_unique( $excluded ) ),
			'ends_at'   => self::timestamp_to_mysql( $row['date_to'] ?? null ),
		);
	}

	/**
	 * Pulls the percentage out of whichever adjustment column holds it.
	 *
	 * The store's rules all use the "bulk" mechanism with a single range that
	 * covers every realistic quantity, which makes them flat percentages in
	 * practice. Fixed-amount discounts are ignored — the store has none, and
	 * guessing at one would be worse than reporting it as unsupported.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 */
	private static function extract_percent( array $row ): float {
		foreach ( array( 'bulk_adjustments', 'product_adjustments' ) as $column ) {
			$data = json_decode( (string) ( $row[ $column ] ?? '' ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( isset( $data['ranges'] ) && is_array( $data['ranges'] ) ) {
				foreach ( $data['ranges'] as $range ) {
					if ( is_array( $range ) && ( $range['type'] ?? '' ) === 'percentage' ) {
						return (float) ( $range['value'] ?? 0 );
					}
				}
			}

			if ( ( $data['type'] ?? '' ) === 'percentage' ) {
				return (float) ( $data['value'] ?? 0 );
			}
		}

		return 0.0;
	}

	/**
	 * Which percentage the old plugin currently gives each product.
	 *
	 * The old plugin resolves ties by letting the highest priority number win —
	 * verified against the live shop, where a product matching both a 60% rule
	 * and the store-wide 10% rule sells at exactly 60% off, not 64% or 70%.
	 * Reproducing that here is what lets the preview screen prove that prices
	 * will not move.
	 *
	 * @return array<int,array{percent:float,rule:string}>
	 */
	public static function source_percent_map(): array {
		$rules = self::read_source_rules();

		if ( $rules === array() ) {
			return array();
		}

		// Ascending priority so later rules overwrite earlier ones.
		usort( $rules, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		$product_ids = Price_Engine::target_product_ids();
		$map         = array();

		foreach ( $product_ids as $product_id ) {
			$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			$terms = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );

			foreach ( $rules as $rule ) {
				if ( in_array( $product_id, $rule['excluded'], true ) ) {
					continue;
				}

				$covered = match ( $rule['scope'] ) {
					Rules::SCOPE_ALL        => true,
					Rules::SCOPE_CATEGORIES => (bool) array_intersect( $rule['categories'], $terms ),
					default                 => in_array( $product_id, $rule['products'], true ),
				};

				if ( $covered ) {
					$map[ $product_id ] = array(
						'percent' => $rule['percent'],
						'rule'    => $rule['title'],
					);
				}
			}
		}

		return $map;
	}

	/**
	 * Expiry information the store already recorded as product categories.
	 *
	 * @return array<int,array{term_id:int,name:string,expiry_ym:string,products:int[]}>
	 */
	public static function expiry_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$found = array();

		foreach ( $terms as $term ) {
			if ( ! preg_match( self::EXPIRY_CATEGORY_PATTERN, $term->name, $m ) ) {
				continue;
			}

			$month = (int) $m[1];
			$year  = (int) $m[2];

			if ( $month < 1 || $month > 12 ) {
				continue;
			}

			$products = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private' ),
					'fields'         => 'ids',
					'numberposts'    => -1,
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
				)
			);

			if ( $products === array() ) {
				continue;
			}

			$found[] = array(
				'term_id'   => (int) $term->term_id,
				'name'      => $term->name,
				'expiry_ym' => sprintf( '%04d%02d', $year, $month ),
				'products'  => array_map( 'intval', $products ),
			);
		}

		usort( $found, static fn( array $a, array $b ): int => strcmp( $a['expiry_ym'], $b['expiry_ym'] ) );

		return $found;
	}

	/**
	 * Describes what an import would create, without writing anything.
	 *
	 * @return array<string,mixed>
	 */
	public static function dry_run(): array {
		$source  = self::read_source_rules();
		$batches = self::plan_batches();
		$percent = self::source_percent_map();

		$in_batches = array();

		foreach ( $batches as $batch ) {
			$in_batches = array_merge( $in_batches, $batch['products'] );
		}

		$in_batches = array_unique( $in_batches );

		$campaigns = array();

		foreach ( $source as $rule ) {
			$products = array_values( array_diff( $rule['products'], $in_batches ) );

			// A product-scoped rule whose entire list moved into batches has
			// nothing left to do, so it is reported rather than created.
			$empty = $rule['scope'] === Rules::SCOPE_PRODUCTS && $products === array();

			$campaigns[] = array(
				'title'      => $rule['title'],
				'percent'    => $rule['percent'],
				'scope'      => $rule['scope'],
				'priority'   => $rule['priority'],
				'products'   => $products,
				'categories' => $rule['categories'],
				'excluded'   => $rule['excluded'],
				'ends_at'    => $rule['ends_at'],
				'skipped'    => $empty,
				'moved'      => count( array_intersect( $rule['products'], $in_batches ) ),
			);
		}

		return array(
			'source_available' => self::source_available(),
			'campaigns'        => $campaigns,
			'batches'          => $batches,
			'percent_map'      => $percent,
			'products_covered' => count( $percent ),
		);
	}

	/**
	 * Groups the expiry categories into batches, giving each the percentage its
	 * products currently receive.
	 *
	 * @return array<int,array{title:string,expiry_ym:string,percent:float,products:int[],mixed_percents:bool}>
	 */
	public static function plan_batches(): array {
		$percent_map = self::source_percent_map();
		$batches     = array();

		foreach ( self::expiry_categories() as $category ) {
			$percents = array();

			foreach ( $category['products'] as $product_id ) {
				$percents[] = $percent_map[ $product_id ]['percent'] ?? 0.0;
			}

			$unique = array_values( array_unique( $percents ) );

			$batches[] = array(
				'title'          => sprintf(
					/* translators: %s: month and year, e.g. "August 2026". */
					__( 'Expiry %s', 'woo-custom-discount' ),
					self::format_expiry( $category['expiry_ym'] )
				),
				'expiry_ym'      => $category['expiry_ym'],
				'percent'        => $unique === array() ? 0.0 : max( $percents ),
				'products'       => $category['products'],
				'mixed_percents' => count( $unique ) > 1,
				'source_term'    => $category['term_id'],
			);
		}

		return $batches;
	}

	/**
	 * Performs the import.
	 *
	 * Previously imported rules are removed first, so running it twice does not
	 * pile up duplicates. Rules created by hand are never touched.
	 *
	 * @param bool $enable Whether the created rules start switched on.
	 * @return array<string,mixed> Summary of what was created.
	 */
	public static function run( bool $enable = true ): array {
		$plan = self::dry_run();

		if ( ! $plan['source_available'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'The old plugin\'s rules table was not found, so there is nothing to import.', 'woo-custom-discount' ),
			);
		}

		self::remove_previous_import();

		$created_batches   = 0;
		$created_campaigns = 0;
		$skipped           = 0;

		// Batches first: campaigns need to know which products have moved.
		foreach ( $plan['batches'] as $batch ) {
			$id = Rules::create(
				array(
					'type'              => Rules::TYPE_BATCH,
					'title'             => $batch['title'],
					'enabled'           => $enable,
					'discount_percent'  => $batch['percent'],
					'scope'             => Rules::SCOPE_PRODUCTS,
					'expiry_ym'         => $batch['expiry_ym'],
					'countdown_enabled' => false,
					'priority'          => 5,
					'source'            => self::SOURCE_TAG,
					'notes'             => $batch['mixed_percents']
						? __( 'Imported from a product category. The products in it had different discounts, so the highest was used — please check.', 'woo-custom-discount' )
						: __( 'Imported from a product category that recorded this expiry month.', 'woo-custom-discount' ),
					'products'          => $batch['products'],
				)
			);

			if ( $id ) {
				++$created_batches;
			}
		}

		foreach ( $plan['campaigns'] as $campaign ) {
			if ( $campaign['skipped'] ) {
				++$skipped;

				continue;
			}

			$notes = '';

			if ( $campaign['moved'] > 0 ) {
				$notes = sprintf(
					/* translators: %d: number of products. */
					__( '%d product(s) from this rule moved into an expiry batch instead.', 'woo-custom-discount' ),
					$campaign['moved']
				);
			}

			$id = Rules::create(
				array(
					'type'              => Rules::TYPE_CAMPAIGN,
					'title'             => $campaign['title'],
					'enabled'           => $enable,
					'discount_percent'  => $campaign['percent'],
					'scope'             => $campaign['scope'],
					'ends_at'           => $campaign['ends_at'],
					'countdown_enabled' => false,
					'priority'          => $campaign['priority'],
					'source'            => self::SOURCE_TAG,
					'notes'             => $notes,
					'products'          => $campaign['products'],
					'categories'        => $campaign['categories'],
					'excluded'          => $campaign['excluded'],
				)
			);

			if ( $id ) {
				++$created_campaigns;
			}
		}

		Resolver::flush();

		update_option( 'wcd_last_import', time(), false );

		return array(
			'ok'        => true,
			'campaigns' => $created_campaigns,
			'batches'   => $created_batches,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Deletes rules a previous import created, leaving hand-made ones alone.
	 */
	public static function remove_previous_import(): int {
		global $wpdb;

		$table = Install::table( Install::TABLE_RULES );

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s", self::SOURCE_TAG )
		);

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			Rules::delete( $id );
		}

		return count( (array) $ids );
	}

	/**
	 * "202608" becomes "August 2026".
	 */
	public static function format_expiry( string $expiry_ym ): string {
		if ( ! preg_match( '/^(\d{4})(\d{2})$/', $expiry_ym, $m ) ) {
			return $expiry_ym;
		}

		$timestamp = (int) gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] );

		return (string) gmdate( 'F Y', $timestamp );
	}

	/**
	 * The old plugin stores end dates as unix timestamps.
	 */
	private static function timestamp_to_mysql( $value ): ?string {
		$stamp = (int) $value;

		if ( $stamp <= 0 ) {
			return null;
		}

		return wp_date( 'Y-m-d H:i:s', $stamp );
	}
}
