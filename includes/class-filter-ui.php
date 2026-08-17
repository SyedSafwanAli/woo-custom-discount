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
 * Draws the filter and offers three ways to place it.
 *
 * A shortcode, a widget, and automatic placement on the shop page. Three routes
 * because the shop page is built in Divi: the shortcode drops into any Divi
 * module, the widget covers a Divi sidebar, and automatic placement works
 * without touching the layout at all.
 *
 * Two presentations:
 *
 *   drawer  A button. Pressing it slides a panel in from the right, where
 *           several choices are ticked and then applied in one go — one page
 *           load instead of one per tick.
 *   panel   Always open, for a sidebar column.
 *
 * Every option is a real link underneath. With JavaScript the drawer batches
 * them behind an Apply button; without it, each link still filters on its own,
 * so the filter never stops working — it just gets less convenient.
 */
class Filter_UI {

	/**
	 * Counter so several filters on one page get unique ids.
	 */
	private static int $instance = 0;

	/**
	 * Hooks the shortcode, widget and automatic placement.
	 */
	public static function init(): void {
		add_shortcode( 'wcd_filter', array( __CLASS__, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );

		if ( (string) Settings::get( 'filter_position', 'none' ) === 'above_grid' ) {
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
				'baseUrl'  => Filter_Query::base_url(),
				'clearUrl' => Filter_Query::clear_url(),
				'strings'  => array(
					'apply'      => __( 'Apply', 'woo-custom-discount' ),
					'applyCount' => __( 'Apply %d filters', 'woo-custom-discount' ),
					'applyOne'   => __( 'Apply 1 filter', 'woo-custom-discount' ),
					'showAll'    => __( 'Show all products', 'woo-custom-discount' ),
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

		return self::page_has_shortcode();
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
				'groups'  => '',
				'display' => '',
				'title'   => '',
			),
			(array) $atts,
			'wcd_filter'
		);

		$groups = $atts['groups'] !== ''
			? array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $atts['groups'] ) ) )
			: null;

		return self::render(
			array(
				'groups'  => $groups,
				'display' => sanitize_key( (string) $atts['display'] ),
				'title'   => sanitize_text_field( (string) $atts['title'] ),
			)
		);
	}

	/**
	 * Prints the filter above the product grid.
	 *
	 * Stands down when the page already carries the shortcode. A Divi-built shop
	 * page runs its product grid through the same WooCommerce hooks, so a page
	 * with `[wcd_filter]` in it would otherwise get a second copy of the panel.
	 */
	public static function output_on_shop(): void {
		if ( self::page_has_shortcode() ) {
			return;
		}

		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped parts.
	}

	/**
	 * Whether the page being viewed places the filter itself.
	 *
	 * Two places to look. Divi stores a page's layout as shortcodes in the post
	 * content, so a filter dropped into a module on the page is found there. But
	 * a Theme Builder template keeps its layout in a separate post, and that is
	 * where a shop page built through the Theme Builder puts it — so the active
	 * template's layouts are checked too.
	 */
	private static function page_has_shortcode(): bool {
		$post = get_post();

		if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'wcd_filter' ) ) {
			return true;
		}

		return self::theme_builder_has_shortcode();
	}

	/**
	 * Whether the Divi Theme Builder template for this request places the filter.
	 */
	private static function theme_builder_has_shortcode(): bool {
		if ( ! function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			return false;
		}

		$layouts = et_theme_builder_get_template_layouts();

		if ( ! is_array( $layouts ) ) {
			return false;
		}

		foreach ( $layouts as $layout ) {
			if ( ! is_array( $layout ) || empty( $layout['id'] ) ) {
				continue;
			}

			$content = (string) get_post_field( 'post_content', (int) $layout['id'] );

			if ( $content !== '' && has_shortcode( $content, 'wcd_filter' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds the whole thing.
	 *
	 * @param array<string,mixed> $args groups, display, title.
	 */
	public static function render( array $args = array() ): string {
		$enabled = $args['groups'] ?? (array) Settings::get( 'filter_groups', array() );
		$display = (string) ( $args['display'] ?? '' );
		$title   = (string) ( $args['title'] ?? '' );

		if ( ! in_array( $display, array( 'drawer', 'panel', 'auto' ), true ) ) {
			$display = (string) Settings::get( 'filter_display', 'drawer' );
		}

		$groups = array();

		foreach ( $enabled as $group ) {
			$html = self::group_html( (string) $group );

			if ( $html !== '' ) {
				$groups[] = $html;
			}
		}

		if ( $groups === array() ) {
			return '';
		}

		++self::$instance;

		$id       = 'wcd-panel-' . self::$instance;
		$title_id = 'wcd-title-' . self::$instance;
		$chips    = self::active_chips();
		$active   = count( $chips );

		$classes = array( 'wcd-filter', 'wcd-filter--' . sanitize_html_class( $display ) );

		if ( $active > 0 ) {
			$classes[] = 'is-filtering';
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-wcd-filter data-mode="<?php echo esc_attr( $display ); ?>">

			<?php if ( $display !== 'panel' ) : ?>
				<div class="wcd-bar">
					<button type="button" class="wcd-trigger" data-wcd-open
						aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon_sliders(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
						<span class="wcd-trigger__text">
							<?php echo esc_html( $title !== '' ? $title : __( 'Filter', 'woo-custom-discount' ) ); ?>
						</span>
						<?php if ( $active > 0 ) : ?>
							<span class="wcd-trigger__count"><?php echo esc_html( (string) $active ); ?></span>
						<?php endif; ?>
					</button>

					<?php echo self::chips_html( $chips ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
				</div>
			<?php endif; ?>

			<div class="wcd-scrim" data-wcd-scrim></div>

			<div class="wcd-panel" id="<?php echo esc_attr( $id ); ?>" data-wcd-panel
				role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">

				<div class="wcd-panel__head">
					<p class="wcd-panel__title" id="<?php echo esc_attr( $title_id ); ?>">
						<?php echo esc_html( $title !== '' ? $title : __( 'Filter products', 'woo-custom-discount' ) ); ?>
					</p>
					<button type="button" class="wcd-close" data-wcd-close
						aria-label="<?php esc_attr_e( 'Close filters', 'woo-custom-discount' ); ?>">
						<?php echo self::icon_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
					</button>
				</div>

				<?php if ( $display === 'panel' && $chips !== array() ) : ?>
					<div class="wcd-panel__chips"><?php echo self::chips_html( $chips ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></div>
				<?php endif; ?>

				<div class="wcd-panel__body">
					<?php echo implode( '', $groups ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each group escapes its own parts. ?>
				</div>

				<div class="wcd-panel__foot">
					<a class="wcd-btn wcd-btn--ghost" href="<?php echo esc_url( Filter_Query::clear_url() ); ?>">
						<?php esc_html_e( 'Clear all', 'woo-custom-discount' ); ?>
					</a>
					<button type="button" class="wcd-btn wcd-btn--primary" data-wcd-apply>
						<?php esc_html_e( 'Apply', 'woo-custom-discount' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * One group's markup, chosen by name.
	 */
	private static function group_html( string $group ): string {
		return match ( $group ) {
			'discount' => self::discount_group(),
			'expiry'   => self::expiry_group(),
			'category' => self::category_group(),
			'price'    => self::price_group(),
			'stock'    => self::stock_group(),
			'sort'     => self::sort_group(),
			default    => '',
		};
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

		foreach ( array_keys( $available ) as $ym ) {
			// The count is left for the shared path to work out, so every group
			// counts the same way the shop does.
			$options[] = array(
				'value' => (string) $ym,
				'label' => Importer::format_expiry( (string) $ym ),
				'group' => Filter_Query::PARAM_EXPIRY,
				'on'    => in_array( (string) $ym, $selected, true ),
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
			// WordPress's own term count includes products hidden from the
			// catalogue, so it is left out and the shared path counts instead.
			$options[] = array(
				'value' => (string) $term->term_id,
				'label' => $term->name,
				'group' => Filter_Query::PARAM_CATEGORY,
				'on'    => in_array( (int) $term->term_id, $selected, true ),
			);
		}

		return self::group( __( 'Category', 'woo-custom-discount' ), $options, true );
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
		$options = array(
			array(
				'value' => '1',
				'label' => __( 'In stock only', 'woo-custom-discount' ),
				'group' => Filter_Query::PARAM_STOCK,
				'on'    => Filter_Query::selection()['instock'],
				'multi' => false,
			),
		);

		return self::group( __( 'Availability', 'woo-custom-discount' ), $options );
	}

	/**
	 * Sort order. One choice only, so it reads as a radio set.
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
	 * Options are links, not form inputs. That keeps the filter working with
	 * JavaScript switched off, and makes every combination a real URL that can
	 * be shared or indexed.
	 *
	 * @param string                         $title    Group heading.
	 * @param array<int,array<string,mixed>> $options  Options to render.
	 * @param bool                           $scroll   Whether a long list scrolls.
	 */
	private static function group( string $title, array $options, bool $scroll = false ): string {
		$show_counts = Settings::is_on( 'show_counts' );
		$hide_empty  = Settings::is_on( 'hide_empty' );

		$rows = array();

		foreach ( $options as $option ) {
			$multi   = $option['multi'] ?? true;
			$is_sort = $option['group'] === Filter_Query::PARAM_SORT;
			$count   = null;

			if ( $show_counts && ! $is_sort ) {
				$count = Filter_Query::count_for( (string) $option['group'], (string) $option['value'] );
			}

			// A band nobody can reach is worse than no band at all — that is how
			// the shop ended up with an empty "30% off" category.
			if ( $hide_empty && $count === 0 && ! $option['on'] ) {
				continue;
			}

			$rows[] = sprintf(
				'<li class="wcd-item%1$s">
					<a class="wcd-opt" href="%2$s" data-group="%3$s" data-value="%4$s" data-multi="%5$d" aria-pressed="%6$s">
						<span class="wcd-box" aria-hidden="true"></span>
						<span class="wcd-opt__label">%7$s</span>
						%8$s
					</a>
				</li>',
				$option['on'] ? ' is-on' : '',
				esc_url( Filter_Query::toggle_url( (string) $option['group'], (string) $option['value'], (bool) $multi ) ),
				esc_attr( (string) $option['group'] ),
				esc_attr( (string) $option['value'] ),
				$multi ? 1 : 0,
				$option['on'] ? 'true' : 'false',
				esc_html( (string) $option['label'] ),
				$count !== null ? '<span class="wcd-opt__count">' . esc_html( (string) $count ) . '</span>' : ''
			);
		}

		if ( $rows === array() ) {
			return '';
		}

		return sprintf(
			'<div class="wcd-group"><p class="wcd-group__title">%1$s</p><ul class="wcd-list%2$s">%3$s</ul></div>',
			esc_html( $title ),
			$scroll ? ' wcd-list--scroll' : '',
			implode( '', $rows )
		);
	}

	/**
	 * The filters currently applied, as removable chips.
	 *
	 * Showing these outside the drawer means the state is visible without having
	 * to open it, and each one can be dropped in a single click.
	 *
	 * @return array<int,array{label:string,url:string}>
	 */
	private static function active_chips(): array {
		$selection = Filter_Query::selection();
		$chips     = array();

		foreach ( $selection['discount'] as $key ) {
			$bucket = Buckets::discount_bucket( $key );

			if ( $bucket !== null ) {
				$chips[] = array(
					'label' => $bucket['label'],
					'url'   => Filter_Query::toggle_url( Filter_Query::PARAM_DISCOUNT, $key ),
				);
			}
		}

		foreach ( $selection['expiry'] as $ym ) {
			$chips[] = array(
				'label' => Importer::format_expiry( $ym ),
				'url'   => Filter_Query::toggle_url( Filter_Query::PARAM_EXPIRY, $ym ),
			);
		}

		foreach ( $selection['category'] as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );

			if ( $term instanceof \WP_Term ) {
				$chips[] = array(
					'label' => $term->name,
					'url'   => Filter_Query::toggle_url( Filter_Query::PARAM_CATEGORY, (string) $term_id ),
				);
			}
		}

		foreach ( $selection['price'] as $key ) {
			$bucket = Buckets::price_bucket( $key );

			if ( $bucket !== null ) {
				$chips[] = array(
					'label' => $bucket['label'],
					'url'   => Filter_Query::toggle_url( Filter_Query::PARAM_PRICE, $key ),
				);
			}
		}

		if ( $selection['instock'] ) {
			$chips[] = array(
				'label' => __( 'In stock only', 'woo-custom-discount' ),
				'url'   => Filter_Query::toggle_url( Filter_Query::PARAM_STOCK, '1', false ),
			);
		}

		if ( $selection['sort'] !== '' ) {
			$labels = Filter_Query::sort_options();

			if ( isset( $labels[ $selection['sort'] ] ) ) {
				$chips[] = array(
					'label' => $labels[ $selection['sort'] ],
					'url'   => Filter_Query::toggle_url( Filter_Query::PARAM_SORT, $selection['sort'], false ),
				);
			}
		}

		return $chips;
	}

	/**
	 * Chip list markup.
	 *
	 * @param array<int,array{label:string,url:string}> $chips Active filters.
	 */
	private static function chips_html( array $chips ): string {
		if ( $chips === array() ) {
			return '';
		}

		$items = array();

		foreach ( $chips as $chip ) {
			$items[] = sprintf(
				'<li><a class="wcd-chip" href="%1$s"><span class="wcd-chip__label">%2$s</span>%3$s</a></li>',
				esc_url( $chip['url'] ),
				esc_html( $chip['label'] ),
				self::icon_close( 'wcd-chip__x' )
			);
		}

		$items[] = sprintf(
			'<li><a class="wcd-chip wcd-chip--clear" href="%1$s">%2$s</a></li>',
			esc_url( Filter_Query::clear_url() ),
			esc_html__( 'Clear all', 'woo-custom-discount' )
		);

		return '<ul class="wcd-chips">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Sliders icon for the trigger.
	 */
	private static function icon_sliders(): string {
		return '<svg class="wcd-trigger__icon" viewBox="0 0 20 20" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false">'
			. '<path d="M3 6h9M15 6h2M3 14h2M8 14h9"/>'
			. '<circle cx="13.5" cy="6" r="1.9"/><circle cx="6.5" cy="14" r="1.9"/>'
			. '</svg>';
	}

	/**
	 * Cross icon.
	 */
	private static function icon_close( string $class = 'wcd-close__icon' ): string {
		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true" focusable="false">'
			. '<path d="M5.5 5.5l9 9M14.5 5.5l-9 9"/>'
			. '</svg>';
	}
}
