<?php
/**
 * Renders the filter, and gets it onto the page.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the filter panel and offers three ways to place it.
 *
 * A shortcode, a widget, and automatic placement on the shop page. Three routes
 * because the shop page is being rebuilt in Divi: the shortcode drops into any
 * Divi module, the widget covers a Divi sidebar, and automatic placement works
 * without touching the layout at all.
 *
 * The markup deliberately carries no colours or fonts of its own. It inherits
 * the theme, so it looks like the rest of the shop rather than like a plugin
 * bolted on.
 */
class Filter_UI {

	/**
	 * Hooks the shortcode, widget and automatic placement.
	 */
	public static function init(): void {
		add_shortcode( 'wcd_filter', array( __CLASS__, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );

		$position = (string) Settings::get( 'filter_position', 'none' );

		if ( $position === 'above_grid' ) {
			// Priority 5 puts the panel ahead of the result count (20) and the
			// sort dropdown (30). Both of those are floated by the theme, and
			// sitting between them left the panel wrapped around them.
			add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'output_on_shop' ), 5 );
		}
	}

	/**
	 * Loads the stylesheet and script only where the filter can appear.
	 */
	public static function enqueue(): void {
		if ( ! self::is_relevant_page() ) {
			return;
		}

		wp_enqueue_style( 'wcd-filter', WCD_URL . 'assets/filter.css', array(), WCD_VERSION );

		wp_enqueue_script( 'wcd-filter', WCD_URL . 'assets/filter.js', array(), WCD_VERSION, true );

		wp_localize_script(
			'wcd-filter',
			'wcdFilter',
			array(
				'clearUrl' => Filter_Query::clear_url(),
				'strings'  => array(
					'open'  => __( 'Filter', 'woo-custom-discount' ),
					'close' => __( 'Close', 'woo-custom-discount' ),
				),
			)
		);
	}

	/**
	 * Pages where the filter makes sense.
	 */
	private static function is_relevant_page(): bool {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}

		if ( is_shop() || is_product_category() || is_product_tag() ) {
			return true;
		}

		// A page carrying the shortcode, e.g. the Divi-built shop page.
		$post = get_post();

		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'wcd_filter' );
	}

	/**
	 * Registers the sidebar widget.
	 */
	public static function register_widget(): void {
		register_widget( Filter_Widget::class );
	}

	/**
	 * [wcd_filter] handler.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'groups' => '',
				'layout' => 'stacked',
				'title'  => '',
			),
			(array) $atts,
			'wcd_filter'
		);

		$groups = $atts['groups'] !== ''
			? array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $atts['groups'] ) ) )
			: null;

		return self::render(
			array(
				'groups' => $groups,
				'layout' => sanitize_key( (string) $atts['layout'] ),
				'title'  => sanitize_text_field( (string) $atts['title'] ),
			)
		);
	}

	/**
	 * Prints the filter above the product grid.
	 */
	public static function output_on_shop(): void {
		echo self::render( array( 'layout' => 'inline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
	}

	/**
	 * Builds the whole panel.
	 *
	 * @param array<string,mixed> $args groups, layout, title.
	 */
	public static function render( array $args = array() ): string {
		$enabled = $args['groups'] ?? (array) Settings::get( 'filter_groups', array() );
		$layout  = (string) ( $args['layout'] ?? 'stacked' );
		$title   = (string) ( $args['title'] ?? '' );

		$sections = array();

		foreach ( $enabled as $group ) {
			$html = match ( $group ) {
				'discount' => self::discount_group(),
				'expiry'   => self::expiry_group(),
				'category' => self::category_group(),
				'price'    => self::price_group(),
				'stock'    => self::stock_group(),
				'sort'     => self::sort_group(),
				default    => '',
			};

			if ( $html !== '' ) {
				$sections[] = $html;
			}
		}

		if ( $sections === array() ) {
			return '';
		}

		$classes = array( 'wcd-filter', 'wcd-filter--' . sanitize_html_class( $layout ) );

		if ( Filter_Query::is_filtering() ) {
			$classes[] = 'is-filtering';
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-wcd-filter>
			<button type="button" class="wcd-filter__toggle" data-wcd-toggle aria-expanded="false">
				<?php esc_html_e( 'Filter', 'woo-custom-discount' ); ?>
				<?php if ( Filter_Query::is_filtering() ) : ?>
					<span class="wcd-filter__dot" aria-hidden="true"></span>
				<?php endif; ?>
			</button>

			<div class="wcd-filter__panel" data-wcd-panel>
				<div class="wcd-filter__head">
					<?php if ( $title !== '' ) : ?>
						<p class="wcd-filter__title"><?php echo esc_html( $title ); ?></p>
					<?php endif; ?>

					<?php if ( Filter_Query::is_filtering() ) : ?>
						<a class="wcd-filter__clear" href="<?php echo esc_url( Filter_Query::clear_url() ); ?>">
							<?php esc_html_e( 'Clear all', 'woo-custom-discount' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php echo implode( '', $sections ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each section escapes its own parts. ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Discount bands.
	 */
	private static function discount_group(): string {
		$buckets = Buckets::discount_buckets();

		if ( $buckets === array() ) {
			return '';
		}

		$selected = Filter_Query::selection()['discount'];
		$options  = array();

		foreach ( $buckets as $bucket ) {
			$options[] = array(
				'value' => $bucket['key'],
				'label' => $bucket['label'],
				'group' => Filter_Query::PARAM_DISCOUNT,
				'on'    => in_array( $bucket['key'], $selected, true ),
			);
		}

		return self::group( __( 'Discount', 'woo-custom-discount' ), $options );
	}

	/**
	 * Expiry months, restricted to the ones the owner chose.
	 */
	private static function expiry_group(): string {
		$available = Expiry::available_months();
		$chosen    = array_map( 'strval', (array) Settings::get( 'expiry_months', array() ) );

		if ( $chosen !== array() ) {
			$available = array_intersect_key( $available, array_flip( $chosen ) );
		}

		if ( $available === array() ) {
			return '';
		}

		$selected = Filter_Query::selection()['expiry'];
		$options  = array();

		foreach ( $available as $ym => $count ) {
			$options[] = array(
				'value' => (string) $ym,
				'label' => Importer::format_expiry( (string) $ym ),
				'group' => Filter_Query::PARAM_EXPIRY,
				'on'    => in_array( (string) $ym, $selected, true ),
				'count' => $count,
			);
		}

		return self::group( __( 'Expiry', 'woo-custom-discount' ), $options );
	}

	/**
	 * Product categories.
	 */
	private static function category_group(): string {
		$terms = Buckets::filter_categories();

		if ( $terms === array() ) {
			return '';
		}

		$selected = Filter_Query::selection()['category'];
		$options  = array();

		foreach ( $terms as $term ) {
			$options[] = array(
				'value' => (string) $term->term_id,
				'label' => $term->name,
				'group' => Filter_Query::PARAM_CATEGORY,
				'on'    => in_array( (int) $term->term_id, $selected, true ),
				'count' => (int) $term->count,
			);
		}

		return self::group( __( 'Category', 'woo-custom-discount' ), $options );
	}

	/**
	 * Price bands.
	 */
	private static function price_group(): string {
		$buckets = Buckets::price_buckets();

		if ( $buckets === array() ) {
			return '';
		}

		$selected = Filter_Query::selection()['price'];
		$options  = array();

		foreach ( $buckets as $bucket ) {
			$options[] = array(
				'value' => $bucket['key'],
				'label' => $bucket['label'],
				'group' => Filter_Query::PARAM_PRICE,
				'on'    => in_array( $bucket['key'], $selected, true ),
			);
		}

		return self::group( __( 'Price', 'woo-custom-discount' ), $options );
	}

	/**
	 * The single in-stock switch.
	 */
	private static function stock_group(): string {
		$on = Filter_Query::selection()['instock'];

		$options = array(
			array(
				'value' => '1',
				'label' => __( 'In stock only', 'woo-custom-discount' ),
				'group' => Filter_Query::PARAM_STOCK,
				'on'    => $on,
				'multi' => false,
			),
		);

		return self::group( __( 'Availability', 'woo-custom-discount' ), $options );
	}

	/**
	 * Sort order. Single choice, so it renders as radio-style links.
	 */
	private static function sort_group(): string {
		$current = Filter_Query::selection()['sort'];
		$options = array();

		foreach ( Filter_Query::sort_options() as $key => $label ) {
			$options[] = array(
				'value' => $key,
				'label' => $label,
				'group' => Filter_Query::PARAM_SORT,
				'on'    => $current === $key,
				'multi' => false,
			);
		}

		return self::group( __( 'Sort by', 'woo-custom-discount' ), $options );
	}

	/**
	 * One labelled group of options.
	 *
	 * Options are links, not form inputs. That keeps the whole thing working
	 * with JavaScript switched off, and makes every combination a real URL that
	 * can be shared or indexed.
	 *
	 * @param string                          $title   Group heading.
	 * @param array<int,array<string,mixed>>  $options Options to render.
	 */
	private static function group( string $title, array $options ): string {
		$show_counts = Settings::is_on( 'show_counts' );
		$hide_empty  = Settings::is_on( 'hide_empty' );

		$rows = array();

		foreach ( $options as $option ) {
			$multi = $option['multi'] ?? true;
			$count = $option['count'] ?? null;

			if ( $show_counts && $count === null && $option['group'] !== Filter_Query::PARAM_SORT ) {
				$count = Filter_Query::count_for( (string) $option['group'], (string) $option['value'] );
			}

			// A band nobody can reach is worse than no band at all — that is how
			// the shop ended up with an empty "30% off" category.
			if ( $hide_empty && $count === 0 && ! $option['on'] ) {
				continue;
			}

			$url = Filter_Query::toggle_url( (string) $option['group'], (string) $option['value'], (bool) $multi );

			$rows[] = sprintf(
				'<li class="wcd-filter__item%1$s"><a href="%2$s" class="wcd-filter__link" %3$s><span class="wcd-filter__box" aria-hidden="true"></span><span class="wcd-filter__label">%4$s</span>%5$s</a></li>',
				$option['on'] ? ' is-on' : '',
				esc_url( $url ),
				$option['on'] ? 'aria-current="true"' : '',
				esc_html( (string) $option['label'] ),
				$show_counts && $count !== null && $option['group'] !== Filter_Query::PARAM_SORT
					? '<span class="wcd-filter__count">' . esc_html( (string) $count ) . '</span>'
					: ''
			);
		}

		if ( $rows === array() ) {
			return '';
		}

		return sprintf(
			'<div class="wcd-filter__group"><p class="wcd-filter__heading">%1$s</p><ul class="wcd-filter__list">%2$s</ul></div>',
			esc_html( $title ),
			implode( '', $rows )
		);
	}
}
