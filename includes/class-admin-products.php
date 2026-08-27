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
		$batches   = self::batches();
		$campaigns = self::campaigns();
		$blankets  = self::blanket_campaigns();

		echo '<p class="wcd-intro">';
		esc_html_e( 'Each product shows the batches and campaigns it is in. A product can sit in several batches — some stock expiring sooner, some later — and each carries its own discount.', 'woo-custom-discount' );
		echo '</p>';

		if ( $batches === array() && $campaigns === array() && $blankets === array() ) {
			echo '<div class="wcd-empty"><p>';
			esc_html_e( 'There are no batches or campaigns yet, so there is nothing to assign. Create one on the Expiry Batches or Campaigns tab first.', 'woo-custom-discount' );
			echo '</p></div>';

			return;
		}

		self::render_filters( $batches );

		$query = self::query_products();

		if ( ! $query->have_posts() ) {
			echo '<div class="wcd-empty"><p>' . esc_html__( 'No products match that. Try clearing the search or the filters.', 'woo-custom-discount' ) . '</p></div>';

			return;
		}

		$product_ids  = array_map( 'intval', $query->posts );
		$batch_map    = Rules::batch_map_for_products( $product_ids );
		$campaign_map = Rules::campaign_map_for_products( $product_ids );
		$excluded_map = Rules::exclusion_map_for_products( $product_ids );

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
		foreach ( array_merge( $batches, $campaigns ) as $rule ) {
			printf( '<input type="hidden" name="managed[]" value="%d">', (int) $rule['id'] );
		}

		// Kept apart: these are not assigned, only excluded from, and a save
		// must not read a missing chip as "take this product out of the rule".
		foreach ( $blankets as $rule ) {
			printf( '<input type="hidden" name="managed_blanket[]" value="%d">', (int) $rule['id'] );
		}

		echo '<div class="wcd-plist">';

		foreach ( $product_ids as $product_id ) {
			self::render_row(
				$product_id,
				$batches,
				$batch_map[ $product_id ] ?? array(),
				$campaigns,
				$campaign_map[ $product_id ] ?? array(),
				$blankets,
				$excluded_map[ $product_id ] ?? array()
			);
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
				esc_html( self::rule_label( $batch ) ),
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
	 * @param int                            $product_id   Product.
	 * @param array<int,array<string,mixed>> $batches      All batches.
	 * @param int[]                          $in           Batches it is in.
	 * @param array<int,array<string,mixed>> $campaigns    Assignable campaigns.
	 * @param int[]                          $in_campaigns Campaigns it is in.
	 * @param array<int,array<string,mixed>> $blankets     Campaigns that arrive on their own.
	 * @param int[]                          $excluded     Blankets it is kept out of.
	 */
	private static function render_row( int $product_id, array $batches, array $in, array $campaigns = array(), array $in_campaigns = array(), array $blankets = array(), array $excluded = array() ): void {
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

		echo '<div class="wcd-plist__row">';

		// --- Name, SKU, prices ----------------------------------------------
		echo '<div class="wcd-plist__head">';

		// Both open in a new tab on purpose. This screen holds unsaved changes —
		// chips added, pictures chosen — and leaving it to look at a product
		// would throw them away.
		$links = sprintf(
			'<span class="wcd-plist__links">
				<a href="%1$s" target="_blank" rel="noopener">%2$s</a>
				<a href="%3$s" target="_blank" rel="noopener">%4$s</a>
			</span>',
			esc_url( (string) get_edit_post_link( $product_id ) ),
			esc_html__( 'Edit', 'woo-custom-discount' ),
			esc_url( (string) get_permalink( $product_id ) ),
			esc_html__( 'View', 'woo-custom-discount' )
		);

		printf(
			'<div class="wcd-plist__title"><a href="%1$s" target="_blank" rel="noopener">%2$s</a>%3$s%4$s</div>',
			esc_url( (string) get_edit_post_link( $product_id ) ),
			esc_html( $product->get_name() ),
			$product->get_sku() !== ''
				? '<span class="wcd-plist__sku">' . esc_html( $product->get_sku() ) . '</span>'
				: '',
			$links // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
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

		// --- Everything it is in, in the order a shopper will see it ---------
		self::render_chips(
			$product_id,
			$batches,
			$in,
			$campaigns,
			$in_campaigns,
			self::blankets_over( $product_id, $blankets ),
			$excluded
		);

		printf( '<input type="hidden" name="shown[]" value="%d">', $product_id );

		echo '</div>';
	}

	/**
	 * Every chip this product carries, in the order a shopper will see them.
	 *
	 * Batches and campaigns used to sit in separate rows, which was tidy until
	 * the order became something the shop sets. A page that groups by kind then
	 * says the batch comes first while the product page says the campaign does,
	 * and the one place you would go to check is the one place that lies. So
	 * there is one row now, sorted the way the product page sorts, and it is the
	 * pickers that stay apart — each adds to a different list.
	 *
	 * @param array<int,array<string,mixed>> $batches      All batches.
	 * @param int[]                          $in           Batches it is in.
	 * @param array<int,array<string,mixed>> $campaigns    Assignable campaigns.
	 * @param int[]                          $in_campaigns Campaigns it is in.
	 * @param array<int,array<string,mixed>> $over         Blankets falling on it.
	 * @param int[]                          $excluded     Blankets it is kept out of.
	 */
	private static function render_chips( int $product_id, array $batches, array $in, array $campaigns, array $in_campaigns, array $over, array $excluded ): void {
		$images = Variations::images_for( $product_id );
		$stock  = Variations::stock_for( $product_id );

		$chips = array();

		$collect = static function ( array $rules, array $member, string $kind, bool $blanket ) use ( &$chips ): void {
			$by_id = array();

			foreach ( $rules as $rule ) {
				$by_id[ (int) $rule['id'] ] = $rule;
			}

			foreach ( $member as $rule_id ) {
				if ( isset( $by_id[ (int) $rule_id ] ) ) {
					$chips[] = array(
						'rule'    => $by_id[ (int) $rule_id ],
						'kind'    => $kind,
						'blanket' => $blanket,
					);
				}
			}
		};

		$collect( $batches, $in, Rules::TYPE_BATCH, false );
		$collect( $campaigns, $in_campaigns, Rules::TYPE_CAMPAIGN, false );
		$collect( $over, wp_list_pluck( $over, 'id' ), Rules::TYPE_CAMPAIGN, true );

		// The same sort the product page uses, for the same reason.
		usort(
			$chips,
			static function ( array $a, array $b ): int {
				$by_order = (int) $a['rule']['priority'] <=> (int) $b['rule']['priority'];

				return $by_order !== 0 ? $by_order : (int) $a['rule']['id'] <=> (int) $b['rule']['id'];
			}
		);

		printf( '<div class="wcd-plist__batches" data-wcd-batches data-product="%d">', $product_id );

		echo '<span class="wcd-plist__chips" data-wcd-chips>';

		foreach ( $chips as $chip ) {
			$id = (int) $chip['rule']['id'];

			if ( $chip['blanket'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
				echo self::blanket_chip_html(
					$product_id,
					$chip['rule'],
					in_array( $id, $excluded, true ),
					$images[ $id ] ?? 0
				);

				continue;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
			echo self::chip_html(
				$product_id,
				$chip['rule'],
				$images[ $id ] ?? 0,
				$chip['kind'] === Rules::TYPE_BATCH ? ( $stock[ $id ] ?? null ) : null,
				$chip['kind']
			);
		}

		echo '</span>';

		self::render_picker( $batches, $in, Rules::TYPE_BATCH );

		if ( $campaigns !== array() ) {
			self::render_picker( $campaigns, $in_campaigns, Rules::TYPE_CAMPAIGN );
		}

		echo '</div>';
	}

	/**
	 * The search box that adds one more rule of a given kind to the row.
	 *
	 * What a new chip should look like travels on the select rather than on the
	 * row, because the row now holds both kinds and the script has to know which
	 * of them it is building.
	 *
	 * @param array<int,array<string,mixed>> $rules Every rule of this kind.
	 * @param int[]                          $in    The ones it is already in.
	 * @param string                         $kind  Batch or campaign.
	 */
	private static function render_picker( array $rules, array $in, string $kind ): void {
		if ( $rules === array() ) {
			return;
		}

		$is_batch = $kind === Rules::TYPE_BATCH;

		$add    = $is_batch ? __( '+ Add to batch', 'woo-custom-discount' ) : __( '+ Add to campaign', 'woo-custom-discount' );
		$search = $is_batch ? __( 'Search batches', 'woo-custom-discount' ) : __( 'Search campaigns', 'woo-custom-discount' );
		$none   = $is_batch ? __( 'No batch matches that', 'woo-custom-discount' ) : __( 'No campaign matches that', 'woo-custom-discount' );

		// The picker lists every rule; the script hides the ones already on the
		// row, so the same one cannot be added twice.
		printf(
			'<select class="wcd-plist__add" data-wcd-add data-wcd-field="%1$s"%2$s>',
			$is_batch ? 'batches' : 'campaigns',
			$is_batch ? '' : ' data-wcd-plain'
		);

		printf( '<option value="">%s</option>', esc_html( $add ) );

		foreach ( $rules as $rule ) {
			printf(
				'<option value="%1$d" data-label="%2$s"%3$s>%2$s</option>',
				(int) $rule['id'],
				esc_attr( self::rule_label( $rule, $kind ) ),
				in_array( (int) $rule['id'], $in, true ) ? ' hidden' : ''
			);
		}

		echo '</select>';

		// The script shows this and stands the select down. Rendered here rather
		// than built in JavaScript so its labels can be translated.
		printf(
			'<button type="button" class="wcd-plist__pick" data-wcd-pick data-search-label="%1$s" data-empty-label="%2$s">%3$s</button>',
			esc_attr( $search ),
			esc_attr( $none ),
			esc_html( $add )
		);
	}

	/**
	 * One batch chip, carrying its own hidden input.
	 *
	 * The input travels with the chip so that removing one removes the other —
	 * there is no separate list to keep in step.
	 *
	 * @param array<string,mixed> $batch Batch data.
	 */
	private static function chip_html( int $product_id, array $batch, int $image_id = 0, ?int $stock = null, string $kind = Rules::TYPE_BATCH ): string {
		$batch_id = (int) $batch['id'];
		$thumb    = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		// A campaign gets a picture but no count. It shows up as a variation the
		// shopper picks, so it wants a picture like any other; what it does not
		// have is a lot of stock of its own to tally.
		if ( $kind !== Rules::TYPE_BATCH ) {
			return sprintf(
				'<span class="wcd-bchip wcd-bchip--plain%6$s" data-batch="%1$d">
					<button type="button" class="wcd-bchip__pic" data-wcd-image title="%5$s" aria-label="%5$s">%7$s</button>
					<span class="wcd-bchip__label">%2$s</span>
					<button type="button" class="wcd-bchip__x" data-wcd-remove aria-label="%3$s">&times;</button>
					<input type="hidden" name="campaigns[%4$d][]" value="%1$d">
					<input type="hidden" class="wcd-bchip__img" name="batch_images[%4$d][%1$d]" value="%8$d">
				</span>',
				$batch_id,
				esc_html( self::rule_label( $batch, $kind ) ),
				esc_attr(
					sprintf(
						/* translators: %s: campaign name. */
						__( 'Remove from %s', 'woo-custom-discount' ),
						self::rule_label( $batch, $kind )
					)
				),
				$product_id,
				esc_attr__( 'Picture for this campaign', 'woo-custom-discount' ),
				$thumb !== '' ? ' has-image' : '',
				$thumb !== ''
					? '<img src="' . esc_url( $thumb ) . '" alt="">'
					: '<span class="wcd-bchip__pic-empty" aria-hidden="true">+</span>',
				$image_id
			);
		}

		return sprintf(
			'<span class="wcd-bchip%7$s" data-batch="%1$d">
				<button type="button" class="wcd-bchip__pic" data-wcd-image title="%5$s" aria-label="%5$s">%6$s</button>
				<span class="wcd-bchip__label">%2$s</span>
				<input type="number" class="wcd-bchip__qty" name="batch_stock[%4$d][%1$d]" value="%9$s"
					min="0" step="1" inputmode="numeric" placeholder="%10$s" title="%11$s" aria-label="%11$s">
				<button type="button" class="wcd-bchip__x" data-wcd-remove aria-label="%3$s">&times;</button>
				<input type="hidden" name="batches[%4$d][]" value="%1$d">
				<input type="hidden" class="wcd-bchip__img" name="batch_images[%4$d][%1$d]" value="%8$d">
			</span>',
			$batch_id,
			esc_html( self::rule_label( $batch ) ),
			esc_attr(
				sprintf(
					/* translators: %s: batch name. */
					__( 'Remove from %s', 'woo-custom-discount' ),
					self::rule_label( $batch )
				)
			),
			$product_id,
			esc_attr__( 'Picture for this batch', 'woo-custom-discount' ),
			$thumb !== ''
				? '<img src="' . esc_url( $thumb ) . '" alt="">'
				: '<span class="wcd-bchip__pic-empty" aria-hidden="true">+</span>',
			$thumb !== '' ? ' has-image' : '',
			$image_id,
			$stock === null ? '' : esc_attr( (string) $stock ),
			esc_attr_x( '∞', 'no separate stock for this batch', 'woo-custom-discount' ),
			esc_attr__( 'How many are left in this batch. Leave empty to sell against the product\'s own stock.', 'woo-custom-discount' )
		);
	}

	/**
	 * "Sep 2026 · 60%" — month and discount, which is what tells two apart.
	 *
	 * A batch shown to shoppers under its own name is listed here under that
	 * name too. Otherwise it answers to a month nobody thinks of it by, and
	 * cannot be found by typing what it is called.
	 *
	 * A campaign has only its name, which is already how the shop thinks of it.
	 *
	 * @param array<string,mixed> $batch Rule data.
	 * @param string              $kind  Batch or campaign.
	 */
	private static function rule_label( array $batch, string $kind = Rules::TYPE_BATCH ): string {
		$percent = Admin_Rules::percent_label( (float) $batch['discount_percent'] );

		if ( $kind !== Rules::TYPE_BATCH ) {
			return sprintf( '%1$s · %2$s%%', (string) $batch['title'], $percent );
		}

		$chosen = trim( (string) ( $batch['display_label'] ?? '' ) );
		$month  = $batch['expiry_ym']
			? Importer::format_expiry( (string) $batch['expiry_ym'] )
			: (string) $batch['title'];

		return sprintf( '%1$s · %2$s%%', $chosen !== '' ? $chosen : $month, $percent );
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
	 * The campaigns a product can be put into from here.
	 *
	 * Only those aimed at named products. A campaign covering the whole shop, or
	 * a whole category, already reaches this product without being told to, and
	 * adding it to the list would offer a switch that does nothing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function campaigns(): array {
		$out = array();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_CAMPAIGN ) ) as $campaign ) {
			if ( $campaign['scope'] !== Rules::SCOPE_PRODUCTS ) {
				continue;
			}

			$out[] = $campaign;
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => strcasecmp( (string) $a['title'], (string) $b['title'] )
		);

		return $out;
	}

	/**
	 * A chip for a campaign that arrives on its own.
	 *
	 * This one cannot be taken off the row, because the campaign covers the
	 * product whether the row says so or not. What the cross does instead is
	 * keep this product out of it — and pressing it again lets it back in, so
	 * nothing is lost by trying. The state travels in the input's value rather
	 * than in whether the input exists, which is what makes it reversible
	 * without a picker to fetch the chip back from.
	 *
	 * @param array<string,mixed> $campaign Campaign data.
	 */
	private static function blanket_chip_html( int $product_id, array $campaign, bool $excluded, int $image_id = 0 ): string {
		$id    = (int) $campaign['id'];
		$thumb = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		$reach = $campaign['scope'] === Rules::SCOPE_ALL
			? __( 'every product', 'woo-custom-discount' )
			: __( 'a category', 'woo-custom-discount' );

		return sprintf(
			'<span class="wcd-bchip wcd-bchip--plain wcd-bchip--auto%6$s%12$s" data-batch="%1$d" title="%5$s">
				<button type="button" class="wcd-bchip__pic" data-wcd-image title="%11$s" aria-label="%11$s">%13$s</button>
				<span class="wcd-bchip__label">%2$s</span>
				<button type="button" class="wcd-bchip__x" data-wcd-toggle
					data-in="%7$s" data-out="%8$s" aria-label="%3$s">%9$s</button>
				<input type="hidden" name="blanket[%4$d][%1$d]" value="%10$s">
				<input type="hidden" class="wcd-bchip__img" name="batch_images[%4$d][%1$d]" value="%14$d">
			</span>',
			$id,
			esc_html( self::rule_label( $campaign, Rules::TYPE_CAMPAIGN ) ),
			esc_attr(
				$excluded
					? sprintf(
						/* translators: %s: campaign name. */
						__( 'Put this product back into %s', 'woo-custom-discount' ),
						(string) $campaign['title']
					)
					: sprintf(
						/* translators: %s: campaign name. */
						__( 'Keep this product out of %s', 'woo-custom-discount' ),
						(string) $campaign['title']
					)
			),
			$product_id,
			esc_attr(
				sprintf(
					/* translators: %s: what the campaign covers. */
					__( 'Runs on %s, so it was not assigned here.', 'woo-custom-discount' ),
					$reach
				)
			),
			$excluded ? ' is-excluded' : '',
			esc_attr__( 'Keep this product out', 'woo-custom-discount' ),
			esc_attr__( 'Put this product back', 'woo-custom-discount' ),
			$excluded ? '&plus;' : '&times;',
			$excluded ? '0' : '1',
			esc_attr__( 'Picture for this campaign', 'woo-custom-discount' ),
			$thumb !== '' ? ' has-image' : '',
			$thumb !== ''
				? '<img src="' . esc_url( $thumb ) . '" alt="">'
				: '<span class="wcd-bchip__pic-empty" aria-hidden="true">+</span>',
			$image_id
		);
	}

	/**
	 * Campaigns that reach a product without being told to.
	 *
	 * A campaign set to the whole shop, or to a category, needs no assignment —
	 * it simply covers whatever falls under it. Those are the discounts a shop
	 * owner forgets are running, so the row shows them, and the only thing that
	 * can be done to one from here is to keep this product out of it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function blanket_campaigns(): array {
		$out = array();

		foreach ( Rules::query( array( 'type' => Rules::TYPE_CAMPAIGN, 'enabled' => true ) ) as $campaign ) {
			if ( $campaign['scope'] === Rules::SCOPE_PRODUCTS ) {
				continue;
			}

			$out[] = $campaign;
		}

		return $out;
	}

	/**
	 * Which of the blanket campaigns fall over this particular product.
	 *
	 * @param array<int,array<string,mixed>> $blankets From blanket_campaigns().
	 * @return array<int,array<string,mixed>>
	 */
	private static function blankets_over( int $product_id, array $blankets ): array {
		if ( $blankets === array() ) {
			return array();
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$terms = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );

		foreach ( $terms as $term_id ) {
			$terms = array_merge( $terms, array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
		}

		$out = array();

		foreach ( $blankets as $campaign ) {
			$covers = $campaign['scope'] === Rules::SCOPE_ALL
				|| (bool) array_intersect( $campaign['categories'], $terms );

			if ( $covers ) {
				$out[] = $campaign;
			}
		}

		return $out;
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
		$joined  = isset( $_POST['campaigns'] ) ? (array) wp_unslash( $_POST['campaigns'] ) : array();

		$blankets = isset( $_POST['managed_blanket'] ) ? array_map( 'intval', (array) $_POST['managed_blanket'] ) : array();
		$keep_out = isset( $_POST['blanket'] ) ? (array) wp_unslash( $_POST['blanket'] ) : array();

		$pictures = isset( $_POST['batch_images'] ) ? (array) wp_unslash( $_POST['batch_images'] ) : array();
		$counts   = isset( $_POST['batch_stock'] ) ? (array) wp_unslash( $_POST['batch_stock'] ) : array();

		$changed = 0;

		foreach ( $shown as $product_id ) {
			$checked = isset( $chosen[ $product_id ] ) ? array_map( 'intval', (array) $chosen[ $product_id ] ) : array();

			// Batches and campaigns go in together. `$managed` names every rule
			// the page offered, so one call settles both lists and a rule the
			// page did not show keeps whatever it had.
			$campaigns = isset( $joined[ $product_id ] ) ? array_map( 'intval', (array) $joined[ $product_id ] ) : array();
			$touched   = Rules::set_product_rules( $product_id, array_merge( $checked, $campaigns ), $managed );

			// A blanket campaign is never joined, only stepped out of. Its chip
			// always submits, carrying 1 for "leave it running here" and 0 for
			// "keep this product out", so a row the shop never touched says so
			// rather than saying nothing.
			$out = array();

			foreach ( $blankets as $rule_id ) {
				if ( ( $keep_out[ $product_id ][ $rule_id ] ?? '1' ) === '0' ) {
					$out[] = $rule_id;
				}
			}

			if ( Rules::set_product_rules( $product_id, $out, $blankets, Rules::ITEM_EXCLUDE ) ) {
				$touched = true;
			}

			// Pictures are saved for whatever the product is in after the change
			// — batches and campaigns alike — so removing one takes its picture
			// with it rather than leaving one behind for a chip nobody can see.
			$before = Variations::images_for( $product_id );
			$after  = array();

			// A blanket campaign the product is still inside keeps its picture;
			// one it has been taken out of loses it, exactly as a removed batch
			// does.
			$staying = array_values( array_diff( $blankets, $out ) );

			foreach ( array_merge( $checked, $campaigns, $staying ) as $batch_id ) {
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

			// Quantities, the same way: only for the batches the product is still
			// in, and an empty box means no separate count rather than zero.
			$stock_before = Variations::stock_for( $product_id );
			$stock_after  = array();

			foreach ( $checked as $batch_id ) {
				$raw = isset( $counts[ $product_id ][ $batch_id ] )
					? trim( (string) $counts[ $product_id ][ $batch_id ] )
					: '';

				if ( $raw !== '' && is_numeric( $raw ) ) {
					$stock_after[ $batch_id ] = max( 0, (int) $raw );
				}
			}

			if ( $stock_before !== $stock_after ) {
				foreach ( array_keys( $stock_before + $stock_after ) as $batch_id ) {
					Variations::set_stock(
						$product_id,
						(int) $batch_id,
						array_key_exists( $batch_id, $stock_after ) ? (int) $stock_after[ $batch_id ] : null
					);
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
