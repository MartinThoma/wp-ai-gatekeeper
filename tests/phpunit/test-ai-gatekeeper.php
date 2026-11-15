<?php
/**
 * Integration tests for WP AI Gatekeeper permissions, REST endpoints, and PR suggestions.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_WP_AI_Gatekeeper
 */
class Test_WP_AI_Gatekeeper extends WP_UnitTestCase {

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
	 * Helper: dispatch a REST request as the AI agent or as a human editor.
	 *
	 * @param string $method   HTTP method.
	 * @param string $route    REST route.
	 * @param array  $params   Request parameters.
	 * @param bool   $as_ai    True to authenticate as AI agent, false for human editor.
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
	 * Test 1: AI can read both published and draft articles authored by anyone.
	 */
	public function test_ai_can_read_all_draft_and_published_articles() {
		$published_id = self::factory()->post->create(
			array(
				'post_author'  => $this->editor_id,
				'post_title'   => 'Published Corporate Post',
				'post_content' => 'Published body content.',
				'post_status'  => 'publish',
			)
		);

		$human_draft_id = self::factory()->post->create(
			array(
				'post_author'  => $this->editor_id,
				'post_title'   => 'Secret Human Draft',
				'post_content' => 'Draft body content.',
				'post_status'  => 'draft',
			)
		);

		wp_set_current_user( $this->ai_user_id );
		$this->assertTrue( current_user_can( 'read_post', $published_id ) );
		$this->assertTrue( current_user_can( 'read_post', $human_draft_id ) );

		$response = $this->dispatch_rest(
			'GET',
			'/ai-gatekeeper/v1/posts',
			array( 'status' => 'all' ),
			true
		);

		$this->assertSame( 200, $response->get_status() );
		$data  = $response->get_data();
		$posts = isset( $data['items'] ) ? $data['items'] : $data;
		$ids   = wp_list_pluck( $posts, 'id' );

		$this->assertContains( $published_id, $ids );
		$this->assertContains( $human_draft_id, $ids );
	}

	/**
	 * Test 2: AI can create a new draft and edit its own draft, but CANNOT publish it
	 * and CANNOT edit a human's draft or a published article directly.
	 */
	public function test_ai_draft_creation_editing_and_publish_guardrail() {
		wp_set_current_user( $this->ai_user_id );

		// AI attempts to create a post with 'publish' status -> forced to 'draft'.
		$ai_post_id = wp_insert_post(
			array(
				'post_author'  => $this->ai_user_id,
				'post_title'   => 'AI Generated Article',
				'post_content' => 'Initial AI draft paragraph.',
				'post_status'  => 'publish',
			)
		);

		$ai_post = get_post( $ai_post_id );
		$this->assertSame( 'draft', $ai_post->post_status, 'AI post creation must be forced to draft status.' );
		$this->assertTrue( current_user_can( 'edit_post', $ai_post_id ), 'AI must be able to edit its own draft.' );

		// AI edits its own draft and tries to publish -> remains draft.
		wp_update_post(
			array(
				'ID'           => $ai_post_id,
				'post_content' => 'Updated AI draft paragraph.',
				'post_status'  => 'publish',
			)
		);
		$ai_post_after = get_post( $ai_post_id );
		$this->assertSame( 'draft', $ai_post_after->post_status, 'AI must not be able to transition draft to publish.' );
		$this->assertSame( 'Updated AI draft paragraph.', $ai_post_after->post_content );

		// Human creates a draft and a published post -> AI cannot edit either directly.
		// Fixtures are created as the editor; as the AI they would be forced to draft.
		wp_set_current_user( $this->editor_id );
		$human_draft_id = self::factory()->post->create(
			array(
				'post_author' => $this->editor_id,
				'post_status' => 'draft',
			)
		);
		$published_id   = self::factory()->post->create(
			array(
				'post_author' => $this->ai_user_id,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'publish', get_post_status( $published_id ) );
		wp_set_current_user( $this->ai_user_id );

		$this->assertFalse( current_user_can( 'edit_post', $human_draft_id ), 'AI must not edit human-owned drafts directly.' );
		$this->assertFalse( current_user_can( 'edit_post', $published_id ), 'AI must not edit published posts directly, even if originally authored by AI.' );
		$this->assertFalse( current_user_can( 'publish_post', $ai_post_id ), 'AI must never have publish_post capability.' );

		// AI has upload_files capability for media uploads.
		$this->assertTrue( current_user_can( 'upload_files' ), 'AI must have upload_files capability.' );
	}

	/**
	 * Test 3: AI cannot create, edit, or moderate WordPress blog comments.
	 */
	public function test_ai_cannot_create_or_moderate_comments() {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $this->editor_id,
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( $this->ai_user_id );
		$this->assertFalse( current_user_can( 'moderate_comments' ) );

		// REST comment creation is blocked.
		$rest_response = $this->dispatch_rest(
			'POST',
			'/wp/v2/comments',
			array(
				'post'    => $post_id,
				'content' => 'AI trying to post a public blog comment.',
			),
			true
		);
		$this->assertSame( 403, $rest_response->get_status() );
	}

	/**
	 * Test 4: Full GitHub PR-style suggestion workflow:
	 * AI proposes change + explanation -> Human comments -> AI reads comment & updates suggestion ->
	 * Human merges suggestion -> Article updated -> Suggestion is permanently locked against further changes.
	 */
	public function test_github_pr_suggestion_dialogue_and_immutable_merge_lifecycle() {
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $this->editor_id,
				'post_title'   => 'Enterprise Security Guide',
				'post_content' => 'Our platform uses legacy passwords for authentication across all services.',
				'post_status'  => 'publish',
			)
		);

