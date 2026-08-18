<?php
/**
 * Adding to the cart from a product page, without leaving it.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * The one gap in an otherwise AJAX cart.
 *
 * WooCommerce's setting for AJAX add to cart covers the archive buttons only.
 * A product page posts its form and reloads, and a reload is what a cart drawer
 * cannot survive — the page it was opening on is already gone.
 *
 * So this sends the product form through WooCommerce's own add_to_cart endpoint
 * and then fires the added_to_cart event core fires everywhere else. Whatever
 * listens for that — this theme's drawer, another theme's, or nothing at all —
 * behaves on a product page exactly as it does on the shop grid. No drawer is
 * supplied here, because supplying a second one is how a site ends up with two.
 */
class Ajax_Cart {

	/**
	 * Hooks the script up.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Only where there is a product form to intercept.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_script(
			'wcd-ajax-cart',
			WCD_URL . 'assets/ajax-cart.js',
			array( 'jquery' ),
			WCD_VERSION,
			true
		);

		wp_localize_script(
			'wcd-ajax-cart',
			'wcdAjaxCart',
			array(
				// %%endpoint%% is core's own placeholder; the script swaps in the
				// action it wants. Going through WC_AJAX rather than admin-ajax
				// keeps these requests off the admin bootstrap.
				'ajaxUrl' => class_exists( '\WC_AJAX' ) ? \WC_AJAX::get_endpoint( '%%endpoint%%' ) : '',
			)
		);
	}
}
