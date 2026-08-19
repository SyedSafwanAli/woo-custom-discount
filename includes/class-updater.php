<?php
/**
 * Update notices for a plugin that does not live on wordpress.org.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Puts this plugin into WordPress's own update flow, from a private repository.
 *
 * WordPress checks wordpress.org for every plugin it does not otherwise know
 * about, finds nothing there, and stays quiet. These filters answer for this one
 * plugin instead: here is the newest release, here is where to get it, here is
 * what changed. From then on it updates like any other plugin — a notice on the
 * Plugins screen, one click, done.
 *
 * The repository is private, so every request carries a token. The token is read
 * from a constant in wp-config.php and never written to the database, so it does
 * not travel in a database export and cannot be read by someone who only reaches
 * the admin screens. With no constant defined this class does nothing at all:
 * the plugin behaves exactly as it did, it simply never offers an update.
 */
class Updater {

	private const OWNER = 'SyedSafwanAli';
	private const REPO  = 'woo-custom-discount';

	/** Where the last answer from GitHub is kept. */
	private const CACHE = 'wcd_latest_release';

	/**
	 * Twelve hours: long enough that opening the admin does not talk to GitHub
	 * every time, short enough that a release is noticed the same day.
	 */
	private const CACHE_LIFE = 12 * HOUR_IN_SECONDS;

