<?php
/**
 * Tests for the admin settings form handler, admin pages, and suggestion cards.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Admin
 */
class Test_Admin extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Create an admin; turn redirects into exceptions.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
	}

	/**
	 * Clean up request globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
	}

	/**
	 * Redirect filter that aborts the request.
	 *
	 * @param string $location Redirect target.
	 * @throws WPAI_Test_Redirect Always.
	 */
	public function throw_on_redirect( $location ) {
		throw new WPAI_Test_Redirect( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only, never output.
	}

	/**
	 * Submit the settings form as the current user.
	 *
	 * @param array $fields     Form fields (wpai_action etc.).
	 * @param bool  $with_nonce Whether to include a valid nonce.
	 * @return string|null Redirect location, or null if the handler did nothing.
	 */
	private function submit( array $fields, $with_nonce = true ) {
		if ( $with_nonce ) {
			$fields['wpai_settings_nonce'] = wp_create_nonce( 'wpai_settings_action' );
		}
		$_POST    = $fields;
		$_REQUEST = $fields;

		try {
			WP_AI_Gatekeeper_Admin_Menu::handle_form_actions();
		} catch ( WPAI_Test_Redirect $redirect ) {
			return $redirect->getMessage();
		}
		return null;
	}

	/**
	 * Capture output of a callable.
	 *
	 * @param callable $render Renderer.
	 * @return string
	 */
	private function render( callable $render ) {
		ob_start();
		$render();
		return ob_get_clean();
	}

	// ---------------------------------------------------------------
	// Settings form handler.
	// ---------------------------------------------------------------

	/**
	 * Test: admin creates a key; it is shown exactly once on the settings page.
	 */
	public function test_create_key_and_show_it_once() {
		wp_set_current_user( $this->admin_id );
		$location = $this->submit(
			array(
				'wpai_action' => 'create_key',
				'key_label'   => 'Bot <b>One</b>',
				'ai_user_id'  => $this->ai_user_id,
			)
		);
		$this->assertStringContainsString( 'updated=key_created', $location );

		$keys = WP_AI_Gatekeeper_Auth::list_api_keys();
		$this->assertSame( 'Bot One', $keys[0]['name'] );

		$page = $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_settings_page' ) );
		$this->assertMatchesRegularExpression( '#<code class="wpai-raw-key-display">wpai_[0-9a-f]{32}</code>#', $page );

		$again = $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_settings_page' ) );
		$this->assertStringNotContainsString( 'wpai-raw-key-display', $again, 'The raw key is shown only once.' );
		$this->assertStringContainsString( 'Bot One', $again );
	}

	/**
	 * Test: revoke and delete through the form.
	 */
	public function test_revoke_and_delete_key() {
		wp_set_current_user( $this->admin_id );
		$key_id = WP_AI_Gatekeeper_Auth::list_api_keys()[0]['id'];

		$this->assertStringContainsString(
			'key_revoked',
			$this->submit(
				array(
					'wpai_action' => 'revoke_key',
					'key_id'      => $key_id,
				)
			)
		);
		$this->assertTrue( WP_AI_Gatekeeper_Auth::list_api_keys()[0]['revoked'] );
		$this->assertStringContainsString( 'Revoked', $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_settings_page' ) ) );

		$this->submit(
			array(
				'wpai_action' => 'delete_key',
				'key_id'      => $key_id,
			)
		);
		$this->assertSame( array(), WP_AI_Gatekeeper_Auth::list_api_keys() );
	}

	/**
	 * Test: the authorship setting can be turned off and on.
	 */
	public function test_save_authorship_setting() {
		wp_set_current_user( $this->admin_id );

		$this->submit( array( 'wpai_action' => 'save_authorship' ) );
		$this->assertFalse( WP_AI_Gatekeeper_Authorship::is_enabled() );

		$this->submit(
			array(
				'wpai_action'                => 'save_authorship',
				'reassign_author_on_publish' => '1',
			)
		);
		$this->assertTrue( WP_AI_Gatekeeper_Authorship::is_enabled() );
	}

	/**
	 * Test: "delete all plugin data on uninstall" is off by default and can be
	 * turned on and off.
	 */
	public function test_save_uninstall_setting() {
		wp_set_current_user( $this->admin_id );
		$this->assertFalse( (bool) get_option( WP_AI_Gatekeeper_Admin_Menu::DELETE_DATA_OPTION, false ) );

		$location = $this->submit(
			array(
				'wpai_action'              => 'save_uninstall',
				'delete_data_on_uninstall' => '1',
			)
		);
		$this->assertStringContainsString( 'uninstall_saved', $location );
		$this->assertTrue( (bool) get_option( WP_AI_Gatekeeper_Admin_Menu::DELETE_DATA_OPTION ) );

		$this->submit( array( 'wpai_action' => 'save_uninstall' ) );
		$this->assertFalse( (bool) get_option( WP_AI_Gatekeeper_Admin_Menu::DELETE_DATA_OPTION ) );
	}

	/**
	 * Test: viewing the settings page never creates the AI account; without
	 * one, it explains how to create it and disables key generation.
	 */
	public function test_settings_page_does_not_create_ai_user() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->ai_user_id );
		delete_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION );
		wp_set_current_user( $this->admin_id );

		$page = $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_settings_page' ) );

		$this->assertSame( array(), get_users( array( 'role' => WP_AI_Gatekeeper_Auth::ROLE_SLUG ) ) );
		$this->assertFalse( get_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION ) );
		$this->assertStringContainsString( 'There is no AI service account yet.', $page );
		$this->assertMatchesRegularExpression( '/<input[^>]*value="Generate API Key"[^>]*disabled|<input[^>]*disabled[^>]*value="Generate API Key"/', $page );

		// The explicit action creates it.
		$this->submit( array( 'wpai_action' => 'sync_ai_user' ) );
		$this->assertCount( 1, get_users( array( 'role' => WP_AI_Gatekeeper_Auth::ROLE_SLUG ) ) );
	}

	/**
	 * Test: re-sync recreates the AI role.
	 */
	public function test_sync_ai_user() {
		wp_set_current_user( $this->admin_id );
		remove_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG );

		$this->assertStringContainsString( 'role_synced', $this->submit( array( 'wpai_action' => 'sync_ai_user' ) ) );
		$this->assertNotNull( get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG ) );
	}

	/**
	 * Test: a missing nonce is rejected.
	 */
	public function test_missing_nonce_is_rejected() {
		wp_set_current_user( $this->admin_id );
		$this->expectException( 'WPDieException' );
		$this->submit( array( 'wpai_action' => 'create_key' ), false );
	}

	/**
	 * Test: non-admins cannot use the form, even with a valid nonce.
	 */
	public function test_non_admin_is_ignored() {
		wp_set_current_user( $this->editor_id );
		$before = count( WP_AI_Gatekeeper_Auth::list_api_keys() );

		$this->assertNull( $this->submit( array( 'wpai_action' => 'create_key' ) ) );
		$this->assertCount( $before, WP_AI_Gatekeeper_Auth::list_api_keys() );
	}

	// ---------------------------------------------------------------
	// Pages.
	// ---------------------------------------------------------------

	/**
	 * Test: the settings page lists keys without secrets.
	 */
	public function test_settings_page_hides_secrets() {
		wp_set_current_user( $this->admin_id );
		$page   = $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_settings_page' ) );
		$record = get_option( WP_AI_Gatekeeper_Auth::KEYS_OPTION )[0];

		$this->assertStringContainsString( 'PHPUnit Test Key', $page );
		$this->assertStringContainsString( $record['key_prefix'], $page );
		$this->assertStringNotContainsString( $record['key_hash'], $page );
		$this->assertStringNotContainsString( $this->ai_api_key, $page );
	}

	/**
	 * Test: settings page is for admins only.
	 */
	public function test_settings_page_requires_admin() {
		wp_set_current_user( $this->editor_id );
		$this->expectException( 'WPDieException' );
		WP_AI_Gatekeeper_Admin_Menu::render_settings_page();
	}

	/**
	 * Test: the queue renders the filtered suggestions.
	 */
	public function test_queue_page_filters_by_status() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Alpha beta</p>' ) );
		$open    = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'original_text'  => 'Alpha',
				'suggested_text' => 'A',
			),
			$this->ai_user_id
		);
		$closed  = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'original_text'  => 'beta',
				'suggested_text' => 'B',
			),
			$this->ai_user_id
		);
		WP_AI_Gatekeeper_Suggestion_Service::reject_suggestion( $closed['id'], $this->editor_id );

		wp_set_current_user( $this->editor_id );
		$page = $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_queue_page' ) );
		$this->assertStringContainsString( 'wpai-suggestion-' . $open['id'] . '"', $page );
		$this->assertStringNotContainsString( 'wpai-suggestion-' . $closed['id'] . '"', $page );

		$_GET['status'] = 'rejected';
		$page           = $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_queue_page' ) );
		$this->assertStringContainsString( 'wpai-suggestion-' . $closed['id'] . '"', $page );

		$_GET = array(
			'status'  => 'open',
			'post_id' => 999999,
		);
		$this->assertStringContainsString( 'No suggestions match', $this->render( array( 'WP_AI_Gatekeeper_Admin_Menu', 'render_queue_page' ) ) );
	}

	/**
	 * Test: the queue is for editors, not contributors.
	 */
	public function test_queue_page_requires_editor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->expectException( 'WPDieException' );
		WP_AI_Gatekeeper_Admin_Menu::render_queue_page();
	}

	// ---------------------------------------------------------------
	// Meta box and cards.
	// ---------------------------------------------------------------

	/**
	 * Test: cards show review buttons only to humans who can edit the post; merged cards are locked.
	 */
	public function test_card_review_controls() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Alpha beta</p>' ) );
		$open    = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'original_text'  => 'Alpha',
				'suggested_text' => 'A',
				'comment'        => "First line\n\nSecond paragraph",
			),
			$this->ai_user_id
		);

		wp_set_current_user( $this->editor_id );
		$card = $this->render(
			function () use ( $open ) {
				WP_AI_Gatekeeper_Meta_Box::render_suggestion_card( $open, true );
			}
		);
		$this->assertStringContainsString( 'wpai-btn-merge', $card );
		$this->assertStringContainsString( 'data-post-id="' . $post_id . '"', $card, 'The editor-sync check needs the post ID.' );
		$this->assertStringContainsString( 'wpai-btn-preview', $card );
		$this->assertStringContainsString( '<p>Second paragraph</p>', $card, 'Comments keep their paragraphs.' );

		wp_set_current_user( $this->ai_user_id );
		$ai_card = $this->render(
			function () use ( $open ) {
				WP_AI_Gatekeeper_Meta_Box::render_suggestion_card( $open, false );
			}
		);
		$this->assertStringNotContainsString( 'wpai-btn-merge', $ai_card );

		wp_set_current_user( $this->editor_id ); // Merges save the post as the current user.
		$merged = WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $open['id'], $this->editor_id );
		$locked = $this->render(
			function () use ( $merged ) {
				WP_AI_Gatekeeper_Meta_Box::render_suggestion_card( $merged, false );
			}
		);
		$this->assertStringContainsString( 'wpai-locked-banner', $locked );
		$this->assertStringNotContainsString( 'wpai-comment-input', $locked );
	}

	/**
	 * Test: the meta box summarizes counts and lists the post's suggestions.
	 */
	public function test_meta_box_counts() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Alpha beta</p>' ) );
		foreach ( array( 'Alpha', 'beta' ) as $word ) {
			$created = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
				$post_id,
				array(
					'original_text'  => $word,
					'suggested_text' => strtoupper( $word ),
				),
				$this->ai_user_id
			);
		}
		WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $created['id'], $this->editor_id );

		wp_set_current_user( $this->editor_id );
		$box = $this->render(
			function () use ( $post_id ) {
				WP_AI_Gatekeeper_Meta_Box::render_meta_box( get_post( $post_id ) );
			}
		);
		$this->assertStringContainsString( '1 Open', $box );
		$this->assertStringContainsString( '1 Merged', $box );

		$empty = $this->create_editor_post( array() );
		$this->assertStringContainsString(
			'No AI suggestions',
			$this->render(
				function () use ( $empty ) {
					WP_AI_Gatekeeper_Meta_Box::render_meta_box( get_post( $empty ) );
				}
			)
		);
	}

	/**
	 * Test: JS config and asset loading on the right screens only.
	 */
	public function test_js_config_and_asset_loading() {
		wp_set_current_user( $this->editor_id );
		$_GET['post'] = '42';
		$config       = WP_AI_Gatekeeper_Meta_Box::get_js_config();
		$this->assertSame( 42, $config['postId'] );
		$this->assertStringContainsString( 'ai-gatekeeper/v1', $config['restRoot'] );
		$this->assertFalse( $config['isAiUser'] );
		$this->assertNotEmpty( $config['i18n']['showArticle'] );

		WP_AI_Gatekeeper_Meta_Box::enqueue_admin_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'wp-ai-gatekeeper-admin', 'enqueued' ) );

		WP_AI_Gatekeeper_Meta_Box::enqueue_admin_assets( 'post.php' );
		$this->assertTrue( wp_script_is( 'wp-ai-gatekeeper-admin', 'enqueued' ) );

		WP_AI_Gatekeeper_Meta_Box::enqueue_gutenberg_assets();
		$this->assertTrue( wp_script_is( 'wp-ai-gatekeeper-gutenberg', 'enqueued' ) );
	}

	/**
	 * Test: the meta box is registered for posts.
	 */
	public function test_meta_box_registration() {
		global $wp_meta_boxes;
		require_once ABSPATH . 'wp-admin/includes/template.php';

		WP_AI_Gatekeeper_Meta_Box::register_meta_box();
		$this->assertArrayHasKey( 'wp-ai-gatekeeper-suggestions', $wp_meta_boxes['post']['normal']['high'] );
	}
}
