<?php
/**
 * Tests: an editor opened before a merge cannot save its older copy of the
 * article over the merge (block editor saves and autosaves, classic editor
 * saves and autosaves), and Heartbeat reports the stale state.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Stale_Editor_Guard
 */
class Test_Stale_Editor_Guard extends WP_AI_Gatekeeper_TestCase {

	/**
	 * The editor's draft, with one merged AI suggestion.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Text of the draft after the merge.
	 *
	 * @var string
	 */
	private $merged_content;

	/**
	 * Creates a draft and merges one suggestion into it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id = $this->create_editor_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Use legacy passwords.</p>',
			)
		);
		$this->assertSame( 0, WP_AI_Gatekeeper_Stale_Editor_Guard::merge_count( $this->post_id ) );

		$created = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$this->post_id,
			array(
				'original_text'  => 'legacy passwords',
				'suggested_text' => 'passkeys',
			),
			$this->ai_user_id
		);
		$merged  = WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $created['id'], $this->editor_id );
		$this->assertNotWPError( $merged );

		$this->merged_content = '<p>Use passkeys.</p>';
		$this->assertSame( $this->merged_content, get_post( $this->post_id )->post_content );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Clears request state and the guard's per-request autosave flag.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$property = new ReflectionProperty( 'WP_AI_Gatekeeper_Stale_Editor_Guard', 'blocked_autosave_post' );
		$property->setAccessible( true );
		$property->setValue( null, 0 );
		parent::tear_down();
	}

	/**
	 * Sends a block editor style save.
	 *
	 * @param string   $route REST route.
	 * @param int|null $base  Merge count header, or null for none.
	 * @return WP_REST_Response
	 */
	private function save( $route, $base ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'content', '<p>Use legacy passwords.</p>' );
		if ( null !== $base ) {
			$request->set_header( WP_AI_Gatekeeper_Stale_Editor_Guard::HEADER, (string) $base );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Current content of the draft.
	 *
	 * @return string
	 */
	private function content() {
		clean_post_cache( $this->post_id );
		return get_post( $this->post_id )->post_content;
	}

	/**
	 * Test: each merge bumps the counter.
	 */
	public function test_merge_bumps_the_counter() {
		$this->assertSame( 1, WP_AI_Gatekeeper_Stale_Editor_Guard::merge_count( $this->post_id ) );
		WP_AI_Gatekeeper_Stale_Editor_Guard::record_merge( $this->post_id );
		$this->assertSame( 2, WP_AI_Gatekeeper_Stale_Editor_Guard::merge_count( $this->post_id ) );
	}

	/**
	 * Test: a block editor save from before the merge is refused.
	 */
	public function test_stale_block_editor_save_is_refused() {
		$response = $this->save( "/wp/v2/posts/{$this->post_id}", 0 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wpai_stale_editor', $response->get_data()['code'] );
		$this->assertSame( $this->merged_content, $this->content() );
	}

	/**
	 * Test: a block editor autosave from before the merge is refused. For the
	 * author's own draft, core writes autosaves straight into the post.
	 */
	public function test_stale_block_editor_autosave_is_refused() {
		$response = $this->save( "/wp/v2/posts/{$this->post_id}/autosaves", 0 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $this->merged_content, $this->content() );
	}

	/**
	 * Test: an editor loaded after the merge saves normally, and so do clients
	 * that do not send the header.
	 */
	public function test_current_editor_and_other_clients_save_normally() {
		$this->assertSame( 200, $this->save( "/wp/v2/posts/{$this->post_id}", 1 )->get_status() );
		$this->assertSame( '<p>Use legacy passwords.</p>', $this->content() );

		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => $this->merged_content,
			)
		);
		$this->assertSame( 200, $this->save( "/wp/v2/posts/{$this->post_id}", null )->get_status() );
		$this->assertSame( '<p>Use legacy passwords.</p>', $this->content() );
	}

