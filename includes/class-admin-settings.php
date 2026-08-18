<?php
/**
 * Settings screen — the master switches.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * The four switches that decide what this plugin does to the store, plus the
 * housekeeping actions.
 *
 * They are separate switches on purpose. Going live is meant to be done one step
 * at a time: prices first, checked, then the filter, then countdowns. If any
 * step misbehaves, only that switch goes back off.
 */
class Admin_Settings {

	/**
	 * Hooks the form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_post_wcd_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wcd_run_engine', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_wcd_clear_prices', array( __CLASS__, 'handle_clear' ) );
	}

	/**
	 * Renders the tab.
	 */
	public static function render(): void {
		$conflicts = Plugin::active_conflicts();
		$owned     = count( Price_Engine::owned_product_ids() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-form">';
		wp_nonce_field( 'wcd_save_settings' );
		echo '<input type="hidden" name="action" value="wcd_save_settings">';

		echo '<h2>' . esc_html__( 'Switches', 'woo-custom-discount' ) . '</h2>';
		echo '<p class="wcd-intro">';
		esc_html_e( 'Turn these on one at a time, checking the shop after each. That way, if anything looks wrong, you know exactly which switch to put back.', 'woo-custom-discount' );
		echo '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		self::toggle(
			'engine_enabled',
			__( 'Price engine', 'woo-custom-discount' ),
			__( 'Write discounted prices into the products', 'woo-custom-discount' ),
			$conflicts === array()
				? __( 'Check the price preview on the Import tab before turning this on.', 'woo-custom-discount' )
				: sprintf(
					/* translators: %s: plugin file. */
					__( 'Blocked: %s is still active. Two discount plugins would stack, so the engine will not run until it is deactivated.', 'woo-custom-discount' ),
					implode( ', ', $conflicts )
				)
		);

		self::toggle(
			'filters_enabled',
			__( 'Shop filters', 'woo-custom-discount' ),
			__( 'Let customers filter the shop', 'woo-custom-discount' ),
			__( 'Set the bands up on the Filters tab first, or nothing will show.', 'woo-custom-discount' )
		);

		self::toggle(
			'countdown_enabled',
			__( 'Countdowns', 'woo-custom-discount' ),
			__( 'Show countdowns on products', 'woo-custom-discount' ),
			__( 'Each campaign and batch also has its own countdown switch.', 'woo-custom-discount' )
		);

		echo '</tbody></table>';

		// --- Countdown appearance --------------------------------------------
		echo '<h2>' . esc_html__( 'How the countdown looks', 'woo-custom-discount' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$position  = (string) Settings::get( 'countdown_in_loop', 'overlay' );
		$positions = array(
			'overlay' => __( 'Across the bottom of the product image', 'woo-custom-discount' ),
			'below'   => __( 'Under the price, as a separate block', 'woo-custom-discount' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'On the shop grid', 'woo-custom-discount' ) . '</th><td>';

		foreach ( $positions as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="radio" name="countdown_in_loop" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $position, $key, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">';
		esc_html_e( 'Over the image, the countdown costs the card no height. Below the price it is bigger, but pushes the price and the button down. The product page always uses the bigger form.', 'woo-custom-discount' );
		echo '</p>';
		echo '</td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label class="wcd-check"><input type="checkbox" name="show_savings" value="1"%2$s> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Saving', 'woo-custom-discount' ),
			checked( Settings::is_on( 'show_savings' ), true, false ),
			esc_html__( 'Show a “You save Rs 2,697” chip under the price', 'woo-custom-discount' ),
			esc_html__( 'Left off because this shop already writes the saving into its product images. Turn it on once those are plain photographs.', 'woo-custom-discount' )
		);

		echo '</tbody></table>';

		// Without this note nobody would find them, and on a Theme Builder
		// product page they are the only way anything appears at all.
		echo '<div class="wcd-explainer">';
		echo '<p><strong>' . esc_html__( 'Building the product page in Divi?', 'woo-custom-discount' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'A Theme Builder product page is assembled from Divi\'s own Woo modules, so WooCommerce\'s usual hooks never run and nothing here can place itself. Drop these into a Code module wherever you want them:', 'woo-custom-discount' ) . '</p>';
		echo '<ul>';
		printf( '<li><code>[wcd_countdown]</code> — %s</li>', esc_html__( 'the countdown for this product', 'woo-custom-discount' ) );
		printf( '<li><code>[wcd_expiry]</code> — %s</li>', esc_html__( 'the expiry dates this product is stocked in, and the discount on each', 'woo-custom-discount' ) );
		printf( '<li><code>[wcd_savings]</code> — %s</li>', esc_html__( 'how much the shopper saves', 'woo-custom-discount' ) );
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'On a theme that uses WooCommerce\'s standard product template these appear on their own, and the shortcodes are not needed.', 'woo-custom-discount' ) . '</p>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'More switches', 'woo-custom-discount' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::toggle(
			'hide_expired',
			__( 'Hide expired stock', 'woo-custom-discount' ),
			__( 'Keep expired products out of the shop', 'woo-custom-discount' ),
			__( 'Only products in a batch whose month has passed. A product with no expiry date is never hidden. Nothing is deleted, so switching this off brings them straight back.', 'woo-custom-discount' )
		);