		// Step 1: AI creates a suggestion with an explanatory comment.
		$create_resp = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/posts/{$post_id}/suggestions",
			array(
				'target_field'   => 'post_content',
				'original_text'  => 'legacy passwords',
				'suggested_text' => 'hardware-backed passkeys',
				'comment'        => 'Updated terminology to reflect our 2026 zero-trust policy.',
			),
			true
		);

		$this->assertSame( 201, $create_resp->get_status() );
		$suggestion = $create_resp->get_data();
		$sug_id     = $suggestion['id'];

		$this->assertSame( 'open', $suggestion['status'] );
		$this->assertFalse( $suggestion['is_locked'] );
		$this->assertCount( 1, $suggestion['comments'] );
		$this->assertSame( 'ai', $suggestion['comments'][0]['author_type'] );
		$this->assertStringContainsString( 'wpai-diff-word-del', $suggestion['diff_html'] );
		$this->assertStringContainsString( 'wpai-diff-word-ins', $suggestion['diff_html'] );

		// Step 2: AI cannot merge its own suggestion.
		$ai_merge_attempt = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}/merge",
			array(),
			true
		);
		$this->assertSame( 403, $ai_merge_attempt->get_status() );

		// Step 3: Human editor adds a review comment asking for a tweak.
		$human_comment_resp = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}/comments",
			array(
				'comment' => 'Can we mention FIDO2 explicitly here?',
			),
			false
		);
		$this->assertSame( 201, $human_comment_resp->get_status() );

		// Step 4: AI reads the suggestion (including human comment) and updates its proposed text.
		$read_resp = $this->dispatch_rest(
			'GET',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}",
			array(),
			true
		);
		$this->assertSame( 200, $read_resp->get_status() );
		$read_data = $read_resp->get_data();
		$this->assertCount( 2, $read_data['comments'] );
		$this->assertSame( 'human', $read_data['comments'][1]['author_type'] );
		$this->assertSame( 'Can we mention FIDO2 explicitly here?', $read_data['comments'][1]['comment_text'] );

		$update_resp = $this->dispatch_rest(
			'PATCH',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}",
			array(
				'suggested_text' => 'FIDO2 hardware-backed passkeys',
				'comment'        => 'Good call! Updated suggestion to specify FIDO2.',
			),
			true
		);
		$this->assertSame( 200, $update_resp->get_status() );
		$updated_data = $update_resp->get_data();
		$this->assertSame( 'FIDO2 hardware-backed passkeys', $updated_data['suggested_text'] );
		$this->assertCount( 3, $updated_data['comments'] );

		// Step 5: Human editor merges the suggestion.
		$merge_resp = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}/merge",
			array(
				'comment' => 'Looks great, merging now.',
			),
			false
		);
		$this->assertSame( 200, $merge_resp->get_status() );
		$merged_data = $merge_resp->get_data();
		$this->assertSame( 'merged', $merged_data['status'] );
		$this->assertTrue( $merged_data['is_locked'] );
		$this->assertNotEmpty( $merged_data['merged_at'] );

		// Verify the article content was updated cleanly.
		$updated_post = get_post( $post_id );
		$this->assertSame(
			'Our platform uses FIDO2 hardware-backed passkeys for authentication across all services.',
			$updated_post->post_content
		);

		// Step 6: Once merged, the suggestion CANNOT be changed or commented on anymore.
		$post_merge_edit = $this->dispatch_rest(
			'PATCH',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}",
			array(
				'suggested_text' => 'Trying to mutate after merge',
			),
			true
		);
		$this->assertSame( 409, $post_merge_edit->get_status() );
		$this->assertSame( 'suggestion_already_merged', $post_merge_edit->get_data()['code'] );

		$post_merge_comment = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}/comments",
			array(
				'comment' => 'Trying to comment after merge',
			),
			true
		);
		$this->assertSame( 409, $post_merge_comment->get_status() );

		$post_merge_reject = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/suggestions/{$sug_id}/reject",
			array(),
			false
		);
		$this->assertSame( 409, $post_merge_reject->get_status() );
	}
}
