<?php
/**
 * Tests for AI guardrails on core REST routes, the plugin's draft and media
 * endpoints, capabilities, and comments.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Guardrails
 */
class Test_Guardrails extends WP_AI_Gatekeeper_TestCase {

	/**
	 * A 1x1 PNG.
	 */
	const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Remove files uploaded by tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Dispatch as the AI user with JSON-like params.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 * @return WP_REST_Response
	 */
	private function as_ai( $method, $route, array $params = array() ) {
		return $this->dispatch_rest( $method, $route, $params, true );
	}

	/**
	 * Create a draft owned by the AI.
	 *
	 * @return int Post ID.
	 */
	private function ai_draft() {
		wp_set_current_user( $this->ai_user_id );
		return self::factory()->post->create(
			array(
				'post_author' => $this->ai_user_id,
				'post_status' => 'draft',
			)
		);
	}

	// ---------------------------------------------------------------
	// Core /wp/v2 routes.
	// ---------------------------------------------------------------

	/**
	 * Test: AI cannot publish through /wp/v2/posts.
	 */
	public function test_core_posts_publish_is_forbidden() {
		$resp = $this->as_ai(
			'POST',
			'/wp/v2/posts',
			array(
				'title'  => 'X',
				'status' => 'publish',
			)
		);
		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'rest_ai_status_forbidden', $resp->get_data()['code'] );
	}

	/**
	 * Test: AI can create and edit its own draft through /wp/v2/posts.
	 */
	public function test_core_posts_own_draft_is_allowed() {
		$created = $this->as_ai(
			'POST',
			'/wp/v2/posts',
			array(
				'title'  => 'AI draft',
				'status' => 'draft',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];
		$this->assertSame( $this->ai_user_id, (int) get_post( $id )->post_author );

		$updated = $this->as_ai( 'POST', "/wp/v2/posts/{$id}", array( 'content' => 'Revised.' ) );
		$this->assertSame( 200, $updated->get_status() );
	}

	/**
	 * Test: AI cannot edit someone else's post through /wp/v2/posts.
	 */
	public function test_core_posts_editing_others_is_forbidden() {
		$post_id = $this->create_editor_post( array( 'post_status' => 'draft' ) );
		$resp    = $this->as_ai( 'POST', "/wp/v2/posts/{$post_id}", array( 'content' => 'Hijack' ) );
		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'rest_ai_cannot_edit_post', $resp->get_data()['code'] );
	}

	/**
	 * Test: AI cannot delete someone else's post.
	 */
	public function test_core_posts_deleting_others_is_forbidden() {
		$post_id = $this->create_editor_post( array() );
		$this->assertSame( 403, $this->as_ai( 'DELETE', "/wp/v2/posts/{$post_id}" )->get_status() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Test: a page ID on the posts route is rejected.
	 */
	public function test_core_posts_route_rejects_page_ids() {
		wp_set_current_user( $this->editor_id );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$resp = $this->as_ai( 'POST', "/wp/v2/posts/{$page_id}", array( 'content' => 'x' ) );
		$this->assertSame( 404, $resp->get_status() );
	}

	/**
	 * Sensitive core routes AI agents must not modify.
	 *
	 * @return array
	 */
	public function forbidden_route_cases() {
		return array(
			'create page'                 => array( 'POST', '/wp/v2/pages' ),
			'change settings'             => array( 'POST', '/wp/v2/settings' ),
			'edit own user profile'       => array( 'POST', '/wp/v2/users/me' ),
			'create application password' => array( 'POST', '/wp/v2/users/me/application-passwords' ),
			'create user'                 => array( 'POST', '/wp/v2/users' ),
		);
	}

	/**
	 * Test: sensitive core routes are blocked for AI.
	 *
	 * @dataProvider forbidden_route_cases
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 */
	public function test_sensitive_core_routes_are_forbidden( $method, $route ) {
		$resp = $this->as_ai(
			$method,
			$route,
			array(
				'title' => 'x',
				'name'  => 'x',
			)
		);
		$this->assertSame( 403, $resp->get_status() );
		$this->assertSame( 'rest_ai_route_forbidden', $resp->get_data()['code'] );
	}

	/**
	 * Test: the comments endpoint is completely blocked for AI, including reads.
	 */
	public function test_core_comments_endpoint_is_blocked() {
		$this->assertSame( 'rest_ai_comments_forbidden', $this->as_ai( 'GET', '/wp/v2/comments' )->get_data()['code'] );
	}

	/**
	 * Test: humans are not affected by the guard.
	 */
	public function test_humans_are_not_affected() {
		$resp = $this->dispatch_rest(
			'POST',
			'/wp/v2/pages',
			array(
				'title'  => 'Editor page',
				'status' => 'publish',
			),
			false
		);
		$this->assertSame( 201, $resp->get_status() );
	}

	// ---------------------------------------------------------------
	// Plugin draft endpoints.
	// ---------------------------------------------------------------

	/**
	 * Test: AI creates a draft via the plugin; HTML is filtered, terms assigned.
	 */
	public function test_plugin_create_draft() {
		$category = self::factory()->category->create();
		$resp     = $this->as_ai(
			'POST',
			'/ai-gatekeeper/v1/posts',
			array(
				'title'      => 'Fresh <b>draft</b>',
				'content'    => '<p>Body</p><script>alert(1)</script>',
				'excerpt'    => 'Short',
				'categories' => array( $category ),
			)
		);

		$this->assertSame( 201, $resp->get_status() );
		$post = get_post( $resp->get_data()['id'] );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( $this->ai_user_id, (int) $post->post_author );
		$this->assertSame( 'Fresh draft', $post->post_title );
		$this->assertStringNotContainsString( '<script', $post->post_content );
		$this->assertContains( $category, wp_get_post_categories( $post->ID ) );
	}

	/**
	 * Test: the plugin create endpoint refuses non-draft statuses for AI.
	 */
	public function test_plugin_create_draft_rejects_publish() {
		$resp = $this->as_ai(
			'POST',
			'/ai-gatekeeper/v1/posts',
			array(
				'title'  => 'X',
				'status' => 'publish',
			)
		);
		$this->assertSame( 403, $resp->get_status() );
	}

	/**
	 * Test: AI updates its own draft; status stays draft; featured image can be set.
	 */
	public function test_plugin_update_own_draft() {
		$post_id       = $this->ai_draft();
		$attachment_id = self::factory()->attachment->create_object( 'image.png', $post_id, array( 'post_mime_type' => 'image/png' ) );

		$resp = $this->as_ai(
			'PATCH',
			"/ai-gatekeeper/v1/posts/{$post_id}",
			array(
				'title'          => 'Updated',
				'featured_media' => $attachment_id,
			)
		);
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'Updated', get_post( $post_id )->post_title );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );

		$this->as_ai( 'PATCH', "/ai-gatekeeper/v1/posts/{$post_id}", array( 'featured_media' => 0 ) );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ), 'featured_media 0 removes it.' );
	}

	/**
	 * Test: AI cannot update someone else's post or publish its own via the plugin.
	 */
	public function test_plugin_update_forbidden_cases() {
		$editor_post = $this->create_editor_post( array( 'post_status' => 'draft' ) );
		$this->assertSame( 403, $this->as_ai( 'PATCH', "/ai-gatekeeper/v1/posts/{$editor_post}", array( 'title' => 'x' ) )->get_status() );

		$own = $this->ai_draft();
		$this->assertSame( 403, $this->as_ai( 'PATCH', "/ai-gatekeeper/v1/posts/{$own}", array( 'status' => 'publish' ) )->get_status() );
	}

	/**
	 * Test: humans may update any post they can edit through the plugin endpoint.
	 */
	public function test_plugin_update_by_human() {
		$post_id = $this->ai_draft();
		$resp    = $this->dispatch_rest( 'PATCH', "/ai-gatekeeper/v1/posts/{$post_id}", array( 'title' => 'Edited by human' ), false );
		$this->assertSame( 200, $resp->get_status() );
	}

	/**
	 * Test: permission errors for anonymous users and subscribers.
	 */
	public function test_plugin_endpoints_require_appropriate_users() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai-gatekeeper/v1/posts' ) )->get_status() );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$create = new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/posts' );
		$create->set_param( 'title', 'x' );
		$this->assertSame( 403, rest_get_server()->dispatch( $create )->get_status() );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' ) )->get_status() );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai-gatekeeper/v1/suggestions' ) )->get_status() );
	}

	/**
	 * Test: unknown and trashed posts are 404.
	 */
	public function test_plugin_unknown_and_trashed_posts_are_404() {
		$this->assertSame( 404, $this->as_ai( 'GET', '/ai-gatekeeper/v1/posts/999999' )->get_status() );

		$post_id = $this->create_editor_post( array() );
		wp_trash_post( $post_id );
		$this->assertSame( 404, $this->as_ai( 'GET', "/ai-gatekeeper/v1/posts/{$post_id}" )->get_status() );
		$this->assertSame( 404, $this->as_ai( 'PATCH', "/ai-gatekeeper/v1/posts/{$post_id}", array( 'title' => 'x' ) )->get_status() );
	}

	/**
	 * Test: listing filters by status, author, and search, and counts open suggestions.
	 */
	public function test_plugin_post_listing_filters() {
		$published = $this->create_editor_post( array( 'post_title' => 'Findable published' ) );
		$this->ai_draft();
		WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$published,
			array(
				'target_field'   => 'post_title',
				'original_text'  => 'Findable',
				'suggested_text' => 'Discoverable',
			),
			$this->ai_user_id
		);

		$drafts = $this->as_ai( 'GET', '/ai-gatekeeper/v1/posts', array( 'status' => 'draft' ) )->get_data();
		$this->assertSame( array( 'draft' ), array_values( array_unique( wp_list_pluck( $drafts['items'], 'status' ) ) ) );

		$by_editor = $this->as_ai( 'GET', '/ai-gatekeeper/v1/posts', array( 'author' => $this->editor_id ) )->get_data();
		$this->assertSame( array( $this->editor_id ), array_values( array_unique( wp_list_pluck( $by_editor['items'], 'author' ) ) ) );

		$found = $this->as_ai( 'GET', '/ai-gatekeeper/v1/posts', array( 'search' => 'Findable' ) )->get_data();
		$this->assertSame( array( $published ), wp_list_pluck( $found['items'], 'id' ) );
		$this->assertSame( 1, $found['items'][0]['open_suggestions_count'] );
	}

	// ---------------------------------------------------------------
	// Media uploads.
	// ---------------------------------------------------------------

	/**
	 * Build a raw-body upload request.
	 *
	 * @param string $body     File bytes.
	 * @param string $filename Filename for Content-Disposition ('' to omit).
	 * @param string $type     Content-Type.
	 * @return WP_REST_Request
	 */
	private function raw_upload( $body, $filename, $type ) {
		$request = new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' );
		$request->set_body( $body );
		$request->set_header( 'content_type', $type );
		if ( '' !== $filename ) {
			$request->set_header( 'content_disposition', 'attachment; filename="' . $filename . '"' );
		}
		return $request;
	}

	/**
	 * Test: AI uploads an image as a raw body.
	 */
	public function test_media_upload_raw_body() {
		$post_id = $this->ai_draft();
		$request = $this->raw_upload( base64_decode( self::PNG_BASE64 ), 'pixel.png', 'image/png' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'alt_text', 'A single pixel' );

		wp_set_current_user( $this->ai_user_id );
		$resp = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertSame( 'image/png', $data['mime_type'] );
		$this->assertSame( 'A single pixel', $data['alt_text'] );
		$this->assertSame( $this->ai_user_id, (int) get_post( $data['id'] )->post_author );
		$this->assertSame( $post_id, (int) get_post( $data['id'] )->post_parent );
	}

	/**
	 * Test: without a filename, one is derived from the Content-Type.
	 */
	public function test_media_upload_without_filename_uses_content_type() {
		wp_set_current_user( $this->ai_user_id );
		$resp = rest_get_server()->dispatch( $this->raw_upload( base64_decode( self::PNG_BASE64 ), '', 'image/png' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.

		$this->assertSame( 201, $resp->get_status() );
		$this->assertStringEndsWith( '.png', $resp->get_data()['source_url'] );
	}

	/**
	 * Test: PHP files are refused.
	 */
	public function test_media_upload_rejects_php() {
		wp_set_current_user( $this->ai_user_id );
		$resp = rest_get_server()->dispatch( $this->raw_upload( '<?php echo 1;', 'shell.php', 'application/x-php' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'media_upload_failed', $resp->get_data()['code'] );
	}

	/**
	 * Test: an empty upload is refused.
	 */
	public function test_media_upload_requires_a_file() {
		wp_set_current_user( $this->ai_user_id );
		$resp = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'missing_media_file', $resp->get_data()['code'] );
	}

	/**
	 * Test: attaching to a post the AI cannot edit silently detaches instead.
	 */
	public function test_media_upload_to_foreign_post_is_detached() {
		$editor_post = $this->create_editor_post( array() );
		$request     = $this->raw_upload( base64_decode( self::PNG_BASE64 ), 'pixel.png', 'image/png' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
		$request->set_param( 'post_id', $editor_post );

		wp_set_current_user( $this->ai_user_id );
		$resp = rest_get_server()->dispatch( $request );
		$this->assertSame( 0, (int) get_post( $resp->get_data()['id'] )->post_parent );
	}

	// ---------------------------------------------------------------
	// Capabilities and core write paths.
	// ---------------------------------------------------------------

	/**
	 * Test: AI may edit/delete only its own attachments and cannot read pages.
	 */
	public function test_attachment_and_page_capabilities() {
		$own     = self::factory()->attachment->create_object( 'a.png', 0, array( 'post_author' => $this->ai_user_id ) );
		$foreign = self::factory()->attachment->create_object( 'b.png', 0, array( 'post_author' => $this->editor_id ) );
		wp_set_current_user( $this->editor_id );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		wp_set_current_user( $this->ai_user_id );
		$this->assertTrue( current_user_can( 'edit_post', $own ) );
		$this->assertTrue( current_user_can( 'delete_post', $own ) );
		$this->assertFalse( current_user_can( 'edit_post', $foreign ) );
		$this->assertFalse( current_user_can( 'read_post', $page ) );
		$this->assertFalse( current_user_can( 'edit_post', 999999 ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'edit_theme_options' ) );
	}

	/**
	 * Test: direct wp_update_post on someone else's post is refused at the DB boundary.
	 */
	public function test_direct_update_of_foreign_post_is_refused() {
		$post_id = $this->create_editor_post( array( 'post_content' => 'Original' ) );

		wp_set_current_user( $this->ai_user_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Hijacked',
			)
		);
		$this->assertSame( 'Original', get_post( $post_id )->post_content );
	}

	/**
	 * Test: AI cannot create pages directly either.
	 */
	public function test_direct_page_insert_is_refused() {
		wp_set_current_user( $this->ai_user_id );
		$result = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Page',
				'post_content' => 'Body',
			),
			true
		);
		$this->assertWPError( $result );
	}

	/**
	 * Test: AI comment creation dies, and approval is refused.
	 */
	public function test_core_comment_hooks_block_ai() {
		$post_id = $this->create_editor_post( array() );
		wp_set_current_user( $this->ai_user_id );

		$this->assertWPError( WP_AI_Gatekeeper_Permissions::block_ai_comment_approval( 1 ) );

		$this->expectException( 'WPDieException' );
		wp_new_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'AI comment',
				'user_id'         => $this->ai_user_id,
			)
		);
	}

	/**
	 * Test: humans can comment normally.
	 */
	public function test_humans_can_comment() {
		$post_id = $this->create_editor_post( array() );
		wp_set_current_user( $this->editor_id );
		$this->assertSame( 1, WP_AI_Gatekeeper_Permissions::block_ai_comment_approval( 1 ) );
	}
}
