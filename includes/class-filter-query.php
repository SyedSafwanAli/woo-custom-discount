<?php
/**
 * Turns filter selections in the URL into query conditions.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the filter parameters out of the URL and narrows the product query.
 *
 * Everything lives in the URL rather than in a session or an AJAX-only state.
 * That means a filtered view can be linked to, survives a page refresh, works
 * with the browser's back button, and can be indexed. AJAX is layered on top
 * for the feel of it, but the URL is the source of truth underneath.
 *
 * Two query paths are covered, because the shop page uses one and Divi's Woo
 * Products module uses the other:
 *
 *   woocommerce_product_query          the main shop and category archives
 *   woocommerce_shortcode_products_query   shortcode and Divi module queries
 *
 * The second one is WooCommerce's own documented filter, not a Divi internal,
 * so a Divi update cannot take it away.
 */
class Filter_Query {

	public const PARAM_DISCOUNT = 'discount';
	public const PARAM_EXPIRY   = 'expiry';
	public const PARAM_CATEGORY = 'pcat';
	public const PARAM_PRICE    = 'price';
	public const PARAM_STOCK    = 'instock';
	public const PARAM_SORT     = 'sort';

	/**
	 * The slider's own fields, so it still works with JavaScript off: a plain
	 * form cannot join two numbers into one parameter, so it submits them
	 * separately and they are read here as well as the tidy combined form.
	 */
	public const PARAM_PRICE_MIN = 'price_min';
	public const PARAM_PRICE_MAX = 'price_max';

	/**
	 * Hooks the query filters.
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_query', array( __CLASS__, 'filter_main_query' ) );

		// Late, so whatever another plugin does to the query has already been
		// done by the time this looks at it.
		add_action( 'pre_get_posts', array( __CLASS__, 'release_sort' ), 9999 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_shortcode_query' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );

		// The shop already has a sort dropdown. Rather than stand a second sort
		// control beside it, the two orderings this plugin makes possible are
		// added to the one that is already there.
		add_filter( 'woocommerce_catalog_orderby', array( __CLASS__, 'add_orderby_options' ) );
		add_filter( 'woocommerce_default_catalog_orderby_options', array( __CLASS__, 'add_orderby_options' ) );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( __CLASS__, 'apply_orderby' ), 10, 2 );
	}

	/**
	 * Adds our orderings to WooCommerce's sort dropdown.
	 *
	 * Neither was possible before: the discount and the expiry only became
	 * sortable once this plugin started storing them as real numbers.
	 *
	 * @param array<string,string> $options Existing options.
	 * @return array<string,string>
	 */
	public static function add_orderby_options( array $options ): array {
		$options['wcd_discount'] = __( 'Sort by biggest discount', 'woo-custom-discount' );
		$options['wcd_expiry']   = __( 'Sort by expiring soonest', 'woo-custom-discount' );

		return $options;
	}

	/**
	 * Turns those two choices into query arguments.
	 *
	 * @param array<string,mixed> $args    Ordering arguments.
	 * @param string              $orderby Chosen ordering.
	 * @return array<string,mixed>
	 */
	public static function apply_orderby( array $args, $orderby ): array {
		if ( $orderby === 'wcd_discount' ) {
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'DESC';
			$args['meta_key'] = Price_Engine::META_PERCENT;
		}

		if ( $orderby === 'wcd_expiry' ) {
			$args['orderby']  = 'meta_value';
			$args['order']    = 'ASC';
			$args['meta_key'] = Price_Engine::META_EXPIRY;
		}

		return $args;
	}

	/**
	 * Lets WordPress keep our parameters instead of stripping them.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function register_query_vars( array $vars ): array {
		return array_merge( $vars, self::param_names() );
	}

	/**
	 * All parameter names this class understands.
	 *
	 * @return string[]
	 */
	public static function param_names(): array {
		return array(
			self::PARAM_DISCOUNT,
			self::PARAM_EXPIRY,
			self::PARAM_CATEGORY,
			self::PARAM_PRICE,
			self::PARAM_PRICE_MIN,
			self::PARAM_PRICE_MAX,
			self::PARAM_STOCK,
			self::PARAM_SORT,
		);
	}

	/**
	 * Whether price is filtered with a slider rather than fixed bands.
	 */
	public static function price_is_slider(): bool {
		return (string) Settings::get( 'price_mode', 'slider' ) === 'slider';
	}