	/**
	 * Test: the header does not affect reads or other posts.
	 */
	public function test_reads_and_other_posts_are_not_affected() {
		$read = new WP_REST_Request( 'GET', "/wp/v2/posts/{$this->post_id}" );
		$read->set_header( WP_AI_Gatekeeper_Stale_Editor_Guard::HEADER, '0' );
		$this->assertSame( 200, rest_get_server()->dispatch( $read )->get_status() );

		$other = $this->create_editor_post( array( 'post_status' => 'draft' ) );
		wp_set_current_user( $this->editor_id );
		$this->assertSame( 200, $this->save( "/wp/v2/posts/{$other}", 0 )->get_status() );
	}

	/**
	 * Test: the classic editor form carries the counter.
	 */
	public function test_classic_form_contains_the_counter() {
		ob_start();
		WP_AI_Gatekeeper_Stale_Editor_Guard::render_field( get_post( $this->post_id ) );
		$html = ob_get_clean();

		$this->assertSame( '<input type="hidden" name="wpai_merge_count" value="1" />', $html );
	}

	/**
	 * Test: a classic editor save from a stale form is stopped.
	 */
	public function test_stale_classic_save_is_refused() {
		$_POST = array(
			'post_ID'          => (string) $this->post_id,
			'wpai_merge_count' => '0',
		);

		$this->expectException( 'WPDieException' );
		WP_AI_Gatekeeper_Stale_Editor_Guard::check_classic_save();
	}

	/**
	 * Test: a classic save from a current form, or without the field, proceeds.
	 */
	public function test_current_classic_save_proceeds() {
		$_POST = array(
			'post_ID'          => (string) $this->post_id,
			'wpai_merge_count' => '1',
		);
		WP_AI_Gatekeeper_Stale_Editor_Guard::check_classic_save();

		$_POST = array( 'post_ID' => (string) $this->post_id );
		WP_AI_Gatekeeper_Stale_Editor_Guard::check_classic_save();

		$this->assertTrue( true, 'No wp_die.' );
	}

	/**
	 * Test: Heartbeat reports whether the editor is stale, only to users who
	 * can edit the post.
	 */
	public function test_heartbeat_reports_stale_state() {
		$this->assertSame(
			1,
			has_filter( 'heartbeat_received', array( 'WP_AI_Gatekeeper_Stale_Editor_Guard', 'heartbeat_received' ) ),
			'Runs before core autosave (priority 500).'
		);

		$response = WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received(
			array(),
			array(
				'wpai_merge_check' => array(
					'post_id'     => 0,
					'merge_count' => '0',
				),
			)
		);
		$this->assertSame( array(), $response, 'Unknown post: no answer.' );

		$response = WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received(
			array(),
			array(
				'wpai_merge_check' => array(
					'post_id'     => $this->post_id,
					'merge_count' => '0',
				),
			)
		);
		$this->assertSame( array( 'wpai_merge_check' => array( 'stale' => true ) ), $response );

		$response = WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received(
			array(),
			array(
				'wpai_merge_check' => array(
					'post_id'     => $this->post_id,
					'merge_count' => '1',
				),
			)
		);
		$this->assertSame( array( 'wpai_merge_check' => array( 'stale' => false ) ), $response );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received(
			array(),
			array(
				'wpai_merge_check' => array(
					'post_id'     => $this->post_id,
					'merge_count' => '0',
				),
			)
		);
		$this->assertSame( array(), $response );
	}

