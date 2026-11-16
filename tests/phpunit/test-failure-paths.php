<?php
/**
 * Tests: what happens when WordPress or the database refuses a write. Every
 * failure must be reported, and a failed merge must leave nothing half done.
 *
 * Database failures are simulated with the "query" filter: returning an empty
 * query makes $wpdb report a failure without touching the database.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Failure_Paths
 */
class Test_Failure_Paths extends WP_AI_Gatekeeper_TestCase {

	/**
	 * A published post by the editor.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Regex of SQL statements to fail.
	 *
	 * @var string
	 */
	private $fail_pattern = '';

	/**
	 * Creates the fixture post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id = $this->create_editor_post( array( 'post_content' => '<p>Use legacy passwords.</p>' ) );
		add_filter( 'query', array( $this, 'maybe_fail_query' ) );
	}

	/**
	 * Removes the failure filters.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'query', array( $this, 'maybe_fail_query' ) );
		remove_all_filters( 'wp_insert_post_empty_content' );
		WP_AI_Gatekeeper_Permissions::init(); // Re-adds the plugin's own filter.
		parent::tear_down();
	}

	/**
	 * Makes matching SQL statements fail.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function maybe_fail_query( $query ) {
		return ( '' !== $this->fail_pattern && preg_match( $this->fail_pattern, $query ) ) ? '' : $query;
	}

	/**
	 * Fails writes of a given kind to one plugin table.
	 *
	 * @param string $verb  INSERT or UPDATE.
	 * @param string $table Table name.
	 * @return void
	 */
	private function fail_writes( $verb, $table ) {
		$this->fail_pattern = '/^\s*' . $verb . '\s+(INTO\s+)?`?' . preg_quote( $table, '/' ) . '`?\s/i';
	}

	/**
	 * Makes WordPress refuse every post write.
	 *
	 * @return void
	 */
	private function refuse_post_writes() {
		add_filter( 'wp_insert_post_empty_content', '__return_true', 99 );
	}

	/**
	 * Creates an open AI suggestion.
	 *
	 * @return int Suggestion ID.
	 */
	private function suggestion() {
		$created = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$this->post_id,
			array(
				'original_text'  => 'legacy passwords',
				'suggested_text' => 'passkeys',
			),
			$this->ai_user_id
		);
		$this->assertIsArray( $created );
		return (int) $created['id'];
	}

	/**
	 * Asserts a WP_Error with a code and HTTP status.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @param mixed  $result Result.
	 * @return void
	 */
	private function assert_error( $code, $status, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/**
	 * Test: a suggestion that cannot be saved is reported.
	 */
	public function test_suggestion_insert_failure() {
		$this->fail_writes( 'INSERT', WP_AI_Gatekeeper_Database::get_suggestions_table() );

		$result = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$this->post_id,
			array(
				'original_text'  => 'legacy passwords',
				'suggested_text' => 'passkeys',
			),
			$this->ai_user_id
		);

		$this->assert_error( 'db_insert_failed', 500, $result );
	}

	/**
	 * Test: a revision that cannot be saved is reported and nothing changes.
	 */
	public function test_suggestion_update_failure() {
		$id = $this->suggestion();
		$this->fail_writes( 'UPDATE', WP_AI_Gatekeeper_Database::get_suggestions_table() );

		$result = WP_AI_Gatekeeper_Suggestion_Service::update_suggestion( $id, array( 'suggested_text' => 'hardware keys' ), $this->ai_user_id );

		$this->assert_error( 'db_update_failed', 500, $result );
		$this->fail_pattern = '';
		$this->assertSame( 'passkeys', WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( $id )['suggested_text'] );
	}

	/**
	 * Test: a comment that cannot be saved is reported.
	 */
	public function test_comment_insert_failure() {
		$id = $this->suggestion();
		$this->fail_writes( 'INSERT', WP_AI_Gatekeeper_Database::get_comments_table() );

		$result = WP_AI_Gatekeeper_Suggestion_Service::add_comment( $id, 'Looks good.', $this->editor_id );

		$this->assert_error( 'db_comment_insert_failed', 500, $result );
	}

	/**
	 * Test: when WordPress refuses to save the article, the merge fails and
	 * the suggestion stays open (nothing is half merged).
	 */
	public function test_merge_failure_leaves_suggestion_open() {
		$id = $this->suggestion();
		$this->refuse_post_writes();

		$result = WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id );

		$this->assertWPError( $result );
		$this->assertSame( '<p>Use legacy passwords.</p>', get_post( $this->post_id )->post_content );
		$this->assertSame( 'open', WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( $id )['status'] );
		$this->assertSame( 0, WP_AI_Gatekeeper_Stale_Editor_Guard::merge_count( $this->post_id ), 'No merge recorded.' );
	}

	/**
	 * Test: drafts and uploads that WordPress refuses to save are reported.
	 */
	public function test_post_and_attachment_write_failures_are_reported() {
		$draft = $this->dispatch_rest( 'POST', '/ai-gatekeeper/v1/posts', array( 'title' => 'Draft' ) );
		$this->assertSame( 201, $draft->get_status() );
		$this->refuse_post_writes();

		$this->assertGreaterThanOrEqual( 400, $this->dispatch_rest( 'POST', '/ai-gatekeeper/v1/posts', array( 'title' => 'Another' ) )->get_status() );
		$this->assertGreaterThanOrEqual( 400, $this->dispatch_rest( 'PUT', '/ai-gatekeeper/v1/posts/' . $draft->get_data()['id'], array( 'title' => 'Renamed' ) )->get_status() );

		wp_set_current_user( $this->ai_user_id );
		$request = new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=notes.txt' );
		$request->set_body( 'Plain text notes.' );
		add_filter( 'upload_mimes', array( $this, 'allow_txt' ) );
		$upload = rest_get_server()->dispatch( $request );
		remove_filter( 'upload_mimes', array( $this, 'allow_txt' ) );
		$this->assertGreaterThanOrEqual( 400, $upload->get_status() );
		$this->assertSame( 'Draft', get_post( $draft->get_data()['id'] )->post_title );
	}

	/**
	 * Allows .txt uploads for the test.
	 *
	 * @param array $mimes Allowed types.
	 * @return array
	 */
	public function allow_txt( $mimes ) {
		$mimes['txt'] = 'text/plain';
		return $mimes;
	}

	/**
	 * Test: if the AI account cannot be created, key creation reports it.
	 */
	public function test_ai_account_creation_failure() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->ai_user_id );
		delete_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION );
		add_filter( 'pre_user_login', '__return_empty_string' );

		$this->assertWPError( WP_AI_Gatekeeper_Auth::ensure_default_ai_user() );
		$this->assertWPError( WP_AI_Gatekeeper_Auth::generate_api_key( 'Bot' ) );
		remove_filter( 'pre_user_login', '__return_empty_string' );
	}

	/**
	 * Test: the AI role is created again if something removed it.
	 */
	public function test_missing_role_is_registered_again() {
		remove_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG );

		WP_AI_Gatekeeper_Auth::register_role();

		$role = get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertFalse( $role->has_cap( 'publish_posts' ) );
	}

	/**
	 * Test: on servers that hide the Authorization header from PHP, it is read
	 * from Apache's request headers, in either capitalization.
	 */
	public function test_authorization_header_from_apache() {
		foreach ( array( 'Authorization', 'authorization' ) as $name ) {
			$GLOBALS['wpai_test_apache_headers'] = array( $name => 'Bearer ' . $this->ai_api_key );
			$this->assertSame( $this->ai_user_id, $this->authenticate_with( array() ), $name );
		}
	}
}
