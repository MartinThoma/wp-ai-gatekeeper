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