		echo '</tbody></table>';

		// --- Rounding --------------------------------------------------------
		echo '<h2>' . esc_html__( 'Rounding', 'woo-custom-discount' ) . '</h2>';

		$rounding = (string) Settings::get( 'rounding', 'down' );

		$modes = array(
			'down' => __( 'Down — the customer pays less', 'woo-custom-discount' ),
			'near' => __( 'To the nearest', 'woo-custom-discount' ),
			'up'   => __( 'Up', 'woo-custom-discount' ),
		);

		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
		esc_html_e( 'Round discounted prices', 'woo-custom-discount' );
		echo '</th><td>';

		foreach ( $modes as $key => $label ) {
			printf(
				'<label class="wcd-check"><input type="radio" name="rounding" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $rounding, $key, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">';
		esc_html_e( '15% off 6,995 comes to 5,945.75. Rounding down makes that 5,945.', 'woo-custom-discount' );
		echo '</p>';
		echo '</td></tr></tbody></table>';

		// --- Uninstall -------------------------------------------------------
		echo '<h2>' . esc_html__( 'When the plugin is deleted', 'woo-custom-discount' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
		esc_html_e( 'Your rules', 'woo-custom-discount' );
		echo '</th><td>';

		printf(
			'<label class="wcd-check"><input type="checkbox" name="purge_on_uninstall" value="1"%s> %s</label>',
			checked( Settings::is_on( 'purge_on_uninstall' ), true, false ),
			esc_html__( 'Delete all campaigns, batches and settings when the plugin is deleted', 'woo-custom-discount' )
		);

		echo '<p class="description">';
		esc_html_e( 'Off by default. A mis-click on Delete should not throw away the product lists you built. Sale prices are cleared on deactivation either way.', 'woo-custom-discount' );
		echo '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Save settings', 'woo-custom-discount' ) );
		echo '</form>';

		// --- Actions ---------------------------------------------------------
		echo '<hr>';
		echo '<h2>' . esc_html__( 'Actions', 'woo-custom-discount' ) . '</h2>';

		echo '<p>';
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a> ',
			esc_url( self::action_url( 'wcd_run_engine' ) ),
			esc_html__( 'Re-apply all prices now', 'woo-custom-discount' )
		);

		printf(
			'<a class="button button-secondary" href="%1$s" onclick="return confirm(%2$s)">%3$s</a>',
			esc_url( self::action_url( 'wcd_clear_prices' ) ),
			esc_js( wp_json_encode( __( 'Remove every sale price this plugin set? Your rules are kept, and you can re-apply at any time.', 'woo-custom-discount' ) ) ),
			esc_html__( 'Remove all discounts', 'woo-custom-discount' )
		);
		echo '</p>';

