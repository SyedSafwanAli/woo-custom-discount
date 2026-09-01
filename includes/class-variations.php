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
 *  1. Only products with two or more things to choose between are converted —
 *     counted across the batches and the campaign together, since one batch and
 *     a campaign is as much a choice as two batches are. A product with nothing
 *     to weigh up stays a simple product.
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
	 * The stock the product was managing before it became variable.
	 *
	 * A variable product manages stock on its variations, so WooCommerce takes
	 * the parent's own stock fields out of use — and reverting gave the product
	 * back with nothing in them, which reads as out of stock.
	 */
	public const META_BASE_STOCK = '_wcd_base_stock';

	/**
	 * Which picture goes with which batch, as batch id => attachment id.
	 *
	 * Kept on the product rather than on the variation, because a variation is
	 * rebuilt whenever the batches change and anything written on it is lost
	 * with it. The product outlives every rebuild, so the choice does too.
	 */
	public const META_BATCH_IMAGES = '_wcd_batch_images';

	/**
	 * The picture chosen for each of a product's batches.
	 *
	 * @return array<int,int> Batch id => attachment id.
	 */
	public static function images_for( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META_BATCH_IMAGES, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $batch_id => $attachment_id ) {
			$batch_id      = (int) $batch_id;
			$attachment_id = (int) $attachment_id;

			// A picture deleted from the media library would otherwise leave the
			// variation pointing at nothing.
			if ( $batch_id > 0 && $attachment_id > 0 && get_post_type( $attachment_id ) === 'attachment' ) {
				$out[ $batch_id ] = $attachment_id;
			}
		}

		return $out;
	}

	/**
	 * How many are left in each batch, as batch id => quantity.
	 *
	 * Optional, like the picture: a batch with no number here is not counted
	 * separately and sells against the product's own stock, which is how every
	 * batch behaved before this existed.
	 *
	 * Kept on the product for the same reason as the pictures — a variation is
	 * rebuilt whenever the batches change, and the count would go with it. It is
	 * written back from the variation after every sale, so what is stored is
	 * always what is left, not what was first put in.
	 */
	public const META_BATCH_STOCK = '_wcd_batch_stock';

	/**
	 * The quantity left in each of a product's batches.
	 *
	 * @return array<int,int> Batch id => quantity. Batches with no limit are absent.
	 */
	public static function stock_for( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META_BATCH_STOCK, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $batch_id => $quantity ) {
			$batch_id = (int) $batch_id;

			if ( $batch_id > 0 && $quantity !== '' && $quantity !== null ) {
				$out[ $batch_id ] = (int) $quantity;
			}
		}

		return $out;
	}

	/**
	 * Sets or clears the quantity for one batch on one product.
	 *
	 * @param int|null $quantity Number left, or null for no separate count.
	 */
	public static function set_stock( int $product_id, int $batch_id, ?int $quantity ): void {
		$stock = self::stock_for( $product_id );

		if ( $quantity === null ) {
			unset( $stock[ $batch_id ] );
		} else {
			$stock[ $batch_id ] = max( 0, $quantity );
		}

		if ( $stock === array() ) {
			delete_post_meta( $product_id, self::META_BATCH_STOCK );
		} else {
			update_post_meta( $product_id, self::META_BATCH_STOCK, $stock );
		}
	}

	/**
	 * Drops every product's picture and count for a batch that has gone.
	 *
	 * Only products that hold one of these keys are looked at, which on this
	 * catalogue is the handful in batches rather than all of them.
	 */
	public static function forget_batch( int $batch_id ): void {
		global $wpdb;

		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
				self::META_BATCH_IMAGES,
				self::META_BATCH_STOCK
			)
		);

		foreach ( array_map( 'intval', (array) $product_ids ) as $product_id ) {
			self::set_image( $product_id, $batch_id, 0 );
			self::set_stock( $product_id, $batch_id, null );
		}
	}

	/**
	 * Sets or clears the picture for one batch on one product.
	 *
	 * @param int $attachment_id Attachment, or 0 to go back to the product's own.
	 */
	public static function set_image( int $product_id, int $batch_id, int $attachment_id ): void {
		$images = self::images_for( $product_id );

		if ( $attachment_id > 0 ) {
			$images[ $batch_id ] = $attachment_id;
		} else {
			unset( $images[ $batch_id ] );
		}

		if ( $images === array() ) {
			delete_post_meta( $product_id, self::META_BATCH_IMAGES );
		} else {
			update_post_meta( $product_id, self::META_BATCH_IMAGES, $images );
		}
	}

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

		// An offer that moves no price still has something to say up there.
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 10, 3 );

		// A picture given to an offer is the picture of what is being sold.
		add_filter( 'woocommerce_product_get_image_id', array( __CLASS__, 'image_id' ), 10, 2 );

		// Every sale takes one off the batch it came from.
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_variation_stock' ) );

		// And the order says which batch it was, in words, for good.
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'stamp_order_item' ), 10, 2 );
	}

	/**
	 * Writes the batch onto the order line, in words.
	 *
	 * The order already carries which one was bought — WooCommerce puts the
	 * variation's name on the line and skips the attribute row because of it. But
	 * that record thins out over time: the value stored is the slug wcd-b81, and
	 * what a person reads is looked up from the batch when the order is opened.
	 * Rename that batch, move its month, or let it be deleted once it is sold out,
	 * and an order from last March starts describing something else.
	 *
	 * So the month and the discount are written onto the line at the moment of
	 * sale and never touched again. An order is a record of what happened, and it
	 * should not change because the shop moved on.
	 *
	 * @param int   $item_id Order item ID.
	 * @param mixed $item    Order item.
	 */
	/**
	 * Reads the marker a variation carries, as kind and id.
	 *
	 * Older variations were stamped with a bare batch id. Those are still out
	 * there on live orders and in the database, so a plain number is read as the
	 * batch it has always been.
	 *
	 * @return array{kind:string,id:int}|null
	 */
	private static function read_marker( int $variation_id ): ?array {
		$raw = (string) get_post_meta( $variation_id, self::META_BATCH, true );

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/^([bc])(\d+)$/', $raw, $m ) ) {
			return array(
				'kind' => 'b' === $m[1] ? 'batch' : 'campaign',
				'id'   => (int) $m[2],
			);
		}

		return ctype_digit( $raw ) ? array( 'kind' => 'batch', 'id' => (int) $raw ) : null;
	}

	public static function stamp_order_item( $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$variation_id = $item->get_variation_id();

		if ( ! $variation_id ) {
			return;
		}

		$marker = self::read_marker( $variation_id );

		if ( $marker === null ) {
			return;
		}

		$batch = Rules::get( $marker['id'] );

		if ( ! $batch ) {
			return;
		}

		// A campaign line has no expiry to record. Its own name is what the order
		// should say, so the shop can see later which offer it was sold under.
		$month = 'campaign' === $marker['kind']
			? (string) $batch['title']
			: ( ! empty( $batch['expiry_ym'] )
				? Importer::format_expiry( (string) $batch['expiry_ym'] )
				: (string) $batch['title'] );

		$percent = (float) $batch['discount_percent'];

		$item->add_meta_data(
			__( 'Expiry batch', 'woo-custom-discount' ),
			$percent > 0
				? sprintf(
					/* translators: 1: expiry month, 2: discount percentage. */
					__( '%1$s — %2$s%% off', 'woo-custom-discount' ),
					$month,
					Admin_Rules::percent_label( $percent )
				)
				: $month,
			true
		);

		$item->save_meta_data();
	}

	/**
	 * Follows a batch's stock down, and retires the batch when it runs out.
	 *
	 * WooCommerce reduces the variation's stock on every order by itself. What it
	 * cannot know is that the variation stands for a batch: when the last August
	 * one is sold, August is not merely out of stock, it is over, and the product
	 * should go back to being sold at whatever its remaining batches say.
	 *
	 * Runs on the admin's own stock edits too, which is the point — correcting a
	 * count by hand should behave exactly like selling one.
	 *
	 * @param \WC_Product_Variation|mixed $variation The variation whose stock changed.
	 */
	public static function on_variation_stock( $variation ): void {
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		// Rebuilding a product's variations sets stock as it goes. Answering those
		// writes would have us retiring batches in the middle of building them.
		if ( Price_Engine::is_busy() ) {
			return;
		}

		$variation_id = $variation->get_id();
		$marker       = self::read_marker( $variation_id );

		// Only a batch keeps a count of its own. The campaign line sells against
		// the product's ordinary stock, so there is nothing here to write back.
		if ( $marker === null || 'batch' !== $marker['kind'] ) {
			return;
		}

		$batch_id = $marker['id'];

		$product_id = $variation->get_parent_id();

		if ( ! $product_id || ! self::owns( $product_id ) ) {
			return;
		}

		$left = $variation->get_stock_quantity();

		if ( $left === null ) {
			return;
		}

		$left = (int) $left;

		if ( $left > 0 ) {
			// Keep the product's own record in step, so a rebuild restores what is
			// left rather than what was first put in.
			self::set_stock( $product_id, $batch_id, $left );

			return;
		}

		self::retire_batch( $product_id, $batch_id );
	}

	/**
	 * Takes a sold-out batch off one product and rebuilds what is left.
	 */
	private static function retire_batch( int $product_id, int $batch_id ): void {
		$keep = array();

		foreach ( self::batches_for( $product_id ) as $batch ) {
			if ( (int) $batch['id'] !== $batch_id ) {
				$keep[] = (int) $batch['id'];
			}
		}

		// Only the batch that ran out is touched; every other batch this product
		// is in, and every other product in this batch, is left alone.
		Rules::set_product_rules( $product_id, $keep, array( $batch_id ) );

		self::set_stock( $product_id, $batch_id, null );
		self::set_image( $product_id, $batch_id, 0 );

		Resolver::flush();
		Expiry::flush_cache();

		// One batch left means a simple product again, none means no discount —
		// both of which this already knows how to do.
		Price_Engine::apply_product( $product_id );
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
		if ( ! $product instanceof \WC_Product ) {
			return (string) $html;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return self::offer_html( (string) $html, $product );
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
	 * Says what the chosen option gives, beside its price.
	 *
	 * WooCommerce prints a struck-through price and a lower one, which tells the
	 * whole story for a discount and none of it for an offer that leaves the
	 * price alone. Choosing "Buy One Get One Free" replaced that pair with a
	 * single unremarkable figure, and the shopper had nothing to read but the
	 * price they were already looking at.
	 *
	 * So whatever the option says in the list it says here too, once chosen.
	 *
	 * @param array<string,mixed>   $data      Variation data WooCommerce built.
	 * @param \WC_Product           $product   The parent.
	 * @param \WC_Product_Variation $variation The variation.
	 * @return array<string,mixed>
	 */
	public static function variation_data( $data, $product, $variation ): array {
		$data = (array) $data;

		if ( ! $variation instanceof \WC_Product_Variation || ! isset( $data['price_html'] ) ) {
			return $data;
		}

		$marker = self::read_marker( $variation->get_id() );

		if ( $marker === null ) {
			return $data;
		}

		$rule = Rules::get( $marker['id'] );

		if ( ! $rule ) {
			return $data;
		}

		$badge = self::badge_for_slug( ( 'campaign' === $marker['kind'] ? 'wcd-c' : 'wcd-b' ) . $marker['id'] );
		$units = Rules::units( $rule );
		$paid  = (float) $variation->get_price();

		// An offer that hands over more than one shows what that many would
		// ordinarily cost, struck through, beside the one payment being asked
		// for. Nothing is written to the database: the variation keeps its own
		// price, and the cart charges it.
		if ( $units > 1 && $paid > 0 ) {
			$data['price_html'] = '<span class="price">' . self::was_and_now( $paid * $units, $paid ) . '</span>';
		} elseif ( (float) $variation->get_sale_price() > 0 ) {
			// A plain discount already shows as a struck-through price beside a
			// lower one, so repeating the percentage would only say it twice.
			return $data;
		}

		if ( $badge !== '' ) {
			$data['price_html'] .= sprintf(
				'<span class="wcd-offer">%s</span>',
				esc_html( $badge )
			);
		}

		return $data;
	}

	/**
	 * The picture the offer brought, in front of the product's own.
	 *
	 * A shop that photographs two bottles for a buy-one-get-one had that picture
	 * shown only once a shopper picked that row — and on a product with nothing
	 * to pick, never at all. What is on offer is what should be pictured, on the
	 * shop grid as much as on the page.
	 *
	 * Variations are left alone: one already carries its own picture, and this
	 * would put the first option's picture on every one of them.
	 *
	 * @param int|string  $image_id Attachment WooCommerce would have used.
	 * @param \WC_Product $product  Product it belongs to.
	 * @return int|string
	 */
	public static function image_id( $image_id, $product ) {
		if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) ) {
			return $image_id;
		}

		$ours = self::offer_image_id( $product->get_id() );

		return $ours > 0 ? $ours : $image_id;
	}

	/**
	 * The picture belonging to the first offer on a product that has one.
	 *
	 * First in the order the shop set, so the row a shopper reads at the top is
	 * the one the picture belongs to.
	 */
	private static function offer_image_id( int $product_id ): int {
		static $seen = array();

		if ( isset( $seen[ $product_id ] ) ) {
			return $seen[ $product_id ];
		}

		$images = self::images_for( $product_id );

		// Almost every product has none, and this is called for every product on
		// the shop grid, so nothing further is looked up until one does.
		if ( $images === array() ) {
			$seen[ $product_id ] = 0;

			return 0;
		}

		$options = self::options_for( $product_id );

		// A product with a campaign and no batch has nothing to choose between,
		// so it has no options — but it does have the one rule that reached it.
		if ( $options === array() ) {
			$outcome = Resolver::resolve( $product_id );
			$rule    = $outcome === null ? null : Rules::get( (int) $outcome['rule_id'] );

			$seen[ $product_id ] = $rule ? (int) ( $images[ (int) $rule['id'] ] ?? 0 ) : 0;

			return $seen[ $product_id ];
		}

		foreach ( $options as $option ) {
			$rule_id = (int) $option['rule']['id'];

			if ( isset( $images[ $rule_id ] ) ) {
				$seen[ $product_id ] = (int) $images[ $rule_id ];

				return $seen[ $product_id ];
			}
		}

		$seen[ $product_id ] = 0;

		return 0;
	}

	/**
	 * The same, for a product with nothing to choose between.
	 *
	 * A product carrying one offer and no second option never becomes variable,
	 * so there is no list of choices and no row to read the offer off. Buy one
	 * get one free takes nothing off the price it is sold at, so such a product
	 * showed a bare figure and no sign at all that anything was on offer — which
	 * is exactly how the shop found it.
	 *
	 * @param \WC_Product $product Product it belongs to.
	 */
	private static function offer_html( string $html, \WC_Product $product ): string {
		$outcome = Resolver::resolve( $product->get_id() );

		if ( $outcome === null ) {
			return $html;
		}

		$rule = Rules::get( (int) $outcome['rule_id'] );

		if ( ! $rule ) {
			return $html;
		}

		$units = Rules::units( $rule );
		$paid  = (float) $product->get_price();

		if ( $units > 1 && $paid > 0 ) {
			$html = self::was_and_now( $paid * $units, $paid );
		}

		$badge = self::badge_for_slug( ( Rules::TYPE_CAMPAIGN === $rule['type'] ? 'wcd-c' : 'wcd-b' ) . (int) $rule['id'] );

		// Only where the price does not already tell the story on its own.
		if ( $badge !== '' && ( $units > 1 || (float) $product->get_sale_price() <= 0 ) ) {
			$html .= sprintf( '<span class="wcd-offer">%s</span>', esc_html( $badge ) );
		}

		return $html;
	}

	/**
	 * A struck-through figure beside the one being charged.
	 *
	 * The shape WooCommerce uses for a sale price, so the theme's own styling
	 * applies without a line of CSS, and a screen reader is told which is which
	 * rather than reading two numbers in a row.
	 */
	private static function was_and_now( float $was, float $now ): string {
		return '<del aria-hidden="true">' . wc_price( $was ) . '</del> '
			. '<span class="screen-reader-text">'
			. esc_html__( 'Original price was:', 'woo-custom-discount' ) . ' ' . wp_strip_all_tags( wc_price( $was ) )
			. '</span> '
			. '<ins aria-hidden="true">' . wc_price( $now ) . '</ins>'
			. '<span class="screen-reader-text">'
			. esc_html__( 'Current price is:', 'woo-custom-discount' ) . ' ' . wp_strip_all_tags( wc_price( $now ) )
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
	/**
	 * Everything a shopper may choose between on this product.
	 *
	 * The batches, and the campaign that covers the product, side by side.
	 *
	 * There is no precedence between them any more. A campaign shows unless the
	 * product has been taken out of it on purpose — being in a batch is not that
	 * purpose. The two answer different questions: the batch is what the
	 * short-dated stock costs, the campaign is what the ordinary stock costs, and
	 * a shopper is entitled to both.
	 *
	 * That includes a product in a single batch. Under the old rule such a
	 * product had one option, so no chooser, so the campaign it qualified for
	 * never appeared — and where the batch sat at 0% the shopper was quoted full
	 * price for something the shop was running an offer on.
	 *
	 * @return array<int,array<string,mixed>> Each: kind, rule, percent.
	 */
	public static function options_for( int $product_id ): array {
		$batches = self::batches_for( $product_id );

		if ( $batches === array() ) {
			return array();
		}

		$options = array();

		foreach ( $batches as $batch ) {
			$options[] = array(
				'kind'    => 'batch',
				'rule'    => $batch,
				'percent' => (float) $batch['discount_percent'],
			);
		}

		$campaign = Resolver::campaign_for( $product_id );

		if ( $campaign !== null ) {
			$rule = Rules::get( (int) $campaign['rule_id'] );

			if ( $rule ) {
				$options[] = array(
					'kind'    => 'campaign',
					'rule'    => $rule,
					'percent' => (float) $campaign['percent'],
					'ends_at' => $campaign['ends_at'],
				);
			}
		}

		// The shopper sees these in this order, so the shop decides it. Batches
		// used to come first and the campaign last, which is only ever right by
		// accident — a shop leading with its headline discount wants that row at
		// the top whichever kind it is. Equal numbers fall back to the id, so the
		// order never wobbles between one page load and the next.
		usort(
			$options,
			static function ( array $a, array $b ): int {
				$by_order = (int) $a['rule']['priority'] <=> (int) $b['rule']['priority'];

				return $by_order !== 0 ? $by_order : (int) $a['rule']['id'] <=> (int) $b['rule']['id'];
			}
		);

		return $options;
	}

	public static function should_be_variable( int $product_id ): bool {
		// Counted in options rather than batches: one batch and a campaign is
		// two things to choose between, and needs a chooser just as much as two
		// batches do.
		return self::enabled() && count( self::options_for( $product_id ) ) >= 2;
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
		$options = self::options_for( $product_id );

		if ( ! self::enabled() || count( $options ) < 2 ) {
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
			self::remember_stock( $product );
		}

		// Type first, and re-read afterwards. A variation asks its parent for
		// data that only a variable product carries, and reading it through a
		// stale simple-product object is where the "undefined catalog_visibility"
		// warnings were coming from.
		wp_set_object_terms( $product_id, 'variable', 'product_type' );
		self::refresh( $product_id );

		self::apply_attribute( $product_id, $options );
		self::apply_variations( $product_id, $options, $regular );

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

		self::restore_stock( $product_id );

		// Back to a single price, worked out the ordinary way.
		Price_Engine::apply_product( $product_id );

		return 'reverted';
	}

	/**
	 * Writes down what the product was managing before it became variable.
	 *
	 * @param \WC_Product $product Product, still in its original shape.
	 */
	private static function remember_stock( $product ): void {
		update_post_meta(
			$product->get_id(),
			self::META_BASE_STOCK,
			array(
				'manage_stock'     => $product->get_manage_stock(),
				'stock_quantity'   => $product->get_stock_quantity(),
				'stock_status'     => $product->get_stock_status(),
				'backorders'       => $product->get_backorders(),
				'low_stock_amount' => $product->get_low_stock_amount(),
			)
		);
	}

	/**
	 * Gives the stock fields back on the way out.
	 *
	 * Without this the product returns saying "6 in stock" nowhere and "out of
	 * stock" everywhere: a variable product keeps its stock on its variations, so
	 * WooCommerce stops managing it on the parent, and deleting the variations
	 * leaves the parent with nothing to be in stock with. Nobody removing a
	 * product from a batch is asking for it to leave the shop.
	 */
	private static function restore_stock( int $product_id ): void {
		$stock = get_post_meta( $product_id, self::META_BASE_STOCK, true );

		if ( ! is_array( $stock ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$product->set_manage_stock( ! empty( $stock['manage_stock'] ) );
		$product->set_stock_quantity( $stock['stock_quantity'] ?? null );
		$product->set_backorders( (string) ( $stock['backorders'] ?? 'no' ) );
		$product->set_low_stock_amount( $stock['low_stock_amount'] ?? '' );

		// Last, because setting a quantity can move the status on its own, and the
		// status the product actually had is the one to end on.
		$product->set_stock_status( (string) ( $stock['stock_status'] ?? 'instock' ) );

		$product->save();

		delete_post_meta( $product_id, self::META_BASE_STOCK );

		self::refresh( $product_id );
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
	private static function apply_attribute( int $product_id, array $options ): void {
		$attribute_id = self::ensure_taxonomy();

		if ( ! $attribute_id ) {
			return;
		}

		$term_ids = array();

		foreach ( $options as $option ) {
			$term_id = self::ensure_term( $option );

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

		// Nothing chosen to begin with.
		//
		// This used to land on the soonest expiry, so that the page opened with a
		// live price and a working button rather than a dead one. But choosing
		// for the shopper decides which stock they are buying before they have
		// looked, and it makes the price above the chooser a lie the moment the
		// page loads: the range says two prices are available while the product
		// has already been narrowed to one.
		//
		// Unchosen is the truthful state. The range stands until the shopper
		// picks, and picking is what a variable product asks of them anyway.
		$product->set_default_attributes( array() );

		$product->save();
	}

	/**
	 * Creates or updates one variation per batch, and removes the rest.
	 *
	 * @param array<int,array<string,mixed>> $batches Batches to offer.
	 */
	private static function apply_variations( int $product_id, array $options, float $regular ): void {
		$images   = self::images_for( $product_id );
		$stock    = self::stock_for( $product_id );
		$existing = array();

		foreach ( self::our_variations( $product_id ) as $variation_id ) {
			$marker = (string) get_post_meta( $variation_id, self::META_BATCH, true );

			// Variations built before campaigns joined the choice carry a bare
			// batch id. Read as-is they would not match the new marker and every
			// one of them would be rebuilt from scratch, losing its id and with
			// it anything WooCommerce had hung off that id.
			if ( ctype_digit( $marker ) ) {
				$marker = 'b' . $marker;
			}

			// Two variations for one option should not happen; if it does, the
			// spare goes rather than being left to confuse the picker.
			if ( '' !== $marker && ! isset( $existing[ $marker ] ) ) {
				$existing[ $marker ] = $variation_id;
			} else {
				wp_delete_post( $variation_id, true );
			}
		}

		$keep = array();

		foreach ( $options as $index => $option ) {
			$rule       = $option['rule'];
			$is_campaign = ( $option['kind'] ?? 'batch' ) === 'campaign';

			// Batches and campaigns share one id sequence, so the marker carries
			// the letter as well and the two cannot be mistaken for each other.
			$batch_id = (int) $rule['id'];
			$marker   = ( $is_campaign ? 'c' : 'b' ) . $batch_id;
			$keep[]   = $marker;

			$term = self::ensure_term( $option );

			if ( ! $term ) {
				continue;
			}

			$term_object = get_term( $term, self::TAXONOMY );

			if ( ! $term_object instanceof \WP_Term ) {
				continue;
			}

			$variation = isset( $existing[ $marker ] )
				? new \WC_Product_Variation( $existing[ $marker ] )
				: new \WC_Product_Variation();

			// discounted_price() answers 0 for "no discount", which is the right
			// answer to the question and the wrong thing to write into a price.
			// A batch on 0% records an expiry and leaves the price alone; passing
			// that 0 straight through put the variation on sale at nothing.
			$percent = (float) $option['percent'];
			$sale    = $percent > 0 ? Price_Engine::discounted_price( $regular, $percent ) : 0.0;

			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array( self::TAXONOMY => $term_object->slug ) );
			$variation->set_regular_price( (string) $regular );
			$variation->set_sale_price( $sale > 0 ? (string) $sale : '' );

			// A batch ends at the end of its month; a campaign ends when it was
			// told to, or not at all. Either way WooCommerce expires the sale
			// price itself on that date. With no sale there is nothing to expire,
			// and a date left behind would be read against an empty price.
			if ( $sale <= 0 ) {
				$ends = null;
			} elseif ( $is_campaign ) {
				$ends = $option['ends_at'] ?? null;
			} else {
				$ends = Rules::expiry_end_timestamp( (string) $rule['expiry_ym'] );
			}

			$variation->set_date_on_sale_from( null );
			$variation->set_date_on_sale_to( $ends ? (string) $ends : null );

			// Any option with its own picture gets it; one without falls back to
			// the product's, which is what WooCommerce does with an empty image
			// id. Set every time, so clearing a picture takes effect as surely
			// as choosing one.
			$variation->set_image_id(
				isset( $images[ $batch_id ] ) ? (string) $images[ $batch_id ] : ''
			);

			// A batch given a number counts down on its own; one without sells
			// against the product's stock, as every batch did before this. Once
			// the variation is managing its own, WooCommerce takes each sale off
			// it without anything here being involved.
			//
			// The campaign never counts separately. It stands for the product's
			// ordinary stock rather than a lot of its own, so a number here would
			// be a second, competing tally of the same shelf.
			if ( ! $is_campaign && isset( $stock[ $batch_id ] ) ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $stock[ $batch_id ] );
				$variation->set_backorders( 'no' );
			} else {
				$variation->set_manage_stock( false );
				$variation->set_stock_quantity( null );
			}

			$variation->set_menu_order( $index );
			$variation->set_status( 'publish' );

			$variation_id = $variation->save();

			update_post_meta( $variation_id, self::META_BATCH, $marker );
		}

		// Anything for an option this product no longer offers. Compared as the
		// markers they are: casting to int turned every one of them into 0, so
		// nothing ever matched and each rebuild deleted the last one's work.
		foreach ( $existing as $marker => $variation_id ) {
			if ( ! in_array( (string) $marker, $keep, true ) ) {
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
	 * The term standing for one option, created if need be.
	 *
	 * The slug is tied to the rule's id rather than to its month or title, so
	 * renaming a batch or moving its date does not orphan every variation using
	 * it. Batches and campaigns are told apart by the letter, since the two share
	 * one id sequence and would otherwise collide.
	 *
	 * @param array<string,mixed> $option From options_for(): kind and rule.
	 */
	private static function ensure_term( array $option ): int {
		self::register_taxonomy_now();

		$rule = $option['rule'];

		if ( ( $option['kind'] ?? 'batch' ) === 'campaign' ) {
			// The campaign is named, not dated — its own title is the only thing
			// that would mean anything to a shopper reading the choice.
			$slug  = 'wcd-c' . (int) $rule['id'];
			$label = (string) $rule['title'];
		} else {
			// A batch is listed by the month its stock expires in. That is what
			// a shopper choosing between two lots wants to know, so it is the
			// default. Where the batch is an offer rather than a date, the store
			// owner names it and that name is shown in place of the month.
			$slug   = 'wcd-b' . (int) $rule['id'];
			$chosen = trim( (string) ( $rule['display_label'] ?? '' ) );
			$label  = $chosen !== ''
				? $chosen
				: ( $rule['expiry_ym']
					? Importer::format_expiry( (string) $rule['expiry_ym'] )
					: (string) $rule['title'] );
		}

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
		if ( ! function_exists( 'is_product' ) ) {
			return;
		}

		// The offer beside a price is drawn wherever a price is, so the styles
		// go out on the shop grid too. Only the product page has anything to
		// choose between, which is all the script is for.
		if ( ! is_product() && ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		wp_enqueue_style( 'wcd-variations', WCD_URL . 'assets/variations.css', array(), WCD_VERSION );

		if ( is_product() ) {
			wp_enqueue_script( 'wcd-variations', WCD_URL . 'assets/variations.js', array( 'jquery' ), WCD_VERSION, true );
		}
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

			$price = $prices[ $slug ] ?? null;
			$badge = self::badge_for_slug( $slug );

			$rows .= sprintf(
				'<tr class="wcd-choice__row" data-value="%1$s" tabindex="0" role="radio" aria-checked="false">
					<td class="wcd-choice__pick"><span class="wcd-choice__dot" aria-hidden="true"></span></td>
					<td class="wcd-choice__month">%2$s</td>
					<td class="wcd-choice__off">%3$s</td>
					<td class="wcd-choice__price">%4$s</td>
				</tr>',
				esc_attr( $slug ),
				esc_html( $term->name ),
				esc_html( $badge ),
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
	 * What to print where the percentage goes.
	 *
	 * An offer that takes nothing off the price has nothing to say here, and
	 * "Buy One Get One Free" sat beside its full price with the column blank
	 * while the row under it read "10% off". The shop can write the words
	 * itself; failing that, what the offer comes to is worked out — free extras
	 * included, so two for the price of one reads as the half price it is.
	 */
	private static function badge_for_slug( string $slug ): string {
		$rule = self::rule_for_slug( $slug );

		if ( ! $rule ) {
			return '';
		}

		$badge = trim( (string) ( $rule['badge'] ?? '' ) );

		if ( $badge !== '' ) {
			return $badge;
		}

		$percent = Rules::effective_percent( $rule );

		return $percent > 0
			? sprintf(
				/* translators: %s: percentage. */
				__( '%s%% off', 'woo-custom-discount' ),
				Admin_Rules::percent_label( $percent )
			)
			: '';
	}

	/**
	 * The rule one option stands for.
	 *
	 * The term slug carries the rule's id, which is what makes this possible
	 * without another lookup table — and what keeps the link intact when a batch
	 * is renamed or its month moved. Batch or campaign: both are rules, and the
	 * shopper weighing them up wants to see what each one offers.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function rule_for_slug( string $slug ): ?array {
		if ( ! preg_match( '/^wcd-[bc](\d+)$/', $slug, $m ) ) {
			return null;
		}

		return Rules::get( (int) $m[1] );
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