	/**
	 * Test: a classic autosave sent along with a stale Heartbeat check does
	 * not overwrite the merged draft.
	 */
	public function test_stale_classic_autosave_is_dropped() {
		WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received(
			array(),
			array(
				'wpai_merge_check' => array(
					'post_id'     => $this->post_id,
					'merge_count' => '0',
				),
				'wp_autosave'      => array( 'post_id' => $this->post_id ),
			)
		);
		// What core's autosave does for the author's own draft (wp_autosave() itself
		// defines DOING_AUTOSAVE, which would disable revisions for later tests).
		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => '<p>Use legacy passwords.</p>',
			)
		);

		$this->assertSame( $this->merged_content, $this->content() );
	}

	/**
	 * Test: a classic autosave from a current editor still saves.
	 */
	public function test_current_classic_autosave_saves() {
		WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received(
			array(),
			array(
				'wpai_merge_check' => array(
					'post_id'     => $this->post_id,
					'merge_count' => '1',
				),
				'wp_autosave'      => array( 'post_id' => $this->post_id ),
			)
		);
		// What core's autosave does for the author's own draft (wp_autosave() itself
		// defines DOING_AUTOSAVE, which would disable revisions for later tests).
		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => '<p>Use hardware keys.</p>',
			)
		);

		$this->assertSame( '<p>Use hardware keys.</p>', $this->content() );
	}

	/**
	 * Test: the editor script is loaded on the post editor with the merge
	 * count and messages, and not elsewhere.
	 */
	public function test_editor_script_is_enqueued_with_merge_count() {
		global $post;
		$post = get_post( $this->post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The edit screen's post.

		WP_AI_Gatekeeper_Stale_Editor_Guard::enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'wp-ai-gatekeeper-stale-editor', 'enqueued' ), 'Not on other screens.' );

		WP_AI_Gatekeeper_Stale_Editor_Guard::enqueue( 'post.php' );
		$this->assertTrue( wp_script_is( 'wp-ai-gatekeeper-stale-editor', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'wp-ai-gatekeeper-stale-editor', 'data' );
		$this->assertStringContainsString( '"postId":"' . $this->post_id . '"', $data );
		$this->assertStringContainsString( '"mergeCount":"1"', $data );
		$this->assertStringContainsString( '"header":"X-WPAI-Merge-Count"', $data );

		wp_dequeue_script( 'wp-ai-gatekeeper-stale-editor' );
		wp_deregister_script( 'wp-ai-gatekeeper-stale-editor' );
		$post = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
	}

	/**
	 * Test: pages and users who cannot edit the post do not get the script.
	 */
	public function test_editor_script_skips_pages_and_readers() {
		global $post;
		$post = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The edit screen's post.
		WP_AI_Gatekeeper_Stale_Editor_Guard::enqueue( 'post.php' );
		$this->assertFalse( wp_script_is( 'wp-ai-gatekeeper-stale-editor', 'enqueued' ) );

		$post = get_post( $this->post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The edit screen's post.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		WP_AI_Gatekeeper_Stale_Editor_Guard::enqueue( 'post.php' );
		$this->assertFalse( wp_script_is( 'wp-ai-gatekeeper-stale-editor', 'enqueued' ) );
		$post = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
	}

	/**
	 * Test: the classic form field is only added to posts.
	 */
	public function test_classic_field_only_on_posts() {
		ob_start();
		WP_AI_Gatekeeper_Stale_Editor_Guard::render_field( self::factory()->post->create_and_get( array( 'post_type' => 'page' ) ) );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Test: an earlier REST error is passed on unchanged, and Heartbeat
	 * requests without the plugin's data are left alone.
	 */
	public function test_unrelated_requests_pass_through() {
		$error   = new WP_Error( 'earlier', 'Earlier error' );
		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$this->post_id}" );
		$request->set_header( WP_AI_Gatekeeper_Stale_Editor_Guard::HEADER, '0' );
		$this->assertSame( $error, WP_AI_Gatekeeper_Stale_Editor_Guard::check_rest_save( $error, array(), $request ) );

		$response = array( 'other' => 1 );
		$this->assertSame( $response, WP_AI_Gatekeeper_Stale_Editor_Guard::heartbeat_received( $response, array( 'wp_autosave' => array() ) ) );
	}
}