	/**
	 * The current selection, parsed and validated.
	 *
	 * @return array{
	 *     discount:string[],
	 *     expiry:string[],
	 *     category:int[],
	 *     price:string[],
	 *     instock:bool,
	 *     sort:string
	 * }
	 */
	public static function selection(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only browsing filters.
		$raw = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
		};
		// phpcs:enable

		$split = static function ( string $value ): array {
			if ( $value === '' ) {
				return array();
			}

			return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		};

		$sort  = $raw( self::PARAM_SORT );
		$sorts = array_keys( self::sort_options() );

		$slider = self::price_is_slider();

		return array(
			'discount' => array_map( 'sanitize_key', $split( $raw( self::PARAM_DISCOUNT ) ) ),
			'expiry'   => array_values(
				array_filter(
					$split( $raw( self::PARAM_EXPIRY ) ),
					static fn( string $ym ): bool => (bool) preg_match( '/^\d{6}$/', $ym )
				)
			),
			'category' => array_map( 'intval', $split( $raw( self::PARAM_CATEGORY ) ) ),
			// Bands and slider both live on the `price` parameter, and a band key
			// such as "6000-10000" is indistinguishable from a slider range. The
			// configured mode decides which reading applies, so only one of these
			// is ever populated.
			'price'    => $slider ? array() : array_map( 'sanitize_key', $split( $raw( self::PARAM_PRICE ) ) ),
			'price_range' => $slider
				? self::parse_price_range( $raw( self::PARAM_PRICE ), $raw( self::PARAM_PRICE_MIN ), $raw( self::PARAM_PRICE_MAX ) )
				: array(
					'min' => null,
					'max' => null,
				),
			'instock'  => $raw( self::PARAM_STOCK ) === '1',
			'sort'     => in_array( $sort, $sorts, true ) ? $sort : '',
		);
	}

	/**
	 * Reads a price range from either the combined or the separate parameters.
	 *
	 * Accepts "4500-9200", "-6000" and "14000-", so an open-ended range is still
	 * expressible in a link.
	 *
	 * @return array{min:?float,max:?float}
	 */
	private static function parse_price_range( string $combined, string $min_param, string $max_param ): array {
		$min = null;
		$max = null;

		if ( $combined !== '' && preg_match( '/^(\d+(?:\.\d+)?)?\s*-\s*(\d+(?:\.\d+)?)?$/', $combined, $m ) ) {
			$min = ( isset( $m[1] ) && $m[1] !== '' ) ? (float) $m[1] : null;
			$max = ( isset( $m[2] ) && $m[2] !== '' ) ? (float) $m[2] : null;
		}

		if ( $min_param !== '' && is_numeric( $min_param ) ) {
			$min = (float) $min_param;
		}

		if ( $max_param !== '' && is_numeric( $max_param ) ) {
			$max = (float) $max_param;
		}

		// Typed in backwards, it would match nothing at all.
		if ( $min !== null && $max !== null && $min > $max ) {
			[ $min, $max ] = array( $max, $min );
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	/**
	 * True when the visitor has chosen at least one filter.
	 */
	public static function is_filtering(): bool {
		$s = self::selection();

		return $s['discount'] !== array()
			|| $s['expiry'] !== array()
			|| $s['category'] !== array()
			|| $s['price'] !== array()
			|| $s['price_range']['min'] !== null
			|| $s['price_range']['max'] !== null
			|| $s['instock']
			|| $s['sort'] !== '';
	}

	/**
	 * Narrows the main shop / category query.
	 *
	 * @param \WP_Query $query Product query.
	 */
	public static function filter_main_query( $query ): void {
		if ( is_admin() || ! self::is_filtering() ) {
			return;
		}

		$s = self::selection();

		$meta = (array) $query->get( 'meta_query' );
		$meta = array_merge( $meta, self::meta_clauses( $s ) );

		if ( $meta !== array() ) {
			$meta['relation'] = 'AND';
			$query->set( 'meta_query', $meta );
		}

		// A category chosen in the filter narrows within whatever the visitor is
		// already browsing, rather than replacing it. Someone on the Vitamins
		// page who ticks "Heart Health" gets products in both.
		if ( $s['category'] !== array() ) {
			$tax = (array) $query->get( 'tax_query' );

			$tax[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $s['category'],
				'operator'         => 'IN',
				'include_children' => true,
			);

			$query->set( 'tax_query', $tax );
		}

		self::apply_sort( $s['sort'], $query );
	}

	/**
	 * Narrows a shortcode or Divi module query.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public static function filter_shortcode_query( array $args ): array {
		if ( is_admin() || ! self::is_filtering() ) {
			return $args;
		}

		$s = self::selection();

		$meta = (array) ( $args['meta_query'] ?? array() );
		$meta = array_merge( $meta, self::meta_clauses( $s ) );

		if ( $meta !== array() ) {
			$meta['relation']    = 'AND';
			$args['meta_query'] = $meta;
		}

		if ( $s['category'] !== array() ) {
			$tax = (array) ( $args['tax_query'] ?? array() );

			$tax[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $s['category'],
				'operator'         => 'IN',
				'include_children' => true,
			);

			$args['tax_query'] = $tax;
		}

		$sort = self::sort_args( $s['sort'] );

		if ( $sort !== array() ) {
			$args = array_merge( $args, $sort );
		}

		return $args;
	}

	/**
	 * Builds the meta conditions for the current selection.
	 *
	 * Within one group the options are OR'd together — ticking "60%+" and
	 * "40-59%" shows both. Between groups it is AND, so "Heart Health" plus
	 * "60% off" means heart products that are 60% off, which is what anyone
	 * would expect.
	 *
	 * @param array<string,mixed> $s Parsed selection.
	 * @return array<int,array<string,mixed>>
	 */
	private static function meta_clauses( array $s ): array {
		$clauses = array();

		// --- Discount buckets ------------------------------------------------
		if ( $s['discount'] !== array() ) {
			$group = array( 'relation' => 'OR' );

			foreach ( $s['discount'] as $key ) {
				$bucket = Buckets::discount_bucket( $key );

				if ( $bucket === null ) {
					continue;
				}

				$group[] = array(
					'key'     => Price_Engine::META_PERCENT,
					'value'   => array( $bucket['min'], $bucket['max'] ),
					'type'    => 'DECIMAL(5,2)',
					'compare' => 'BETWEEN',
				);
			}

			if ( count( $group ) > 1 ) {
				$clauses[] = $group;
			}
		}

		// --- Expiry months ---------------------------------------------------
		if ( $s['expiry'] !== array() ) {
			// Every month the product is stocked in, not just the soonest, so a
			// product held in three batches is found under all three.
			$clauses[] = array(
				'key'     => Price_Engine::META_EXPIRY_ALL,
				'value'   => $s['expiry'],
				'compare' => 'IN',
			);
		}

		// --- Price buckets ---------------------------------------------------
		if ( $s['price'] !== array() ) {
			$group = array( 'relation' => 'OR' );

			foreach ( $s['price'] as $key ) {
				$bucket = Buckets::price_bucket( $key );

				if ( $bucket === null ) {
					continue;
				}

				$group[] = array(
					'key'     => '_price',
					'value'   => array( $bucket['min'], $bucket['max'] ),
					'type'    => 'DECIMAL(12,2)',
					'compare' => 'BETWEEN',
				);
			}

			if ( count( $group ) > 1 ) {
				$clauses[] = $group;
			}
		}

		// --- Price range from the slider -------------------------------------
		$range = $s['price_range'] ?? array(
			'min' => null,
			'max' => null,
		);

		if ( $range['min'] !== null || $range['max'] !== null ) {
			if ( $range['min'] !== null && $range['max'] !== null ) {
				$clauses[] = array(
					'key'     => '_price',
					'value'   => array( $range['min'], $range['max'] ),
					'type'    => 'DECIMAL(12,2)',
					'compare' => 'BETWEEN',
				);
			} else {
				$clauses[] = array(
					'key'     => '_price',
					'value'   => $range['min'] ?? $range['max'],
					'type'    => 'DECIMAL(12,2)',
					'compare' => $range['min'] !== null ? '>=' : '<=',
				);
			}
		}

		// --- In stock only ---------------------------------------------------
		if ( $s['instock'] ) {
			$clauses[] = array(
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			);
		}

		return $clauses;
	}

	/**
	 * The sort options offered, and their labels.
	 *
	 * The last two are only possible because this plugin stores the discount and
	 * the expiry as real numbers. Nothing could sort by them before.
	 *
	 * @return array<string,string>
	 */
	public static function sort_options(): array {
		return array(
			'price_asc'  => __( 'Price: low to high', 'woo-custom-discount' ),
			'price_desc' => __( 'Price: high to low', 'woo-custom-discount' ),
			'discount'   => __( 'Biggest discount first', 'woo-custom-discount' ),
			'expiry'     => __( 'Expiring soonest first', 'woo-custom-discount' ),
			'newest'     => __( 'Newest first', 'woo-custom-discount' ),
		);
	}

	/**
	 * Query arguments for one sort option.
	 *
	 * @return array<string,mixed>
	 */
	private static function sort_args( string $sort ): array {
		return match ( $sort ) {
			'price_asc'  => array(
				'orderby'  => 'meta_value_num',
				'meta_key' => '_price',
				'order'    => 'ASC',
			),
			'price_desc' => array(
				'orderby'  => 'meta_value_num',
				'meta_key' => '_price',
				'order'    => 'DESC',
			),
			'discount'   => array(
				'orderby'  => 'meta_value_num',
				'meta_key' => Price_Engine::META_PERCENT,
				'order'    => 'DESC',
			),
			'expiry'     => array(
				'orderby'  => 'meta_value',
				'meta_key' => Price_Engine::META_EXPIRY,
				'order'    => 'ASC',
			),
			'newest'     => array(
				'orderby' => 'date',
				'order'   => 'DESC',
			),
			default      => array(),
		};
	}

	/**
	 * Applies a sort option to a WP_Query object.
	 *
	 * @param string    $sort  Sort key.
	 * @param \WP_Query $query Query to modify.
	 */
	private static function apply_sort( string $sort, $query ): void {
		$args = self::sort_args( $sort );

		if ( $args === array() ) {
			return;
		}

		foreach ( $args as $key => $value ) {
			$query->set( $key, $value );
		}

		// Marked so it can be taken back off again. See release_sort().
		if ( isset( $args['meta_key'] ) ) {
			$query->set( 'wcd_sorted', 1 );
		}
	}

	/**
	 * Takes our sort back off a query that has stopped being a product query.
	 *
	 * Divi serves the shop page by rewriting the main query into a query for a
	 * single page — and it clears meta_query while doing so, but not meta_key.
	 * A sort left behind therefore asked page 5 for a meta value it has never
	 * had, matched nothing, and turned the shop into a 404. The filters that use
	 * meta_query were cleared along with everything else and so came through it
	 * unharmed, which is why only sorting broke.
	 *
	 * Rather than guess at hook order, the sort is removed once the query has
	 * settled into whatever it is going to be. The shop page keeps its ordering
	 * anyway: on that page the products come from Divi's own module, whose query
	 * is filtered separately and is not touched by any of this.
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public static function release_sort( $query ): void {
		if ( ! $query->get( 'wcd_sorted' ) ) {
			return;
		}

		$types = array_filter( (array) $query->get( 'post_type' ) );

		if ( $types === array() || in_array( 'product', $types, true ) ) {
			return;
		}

		$query->set( 'meta_key', '' );
		$query->set( 'orderby', '' );
		$query->set( 'wcd_sorted', 0 );
	}

	/**
	 * A URL with one option toggled on or off, keeping everything else.
	 *
	 * @param string $group Parameter name.
	 * @param string $value Option value; empty clears the group.
	 * @param bool   $multi Whether the group accepts several values.
	 */
	public static function toggle_url( string $group, string $value, bool $multi = true ): string {
		$base = self::base_url();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$current = isset( $_GET[ $group ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $group ] ) ) : '';
		$values  = $current === '' ? array() : array_filter( array_map( 'trim', explode( ',', $current ) ) );

		if ( $value === '' ) {
			$values = array();
		} elseif ( in_array( $value, $values, true ) ) {
			$values = array_values( array_diff( $values, array( $value ) ) );
		} elseif ( $multi ) {
			$values[] = $value;
		} else {
			$values = array( $value );
		}

		$args = self::current_args();

		if ( $values === array() ) {
			unset( $args[ $group ] );
		} else {
			$args[ $group ] = implode( ',', $values );
		}

		// Filtering always restarts at page one; page four of the old result set
		// is meaningless once the result set changes.
		unset( $args['paged'], $args['page'] );

		return $args === array() ? $base : add_query_arg( $args, $base );
	}

	/**
	 * The URL with every filter cleared.
	 */
	public static function clear_url(): string {
		return self::base_url();
	}

	/**
	 * The current URL with the named parameters dropped.
	 *
	 * The slider spans three parameters, so removing it needs more than the
	 * single-value toggle.
	 *
	 * @param string[] $params Parameter names to remove.
	 */
	public static function without( array $params ): string {
		$args = self::current_args();

		foreach ( $params as $name ) {
			unset( $args[ $name ] );
		}

		$base = self::base_url();

		return $args === array() ? $base : add_query_arg( $args, $base );
	}

	/**
	 * Current filter parameters as they appear in the URL.
	 *
	 * @return array<string,string>
	 */
	public static function current_args(): array {
		$args = array();

		foreach ( self::param_names() as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			if ( isset( $_GET[ $name ] ) && $_GET[ $name ] !== '' ) {
				$args[ $name ] = sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) );
			}
		}

		return $args;
	}

	/**
	 * The page the filter is sitting on, without any filter parameters.
	 */
	public static function base_url(): string {
		if ( is_product_category() || is_product_tag() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );

				if ( ! is_wp_error( $link ) ) {
					return (string) $link;
				}
			}
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop = wc_get_page_permalink( 'shop' );

			if ( $shop ) {
				return $shop;
			}
		}

		$permalink = get_permalink();

		return $permalink ? (string) $permalink : home_url( '/' );
	}

	/**
	 * How many products a single option would return, given what is already
	 * selected. Used for the counts beside each checkbox.
	 */
	public static function count_for( string $group, string $value ): int {
		// The version stamp is bumped whenever prices change, so a stale count
		// cannot outlive the data it describes.
		$key = 'wcd_count_' . md5(
			implode(
				'|',
				array(
					$group,
					$value,
					(string) wp_json_encode( self::current_args() ),
					(string) get_option( 'wcd_counts_version', '0' ),
				)
			)
		);

		$cached = get_transient( $key );

		if ( $cached !== false ) {
			return (int) $cached;
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => false,
			// Count what the shop would actually show. A product the owner has
			// hidden from the catalogue must not be counted, or the label would
			// promise twenty products and the grid deliver nineteen.
			'tax_query'      => self::visibility_clauses(),
		);

		$selection = self::selection();

		// Replace just this group with the single value being counted.
		$map = array(
			self::PARAM_DISCOUNT => 'discount',
			self::PARAM_EXPIRY   => 'expiry',
			self::PARAM_PRICE    => 'price',
			self::PARAM_CATEGORY => 'category',
			self::PARAM_STOCK    => 'instock',
		);

		if ( isset( $map[ $group ] ) ) {
			$field = $map[ $group ];

			$selection[ $field ] = $group === self::PARAM_STOCK
				? true
				: ( $group === self::PARAM_CATEGORY ? array( (int) $value ) : array( $value ) );
		}

		$meta = self::meta_clauses( $selection );

		if ( $meta !== array() ) {
			$meta['relation']    = 'AND';
			$args['meta_query'] = $meta;
		}

		if ( $selection['category'] !== array() ) {
			$args['tax_query'][] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $selection['category'],
				'include_children' => true,
			);
		}

		$expired = Settings::is_on( 'hide_expired' ) ? Expiry::expired_product_ids() : array();

		if ( $expired !== array() ) {
			$args['post__not_in'] = $expired;
		}

		$query = new \WP_Query( $args );
		$count = (int) $query->found_posts;

		set_transient( $key, $count, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * The catalogue-visibility conditions WooCommerce applies to the shop.
	 *
	 * Counting without these would report products the shop refuses to show.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function visibility_clauses(): array {
		if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			return array();
		}

		$ids     = wc_get_product_visibility_term_ids();
		$clauses = array();

		if ( ! empty( $ids['exclude-from-catalog'] ) ) {
			$clauses[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( $ids['exclude-from-catalog'] ),
				'operator' => 'NOT IN',
			);
		}

		if ( get_option( 'woocommerce_hide_out_of_stock_items' ) === 'yes' && ! empty( $ids['outofstock'] ) ) {
			$clauses[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( $ids['outofstock'] ),
				'operator' => 'NOT IN',
			);
		}

		return $clauses;
	}

	/**
	 * Invalidates every cached count.
	 *
	 * Bumping a version stamp beats hunting down dozens of transient keys, one
	 * per option and filter combination.
	 */
	public static function bump_counts_version(): void {
		update_option( 'wcd_counts_version', (string) time(), false );
	}
}
