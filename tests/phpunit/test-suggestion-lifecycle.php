<?php
/**
 * Tests for suggestion listing/filtering and lifecycle error paths.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Suggestion_Lifecycle
 */
class Test_Suggestion_Lifecycle extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Article ID.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Create an article.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id = $this->create_editor_post( array( 'post_content' => '<p>Alpha beta gamma delta epsilon.</p>' ) );
	}

	/**
	 * Create an AI suggestion and return its ID.
	 *
	 * @param string $original  Original text.
	 * @param string $suggested Suggested text.
	 * @param int    $post_id   Optional post ID.
	 * @return int
	 */
	private function suggest( $original, $suggested, $post_id = 0 ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id ? $post_id : $this->post_id,
			array(
				'original_text'  => $original,
				'suggested_text' => $suggested,
			),
			$this->ai_user_id
		);
		$this->assertIsArray( $result );
		return $result['id'];
	}

	/**
	 * Assert a WP_Error code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Result.
	 * @param int    $status Expected HTTP status.
	 */
	private function assertErrorCode( $code, $result, $status ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	// ---------------------------------------------------------------
	// Listing and filtering.
	// ---------------------------------------------------------------

	/**
	 * Test: global list filters by status, paginates, and returns counts.
	 */
	public function test_global_list_filters_paginates_and_counts() {
		$open1    = $this->suggest( 'Alpha', 'A' );
		$open2    = $this->suggest( 'beta', 'B' );
		$rejected = $this->suggest( 'gamma', 'C' );
		WP_AI_Gatekeeper_Suggestion_Service::reject_suggestion( $rejected, $this->editor_id );

		$open = $this->dispatch_rest( 'GET', '/ai-gatekeeper/v1/suggestions', array( 'status' => 'open' ), false )->get_data();
		$this->assertSame( 2, $open['total'] );
		$this->assertEqualsCanonicalizing( array( $open1, $open2 ), wp_list_pluck( $open['items'], 'id' ) );
		$this->assertSame( 2, $open['counts']['open'] );
		$this->assertSame( 1, $open['counts']['rejected'] );
		$this->assertSame( 3, $open['counts']['all'] );

		$page2 = $this->dispatch_rest(
			'GET',
			'/ai-gatekeeper/v1/suggestions',
			array(
				'status'   => 'all',
				'per_page' => 2,
				'page'     => 2,
			),
			false
		)->get_data();
		$this->assertCount( 1, $page2['items'] );
		$this->assertSame( 3, $page2['total'] );
	}

	/**
	 * Test: needs_ai_reply lists open suggestions whose last comment is from a human.
	 */
	public function test_needs_ai_reply_filter() {
		$asked   = $this->suggest( 'Alpha', 'A' );
		$replied = $this->suggest( 'beta', 'B' );
		WP_AI_Gatekeeper_Suggestion_Service::add_comment( $asked, 'Why?', $this->editor_id );
		WP_AI_Gatekeeper_Suggestion_Service::add_comment( $replied, 'Why?', $this->editor_id );
		WP_AI_Gatekeeper_Suggestion_Service::add_comment( $replied, 'Because.', $this->ai_user_id );

		$data = $this->dispatch_rest( 'GET', '/ai-gatekeeper/v1/suggestions', array( 'needs_ai_reply' => true ), true )->get_data();
		$this->assertSame( array( $asked ), wp_list_pluck( $data['items'], 'id' ) );
		$this->assertSame( 1, $data['counts']['needs_ai_reply'] );
		$this->assertTrue( $data['items'][0]['needs_ai_reply'] );
	}

	/**
	 * Test: the per-post list only returns that post's suggestions.
	 */
	public function test_per_post_list() {
		$other_post = $this->create_editor_post( array( 'post_content' => '<p>Other text.</p>' ) );
		$mine       = $this->suggest( 'Alpha', 'A' );
		$this->suggest( 'Other', 'Different', $other_post );

		$data = $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/posts/{$this->post_id}/suggestions", array(), true )->get_data();
		$this->assertSame( array( $mine ), wp_list_pluck( $data['items'], 'id' ) );
		$this->assertSame( 1, $data['counts']['all'] );
	}

	/**
	 * Test: reading a single suggestion: 404 for unknown, 403 for unreadable posts.
	 */
	public function test_single_suggestion_access() {
		$this->assertSame( 404, $this->dispatch_rest( 'GET', '/ai-gatekeeper/v1/suggestions/999999', array(), false )->get_status() );

		$private_draft = $this->create_editor_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Secret.</p>',
			)
		);
		$id            = $this->suggest( 'Secret', 'Hidden', $private_draft );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$resp = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/ai-gatekeeper/v1/suggestions/{$id}" ) );
		$this->assertSame( 403, $resp->get_status() );
	}

	// ---------------------------------------------------------------
	// Create errors.
	// ---------------------------------------------------------------

	/**
	 * Test: create validation errors.
	 */
	public function test_create_validation_errors() {
		$create = function ( array $params, $post_id = 0 ) {
			return WP_AI_Gatekeeper_Suggestion_Service::create_suggestion( $post_id ? $post_id : $this->post_id, $params, $this->ai_user_id );
		};

		$this->assertErrorCode( 'post_not_found', $create( array( 'original_text' => 'Alpha' ), 999999 ), 404 );
		$this->assertErrorCode(
			'invalid_target_field',
			$create(
				array(
					'target_field'   => 'post_password',
					'original_text'  => 'x',
					'suggested_text' => 'y',
				)
			),
			400
		);
		$this->assertErrorCode( 'missing_original_text', $create( array( 'suggested_text' => 'y' ) ), 400 );
		$this->assertErrorCode(
			'identical_suggestion',
			$create(
				array(
					'original_text'  => 'Alpha',
					'suggested_text' => 'Alpha',
				)
			),
			400
		);
		$this->assertErrorCode(
			'original_text_not_found',
			$create(
				array(
					'original_text'  => 'Omega',
					'suggested_text' => 'Z',
				)
			),
			422
		);
	}

	/**
	 * Test: context disambiguates repeated text; wrong context is reported.
	 */
	public function test_context_selects_the_right_occurrence() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>one cat, two cat</p>' ) );
		$result  = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'original_text'  => 'cat',
				'suggested_text' => 'dog',
				'context_before' => 'two ',
			),
			$this->ai_user_id
		);
		WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $result['id'], $this->editor_id );
		$this->assertSame( '<p>one cat, two dog</p>', get_post( $post_id )->post_content );

		$wrong = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'original_text'  => 'cat',
				'suggested_text' => 'cow',
				'context_before' => 'three ',
			),
			$this->ai_user_id
		);
		$this->assertErrorCode( 'original_text_not_found', $wrong, 422 );
		$this->assertStringContainsString( 'context', $wrong->get_error_message() );
	}

	/**
	 * Test: Windows line endings in suggestions still match LF content.
	 */
	public function test_crlf_suggestions_match_lf_content() {
		$post_id = $this->create_editor_post( array( 'post_content' => "Line one\nLine two" ) );
		$result  = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'original_text'  => "Line one\r\nLine two",
				'suggested_text' => "Line 1\r\nLine 2",
			),
			$this->ai_user_id
		);
		$this->assertIsArray( $result );
		WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $result['id'], $this->editor_id );
		$this->assertSame( "Line 1\nLine 2", get_post( $post_id )->post_content );
	}

	// ---------------------------------------------------------------
	// Update, comment, merge, reject errors.
	// ---------------------------------------------------------------

	/**
	 * Test: update error paths.
	 */
	public function test_update_errors() {
		$id = $this->suggest( 'Alpha', 'A' );
		$s  = 'WP_AI_Gatekeeper_Suggestion_Service';

		$this->assertErrorCode( 'suggestion_not_found', $s::update_suggestion( 999999, array(), $this->ai_user_id ), 404 );
		$this->assertErrorCode( 'missing_original_text', $s::update_suggestion( $id, array( 'original_text' => '' ), $this->ai_user_id ), 400 );
		$this->assertErrorCode( 'identical_suggestion', $s::update_suggestion( $id, array( 'suggested_text' => 'Alpha' ), $this->ai_user_id ), 400 );
		$this->assertErrorCode( 'original_text_not_found', $s::update_suggestion( $id, array( 'original_text' => 'Omega' ), $this->ai_user_id ), 422 );

		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->assertErrorCode( 'suggestion_update_forbidden', $s::update_suggestion( $id, array( 'suggested_text' => 'B' ), $contributor ), 403 );

		$s::reject_suggestion( $id, $this->editor_id );
		$this->assertErrorCode( 'suggestion_not_open', $s::update_suggestion( $id, array( 'suggested_text' => 'B' ), $this->ai_user_id ), 409 );
	}

	/**
	 * Test: humans who can edit the post may refine an AI suggestion; it stays attributed to the AI.
	 */
	public function test_editor_can_refine_ai_suggestion() {
		$id     = $this->suggest( 'Alpha', 'A' );
		$result = WP_AI_Gatekeeper_Suggestion_Service::update_suggestion( $id, array( 'suggested_text' => 'Aleph' ), $this->editor_id );

		$this->assertSame( 'Aleph', $result['suggested_text'] );
		$this->assertSame( $this->ai_user_id, $result['author_user_id'] );
		$this->assertSame( 'human', end( $result['comments'] )['author_type'] );
	}

	/**
	 * Test: comment error paths.
	 */
	public function test_comment_errors() {
		$id = $this->suggest( 'Alpha', 'A' );
		$s  = 'WP_AI_Gatekeeper_Suggestion_Service';

		$this->assertErrorCode( 'suggestion_not_found', $s::add_comment( 999999, 'Hi', $this->editor_id ), 404 );
		$this->assertErrorCode( 'empty_comment', $s::add_comment( $id, '   ', $this->editor_id ), 400 );

		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->assertErrorCode( 'suggestion_comment_forbidden', $s::add_comment( $id, 'Hi', $contributor ), 403 );

		$s::reject_suggestion( $id, $this->editor_id );
		$this->assertErrorCode( 'suggestion_not_open', $s::add_comment( $id, 'Hi', $this->editor_id ), 409 );
	}

	/**
	 * Test: comment text is stored as plain text.
	 */
	public function test_comment_html_is_stripped() {
		$id     = $this->suggest( 'Alpha', 'A' );
		$result = WP_AI_Gatekeeper_Suggestion_Service::add_comment( $id, 'Looks <script>alert(1)</script>good', $this->ai_user_id );
		$this->assertStringNotContainsString( '<script', end( $result['comments'] )['comment_text'] );
	}

	/**
	 * Test: merge error paths.
	 */
	public function test_merge_errors() {
		$s = 'WP_AI_Gatekeeper_Suggestion_Service';

		$this->assertErrorCode( 'ai_cannot_merge', $s::merge_suggestion( $this->suggest( 'Alpha', 'A' ), $this->ai_user_id ), 403 );
		$this->assertErrorCode( 'suggestion_not_found', $s::merge_suggestion( 999999, $this->editor_id ), 404 );

		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->assertErrorCode( 'merge_forbidden', $s::merge_suggestion( $this->suggest( 'beta', 'B' ), $contributor ), 403 );

		$rejected = $this->suggest( 'gamma', 'C' );
		$s::reject_suggestion( $rejected, $this->editor_id );
		$this->assertErrorCode( 'suggestion_not_open', $s::merge_suggestion( $rejected, $this->editor_id ), 409 );
	}

	/**
	 * Test: merging after the text changed reports a conflict and leaves the post alone.
	 */
	public function test_merge_conflict_after_edit() {
		$id = $this->suggest( 'Alpha', 'A' );
		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => '<p>Rewritten.</p>',
			)
		);

		$details = WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( $id );
		$this->assertFalse( $details['is_applicable'] );
		$this->assertNotEmpty( $details['conflict_reason'] );

		$this->assertErrorCode( 'suggestion_conflict', WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id ), 409 );
		$this->assertSame( '<p>Rewritten.</p>', get_post( $this->post_id )->post_content );
		$this->assertSame( 'open', WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( $id )['status'] );
	}

	/**
	 * Test: merge records the revision and the merging user.
	 */
	public function test_merge_records_revision_and_merger() {
		$id     = $this->suggest( 'Alpha', 'A' );
		$merged = WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id, 'Ship it' );

		$this->assertSame( $this->editor_id, $merged['merged_by'] );
		$this->assertSame( 'Senior Human Editor', $merged['merged_by_name'] );
		$this->assertSame( $this->post_id, wp_get_post_parent_id( $merged['merged_revision_id'] ) );
		$this->assertSame( 'Ship it', end( $merged['comments'] )['comment_text'] );
		$this->assertStringContainsString( 'A beta', $merged['updated_post']['post_content'] );
	}

	/**
	 * Test: reject error paths and the rejection record.
	 */
	public function test_reject_paths() {
		$s  = 'WP_AI_Gatekeeper_Suggestion_Service';
		$id = $this->suggest( 'Alpha', 'A' );

		$this->assertErrorCode( 'ai_cannot_reject', $s::reject_suggestion( $id, $this->ai_user_id ), 403 );
		$this->assertErrorCode( 'suggestion_not_found', $s::reject_suggestion( 999999, $this->editor_id ), 404 );

		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->assertErrorCode( 'reject_forbidden', $s::reject_suggestion( $id, $contributor ), 403 );

		$rejected = $s::reject_suggestion( $id, $this->editor_id );
		$this->assertSame( 'rejected', $rejected['status'] );
		$this->assertSame( 'Rejected this suggestion.', end( $rejected['comments'] )['comment_text'] );
		$this->assertErrorCode( 'suggestion_not_open', $s::reject_suggestion( $id, $this->editor_id ), 409 );

		$merged = $this->suggest( 'beta', 'B' );
		$s::merge_suggestion( $merged, $this->editor_id );
		$this->assertErrorCode( 'suggestion_already_merged', $s::reject_suggestion( $merged, $this->editor_id ), 409 );
	}

	/**
	 * Test: REST merge/reject routes refuse AI and unauthenticated users.
	 */
	public function test_review_routes_refuse_ai_and_anonymous() {
		$id = $this->suggest( 'Alpha', 'A' );

		$this->assertSame( 'rest_ai_review_forbidden', $this->dispatch_rest( 'POST', "/ai-gatekeeper/v1/suggestions/{$id}/reject", array(), true )->get_data()['code'] );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/ai-gatekeeper/v1/suggestions/{$id}/merge" ) )->get_status() );
		$comment = new WP_REST_Request( 'POST', "/ai-gatekeeper/v1/suggestions/{$id}/comments" );
		$comment->set_param( 'comment', 'Anonymous' ); // Parameters are validated before permissions.
		$this->assertSame( 401, rest_get_server()->dispatch( $comment )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/ai-gatekeeper/v1/suggestions/{$id}/merge" ) )->get_status() );
	}

	/**
	 * Test: the REST reject route works for editors.
	 */
	public function test_rest_reject_by_editor() {
		$id   = $this->suggest( 'Alpha', 'A' );
		$resp = $this->dispatch_rest( 'POST', "/ai-gatekeeper/v1/suggestions/{$id}/reject", array( 'comment' => 'Not now' ), false );

		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'Not now', end( $resp->get_data()['comments'] )['comment_text'] );
	}

	/**
	 * Test: hydrated suggestions handle deleted posts.
	 */
	public function test_suggestion_on_deleted_post() {
		$id = $this->suggest( 'Alpha', 'A' );
		wp_delete_post( $this->post_id, true );

		$details = WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( $id );
		$this->assertSame( 'deleted', $details['post_status'] );
		$this->assertFalse( $details['is_applicable'] );
		$this->assertErrorCode( 'post_not_found', WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id ), 404 );
	}

	/**
	 * Test: updating a suggestion whose article was deleted reports 404, and
	 * suggestions can be listed by author.
	 */
	public function test_update_after_post_deleted_and_author_filter() {
		$id = $this->suggest( 'Alpha', 'A' );
		wp_delete_post( $this->post_id, true );

		$this->assertErrorCode( 'post_not_found', WP_AI_Gatekeeper_Suggestion_Service::update_suggestion( $id, array( 'suggested_text' => 'B' ), $this->ai_user_id ), 404 );

		$by_ai    = WP_AI_Gatekeeper_Database::count_suggestions( array( 'author_user_id' => $this->ai_user_id ) );
		$by_human = WP_AI_Gatekeeper_Database::count_suggestions( array( 'author_user_id' => $this->editor_id ) );
		$this->assertSame( 1, $by_ai );
		$this->assertSame( 0, $by_human );
	}

	/**
	 * Test: without any suggestions, nobody's queue contains posts.
	 */
	public function test_visible_post_ids_without_suggestions() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WP_AI_Gatekeeper_Database::get_suggestions_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Test reset.

		$this->assertSame( array(), WP_AI_Gatekeeper_Suggestion_Service::visible_post_ids( $this->editor_id ) );
	}
}