		echo '<p class="description">';
		printf(
			esc_html(
				/* translators: %d: number of products. */
				_n(
					'This plugin currently owns the sale price on %d product. Prices set by hand are never touched.',
					'This plugin currently owns the sale price on %d products. Prices set by hand are never touched.',
					$owned,
					'woo-custom-discount'
				)
			),
			(int) $owned
		);
		echo '</p>';
	}

	/**
	 * One switch row.
	 */
	private static function toggle( string $key, string $label, string $checkbox_label, string $help ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label class="wcd-check"><input type="checkbox" name="%2$s" value="1"%3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( Settings::is_on( $key ), true, false ),
			esc_html( $checkbox_label ),
			esc_html( $help )
		);
	}

	/**
	 * A nonce-protected action URL.
	 */
	private static function action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg( array( 'action' => $action ), admin_url( 'admin-post.php' ) ),
			$action
		);
	}

	/**
	 * Saves the settings, then brings prices in line with them.
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wcd_save_settings' );
		self::guard();

		$was_on = Settings::is_on( 'engine_enabled' );
		$now_on = ! empty( $_POST['engine_enabled'] );

		$rounding = isset( $_POST['rounding'] ) ? sanitize_key( wp_unslash( (string) $_POST['rounding'] ) ) : 'down';
		$in_loop  = isset( $_POST['countdown_in_loop'] ) ? sanitize_key( wp_unslash( (string) $_POST['countdown_in_loop'] ) ) : 'overlay';

		Settings::update(
			array(
				'engine_enabled'     => $now_on,
				'filters_enabled'    => ! empty( $_POST['filters_enabled'] ),
				'countdown_enabled'  => ! empty( $_POST['countdown_enabled'] ),
				'hide_expired'       => ! empty( $_POST['hide_expired'] ),
				'rounding'           => in_array( $rounding, array( 'down', 'near', 'up' ), true ) ? $rounding : 'down',
				'countdown_in_loop'  => in_array( $in_loop, array( 'overlay', 'below' ), true ) ? $in_loop : 'overlay',
				'show_savings'       => ! empty( $_POST['show_savings'] ),
				'purge_on_uninstall' => ! empty( $_POST['purge_on_uninstall'] ),
			)
		);

		Expiry::flush_cache();
		Resolver::flush();

		$message = __( 'Settings saved.', 'woo-custom-discount' );

		if ( $now_on && Plugin::engine_can_run() ) {
			$stats = Price_Engine::apply_all();

			$message = sprintf(
				/* translators: 1: number priced, 2: number cleared. */
				__( 'Settings saved. %1$d products priced, %2$d put back to normal.', 'woo-custom-discount' ),
				(int) $stats['discount'],
				(int) $stats['cleared']
			);
		} elseif ( $was_on && ! $now_on ) {
			// Switching the engine off must undo its work immediately, not leave
			// discounted prices sitting in the database.
			$cleared = Price_Engine::clear_all();

			$message = sprintf(
				/* translators: %d: number of products. */
				__( 'Engine switched off. %d products are back to their original prices.', 'woo-custom-discount' ),
				(int) $cleared
			);
		}

		Admin::redirect_with_message( 'settings', $message );
	}

	/**
	 * Re-applies prices on demand.
	 */
	public static function handle_run(): void {
		check_admin_referer( 'wcd_run_engine' );
		self::guard();

		if ( ! Plugin::engine_can_run() ) {
			Admin::redirect_with_message(
				'settings',
				__( 'The engine is switched off, or a conflicting discount plugin is active. Nothing was changed.', 'woo-custom-discount' )
			);
		}

		Resolver::flush();
		$stats = Price_Engine::apply_all();

		Admin::redirect_with_message(
			'settings',
			sprintf(
				/* translators: 1: priced, 2: unchanged, 3: cleared. */
				__( 'Done. %1$d priced, %2$d already correct, %3$d put back to normal.', 'woo-custom-discount' ),
				(int) $stats['discount'],
				(int) $stats['unchanged'],
				(int) $stats['cleared']
			)
		);
	}

	/**
	 * Removes every price this plugin owns.
	 */
	public static function handle_clear(): void {
		check_admin_referer( 'wcd_clear_prices' );
		self::guard();

		$cleared = Price_Engine::clear_all();

		Admin::redirect_with_message(
			'settings',
			sprintf(
				/* translators: %d: number of products. */
				__( '%d products are back to their original prices. Your rules are untouched.', 'woo-custom-discount' ),
				(int) $cleared
			)
		);
	}

	/**
	 * Stops anyone without the capability.
	 */
	private static function guard(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}
	}
}
