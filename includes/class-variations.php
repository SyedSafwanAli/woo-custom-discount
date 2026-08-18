<?php
/**
 * Letting a shopper choose which expiry they are buying.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a product held in several expiry batches into a variable product, with
 * one variation per batch.
 *
 * WooCommerce gives a simple product exactly one price. A shop holding the same
 * item with an August date at 60% off and a December date at 10% off needs two,
 * and needs the shopper to pick between them — which is what variations are for.
 *
 * Taking that route rather than inventing one means WooCommerce keeps doing the
 * work it already does correctly: the price range on the shop grid, the choice
 * on the product page, the line on the order, the stock, the refund. None of
 * that is reimplemented here, and none of it is where a mistake costs money.
 *
 * Two rules bound what this touches:
 *
 *  1. Only products in two or more live batches are converted. One batch is one
 *     price, and stays a simple product.
 *  2. Only products this class converted are ever converted back. Anything the
 *     shop made variable itself carries no marker and is left alone.
 */
class Variations {

	/** Attribute the shopper picks from. */
	public const TAXONOMY = 'pa_expiry';

	/** Marks a product this class made variable. */
	public const META_OWNED = '_wcd_owns_variations';

	/** Marks a variation this class created, and which batch it stands for. */
	public const META_BATCH = '_wcd_batch_id';

	/** Remembers what the product was before, so it can be put back. */
	public const META_WAS_TYPE = '_wcd_previous_type';

	/**
	 * The regular price the product had before it became variable.
	 *
	 * A variable product keeps no price of its own, so without this the original
	 * figure would be gone — both for putting it back on the way out, and for
	 * showing what the discount is measured against while it is in.
	 */
	public const META_BASE_REGULAR = '_wcd_base_regular';

	/**
	 * Whether the feature is switched on at all.
	 */
	public static function enabled(): bool {
		return Settings::is_on( 'batch_variations' );
	}

	/**
	 * Hooks the front-end pieces.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// The dropdown WooCommerce renders becomes a row of buttons.
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( __CLASS__, 'add_buttons' ), 10, 2 );

		// And the price gets the figure it is discounted from.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 10, 2 );
	}

	/**
	 * Puts the original price back beside a variable product's range.
	 *
	 * WooCommerce strikes a price through when it can see what the product used
	 * to cost. A variable product keeps no price of its own, so on the shop grid
	 * these read as though 1,798 were simply what the item costs — the one thing
	 * a shop running a 60% sale most wants shown is the 4,495 it is 60% off.
	 *
	 * The figure comes from the price stashed at conversion, which is the same
	 * one put back if the product ever converts out. So there is no second
	 * version of the truth here: it is the product's own regular price, kept
	 * somewhere WooCommerce does not clear.
	 *
	 * @param string      $html    Price HTML WooCommerce built.
	 * @param \WC_Product $product Product it belongs to.
	 */
	public static function price_html( $html, $product ): string {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return (string) $html;
		}

		$product_id = $product->get_id();

		if ( ! self::owns( $product_id ) ) {
			return (string) $html;
		}

		$regular = (float) get_post_meta( $product_id, self::META_BASE_REGULAR, true );

		if ( $regular <= 0 ) {
			return (string) $html;
		}

		$min = (float) $product->get_variation_price( 'min', true );
		$max = (float) $product->get_variation_price( 'max', true );

		if ( $min <= 0 || $min >= $regular ) {
			return (string) $html;
		}

		// Cheapest first, which is the order WooCommerce shows a range in and the
		// order a shopper reads it: the best price this product can be had for,
		// then what it climbs to.
		$now = $min === $max
			? wc_price( $min )
			: wc_format_price_range( $min, $max );

