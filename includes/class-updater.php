<?php
/**
 * Self-hosted updates from GitHub releases, with signature verification.
 *
 * WordPress only knows wordpress.org. The plugin header's "Update URI" stops
 * WordPress from asking wordpress.org about this plugin (a future plugin with
 * the same slug there could otherwise be offered as an "update") and routes
 * update checks to the update_plugins_github.com filter handled here.
 *
 * Supply chain:
 * - The update manifest (update.json) only provides a version; the download
 *   URL is always built here from this repository's release URL pattern.
 * - Every package must carry a valid Ed25519 signature (over the SHA-384 of the
 *   zip, like WordPress core's own scheme) from a key in release-keys.php.
 *   Release keys never live on GitHub, so a compromised GitHub account or
 *   token cannot ship code to sites. Unlike core's signature check, which only
 *   soft-fails by default, a bad or missing signature aborts the update.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers updates from GitHub releases and installs only signed packages.
 */
class WP_AI_Gatekeeper_Updater {

	/**
	 * GitHub repository (owner/name). Must match the Update URI header.
	 */
	const REPO = 'MartinThoma/wp-ai-gatekeeper';

	/**
	 * Release asset file name of the plugin zip.
	 */
	const ASSET = 'wp-ai-gatekeeper.zip';

	/**
	 * Slug used for the "View details" dialog.
	 */
	const SLUG = 'wp-ai-gatekeeper';

	/**
	 * Site transient caching the parsed manifest (or a failure marker).
	 */
	const CACHE_KEY = 'wp_ai_gatekeeper_update_manifest';

	/**
	 * Cache lifetime after a successful check (12 hours).
	 */
	const CACHE_TTL = 43200;

	/**
	 * Cache lifetime after a failed check (1 hour), to avoid hammering GitHub.
	 */
	const FAILURE_TTL = 3600;

	/**
	 * Registers update hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer_update' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_verified_package' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
	}

	/**
	 * Plugin basename, e.g. "wp-ai-gatekeeper/wp-ai-gatekeeper.php".
	 *
	 * @return string
	 */
	public static function plugin_file() {
		return plugin_basename( WP_AI_GATEKEEPER_PLUGIN_FILE );
	}

	/**
	 * URL of the manifest of the latest published release. Uses the release
	 * download URL rather than the GitHub API (60 requests/hour per IP).
	 *
	 * @return string
	 */
	public static function manifest_url() {
		return 'https://github.com/' . self::REPO . '/releases/latest/download/update.json';
	}

	/**
	 * Package URL for a version. Never taken from the manifest.
	 *
	 * @param string $version Version (x.y.z).
	 * @return string
	 */
	public static function package_url( $version ) {
		return 'https://github.com/' . self::REPO . '/releases/download/v' . $version . '/' . self::ASSET;
	}