	/**
	 * Hooks the update flow, but only where it can work.
	 */
	public static function init(): void {
		if ( self::token() === '' ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'offer_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	/**
	 * The token, from wp-config.php.
	 */
	private static function token(): string {
		return defined( 'WCD_GITHUB_TOKEN' ) ? trim( (string) WCD_GITHUB_TOKEN ) : '';
	}

	/**
	 * The newest release, or null when there is none or GitHub cannot be reached.
	 *
	 * A failure here is deliberately quiet. The Plugins screen is not the place
	 * to report that a network call did not work, and a site that cannot see
	 * GitHub should behave exactly like one with no update waiting.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function latest(): ?array {
		$found = self::look();

		return isset( $found['version'] ) ? $found : null;
	}

	/**
	 * The last answer from GitHub, good or bad, with the reason kept.
	 *
	 * A failure used to be remembered as "nothing", which was quiet in the right
	 * way but left no way to tell a site with no update from one that cannot
	 * reach GitHub at all. The reason is kept now so the Status screen can say
	 * which it is.
	 *
	 * @return array<string,mixed>
	 */
	private static function look(): array {
		// Pressing "Check again" should mean it: a wrong token corrected a
		// minute ago should not leave the site quiet for the rest of the hour.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a flag WordPress itself sets.
		$forced = isset( $_GET['force-check'] );

		$cached = $forced ? false : get_site_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::OWNER, self::REPO ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'Authorization'        => 'Bearer ' . self::token(),
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'woo-custom-discount',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::remember( array( 'error' => $response->get_error_message() ), HOUR_IN_SECONDS );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code !== 200 ) {
			// Remembered for a while, so a wrong token or a rate limit does not
			// mean a call to GitHub on every admin page load.
			return self::remember(
				array(
					'error' => $code === 404
						? __( 'No release found. Publish one on GitHub, with the zip attached.', 'woo-custom-discount' )
						: sprintf(
							/* translators: %d: HTTP status code. */
							__( 'GitHub answered %d. The token may be wrong, expired, or without access to this repository.', 'woo-custom-discount' ),
							$code
						),
				),
				HOUR_IN_SECONDS
			);
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return self::remember( array( 'error' => __( 'GitHub answered with something unreadable.', 'woo-custom-discount' ) ), HOUR_IN_SECONDS );
		}

		$found = array(
			'version' => ltrim( (string) $release['tag_name'], 'vV' ),
			'notes'   => (string) ( $release['body'] ?? '' ),
			'date'    => (string) ( $release['published_at'] ?? '' ),
			'package' => self::package_url( $release ),
		);

		return self::remember( $found, self::CACHE_LIFE );
	}

	/**
	 * Keeps an answer and hands it straight back.
	 *
	 * @param array<string,mixed> $answer What was found, or why nothing was.
	 * @param int                 $life   How long to keep it.
	 * @return array<string,mixed>
	 */
	private static function remember( array $answer, int $life ): array {
		set_site_transient( self::CACHE, $answer, $life );

		return $answer;
	}

	/**
	 * What to show on the Status screen.
	 *
	 * @return array{token:bool,version:string,error:string}
	 */
	public static function status(): array {
		if ( self::token() === '' ) {
			return array(
				'token'   => false,
				'version' => '',
				'error'   => __( 'No token in wp-config.php, so updates are never offered.', 'woo-custom-discount' ),
			);
		}

		$found = self::look();

		return array(
			'token'   => true,
			'version' => (string) ( $found['version'] ?? '' ),
			'error'   => (string) ( $found['error'] ?? '' ),
		);
	}

	/**
	 * Where to download the release from.
	 *
	 * A zip attached to the release is preferred: it is built the way the plugin
	 * expects, with the folder named after the plugin. GitHub's own zipball
	 * names that folder after the commit, and WordPress would install it as a
	 * second plugin sitting beside this one rather than replacing it.
	 *
	 * @param array<string,mixed> $release Release data from GitHub.
	 */
	private static function package_url( array $release ): string {
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['url'] ) && substr( (string) $asset['name'], -4 ) === '.zip' ) {
				return (string) $asset['url'];
			}
		}

		return (string) ( $release['zipball_url'] ?? '' );
	}

	/**
	 * Tells WordPress an update is waiting.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public static function offer_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::latest();

		if ( $release === null || $release['package'] === '' ) {
			return $transient;
		}

		if ( version_compare( $release['version'], WCD_VERSION, '<=' ) ) {
			return $transient;
		}

		if ( ! is_array( $transient->response ?? null ) ) {
			$transient->response = array();
		}

		$transient->response[ WCD_BASENAME ] = (object) array(
			'slug'         => dirname( WCD_BASENAME ),
			'plugin'       => WCD_BASENAME,
			'new_version'  => $release['version'],
			'package'      => $release['package'],
			'url'          => '',
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '8.0',
		);

		return $transient;
	}

	/**
	 * Fills the "View details" panel.
	 *
	 * @param mixed  $result Whatever another handler returned.
	 * @param string $action What is being asked for.
	 * @param object $args   Arguments, including the slug.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== dirname( WCD_BASENAME ) ) {
			return $result;
		}

		$release = self::latest();

		if ( $release === null ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Woo Custom Discount',
			'slug'          => dirname( WCD_BASENAME ),
			'version'       => $release['version'],
			'author'        => 'Syed Safwan Ali',
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'last_updated'  => $release['date'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => $release['notes'] !== ''
					? wpautop( esc_html( $release['notes'] ) )
					: '<p>' . esc_html__( 'No notes were written for this release.', 'woo-custom-discount' ) . '</p>',
			),
		);
	}

	/**
	 * Fetches the package itself.
	 *
	 * WordPress downloads update packages with a plain request and no way to add
	 * a header, which a private repository refuses. So the download happens here
	 * instead, with the token attached, and comes back as a file on disk — which
	 * is all the upgrader wanted in the first place.
	 *
	 * @param mixed  $reply    False to let WordPress download it.
	 * @param string $package  The URL being downloaded.
	 * @param object $upgrader The upgrader asking.
	 * @return mixed
	 */
	public static function download( $reply, $package, $upgrader ) {
		$ours = 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO;

		if ( strpos( (string) $package, $ours ) !== 0 ) {
			return $reply;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = wp_tempnam( $package );

		if ( ! $file ) {
			return new \WP_Error( 'wcd_no_temp', __( 'Could not make a temporary file for the download.', 'woo-custom-discount' ) );
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $file,
				'headers'  => array(
					// A release asset is the file itself only when asked for
					// this way; otherwise GitHub answers with its JSON record.
					'Accept'        => 'application/octet-stream',
					'Authorization' => 'Bearer ' . self::token(),
					'User-Agent'    => 'woo-custom-discount',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code !== 200 ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return new \WP_Error(
				'wcd_download_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GitHub answered the download with %d. Check that the token in wp-config.php can still read the repository.', 'woo-custom-discount' ),
					$code
				)
			);
		}

		return $file;
	}

	/**
	 * Drops the remembered release after an update, so the Plugins screen does
	 * not go on offering the version that was just installed.
	 */
	public static function forget(): void {
		delete_site_transient( self::CACHE );
	}
}
