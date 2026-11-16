<?php
/**
 * Tests: plugin bootstrap. Hooks are registered, upgrades refresh the
 * database and the AI role after an update, and activation is repeatable.
 *
 * WordPress loads the plugin before a test starts, so these tests call the
 * setup functions again; registering the same callback twice is a no-op.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Bootstrap
 */
class Test_Bootstrap extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Test: the coordinator is a singleton and wires the REST routes.
	 */
	public function test_singleton_and_rest_routes() {
		$plugin = WP_AI_Gatekeeper::instance();
		$this->assertSame( $plugin, WP_AI_Gatekeeper::instance() );
		$this->assertSame( 10, has_action( 'rest_api_init', array( $plugin, 'register_rest_routes' ) ) );

		$routes = rest_get_server()->get_routes();
		foreach ( array( '/ai-gatekeeper/v1/posts', '/ai-gatekeeper/v1/suggestions', '/ai-gatekeeper/v1/suggestions/(?P<id>\d+)/merge' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	/**
	 * Data provider: hooks each subsystem must register (hook, callback).
	 *
	 * @return array
	 */
	public function hooks() {
		return array(
			'AI key auth'            => array( 'determine_current_user', array( 'WP_AI_Gatekeeper_Auth', 'determine_current_user' ) ),
			'AI key errors'          => array( 'rest_authentication_errors', array( 'WP_AI_Gatekeeper_Auth', 'rest_authentication_errors' ) ),
			'capability stripping'   => array( 'user_has_cap', array( 'WP_AI_Gatekeeper_Permissions', 'filter_user_has_cap' ) ),
			'meta capabilities'      => array( 'map_meta_cap', array( 'WP_AI_Gatekeeper_Permissions', 'filter_map_meta_cap' ) ),
			'REST guards'            => array( 'rest_pre_dispatch', array( 'WP_AI_Gatekeeper_Permissions', 'filter_rest_pre_dispatch' ) ),
			'draft-only writes'      => array( 'wp_insert_post_data', array( 'WP_AI_Gatekeeper_Permissions', 'filter_wp_insert_post_data' ) ),
			'no AI comments'         => array( 'preprocess_comment', array( 'WP_AI_Gatekeeper_Permissions', 'block_ai_comment_creation' ) ),
			'authorship'             => array( 'wp_after_insert_post', array( 'WP_AI_Gatekeeper_Authorship', 'on_after_insert_post' ) ),
			'updates'                => array( 'update_plugins_github.com', array( 'WP_AI_Gatekeeper_Updater', 'offer_update' ) ),
			'signed packages'        => array( 'upgrader_pre_download', array( 'WP_AI_Gatekeeper_Updater', 'download_verified_package' ) ),
			'stale editor saves'     => array( 'rest_request_before_callbacks', array( 'WP_AI_Gatekeeper_Stale_Editor_Guard', 'check_rest_save' ) ),
			'stale editor heartbeat' => array( 'heartbeat_received', array( 'WP_AI_Gatekeeper_Stale_Editor_Guard', 'heartbeat_received' ) ),
			'admin menu'             => array( 'admin_menu', array( 'WP_AI_Gatekeeper_Admin_Menu', 'register_menus' ) ),
			'settings forms'         => array( 'admin_init', array( 'WP_AI_Gatekeeper_Admin_Menu', 'handle_form_actions' ) ),
			'meta box'               => array( 'add_meta_boxes', array( 'WP_AI_Gatekeeper_Meta_Box', 'register_meta_box' ) ),
		);
	}

	/**
	 * Test: loading the plugin registers every guard, including the admin-only
	 * ones in WP Admin.
	 *
	 * @dataProvider hooks
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Expected callback.
	 */
	public function test_plugins_loaded_registers_hooks( $hook, $callback ) {
		set_current_screen( 'dashboard' ); // is_admin() for the admin-only subsystems.
		WP_AI_Gatekeeper::instance()->on_plugins_loaded();

		$this->assertNotFalse( has_filter( $hook, $callback ), "{$hook} is not hooked." );
	}

	/**
	 * Test: after an update (stored version differs), the tables are ensured
	 * and the AI role's capabilities are re-applied.
	 */
	public function test_upgrade_refreshes_role_after_update() {
		$role = get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG );
		$role->add_cap( 'publish_posts' ); // As if an older version had granted it.
		update_option( 'wp_ai_gatekeeper_version', '0.9.0' );

		WP_AI_Gatekeeper::maybe_upgrade();

		$this->assertSame( WP_AI_GATEKEEPER_VERSION, get_option( 'wp_ai_gatekeeper_version' ) );
		$this->assertSame( WP_AI_GATEKEEPER_DB_VERSION, get_option( 'wp_ai_gatekeeper_db_version' ) );
		$this->assertFalse( get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->has_cap( 'publish_posts' ) );
	}

	/**
	 * Test: when nothing changed, the upgrade check does no work.
	 */
	public function test_upgrade_is_a_no_op_when_current() {
		WP_AI_Gatekeeper::maybe_upgrade();
		get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->add_cap( 'publish_posts' );

		WP_AI_Gatekeeper::maybe_upgrade();

		$this->assertTrue( get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->has_cap( 'publish_posts' ), 'Role untouched: no upgrade ran.' );
		get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG )->remove_cap( 'publish_posts' );
	}

	/**
	 * Test: activation and deactivation can run repeatedly without side effects
	 * on existing data.
	 */
	public function test_activation_is_repeatable() {
		$keys_before = WP_AI_Gatekeeper_Auth::list_api_keys();

		WP_AI_Gatekeeper::activate();
		WP_AI_Gatekeeper::activate();
		WP_AI_Gatekeeper::deactivate();

		$this->assertSame( WP_AI_GATEKEEPER_VERSION, get_option( 'wp_ai_gatekeeper_version' ) );
		$this->assertSame( $this->ai_user_id, WP_AI_Gatekeeper_Auth::find_default_ai_user() );
		$this->assertCount( 1, get_users( array( 'role' => WP_AI_Gatekeeper_Auth::ROLE_SLUG ) ) );
		$this->assertSame( $keys_before, WP_AI_Gatekeeper_Auth::list_api_keys() );
	}
}
