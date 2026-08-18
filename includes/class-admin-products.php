<?php
/**
 * The product-by-batch assignment grid.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns products to expiry batches from one screen.
 *
 * The batch editor asks the question the wrong way round for day-to-day work.
 * It asks "which products are in the August batch"; the shop owner is thinking
 * "this Co Q-10 — some of it expires in August and some in December". Putting
 * one product into three batches meant opening three separate editors and
 * finding the same product three times.
 *
 * So this screen turns the table on its side: products down, batches across,
 * a checkbox where they meet. Two batches on one product is two ticks, and the
 * whole picture is visible at once.
 */
class Admin_Products {

	/** Rows per page. */
	private const PER_PAGE = 25;

	/**
	 * How many batch columns fit before the table outgrows the screen.
	 *
	 * Measured rather than guessed: the name, two prices and the campaign take
	 * roughly 500px, and each batch column 78px. Eight columns lands near
	 * 1,120px, which sits inside a normal admin content area.
	 */
	private const COMFORTABLE_COLUMNS = 8;

	/**
	 * Hooks the save handler.
	 */
	public static function init(): void {
		add_action( 'admin_post_wcd_save_products', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Renders the tab.
	 */
	public static function render(): void {
		$batches = self::visible_batches();

		echo '<p class="wcd-intro">';
		esc_html_e( 'Tick where a product meets a batch. A product can sit in several — some stock expiring sooner, some later — and each batch carries its own discount.', 'woo-custom-discount' );
		echo '</p>';

		if ( $batches === array() ) {
			echo '<div class="wcd-empty"><p>';
			esc_html_e( 'There are no batches yet, so there is nothing to assign. Create one on the Expiry Batches tab first.', 'woo-custom-discount' );
			echo '</p></div>';

			return;
		}

		self::render_filters();

		$query = self::query_products();

		if ( ! $query->have_posts() ) {
			echo '<div class="wcd-empty"><p>' . esc_html__( 'No products match that. Try clearing the search or the category.', 'woo-custom-discount' ) . '</p></div>';

			return;
		}

		$product_ids = array_map( 'intval', $query->posts );
		$batch_map   = Rules::batch_map_for_products( $product_ids );

		self::render_pagination( $query, 'top' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-grid-form">';
		wp_nonce_field( 'wcd_save_products' );
		echo '<input type="hidden" name="action" value="wcd_save_products">';

		// Carries the current view back, so saving returns to the same page and
		// the same search rather than dumping the reader at the start.
		foreach ( self::current_args() as $key => $value ) {
			printf( '<input type="hidden" name="view[%s]" value="%s">', esc_attr( $key ), esc_attr( (string) $value ) );
		}

		// Which batches this submission is allowed to change. Without it, a
		// batch whose column is hidden would be emptied on save.
		foreach ( $batches as $batch ) {
			printf( '<input type="hidden" name="managed[]" value="%d">', (int) $batch['id'] );
		}

		self::render_table( $product_ids, $batches, $batch_map );

		// Sticky, because ticking your way down twenty-five rows and then having
		// to hunt for Save is how a page of work gets lost.
		echo '<div class="wcd-grid-actions">';
		submit_button( __( 'Save this page', 'woo-custom-discount' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Only the products shown here are changed.', 'woo-custom-discount' ) . '</span>';
		echo '</div>';

		echo '</form>';

		self::render_pagination( $query, 'bottom' );

		wp_reset_postdata();
	}

	/**
	 * Search, category and a filter for what to show.
	 */
	private static function render_filters(): void {
		$args = self::current_args();

		echo '<form method="get" class="wcd-grid-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Admin::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="products">';

		printf(
			'<input type="search" name="s" value="%1$s" placeholder="%2$s" class="wcd-grid-search">',
			esc_attr( (string) ( $args['s'] ?? '' ) ),
			esc_attr__( 'Search by name or SKU…', 'woo-custom-discount' )
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);

		if ( ! is_wp_error( $terms ) && $terms !== array() ) {
			printf( '<select name="pcat"><option value="">%s</option>', esc_html__( 'Every category', 'woo-custom-discount' ) );

			foreach ( $terms as $term ) {
				printf(
					'<option value="%1$d"%2$s>%3$s (%4$d)</option>',
					(int) $term->term_id,
					selected( (int) ( $args['pcat'] ?? 0 ), (int) $term->term_id, false ),
					esc_html( $term->name ),
					(int) $term->count
				);
			}

			echo '</select>';
		}

		$shows = array(
			''         => __( 'All products', 'woo-custom-discount' ),
			'batched'  => __( 'Only those in a batch', 'woo-custom-discount' ),
			'unbatched' => __( 'Only those in no batch', 'woo-custom-discount' ),
		);

		echo '<select name="show">';

		foreach ( $shows as $key => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $key ),
				selected( (string) ( $args['show'] ?? '' ), $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		// Only worth offering once there are more batches than fit.
		$total = count( self::all_batches() );

		if ( $total > self::COMFORTABLE_COLUMNS ) {
			$choice  = (string) ( $args['cols'] ?? '' );
			$columns = array(
				'6'   => __( 'Soonest 6 months', 'woo-custom-discount' ),
				'12'  => __( 'Soonest 12 months', 'woo-custom-discount' ),
				'all' => sprintf(
					/* translators: %d: number of batches. */
					__( 'All %d batches', 'woo-custom-discount' ),
					$total
				),
			);

			echo '<select name="cols">';

			foreach ( $columns as $key => $label ) {
				$selected = $choice === $key || ( $choice === '' && $key === (string) self::COMFORTABLE_COLUMNS );

				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $key ),
					selected( $selected, true, false ),
					esc_html( $label )
				);
			}

			echo '</select>';
		}

		submit_button( __( 'Filter', 'woo-custom-discount' ), 'secondary', '', false );

		if ( self::current_args() !== array() ) {
			printf(
				' <a class="button-link" href="%1$s">%2$s</a>',
				esc_url( Admin::url( 'products' ) ),
				esc_html__( 'Clear', 'woo-custom-discount' )
			);
		}

		echo '</form>';
	}

	/**
	 * The grid itself.
	 *
	 * @param int[]                          $product_ids Products on this page.
	 * @param array<int,array<string,mixed>> $batches     Batch columns.
	 * @param array<int,int[]>               $batch_map   Product => batch IDs.
	 */
	private static function render_table( array $product_ids, array $batches, array $batch_map ): void {
		echo '<div class="wcd-grid-wrap"><table class="widefat striped wcd-grid"><thead><tr>';

		echo '<th class="wcd-grid-name">' . esc_html__( 'Product', 'woo-custom-discount' ) . '</th>';
		echo '<th class="wcd-num">' . esc_html__( 'Regular', 'woo-custom-discount' ) . '</th>';
		echo '<th class="wcd-num">' . esc_html__( 'Now', 'woo-custom-discount' ) . '</th>';

		foreach ( $batches as $batch ) {
			$expired = Rules::is_batch_expired( $batch );

			printf(
				'<th class="wcd-grid-batch%1$s"><span class="wcd-grid-batch__month">%2$s</span><span class="wcd-grid-batch__pct">%3$s%%</span>%4$s</th>',
				$expired ? ' is-expired' : '',
				esc_html( self::short_month( (string) $batch['expiry_ym'] ) ),
				esc_html( Admin_Rules::percent_label( $batch['discount_percent'] ) ),
				$expired
					? '<span class="wcd-grid-batch__note">' . esc_html__( 'expired', 'woo-custom-discount' ) . '</span>'
					: ( $batch['enabled'] ? '' : '<span class="wcd-grid-batch__note">' . esc_html__( 'paused', 'woo-custom-discount' ) . '</span>' )
			);
		}

		echo '<th>' . esc_html__( 'Campaign', 'woo-custom-discount' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $product_ids as $product_id ) {
			self::render_row( $product_id, $batches, $batch_map[ $product_id ] ?? array() );
		}

		echo '</tbody></table></div>';
	}

	/**
	 * One product row.
	 *
	 * @param int                            $product_id Product.
	 * @param array<int,array<string,mixed>> $batches    Batch columns.
	 * @param int[]                          $in         Batches it is already in.
	 */
	private static function render_row( int $product_id, array $batches, array $in ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$outcome  = Resolver::resolve( $product_id );
		$regular  = (float) $product->get_regular_price();
		$now      = (float) $product->get_price();
		$campaign = '';

		if ( $outcome !== null && $outcome['type'] === Rules::TYPE_CAMPAIGN ) {
			$rule     = Rules::get( $outcome['rule_id'] );
			$campaign = $rule ? (string) $rule['title'] : '';
		}

		echo '<tr>';

		// Batches this product is in whose column is not on screen. Hiding a
		// column must not hide the fact that the product is assigned to it.
		$shown_ids = wp_list_pluck( $batches, 'id' );
		$offscreen = array_diff( $in, array_map( 'intval', $shown_ids ) );

		$note = '';

		if ( $offscreen !== array() ) {
			$names = array();

			foreach ( $offscreen as $rule_id ) {
				$rule = Rules::get( (int) $rule_id );

				if ( $rule ) {
					$names[] = self::short_month( (string) $rule['expiry_ym'] );
				}
			}

			$note = sprintf(
				'<span class="wcd-grid-more" title="%1$s">%2$s</span>',
				esc_attr(
					sprintf(
						/* translators: %s: comma-separated list of months. */
						__( 'Also in: %s', 'woo-custom-discount' ),
						implode( ', ', $names )
					)
				),
				esc_html(
					sprintf(
						/* translators: %d: number of batches. */
						_n( '+%d more batch', '+%d more batches', count( $offscreen ), 'woo-custom-discount' ),
						count( $offscreen )
					)
				)
			);
		}

		printf(
			'<td class="wcd-grid-name"><a href="%1$s">%2$s</a>%3$s%4$s</td>',
			esc_url( (string) get_edit_post_link( $product_id ) ),
			esc_html( $product->get_name() ),
			$product->get_sku() !== ''
				? '<span class="wcd-grid-sku">' . esc_html( $product->get_sku() ) . '</span>'
				: '',
			$note
		);

		printf( '<td class="wcd-num">%s</td>', esc_html( $regular > 0 ? number_format( $regular ) : '—' ) );

		printf(
			'<td class="wcd-num">%1$s%2$s</td>',
			esc_html( $now > 0 ? number_format( $now ) : '—' ),
			$outcome !== null && $outcome['percent'] > 0
				? '<span class="wcd-grid-pct">' . esc_html( Admin_Rules::percent_label( $outcome['percent'] ) ) . '%</span>'
				: ''
		);

		foreach ( $batches as $batch ) {
			printf(
				'<td class="wcd-grid-cell"><label><input type="checkbox" name="batches[%1$d][]" value="%2$d"%3$s><span class="screen-reader-text">%4$s</span></label></td>',
				$product_id,
				(int) $batch['id'],
				checked( in_array( (int) $batch['id'], $in, true ), true, false ),
				esc_html( $batch['title'] )
			);
		}

		printf(
			'<td class="wcd-grid-campaign">%s</td>',
			$campaign !== '' ? esc_html( $campaign ) : '<span class="wcd-grid-dash">—</span>'
		);

		// Every product on the page is listed, so a row whose boxes are all
		// unticked is still processed and can be emptied.
		printf( '<input type="hidden" name="shown[]" value="%d">', $product_id );

		echo '</tr>';
	}

	/**
	 * Paging links.
	 *
	 * @param \WP_Query $query The product query.
	 */
	private static function render_pagination( \WP_Query $query, string $where = 'bottom' ): void {
		if ( $query->max_num_pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', Admin::url( 'products', self::current_args() ) ),
				'format'    => '',
				'current'   => self::current_page(),
				'total'     => (int) $query->max_num_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);

		if ( $links ) {
			printf(
				'<div class="tablenav wcd-grid-nav wcd-grid-nav--%4$s"><div class="tablenav-pages">
					<span class="displaying-num">%2$s</span>
					<span class="pagination-links">%1$s</span>
				</div></div>',
				$links, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by core.
				esc_html(
					sprintf(
						/* translators: 1: first product on this page, 2: last, 3: total. */
						__( 'Showing %1$d–%2$d of %3$d products', 'woo-custom-discount' ),
						( ( self::current_page() - 1 ) * self::PER_PAGE ) + 1,
						min( self::current_page() * self::PER_PAGE, (int) $query->found_posts ),
						(int) $query->found_posts
					)
				),
				'',
				esc_attr( $where )
			);
		}
	}

	/**
	 * Every batch worth offering as a column, soonest first.
	 *
	 * Expired ones are left out unless they still hold products, which keeps the
	 * table readable without hiding an assignment someone might need to undo.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function all_batches(): array {
		$out = array();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_BATCH ) ) as $batch ) {
			if ( Rules::is_batch_expired( $batch ) && $batch['products'] === array() ) {
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
	 * The batches actually shown as columns.
	 *
	 * A full year of monthly batches is fifteen columns, and the table then
	 * wants about 1,770px — wider than the screen, so it scrolls sideways and
	 * every column past the first few is guesswork. Showing the soonest few by
	 * default keeps it readable; the rest are one click away, and a product
	 * assigned to a hidden batch is flagged on its row rather than lost.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function visible_batches(): array {
		$all   = self::all_batches();
		$limit = self::column_limit( count( $all ) );

		return $limit === 0 ? $all : array_slice( $all, 0, $limit );
	}

	/**
	 * How many columns to draw. Zero means all of them.
	 */
	private static function column_limit( int $total ): int {
		$args   = self::current_args();
		$choice = (string) ( $args['cols'] ?? '' );

		if ( $choice === 'all' ) {
			return 0;
		}

		if ( in_array( $choice, array( '6', '12' ), true ) ) {
			return (int) $choice;
		}

		// Nothing chosen: show everything while it still fits, then cap.
		return $total > self::COMFORTABLE_COLUMNS ? self::COMFORTABLE_COLUMNS : 0;
	}

	/**
	 * The product query for the current view.
	 */
	private static function query_products(): \WP_Query {
		$args = self::current_args();

		$query_args = array(
			'post_type'      => 'product',
			// The same statuses the price engine works on. Listing drafts here
			// showed a discount beside a price that had not moved, because the
			// engine never touches them — a number that looked like a bug in the
			// pricing when it was only a bug in this list.
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => self::current_page(),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		if ( ! empty( $args['s'] ) ) {
			$query_args['s'] = (string) $args['s'];
		}

		if ( ! empty( $args['pcat'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => (int) $args['pcat'],
					'include_children' => true,
				),
			);
		}

		$show = (string) ( $args['show'] ?? '' );

		if ( $show === 'batched' || $show === 'unbatched' ) {
			$batched = Rules::batch_product_ids();

			if ( $show === 'batched' ) {
				// post__in of an empty array would return everything, which is
				// the opposite of what "only those in a batch" means.
				$query_args['post__in'] = $batched === array() ? array( 0 ) : $batched;
			} elseif ( $batched !== array() ) {
				$query_args['post__not_in'] = $batched;
			}
		}

		return new \WP_Query( $query_args );
	}

	/**
	 * Saves the ticks on one page.
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wcd_save_products' );

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}

		$shown   = isset( $_POST['shown'] ) ? array_map( 'intval', (array) $_POST['shown'] ) : array();
		$managed = isset( $_POST['managed'] ) ? array_map( 'intval', (array) $_POST['managed'] ) : array();
		$ticked  = isset( $_POST['batches'] ) ? (array) wp_unslash( $_POST['batches'] ) : array();

		$changed = 0;

		foreach ( $shown as $product_id ) {
			$checked = isset( $ticked[ $product_id ] ) ? array_map( 'intval', (array) $ticked[ $product_id ] ) : array();

			if ( Rules::set_product_batches( $product_id, $checked, $managed ) ) {
				++$changed;
			}
		}

		if ( $changed > 0 ) {
			Resolver::flush();
			Expiry::flush_cache();

			if ( Plugin::engine_can_run() ) {
				Price_Engine::apply_all();
			}
		}

		$view = isset( $_POST['view'] ) ? (array) wp_unslash( $_POST['view'] ) : array();
		$view = array_map( 'sanitize_text_field', $view );

		wp_safe_redirect(
			Admin::url(
				'products',
				array_merge(
					$view,
					array(
						'wcd_message' => $changed > 0
							? sprintf(
								/* translators: %d: number of products. */
								_n( '%d product updated, and prices re-applied.', '%d products updated, and prices re-applied.', $changed, 'woo-custom-discount' ),
								$changed
							)
							: __( 'Nothing changed.', 'woo-custom-discount' ),
					)
				)
			)
		);

		exit;
	}

	/**
	 * The filter arguments currently in the URL.
	 *
	 * @return array<string,string>
	 */
	private static function current_args(): array {
		$out = array();

		foreach ( array( 's', 'pcat', 'show', 'cols' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
			if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
				$out[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			}
		}

		return $out;
	}

	/**
	 * Which page of the grid is being viewed.
	 */
	private static function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	}

	/**
	 * "Aug 26" — short enough to sit above a checkbox column.
	 */
	private static function short_month( string $expiry_ym ): string {
		if ( ! preg_match( '/^(\d{4})(\d{2})$/', $expiry_ym, $m ) ) {
			return $expiry_ym;
		}

		$timestamp = (int) gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] );

		return (string) gmdate( 'M y', $timestamp );
	}
}
