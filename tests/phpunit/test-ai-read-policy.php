<?php
/**
 * Tests: what the AI may read. Posts and pages by any author that are
 * published, draft, pending, or scheduled; never other authors' private
 * posts; pages are read-only; /wp/v2/posts keeps core's default.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_AI_Read_Policy
 */
class Test_AI_Read_Policy extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Creates a post by the editor.
	 *
	 * @param string $status Post status.
	 * @param string $type   Post type.
	 * @return int
	 */
	private function editor_item( $status, $type = 'post' ) {
		$args = array(
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => "Editor {$type} {$status}",
			'post_content' => "<p>Editor {$type} {$status} text.</p>",
		);
		if ( 'future' === $status ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}
		return $this->create_editor_post( $args );
	}

	/**
	 * Sends a request as the AI.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Parameters.
	 * @return WP_REST_Response
	 */
	private function as_ai( $method, $route, array $params = array() ) {
		return $this->dispatch_rest( $method, $route, $params, true );
	}

	/**
	 * Data provider: post type, status, whether the AI may read it.
	 *
	 * @return array
	 */
	public function read_cases() {
		return array(
			'published post' => array( 'post', 'publish', true ),
			'draft post'     => array( 'post', 'draft', true ),
			'pending post'   => array( 'post', 'pending', true ),
			'scheduled post' => array( 'post', 'future', true ),
			'private post'   => array( 'post', 'private', false ),
			'trashed post'   => array( 'post', 'trash', false ),
			'published page' => array( 'page', 'publish', true ),
			'draft page'     => array( 'page', 'draft', true ),
			'private page'   => array( 'page', 'private', false ),
			'reusable block' => array( 'wp_block', 'publish', false ),
		);
	}

	/**
	 * Test: read_post for other authors' content follows the policy.
	 *
	 * @dataProvider read_cases
	 *
	 * @param string $type     Post type.
	 * @param string $status   Post status.
	 * @param bool   $readable Expected.
	 */
	public function test_read_policy( $type, $status, $readable ) {
		$id = $this->editor_item( $status, $type );
		wp_set_current_user( $this->ai_user_id );

		$this->assertSame( $readable, current_user_can( 'read_post', $id ) );
		$this->assertFalse( current_user_can( 'edit_post', $id ), 'Never editable.' );
	}

	/**
	 * Test: attachments follow the post they belong to.
	 */
	public function test_attachments_follow_their_post() {
		$public     = $this->editor_item( 'publish' );
		$private    = $this->editor_item( 'private' );
		$unattached = self::factory()->attachment->create_object( 'a.png', 0, array( 'post_author' => $this->editor_id ) );
		$on_public  = self::factory()->attachment->create_object( 'b.png', $public, array( 'post_author' => $this->editor_id ) );
		$on_private = self::factory()->attachment->create_object( 'c.png', $private, array( 'post_author' => $this->editor_id ) );

		wp_set_current_user( $this->ai_user_id );
		$this->assertTrue( current_user_can( 'read_post', $unattached ) );
		$this->assertTrue( current_user_can( 'read_post', $on_public ) );
		$this->assertFalse( current_user_can( 'read_post', $on_private ) );
	}

	/**
	 * Test: other authors' private posts are not readable through any endpoint,
	 * and the AI cannot suggest changes to them.
	 */
	public function test_private_posts_are_hidden_everywhere() {
		$id = $this->editor_item( 'private' );

		$this->assertContains( $this->as_ai( 'GET', "/wp/v2/posts/{$id}" )->get_status(), array( 401, 403 ) );
		$this->assertSame( 403, $this->as_ai( 'GET', "/ai-gatekeeper/v1/posts/{$id}" )->get_status() );

		$listed = $this->as_ai( 'GET', '/wp/v2/posts', array( 'status' => 'private' ) );
		if ( 200 === $listed->get_status() ) {
			$this->assertNotContains( $id, wp_list_pluck( $listed->get_data(), 'id' ) );
		}

		$suggest = $this->as_ai(
			'POST',
			"/ai-gatekeeper/v1/posts/{$id}/suggestions",
			array(
				'original_text'  => 'text',
				'suggested_text' => 'words',
			)
		);
		$this->assertSame( 403, $suggest->get_status() );
	}

	/**
	 * Test: pending and scheduled posts can be read and receive suggestions.
	 */
	public function test_pending_and_scheduled_posts_accept_suggestions() {
		foreach ( array( 'pending', 'future' ) as $status ) {
			$id = $this->editor_item( $status );
			$this->assertSame( 200, $this->as_ai( 'GET', "/ai-gatekeeper/v1/posts/{$id}" )->get_status() );

			$suggest = $this->as_ai(
				'POST',
				"/ai-gatekeeper/v1/posts/{$id}/suggestions",
				array(
					'original_text'  => 'text',
					'suggested_text' => 'words',
				)
			);
			$this->assertSame( 201, $suggest->get_status(), $status );
		}
	}

	/**
	 * Test: pages can be read through core's endpoint but not changed.
	 */
	public function test_pages_are_read_only() {
		$published = $this->editor_item( 'publish', 'page' );
		$draft     = $this->editor_item( 'draft', 'page' );

		$list = $this->as_ai( 'GET', '/wp/v2/pages' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( $published, wp_list_pluck( $list->get_data(), 'id' ) );

		$single = $this->as_ai( 'GET', "/wp/v2/pages/{$draft}" );
		$this->assertSame( 200, $single->get_status() );
		$this->assertStringContainsString( 'Editor page draft text.', $single->get_data()['content']['rendered'] );

		$this->assertSame( 403, $this->as_ai( 'POST', "/wp/v2/pages/{$published}", array( 'title' => 'Changed' ) )->get_status() );
		$this->assertSame( 'Editor page publish', get_post( $published )->post_title );
	}

	/**
	 * Test: /wp/v2/posts keeps core's default (published only); other statuses
	 * are available on request.
	 */
	public function test_wp_v2_posts_keeps_core_default() {
		$published = $this->editor_item( 'publish' );
		$draft     = $this->editor_item( 'draft' );
		$pending   = $this->editor_item( 'pending' );

		$default = wp_list_pluck( $this->as_ai( 'GET', '/wp/v2/posts' )->get_data(), 'id' );
		$this->assertContains( $published, $default );
		$this->assertNotContains( $draft, $default );

		$asked = wp_list_pluck( $this->as_ai( 'GET', '/wp/v2/posts', array( 'status' => array( 'draft', 'pending' ) ) )->get_data(), 'id' );
		$this->assertContains( $draft, $asked );
		$this->assertContains( $pending, $asked );
	}
}
