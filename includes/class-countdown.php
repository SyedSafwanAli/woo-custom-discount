<?php
/**
 * Countdown timers for sale endings and expiry.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the two countdowns: sale ending, and expiry.
 *
 * The server prints only an end timestamp; the browser does the counting. That
 * is not a stylistic choice — a countdown rendered as text on the server gets
 * frozen by page caching, so every visitor would see whatever the clock said
 * when the page was cached. LiteSpeed is running on the live site, so this is
 * the only way it can work.
 *
 * A product is either in an expiry batch or in a campaign, so it has at most one
 * countdown. If both were somehow possible, the one ending sooner is the one
 * worth showing.
 */
class Countdown {

	/** Per-product override: show even if the rule says no. */
	public const META_FORCE = '_wcd_countdown_force';

	/** Per-product override: never show on this product. */
	public const META_HIDE = '_wcd_countdown_hide';

	/** Per-product override: show the expiry date on the product page. */
	public const META_SHOW_EXPIRY = '_wcd_show_expiry';

	/**
	 * Hooks the countdown into the grid and the single product page.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// A product page built in Divi's Theme Builder is assembled from Divi's
		// own Woo modules, so woocommerce_single_product_summary never fires and
		// nothing hooked to it appears. These give a way to place the same
		// things by hand, in a Code module.
		add_shortcode( 'wcd_countdown', array( __CLASS__, 'shortcode_countdown' ) );
		add_shortcode( 'wcd_expiry', array( __CLASS__, 'shortcode_expiry' ) );
		add_shortcode( 'wcd_savings', array( __CLASS__, 'shortcode_savings' ) );

		if ( self::loop_is_overlay() ) {
			// A wrapper of our own around the thumbnail, opened before it and
			// closed after. Anchoring to the card's own link does not work:
			// WooCommerce keeps that link open past the title and the price, so
			// "bottom" lands under the price rather than on the image.
			add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'open_media' ), 9 );
			add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'close_media' ), 20 );
		} else {
			add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_loop' ), 15 );
		}

		// Single product page — under the price.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single' ), 11 );

		if ( Settings::is_on( 'show_savings' ) ) {
			add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_savings' ), 11 );
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_savings' ), 11 );
		}
	}

	/**
	 * Whether the grid countdown sits over the image.
	 */
	public static function loop_is_overlay(): bool {
		return (string) Settings::get( 'countdown_in_loop', 'overlay' ) === 'overlay';
	}

