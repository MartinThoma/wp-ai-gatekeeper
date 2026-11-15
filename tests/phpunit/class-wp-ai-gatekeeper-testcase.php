<?php
/**
 * Shared fixtures and helpers for WP AI Gatekeeper integration tests.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Base test case: an editor, the AI user with an API key, and a REST helper.
 */
abstract class WP_AI_Gatekeeper_TestCase extends WP_UnitTestCase {

	/**
	 * Human editor user ID.
	 *
	 * @var int
	 */
	protected $editor_id;

	/**
	 * AI Gatekeeper user ID.
	 *
	 * @var int
	 */
	protected $ai_user_id;

	/**
	 * Raw API key for AI requests.
	 *
	 * @var string
	 */
	protected $ai_api_key;

	/**
	 * Set up test fixtures before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WP_AI_Gatekeeper_Database::create_tables();
		WP_AI_Gatekeeper_Auth::activate();

		$this->editor_id  = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Senior Human Editor',
			)
		);
		$this->ai_user_id = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();

		$key_data         = WP_AI_Gatekeeper_Auth::generate_api_key( 'PHPUnit Test Key', $this->ai_user_id );
		$this->ai_api_key = $key_data['raw_key'];

		rest_get_server();
	}

	/**
	 * Request headers that tests may set; cleared after every test.
	 *
	 * @var string[]
	 */
	const AUTH_SERVER_KEYS = array( 'HTTP_X_WP_AI_KEY', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

	/**
	 * Clear per-request auth state so one test's login cannot leak into the next.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( self::AUTH_SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$this->reset_auth_state();
		parent::tear_down();
	}

	/**
	 * Reset WP_AI_Gatekeeper_Auth's static per-request state.
	 *
	 * @return void
	 */
	protected function reset_auth_state() {
		$defaults = array(
			'is_ai_key_request' => false,
			'active_key_id'     => null,
			'auth_error'        => null,
			'determining_user'  => false,
		);
		foreach ( $defaults as $name => $value ) {
			$property = new ReflectionProperty( 'WP_AI_Gatekeeper_Auth', $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Authenticate like a real request: set server headers, then let WordPress
	 * determine the current user (runs the determine_current_user filter).
	 *
	 * @param array $server $_SERVER entries, e.g. array( 'HTTP_X_WP_AI_KEY' => $key ).
	 * @return int The resulting current user ID.
	 */
	protected function authenticate_with( array $server ) {
		foreach ( self::AUTH_SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		$this->reset_auth_state();

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Forces WordPress to determine the user again.
		return wp_get_current_user()->ID;
	}

	/**
	 * Dispatch a REST request as the AI agent or as a human editor.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $params Request parameters.
	 * @param bool   $as_ai  True to authenticate as AI agent, false for human editor.
	 * @return WP_REST_Response
	 */
	protected function dispatch_rest( $method, $route, $params = array(), $as_ai = true ) {
		$request = new WP_REST_Request( $method, $route );

		if ( $as_ai ) {
			wp_set_current_user( $this->ai_user_id );
			$request->set_header( 'X-WP-AI-Key', $this->ai_api_key );
		} else {
			wp_set_current_user( $this->editor_id );
		}

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Create a post as the editor (who has unfiltered_html on single site).
	 *
	 * @param array $args Post fields.
	 * @return int Post ID.
	 */
	protected function create_editor_post( array $args ) {
		wp_set_current_user( $this->editor_id );
		return self::factory()->post->create(
			array_merge(
				array(
					'post_author' => $this->editor_id,
					'post_status' => 'publish',
				),
				$args
			)
		);
	}
}