	/**
	 * Whether a URL is a release package of this plugin.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_own_package( $url ) {
		$pattern = '#^https://github\.com/' . preg_quote( self::REPO, '#' ) . '/releases/download/v\d+\.\d+\.\d+/' . preg_quote( self::ASSET, '#' ) . '$#';
		return is_string( $url ) && 1 === preg_match( $pattern, $url );
	}

	/**
	 * Returns the latest release manifest (cached), or null if unavailable.
	 *
	 * @return array|null
	 */
	public static function get_manifest() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && isset( $cached['version'] ) ) ? $cached : null;
		}

		$manifest = self::parse_manifest(
			wp_safe_remote_get(
				self::manifest_url(),
				array( 'timeout' => 10 )
			)
		);

		set_site_transient(
			self::CACHE_KEY,
			null === $manifest ? array( 'error' => true ) : $manifest,
			null === $manifest ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $manifest;
	}

	/**
	 * Validates an HTTP response containing update.json.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|null Normalized manifest, or null if invalid.
	 */
	public static function parse_manifest( $response ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['version'] ) || ! is_string( $data['version'] )
			|| ! preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] )
		) {
			return null;
		}

		$requirement = static function ( $key ) use ( $data ) {
			return ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && preg_match( '/^\d+(\.\d+){0,2}$/', $data[ $key ] ) ) ? $data[ $key ] : '';
		};

		return array(
			'version'      => $data['version'],
			'requires'     => $requirement( 'requires' ),
			'requires_php' => $requirement( 'requires_php' ),
			'tested'       => $requirement( 'tested' ),
			'changelog'    => ( isset( $data['changelog'] ) && is_string( $data['changelog'] ) ) ? $data['changelog'] : '',
		);
	}

	/**
	 * Provides update data for this plugin (WordPress compares versions).
	 *
	 * @param array|false $update      Update data from other filters.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function offer_update( $update, $plugin_data, $plugin_file ) {
		if ( self::plugin_file() !== $plugin_file ) {
			return $update; // Another plugin hosted on GitHub.
		}

		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $manifest['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => self::package_url( $manifest['version'] ),
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
		);
	}

	/**
	 * Downloads this plugin's update package and verifies its signature.
	 * Any other package is left to WordPress.
	 *
	 * @param false|string|WP_Error $reply      Result from earlier filters.
	 * @param string                $package    Package URL.
	 * @param WP_Upgrader           $upgrader   Upgrader.
	 * @param array                 $hook_extra Upgrade context.
	 * @return false|string|WP_Error Local file path, error, or $reply untouched.
	 */
	public static function download_verified_package( $reply, $package, $upgrader, $hook_extra ) {
		if ( false !== $reply ) {
			return $reply;
		}

		$is_this_plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && self::plugin_file() === $hook_extra['plugin'];
		if ( ! self::is_own_package( $package ) ) {
			if ( $is_this_plugin ) {
				// The update data was altered to point elsewhere: refuse.
				return new WP_Error( 'wpai_update_untrusted_source', __( 'The WP AI Gatekeeper update does not come from its official release location, so it was not installed.', 'wp-ai-gatekeeper' ) );
			}
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$signature = wp_safe_remote_get( $package . '.sig', array( 'timeout' => 30 ) );
		$valid     = ! is_wp_error( $signature )
			&& 200 === (int) wp_remote_retrieve_response_code( $signature )
			&& self::verify( $file, trim( wp_remote_retrieve_body( $signature ) ) );

		if ( ! $valid ) {
			wp_delete_file( $file );
			return new WP_Error( 'wpai_update_signature_invalid', __( 'The WP AI Gatekeeper update is not signed with a trusted release key, so it was not installed.', 'wp-ai-gatekeeper' ) );
		}

		return $file;
	}

	/**
	 * Verifies a base64 Ed25519 signature over the file's SHA-384 hash.
	 *
	 * @param string $file             Local file path.
	 * @param string $signature_base64 Base64 signature.
	 * @return bool
	 */
	public static function verify( $file, $signature_base64 ) {
		$signature = base64_decode( (string) $signature_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Signature encoding.
		if ( false === $signature || 64 !== strlen( $signature ) || ! is_readable( $file ) ) {
			return false;
		}

		$hash = hash_file( 'sha384', $file, true );
		foreach ( self::trusted_keys() as $key_base64 ) {
			$key = base64_decode( (string) $key_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Key encoding.
			if ( false !== $key && 32 === strlen( $key ) && sodium_crypto_sign_verify_detached( $signature, $hash, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Trusted release public keys (base64).
	 *
	 * @return string[]
	 */
	public static function trusted_keys() {
		$keys = include __DIR__ . '/release-keys.php';

		/**
		 * Filters trusted release keys. Intended for tests; code that can add
		 * filters could already change the site anyway.
		 *
		 * @param string[] $keys Base64 Ed25519 public keys.
		 */
		return (array) apply_filters( 'wp_ai_gatekeeper_release_public_keys', is_array( $keys ) ? $keys : array() );
	}

	/**
	 * Supplies the "View details" dialog for this plugin.
	 *
	 * @param false|object|array $result Result from earlier filters.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$manifest = self::get_manifest();
		if ( null === $manifest ) {
			return $result;
		}

		$releases  = 'https://github.com/' . self::REPO . '/releases';
		$changelog = '' !== $manifest['changelog'] ? wpautop( esc_html( $manifest['changelog'] ) ) : '';

		return (object) array(
			'name'          => 'WP AI Gatekeeper',
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'author'        => 'WP AI Gatekeeper Contributors',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'download_link' => self::package_url( $manifest['version'] ),
			'sections'      => array(
				'changelog' => $changelog . '<p><a href="' . esc_url( $releases ) . '">' . esc_html__( 'All release notes on GitHub', 'wp-ai-gatekeeper' ) . '</a></p>',
			),
		);
	}
}
