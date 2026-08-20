<?php
/**
 * Admin menu, tab routing and the status screen.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * One page with tabs, rather than six menu entries.
 */
class Admin {

	public const CAPABILITY = 'manage_woocommerce';
	public const SLUG       = 'wcd';

	/**
	 * Hooks the admin pieces.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . WCD_BASENAME, array( __CLASS__, 'action_links' ) );

		Admin_Rules::init();
		Admin_Products::init();
		Admin_Import::init();
		Admin_Filters::init();
		Admin_Settings::init();
		Admin_Product::init();
	}

	/**
	 * The tabs, in order.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			''          => __( 'Status', 'woo-custom-discount' ),
			'campaigns' => __( 'Campaigns', 'woo-custom-discount' ),
			'batches'   => __( 'Expiry Batches', 'woo-custom-discount' ),
			'products'  => __( 'Assign Products', 'woo-custom-discount' ),
			'filters'   => __( 'Filters', 'woo-custom-discount' ),
			'import'    => __( 'Import & Preview', 'woo-custom-discount' ),
			'settings'  => __( 'Settings', 'woo-custom-discount' ),
		);
	}

	/**
	 * Adds the menu under WooCommerce.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Custom Discount', 'woo-custom-discount' ),
			__( 'Custom Discount', 'woo-custom-discount' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * URL of one tab.
	 *
	 * @param array<string,string|int> $extra Extra query arguments.
	 */
	public static function url( string $tab = '', array $extra = array() ): string {
		$args = array( 'page' => self::SLUG );

		if ( $tab !== '' ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The tab being viewed.
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';

		return array_key_exists( $tab, self::tabs() ) ? $tab : '';
	}

	/**
	 * Loads WooCommerce's product search control on the rule editor.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( string $hook ): void {
		if ( strpos( $hook, self::SLUG ) === false ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );

		// The media library, for choosing a picture per batch. WordPress's own
		// frame rather than a file field: these pictures are already uploaded.
		wp_enqueue_media();
		wp_enqueue_style( 'wcd-admin', WCD_URL . 'assets/admin.css', array(), WCD_VERSION );
		wp_enqueue_script( 'wcd-admin', WCD_URL . 'assets/admin.js', array(), WCD_VERSION, true );
	}

	/**
	 * Adds a link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'woo-custom-discount' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Renders whichever tab is active.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-custom-discount' ) );
		}

		$tab = self::current_tab();

		echo '<div class="wrap wcd-admin">';
		echo '<h1>' . esc_html__( 'Woo Custom Discount', 'woo-custom-discount' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper wcd-tabs">';

		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';

		switch ( $tab ) {
			case 'campaigns':
				Admin_Rules::render( Rules::TYPE_CAMPAIGN );
				break;

			case 'batches':
				Admin_Rules::render( Rules::TYPE_BATCH );
				break;

			case 'products':
				Admin_Products::render();
				break;

			case 'filters':
				Admin_Filters::render();
				break;

			case 'import':
				Admin_Import::render();
				break;

			case 'settings':
				Admin_Settings::render();
				break;

			default:
				self::render_status();
		}

		echo '</div>';
	}

	/**
	 * Admin notices: the conflict guard, and one-off action feedback.
	 */
	public static function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$conflicts = Plugin::active_conflicts();

		if ( $conflicts !== array() && Settings::is_on( 'engine_enabled' ) ) {
			echo '<div class="notice notice-error"><p><strong>';
			echo esc_html__( 'Woo Custom Discount: the price engine is switched on but parked.', 'woo-custom-discount' );
			echo '</strong></p><p>';
			printf(
				/* translators: %s: list of conflicting plugin files. */
				esc_html__( 'Another discount plugin is still active (%s). Running both would apply a second discount on top of ours, so no prices have been changed. Deactivate the other plugin to let the engine run.', 'woo-custom-discount' ),
				'<code>' . esc_html( implode( '</code>, <code>', $conflicts ) ) . '</code>'
			);
			echo '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = isset( $_GET['wcd_message'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wcd_message'] ) ) : '';

		if ( $message !== '' ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	}

	/**
	 * Redirects back to a tab with a message.
	 */
	public static function redirect_with_message( string $tab, string $message ): void {
		wp_safe_redirect( self::url( $tab, array( 'wcd_message' => $message ) ) );
		exit;
	}

	/**
	 * The status screen: what is on, what is off, and what has been touched.
	 */
	private static function render_status(): void {
		$counts    = Rules::counts();
		$conflicts = Plugin::active_conflicts();
		$owned     = count( Price_Engine::owned_product_ids() );

		$switches = array(
			'engine_enabled'    => __( 'Price engine', 'woo-custom-discount' ),
			'filters_enabled'   => __( 'Shop filters', 'woo-custom-discount' ),
			'countdown_enabled' => __( 'Countdowns', 'woo-custom-discount' ),
			'hide_expired'      => __( 'Hide expired products', 'woo-custom-discount' ),
			'ajax_add_to_cart'  => __( 'No-reload Add to Cart', 'woo-custom-discount' ),
		);

		echo '<p class="description">';
		printf(
			/* translators: %s: plugin version. */
			esc_html__( 'Version %s. Nothing is applied to the store until a switch is turned on under Settings.', 'woo-custom-discount' ),
			esc_html( WCD_VERSION )
		);
		echo '</p>';

		echo '<div class="wcd-cards">';

		// --- Features --------------------------------------------------------
		echo '<div class="wcd-card"><h2>' . esc_html__( 'Features', 'woo-custom-discount' ) . '</h2><table class="widefat striped"><tbody>';

		foreach ( $switches as $key => $label ) {
			$on = Settings::is_on( $key );

			printf(
				'<tr><td>%1$s</td><td class="wcd-state"><span class="wcd-pill %2$s">%3$s</span></td></tr>',
				esc_html( $label ),
				$on ? 'is-on' : 'is-off',
				$on ? esc_html__( 'On', 'woo-custom-discount' ) : esc_html__( 'Off', 'woo-custom-discount' )
			);
		}

		echo '</tbody></table></div>';

		// --- Health ----------------------------------------------------------
		echo '<div class="wcd-card"><h2>' . esc_html__( 'Health', 'woo-custom-discount' ) . '</h2><table class="widefat striped"><tbody>';

		self::row(
			__( 'Database tables', 'woo-custom-discount' ),
			Install::tables_exist() ? __( 'Created', 'woo-custom-discount' ) : __( 'Missing — reactivate the plugin', 'woo-custom-discount' ),
			Install::tables_exist()
		);

		self::row(
			__( 'Conflicting discount plugin', 'woo-custom-discount' ),
			$conflicts === array() ? __( 'None active', 'woo-custom-discount' ) : implode( ', ', $conflicts ),
			$conflicts === array()
		);

		self::row(
			__( 'Engine allowed to run', 'woo-custom-discount' ),
			Plugin::engine_can_run() ? __( 'Yes', 'woo-custom-discount' ) : __( 'No — switched off, or a conflicting plugin is active', 'woo-custom-discount' ),
			Plugin::engine_can_run()
		);

		self::row( __( 'Campaigns', 'woo-custom-discount' ), (string) $counts[ Rules::TYPE_CAMPAIGN ], null );
		self::row( __( 'Expiry batches', 'woo-custom-discount' ), (string) $counts[ Rules::TYPE_BATCH ], null );
		self::row( __( 'Sale prices we own', 'woo-custom-discount' ), (string) $owned, null );
		self::row( __( 'Site timezone', 'woo-custom-discount' ), self::timezone_label(), null );

		// There are several ways for updates to go quiet — no release published,
		// a repository gone private without a token, a token that has expired,
		// no network. Silence looks identical in every case, so the reason is put
		// on the screen rather than left to be guessed at.
		$updates = Updater::status();

		if ( $updates['error'] !== '' ) {
			self::row( __( 'Updates', 'woo-custom-discount' ), $updates['error'], false );
		} elseif ( version_compare( $updates['version'], WCD_VERSION, '>' ) ) {
			// Saying a version is waiting and leaving the reader to go and find
			// it is half a message. The row takes them there.
			printf(
				'<tr><td>%1$s</td><td class="wcd-good">%2$s <a href="%3$s">%4$s</a></td></tr>',
				esc_html__( 'Updates', 'woo-custom-discount' ),
				esc_html(
					sprintf(
						/* translators: %s: version number. */
						__( '%s is ready to install.', 'woo-custom-discount' ),
						$updates['version']
					)
				),
				esc_url( self_admin_url( 'plugins.php?plugin_status=upgrade' ) ),
				esc_html__( 'Go to Plugins', 'woo-custom-discount' )
			);
		} elseif ( $updates['version'] !== '' ) {
			// With a link to ask again. Otherwise "up to date" is a claim the
			// reader has no way to test, and the answer behind it may be minutes
			// or hours old.
			printf(
				'<tr><td>%1$s</td><td class="wcd-good">%2$s <a href="%3$s">%4$s</a></td></tr>',
				esc_html__( 'Updates', 'woo-custom-discount' ),
				esc_html(
					sprintf(
						/* translators: %s: version number. */
						__( 'Up to date (latest release is %s).', 'woo-custom-discount' ),
						$updates['version']
					)
				),
				esc_url( self::url( '', array( 'wcd-check' => 1 ) ) ),
				esc_html__( 'Check now', 'woo-custom-discount' )
			);
		}

		echo '</tbody></table></div>';
		echo '</div>';

		// --- What is live ----------------------------------------------------
		$expired = Expiry::expired_product_ids();

		if ( $expired !== array() ) {
			echo '<h2>' . esc_html__( 'Expired stock', 'woo-custom-discount' ) . '</h2>';
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of products. */
						_n(
							'%d product sits in a batch whose expiry month has passed.',
							'%d products sit in a batch whose expiry month has passed.',
							count( $expired ),
							'woo-custom-discount'
						),
						count( $expired )
					)
				)
			);

			if ( ! Settings::is_on( 'hide_expired' ) ) {
				echo '<p class="description">' . esc_html__( 'Hiding is switched off, so they are still on sale in the shop.', 'woo-custom-discount' ) . '</p>';
			}
		}
	}

	/**
	 * One row of the health table.
	 *
	 * @param string    $label Row label.
	 * @param string    $value Row value.
	 * @param bool|null $good  True/false to colour it, null to leave plain.
	 */
	private static function row( string $label, string $value, ?bool $good ): void {
		$class = '';

		if ( $good === true ) {
			$class = 'wcd-good';
		} elseif ( $good === false ) {
			$class = 'wcd-bad';
		}

		printf(
			'<tr><td>%1$s</td><td class="%2$s">%3$s</td></tr>',
			esc_html( $label ),
			esc_attr( $class ),
			esc_html( $value )
		);
	}

	/**
	 * A readable timezone, with a nudge when the store is still on UTC.
	 */
	private static function timezone_label(): string {
		$tz    = wp_timezone_string();
		$label = sprintf( '%s — %s', $tz, (string) wp_date( 'd M Y, H:i' ) );

		if ( in_array( $tz, array( 'UTC', '+00:00' ), true ) ) {
			$label .= ' ' . __( '(store sells in PKR; Pakistan is UTC+5, so sale end times will be 5 hours late)', 'woo-custom-discount' );
		}

		return $label;
	}
}
