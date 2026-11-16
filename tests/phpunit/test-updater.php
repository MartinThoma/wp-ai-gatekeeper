<?php
/**
 * Tests for self-hosted updates and package signature verification.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Updater
 */
class Test_Updater extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Fake HTTP responses by URL: array( 'code' => int, 'body' => string ).
	 *
	 * @var array<string, array>
	 */
	private $http = array();

	/**
	 * URLs requested during the test.
	 *
	 * @var string[]
	 */
	private $requested = array();

	/**
	 * Test signing key pair (binary).
	 *
	 * @var string
	 */
	private $keypair;

	/**
	 * Set up fake HTTP and a trusted test key.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		delete_site_transient( WP_AI_Gatekeeper_Updater::CACHE_KEY );
		$this->keypair = sodium_crypto_sign_keypair();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'wp_ai_gatekeeper_release_public_keys', array( $this, 'trust_test_key' ) );
	}

	/**
	 * Remove filters.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'wp_ai_gatekeeper_release_public_keys', array( $this, 'trust_test_key' ) );
		parent::tear_down();
	}

	/**
	 * Serve fake responses; streamed downloads are written to the target file.
	 *
	 * @param false|array $preempt Preempt value.
	 * @param array       $args    Request args.
	 * @param string      $url     URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $preempt, $args, $url ) {
		$this->requested[] = $url;
		$fake              = $this->http[ $url ] ?? array(
			'code' => 404,
			'body' => 'Not Found',
		);
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $fake['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fake.
		}
		return array(
			'headers'  => array(),
			'body'     => $fake['body'],
			'response' => array(
				'code'    => $fake['code'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}

	/**
	 * Add the test public key to the trusted keys.
	 *
	 * @param string[] $keys Keys.
	 * @return string[]
	 */
	public function trust_test_key( $keys ) {
		$keys[] = base64_encode( sodium_crypto_sign_publickey( $this->keypair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Key encoding.
		return $keys;
	}

	/**
	 * Sign bytes like bin/release-tool.php does.
	 *
	 * @param string      $bytes   Package bytes.
	 * @param string|null $keypair Key pair (default: trusted test key).
	 * @return string Base64 signature.
	 */
	private function sign( $bytes, $keypair = null ) {
		$secret = sodium_crypto_sign_secretkey( $keypair ? $keypair : $this->keypair );
		return base64_encode( sodium_crypto_sign_detached( hash( 'sha384', $bytes, true ), $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signature encoding.
	}

	/**
	 * Serve a manifest.
	 *
	 * @param array $manifest Manifest data.
	 */
	private function serve_manifest( array $manifest ) {
		$this->http[ WP_AI_Gatekeeper_Updater::manifest_url() ] = array(
			'code' => 200,
			'body' => wp_json_encode( $manifest ),
		);
	}

	/**
	 * Call the upgrader download filter for a package URL.
	 *
	 * @param string $package Package URL.
	 * @param array  $extra   Hook extra.
	 * @return mixed
	 */
	private function download( $package, array $extra = array() ) {
		$extra = $extra ? $extra : array( 'plugin' => WP_AI_Gatekeeper_Updater::plugin_file() );
		return WP_AI_Gatekeeper_Updater::download_verified_package( false, $package, null, $extra );
	}

	// ---------------------------------------------------------------
	// Header and update offer.
	// ---------------------------------------------------------------

	/**
	 * Test: the Update URI header routes checks to github.com and away from wordpress.org.
	 */
	public function test_update_uri_header() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_plugin_data( WP_AI_GATEKEEPER_PLUGIN_FILE, false, false );

		$this->assertSame( 'https://github.com/' . WP_AI_Gatekeeper_Updater::REPO, $data['UpdateURI'] );
		$this->assertSame( 'github.com', wp_parse_url( $data['UpdateURI'], PHP_URL_HOST ), 'Must match the update_plugins_github.com filter.' );
		$this->assertSame( WP_AI_GATEKEEPER_VERSION, $data['Version'] );
	}

	/**
	 * Test: a newer release is offered with a package URL built from the version.
	 */
	public function test_offers_update_with_package_url_built_locally() {
		$this->serve_manifest(
			array(
				'version'      => '1.2.3',
				'requires'     => '6.0',
				'requires_php' => '7.4',
				'package'      => 'https://evil.example/backdoor.zip',
			)
		);

		$update = WP_AI_Gatekeeper_Updater::offer_update( false, array(), WP_AI_Gatekeeper_Updater::plugin_file() );

		$this->assertSame( '1.2.3', $update['version'] );
		$this->assertSame( 'https://github.com/MartinThoma/wp-ai-gatekeeper/releases/download/v1.2.3/wp-ai-gatekeeper.zip', $update['package'] );
		$this->assertSame( '7.4', $update['requires_php'] );
	}

	/**
	 * Test: other plugins hosted on GitHub are not touched.
	 */
	public function test_other_github_plugins_are_ignored() {
		$this->assertSame( 'theirs', WP_AI_Gatekeeper_Updater::offer_update( 'theirs', array(), 'other/other.php' ) );
		$this->assertSame( array(), $this->requested );
	}

	/**
	 * Malformed manifests.
	 *
	 * @return array
	 */
	public function bad_manifest_cases() {
		return array(
			'missing version'    => array( '{"requires":"6.0"}' ),
			'non-semver version' => array( '{"version":"1.0.0; rm -rf /"}' ),
			'version not string' => array( '{"version":2}' ),
			'not json'           => array( '<html>Oops</html>' ),
		);
	}

	/**
	 * Test: malformed manifests offer nothing.
	 *
	 * @dataProvider bad_manifest_cases
	 *
	 * @param string $body Response body.
	 */
	public function test_malformed_manifest_offers_nothing( $body ) {
		$this->http[ WP_AI_Gatekeeper_Updater::manifest_url() ] = array(
			'code' => 200,
			'body' => $body,
		);
		$this->assertFalse( WP_AI_Gatekeeper_Updater::offer_update( false, array(), WP_AI_Gatekeeper_Updater::plugin_file() ) );
	}

	/**
	 * Test: invalid requirement fields are dropped, not passed on.
	 */
	public function test_invalid_requirements_are_dropped() {
		$this->serve_manifest(
			array(
				'version'      => '1.2.3',
				'requires_php' => '<script>',
			)
		);
		$update = WP_AI_Gatekeeper_Updater::offer_update( false, array(), WP_AI_Gatekeeper_Updater::plugin_file() );
		$this->assertSame( '', $update['requires_php'] );
	}

	/**
	 * Test: results and failures are cached.
	 */
	public function test_manifest_is_cached_including_failures() {
		WP_AI_Gatekeeper_Updater::get_manifest();
		WP_AI_Gatekeeper_Updater::get_manifest();
		$this->assertCount( 1, $this->requested, 'A failed check (404) is cached too.' );

		delete_site_transient( WP_AI_Gatekeeper_Updater::CACHE_KEY );
		$this->serve_manifest( array( 'version' => '1.2.3' ) );
		WP_AI_Gatekeeper_Updater::get_manifest();
		$this->assertSame( '1.2.3', WP_AI_Gatekeeper_Updater::get_manifest()['version'] );
		$this->assertCount( 2, $this->requested );
	}

	// ---------------------------------------------------------------
	// Signed downloads.
	// ---------------------------------------------------------------

	/**
	 * Test: a correctly signed package is downloaded and accepted.
	 */
	public function test_signed_package_is_accepted() {
		$url                         = WP_AI_Gatekeeper_Updater::package_url( '1.2.3' );
		$this->http[ $url ]          = array(
			'code' => 200,
			'body' => 'ZIP-BYTES',
		);
		$this->http[ $url . '.sig' ] = array(
			'code' => 200,
			'body' => $this->sign( 'ZIP-BYTES' ) . "\n",
		);

		$file = $this->download( $url );
		$this->assertIsString( $file );
		$this->assertSame( 'ZIP-BYTES', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		wp_delete_file( $file );
	}

	/**
	 * Test: a tampered package is rejected and deleted.
	 */
	public function test_tampered_package_is_rejected() {
		$url                         = WP_AI_Gatekeeper_Updater::package_url( '1.2.3' );
		$this->http[ $url ]          = array(
			'code' => 200,
			'body' => 'EVIL-BYTES',
		);
		$this->http[ $url . '.sig' ] = array(
			'code' => 200,
			'body' => $this->sign( 'ZIP-BYTES' ),
		);

		$result = $this->download( $url );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_update_signature_invalid', $result->get_error_code() );
	}

	/**
	 * Test: a missing signature is rejected (no soft-fail).
	 */
	public function test_missing_signature_is_rejected() {
		$url                = WP_AI_Gatekeeper_Updater::package_url( '1.2.3' );
		$this->http[ $url ] = array(
			'code' => 200,
			'body' => 'ZIP-BYTES',
		);
		$this->assertSame( 'wpai_update_signature_invalid', $this->download( $url )->get_error_code() );
	}

	/**
	 * Test: a signature from an untrusted key is rejected.
	 */
	public function test_untrusted_key_is_rejected() {
		$url                         = WP_AI_Gatekeeper_Updater::package_url( '1.2.3' );
		$this->http[ $url ]          = array(
			'code' => 200,
			'body' => 'ZIP-BYTES',
		);
		$this->http[ $url . '.sig' ] = array(
			'code' => 200,
			'body' => $this->sign( 'ZIP-BYTES', sodium_crypto_sign_keypair() ),
		);
		$this->assertSame( 'wpai_update_signature_invalid', $this->download( $url )->get_error_code() );
	}

	/**
	 * Test: an update for this plugin pointing elsewhere is refused.
	 */
	public function test_foreign_package_for_this_plugin_is_refused() {
		$result = $this->download( 'https://evil.example/wp-ai-gatekeeper.zip' );
		$this->assertSame( 'wpai_update_untrusted_source', $result->get_error_code() );
		$this->assertSame( array(), $this->requested, 'Nothing is downloaded.' );
	}

	/**
	 * Test: other plugins' and core downloads are left to WordPress.
	 */
	public function test_other_downloads_pass_through() {
		$this->assertFalse( $this->download( 'https://downloads.wordpress.org/plugin/akismet.zip', array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertFalse( $this->download( 'https://downloads.wordpress.org/release/wordpress-7.0.zip', array( 'type' => 'core' ) ) );
		$this->assertSame( 'earlier', WP_AI_Gatekeeper_Updater::download_verified_package( 'earlier', WP_AI_Gatekeeper_Updater::package_url( '1.0.0' ), null, array() ) );
	}

	/**
	 * Test: only exact release URLs of this repository count as own packages.
	 */
	public function test_own_package_pattern_is_strict() {
		$this->assertTrue( WP_AI_Gatekeeper_Updater::is_own_package( 'https://github.com/MartinThoma/wp-ai-gatekeeper/releases/download/v2.0.1/wp-ai-gatekeeper.zip' ) );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::is_own_package( 'http://github.com/MartinThoma/wp-ai-gatekeeper/releases/download/v2.0.1/wp-ai-gatekeeper.zip' ) );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::is_own_package( 'https://github.com/Attacker/wp-ai-gatekeeper/releases/download/v2.0.1/wp-ai-gatekeeper.zip' ) );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::is_own_package( 'https://github.com/MartinThoma/wp-ai-gatekeeper/releases/download/v2.0.1/wp-ai-gatekeeper.zip?x=1' ) );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::is_own_package( 'https://github.com.evil.example/MartinThoma/wp-ai-gatekeeper/releases/download/v2.0.1/wp-ai-gatekeeper.zip' ) );
	}

	/**
	 * Test: malformed signatures never verify.
	 */
	public function test_malformed_signatures_never_verify() {
		$file = wp_tempnam( 'wpai-test' );
		file_put_contents( $file, 'ZIP-BYTES' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test.

		$this->assertTrue( WP_AI_Gatekeeper_Updater::verify( $file, $this->sign( 'ZIP-BYTES' ) ) );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::verify( $file, 'not base64 !!' ) );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::verify( $file, base64_encode( 'short' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test.
		$this->assertFalse( WP_AI_Gatekeeper_Updater::verify( $file . '-missing', $this->sign( 'ZIP-BYTES' ) ) );
		wp_delete_file( $file );
	}

	/**
	 * Test: the shipped release keys are well-formed.
	 */
	public function test_shipped_release_keys_are_valid() {
		remove_filter( 'wp_ai_gatekeeper_release_public_keys', array( $this, 'trust_test_key' ) );
		$keys = WP_AI_Gatekeeper_Updater::trusted_keys();

		$this->assertNotEmpty( $keys );
		foreach ( $keys as $key ) {
			$this->assertSame( 32, strlen( base64_decode( $key, true ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test.
		}
	}

	// ---------------------------------------------------------------
	// "View details" and upgrade routine.
	// ---------------------------------------------------------------

	/**
	 * Test: the details dialog shows the version and an escaped changelog.
	 */
	public function test_plugin_information_dialog() {
		$this->serve_manifest(
			array(
				'version'   => '1.2.3',
				'changelog' => "- Fix <script>alert(1)</script>\n- Add feature",
			)
		);

		$info = WP_AI_Gatekeeper_Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'wp-ai-gatekeeper' ) );
		$this->assertSame( '1.2.3', $info->version );
		$this->assertStringNotContainsString( '<script>', $info->sections['changelog'] );
		$this->assertStringContainsString( 'Add feature', $info->sections['changelog'] );

		$this->assertSame( 'other', WP_AI_Gatekeeper_Updater::plugin_information( 'other', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
		$this->assertSame( 'other', WP_AI_Gatekeeper_Updater::plugin_information( 'other', 'query_plugins', (object) array( 'slug' => 'wp-ai-gatekeeper' ) ) );
	}

	/**
	 * Test: after an update (version change) the AI role is synced, once.
	 */
	public function test_upgrade_syncs_role_after_version_change() {
		get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->add_cap( 'publish_posts' ); // Stale capability from an "old version".
		update_option( 'wp_ai_gatekeeper_version', '0.9.0' );

		WP_AI_Gatekeeper::maybe_upgrade();

		$this->assertFalse( get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->has_cap( 'publish_posts' ) );
		$this->assertSame( WP_AI_GATEKEEPER_VERSION, get_option( 'wp_ai_gatekeeper_version' ) );

		get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->add_cap( 'custom_cap' );
		WP_AI_Gatekeeper::maybe_upgrade();
		$this->assertTrue( get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->has_cap( 'custom_cap' ), 'No re-sync without a version change.' );
	}

	/**
	 * Test: a failed package download is reported as such, and the details
	 * dialog falls back to WordPress when no manifest is available.
	 */
	public function test_download_failure_and_missing_details() {
		$result = $this->download( WP_AI_Gatekeeper_Updater::package_url( '9.9.9' ) );
		$this->assertWPError( $result );
		$this->assertNotSame( 'wpai_update_signature_invalid', $result->get_error_code(), 'Download error, not a signature error.' );

		set_site_transient( WP_AI_Gatekeeper_Updater::CACHE_KEY, array( 'error' => true ), HOUR_IN_SECONDS );
		$this->assertFalse( WP_AI_Gatekeeper_Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => WP_AI_Gatekeeper_Updater::SLUG ) ) );
	}
}
