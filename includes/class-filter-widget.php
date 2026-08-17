<?php
/**
 * Sidebar widget wrapper for the filter.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the filter in any sidebar, including Divi's.
 */
class Filter_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'wcd_filter_widget',
			__( 'Product Filter (Woo Custom Discount)', 'woo-custom-discount' ),
			array(
				'description' => __( 'Filter products by discount, expiry, category, price and stock.', 'woo-custom-discount' ),
				'classname'   => 'wcd-filter-widget',
			)
		);
	}

	/**
	 * Renders the widget.
	 *
	 * @param array<string,mixed> $args     Sidebar arguments.
	 * @param array<string,mixed> $instance Saved settings.
	 */
	public function widget( $args, $instance ): void {
		$html = Filter_UI::render( array( 'layout' => 'stacked' ) );

		if ( $html === '' ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.

		$title = apply_filters( 'widget_title', (string) ( $instance['title'] ?? '' ), $instance, $this->id_base );

		if ( $title !== '' ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
	}

	/**
	 * Settings form.
	 *
	 * @param array<string,mixed> $instance Saved settings.
	 */
	public function form( $instance ): string {
		$title = (string) ( $instance['title'] ?? __( 'Filter', 'woo-custom-discount' ) );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'woo-custom-discount' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p class="description">
			<?php esc_html_e( 'Which groups appear is set under WooCommerce → Custom Discount → Filters.', 'woo-custom-discount' ); ?>
		</p>
		<?php

		return '';
	}

	/**
	 * Saves settings.
	 *
	 * @param array<string,mixed> $new_instance Submitted values.
	 * @param array<string,mixed> $old_instance Previous values.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		return array(
			'title' => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
		);
	}
}
