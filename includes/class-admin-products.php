<?php
/**
 * The product-to-batch assignment screen.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns products to expiry batches from one screen.
 *
 * The batch editor asks the question the wrong way round for daily work. It asks
 * "which products are in the August batch"; the shop owner is thinking "this
 * Co Q-10 — some of it expires in August and some in December". Putting one
 * product into three batches meant opening three editors and finding the same
 * product three times.
 *
 * This was first built as a matrix — products down, batches across, a tick where
 * they meet — which reads beautifully at three batches and falls apart at twenty.
 * Twenty columns of checkboxes want about 2,000px of screen, so most of them are
 * always hidden, and a hidden column is an assignment nobody can see.
 *
 * So the batches live under each product's name instead, as chips. A row shows
 * only the batches that product is actually in, which makes the layout
 * indifferent to how many batches exist — three or thirty, the row is the same
 * width.
 */
class Admin_Products {

	/** Rows per page. */
	private const PER_PAGE = 25;

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
		$batches = self::batches();

		echo '<p class="wcd-intro">';
		esc_html_e( 'Each product shows the batches it is in. A product can sit in several — some stock expiring sooner, some later — and each batch carries its own discount.', 'woo-custom-discount' );
		echo '</p>';

		if ( $batches === array() ) {
			echo '<div class="wcd-empty"><p>';
			esc_html_e( 'There are no batches yet, so there is nothing to assign. Create one on the Expiry Batches tab first.', 'woo-custom-discount' );
			echo '</p></div>';

			return;
		}

		self::render_filters( $batches );

		$query = self::query_products();

		if ( ! $query->have_posts() ) {
			echo '<div class="wcd-empty"><p>' . esc_html__( 'No products match that. Try clearing the search or the filters.', 'woo-custom-discount' ) . '</p></div>';

			return;
		}

		$product_ids = array_map( 'intval', $query->posts );
		$batch_map   = Rules::batch_map_for_products( $product_ids );

