<?php
/**
 * Tests: humans only see suggestions (and their discussions) on posts they can
 * edit, the same people who may comment on and merge them. The AI sees all.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Suggestion_Visibility
 */
class Test_Suggestion_Visibility extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Contributor user ID.
	 *
	 * @var int
	 */
	private $contributor;

	/**
	 * Suggestion IDs by fixture name.
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Post IDs by fixture name.
	 *
	 * @var array<string, int>
	 */
	private $posts = array();

	/**
	 * Fixtures: suggestions on the contributor's own draft, the editor's draft,
	 * the editor's published post, and the editor's private post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		wp_set_current_user( $this->contributor );
		$this->posts['own_draft']        = self::factory()->post->create(
			array(
				'post_author'  => $this->contributor,
				'post_status'  => 'draft',
				'post_content' => '<p>Own draft text.</p>',
			)
		);
		$this->posts['editor_draft']     = $this->create_editor_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Editor draft text.</p>',
			)
		);
		$this->posts['editor_published'] = $this->create_editor_post( array( 'post_content' => '<p>Published text.</p>' ) );
		$this->posts['editor_private']   = $this->create_editor_post(
			array(
				'post_status'  => 'private',
				'post_content' => '<p>Private text.</p>',
			)
		);

		foreach ( $this->posts as $name => $post_id ) {
			$original           = array(
				'own_draft'        => 'Own draft',
				'editor_draft'     => 'Editor draft',
				'editor_published' => 'Published',
				'editor_private'   => 'Private',
			)[ $name ];
			$created            = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
				$post_id,
				array(
					'original_text'  => $original,
					'suggested_text' => $original . ' (revised)',
				),
				$this->ai_user_id
			);
			$this->ids[ $name ] = $created['id'];
		}
	}

	/**
	 * GET a route as a given user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $route   Route.
	 * @param array  $params  Query params.
	 * @return WP_REST_Response
	 */
	private function get_as( $user_id, $route, array $params = array() ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test: a contributor's global list contains only suggestions on posts they can edit.
	 */
	public function test_contributor_global_list_only_shows_editable_posts() {
		$data = $this->get_as( $this->contributor, '/ai-gatekeeper/v1/suggestions', array( 'status' => 'all' ) )->get_data();

		$this->assertSame( array( $this->ids['own_draft'] ), wp_list_pluck( $data['items'], 'id' ) );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $data['counts']['all'] );
		$this->assertSame( 1, $data['counts']['open'] );
	}

	/**
	 * Test: filtering keeps pagination consistent.
	 */
	public function test_pagination_matches_filtered_results() {
		$data = $this->get_as(
			$this->contributor,
			'/ai-gatekeeper/v1/suggestions',
			array(
				'status'   => 'all',
				'per_page' => 1,
				'page'     => 2,
			)
		)->get_data();

		$this->assertSame( array(), $data['items'] );
		$this->assertSame( 1, $data['total'] );
	}

	/**
	 * Test: editors and the AI see everything.
	 */
	public function test_editor_and_ai_see_all() {
		$editor = $this->get_as( $this->editor_id, '/ai-gatekeeper/v1/suggestions', array( 'status' => 'all' ) )->get_data();
		$this->assertSame( 4, $editor['total'] );

		$ai = $this->get_as( $this->ai_user_id, '/ai-gatekeeper/v1/suggestions', array( 'status' => 'all' ) )->get_data();
		$this->assertSame( 4, $ai['total'] );
	}

	/**
	 * Test: per-post list, single suggestion, and preview require edit access for humans.
	 */
	public function test_contributor_cannot_read_suggestions_on_others_posts() {
		foreach ( array( 'editor_draft', 'editor_published', 'editor_private' ) as $name ) {
			$this->assertSame( 403, $this->get_as( $this->contributor, "/ai-gatekeeper/v1/posts/{$this->posts[ $name ]}/suggestions" )->get_status(), "per-post list: {$name}" );
			$this->assertSame( 403, $this->get_as( $this->contributor, "/ai-gatekeeper/v1/suggestions/{$this->ids[ $name ]}" )->get_status(), "single: {$name}" );
			$this->assertSame( 403, $this->get_as( $this->contributor, "/ai-gatekeeper/v1/suggestions/{$this->ids[ $name ]}/preview" )->get_status(), "preview: {$name}" );
		}
	}

	/**
	 * Test: contributors still see suggestions on their own drafts everywhere.
	 */
	public function test_contributor_sees_own_draft_suggestions() {
		$this->assertSame( 200, $this->get_as( $this->contributor, "/ai-gatekeeper/v1/posts/{$this->posts['own_draft']}/suggestions" )->get_status() );
		$this->assertSame( 200, $this->get_as( $this->contributor, "/ai-gatekeeper/v1/suggestions/{$this->ids['own_draft']}" )->get_status() );
		$this->assertSame( 200, $this->get_as( $this->contributor, "/ai-gatekeeper/v1/suggestions/{$this->ids['own_draft']}/preview" )->get_status() );
	}

	/**
	 * Test: subscribers cannot read editorial discussions on published posts.
	 */
	public function test_subscriber_cannot_read_published_post_discussions() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame( 403, $this->get_as( $subscriber, "/ai-gatekeeper/v1/posts/{$this->posts['editor_published']}/suggestions" )->get_status() );
		$this->assertSame( 403, $this->get_as( $subscriber, "/ai-gatekeeper/v1/suggestions/{$this->ids['editor_published']}" )->get_status() );
	}

	/**
	 * Test: the AI can still read per-post lists and single suggestions on any post it may read.
	 */
	public function test_ai_reads_suggestions_on_others_posts() {
		$this->assertSame( 200, $this->get_as( $this->ai_user_id, "/ai-gatekeeper/v1/posts/{$this->posts['editor_draft']}/suggestions" )->get_status() );
		$this->assertSame( 200, $this->get_as( $this->ai_user_id, "/ai-gatekeeper/v1/suggestions/{$this->ids['editor_draft']}" )->get_status() );
	}

	/**
	 * Test: the review queue follows per-post capabilities (custom role without private access).
	 */
	public function test_queue_hides_posts_the_reviewer_cannot_edit() {
		add_role(
			'limited_editor',
			'Limited Editor',
			array(
				'read'              => true,
				'edit_posts'        => true,
				'edit_others_posts' => true,
			)
		);
		$limited = self::factory()->user->create( array( 'role' => 'limited_editor' ) );
		wp_set_current_user( $limited );
		$_GET['status'] = 'all';

		ob_start();
		WP_AI_Gatekeeper_Admin_Menu::render_queue_page();
		$page = ob_get_clean();
		unset( $_GET['status'] );
		remove_role( 'limited_editor' );

		$this->assertStringContainsString( 'wpai-suggestion-' . $this->ids['editor_draft'] . '"', $page );
		$this->assertStringNotContainsString( 'wpai-suggestion-' . $this->ids['editor_private'] . '"', $page, 'No edit_private_posts: private post suggestions are hidden.' );
	}
}
