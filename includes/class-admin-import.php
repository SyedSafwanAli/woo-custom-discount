<?php
/**
 * Import and price preview.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * The import button, and the screen that has to be checked before going live.
 *
 * The preview table is the most important thing in this plugin. Switching the
 * engine on rewrites prices across the catalogue, and the only responsible way
 * to do that is to show every product's current price beside its proposed one
 * first — so a mistake is caught while it is still a table on a screen, not
 * after a customer has paid the wrong amount.
 */
class Admin_Import {

	/**
	 * Hooks the actions.
	 */
	public static function init(): void {
		add_action( 'admin_post_wcd_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wcd_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_wcd_import_file', array( __CLASS__, 'handle_import_file' ) );
	}

	/**
	 * Renders the tab.
	 */
	public static function render(): void {
		self::render_transfer_box();
		self::render_import_box();
		self::render_preview();
	}

	/**
	 * Moving rules to another site — the panel that matters for going live.
	 */
	private static function render_transfer_box(): void {
		$counts = Rules::counts();
		$total  = $counts[ Rules::TYPE_CAMPAIGN ] + $counts[ Rules::TYPE_BATCH ];

		echo '<h2>' . esc_html__( 'Move these rules to another site', 'woo-custom-discount' ) . '</h2>';

		echo '<p class="wcd-intro">';
		esc_html_e( 'Set your campaigns and batches up here, then carry them to the live shop as a file. Products are matched by SKU first and by ID second, and anything that cannot be found is listed rather than skipped quietly.', 'woo-custom-discount' );
		echo '</p>';

		echo '<div class="wcd-transfer">';

		// --- Export ----------------------------------------------------------
		echo '<div class="wcd-transfer__half">';
		echo '<h3>' . esc_html__( 'Export', 'woo-custom-discount' ) . '</h3>';

		if ( $total === 0 ) {
			echo '<p class="description">' . esc_html__( 'There is nothing to export yet.', 'woo-custom-discount' ) . '</p>';
		} else {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: campaigns, 2: batches. */
						__( '%1$d campaigns and %2$d expiry batches are ready to export.', 'woo-custom-discount' ),
						$counts[ Rules::TYPE_CAMPAIGN ],
						$counts[ Rules::TYPE_BATCH ]
					)
				)
			);

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wcd_export' );
			echo '<input type="hidden" name="action" value="wcd_export">';

			printf(
				'<p><label class="wcd-check"><input type="checkbox" name="with_settings" value="1" checked> %s</label></p>',
				esc_html__( 'Include the filter settings — bands, months, layout', 'woo-custom-discount' )
			);

			submit_button( __( 'Download rules file', 'woo-custom-discount' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '</div>';

		// --- Import ----------------------------------------------------------
		echo '<div class="wcd-transfer__half">';
		echo '<h3>' . esc_html__( 'Import', 'woo-custom-discount' ) . '</h3>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wcd_import_file' );
		echo '<input type="hidden" name="action" value="wcd_import_file">';

		echo '<p><input type="file" name="wcd_file" accept=".json,application/json" required></p>';

		printf(
			'<p><label class="wcd-check"><input type="checkbox" name="with_settings" value="1" checked> %s</label>',
			esc_html__( 'Apply the filter settings from the file', 'woo-custom-discount' )
		);

		printf(
			'<label class="wcd-check"><input type="checkbox" name="enable" value="1"> %s</label></p>',
			esc_html__( 'Switch the rules on straight away', 'woo-custom-discount' )
		);

		echo '<p class="description">';
		esc_html_e( 'Leave that unticked to bring them in paused, look them over, then activate. On a live shop that is the safer order.', 'woo-custom-discount' );
		echo '</p>';

		submit_button( __( 'Import rules file', 'woo-custom-discount' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>';
		echo '</div>';

		self::render_last_file_report();
	}

	/**
	 * What the last file import actually matched.
	 */
	private static function render_last_file_report(): void {
		$report = get_transient( 'wcd_last_file_import' );

		if ( ! is_array( $report ) ) {
			return;
		}

		delete_transient( 'wcd_last_file_import' );

		echo '<h3>' . esc_html__( 'What was imported', 'woo-custom-discount' ) . '</h3>';

		printf(
			'<p><span class="wcd-pill %1$s">%2$s</span></p>',
			$report['unmatched'] > 0 ? 'is-warn' : 'is-on',
			esc_html(
				sprintf(
					/* translators: 1: matched count, 2: unmatched count. */
					__( '%1$d products and categories matched, %2$d could not be found.', 'woo-custom-discount' ),
					(int) $report['matched'],
					(int) $report['unmatched']
				)
			)
		);

		if ( $report['unmatched'] === 0 ) {
			return;
		}

		echo '<table class="widefat striped wcd-list"><thead><tr>';
		echo '<th>' . esc_html__( 'Rule', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Not found', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Why', 'woo-custom-discount' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report['rules'] as $rule ) {
			foreach ( $rule['missing'] as $miss ) {
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
					esc_html( $rule['title'] ),
					esc_html( $miss['name'] ),
					esc_html( $miss['sku'] !== '' ? $miss['sku'] : '—' ),
					esc_html( $miss['reason'] )
				);
			}
		}

		echo '</tbody></table>';

		echo '<p class="description">';
		esc_html_e( 'These were left out of their rule. Add them by hand from the Campaigns or Expiry Batches tab, or give the products a SKU on both sites and import again.', 'woo-custom-discount' );
		echo '</p>';
	}

	/**
	 * The import panel.
	 */
	private static function render_import_box(): void {
		$available = Importer::source_available();
		$last      = (int) get_option( 'wcd_last_import', 0 );

		echo '<h2>' . esc_html__( 'Bring your existing rules across', 'woo-custom-discount' ) . '</h2>';

		if ( ! $available ) {
			echo '<div class="wcd-empty"><p>';
			esc_html_e( 'No rules table from Discount Rules for WooCommerce was found, so there is nothing to import. You can still create campaigns and batches by hand.', 'woo-custom-discount' );
			echo '</p></div>';

			return;
		}

		$plan = Importer::dry_run();

		$campaigns = array_filter( $plan['campaigns'], static fn( array $c ): bool => ! $c['skipped'] );
		$skipped   = count( $plan['campaigns'] ) - count( $campaigns );

		echo '<p class="wcd-intro">';
		esc_html_e( 'Your current discount rules are read once and copied into this plugin, so nothing has to be re-entered. Your expiry categories become real expiry batches with real dates.', 'woo-custom-discount' );
		echo '</p>';

		echo '<table class="widefat striped wcd-list"><thead><tr>';
		echo '<th>' . esc_html__( 'Will be created', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Discount', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'woo-custom-discount' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $plan['batches'] as $batch ) {
			printf(
				'<tr><td><strong>%1$s</strong><br><span class="description">%2$s</span></td><td class="wcd-num">%3$s%%</td><td class="wcd-num">%4$d</td><td>%5$s</td></tr>',
				esc_html( $batch['title'] ),
				esc_html__( 'Expiry batch', 'woo-custom-discount' ),
				esc_html( Admin_Rules::percent_label( $batch['percent'] ) ),
				count( $batch['products'] ),
				$batch['mixed_percents']
					? esc_html__( 'These products had different discounts — the highest was used. Worth checking.', 'woo-custom-discount' )
					: esc_html__( 'From your expiry category', 'woo-custom-discount' )
			);
		}

		foreach ( $plan['campaigns'] as $campaign ) {
			if ( $campaign['skipped'] ) {
				continue;
			}

			printf(
				'<tr><td><strong>%1$s</strong><br><span class="description">%2$s</span></td><td class="wcd-num">%3$s%%</td><td class="wcd-num">%4$s</td><td>%5$s</td></tr>',
				esc_html( $campaign['title'] ),
				esc_html__( 'Campaign', 'woo-custom-discount' ),
				esc_html( Admin_Rules::percent_label( $campaign['percent'] ) ),
				$campaign['scope'] === Rules::SCOPE_ALL
					? esc_html__( 'all', 'woo-custom-discount' )
					: (string) count( $campaign['products'] ),
				$campaign['moved'] > 0
					? esc_html(
						sprintf(
							/* translators: %d: number of products. */
							_n( '%d product moved into an expiry batch', '%d products moved into an expiry batch', $campaign['moved'], 'woo-custom-discount' ),
							$campaign['moved']
						)
					)
					: ( $campaign['excluded'] !== array()
						? esc_html(
							sprintf(
								/* translators: %d: number of products. */
								_n( '%d product kept out', '%d products kept out', count( $campaign['excluded'] ), 'woo-custom-discount' ),
								count( $campaign['excluded'] )
							)
						)
						: '' )
			);
		}

		echo '</tbody></table>';

		if ( $skipped > 0 ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of rules. */
						_n(
							'%d rule will not be created, because every product in it has moved into an expiry batch.',
							'%d rules will not be created, because every product in them has moved into an expiry batch.',
							$skipped,
							'woo-custom-discount'
						),
						$skipped
					)
				)
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcd-import-form">';
		wp_nonce_field( 'wcd_import' );
		echo '<input type="hidden" name="action" value="wcd_import">';

		echo '<p><label><input type="checkbox" name="enable" value="1" checked> ';
		esc_html_e( 'Create them active (they still change nothing until the price engine is switched on)', 'woo-custom-discount' );
		echo '</label></p>';

		submit_button(
			$last > 0
				? __( 'Import again, replacing the last import', 'woo-custom-discount' )
				: __( 'Import my rules', 'woo-custom-discount' ),
			'primary',
			'submit',
			false
		);

		if ( $last > 0 ) {
			printf(
				' <span class="description">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable time difference. */
						__( 'Last imported %s ago. Rules you made by hand are never touched.', 'woo-custom-discount' ),
						human_time_diff( $last )
					)
				)
			);
		}

		echo '</form>';
	}

	/**
	 * The price comparison table.
	 */
	private static function render_preview(): void {
		$rows = self::preview_rows();

		echo '<h2>' . esc_html__( 'Price preview', 'woo-custom-discount' ) . '</h2>';

		if ( $rows === array() ) {
			echo '<div class="wcd-empty"><p>';
			esc_html_e( 'Import your rules, or create a campaign, and every product will be listed here with the price it would get.', 'woo-custom-discount' );
			echo '</p></div>';

			return;
		}

		$changed  = 0;
		$rounding = 0;

		foreach ( $rows as $row ) {
			if ( $row['verdict'] === 'changed' ) {
				++$changed;
			} elseif ( $row['verdict'] === 'rounding' ) {
				++$rounding;
			}
		}

		echo '<p class="wcd-intro">';
		esc_html_e( 'What each product sells for today, and what it would sell for once the engine is switched on. Today\'s figure is worked out from your old plugin\'s own rules, using the same order it resolves them in.', 'woo-custom-discount' );
		echo '</p>';

		echo '<div class="wcd-summary">';

		printf(
			'<span class="wcd-pill %1$s">%2$s</span>',
			$changed === 0 ? 'is-on' : 'is-warn',
			esc_html(
				$changed === 0
					? __( 'No unexpected price changes', 'woo-custom-discount' )
					: sprintf(
						/* translators: %d: number of products. */
						_n( '%d price would change', '%d prices would change', $changed, 'woo-custom-discount' ),
						$changed
					)
			)
		);

		if ( $rounding > 0 ) {
			printf(
				' <span class="wcd-pill is-info">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: number of products. */
						_n( '%d rounded down by up to 1', '%d rounded down by up to 1', $rounding, 'woo-custom-discount' ),
						$rounding
					)
				)
			);
		}

		echo '</div>';

		echo '<table class="widefat striped wcd-list wcd-preview"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'woo-custom-discount' ) . '</th>';
		echo '<th class="wcd-num">' . esc_html__( 'Regular', 'woo-custom-discount' ) . '</th>';
		echo '<th class="wcd-num">' . esc_html__( 'Today', 'woo-custom-discount' ) . '</th>';
		echo '<th class="wcd-num">' . esc_html__( 'New', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Rule', 'woo-custom-discount' ) . '</th>';
		echo '<th>' . esc_html__( 'Difference', 'woo-custom-discount' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$class = match ( $row['verdict'] ) {
				'changed'  => 'wcd-row-warn',
				'rounding' => 'wcd-row-info',
				default    => '',
			};

			printf(
				'<tr class="%1$s"><td>%2$s</td><td class="wcd-num">%3$s</td><td class="wcd-num">%4$s</td><td class="wcd-num">%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_attr( $class ),
				esc_html( $row['name'] ),
				esc_html( $row['regular'] ),
				esc_html( $row['today'] ),
				esc_html( $row['new'] ),
				esc_html( $row['rule'] ),
				esc_html( $row['difference'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Builds the comparison rows.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function preview_rows(): array {
		$counts = Rules::counts();

		if ( $counts[ Rules::TYPE_CAMPAIGN ] === 0 && $counts[ Rules::TYPE_BATCH ] === 0 ) {
			return array();
		}

		$old  = Importer::source_available() ? Importer::source_percent_map() : array();
		$rows = array();

		foreach ( Price_Engine::target_product_ids() as $product_id ) {
			$plan = Price_Engine::plan_product( $product_id );

			if ( $plan['status'] === 'missing' ) {
				continue;
			}

			$old_percent = $old[ $product_id ]['percent'] ?? 0.0;
			$today       = $old_percent > 0
				? Price_Engine::discounted_price( $plan['regular'], $old_percent )
				: $plan['regular'];

			$new = $plan['status'] === 'discount' ? $plan['new_price'] : $plan['regular'];

			$verdict    = 'same';
			$difference = '—';

			if ( $plan['status'] === 'skipped_type' ) {
				$verdict    = 'skipped';
				$difference = __( 'Variable product — left alone', 'woo-custom-discount' );
			} elseif ( abs( $old_percent - $plan['percent'] ) > 0.001 ) {
				$verdict    = 'changed';
				$difference = sprintf(
					/* translators: 1: old percentage, 2: new percentage. */
					__( 'Discount changes from %1$s%% to %2$s%%', 'woo-custom-discount' ),
					Admin_Rules::percent_label( $old_percent ),
					Admin_Rules::percent_label( $plan['percent'] )
				);
			} elseif ( abs( $today - $new ) > 0.001 ) {
				// Same percentage, different figure: this is rounding, and it
				// always lands in the customer's favour.
				$verdict    = 'rounding';
				$difference = sprintf(
					/* translators: %s: amount. */
					__( '%s cheaper (rounded down)', 'woo-custom-discount' ),
					self::money( $today - $new )
				);
			}

			$rows[] = array(
				'name'       => $plan['name'],
				'regular'    => self::money( $plan['regular'] ),
				'today'      => self::money( $today ),
				'new'        => $plan['status'] === 'discount' ? self::money( $new ) : '—',
				'rule'       => $plan['status'] === 'discount' ? $plan['rule_title'] : self::status_label( $plan['status'] ),
				'difference' => $difference,
				'verdict'    => $verdict,
			);
		}

		// Anything worth a second look floats to the top.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$order = array(
					'changed'  => 0,
					'rounding' => 1,
					'skipped'  => 2,
					'same'     => 3,
				);

				return ( $order[ $a['verdict'] ] ?? 9 ) <=> ( $order[ $b['verdict'] ] ?? 9 );
			}
		);

		return $rows;
	}

	/**
	 * Readable label for a non-discount outcome.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			'no_discount'      => __( 'No discount', 'woo-custom-discount' ),
			'skipped_type'     => __( 'Variable product', 'woo-custom-discount' ),
			'no_regular_price' => __( 'No regular price set', 'woo-custom-discount' ),
			default            => $status,
		};
	}

	/**
	 * Plain formatted amount.
	 */
	private static function money( float $amount ): string {
		return number_format( $amount, (int) ( function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0 ) );
	}

	/**
	 * Sends the rules file to the browser.
	 */
	public static function handle_export(): void {
		check_admin_referer( 'wcd_export' );
		self::guard();

		$payload = Exporter::build( ! empty( $_POST['with_settings'] ) );

		$filename = sprintf(
			'woo-custom-discount-%s.json',
			gmdate( 'Y-m-d-His' )
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		exit;
	}

	/**
	 * Reads an uploaded rules file.
	 */
	public static function handle_import_file(): void {
		check_admin_referer( 'wcd_import_file' );
		self::guard();

		$file = $_FILES['wcd_file'] ?? null;

		if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			Admin::redirect_with_message( 'import', __( 'No file was uploaded, or it did not arrive in one piece.', 'woo-custom-discount' ) );
		}

		// Read the upload where PHP put it rather than storing it in the media
		// library — a rules file has no business becoming a public attachment.
		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( $raw === false || $raw === '' ) {
			Admin::redirect_with_message( 'import', __( 'That file could not be read.', 'woo-custom-discount' ) );
		}

		$payload = json_decode( $raw, true );

		if ( ! is_array( $payload ) ) {
			Admin::redirect_with_message( 'import', __( 'That does not look like a rules file — it is not readable JSON.', 'woo-custom-discount' ) );
		}

		$report = Exporter::inspect( $payload );

		if ( empty( $report['ok'] ) ) {
			Admin::redirect_with_message( 'import', (string) $report['error'] );
		}

		$with_settings = ! empty( $_POST['with_settings'] );

		if ( $with_settings && isset( $payload['settings'] ) ) {
			$report['settings'] = $payload['settings'];
		}

		$created = Exporter::apply( $report, ! empty( $_POST['enable'] ), $with_settings );

		// Kept for one page load so the report can be shown after the redirect.
		set_transient( 'wcd_last_file_import', $report, 5 * MINUTE_IN_SECONDS );

		if ( Plugin::engine_can_run() ) {
			Price_Engine::apply_all();
		}

		Admin::redirect_with_message(
			'import',
			sprintf(
				/* translators: 1: campaigns, 2: batches, 3: unmatched count. */
				__( 'Imported %1$d campaigns and %2$d expiry batches. %3$d products could not be matched.', 'woo-custom-discount' ),
				(int) $created[ Rules::TYPE_CAMPAIGN ],
				(int) $created[ Rules::TYPE_BATCH ],
				(int) $report['unmatched']
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

	/**
	 * Runs the import.
	 */
	public static function handle_import(): void {
		check_admin_referer( 'wcd_import' );

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-custom-discount' ) );
		}

		$result = Importer::run( ! empty( $_POST['enable'] ) );

		if ( empty( $result['ok'] ) ) {
			Admin::redirect_with_message( 'import', (string) ( $result['message'] ?? __( 'Import failed.', 'woo-custom-discount' ) ) );
		}

		Resolver::flush();
		Expiry::flush_cache();

		if ( Plugin::engine_can_run() ) {
			Price_Engine::apply_all();
		}

		Admin::redirect_with_message(
			'import',
			sprintf(
				/* translators: 1: campaigns created, 2: batches created. */
				__( 'Imported %1$d campaigns and %2$d expiry batches. Check the price preview below before switching the engine on.', 'woo-custom-discount' ),
				(int) $result['campaigns'],
				(int) $result['batches']
			)
		);
	}
}
