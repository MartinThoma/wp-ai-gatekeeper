<?php
/**
 * Tests for API key authentication through real request headers.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Api_Key_Auth
 */
class Test_Api_Key_Auth extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Result of the REST authentication check for the current request.
	 *
	 * @return true|WP_Error|null
	 */
	private function rest_auth_result() {
		return rest_get_server()->check_authentication();
	}

	/**
	 * Test: a valid X-WP-AI-Key header logs in the AI user.
	 */
	public function test_valid_custom_header_authenticates_ai_user() {
		$this->assertSame( $this->ai_user_id, $this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $this->ai_api_key ) ) );
		$this->assertTrue( WP_AI_Gatekeeper_Auth::is_ai_key_request() );
		$this->assertTrue( $this->rest_auth_result() );
	}

	/**
	 * Test: a valid Bearer token logs in the AI user.
	 */
	public function test_valid_bearer_token_authenticates_ai_user() {
		$this->assertSame( $this->ai_user_id, $this->authenticate_with( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->ai_api_key ) ) );
	}

	/**
	 * Test: the redirected Authorization header (some Apache setups) is honored.
	 */
	public function test_redirect_authorization_header_is_honored() {
		$this->assertSame( $this->ai_user_id, $this->authenticate_with( array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $this->ai_api_key ) ) );
	}

	/**
	 * Test: Bearer tokens that are not AI keys are ignored, not rejected.
	 */
	public function test_foreign_bearer_token_is_ignored() {
		$this->assertSame( 0, $this->authenticate_with( array( 'HTTP_AUTHORIZATION' => 'Bearer some-oauth-token' ) ) );
		$this->assertFalse( WP_AI_Gatekeeper_Auth::is_ai_key_request() );
		$this->assertNotInstanceOf( WP_Error::class, $this->rest_auth_result() );
	}

	/**
	 * Test: no key means no change and no error.
	 */
	public function test_no_key_leaves_request_anonymous() {
		$this->assertSame( 0, $this->authenticate_with( array() ) );
		$this->assertNotInstanceOf( WP_Error::class, $this->rest_auth_result() );
	}

	/**
	 * Test: an unknown key is rejected with 401.
	 */
	public function test_unknown_key_is_rejected() {
		$this->assertSame( 0, $this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => 'wpai_' . str_repeat( '0', 32 ) ) ) );

		$error = $this->rest_auth_result();
		$this->assertWPError( $error );
		$this->assertSame( 'rest_ai_invalid_key', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	/**
	 * Test: a revoked key is rejected with 401.
	 */
	public function test_revoked_key_is_rejected() {
		$created = WP_AI_Gatekeeper_Auth::generate_api_key( 'To revoke', $this->ai_user_id );
		$this->assertTrue( WP_AI_Gatekeeper_Auth::revoke_api_key( $created['record']['id'] ) );

		$this->assertSame( 0, $this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $created['raw_key'] ) ) );
		$this->assertSame( 'rest_ai_invalid_key', $this->rest_auth_result()->get_error_code() );
	}

	/**
	 * Test: a deleted key is rejected and disappears from the list.
	 */
	public function test_deleted_key_is_rejected_and_unlisted() {
		$created = WP_AI_Gatekeeper_Auth::generate_api_key( 'To delete', $this->ai_user_id );
		$this->assertTrue( WP_AI_Gatekeeper_Auth::delete_api_key( $created['record']['id'] ) );
		$this->assertFalse( WP_AI_Gatekeeper_Auth::delete_api_key( $created['record']['id'] ), 'Deleting twice reports not found.' );

		$this->assertSame( 0, $this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $created['raw_key'] ) ) );
		$this->assertNotContains( $created['record']['id'], wp_list_pluck( WP_AI_Gatekeeper_Auth::list_api_keys(), 'id' ) );
	}

	/**
	 * Test: revoking an unknown key reports not found.
	 */
	public function test_revoking_unknown_key_returns_false() {
		$this->assertFalse( WP_AI_Gatekeeper_Auth::revoke_api_key( 'does-not-exist' ) );
	}

	/**
	 * Test: a key whose user lost the AI role is rejected (cannot become a human login).
	 */
	public function test_key_for_user_without_ai_role_is_rejected() {
		get_userdata( $this->ai_user_id )->set_role( 'editor' );

		$this->assertSame( 0, $this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $this->ai_api_key ) ) );
		$this->assertSame( 'rest_ai_invalid_user', $this->rest_auth_result()->get_error_code() );
	}

	/**
	 * Test: an invalid key also logs out an otherwise authenticated user.
	 */
	public function test_invalid_key_overrides_existing_login() {
		$filter = function () {
			return $this->editor_id;
		};
		add_filter( 'determine_current_user', $filter, 15 ); // After core's cookie check (10), before the plugin (20).

		$this->assertSame( 0, $this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => 'wpai_invalid' ) ) );
		remove_filter( 'determine_current_user', $filter, 15 );
	}

	/**
	 * Data provider: request URLs and whether they count as REST requests.
	 *
	 * @return array
	 */
	public function request_urls() {
		return array(
			'REST, pretty permalinks'       => array( '/wp-json/ai-gatekeeper/v1/posts', array(), true ),
			'REST index'                    => array( '/wp-json', array(), true ),
			'REST, plain permalinks'        => array( '/', array( 'rest_route' => '/ai-gatekeeper/v1/posts' ), true ),
			'REST via index.php'            => array( '/index.php', array( 'rest_route' => '/' ), true ),
			'admin-ajax'                    => array( '/wp-admin/admin-ajax.php', array(), false ),
			'admin-ajax with rest_route'    => array( '/wp-admin/admin-ajax.php', array( 'rest_route' => '/ai-gatekeeper/v1/posts' ), false ),
			'admin-post'                    => array( '/wp-admin/admin-post.php', array(), false ),
			'XML-RPC'                       => array( '/xmlrpc.php', array(), false ),
			'admin page'                    => array( '/wp-admin/edit.php', array(), false ),
			'front-end page'                => array( '/hello-world/', array(), false ),
			'look-alike prefix'             => array( '/wp-jsonp/x', array(), false ),
			'REST prefix deeper in the URL' => array( '/blog/wp-json/x', array(), false ),
		);
	}

	/**
	 * Test: the AI key is only accepted on REST API requests.
	 *
	 * @dataProvider request_urls
	 *
	 * @param string $uri     REQUEST_URI.
	 * @param array  $get     Query parameters.
	 * @param bool   $is_rest Whether the key should authenticate.
	 */
	public function test_key_is_only_accepted_on_rest_requests( $uri, array $get, $is_rest ) {
		$this->assertFalse( defined( 'REST_REQUEST' ), 'Precondition: decided by the URL.' );
		$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$user = $this->authenticate_with(
			array(
				'HTTP_X_WP_AI_KEY' => $this->ai_api_key,
				'REQUEST_URI'      => $uri,
			)
		);

		$this->assertSame( $is_rest ? $this->ai_user_id : 0, $user );
		$this->assertSame( $is_rest, WP_AI_Gatekeeper_Auth::is_ai_key_request() );
	}

	/**
	 * Test: outside the REST API a key, valid or not, leaves an existing login
	 * alone (it no longer logs the browser user out of admin-ajax).
	 */
	public function test_key_does_not_touch_logins_outside_rest() {
		$filter = function () {
			return $this->editor_id;
		};
		add_filter( 'determine_current_user', $filter, 15 ); // After core's cookie check (10), before the plugin (20).

		foreach ( array( $this->ai_api_key, 'wpai_invalid' ) as $key ) {
			$user = $this->authenticate_with(
				array(
					'HTTP_X_WP_AI_KEY' => $key,
					'REQUEST_URI'      => '/wp-admin/admin-ajax.php',
				)
			);
			$this->assertSame( $this->editor_id, $user );
		}
		remove_filter( 'determine_current_user', $filter, 15 );
	}

	/**
	 * Test: on a site installed in a subdirectory, the REST prefix is relative
	 * to the site's home path.
	 */
	public function test_rest_detection_in_subdirectory_install() {
		update_option( 'home', 'http://example.org/blog' );

		$this->assertSame(
			$this->ai_user_id,
			$this->authenticate_with(
				array(
					'HTTP_X_WP_AI_KEY' => $this->ai_api_key,
					'REQUEST_URI'      => '/blog/wp-json/ai-gatekeeper/v1/posts',
				)
			)
		);
		$this->assertSame(
			0,
			$this->authenticate_with(
				array(
					'HTTP_X_WP_AI_KEY' => $this->ai_api_key,
					'REQUEST_URI'      => '/wp-json/ai-gatekeeper/v1/posts',
				)
			)
		);
	}

	/**
	 * Test: authenticating records last use, at most once per minute.
	 */
	public function test_last_used_is_recorded_and_throttled() {
		$this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $this->ai_api_key ) );
		$first = $this->key_record()['last_used_at'];
		$this->assertNotEmpty( $first );

		// Pretend the last use was 30 seconds ago: no new write.
		$this->set_last_used( gmdate( 'Y-m-d H:i:s', time() - 30 ) );
		$this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $this->ai_api_key ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', time() - 30 ), $this->key_record()['last_used_at'] );

		// Two minutes ago: updated.
		$this->set_last_used( gmdate( 'Y-m-d H:i:s', time() - 120 ) );
		$this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $this->ai_api_key ) );
		$this->assertGreaterThan( time() - 60, strtotime( $this->key_record()['last_used_at'] ) );
	}

	/**
	 * Test: listed keys never expose the hash and are newest first.
	 */
	public function test_key_list_hides_hashes_and_is_newest_first() {
		WP_AI_Gatekeeper_Auth::generate_api_key( 'Second key', $this->ai_user_id );
		$keys = WP_AI_Gatekeeper_Auth::list_api_keys();

		$this->assertSame( 'Second key', $keys[0]['name'] );
		foreach ( $keys as $key ) {
			$this->assertArrayNotHasKey( 'key_hash', $key );
			$this->assertStringStartsWith( 'wpai_', $key['key_prefix'] );
		}
		$this->assertStringNotContainsString( $this->ai_api_key, wp_json_encode( $keys ), 'The raw key is never stored.' );
	}

	/**
	 * Test: keys can only be bound to AI users.
	 */
	public function test_keys_cannot_be_bound_to_human_users() {
		$result = WP_AI_Gatekeeper_Auth::generate_api_key( 'Editor key', $this->editor_id );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_ai_user', $result->get_error_code() );
	}

	/**
	 * Test: an empty label gets a default, and the default AI user is used.
	 */
	public function test_empty_label_gets_default_and_default_user() {
		$created = WP_AI_Gatekeeper_Auth::create_api_key( '   ' );
		$this->assertSame( 'AI Agent Key', $created['record']['name'] );
		$this->assertSame( $this->ai_user_id, $created['record']['user_id'] );
	}

	/**
	 * Test: end to end, a key-authenticated request gets AI restrictions.
	 */
	public function test_key_authenticated_request_is_restricted_as_ai() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );
		$this->authenticate_with( array( 'HTTP_X_WP_AI_KEY' => $this->ai_api_key ) );

		$read = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/ai-gatekeeper/v1/posts/{$post_id}" ) );
		$this->assertSame( 200, $read->get_status() );

		$publish = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$publish->set_param( 'title', 'Sneaky' );
		$publish->set_param( 'status', 'publish' );
		$this->assertSame( 403, rest_get_server()->dispatch( $publish )->get_status() );
	}

	/**
	 * Test: the default AI user is reused, and re-created with the role if missing.
	 */
	public function test_default_ai_user_is_stable() {
		$this->assertSame( $this->ai_user_id, WP_AI_Gatekeeper_Auth::ensure_default_ai_user() );

		delete_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION );
		$this->assertSame( $this->ai_user_id, WP_AI_Gatekeeper_Auth::ensure_default_ai_user(), 'Found again by login.' );
	}

	/**
	 * Test: the plugin leaves WordPress's Application Passwords decision alone,
	 * including a site or plugin that switched them off.
	 */
	public function test_application_passwords_decision_is_not_overridden() {
		$this->assertFalse( has_filter( 'wp_is_application_passwords_available' ) );

		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->assertFalse( wp_is_application_passwords_available() );
		remove_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertSame( wp_is_application_passwords_supported(), wp_is_application_passwords_available() );
	}

	/**
	 * Returns the stored record for the fixture key.
	 *
	 * @return array
	 */
	private function key_record() {
		foreach ( get_option( WP_AI_Gatekeeper_Auth::KEYS_OPTION ) as $record ) {
			if ( 0 === strpos( $this->ai_api_key, $record['key_prefix'] ) && 'PHPUnit Test Key' === $record['name'] ) {
				return $record;
			}
		}
		$this->fail( 'Fixture key not found.' );
	}

	/**
	 * Overwrite last_used_at of the fixture key.
	 *
	 * @param string $mysql_time UTC time.
	 */
	private function set_last_used( $mysql_time ) {
		$keys = get_option( WP_AI_Gatekeeper_Auth::KEYS_OPTION );
		foreach ( $keys as $i => $record ) {
			if ( 'PHPUnit Test Key' === $record['name'] ) {
				$keys[ $i ]['last_used_at'] = $mysql_time;
			}
		}
		update_option( WP_AI_Gatekeeper_Auth::KEYS_OPTION, $keys, false );
	}
}
