<?php
/**
 * Tests: the admin menu badge counts the open suggestions in the user's
 * review queue with one cheap query, without the old cap of 100.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Admin_Menu_Badge
 */
class Test_Admin_Menu_Badge extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Inserts suggestion rows directly (fast; no diff or guard work needed).
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $count   Number of rows.
	 * @param string $status  Status.
	 * @return void
	 */
	private function add_suggestions( $post_id, $count, $status = 'open' ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$id = WP_AI_Gatekeeper_Database::insert_suggestion(
				array(
					'post_id'        => $post_id,
					'author_user_id' => $this->ai_user_id,
					'target_field'   => 'post_content',
					'original_text'  => 'Hello',
					'suggested_text' => 'Hi ' . $i,
					'status'         => $status,
				)
			);
			$this->assertNotWPError( $id );
		}
	}

	/**
	 * Test: the badge equals the queue's Open count, counts past 100, and
	 * ignores merged/rejected suggestions and posts the user cannot edit.
	 */
	public function test_badge_matches_the_queue_and_is_not_capped() {
		$post_a = $this->create_editor_post( array( 'post_content' => '<p>Hello</p>' ) );
		$post_b = $this->create_editor_post( array( 'post_content' => '<p>Hello</p>' ) );
		$this->add_suggestions( $post_a, 70 );
		$this->add_suggestions( $post_b, 35 );
		$this->add_suggestions( $post_b, 4, 'merged' );
		$this->add_suggestions( $post_b, 3, 'rejected' );

		// A private post of an administrator: editors cannot edit it.
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$private = self::factory()->post->create(
			array(
				'post_author' => $admin,
				'post_status' => 'private',
			)
		);
		$this->add_suggestions( $private, 2 );
		$restricted_editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $restricted_editor )->add_cap( 'edit_private_posts', false );

		$this->assertSame( 107, WP_AI_Gatekeeper_Admin_Menu::open_badge_count( $this->editor_id ) );
		$this->assertSame( 105, WP_AI_Gatekeeper_Admin_Menu::open_badge_count( $restricted_editor ) );

		foreach ( array( $this->editor_id, $restricted_editor ) as $user_id ) {
			$queue = WP_AI_Gatekeeper_Database::get_status_counts(
				array( 'post_ids' => WP_AI_Gatekeeper_Suggestion_Service::visible_post_ids( $user_id ) )
			);
			$this->assertSame( $queue['open'], WP_AI_Gatekeeper_Admin_Menu::open_badge_count( $user_id ), 'Badge equals the queue tab.' );
		}
	}

	/**
	 * Test: users without the menu get no badge and no queries.
	 */
	public function test_users_without_the_menu_cost_nothing() {
		global $wpdb;
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello</p>' ) );
		$this->add_suggestions( $post_id, 3 );

		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		user_can( $contributor, 'edit_others_posts' ); // Warm the user cache.

		$before = $wpdb->num_queries;
		$this->assertSame( 0, WP_AI_Gatekeeper_Admin_Menu::open_badge_count( $contributor ) );
		$this->assertSame( 0, WP_AI_Gatekeeper_Admin_Menu::open_badge_count( $this->ai_user_id ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * Test: the badge needs only a few queries, however many suggestions exist
	 * (it used to load and diff up to 100 suggestions with their threads).
	 */
	public function test_badge_uses_a_constant_number_of_queries() {
		global $wpdb;
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello</p>' ) );
		$this->add_suggestions( $post_id, 60 );

		$before = $wpdb->num_queries;
		$this->assertSame( 60, WP_AI_Gatekeeper_Admin_Menu::open_badge_count( $this->editor_id ) );
		$this->assertLessThanOrEqual( 4, $wpdb->num_queries - $before );
	}

	/**
	 * Test: the menu title shows the badge.
	 */
	public function test_menu_shows_the_badge() {
		global $menu;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello</p>' ) );
		$this->add_suggestions( $post_id, 2 );

		$menu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated admin menu for the test.
		wp_set_current_user( $this->editor_id );
		WP_AI_Gatekeeper_Admin_Menu::register_menus();

		$titles = wp_list_pluck( $menu, 0 );
		$this->assertContains( 'AI Gatekeeper <span class="awaiting-mod count-2"><span class="pending-count">2</span></span>', $titles );
	}
}
