<?php
/**
 * Tests: the text of password-protected posts is only shown to users who can
 * edit the post: not through the post endpoints, not through search, and not
 * to the AI through suggestions (which quote the article).
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Password_Protected_Posts
 */
class Test_Password_Protected_Posts extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber;

	/**
	 * Password-protected published post by the editor.
	 *
	 * @var int
	 */
	private $protected_post;

	/**
	 * Public published post by the editor.
	 *
	 * @var int
	 */
	private $public_post;

	/**
	 * Creates a protected and a public post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->subscriber     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->protected_post = $this->create_editor_post(
			array(
				'post_title'    => 'Board minutes',
				'post_content'  => '<p>The merger with Zanzibar Widgets closes in May.</p>',
				'post_excerpt'  => 'Confidential merger timeline.',
				'post_password' => 'hunter2',
			)
		);
		$this->public_post    = $this->create_editor_post(
			array(
				'post_title'   => 'Company picnic',
				'post_content' => '<p>The picnic is in June.</p>',
			)
		);
	}

	/**
	 * Dispatches a request as a given user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $method  HTTP method.
	 * @param string $route   REST route.
	 * @param array  $params  Parameters.
	 * @return WP_REST_Response
	 */
	private function request_as( $user_id, $method, $route, array $params = array() ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Asserts a formatted post carries none of the protected text.
	 *
	 * @param array $post Formatted post from the API.
	 * @return void
	 */
	private function assert_withheld( array $post ) {
		$this->assertTrue( $post['content_protected'] );
		$this->assertSame( '', $post['content_raw'] );
		$this->assertSame( '', $post['content_rendered'] );
		$this->assertSame( '', $post['excerpt'] );
		$this->assertSame( 'Board minutes', $post['title'], 'The title stays visible, as in WordPress itself.' );
		$this->assertStringNotContainsString( 'Zanzibar', wp_json_encode( $post ) );
	}

	/**
	 * Creates an AI suggestion on the protected post, made before the password
	 * was set (otherwise the AI could not have created it).
	 *
	 * @return int Suggestion ID.
	 */
	private function suggestion_from_before_the_password() {
		wp_update_post(
			array(
				'ID'            => $this->protected_post,
				'post_password' => '',
			)
		);
		$created = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$this->protected_post,
			array(
				'original_text'  => 'closes in May',
				'suggested_text' => 'closes in early May',
			),
			$this->ai_user_id
		);
		$this->assertNotWPError( $created );
		wp_update_post(
			array(
				'ID'            => $this->protected_post,
				'post_password' => 'hunter2',
			)
		);
		return (int) $created['id'];
	}

	/**
	 * Data provider: callers that may read but not edit the protected post.
	 *
	 * @return array
	 */
	public function readers_without_edit_rights() {
		return array(
			'subscriber' => array( 'subscriber' ),
			'AI agent'   => array( 'ai' ),
		);
	}

	/**
	 * Resolves a data-provider role to a user ID.
	 *
	 * @param string $who 'subscriber' or 'ai'.
	 * @return int
	 */
	private function user( $who ) {
		return 'ai' === $who ? $this->ai_user_id : $this->subscriber;
	}

	/**
	 * Test: the single-post endpoint withholds protected content.
	 *
	 * @dataProvider readers_without_edit_rights
	 *
	 * @param string $who Caller.
	 */
	public function test_single_post_withholds_protected_content( $who ) {
		$response = $this->request_as( $this->user( $who ), 'GET', "/ai-gatekeeper/v1/posts/{$this->protected_post}" );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_withheld( $response->get_data() );
	}

	/**
	 * Test: the post list withholds protected content.
	 *
	 * @dataProvider readers_without_edit_rights
	 *
	 * @param string $who Caller.
	 */
	public function test_post_list_withholds_protected_content( $who ) {
		$data  = $this->request_as( $this->user( $who ), 'GET', '/ai-gatekeeper/v1/posts', array( 'status' => 'publish' ) )->get_data();
		$items = array_column( $data['items'], null, 'id' );

		$this->assertArrayHasKey( $this->protected_post, $items );
		$this->assert_withheld( $items[ $this->protected_post ] );
		$this->assertFalse( $items[ $this->public_post ]['content_protected'] );
		$this->assertStringContainsString( 'picnic is in June', $items[ $this->public_post ]['content_raw'] );
	}

	/**
	 * Test: searching for a phrase from protected content finds nothing, so a
	 * search cannot be used to test what the hidden text says.
	 *
	 * @dataProvider readers_without_edit_rights
	 *
	 * @param string $who Caller.
	 */
	public function test_search_does_not_match_protected_content( $who ) {
		$data = $this->request_as( $this->user( $who ), 'GET', '/ai-gatekeeper/v1/posts', array( 'search' => 'Zanzibar' ) )->get_data();

		$this->assertSame( array(), $data['items'] );
		$this->assertSame( 0, $data['total'] );

		$public = $this->request_as( $this->user( $who ), 'GET', '/ai-gatekeeper/v1/posts', array( 'search' => 'picnic' ) )->get_data();
		$this->assertSame( array( $this->public_post ), wp_list_pluck( $public['items'], 'id' ), 'Normal search still works.' );
	}

	/**
	 * Test: users who can edit the post see everything and can search it.
	 */
	public function test_editor_sees_and_searches_protected_content() {
		$post = $this->request_as( $this->editor_id, 'GET', "/ai-gatekeeper/v1/posts/{$this->protected_post}" )->get_data();
		$this->assertFalse( $post['content_protected'] );
		$this->assertStringContainsString( 'Zanzibar Widgets', $post['content_raw'] );
		$this->assertStringContainsString( 'Zanzibar Widgets', $post['content_rendered'] );
		$this->assertSame( 'Confidential merger timeline.', $post['excerpt'] );

		$found = $this->request_as( $this->editor_id, 'GET', '/ai-gatekeeper/v1/posts', array( 'search' => 'Zanzibar' ) )->get_data();
		$this->assertSame( array( $this->protected_post ), wp_list_pluck( $found['items'], 'id' ) );
	}

	/**
	 * Test: an author finds their own protected post in a search.
	 */
	public function test_author_searches_own_protected_post() {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author'   => $author,
				'post_status'   => 'publish',
				'post_content'  => '<p>Quokka recipes.</p>',
				'post_password' => 'secret',
			)
		);

		$found = $this->request_as( $author, 'GET', '/ai-gatekeeper/v1/posts', array( 'search' => 'Quokka' ) )->get_data();
		$this->assertSame( array( $post_id ), wp_list_pluck( $found['items'], 'id' ) );
		$this->assertStringContainsString( 'Quokka recipes', $found['items'][0]['content_raw'] );
	}

	/**
	 * Test: the AI cannot suggest changes to a protected post: whether a
	 * suggestion's original_text is found would reveal the hidden text.
	 */
	public function test_ai_cannot_suggest_on_protected_post() {
		$response = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/posts/{$this->protected_post}/suggestions",
			array(
				'original_text'  => 'closes in May',
				'suggested_text' => 'closes in June',
			)
		);
		$this->assertSame( 403, $response->get_status() );

		$public = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/posts/{$this->public_post}/suggestions",
			array(
				'original_text'  => 'in June',
				'suggested_text' => 'in July',
			)
		);
		$this->assertSame( 201, $public->get_status() );
	}

	/**
	 * Test: once a post gets a password, the AI loses access to its suggestions,
	 * which quote the article; the editor keeps it.
	 */
	public function test_ai_loses_access_to_suggestions_on_protected_post() {
		$id = $this->suggestion_from_before_the_password();

		$this->assertSame( 403, $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/suggestions/{$id}" )->get_status() );
		$this->assertSame( 403, $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/posts/{$this->protected_post}/suggestions" )->get_status() );
		$this->assertSame( 403, $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/suggestions/{$id}/preview" )->get_status() );
		$this->assertSame( 403, $this->dispatch_rest( 'POST', "/ai-gatekeeper/v1/suggestions/{$id}/comments", array( 'comment' => 'Any update?' ) )->get_status() );
		$this->assertSame( 403, $this->dispatch_rest( 'PUT', "/ai-gatekeeper/v1/suggestions/{$id}", array( 'suggested_text' => 'closes in late May' ) )->get_status() );

		$list = $this->dispatch_rest( 'GET', '/ai-gatekeeper/v1/suggestions', array( 'status' => 'all' ) )->get_data();
		$this->assertNotContains( $id, wp_list_pluck( $list['items'], 'id' ) );
		$this->assertStringNotContainsString( 'Zanzibar', wp_json_encode( $list ) );

		$this->assertSame( 200, $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/suggestions/{$id}", array(), false )->get_status() );
		$this->assertSame( 200, $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/suggestions/{$id}/preview", array(), false )->get_status() );
	}

	/**
	 * Test: the rule ignores the wp-postpass cookie, so the API answer does not
	 * depend on whether the browser once entered the password.
	 */
	public function test_rule_requires_edit_rights_not_password_cookie() {
		$this->assertFalse( WP_AI_Gatekeeper_Permissions::can_read_post_content( $this->protected_post, $this->subscriber ) );
		$this->assertTrue( WP_AI_Gatekeeper_Permissions::can_read_post_content( $this->protected_post, $this->editor_id ) );
		$this->assertTrue( WP_AI_Gatekeeper_Permissions::can_read_post_content( $this->public_post, $this->subscriber ) );
		$this->assertFalse( WP_AI_Gatekeeper_Permissions::can_read_post_content( 999999, $this->editor_id ) );
	}
}
