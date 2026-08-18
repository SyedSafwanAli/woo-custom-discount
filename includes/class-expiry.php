<?php
/**
 * Expiry handling: hiding stock whose month has passed.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps expired stock out of the shop.
 *
 * The safety rule here matters more than the feature: a product with no expiry
 * date is never hidden, ever. Only a product that sits in an expiry batch whose
 * month has passed can disappear, and even then the product is not deleted or
 * unpublished — it is simply left out of shop queries.
 *
 * That distinction is what makes this reversible. Switch the setting off and
 * everything is back, with no data to repair.
 */
class Expiry {

	/**
	 * Hooks the query filters, only when hiding is switched on.
	 */
	public static function init(): void {
		if ( ! Settings::is_on( 'hide_expired' ) ) {
			return;
		}

		add_action( 'woocommerce_product_query', array( __CLASS__, 'exclude_from_query' ) );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'exclude_from_shortcode' ) );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'filter_visibility' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_expired_product' ) );
	}

	/**
	 * Product IDs whose batch month has passed.
	 *
	 * Cached in a transient because this runs on every shop page, and the answer
	 * only changes when a month rolls over or a rule is edited.
	 *
	 * @return int[]
	 */
	public static function expired_product_ids(): array {
		$cached = get_transient( 'wcd_expired_ids' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = array();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_BATCH, 'enabled' => true ) ) as $rule ) {
			if ( Rules::is_batch_expired( $rule ) ) {
				$ids = array_merge( $ids, $rule['products'] );
			}
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		set_transient( 'wcd_expired_ids', $ids, HOUR_IN_SECONDS );

		return $ids;
	}

	/**
	 * Forgets the cached list. Called whenever rules change.
	 */
	public static function flush_cache(): void {
		delete_transient( 'wcd_expired_ids' );
	}

	/**
	 * Removes expired products from the main shop and category queries.
	 *
	 * @param \WP_Query $query The product query.
	 */
	public static function exclude_from_query( $query ): void {
		if ( is_admin() ) {
			return;
		}

		$expired = self::expired_product_ids();

		if ( $expired === array() ) {
			return;
		}

		$existing = (array) $query->get( 'post__not_in' );

		$query->set( 'post__not_in', array_merge( $existing, $expired ) );
	}

	/**
	 * Same, for the shortcode-driven queries Divi's Woo Products module uses.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public static function exclude_from_shortcode( array $args ): array {
		if ( is_admin() ) {
			return $args;
		}

		$expired = self::expired_product_ids();

		if ( $expired === array() ) {
			return $args;
		}

		$existing            = (array) ( $args['post__not_in'] ?? array() );
		$args['post__not_in'] = array_merge( $existing, $expired );

		return $args;
	}

	/**
	 * Hides expired products from anything that asks WooCommerce directly,
	 * such as related products and cross-sells.
	 *
	 * @param bool $visible    Current visibility.
	 * @param int  $product_id Product ID.
	 */
	public static function filter_visibility( $visible, $product_id ): bool {
		if ( is_admin() || ! $visible ) {
			return (bool) $visible;
		}

		return ! in_array( (int) $product_id, self::expired_product_ids(), true );
	}

	/**
	 * Sends someone who lands on an expired product's page somewhere useful.
	 *
	 * A 404 would throw away whatever ranking or inbound link that URL has, so
	 * the visitor goes to the product's own category instead, where they can
	 * find something comparable.
	 */
	public static function redirect_expired_product(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$product_id = get_queried_object_id();

		if ( ! in_array( (int) $product_id, self::expired_product_ids(), true ) ) {
			return;
		}

		// Someone who can edit products should still be able to see the page.
		if ( current_user_can( 'edit_products' ) ) {
			return;
		}

		$target = self::fallback_url( (int) $product_id );

		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * The best place to send a visitor from an expired product.
	 */
	private static function fallback_url( int $product_id ): string {
		$terms = wp_get_post_terms( $product_id, 'product_cat' );

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				// Skip the housekeeping categories; they are no use to a shopper.
				if ( preg_match( '/^(expiry|special discounts|uncategorized|\d+% off)/i', $term->name ) ) {
					continue;
				}

				$link = get_term_link( $term );

				if ( ! is_wp_error( $link ) ) {
					return (string) $link;
				}
			}
		}

		$shop = wc_get_page_permalink( 'shop' );

		return $shop ? $shop : home_url( '/' );
	}

	/**
	 * A human-readable expiry for one product, or an empty string.
	 */
	public static function label_for( int $product_id ): string {
		$ym = (string) get_post_meta( $product_id, Price_Engine::META_EXPIRY, true );

		return $ym === '' ? '' : Importer::format_expiry( $ym );
	}

	/**
	 * The expiry months that actually have products behind them, newest last.
	 *
	 * @return array<string,int> YYYYMM => product count.
	 */
	/**
	 * Every month there is a batch for, whether or not it holds anything yet.
	 *
	 * available_months() answers what the shop can offer today, and a month with
	 * nothing in it is not something to offer a customer. But the owner building
	 * the filter is working ahead: a batch created for a month that has not been
	 * stocked yet is still a month they mean to use, and leaving it out of the
	 * list makes it look as though the batch was never made.
	 *
	 * @return array<string,int> Month as YYYYMM => product count, oldest first.
	 */
	public static function all_months(): array {
		$months = self::available_months();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_BATCH, 'enabled' => true ) ) as $batch ) {
			$ym = (string) $batch['expiry_ym'];

			if ( $ym !== '' && ! isset( $months[ $ym ] ) ) {
				$months[ $ym ] = 0;
			}
		}

		ksort( $months );

		return $months;
	}

	public static function available_months(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS ym, COUNT(*) AS total
				   FROM {$wpdb->postmeta} pm
				   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s
				    AND p.post_type = 'product'
				    AND p.post_status = 'publish'
				  GROUP BY pm.meta_value
				  ORDER BY pm.meta_value ASC",
				Price_Engine::META_EXPIRY
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['ym'] ] = (int) $row['total'];
		}

		return $out;
	}
}