		// Same shape WooCommerce uses for a sale price, so the theme's own styling
		// for struck-through and current prices applies without a line of CSS,
		// and a screen reader is told which is which rather than reading two
		// numbers in a row.
		return '<del aria-hidden="true">' . wc_price( $regular ) . '</del> '
			. '<span class="screen-reader-text">'
			. esc_html__( 'Original price was:', 'woo-custom-discount' ) . ' ' . wp_strip_all_tags( wc_price( $regular ) )
			. '</span> '
			. '<ins aria-hidden="true">' . $now . '</ins>'
			. '<span class="screen-reader-text">'
			. esc_html__( 'Current price is:', 'woo-custom-discount' ) . ' ' . wp_strip_all_tags( $now )
			. '</span>';
	}

	/**
	 * The batches a product should have a variation for.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function batches_for( int $product_id ): array {
		$out = array();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_BATCH, 'enabled' => true ) ) as $batch ) {
			if ( ! in_array( $product_id, $batch['products'], true ) ) {
				continue;
			}

			if ( Rules::is_batch_expired( $batch ) ) {
				continue;
			}

			$out[] = $batch;
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => strcmp( (string) $a['expiry_ym'], (string) $b['expiry_ym'] )
		);

		return $out;
	}

	/**
	 * Whether this product should be offering a choice.
	 */
	public static function should_be_variable( int $product_id ): bool {
		return self::enabled() && count( self::batches_for( $product_id ) ) >= 2;
	}

	/**
	 * Whether this class made this product variable.
	 */
	public static function owns( int $product_id ): bool {
		return get_post_meta( $product_id, self::META_OWNED, true ) === '1';
	}

	/**
	 * Brings one product in line with its batches.
	 *
	 * @return string What happened: converted, updated, reverted or skipped.
	 */
	public static function sync_product( int $product_id ): string {
		// Converting a product saves it, and saving a product fires the hook that
		// leads back here. Without holding the guard across the whole operation
		// — not just around each individual save — this recurses until the
		// request dies.
		Price_Engine::begin_write();

		try {
			return self::sync_product_inner( $product_id );
		} finally {
			Price_Engine::end_write();
		}
	}

	/**
	 * The actual work, with the re-entrancy guard already held.
	 */
	private static function sync_product_inner( int $product_id ): string {
		$batches = self::batches_for( $product_id );

		if ( ! self::enabled() || count( $batches ) < 2 ) {
			return self::owns( $product_id ) ? self::revert( $product_id ) : 'skipped';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return 'skipped';
		}

		// A product the shop made variable itself is not ours to rearrange.
		if ( $product->is_type( 'variable' ) && ! self::owns( $product_id ) ) {
			return 'skipped';
		}

		$regular = (float) self::regular_price( $product );

		if ( $regular <= 0 ) {
			return 'skipped';
		}

		$was_owned = self::owns( $product_id );

		if ( ! $was_owned ) {
			update_post_meta( $product_id, self::META_WAS_TYPE, $product->get_type() );
			update_post_meta( $product_id, self::META_OWNED, '1' );
		}

		// Type first, and re-read afterwards. A variation asks its parent for
		// data that only a variable product carries, and reading it through a
		// stale simple-product object is where the "undefined catalog_visibility"
		// warnings were coming from.
		wp_set_object_terms( $product_id, 'variable', 'product_type' );
		self::refresh( $product_id );

		self::apply_attribute( $product_id, $batches );
		self::apply_variations( $product_id, $batches, $regular );

		self::refresh( $product_id );

		\WC_Product_Variable::sync( $product_id );

		return $was_owned ? 'updated' : 'converted';
	}

	/**
	 * Puts a product back the way it was.
	 */
	public static function revert( int $product_id ): string {
		if ( ! self::owns( $product_id ) ) {
			return 'skipped';
		}

		Price_Engine::begin_write();

		try {
			return self::revert_inner( $product_id );
		} finally {
			Price_Engine::end_write();
		}
	}

	/**
	 * The actual work, with the re-entrancy guard already held.
	 */
	private static function revert_inner( int $product_id ): string {
		foreach ( self::our_variations( $product_id ) as $variation_id ) {
			wp_delete_post( $variation_id, true );
		}

		$product = wc_get_product( $product_id );

		if ( $product ) {
			$attributes = $product->get_attributes();

			unset( $attributes[ self::TAXONOMY ] );

			$product->set_attributes( $attributes );
			$product->save();
		}

		wp_set_object_terms( $product_id, array(), self::TAXONOMY );

		$was = (string) get_post_meta( $product_id, self::META_WAS_TYPE, true );

		delete_post_meta( $product_id, self::META_OWNED );
		delete_post_meta( $product_id, self::META_WAS_TYPE );

		// Last, and only after the save above — saving a variable product writes
		// its own type back, so setting this first would simply be undone.
		wp_set_object_terms( $product_id, $was !== '' ? $was : 'simple', 'product_type' );

		// Without this, the next wc_get_product() hands back the variable object
		// it built a moment ago, and the pricing below decides the product is
		// still variable and leaves it with no price at all.
		self::refresh( $product_id );

		// A variable product does not use its own regular price, and WooCommerce
		// clears it. Put back the figure stashed at conversion, or the product
		// comes out of this with no price at all — which is how it looks to a
		// shopper, not just in the database.
		$base = get_post_meta( $product_id, self::META_BASE_REGULAR, true );

		if ( $base !== '' && (float) $base > 0 ) {
			$simple = wc_get_product( $product_id );

			if ( $simple ) {
				$simple->set_regular_price( (string) $base );
				$simple->save();
			}

			self::refresh( $product_id );
		}

		delete_post_meta( $product_id, self::META_BASE_REGULAR );

		// Back to a single price, worked out the ordinary way.
		Price_Engine::apply_product( $product_id );

		return 'reverted';
	}

	/**
	 * Drops the caches that would otherwise hand back the previous shape of a
	 * product whose type has just changed.
	 */
	private static function refresh( int $product_id ): void {
		clean_post_cache( $product_id );
		clean_object_term_cache( $product_id, 'product' );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
		}
	}

	/**
	 * Every product this class currently owns.
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
	 * Reverts every product this class owns. Used when the feature is switched
	 * off, so that turning it off really does undo it.
	 */
	public static function revert_all(): int {
		$done = 0;

		foreach ( self::owned_product_ids() as $product_id ) {
			if ( self::revert( $product_id ) === 'reverted' ) {
				++$done;
			}
		}

		return $done;
	}

	/**
	 * The price a variation is worked out from.
	 *
	 * Once a product is variable its own regular price is no longer used, so it
	 * is remembered on first conversion and read back afterwards.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function regular_price( $product ): float {
		$stored = get_post_meta( $product->get_id(), self::META_BASE_REGULAR, true );

		if ( $stored !== '' && (float) $stored > 0 ) {
			return (float) $stored;
		}

		$regular = (float) $product->get_regular_price();

		if ( $regular > 0 ) {
			update_post_meta( $product->get_id(), self::META_BASE_REGULAR, (string) $regular );
		}

		return $regular;
	}

	/**
	 * Gives the product the expiry attribute, set up for variations.
	 *
	 * @param array<int,array<string,mixed>> $batches Batches to offer.
	 */
	private static function apply_attribute( int $product_id, array $batches ): void {
		$attribute_id = self::ensure_taxonomy();

		if ( ! $attribute_id ) {
			return;
		}

		$term_ids = array();

		foreach ( $batches as $batch ) {
			$term_id = self::ensure_term( $batch );

			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( $term_ids === array() ) {
			return;
		}

		wp_set_object_terms( $product_id, $term_ids, self::TAXONOMY );

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_id );
		$attribute->set_name( self::TAXONOMY );
		$attribute->set_options( $term_ids );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$attributes = $product->get_attributes();
		$attributes[ self::TAXONOMY ] = $attribute;

		$product->set_attributes( $attributes );

		// Land on the soonest expiry already chosen. An unchosen chooser leaves
		// the add-to-cart button disabled and no price on the page, so the first
		// thing a shopper meets is a dead button — and the soonest date is the
		// one being cleared out, so it is the right one to lead with. Setting it
		// here rather than in the browser means it holds with scripts off.
		$product->set_default_attributes(
			array( self::TAXONOMY => 'wcd-b' . (int) $batches[0]['id'] )
		);

		$product->save();
	}

	/**
	 * Creates or updates one variation per batch, and removes the rest.
	 *
	 * @param array<int,array<string,mixed>> $batches Batches to offer.
	 */
	private static function apply_variations( int $product_id, array $batches, float $regular ): void {
		$existing = array();

		foreach ( self::our_variations( $product_id ) as $variation_id ) {
			$batch_id = (int) get_post_meta( $variation_id, self::META_BATCH, true );

			// Two variations for one batch should not happen; if it does, the
			// spare goes rather than being left to confuse the picker.
			if ( $batch_id && ! isset( $existing[ $batch_id ] ) ) {
				$existing[ $batch_id ] = $variation_id;
			} else {
				wp_delete_post( $variation_id, true );
			}
		}

		$keep = array();

		foreach ( $batches as $index => $batch ) {
			$batch_id = (int) $batch['id'];
			$keep[]   = $batch_id;

			$term = self::ensure_term( $batch );

			if ( ! $term ) {
				continue;
			}

			$term_object = get_term( $term, self::TAXONOMY );

			if ( ! $term_object instanceof \WP_Term ) {
				continue;
			}

			$variation = isset( $existing[ $batch_id ] )
				? new \WC_Product_Variation( $existing[ $batch_id ] )
				: new \WC_Product_Variation();

			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array( self::TAXONOMY => $term_object->slug ) );
			$variation->set_regular_price( (string) $regular );
			$variation->set_sale_price( (string) Price_Engine::discounted_price( $regular, (float) $batch['discount_percent'] ) );

			// The batch ends at the end of its month, and WooCommerce expires the
			// sale price itself on that date.
			$ends = Rules::expiry_end_timestamp( (string) $batch['expiry_ym'] );

			$variation->set_date_on_sale_from( null );
			$variation->set_date_on_sale_to( $ends ? (string) $ends : null );

			$variation->set_menu_order( $index );
			$variation->set_status( 'publish' );

			$variation_id = $variation->save();

			update_post_meta( $variation_id, self::META_BATCH, (string) $batch_id );
		}

		// Anything for a batch this product has left.
		foreach ( $existing as $batch_id => $variation_id ) {
			if ( ! in_array( (int) $batch_id, $keep, true ) ) {
				wp_delete_post( $variation_id, true );
			}
		}
	}

	/**
	 * The variations this class created for a product.
	 *
	 * @return int[]
	 */
	private static function our_variations( int $product_id ): array {
		$ids = get_posts(
			array(
				'post_type'   => 'product_variation',
				'post_parent' => $product_id,
				'post_status' => array( 'publish', 'private', 'draft' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::META_BATCH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Makes sure the expiry attribute exists, and returns its id.
	 */
	private static function ensure_taxonomy(): int {
		$id = wc_attribute_taxonomy_id_by_name( 'expiry' );

		if ( $id ) {
			self::register_taxonomy_now();

			return (int) $id;
		}

		$created = wc_create_attribute(
			array(
				'name'         => __( 'Expiry', 'woo-custom-discount' ),
				'slug'         => 'expiry',
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $created ) ) {
			return 0;
		}

		delete_transient( 'wc_attribute_taxonomies' );

		// WooCommerce registers attribute taxonomies on init, which has already
		// run by the time one is created mid-request. Without this the terms
		// below would have nowhere to go.
		self::register_taxonomy_now();

		return (int) $created;
	}

	/**
	 * Registers the taxonomy for the rest of this request.
	 */
	private static function register_taxonomy_now(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			array( 'product', 'product_variation' ),
			array(
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
				'public'       => false,
			)
		);
	}

	/**
	 * The term standing for one batch, created if need be.
	 *
	 * The slug is tied to the batch id rather than the month, so renaming a
	 * batch or moving its date does not orphan every variation using it.
	 *
	 * @param array<string,mixed> $batch Batch data.
	 */
	private static function ensure_term( array $batch ): int {
		self::register_taxonomy_now();

		$slug  = 'wcd-b' . (int) $batch['id'];
		$label = $batch['expiry_ym']
			? Importer::format_expiry( (string) $batch['expiry_ym'] )
			: (string) $batch['title'];

		$term = get_term_by( 'slug', $slug, self::TAXONOMY );

		if ( $term instanceof \WP_Term ) {
			if ( $term->name !== $label ) {
				wp_update_term( $term->term_id, self::TAXONOMY, array( 'name' => $label ) );
			}

			return (int) $term->term_id;
		}

		$created = wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );

		if ( is_wp_error( $created ) ) {
			return 0;
		}

		return (int) $created['term_id'];
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Loads the swatch styling on a product page that needs it.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_style( 'wcd-variations', WCD_URL . 'assets/variations.css', array(), WCD_VERSION );
		wp_enqueue_script( 'wcd-variations', WCD_URL . 'assets/variations.js', array( 'jquery' ), WCD_VERSION, true );
	}

	/**
	 * Adds a row of buttons alongside WooCommerce's dropdown.
	 *
	 * The dropdown stays in the markup and keeps doing the work — it is what
	 * WooCommerce's own variation script reads and writes. The buttons drive it
	 * rather than replacing it, so nothing downstream has to be reimplemented.
	 *
	 * @param string              $html Dropdown markup.
	 * @param array<string,mixed> $args Dropdown arguments.
	 */
	public static function add_buttons( $html, $args ): string {
		$attribute = (string) ( $args['attribute'] ?? '' );

		if ( $attribute !== self::TAXONOMY ) {
			return (string) $html;
		}

		$product = $args['product'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return (string) $html;
		}

		$options = (array) ( $args['options'] ?? array() );

		if ( $options === array() ) {
			return (string) $html;
		}

		$prices = self::variation_prices( $product );
		$rows   = '';

		foreach ( $options as $slug ) {
			$term = get_term_by( 'slug', $slug, $attribute );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$price   = $prices[ $slug ] ?? null;
			$percent = self::percent_for_slug( $slug );

			$rows .= sprintf(
				'<tr class="wcd-choice__row" data-value="%1$s" tabindex="0" role="radio" aria-checked="false">
					<td class="wcd-choice__pick"><span class="wcd-choice__dot" aria-hidden="true"></span></td>
					<td class="wcd-choice__month">%2$s</td>
					<td class="wcd-choice__off">%3$s</td>
					<td class="wcd-choice__price">%4$s</td>
				</tr>',
				esc_attr( $slug ),
				esc_html( $term->name ),
				$percent > 0
					? esc_html(
						sprintf(
							/* translators: %s: percentage. */
							__( '%s%% off', 'woo-custom-discount' ),
							Admin_Rules::percent_label( $percent )
						)
					)
					: '',
				$price !== null ? wp_kses_post( wc_price( $price ) ) : ''
			);
		}

		if ( $rows === '' ) {
			return (string) $html;
		}

		$buttons = sprintf(
			'<table class="wcd-choice" role="radiogroup" aria-label="%1$s"><tbody>%2$s</tbody></table>',
			esc_attr__( 'Choose an expiry date', 'woo-custom-discount' ),
			$rows
		);

		// The dropdown goes inside a wrapper of our own rather than being hidden
		// where it stands. Hiding it in place meant competing with the theme's
		// rules for the select itself, which is a fight to be re-fought with
		// every theme and every update; nothing else styles this wrapper.
		return sprintf(
			'<div class="wcd-swatches" data-attribute="%1$s">%2$s</div><span class="wcd-native">%3$s</span>',
			esc_attr( $attribute ),
			$buttons,
			$html
		);
	}

	/**
	 * The discount behind one option.
	 *
	 * The term slug carries the batch id, which is what makes this possible
	 * without another lookup table — and what keeps the link intact when a batch
	 * is renamed or its month moved.
	 */
	private static function percent_for_slug( string $slug ): float {
		if ( ! preg_match( '/^wcd-b(\d+)$/', $slug, $m ) ) {
			return 0.0;
		}

		$rule = Rules::get( (int) $m[1] );

		return $rule ? (float) $rule['discount_percent'] : 0.0;
	}

	/**
	 * What each option costs, so the buttons can say so.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array<string,float>
	 */
	private static function variation_prices( $product ): array {
		$out = array();

		if ( ! $product->is_type( 'variable' ) ) {
			return $out;
		}

		foreach ( $product->get_available_variations() as $variation ) {
			$slug = $variation['attributes'][ 'attribute_' . self::TAXONOMY ] ?? '';

			if ( $slug !== '' ) {
				$out[ $slug ] = (float) $variation['display_price'];
			}
		}

		return $out;
	}
}