	/**
	 * Loads the assets on shop and product pages.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		if ( ! is_woocommerce() && ! is_shop() && ! is_product_category() && ! is_product() ) {
			return;
		}

		wp_enqueue_style( 'wcd-countdown', WCD_URL . 'assets/countdown.css', array(), WCD_VERSION );
		wp_enqueue_script( 'wcd-countdown', WCD_URL . 'assets/countdown.js', array(), WCD_VERSION, true );

		wp_localize_script(
			'wcd-countdown',
			'wcdCountdown',
			array(
				// The browser's clock can be wrong. Sending the server's time
				// lets the script correct for the difference.
				'serverNow' => time(),
				'labels'    => array(
					'days'    => __( 'Days', 'woo-custom-discount' ),
					'hours'   => __( 'Hours', 'woo-custom-discount' ),
					'minutes' => __( 'Mins', 'woo-custom-discount' ),
					'seconds' => __( 'Secs', 'woo-custom-discount' ),
				),
			)
		);
	}

	/**
	 * Countdown in the product grid.
	 */
	public static function render_loop(): void {
		global $product;

		if ( $product instanceof \WC_Product ) {
			echo self::html( $product->get_id(), 'loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		}
	}

	/**
	 * Opens the wrapper that the overlay is positioned against.
	 *
	 * It has to contain the thumbnail and nothing else, which is why it is put
	 * here rather than reusing whatever the theme wraps the image in — those
	 * differ by theme, and Divi's also holds the hover overlay.
	 */
	public static function open_media(): void {
		echo '<span class="wcd-media">';
	}

	/**
	 * Closes it, with the countdown inside.
	 */
	public static function close_media(): void {
		global $product;

		if ( $product instanceof \WC_Product ) {
			echo self::html( $product->get_id(), 'loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		}

		echo '</span>';
	}

	/**
	 * Countdown, and optionally the expiry date, on the product page.
	 */
	public static function render_single(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		echo self::html( $product->get_id(), 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		echo self::expiry_note( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
	}

	/**
	 * Builds the countdown markup for one product, or an empty string.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $context    loop or single.
	 */
	public static function html( int $product_id, string $context = 'loop' ): string {
		$countdown = self::for_product( $product_id );

		if ( $countdown === null ) {
			return '';
		}

		$overlay = $context === 'loop' && self::loop_is_overlay();

		return $overlay
			? self::overlay_html( $countdown )
			: self::block_html( $countdown, $context );
	}

	/**
	 * The compact strip that sits across the bottom of a product image.
	 *
	 * Deliberately one line. A grid card has very little room, and the block
	 * form pushed the price and the button down the page — the countdown ended
	 * up shouting louder than the thing being sold.
	 *
	 * @param array{kind:string,ends:int} $countdown Resolved countdown.
	 */
	private static function overlay_html( array $countdown ): string {
		$label = $countdown['kind'] === 'expiry'
			? __( 'Expires in', 'woo-custom-discount' )
			: __( 'Ends in', 'woo-custom-discount' );

		ob_start();
		?>
		<span class="wcd-countdown wcd-countdown--<?php echo esc_attr( $countdown['kind'] ); ?> wcd-countdown--overlay"
			data-wcd-countdown
			data-ends="<?php echo esc_attr( (string) $countdown['ends'] ); ?>">
			<span class="wcd-countdown__label"><?php echo esc_html( $label ); ?></span>
			<span class="wcd-countdown__clock" data-wcd-clock>
				<?php foreach ( array( 'days', 'hours', 'minutes', 'seconds' ) as $unit ) : ?>
					<span class="wcd-countdown__unit">
						<span class="wcd-countdown__value" data-unit="<?php echo esc_attr( $unit ); ?>">--</span>
						<span class="wcd-countdown__name"><?php echo esc_html( self::unit_label( $unit ) ); ?></span>
					</span>
				<?php endforeach; ?>
			</span>
		</span>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The fuller block, used on the product page and when the grid countdown is
	 * set to sit below the price instead.
	 *
	 * @param array{kind:string,ends:int} $countdown Resolved countdown.
	 * @param string                      $context   loop or single.
	 */
	private static function block_html( array $countdown, string $context ): string {
		$is_expiry = $countdown['kind'] === 'expiry';

		$heading = $is_expiry
			? __( 'Short expiry', 'woo-custom-discount' )
			: __( 'Hurry up!', 'woo-custom-discount' );

		$subheading = $is_expiry
			? __( 'Expires in', 'woo-custom-discount' )
			: __( 'Sale ends in', 'woo-custom-discount' );

		ob_start();
		?>
		<div class="wcd-countdown wcd-countdown--<?php echo esc_attr( $countdown['kind'] ); ?> wcd-countdown--<?php echo esc_attr( $context ); ?>"
			data-wcd-countdown
			data-ends="<?php echo esc_attr( (string) $countdown['ends'] ); ?>">
			<p class="wcd-countdown__intro">
				<span class="wcd-countdown__heading"><?php echo esc_html( $heading ); ?></span>
				<span class="wcd-countdown__sub"><?php echo esc_html( $subheading ); ?></span>
			</p>
			<div class="wcd-countdown__clock" data-wcd-clock aria-live="off">
				<?php foreach ( array( 'days', 'hours', 'minutes', 'seconds' ) as $unit ) : ?>
					<span class="wcd-countdown__unit">
						<span class="wcd-countdown__value" data-unit="<?php echo esc_attr( $unit ); ?>">--</span>
						<span class="wcd-countdown__name"><?php echo esc_html( self::unit_label( $unit ) ); ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The product being displayed, whether or not the loop set the global.
	 *
	 * Divi's Woo modules do set it, but a Code module dropped anywhere on a
	 * Theme Builder template may run before they do.
	 */
	private static function current_product(): ?\WC_Product {
		global $product;

		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$id = get_queried_object_id();

		if ( ! $id ) {
			return null;
		}

		$found = wc_get_product( $id );

		return $found instanceof \WC_Product ? $found : null;
	}

	/**
	 * [wcd_countdown] — the countdown for this product.
	 */
	public static function shortcode_countdown(): string {
		$product = self::current_product();

		return $product ? self::html( $product->get_id(), 'single' ) : '';
	}

	/**
	 * [wcd_savings] — the amount saved, whatever the setting says elsewhere.
	 */
	public static function shortcode_savings(): string {
		$product = self::current_product();

		if ( ! $product ) {
			return '';
		}

		$regular = (float) $product->get_regular_price();
		$now     = (float) $product->get_price();

		if ( $regular <= 0 || $now <= 0 || $now >= $regular ) {
			return '';
		}

		return sprintf(
			'<p class="wcd-savings">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: amount saved. */
					__( 'You save %s', 'woo-custom-discount' ),
					self::money( $regular - $now )
				)
			)
		);
	}

	/**
	 * [wcd_expiry] — the expiry stock this product is held in.
	 *
	 * A product can sit in several batches, and only one of them is setting the
	 * price today. Listing them all and marking which is which is honest; a bare
	 * list would leave a shopper wondering why four discounts produce one price.
	 */
	public static function shortcode_expiry(): string {
		$product = self::current_product();

		if ( ! $product ) {
			return '';
		}

		$product_id = $product->get_id();
		$batches    = array();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_BATCH, 'enabled' => true ) ) as $batch ) {
			if ( in_array( $product_id, $batch['products'], true ) && ! Rules::is_batch_expired( $batch ) ) {
				$batches[] = $batch;
			}
		}

		if ( $batches === array() ) {
			return '';
		}

		usort(
			$batches,
			static fn( array $a, array $b ): int => strcmp( (string) $a['expiry_ym'], (string) $b['expiry_ym'] )
		);

		$active  = Resolver::active_batch_for( $product_id );
		$regular = (float) $product->get_regular_price();

		ob_start();
		?>
		<div class="wcd-expiry">
			<p class="wcd-expiry__title">
				<?php echo esc_html( _n( 'Expiry', 'Expiry dates held', count( $batches ), 'woo-custom-discount' ) ); ?>
			</p>
			<ul class="wcd-expiry__list">
				<?php foreach ( $batches as $batch ) : ?>
					<?php $is_active = $active !== null && (int) $active['id'] === (int) $batch['id']; ?>
					<li class="wcd-expiry__item<?php echo $is_active ? ' is-active' : ''; ?>">
						<span class="wcd-expiry__month">
							<?php echo esc_html( Importer::format_expiry( (string) $batch['expiry_ym'] ) ); ?>
						</span>
						<span class="wcd-expiry__off">
							<?php
							printf(
								/* translators: %s: percentage. */
								esc_html__( '%s%% off', 'woo-custom-discount' ),
								esc_html( Admin_Rules::percent_label( (float) $batch['discount_percent'] ) )
							);
							?>
						</span>
						<span class="wcd-expiry__price">
							<?php echo esc_html( self::money( Price_Engine::discounted_price( $regular, (float) $batch['discount_percent'] ) ) ); ?>
						</span>
						<?php if ( $is_active ) : ?>
							<span class="wcd-expiry__flag"><?php esc_html_e( 'current price', 'woo-custom-discount' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * A formatted amount, symbol and all.
	 */
	private static function money( float $amount ): string {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		return trim( $symbol . ' ' . number_format( $amount ) );
	}

	/**
	 * How much a shopper saves on this product, as a chip under the price.
	 */
	public static function render_savings(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$regular = (float) $product->get_regular_price();
		$now     = (float) $product->get_price();

		if ( $regular <= 0 || $now <= 0 || $now >= $regular ) {
			return;
		}

		printf(
			'<p class="wcd-savings">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: amount saved, already formatted. */
					__( 'You save %s', 'woo-custom-discount' ),
					self::money( $regular - $now )
				)
			)
		);
	}


	/**
	 * Which countdown, if any, a product should show.
	 *
	 * @return array{kind:string,ends:int}|null
	 */
	public static function for_product( int $product_id ): ?array {
		if ( get_post_meta( $product_id, self::META_HIDE, true ) === '1' ) {
			return null;
		}

		$outcome = Resolver::resolve( $product_id );

		if ( $outcome === null || empty( $outcome['ends_at'] ) ) {
			return null;
		}

		// The rule decides by default; a product can overrule it either way.
		$forced = get_post_meta( $product_id, self::META_FORCE, true ) === '1';

		if ( ! $outcome['countdown'] && ! $forced ) {
			return null;
		}

		if ( $outcome['ends_at'] <= time() ) {
			return null;
		}

		return array(
			'kind' => $outcome['type'] === Rules::TYPE_BATCH ? 'expiry' : 'sale',
			'ends' => (int) $outcome['ends_at'],
		);
	}

	/**
	 * The plain expiry line on the product page, when it is switched on.
	 */
	public static function expiry_note( int $product_id ): string {
		if ( get_post_meta( $product_id, self::META_SHOW_EXPIRY, true ) !== '1' ) {
			return '';
		}

		$label = Expiry::label_for( $product_id );

		if ( $label === '' ) {
			return '';
		}

		return sprintf(
			'<p class="wcd-expiry-note"><span class="wcd-expiry-note__label">%1$s</span> <span class="wcd-expiry-note__value">%2$s</span></p>',
			esc_html__( 'Best before', 'woo-custom-discount' ),
			esc_html( $label )
		);
	}

	/**
	 * Translated unit name.
	 */
	private static function unit_label( string $unit ): string {
		return match ( $unit ) {
			'days'    => __( 'Days', 'woo-custom-discount' ),
			'hours'   => __( 'Hours', 'woo-custom-discount' ),
			'minutes' => __( 'Mins', 'woo-custom-discount' ),
			default   => __( 'Secs', 'woo-custom-discount' ),
		};
	}
}
