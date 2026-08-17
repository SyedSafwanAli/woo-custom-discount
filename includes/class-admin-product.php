<?php
/**
 * The panel on each product's edit screen.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what this plugin is doing to one product, and lets it be overridden.
 *
 * Whoever edits a product should be able to see why its price is what it is,
 * without hunting through rule lists. So the panel names the rule, shows the
 * arithmetic, and offers the two per-product switches.
 */
class Admin_Product {

	/**
	 * Hooks the panel.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Registers the side panel.
	 */
	public static function add_box(): void {
		add_meta_box(
			'wcd-product',
			__( 'Discount & expiry', 'woo-custom-discount' ),
			array( __CLASS__, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Renders the panel.
	 *
	 * @param \WP_Post $post Current product.
	 */
	public static function render( $post ): void {
		$product_id = (int) $post->ID;
		$plan       = Price_Engine::plan_product( $product_id );

		wp_nonce_field( 'wcd_product', 'wcd_product_nonce' );

		echo '<div class="wcd-product-box">';

		if ( $plan['status'] === 'discount' ) {
			printf(
				'<p><strong>%1$s</strong><br><span class="description">%2$s</span></p>',
				esc_html(
					sprintf(
						/* translators: %s: percentage. */
						__( '%s%% off', 'woo-custom-discount' ),
						Admin_Rules::percent_label( $plan['percent'] )
					)
				),
				esc_html( $plan['rule_title'] )
			);

			printf(
				'<p class="wcd-product-sum">%1$s &rarr; <strong>%2$s</strong></p>',
				esc_html( number_format( $plan['regular'] ) ),
				esc_html( number_format( $plan['new_price'] ) )
			);
		} elseif ( $plan['status'] === 'skipped_type' ) {
			echo '<p class="description">' . esc_html__( 'This is a variable product, so prices are set on each variation. The plugin leaves it alone.', 'woo-custom-discount' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No discount applies to this product.', 'woo-custom-discount' ) . '</p>';
		}

		if ( $plan['expiry_ym'] !== null ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Expiry:', 'woo-custom-discount' ),
				esc_html( Importer::format_expiry( $plan['expiry_ym'] ) )
			);
		}

		if ( ! Plugin::engine_can_run() ) {
			echo '<p class="description"><em>' . esc_html__( 'The price engine is off, so this is what would happen — not what is live.', 'woo-custom-discount' ) . '</em></p>';
		}

		echo '<hr>';

		self::checkbox(
			Countdown::META_FORCE,
			$product_id,
			__( 'Always show the countdown here', 'woo-custom-discount' )
		);

		self::checkbox(
			Countdown::META_HIDE,
			$product_id,
			__( 'Never show a countdown here', 'woo-custom-discount' )
		);

		self::checkbox(
			Countdown::META_SHOW_EXPIRY,
			$product_id,
			__( 'Show the expiry date on the product page', 'woo-custom-discount' )
		);

		echo '</div>';
	}

	/**
	 * One override checkbox.
	 */
	private static function checkbox( string $meta_key, int $product_id, string $label ): void {
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label></p>',
			esc_attr( $meta_key ),
			checked( get_post_meta( $product_id, $meta_key, true ), '1', false ),
			esc_html( $label )
		);
	}

	/**
	 * Saves the overrides.
	 *
	 * @param int      $post_id Product ID.
	 * @param \WP_Post $post    Product.
	 */
	public static function save( $post_id, $post ): void {
		if ( ! isset( $_POST['wcd_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['wcd_product_nonce'] ) ), 'wcd_product' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		foreach ( array( Countdown::META_FORCE, Countdown::META_HIDE, Countdown::META_SHOW_EXPIRY ) as $key ) {
			if ( empty( $_POST[ $key ] ) ) {
				delete_post_meta( $post_id, $key );

				continue;
			}

			update_post_meta( $post_id, $key, '1' );
		}
	}
}
