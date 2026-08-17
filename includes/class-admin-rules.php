<?php
/**
 * Campaign and expiry batch screens.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and edits both kinds of rule.
 *
 * One class handles campaigns and batches because they are the same object with
 * a different type. What changes is which fields are shown: a batch asks for an
 * expiry month, a campaign asks for a scope and an optional end date.
 */
class Admin_Rules {

	/**
	 * Hooks the form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_post_wcd_save_rule', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wcd_delete_rule', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wcd_toggle_rule', array( __CLASS__, 'handle_toggle' ) );
	}

	/**
	 * Which tab a type belongs to.
	 */
	private static function tab( string $type ): string {
		return $type === Rules::TYPE_BATCH ? 'batches' : 'campaigns';
	}

	/**
	 * Renders the list, or the editor when editing.
	 */
	public static function render( string $type ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		$id     = isset( $_GET['rule'] ) ? (int) $_GET['rule'] : 0;
		// phpcs:enable

		if ( $action === 'edit' || $action === 'new' ) {
			self::render_editor( $type, $id );

			return;
		}

		self::render_list( $type );
	}

	/**
	 * The list table.
	 */
	private static function render_list( string $type ): void {
		$rules   = Rules::query( array( 'type' => $type ) );
		$is_batch = $type === Rules::TYPE_BATCH;
		$tab     = self::tab( $type );

		echo '<p class="wcd-intro">';

		if ( $is_batch ) {
			esc_html_e( 'A batch groups products that share an expiry month and a discount. When the month passes, the discount ends and the products are hidden from the shop.', 'woo-custom-discount' );
		} else {
			esc_html_e( 'A campaign is an ordinary discount. It can cover the whole store, some categories, or a list of products. When it ends the discount stops, but the products carry on selling as normal.', 'woo-custom-discount' );
		}

		echo '</p>';

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( Admin::url( $tab, array( 'action' => 'new' ) ) ),
			$is_batch
				? esc_html__( 'Add expiry batch', 'woo-custom-discount' )
				: esc_html__( 'Add campaign', 'woo-custom-discount' )
		);

		if ( $rules === array() ) {
			echo '<div class="wcd-empty"><p>';
			esc_html_e( 'Nothing here yet. You can create one by hand, or bring your existing rules across from the Import tab.', 'woo-custom-discount' );
			echo '</p></div>';

			return;
		}

