<?php
/**
 * Tests: publishing an AI draft makes the publishing human the author and
 * records the AI as "drafted by".
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Authorship
 */
class Test_Authorship extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Create a draft as the AI.
	 *
	 * @return int Post ID.
	 */
	private function ai_draft() {
		wp_set_current_user( $this->ai_user_id );
		$post_id = wp_insert_post(
			array(
				'post_author'  => $this->ai_user_id,
				'post_status'  => 'draft',
				'post_title'   => 'AI draft',
				'post_content' => 'Drafted by the AI.',
			)
		);
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		return $post_id;
	}

	/**
	 * Test: publishing an AI draft makes the publisher the author.
	 */
	public function test_publishing_ai_draft_makes_publisher_the_author() {
		$post_id = $this->ai_draft();

		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( $this->editor_id, (int) get_post( $post_id )->post_author );
		$this->assertSame( $this->ai_user_id, WP_AI_Gatekeeper_Authorship::drafted_by( $post_id ) );
	}

	/**
	 * Test: publishing through the REST API (block editor) reassigns the author and the response shows it.
	 */
	public function test_publishing_via_rest_reassigns_author() {
		$post_id = $this->ai_draft();

		$resp = $this->dispatch_rest( 'POST', "/wp/v2/posts/{$post_id}", array( 'status' => 'publish' ), false );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( $this->editor_id, $resp->get_data()['author'] );
		$this->assertSame( $this->ai_user_id, WP_AI_Gatekeeper_Authorship::drafted_by( $post_id ) );
	}

	/**
	 * Test: a scheduled AI draft is credited to whoever scheduled it when cron publishes it.
	 */
	public function test_scheduled_post_is_credited_to_the_scheduler() {
		$post_id = $this->ai_draft();

		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'edit_date'     => true, // Otherwise WordPress resets a draft's date to now.
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $this->ai_user_id, (int) get_post( $post_id )->post_author, 'Author changes on publish, not on scheduling.' );

		wp_set_current_user( 0 ); // Cron runs without a user.
		wp_publish_post( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( $this->editor_id, (int) get_post( $post_id )->post_author );
		$this->assertSame( $this->ai_user_id, WP_AI_Gatekeeper_Authorship::drafted_by( $post_id ) );
	}

	/**
	 * Test: without a known human publisher, the AI stays the author.
	 */
	public function test_unknown_publisher_leaves_author_unchanged() {
		$post_id = $this->ai_draft();

		wp_set_current_user( 0 );
		wp_publish_post( $post_id );

		$this->assertSame( $this->ai_user_id, (int) get_post( $post_id )->post_author );
		$this->assertSame( 0, WP_AI_Gatekeeper_Authorship::drafted_by( $post_id ) );
	}

	/**
	 * Test: the setting can turn reassignment off.
	 */
	public function test_disabled_setting_keeps_ai_as_author() {
		update_option( WP_AI_Gatekeeper_Authorship::SETTINGS_OPTION, array( 'reassign_author_on_publish' => false ) );
		$post_id = $this->ai_draft();

		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( $this->ai_user_id, (int) get_post( $post_id )->post_author );
	}

	/**
	 * Test: the setting is on by default.
	 */
	public function test_enabled_by_default() {
		delete_option( WP_AI_Gatekeeper_Authorship::SETTINGS_OPTION );
		$this->assertTrue( WP_AI_Gatekeeper_Authorship::is_enabled() );
	}

	/**
	 * Test: human-written posts and updates of published posts are left alone.
	 */
	public function test_human_posts_and_republishing_are_untouched() {
		$human_post = $this->create_editor_post( array( 'post_title' => 'Human' ) );
		$this->assertSame( 0, WP_AI_Gatekeeper_Authorship::drafted_by( $human_post ) );

		$post_id = $this->ai_draft();
		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// A second editor updates the published post: authorship must not move again.
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Edited later.',
			)
		);
		$this->assertSame( $this->editor_id, (int) get_post( $post_id )->post_author );
	}

	/**
	 * Test: the plugin API still reports a reassigned post as AI-drafted.
	 */
	public function test_api_reports_reassigned_post_as_ai_drafted() {
		$post_id = $this->ai_draft();
		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$data = $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/posts/{$post_id}", array(), true )->get_data();
		$this->assertSame( $this->editor_id, $data['author'] );
		$this->assertTrue( $data['is_ai_authored'] );
		$this->assertSame( $this->ai_user_id, $data['drafted_by'] );
	}

	/**
	 * Test: the posts list shows the AI credit.
	 */
	public function test_posts_list_column_shows_ai_credit() {
		$post_id = $this->ai_draft();
		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$columns = WP_AI_Gatekeeper_Authorship::add_column( array( 'author' => 'Author' ) );
		$this->assertArrayHasKey( WP_AI_Gatekeeper_Authorship::COLUMN, $columns );

		ob_start();
		WP_AI_Gatekeeper_Authorship::render_column( WP_AI_Gatekeeper_Authorship::COLUMN, $post_id );
		$this->assertStringContainsString( 'AI Editorial Assistant', ob_get_clean() );
	}

	/**
	 * Test: the column is added even without an Author column, other columns
	 * are ignored, unpublished AI drafts show the AI, and human posts show "No".
	 */
	public function test_posts_list_column_edge_cases() {
		$columns = WP_AI_Gatekeeper_Authorship::add_column( array( 'title' => 'Title' ) );
		$this->assertSame( array( 'title', WP_AI_Gatekeeper_Authorship::COLUMN ), array_keys( $columns ) );

		$render = static function ( $column, $post_id ) {
			ob_start();
			WP_AI_Gatekeeper_Authorship::render_column( $column, $post_id );
			return ob_get_clean();
		};
		$draft  = $this->ai_draft();
		$human  = $this->create_editor_post( array() );

		$this->assertSame( '', $render( 'title', $draft ) );
		$this->assertStringContainsString( 'AI Editorial Assistant', $render( WP_AI_Gatekeeper_Authorship::COLUMN, $draft ) );
		$this->assertStringContainsString( 'screen-reader-text">No<', $render( WP_AI_Gatekeeper_Authorship::COLUMN, $human ) );
	}
}
