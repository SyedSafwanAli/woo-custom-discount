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

		// Product grid — under the price, above the add-to-cart button.
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_loop' ), 15 );

		// Single product page — under the price.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single' ), 11 );
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
