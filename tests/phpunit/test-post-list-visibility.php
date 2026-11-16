<?php
/**
 * Tests: the post list only matches posts the caller may read, so `total`
 * and `total_pages` never count (and reveal) other people's drafts.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Post_List_Visibility
 */
class Test_Post_List_Visibility extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Contributor user ID.
	 *
	 * @var int
	 */
	private $contributor;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber;

	/**
	 * Post IDs by fixture name.
	 *
	 * @var array<string, int>
	 */
	private $posts = array();

	/**
	 * Fixtures: the editor's draft, the contributor's draft, two published posts.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->subscriber  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->posts['editor_draft']      = $this->create_editor_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Layoffs planned for Xylophonequartz division.</p>',
			)
		);
		$this->posts['contributor_draft'] = self::factory()->post->create(
			array(
				'post_author'  => $this->contributor,
				'post_status'  => 'draft',
				'post_content' => '<p>My own Xylophonequartz notes.</p>',
			)
		);
		$this->posts['published_a']       = $this->create_editor_post( array( 'post_content' => '<p>Public news one.</p>' ) );
		$this->posts['published_b']       = $this->create_editor_post( array( 'post_content' => '<p>Public news two.</p>' ) );
	}

	/**
	 * Lists posts as a given user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $params  Query parameters.
	 * @return array Response data.
	 */
	private function list_as( $user_id, array $params ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', '/ai-gatekeeper/v1/posts' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * Sorted IDs of a list response.
	 *
	 * @param array $data Response data.
	 * @return int[]
	 */
	private function ids( array $data ) {
		$ids = array_map( 'intval', wp_list_pluck( $data['items'], 'id' ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Sorted fixture IDs.
	 *
	 * @param string ...$names Fixture names.
	 * @return int[]
	 */
	private function fixtures( ...$names ) {
		$ids = array();
		foreach ( $names as $name ) {
			$ids[] = $this->posts[ $name ];
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Test: a subscriber's draft search cannot tell that someone else's draft
	 * contains a phrase (this used to return items=0, total=1).
	 */
	public function test_subscriber_draft_search_reveals_nothing() {
		$data = $this->list_as(
			$this->subscriber,
			array(
				'status' => 'draft',
				'search' => 'Xylophonequartz',
			)
		);

		$this->assertSame( array(), $data['items'] );
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( 0, $data['total_pages'] );
	}

	/**
	 * Test: without a search, the draft count does not include others' drafts.
	 */
	public function test_subscriber_draft_count_is_zero() {
		$data = $this->list_as( $this->subscriber, array( 'status' => 'draft' ) );

		$this->assertSame( 0, $data['total'] );
	}

	/**
	 * Test: a contributor finds their own draft, not the editor's.
	 */
	public function test_contributor_sees_only_own_drafts() {
		$search = $this->list_as(
			$this->contributor,
			array(
				'status' => 'draft',
				'search' => 'Xylophonequartz',
			)
		);
		$this->assertSame( $this->fixtures( 'contributor_draft' ), $this->ids( $search ) );
		$this->assertSame( 1, $search['total'] );

		$all = $this->list_as( $this->contributor, array( 'status' => 'all' ) );
		$this->assertSame( $this->fixtures( 'contributor_draft', 'published_a', 'published_b' ), $this->ids( $all ) );
		$this->assertSame( 3, $all['total'] );
	}

	/**
	 * Test: editors and the AI still see every draft.
	 */
	public function test_editor_and_ai_see_all_drafts() {
		$expected = $this->fixtures( 'editor_draft', 'contributor_draft' );
		$params   = array(
			'status' => 'draft',
			'search' => 'Xylophonequartz',
		);

		$editor = $this->list_as( $this->editor_id, $params );
		$this->assertSame( $expected, $this->ids( $editor ) );
		$this->assertSame( 2, $editor['total'] );

		$ai = $this->list_as( $this->ai_user_id, $params );
		$this->assertSame( $expected, $this->ids( $ai ) );
		$this->assertSame( 2, $ai['total'] );
	}

	/**
	 * Test: pagination counts only readable posts, and every page is full.
	 */
	public function test_pagination_matches_readable_posts() {
		$seen = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$data = $this->list_as(
				$this->contributor,
				array(
					'status'   => 'all',
					'per_page' => 1,
					'page'     => $page,
				)
			);
			$this->assertSame( 3, $data['total'] );
			$this->assertSame( 3, $data['total_pages'] );
			$this->assertCount( 1, $data['items'], "Page {$page} is full." );
			$seen[] = (int) $data['items'][0]['id'];
		}
		sort( $seen );
		$this->assertSame( $this->fixtures( 'contributor_draft', 'published_a', 'published_b' ), $seen );
	}

	/**
	 * Test: the restriction does not leak into other queries on the same request.
	 */
	public function test_restriction_is_removed_after_the_query() {
		$this->list_as( $this->subscriber, array( 'status' => 'draft' ) );

		$this->assertFalse( has_filter( 'posts_where' ) );
		$other = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'fields'      => 'ids',
			)
		);
		$this->assertSame( 2, $other->found_posts );
	}
}
