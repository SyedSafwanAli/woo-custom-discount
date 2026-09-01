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

		echo '<div class="wcd-toolbar">';

		echo '<p class="wcd-intro">';

		if ( $is_batch ) {
			esc_html_e( 'Stock that is running out of shelf life. Each batch has one expiry month and one discount. Once the month passes, the discount stops and the products are hidden — nothing is deleted, so it is all reversible.', 'woo-custom-discount' );
		} else {
			esc_html_e( 'Ordinary discounts — a sale, a bundle offer, a blanket percentage. When a campaign ends the discount stops, but the products stay in the shop. Discounts never add up: the most specific rule wins.', 'woo-custom-discount' );
		}

		echo '</p>';

		printf(
			'<a class="button button-primary button-hero wcd-add" href="%1$s">%2$s</a>',
			esc_url( Admin::url( $tab, array( 'action' => 'new' ) ) ),
			$is_batch
				? esc_html__( 'Add expiry batch', 'woo-custom-discount' )
				: esc_html__( 'Add campaign', 'woo-custom-discount' )
		);

		echo '</div>';

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
	 *
	 * Grouped into sections rather than presented as one long column of
	 * unrelated fields, and the fields that do not apply to the chosen scope are
	 * hidden rather than labelled "used only when…". Someone else has to be able
	 * to change a discount here without being walked through it.
	 */
	private static function render_editor( string $type, int $id ): void {
		$rule     = $id ? Rules::get( $id ) : null;
		$is_batch = $type === Rules::TYPE_BATCH;
		$tab      = self::tab( $type );

		if ( $id && ( $rule === null || $rule['type'] !== $type ) ) {
			// A dead end here is easy to land on: rules get new IDs when they
			// are re-imported, so a bookmarked or still-open edit link outlives
			// the rule it points at. Say so, and offer the way out.
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'That rule no longer exists. Rules are given new numbers when they are re-imported, so a link you had open may be pointing at an older one.', 'woo-custom-discount' );
			echo '</p></div>';

			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
				esc_url( Admin::url( $tab ) ),
				$is_batch
					? esc_html__( 'See all expiry batches', 'woo-custom-discount' )
					: esc_html__( 'See all campaigns', 'woo-custom-discount' )
			);

			self::render_list( $type );

			return;
		}

		$value = static function ( string $key, $fallback = '' ) use ( $rule ) {
			return $rule !== null ? $rule[ $key ] : $fallback;
		};

		// --- Heading and the way back ----------------------------------------
		printf(
			'<p class="wcd-breadcrumb"><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Admin::url( $tab ) ),
			$is_batch
				? esc_html__( 'All expiry batches', 'woo-custom-discount' )
				: esc_html__( 'All campaigns', 'woo-custom-discount' )
		);

		echo '<h2 class="wcd-editor-title">';

		if ( $id ) {
			printf(
				/* translators: %s: rule name. */
				esc_html__( 'Editing “%s”', 'woo-custom-discount' ),
				esc_html( (string) $value( 'title' ) )
			);
		} else {
			echo $is_batch
				? esc_html__( 'New expiry batch', 'woo-custom-discount' )
				: esc_html__( 'New campaign', 'woo-custom-discount' );
		}

		echo '</h2>';

		self::render_explainer( $is_batch );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-form">';
		wp_nonce_field( 'wcd_save_rule' );
		echo '<input type="hidden" name="action" value="wcd_save_rule">';
		printf( '<input type="hidden" name="type" value="%s">', esc_attr( $type ) );
		printf( '<input type="hidden" name="rule" value="%d">', (int) $id );

		// ==================================================================
		// 1. The basics
		// ==================================================================
		self::open_section( __( 'The basics', 'woo-custom-discount' ) );

		self::field(
			__( 'Name', 'woo-custom-discount' ),
			sprintf(
				'<input type="text" name="title" class="regular-text" value="%s" required>',
				esc_attr( (string) $value( 'title' ) )
			),
			$is_batch
				? __( 'Only you see this. Naming it after the month — “Expiry August 2026” — makes the list easy to scan.', 'woo-custom-discount' )
				: __( 'Only you see this. Something like “Ramzan sale” or “Bundle offer”.', 'woo-custom-discount' )
		);

		self::field(
			__( 'Discount', 'woo-custom-discount' ),
			sprintf(
				'<span class="wcd-input-suffix"><input type="number" name="discount_percent" class="small-text" min="0" max="100" step="0.01" value="%s" required><span>%%</span></span>',
				esc_attr( (string) $value( 'discount_percent', '' ) )
			),
			__( 'Taken off the regular price, and rounded down — 6,995 less 60% becomes 2,798, never 2,798.50.', 'woo-custom-discount' )
		);

		self::field(
			__( 'Free with each one', 'woo-custom-discount' ),
			sprintf(
				'<input type="number" name="free_extras" class="small-text" min="0" step="1" value="%s">',
				esc_attr( (string) $value( 'free_extras', 0 ) )
			),
			__( 'How many more the shopper takes home without paying. Buy one get one free is 1. The page then shows what that many would ordinarily cost, struck through, beside the one payment being asked for — one bottle at 5,295 reads as 10,590 down to 5,295, half off. No price in the catalogue is touched and the cart charges the single price, so sending the extras is yours to do.', 'woo-custom-discount' )
		);

		self::field(
			__( 'Instead of the percentage', 'woo-custom-discount' ),
			sprintf(
				'<input type="text" name="badge" class="regular-text" value="%1$s" placeholder="%2$s">',
				esc_attr( (string) $value( 'badge', '' ) ),
				esc_attr(
					(float) $value( 'discount_percent', 0 ) > 0
						? sprintf(
							/* translators: %s: percentage. */
							__( '%s%% off', 'woo-custom-discount' ),
							Admin_Rules::percent_label( (float) $value( 'discount_percent', 0 ) )
						)
						: __( 'nothing', 'woo-custom-discount' )
				)
			),
			__( 'Words to show beside this choice on the product page. Leave it empty and the discount is shown — an offer that takes nothing off the price shows nothing at all, which is why “Buy One Get One Free” sits there blank. Write what the shopper gains: “2 for 1”, “Second bottle free”. Take care with a percentage: the price beside it is not reduced, so “50% off” would read as though it already had been.', 'woo-custom-discount' )
		);

		self::field(
			__( 'Order shown', 'woo-custom-discount' ),
			sprintf(
				'<input type="number" name="priority" class="small-text" step="1" value="%s">',
				esc_attr( (string) $value( 'priority', 10 ) )
			),
			__( 'Where this sits in the list a shopper picks from, when a product has more than one choice. Smaller numbers come first. Leave every rule on 10 and they fall in the order they were made.', 'woo-custom-discount' )
		);

		self::close_section();

		if ( $is_batch ) {
			// ==============================================================
			// 2. When the stock expires
			// ==============================================================
			self::open_section(
				__( 'When this stock expires', 'woo-custom-discount' ),
				__( 'Pick the month printed on the packaging. The batch runs to the last moment of it.', 'woo-custom-discount' )
			);

			$expiry_ym = (string) $value( 'expiry_ym', '' );

			self::field(
				__( 'Expires', 'woo-custom-discount' ),
				self::month_input( $expiry_ym ),
				self::expiry_note( $expiry_ym )
			);

			self::field(
				__( 'Shown to shoppers', 'woo-custom-discount' ),
				sprintf(
					'<input type="text" name="display_label" class="regular-text" value="%1$s" placeholder="%2$s">',
					esc_attr( (string) $value( 'display_label', '' ) ),
					esc_attr(
						$expiry_ym !== ''
							? Importer::format_expiry( $expiry_ym )
							: __( 'the month above', 'woo-custom-discount' )
					)
				),
				__( 'Leave this empty and the batch is listed by its month, which is what a shopper comparing stock wants to know. Fill it in when the batch is an offer rather than a date — “Buy One Get One Free” — and that is what appears on the product page instead.', 'woo-custom-discount' )
			);

			self::close_section();

			// ==============================================================
			// 3. What is in the batch
			// ==============================================================
			$in_batch = (array) $value( 'products', array() );

			self::open_section(
				__( 'Which products', 'woo-custom-discount' ),
				count( $in_batch ) > 0
					? sprintf(
						/* translators: %d: number of products. */
						(string) _n( '%d product is in this batch.', '%d products are in this batch.', count( $in_batch ), 'woo-custom-discount' ),
						count( $in_batch )
					)
					: (string) __( 'Nothing in it yet. A batch with no products does nothing.', 'woo-custom-discount' )
			);

			self::field(
				__( 'Products', 'woo-custom-discount' ),
				self::product_select( 'products', $in_batch ),
				__( 'Start typing a product name to find it. A product can sit in several batches at once — some stock expiring sooner, some later.', 'woo-custom-discount' )
			);

			// Pointing at the better tool. This box is fine for one batch; it is
			// the wrong shape for putting one product into three, which means
			// opening three of these screens and finding the same product each
			// time.
			printf(
				'<tr><th scope="row"></th><td><p class="description wcd-hint">%1$s <a href="%2$s">%3$s</a></p></td></tr>',
				esc_html__( 'Working across several batches at once?', 'woo-custom-discount' ),
				esc_url( Admin::url( 'products' ) ),
				esc_html__( 'The Assign Products grid is quicker — every product on one screen, a column per batch.', 'woo-custom-discount' )
			);

			self::close_section();
		} else {
			// ==============================================================
			// 2. Who it applies to
			// ==============================================================
			self::open_section(
				__( 'Which products', 'woo-custom-discount' ),
				__( 'A product’s own campaign beats one on its category, which beats a store-wide one. Discounts never add up — a product on 60% does not also collect a store-wide 10%.', 'woo-custom-discount' )
			);

			$scope = (string) $value( 'scope', Rules::SCOPE_PRODUCTS );

			$scopes = array(
				Rules::SCOPE_ALL        => array(
					__( 'Every product in the store', 'woo-custom-discount' ),
					__( 'A blanket discount. Anything listed under “Never include” below is left out.', 'woo-custom-discount' ),
				),
				Rules::SCOPE_CATEGORIES => array(
					__( 'Chosen categories', 'woo-custom-discount' ),
					__( 'Products added to those categories later are picked up automatically.', 'woo-custom-discount' ),
				),
				Rules::SCOPE_PRODUCTS   => array(
					__( 'Chosen products', 'woo-custom-discount' ),
					__( 'A fixed list you pick yourself.', 'woo-custom-discount' ),
				),
			);

			$radios = '';

			foreach ( $scopes as $key => [ $label, $hint ] ) {
				$radios .= sprintf(
					'<label class="wcd-radio"><input type="radio" name="scope" value="%1$s"%2$s data-wcd-scope><span><strong>%3$s</strong><em>%4$s</em></span></label>',
					esc_attr( $key ),
					checked( $scope, $key, false ),
					esc_html( $label ),
					esc_html( $hint )
				);
			}

			self::field( __( 'Applies to', 'woo-custom-discount' ), '<div class="wcd-radios">' . $radios . '</div>' );

			self::field(
				__( 'Categories', 'woo-custom-discount' ),
				self::category_select( (array) $value( 'categories', array() ) ),
				'',
				array( 'data-wcd-show-for' => Rules::SCOPE_CATEGORIES )
			);

			self::field(
				__( 'Products', 'woo-custom-discount' ),
				self::product_select( 'products', (array) $value( 'products', array() ) ),
				__( 'Start typing a product name to find it.', 'woo-custom-discount' ),
				array( 'data-wcd-show-for' => Rules::SCOPE_PRODUCTS )
			);

			self::field(
				__( 'Never include', 'woo-custom-discount' ),
				self::product_select( 'excluded', (array) $value( 'excluded', array() ) ),
				__( 'Products this campaign must skip, even when it covers the whole store. Use it for anything that should stay at full price.', 'woo-custom-discount' )
			);

			self::close_section();

			// ==============================================================
			// 3. When it ends
			// ==============================================================
			self::open_section(
				__( 'When it ends', 'woo-custom-discount' ),
				__( 'Ending a campaign removes the discount. The products carry on selling as normal — nothing is hidden.', 'woo-custom-discount' )
			);

			$ends = (string) $value( 'ends_at', '' );

			self::field(
				__( 'Ends', 'woo-custom-discount' ),
				sprintf(
					'<input type="datetime-local" name="ends_at" value="%s">',
					esc_attr( $ends !== '' ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $ends ) ) : '' )
				),
				__( 'Leave it empty to run until you pause it. A countdown needs a date here to count towards.', 'woo-custom-discount' )
			);

			self::close_section();
		}

		// ==================================================================
		// Last: what shoppers see, and whether it is live
		// ==================================================================
		self::open_section( __( 'Display and status', 'woo-custom-discount' ) );

		self::field(
			__( 'Countdown', 'woo-custom-discount' ),
			sprintf(
				'<label class="wcd-switch"><input type="checkbox" name="countdown_enabled" value="1"%s> %s</label>',
				checked( (bool) $value( 'countdown_enabled', false ), true, false ),
				esc_html__( 'Show a countdown on these products', 'woo-custom-discount' )
			),
			$is_batch
				? __( 'Counts down to the end of the expiry month, on the shop grid and the product page.', 'woo-custom-discount' )
				: __( 'Needs an end date above, otherwise there is nothing to count towards.', 'woo-custom-discount' )
		);

		self::field(
			__( 'Status', 'woo-custom-discount' ),
			sprintf(
				'<label class="wcd-switch"><input type="checkbox" name="enabled" value="1"%s> %s</label>',
				checked( (bool) $value( 'enabled', true ), true, false ),
				esc_html__( 'Active', 'woo-custom-discount' )
			),
			__( 'Pausing puts these products back to their normal price and keeps everything you set here, so you can switch it on again later.', 'woo-custom-discount' )
		);

		self::close_section();

		echo '<p class="wcd-form-actions">';
		submit_button( __( 'Save', 'woo-custom-discount' ), 'primary', 'submit', false );
		printf(
			' <a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( Admin::url( $tab ) ),
			esc_html__( 'Cancel', 'woo-custom-discount' )
		);
		echo '</p>';

		echo '<p class="description">' . esc_html__( 'Saving applies the prices straight away. There is no second step.', 'woo-custom-discount' ) . '</p>';

		echo '</form>';
	}

	/**
	 * The short "what am I looking at" panel above the form.
	 */
	private static function render_explainer( bool $is_batch ): void {
		echo '<div class="wcd-explainer">';

		if ( $is_batch ) {
			echo '<p><strong>' . esc_html__( 'An expiry batch is for stock that is running out of shelf life.', 'woo-custom-discount' ) . '</strong></p>';
			echo '<ul>';
			echo '<li>' . esc_html__( 'One expiry month and one discount, shared by every product you put in it.', 'woo-custom-discount' ) . '</li>';
			echo '<li>' . esc_html__( 'Once the month passes, the discount stops and the products are hidden from the shop.', 'woo-custom-discount' ) . '</li>';
			echo '<li>' . esc_html__( 'A product in a batch is skipped by every campaign, including a store-wide one.', 'woo-custom-discount' ) . '</li>';
			echo '<li>' . esc_html__( 'A product can be in several batches. For now it is priced from the one expiring soonest.', 'woo-custom-discount' ) . '</li>';
			echo '<li>' . esc_html__( 'Nothing is deleted. Pausing the batch, or clearing the hiding setting, brings the products straight back.', 'woo-custom-discount' ) . '</li>';
			echo '</ul>';
		} else {
			echo '<p><strong>' . esc_html__( 'A campaign is an ordinary discount — a sale, a bundle offer, a blanket percentage.', 'woo-custom-discount' ) . '</strong></p>';
			echo '<ul>';
			echo '<li>' . esc_html__( 'It can cover the whole store, some categories, or a list of products you choose.', 'woo-custom-discount' ) . '</li>';
			echo '<li>' . esc_html__( 'When it ends the discount stops, but the products stay in the shop.', 'woo-custom-discount' ) . '</li>';
			echo '<li>' . esc_html__( 'For short-dated stock you want cleared and then hidden, use an expiry batch instead.', 'woo-custom-discount' ) . '</li>';
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Spells out the exact moment a batch stops.
	 */
	private static function expiry_note( string $expiry_ym ): string {
		if ( $expiry_ym === '' ) {
			return (string) __( 'Packaging is printed as 08/2026, so only the month is asked for.', 'woo-custom-discount' );
		}

		$end = Rules::expiry_end_timestamp( $expiry_ym );

		if ( $end === null ) {
			return '';
		}

		if ( $end <= time() ) {
			return (string) __( 'This month has already passed, so these products are treated as expired.', 'woo-custom-discount' );
		}

		return sprintf(
			/* translators: 1: date and time, 2: human-readable time remaining. */
			(string) __( 'Runs until %1$s — %2$s from now.', 'woo-custom-discount' ),
			(string) wp_date( 'j F Y, H:i', $end ),
			human_time_diff( time(), $end )
		);
	}

	/**
	 * Opens a titled group of fields.
	 */
	private static function open_section( string $title, string $description = '' ): void {
		printf(
			'<div class="wcd-section"><div class="wcd-section__head"><h3>%1$s</h3>%2$s</div><table class="form-table" role="presentation"><tbody>',
			esc_html( $title ),
			$description !== '' ? '<p>' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * Closes it.
	 */
	private static function close_section(): void {
		echo '</tbody></table></div>';
	}

	/**
	 * A form-table row.
	 *
	 * @param array<string,string> $row_attrs Extra attributes for the row, used
	 *                                        to mark fields that only apply to
	 *                                        one scope.
	 */
	private static function field( string $label, string $control, string $help = '', array $row_attrs = array() ): void {
		$attrs = '';

		foreach ( $row_attrs as $name => $val ) {
			$attrs .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $val ) );
		}

		printf(
			'<tr%1$s><th scope="row">%2$s</th><td>%3$s%4$s</td></tr>',
			$attrs,
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
		// Full width rather than a fixed 32em: these product names run long, and
		// a fixed width clipped them mid-word with no way to read the rest.
		$html = sprintf(
			'<select class="wc-product-search wcd-select" multiple="multiple" name="%1$s[]" data-placeholder="%2$s" data-action="woocommerce_json_search_products_and_variations">',
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

		$html = '<select name="categories[]" class="wcd-select-plain" multiple="multiple" size="10">';

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
			'display_label'     => isset( $_POST['display_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['display_label'] ) ) : '',
			'priority'          => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10,
			'badge'             => isset( $_POST['badge'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['badge'] ) ) : '',
			'free_extras'       => isset( $_POST['free_extras'] ) ? max( 0, (int) $_POST['free_extras'] ) : 0,
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