		echo '<table class="widefat striped wcd-list"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Discount', 'woo-custom-discount' ) . '</th>';

		if ( $is_batch ) {
			echo '<th>' . esc_html__( 'Expires', 'woo-custom-discount' ) . '</th>';
			echo '<th>' . esc_html__( 'Time left', 'woo-custom-discount' ) . '</th>';
		} else {
			echo '<th>' . esc_html__( 'Applies to', 'woo-custom-discount' ) . '</th>';
			echo '<th>' . esc_html__( 'Ends', 'woo-custom-discount' ) . '</th>';
		}

		echo '<th>' . esc_html__( 'Products', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Countdown', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'woo-custom-discount' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rules as $rule ) {
			self::render_row( $rule, $is_batch, $tab );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row of the list.
	 *
	 * @param array<string,mixed> $rule     Rule data.
	 * @param bool                $is_batch Whether this is a batch.
	 * @param string              $tab      Tab slug.
	 */
	private static function render_row( array $rule, bool $is_batch, string $tab ): void {
		$expired = $is_batch && Rules::is_batch_expired( $rule );

		echo '<tr' . ( $expired ? ' class="wcd-row-expired"' : '' ) . '>';

		printf(
			'<td><strong><a href="%1$s">%2$s</a></strong>%3$s</td>',
			esc_url( Admin::url( $tab, array( 'action' => 'edit', 'rule' => $rule['id'] ) ) ),
			esc_html( $rule['title'] !== '' ? $rule['title'] : __( '(no name)', 'woo-custom-discount' ) ),
			$rule['notes'] ? '<br><span class="description">' . esc_html( (string) $rule['notes'] ) . '</span>' : ''
		);

		printf( '<td class="wcd-num">%s%%</td>', esc_html( self::percent_label( $rule['discount_percent'] ) ) );

		if ( $is_batch ) {
			printf(
				'<td>%s</td>',
				esc_html( $rule['expiry_ym'] ? Importer::format_expiry( (string) $rule['expiry_ym'] ) : '—' )
			);
			printf( '<td>%s</td>', esc_html( self::time_left( $rule ) ) );
		} else {
			printf( '<td>%s</td>', esc_html( self::scope_label( $rule ) ) );
			printf(
				'<td>%s</td>',
				esc_html( $rule['ends_at'] ? (string) wp_date( 'd M Y, H:i', (int) strtotime( (string) $rule['ends_at'] ) ) : __( 'No end date', 'woo-custom-discount' ) )
			);
		}

		printf( '<td class="wcd-num">%d</td>', count( $rule['products'] ) );

		printf(
			'<td>%s</td>',
			$rule['countdown_enabled']
				? esc_html__( 'Shown', 'woo-custom-discount' )
				: '<span class="description">' . esc_html__( 'Hidden', 'woo-custom-discount' ) . '</span>'
		);

		printf(
			'<td><span class="wcd-pill %1$s">%2$s</span></td>',
			$rule['enabled'] ? 'is-on' : 'is-off',
			$rule['enabled'] ? esc_html__( 'Active', 'woo-custom-discount' ) : esc_html__( 'Paused', 'woo-custom-discount' )
		);

		echo '<td class="wcd-actions">';

		// The name is a link to the editor too, but a button says so plainly —
		// changing a batch from 60% to 50% should not depend on guessing that
		// the title is clickable.
		printf(
			'<a class="button button-small button-primary" href="%1$s">%2$s</a> ',
			esc_url( Admin::url( $tab, array( 'action' => 'edit', 'rule' => $rule['id'] ) ) ),
			esc_html__( 'Edit', 'woo-custom-discount' )
		);

		printf(
			'<a class="button button-small" href="%1$s">%2$s</a> ',
			esc_url( self::action_url( 'wcd_toggle_rule', $rule['id'], $tab ) ),
			$rule['enabled'] ? esc_html__( 'Pause', 'woo-custom-discount' ) : esc_html__( 'Activate', 'woo-custom-discount' )
		);

		printf(
			'<a class="button button-small wcd-delete" href="%1$s" onclick="return confirm(%2$s)">%3$s</a>',
			esc_url( self::action_url( 'wcd_delete_rule', $rule['id'], $tab ) ),
			esc_js( wp_json_encode( __( 'Delete this rule? Prices it set will go back to normal.', 'woo-custom-discount' ) ) ),
			esc_html__( 'Delete', 'woo-custom-discount' )
		);

		echo '</td></tr>';
	}

	/**
	 * The add/edit form.
	 */
	private static function render_editor( string $type, int $id ): void {
		$rule     = $id ? Rules::get( $id ) : null;
		$is_batch = $type === Rules::TYPE_BATCH;
		$tab      = self::tab( $type );

		if ( $id && ( $rule === null || $rule['type'] !== $type ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That rule could not be found.', 'woo-custom-discount' ) . '</p></div>';

			return;
		}

		$value = static function ( string $key, $fallback = '' ) use ( $rule ) {
			return $rule !== null ? $rule[ $key ] : $fallback;
		};

		echo '<h2>';
		echo $id
			? esc_html__( 'Edit', 'woo-custom-discount' )
			: ( $is_batch ? esc_html__( 'New expiry batch', 'woo-custom-discount' ) : esc_html__( 'New campaign', 'woo-custom-discount' ) );
		echo '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-form">';
		wp_nonce_field( 'wcd_save_rule' );
		echo '<input type="hidden" name="action" value="wcd_save_rule">';
		printf( '<input type="hidden" name="type" value="%s">', esc_attr( $type ) );
		printf( '<input type="hidden" name="rule" value="%d">', (int) $id );

		echo '<table class="form-table" role="presentation"><tbody>';

		// --- Name ------------------------------------------------------------
		self::field(
			__( 'Name', 'woo-custom-discount' ),
			sprintf(
				'<input type="text" name="title" class="regular-text" value="%s" required>',
				esc_attr( (string) $value( 'title' ) )
			),
			$is_batch
				? __( 'Something you will recognise, such as "Expiry August 2026".', 'woo-custom-discount' )
				: __( 'Something you will recognise, such as "Ramzan sale".', 'woo-custom-discount' )
		);

		// --- Discount --------------------------------------------------------
		self::field(
			__( 'Discount', 'woo-custom-discount' ),
			sprintf(
				'<input type="number" name="discount_percent" class="small-text" min="0" max="100" step="0.01" value="%s"> %%',
				esc_attr( (string) $value( 'discount_percent', '' ) )
			),
			__( 'Taken off the regular price. Rounded down, so the customer never pays a part-rupee.', 'woo-custom-discount' )
		);

		if ( $is_batch ) {
			// --- Expiry month ------------------------------------------------
			self::field(
				__( 'Expires', 'woo-custom-discount' ),
				self::month_input( (string) $value( 'expiry_ym', '' ) ),
				__( 'Only the month matters — packaging is printed as 08/2026, so the batch runs to the last day of that month.', 'woo-custom-discount' )
			);
		} else {
			// --- Scope -------------------------------------------------------
			$scope = (string) $value( 'scope', Rules::SCOPE_PRODUCTS );

			$options = array(
				Rules::SCOPE_ALL        => __( 'Every product in the store', 'woo-custom-discount' ),
				Rules::SCOPE_CATEGORIES => __( 'Chosen categories', 'woo-custom-discount' ),
				Rules::SCOPE_PRODUCTS   => __( 'Chosen products', 'woo-custom-discount' ),
			);

			$select = '<select name="scope">';

			foreach ( $options as $key => $label ) {
				$select .= sprintf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $key ),
					selected( $scope, $key, false ),
					esc_html( $label )
				);
			}

			$select .= '</select>';

			self::field(
				__( 'Applies to', 'woo-custom-discount' ),
				$select,
				__( 'A product with its own campaign beats a category one, which beats a store-wide one. Discounts never add up.', 'woo-custom-discount' )
			);

			// --- Categories --------------------------------------------------
			self::field(
				__( 'Categories', 'woo-custom-discount' ),
				self::category_select( (array) $value( 'categories', array() ) ),
				__( 'Used only when "Chosen categories" is selected above.', 'woo-custom-discount' )
			);

			// --- End date ----------------------------------------------------
			$ends = (string) $value( 'ends_at', '' );

			self::field(
				__( 'Ends', 'woo-custom-discount' ),
				sprintf(
					'<input type="datetime-local" name="ends_at" value="%s">',
					esc_attr( $ends !== '' ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $ends ) ) : '' )
				),
				__( 'Leave empty to run until you pause it. With an end date, the countdown has something to count to.', 'woo-custom-discount' )
			);
		}

		// --- Products --------------------------------------------------------
		self::field(
			__( 'Products', 'woo-custom-discount' ),
			self::product_select( 'products', (array) $value( 'products', array() ) ),
			$is_batch
				? __( 'The products in this batch.', 'woo-custom-discount' )
				: __( 'Used only when "Chosen products" is selected above.', 'woo-custom-discount' )
		);

		if ( ! $is_batch ) {
			// --- Exclusions --------------------------------------------------
			self::field(
				__( 'Never include', 'woo-custom-discount' ),
				self::product_select( 'excluded', (array) $value( 'excluded', array() ) ),
				__( 'Products this campaign must skip, even a store-wide one. Useful for anything that should stay at full price.', 'woo-custom-discount' )
			);
		}

		// --- Countdown -------------------------------------------------------
		self::field(
			__( 'Countdown', 'woo-custom-discount' ),
			sprintf(
				'<label><input type="checkbox" name="countdown_enabled" value="1"%s> %s</label>',
				checked( (bool) $value( 'countdown_enabled', false ), true, false ),
				esc_html__( 'Show a countdown on these products', 'woo-custom-discount' )
			),
			$is_batch
				? __( 'Counts down to the end of the expiry month.', 'woo-custom-discount' )
				: __( 'Needs an end date above, otherwise there is nothing to count to.', 'woo-custom-discount' )
		);

		// --- Active ----------------------------------------------------------
		self::field(
			__( 'Status', 'woo-custom-discount' ),
			sprintf(
				'<label><input type="checkbox" name="enabled" value="1"%s> %s</label>',
				checked( (bool) $value( 'enabled', true ), true, false ),
				esc_html__( 'Active', 'woo-custom-discount' )
			),
			__( 'Pausing a rule puts its products back to their normal price.', 'woo-custom-discount' )
		);

		echo '</tbody></table>';

		submit_button( __( 'Save', 'woo-custom-discount' ) );

		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( Admin::url( $tab ) ),
			esc_html__( 'Cancel', 'woo-custom-discount' )
		);

		echo '</form>';
	}

	/**
	 * A form-table row.
	 */
	private static function field( string $label, string $control, string $help = '' ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s%3$s</td></tr>',
			esc_html( $label ),
			$control,
			$help !== '' ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * Month and year pickers for a batch.
	 */
	private static function month_input( string $expiry_ym ): string {
		$year  = $expiry_ym !== '' ? (int) substr( $expiry_ym, 0, 4 ) : (int) wp_date( 'Y' );
		$month = $expiry_ym !== '' ? (int) substr( $expiry_ym, 4, 2 ) : (int) wp_date( 'n' );

		$html = '<select name="expiry_month">';

		for ( $i = 1; $i <= 12; $i++ ) {
			$html .= sprintf(
				'<option value="%1$02d"%2$s>%3$s</option>',
				$i,
				selected( $month, $i, false ),
				esc_html( (string) gmdate( 'F', (int) gmmktime( 0, 0, 0, $i, 1, 2000 ) ) )
			);
		}

		$html .= '</select> <select name="expiry_year">';

		$this_year = (int) wp_date( 'Y' );

		for ( $y = $this_year - 1; $y <= $this_year + 6; $y++ ) {
			$html .= sprintf(
				'<option value="%1$d"%2$s>%1$d</option>',
				$y,
				selected( $year, $y, false )
			);
		}

		return $html . '</select>';
	}

	/**
	 * WooCommerce's searchable product picker.
	 *
	 * @param int[] $selected Chosen product IDs.
	 */
	private static function product_select( string $name, array $selected ): string {
		$html = sprintf(
			'<select class="wc-product-search" multiple="multiple" style="width:32em" name="%1$s[]" data-placeholder="%2$s" data-action="woocommerce_json_search_products_and_variations">',
			esc_attr( $name ),
			esc_attr__( 'Search for a product…', 'woo-custom-discount' )
		);

		foreach ( $selected as $product_id ) {
			$product = wc_get_product( (int) $product_id );

			if ( ! $product ) {
				continue;
			}

			$html .= sprintf(
				'<option value="%1$d" selected>%2$s</option>',
				(int) $product_id,
				esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
			);
		}

		return $html . '</select>';
	}

	/**
	 * Category multi-select.
	 *
	 * @param int[] $selected Chosen term IDs.
	 */
	private static function category_select( array $selected ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) || $terms === array() ) {
			return '<p class="description">' . esc_html__( 'No product categories found.', 'woo-custom-discount' ) . '</p>';
		}

		$html = '<select name="categories[]" multiple="multiple" size="8" style="width:32em">';

		foreach ( $terms as $term ) {
			$html .= sprintf(
				'<option value="%1$d"%2$s>%3$s (%4$d)</option>',
				(int) $term->term_id,
				in_array( (int) $term->term_id, array_map( 'intval', $selected ), true ) ? ' selected' : '',
				esc_html( $term->name ),
				(int) $term->count
			);
		}

		return $html . '</select>';
	}

	/**
	 * Saves a rule, then re-prices the catalogue.
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wcd_save_rule' );
		self::guard();

		$type = isset( $_POST['type'] ) && $_POST['type'] === Rules::TYPE_BATCH ? Rules::TYPE_BATCH : Rules::TYPE_CAMPAIGN;
		$id   = isset( $_POST['rule'] ) ? (int) $_POST['rule'] : 0;

		$data = array(
			'type'              => $type,
			'title'             => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
			'discount_percent'  => isset( $_POST['discount_percent'] ) ? (float) $_POST['discount_percent'] : 0.0,
			'countdown_enabled' => ! empty( $_POST['countdown_enabled'] ),
			'enabled'           => ! empty( $_POST['enabled'] ),
			'products'          => isset( $_POST['products'] ) ? array_map( 'intval', (array) $_POST['products'] ) : array(),
		);

		if ( $type === Rules::TYPE_BATCH ) {
			$month = isset( $_POST['expiry_month'] ) ? (int) $_POST['expiry_month'] : 0;
			$year  = isset( $_POST['expiry_year'] ) ? (int) $_POST['expiry_year'] : 0;

			$data['scope']     = Rules::SCOPE_PRODUCTS;
			$data['expiry_ym'] = ( $month >= 1 && $month <= 12 && $year > 2000 )
				? sprintf( '%04d%02d', $year, $month )
				: '';
			$data['ends_at']   = null;
		} else {
			$data['scope']      = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope'] ) ) : Rules::SCOPE_PRODUCTS;
			$data['categories'] = isset( $_POST['categories'] ) ? array_map( 'intval', (array) $_POST['categories'] ) : array();
			$data['excluded']   = isset( $_POST['excluded'] ) ? array_map( 'intval', (array) $_POST['excluded'] ) : array();
			$data['expiry_ym']  = '';

			$ends = isset( $_POST['ends_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ends_at'] ) ) : '';
			$data['ends_at'] = $ends !== '' ? str_replace( 'T', ' ', $ends ) . ':00' : null;
		}

		if ( $id ) {
			Rules::update( $id, $data );
			$message = __( 'Saved.', 'woo-custom-discount' );
		} else {
			Rules::create( $data );
			$message = __( 'Created.', 'woo-custom-discount' );
		}

		self::after_change();

		Admin::redirect_with_message( self::tab( $type ), $message );
	}

	/**
	 * Deletes a rule.
	 */
	public static function handle_delete(): void {
		$id  = isset( $_GET['rule'] ) ? (int) $_GET['rule'] : 0;
		$tab = isset( $_GET['back'] ) ? sanitize_key( wp_unslash( (string) $_GET['back'] ) ) : 'campaigns';

		check_admin_referer( 'wcd_rule_' . $id );
		self::guard();

		Rules::delete( $id );
		self::after_change();

		Admin::redirect_with_message( $tab, __( 'Deleted. Any prices it set are back to normal.', 'woo-custom-discount' ) );
	}

	/**
	 * Pauses or activates a rule.
	 */
	public static function handle_toggle(): void {
		$id  = isset( $_GET['rule'] ) ? (int) $_GET['rule'] : 0;
		$tab = isset( $_GET['back'] ) ? sanitize_key( wp_unslash( (string) $_GET['back'] ) ) : 'campaigns';

		check_admin_referer( 'wcd_rule_' . $id );
		self::guard();

		$rule = Rules::get( $id );

		if ( $rule !== null ) {
			Rules::update( $id, array( 'enabled' => ! $rule['enabled'] ) );
			self::after_change();
		}

		Admin::redirect_with_message( $tab, __( 'Updated.', 'woo-custom-discount' ) );
	}

	/**
	 * A nonce-protected action URL.
	 */
	private static function action_url( string $action, int $rule_id, string $tab ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'rule'   => $rule_id,
					'back'   => $tab,
				),
				admin_url( 'admin-post.php' )
			),
			'wcd_rule_' . $rule_id
		);
	}

	/**
	 * Re-prices the catalogue and clears the caches after any rule change.
	 */
	private static function after_change(): void {
		Resolver::flush();
		Expiry::flush_cache();

		if ( Plugin::engine_can_run() ) {
			Price_Engine::apply_all();
		}
	}

	/**
	 * Stops anyone without the capability.
	 */
	private static function guard(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}
	}

	/**
	 * "60" rather than "60.00".
	 */
	public static function percent_label( float $percent ): string {
		return rtrim( rtrim( number_format( $percent, 2 ), '0' ), '.' );
	}

	/**
	 * Human-readable scope.
	 *
	 * @param array<string,mixed> $rule Rule data.
	 */
	private static function scope_label( array $rule ): string {
		return match ( $rule['scope'] ) {
			Rules::SCOPE_ALL        => __( 'Whole store', 'woo-custom-discount' ),
			Rules::SCOPE_CATEGORIES => sprintf(
				/* translators: %d: number of categories. */
				_n( '%d category', '%d categories', count( $rule['categories'] ), 'woo-custom-discount' ),
				count( $rule['categories'] )
			),
			default                 => __( 'Chosen products', 'woo-custom-discount' ),
		};
	}

	/**
	 * How long a batch has left.
	 *
	 * @param array<string,mixed> $rule Rule data.
	 */
	private static function time_left( array $rule ): string {
		if ( empty( $rule['expiry_ym'] ) ) {
			return '—';
		}

		$end = Rules::expiry_end_timestamp( (string) $rule['expiry_ym'] );

		if ( $end === null ) {
			return '—';
		}

		if ( $end <= time() ) {
			return __( 'Expired', 'woo-custom-discount' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 months". */
			__( '%s left', 'woo-custom-discount' ),
			human_time_diff( time(), $end )
		);
	}
}