		self::render_pagination( $query, 'top' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-plist-form">';
		wp_nonce_field( 'wcd_save_products' );
		echo '<input type="hidden" name="action" value="wcd_save_products">';

		foreach ( self::current_args() as $key => $value ) {
			printf( '<input type="hidden" name="view[%s]" value="%s">', esc_attr( $key ), esc_attr( (string) $value ) );
		}

		// Every batch is offered in every picker, so a save is free to change any
		// of them. The matrix had to name the ones it was allowed to touch,
		// because the columns it hid would otherwise have been wiped.
		foreach ( $batches as $batch ) {
			printf( '<input type="hidden" name="managed[]" value="%d">', (int) $batch['id'] );
		}

		echo '<div class="wcd-plist">';

		foreach ( $product_ids as $product_id ) {
			self::render_row( $product_id, $batches, $batch_map[ $product_id ] ?? array() );
		}

		echo '</div>';

		echo '<div class="wcd-plist-actions">';
		submit_button( __( 'Save this page', 'woo-custom-discount' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Only the products shown here are changed.', 'woo-custom-discount' ) . '</span>';
		echo '</div>';

		echo '</form>';

		self::render_pagination( $query, 'bottom' );

		wp_reset_postdata();
	}

	/**
	 * Search, category, and a filter for one batch.
	 *
	 * @param array<int,array<string,mixed>> $batches Available batches.
	 */
	private static function render_filters( array $batches ): void {
		$args = self::current_args();

		echo '<form method="get" class="wcd-plist-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Admin::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="products">';

		printf(
			'<input type="search" name="s" value="%1$s" placeholder="%2$s" class="wcd-plist-search">',
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

		// Replaces what a column gave: a way to see one batch's contents.
		printf( '<select name="batch"><option value="">%s</option>', esc_html__( 'Any batch', 'woo-custom-discount' ) );

		foreach ( $batches as $batch ) {
			printf(
				'<option value="%1$d"%2$s>%3$s (%4$d)</option>',
				(int) $batch['id'],
				selected( (int) ( $args['batch'] ?? 0 ), (int) $batch['id'], false ),
				esc_html( self::batch_label( $batch ) ),
				count( $batch['products'] )
			);
		}

		echo '</select>';

		$shows = array(
			''          => __( 'All products', 'woo-custom-discount' ),
			'batched'   => __( 'In some batch', 'woo-custom-discount' ),
			'unbatched' => __( 'In no batch', 'woo-custom-discount' ),
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

		submit_button( __( 'Filter', 'woo-custom-discount' ), 'secondary', '', false );

		if ( $args !== array() ) {
			printf(
				' <a class="button-link" href="%1$s">%2$s</a>',
				esc_url( Admin::url( 'products' ) ),
				esc_html__( 'Clear', 'woo-custom-discount' )
			);
		}

		echo '</form>';
	}

	/**
	 * One product, with its batches beneath the name.
	 *
	 * @param int                            $product_id Product.
	 * @param array<int,array<string,mixed>> $batches    All batches.
	 * @param int[]                          $in         Batches it is in.
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

		// A variable product keeps no price of its own — the figures live on its
		// variations — so get_regular_price() is empty and the row showed the
		// discounted price with nothing to compare it against. The price the
		// product had before we converted it is stashed for exactly this.
		if ( $regular <= 0 && Variations::owns( $product_id ) ) {
			$regular = (float) get_post_meta( $product_id, Variations::META_BASE_REGULAR, true );
		}

		if ( $outcome !== null && $outcome['type'] === Rules::TYPE_CAMPAIGN ) {
			$rule     = Rules::get( $outcome['rule_id'] );
			$campaign = $rule ? (string) $rule['title'] : '';
		}

		$by_id = array();

		foreach ( $batches as $batch ) {
			$by_id[ (int) $batch['id'] ] = $batch;
		}

		echo '<div class="wcd-plist__row">';

		// --- Name, SKU, prices ----------------------------------------------
		echo '<div class="wcd-plist__head">';

		printf(
			'<div class="wcd-plist__title"><a href="%1$s">%2$s</a>%3$s</div>',
			esc_url( (string) get_edit_post_link( $product_id ) ),
			esc_html( $product->get_name() ),
			$product->get_sku() !== ''
				? '<span class="wcd-plist__sku">' . esc_html( $product->get_sku() ) . '</span>'
				: ''
		);

		echo '<div class="wcd-plist__prices">';

		if ( $regular > 0 && $now > 0 && $now < $regular ) {
			printf(
				'<span class="wcd-plist__was">%1$s</span><span class="wcd-plist__now">%2$s</span>',
				esc_html( number_format( $regular ) ),
				esc_html( number_format( $now ) )
			);
		} else {
			printf(
				'<span class="wcd-plist__now">%s</span>',
				esc_html( $now > 0 ? number_format( $now ) : '—' )
			);
		}

		if ( $outcome !== null && $outcome['percent'] > 0 ) {
			printf(
				'<span class="wcd-plist__pct">%s%%</span>',
				esc_html( Admin_Rules::percent_label( $outcome['percent'] ) )
			);
		}

		echo '</div>';

		printf(
			'<div class="wcd-plist__campaign">%s</div>',
			$campaign !== ''
				? esc_html( $campaign )
				: '<span class="wcd-plist__dash">' . esc_html__( 'no campaign', 'woo-custom-discount' ) . '</span>'
		);

		echo '</div>';

		// --- Batches ---------------------------------------------------------
		printf( '<div class="wcd-plist__batches" data-wcd-batches data-product="%d">', $product_id );

		echo '<span class="wcd-plist__chips" data-wcd-chips>';

		$images = Variations::images_for( $product_id );

		foreach ( $in as $batch_id ) {
			$batch = $by_id[ (int) $batch_id ] ?? null;

			if ( $batch === null ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
			echo self::chip_html( $product_id, $batch, $images[ (int) $batch_id ] ?? 0 );
		}

		echo '</span>';

		// The picker lists every batch; the script hides the ones already on the
		// row, so the same batch cannot be added twice.
		echo '<select class="wcd-plist__add" data-wcd-add>';
		printf( '<option value="">%s</option>', esc_html__( '+ Add to batch', 'woo-custom-discount' ) );

		foreach ( $batches as $batch ) {
			printf(
				'<option value="%1$d" data-label="%2$s"%3$s>%2$s</option>',
				(int) $batch['id'],
				esc_attr( self::batch_label( $batch ) ),
				in_array( (int) $batch['id'], $in, true ) ? ' hidden' : ''
			);
		}

		echo '</select>';

		// The script shows this and stands the select down. Rendered here rather
		// than built in JavaScript so its labels can be translated.
		printf(
			'<button type="button" class="wcd-plist__pick" data-wcd-pick data-search-label="%1$s" data-empty-label="%2$s">%3$s</button>',
			esc_attr__( 'Search batches', 'woo-custom-discount' ),
			esc_attr__( 'No batch matches that', 'woo-custom-discount' ),
			esc_html__( '+ Add to batch', 'woo-custom-discount' )
		);

		echo '</div>';

		printf( '<input type="hidden" name="shown[]" value="%d">', $product_id );

		echo '</div>';
	}

	/**
	 * One batch chip, carrying its own hidden input.
	 *
	 * The input travels with the chip so that removing one removes the other —
	 * there is no separate list to keep in step.
	 *
	 * @param array<string,mixed> $batch Batch data.
	 */
	private static function chip_html( int $product_id, array $batch, int $image_id = 0 ): string {
		$batch_id = (int) $batch['id'];
		$thumb    = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		return sprintf(
			'<span class="wcd-bchip%7$s" data-batch="%1$d">
				<button type="button" class="wcd-bchip__pic" data-wcd-image title="%5$s" aria-label="%5$s">%6$s</button>
				<span class="wcd-bchip__label">%2$s</span>
				<button type="button" class="wcd-bchip__x" data-wcd-remove aria-label="%3$s">&times;</button>
				<input type="hidden" name="batches[%4$d][]" value="%1$d">
				<input type="hidden" class="wcd-bchip__img" name="batch_images[%4$d][%1$d]" value="%8$d">
			</span>',
			$batch_id,
			esc_html( self::batch_label( $batch ) ),
			esc_attr(
				sprintf(
					/* translators: %s: batch name. */
					__( 'Remove from %s', 'woo-custom-discount' ),
					self::batch_label( $batch )
				)
			),
			$product_id,
			esc_attr__( 'Picture for this batch', 'woo-custom-discount' ),
			$thumb !== ''
				? '<img src="' . esc_url( $thumb ) . '" alt="">'
				: '<span class="wcd-bchip__pic-empty" aria-hidden="true">+</span>',
			$thumb !== '' ? ' has-image' : '',
			$image_id
		);
	}

	/**
	 * "Sep 2026 · 60%" — month and discount, which is what tells two apart.
	 *
	 * @param array<string,mixed> $batch Batch data.
	 */
	private static function batch_label( array $batch ): string {
		$month = $batch['expiry_ym']
			? Importer::format_expiry( (string) $batch['expiry_ym'] )
			: (string) $batch['title'];

		return sprintf(
			'%1$s · %2$s%%',
			$month,
			Admin_Rules::percent_label( (float) $batch['discount_percent'] )
		);
	}

	/**
	 * Paging links.
	 *
	 * @param \WP_Query $query The product query.
	 * @param string    $where top or bottom.
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

		if ( ! $links ) {
			return;
		}

		printf(
			'<div class="tablenav wcd-grid-nav wcd-grid-nav--%3$s"><div class="tablenav-pages">
				<span class="displaying-num">%2$s</span>
				<span class="pagination-links">%1$s</span>
			</div></div>',
			$links, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by core.
			esc_html(
				sprintf(
					/* translators: 1: first on this page, 2: last, 3: total. */
					__( 'Showing %1$d–%2$d of %3$d products', 'woo-custom-discount' ),
					( ( self::current_page() - 1 ) * self::PER_PAGE ) + 1,
					min( self::current_page() * self::PER_PAGE, (int) $query->found_posts ),
					(int) $query->found_posts
				)
			),
			esc_attr( $where )
		);
	}

