<?php
/**
 * Filter configuration screen.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Where the shop owner decides what the filter offers.
 *
 * The "Suggest from my products" buttons exist because the obvious bands are
 * the wrong ones for this shop. Bands of 60%+, 40-59% and 20-39% would leave
 * most of the catalogue unreachable, since the bulk of it sits at 10% and 15%.
 * Suggesting from the real data avoids that trap.
 */
class Admin_Filters {

	/**
	 * Hooks the form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_post_wcd_save_filters', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wcd_suggest', array( __CLASS__, 'handle_suggest' ) );
	}

	/**
	 * Renders the tab.
	 */
	public static function render(): void {
		$discount_buckets = Buckets::discount_buckets();
		$price_buckets    = Buckets::price_buckets();
		$months           = Expiry::available_months();
		$chosen_months    = array_map( 'strval', (array) Settings::get( 'expiry_months', array() ) );
		$chosen_cats      = array_map( 'intval', (array) Settings::get( 'filter_categories', array() ) );
		$groups           = (array) Settings::get( 'filter_groups', array() );

		echo '<p class="wcd-intro">';
		esc_html_e( 'Six groups are available. Turn off whatever you do not need, and set the bands yourself so nothing in your catalogue becomes unreachable.', 'woo-custom-discount' );
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-form">';
		wp_nonce_field( 'wcd_save_filters' );
		echo '<input type="hidden" name="action" value="wcd_save_filters">';

		// --- Which groups ----------------------------------------------------
		echo '<h2>' . esc_html__( 'Groups', 'woo-custom-discount' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$all_groups = array(
			'discount' => __( 'Discount', 'woo-custom-discount' ),
			'expiry'   => __( 'Expiry month', 'woo-custom-discount' ),
			'category' => __( 'Category', 'woo-custom-discount' ),
			'price'    => __( 'Price', 'woo-custom-discount' ),
			'stock'    => __( 'In stock only', 'woo-custom-discount' ),
			'sort'     => __( 'Sort by (only if your page has no sort dropdown)', 'woo-custom-discount' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Show these', 'woo-custom-discount' ) . '</th><td>';

		foreach ( $all_groups as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="checkbox" name="filter_groups[]" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				in_array( $key, $groups, true ) ? ' checked' : '',
				esc_html( $label )
			);
		}

		echo '<p class="description">';
		esc_html_e( 'Two new orderings — biggest discount, and expiring soonest — are added to the shop\'s own sort dropdown, so you do not need the Sort by group here as well.', 'woo-custom-discount' );
		echo '</p>';

		echo '</td></tr>';

		// --- Placement -------------------------------------------------------
		$position  = (string) Settings::get( 'filter_position', 'none' );
		$positions = array(
			'none'       => __( 'Nowhere automatically — I will use the shortcode or widget', 'woo-custom-discount' ),
			'above_grid' => __( 'Above the product grid on shop and category pages', 'woo-custom-discount' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Placement', 'woo-custom-discount' ) . '</th><td>';

		foreach ( $positions as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="radio" name="filter_position" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $position, $key, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">';
		printf(
			/* translators: %s: the shortcode. */
			esc_html__( 'Shortcode: %s — drops into any page or Divi module. There is also a "Product Filter" widget for Divi sidebars.', 'woo-custom-discount' ),
			'<code>[wcd_filter]</code>'
		);
		echo '</p>';
		echo '</td></tr>';

		// --- Presentation ----------------------------------------------------
		$display  = (string) Settings::get( 'filter_display', 'drawer' );
		$displays = array(
			'drawer' => __( 'A button that slides a panel in from the right', 'woo-custom-discount' ),
			'panel'  => __( 'Always open, for a sidebar column', 'woo-custom-discount' ),
			'auto'   => __( 'Open on wide screens, a drawer on narrow ones', 'woo-custom-discount' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Appearance', 'woo-custom-discount' ) . '</th><td>';

		foreach ( $displays as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="radio" name="filter_display" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $display, $key, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">';
		esc_html_e( 'In drawer form, choices are ticked and then applied together, so the page reloads once instead of once per tick. The shortcode can override this per placement, e.g. [wcd_filter display="panel"].', 'woo-custom-discount' );
		echo '</p>';
		echo '</td></tr>';

		// --- Button side -----------------------------------------------------
		$align  = (string) Settings::get( 'filter_align', 'left' );
		$aligns = array(
			'left'   => __( 'Left', 'woo-custom-discount' ),
			'center' => __( 'Centre', 'woo-custom-discount' ),
			'right'  => __( 'Right', 'woo-custom-discount' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Button side', 'woo-custom-discount' ) . '</th><td>';

		foreach ( $aligns as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="radio" name="filter_align" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $align, $key, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">';
		esc_html_e( 'Choose Right when the row already has a heading on the left. Applies to the drawer button only.', 'woo-custom-discount' );
		echo '</p>';
		echo '</td></tr>';

		// --- Counts ----------------------------------------------------------
		echo '<tr><th scope="row">' . esc_html__( 'Options', 'woo-custom-discount' ) . '</th><td>';

		printf(
			'<label class="wcd-check"><input type="checkbox" name="show_counts" value="1"%s> %s</label>',
			checked( Settings::is_on( 'show_counts' ), true, false ),
			esc_html__( 'Show the number of products beside each option', 'woo-custom-discount' )
		);

		printf(
			'<label class="wcd-check"><input type="checkbox" name="hide_empty" value="1"%s> %s</label>',
			checked( Settings::is_on( 'hide_empty' ), true, false ),
			esc_html__( 'Hide options that currently match nothing', 'woo-custom-discount' )
		);

		echo '</td></tr></tbody></table>';

		// --- Discount bands --------------------------------------------------
		echo '<h2>' . esc_html__( 'Discount bands', 'woo-custom-discount' ) . '</h2>';

		self::render_distribution();

		self::render_bucket_editor( 'discount_buckets', $discount_buckets, '%' );

		// --- Price -----------------------------------------------------------
		echo '<h2>' . esc_html__( 'Price', 'woo-custom-discount' ) . '</h2>';

		$price_mode  = (string) Settings::get( 'price_mode', 'slider' );
		$price_modes = array(
			'slider' => __( 'A slider the customer sets themselves', 'woo-custom-discount' ),
			'bands'  => __( 'Fixed bands, set below', 'woo-custom-discount' ),
		);

		foreach ( $price_modes as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="radio" name="price_mode" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $price_mode, $key, false ),
				esc_html( $label )
			);
		}

		$bounds = Buckets::price_bounds();

		if ( $price_mode === 'slider' && $bounds['max'] > $bounds['min'] ) {
			echo '<p class="description">';
			printf(
				/* translators: 1: lowest price, 2: highest price, 3: step size. */
				esc_html__( 'The slider runs from %1$s to %2$s in steps of %3$s, worked out from your catalogue and updated whenever prices change. The bands below are still used when a visitor has JavaScript switched off, since a sliding range cannot be expressed as a plain link.', 'woo-custom-discount' ),
				esc_html( number_format( $bounds['min'] ) ),
				esc_html( number_format( $bounds['max'] ) ),
				esc_html( number_format( $bounds['step'] ) )
			);
			echo '</p>';
		}

		self::render_bucket_editor( 'price_buckets', $price_buckets, get_woocommerce_currency_symbol() );

		// --- Expiry months ---------------------------------------------------
		echo '<h2>' . esc_html__( 'Expiry months', 'woo-custom-discount' ) . '</h2>';

		if ( $months === array() ) {
			echo '<p class="description">';
			esc_html_e( 'No expiry months yet. Create an expiry batch and its month will appear here.', 'woo-custom-discount' );
			echo '</p>';
		} else {
			echo '<p class="description">';
			esc_html_e( 'Tick the months to offer. Leave all unticked to offer whichever months have products.', 'woo-custom-discount' );
			echo '</p>';

			foreach ( $months as $ym => $count ) {
				printf(
					'<label class="wcd-check"><input type="checkbox" name="expiry_months[]" value="%1$s"%2$s> %3$s <span class="description">(%4$d)</span></label>',
					esc_attr( (string) $ym ),
					in_array( (string) $ym, $chosen_months, true ) ? ' checked' : '',
					esc_html( Importer::format_expiry( (string) $ym ) ),
					(int) $count
				);
			}
		}

		// --- Categories ------------------------------------------------------
		echo '<h2>' . esc_html__( 'Categories', 'woo-custom-discount' ) . '</h2>';
		echo '<p class="description">';
		esc_html_e( 'Tick the categories to offer. The ones you were using to stand in for this plugin — "60% off", "Expiry 08-2026", "Special Discounts" — are marked, because showing them here would repeat what the discount and expiry groups already do.', 'woo-custom-discount' );
		echo '</p>';

		printf(
			'<p>%s</p>',
			self::suggest_button( 'categories', __( 'Tick the sensible ones for me', 'woo-custom-discount' ) )
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			echo '<div class="wcd-cat-grid">';

			foreach ( $terms as $term ) {
				$housekeeping = Buckets::is_housekeeping_category( $term->name );

				printf(
					'<label class="wcd-check%1$s"><input type="checkbox" name="filter_categories[]" value="%2$d"%3$s> %4$s <span class="description">(%5$d)</span>%6$s</label>',
					$housekeeping ? ' is-housekeeping' : '',
					(int) $term->term_id,
					in_array( (int) $term->term_id, $chosen_cats, true ) ? ' checked' : '',
					esc_html( $term->name ),
					(int) $term->count,
					$housekeeping ? ' <em>' . esc_html__( '— duplicates another group', 'woo-custom-discount' ) . '</em>' : ''
				);
			}

			echo '</div>';
		}

		submit_button( __( 'Save filter settings', 'woo-custom-discount' ) );

		echo '</form>';
	}

	/**
	 * Shows what discounts actually exist, so the bands can be chosen honestly.
	 */
	private static function render_distribution(): void {
		$distribution = Buckets::discount_distribution();

		if ( $distribution === array() ) {
			echo '<p class="description">';
			esc_html_e( 'Once the engine has run, this will show how many products sit at each discount, so you can see which bands are worth offering.', 'woo-custom-discount' );
			echo '</p>';

			return;
		}

		echo '<p class="description">' . esc_html__( 'What your catalogue actually holds right now:', 'woo-custom-discount' ) . '</p>';
		echo '<ul class="wcd-dist">';

		foreach ( $distribution as $percent => $count ) {
			printf(
				'<li><strong>%1$s%%</strong> <span>%2$s</span></li>',
				esc_html( Admin_Rules::percent_label( (float) $percent ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of products. */
						_n( '%d product', '%d products', $count, 'woo-custom-discount' ),
						$count
					)
				)
			);
		}

		echo '</ul>';
	}

	/**
	 * Editable list of bands.
	 *
	 * @param string                                                        $name    Field name.
	 * @param array<int,array{key:string,label:string,min:float,max:float}> $buckets Current bands.
	 * @param string                                                        $unit    Unit shown beside the numbers.
	 */
	private static function render_bucket_editor( string $name, array $buckets, string $unit ): void {
		$kind = $name === 'discount_buckets' ? 'discount' : 'price';

		// The symbol arrives HTML-encoded ("&#8360;" for the rupee), so decode it
		// once — escaping it again would print the entity rather than the sign.
		$unit = html_entity_decode( $unit, ENT_QUOTES, 'UTF-8' );

		printf(
			'<p>%s</p>',
			self::suggest_button( $kind, __( 'Suggest from my products', 'woo-custom-discount' ) )
		);

		printf(
			'<table class="widefat striped wcd-buckets" data-wcd-buckets="%s"><thead><tr>',
			esc_attr( $name )
		);
		echo '<th>' . esc_html__( 'Label shown to customers', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'To', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'In the URL', 'woo-custom-discount' ) . '</th>';
		echo '<th class="wcd-bucket-remove-col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'woo-custom-discount' ) . '</span></th>';
		echo '</tr></thead><tbody data-wcd-bucket-rows>';

		foreach ( $buckets as $index => $bucket ) {
			echo self::bucket_row( $name, (string) $index, $bucket, $unit, $kind ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		}

		// One spare row, so adding a band needs no clicks at all when only one is
		// wanted. The Add button covers wanting several.
		echo self::bucket_row( $name, (string) count( $buckets ), self::blank_bucket(), $unit, $kind ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.

		echo '</tbody></table>';

		printf(
			'<p><button type="button" class="button" data-wcd-add-bucket="%1$s">%2$s</button></p>',
			esc_attr( $name ),
			esc_html__( '+ Add another band', 'woo-custom-discount' )
		);

		// The blueprint the Add button copies. __INDEX__ is replaced with a fresh
		// number; the save routine reads the rows in order and ignores the keys,
		// so they only have to be unique.
		printf(
			'<template data-wcd-bucket-template="%1$s">%2$s</template>',
			esc_attr( $name ),
			self::bucket_row( $name, '__INDEX__', self::blank_bucket(), $unit, $kind )
		);

		echo '<p class="description">' . esc_html__( 'Leave "To" empty for an open-ended band, such as "50% and above". A band with no label is dropped when you save.', 'woo-custom-discount' ) . '</p>';
		echo '<p class="description">';
		esc_html_e( 'The code on the right is what appears in the address bar. It is kept as it is when you edit a label, so links you have already shared carry on working.', 'woo-custom-discount' );
		echo '</p>';
	}

	/**
	 * An empty band.
	 *
	 * @return array{key:string,label:string,min:float,max:float}
	 */
	private static function blank_bucket(): array {
		return array(
			'key'   => '',
			'label' => '',
			'min'   => 0.0,
			'max'   => 0.0,
		);
	}

	/**
	 * One row of the band editor.
	 *
	 * @param string                                                 $name   Field name.
	 * @param string                                                 $index  Row index, or the template placeholder.
	 * @param array{key:string,label:string,min:float,max:float}     $bucket Band values.
	 * @param string                                                 $unit   Unit shown beside the numbers.
	 * @param string                                                 $kind   discount or price.
	 */
	private static function bucket_row( string $name, string $index, array $bucket, string $unit, string $kind ): string {
		$blank      = $bucket['label'] === '';
		$open_ended = $bucket['max'] >= ( $kind === 'discount' ? 100.0 : 99999999.0 );

		return sprintf(
			'<tr data-wcd-bucket-row>
				<td><input type="text" name="%1$s[%2$s][label]" class="regular-text" value="%3$s" placeholder="%4$s">
					<input type="hidden" name="%1$s[%2$s][key]" value="%9$s"></td>
				<td><input type="number" name="%1$s[%2$s][min]" class="small-text" step="0.01" min="0" value="%5$s"> %6$s</td>
				<td><input type="number" name="%1$s[%2$s][max]" class="small-text" step="0.01" min="0" value="%7$s" placeholder="%8$s"> %6$s</td>
				<td class="wcd-bucket-key">%10$s</td>
				<td class="wcd-bucket-remove-col">
					<button type="button" class="button-link wcd-bucket-remove" data-wcd-remove-bucket
						aria-label="%11$s">&times;</button>
				</td>
			</tr>',
			esc_attr( $name ),
			esc_attr( $index ),
			esc_attr( $bucket['label'] ),
			esc_attr__( 'e.g. 50% and above', 'woo-custom-discount' ),
			esc_attr( $blank ? '' : (string) $bucket['min'] ),
			esc_html( $unit ),
			esc_attr( $open_ended || $blank ? '' : (string) $bucket['max'] ),
			esc_attr__( 'no limit', 'woo-custom-discount' ),
			esc_attr( $bucket['key'] ),
			$bucket['key'] !== ''
				? '<code>' . esc_html( $bucket['key'] ) . '</code>'
				: '<span class="wcd-bucket-key-pending">' . esc_html__( 'set on save', 'woo-custom-discount' ) . '</span>',
			esc_attr__( 'Remove this band', 'woo-custom-discount' )
		);
	}

	/**
	 * A nonce-protected suggest button.
	 */
	private static function suggest_button( string $kind, string $label ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wcd_suggest',
					'kind'   => $kind,
				),
				admin_url( 'admin-post.php' )
			),
			'wcd_suggest'
		);

		return sprintf(
			'<a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Saves the filter configuration.
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wcd_save_filters' );

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}

		$valid_groups = array( 'discount', 'expiry', 'category', 'price', 'stock', 'sort' );

		$groups = isset( $_POST['filter_groups'] )
			? array_values( array_intersect( array_map( 'sanitize_key', (array) $_POST['filter_groups'] ), $valid_groups ) )
			: array();

		$position = isset( $_POST['filter_position'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter_position'] ) ) : 'none';
		$display  = isset( $_POST['filter_display'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter_display'] ) ) : 'drawer';
		$align      = isset( $_POST['filter_align'] ) ? sanitize_key( wp_unslash( (string) $_POST['filter_align'] ) ) : 'left';
		$price_mode = isset( $_POST['price_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['price_mode'] ) ) : 'slider';

		Settings::update(
			array(
				'filter_groups'     => $groups,
				'filter_position'   => in_array( $position, array( 'none', 'above_grid' ), true ) ? $position : 'none',
				'filter_display'    => in_array( $display, array( 'drawer', 'panel', 'auto' ), true ) ? $display : 'drawer',
				'filter_align'      => in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left',
				'price_mode'        => in_array( $price_mode, array( 'slider', 'bands' ), true ) ? $price_mode : 'slider',
				'show_counts'       => ! empty( $_POST['show_counts'] ),
				'hide_empty'        => ! empty( $_POST['hide_empty'] ),
				'discount_buckets'  => self::clean_buckets( $_POST['discount_buckets'] ?? array(), 'discount' ),
				'price_buckets'     => self::clean_buckets( $_POST['price_buckets'] ?? array(), 'price' ),
				'expiry_months'     => isset( $_POST['expiry_months'] )
					? array_values(
						array_filter(
							array_map( 'sanitize_text_field', (array) $_POST['expiry_months'] ),
							static fn( string $ym ): bool => (bool) preg_match( '/^\d{6}$/', $ym )
						)
					)
					: array(),
				'filter_categories' => isset( $_POST['filter_categories'] )
					? array_values( array_unique( array_map( 'intval', (array) $_POST['filter_categories'] ) ) )
					: array(),
			)
		);

		Admin::redirect_with_message( 'filters', __( 'Filter settings saved.', 'woo-custom-discount' ) );
	}

	/**
	 * Fills the bands from the catalogue.
	 */
	public static function handle_suggest(): void {
		check_admin_referer( 'wcd_suggest' );

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}

		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '';

		switch ( $kind ) {
			case 'discount':
				$buckets = Buckets::suggest_discount_buckets();

				Settings::update( array( 'discount_buckets' => $buckets ) );

				$message = $buckets === array()
					? __( 'No discounts found yet — switch the engine on first, then try again.', 'woo-custom-discount' )
					: sprintf(
						/* translators: %d: number of bands. */
						__( 'Suggested %d discount bands covering every discounted product.', 'woo-custom-discount' ),
						count( $buckets )
					);
				break;

			case 'price':
				$buckets = Buckets::suggest_price_buckets();

				Settings::update( array( 'price_buckets' => $buckets ) );

				$message = sprintf(
					/* translators: %d: number of bands. */
					__( 'Suggested %d price bands.', 'woo-custom-discount' ),
					count( $buckets )
				);
				break;

			case 'categories':
				$ids = Buckets::suggest_filter_categories();

				Settings::update( array( 'filter_categories' => $ids ) );

				$message = sprintf(
					/* translators: %d: number of categories. */
					__( 'Ticked %d categories, leaving out the ones that duplicate another group.', 'woo-custom-discount' ),
					count( $ids )
				);
				break;

			default:
				$message = __( 'Nothing to suggest.', 'woo-custom-discount' );
		}

		Admin::redirect_with_message( 'filters', $message );
	}

	/**
	 * Validates submitted bands, dropping the ones with no label.
	 *
	 * A band's key is what shows up in the address bar, so it is kept exactly as
	 * submitted where one already exists. Regenerating keys on every save would
	 * break links people had already shared — and break them silently, since the
	 * page would still load, just without the filter applied.
	 *
	 * @param mixed  $raw  Submitted rows.
	 * @param string $kind discount or price, used to derive a key for new rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function clean_buckets( $raw, string $kind ): array {
		$out  = array();
		$used = array();

		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );

			if ( $label === '' ) {
				continue;
			}

			$min = (float) ( $row['min'] ?? 0 );
			$max = (float) ( $row['max'] ?? 0 );

			// A band typed in backwards would silently match nothing.
			if ( $max > 0 && $max < $min ) {
				[ $min, $max ] = array( $max, $min );
			}

			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );

			if ( $key === '' ) {
				$key = Buckets::derive_key( $kind, $min, $max );
			}

			// Two bands cannot share a key, or one would be unreachable.
			$base    = $key;
			$attempt = 2;

			while ( in_array( $key, $used, true ) ) {
				$key = $base . '-' . $attempt;
				++$attempt;
			}

			$used[] = $key;

			$out[] = array(
				'key'   => $key,
				'label' => $label,
				'min'   => $min,
				'max'   => $max,
			);
		}

		return $out;
	}
}