	/**
	 * Every batch worth offering, soonest first.
	 *
	 * Unlike the matrix, this list has no reason to be trimmed: it feeds a
	 * dropdown, which is the same size whether it holds three entries or thirty.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function batches(): array {
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
	 * The product query for the current view.
	 */
	private static function query_products(): \WP_Query {
		$args = self::current_args();

		$query_args = array(
			'post_type'      => 'product',
			// The same statuses the price engine works on, so a discount is
			// never shown beside a price that has not moved.
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

		$limit = null;

		if ( ! empty( $args['batch'] ) ) {
			$rule  = Rules::get( (int) $args['batch'] );
			$limit = $rule ? $rule['products'] : array();
		}

		$show = (string) ( $args['show'] ?? '' );

		if ( $show === 'batched' || $show === 'unbatched' ) {
			$batched = Rules::batch_product_ids();

			if ( $show === 'batched' ) {
				$limit = $limit === null ? $batched : array_intersect( $limit, $batched );
			} elseif ( $batched !== array() ) {
				$query_args['post__not_in'] = $batched;
			}
		}

		if ( $limit !== null ) {
			// An empty post__in returns everything, which is the opposite of
			// what a filter that matched nothing should do.
			$query_args['post__in'] = $limit === array() ? array( 0 ) : array_values( $limit );
		}

		return new \WP_Query( $query_args );
	}

	/**
	 * Saves the batches on one page of products.
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wcd_save_products' );

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}

		$shown   = isset( $_POST['shown'] ) ? array_map( 'intval', (array) $_POST['shown'] ) : array();
		$managed = isset( $_POST['managed'] ) ? array_map( 'intval', (array) $_POST['managed'] ) : array();
		$chosen  = isset( $_POST['batches'] ) ? (array) wp_unslash( $_POST['batches'] ) : array();

		$pictures = isset( $_POST['batch_images'] ) ? (array) wp_unslash( $_POST['batch_images'] ) : array();

		$changed = 0;

		foreach ( $shown as $product_id ) {
			$checked = isset( $chosen[ $product_id ] ) ? array_map( 'intval', (array) $chosen[ $product_id ] ) : array();
			$touched = Rules::set_product_batches( $product_id, $checked, $managed );

			// Pictures are saved for the batches the product is in after the
			// change, so removing a batch takes its picture with it rather than
			// leaving one behind for a batch nobody can see.
			$before = Variations::images_for( $product_id );
			$after  = array();

			foreach ( $checked as $batch_id ) {
				$chosen_image = isset( $pictures[ $product_id ][ $batch_id ] )
					? (int) $pictures[ $product_id ][ $batch_id ]
					: 0;

				if ( $chosen_image > 0 ) {
					$after[ $batch_id ] = $chosen_image;
				}
			}

			if ( $before !== $after ) {
				foreach ( array_keys( $before + $after ) as $batch_id ) {
					Variations::set_image( $product_id, (int) $batch_id, $after[ $batch_id ] ?? 0 );
				}

				$touched = true;
			}

			if ( $touched ) {
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

		foreach ( array( 's', 'pcat', 'show', 'batch' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
			if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
				$out[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			}
		}

		return $out;
	}

	/**
	 * Which page of the list is being viewed.
	 */
	private static function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	}
}
