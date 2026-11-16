<?php
/**
 * Tests: uninstalling with the default setting (keep data). Deleting data
 * drops tables, which would end the test's database transaction, so that
 * path is checked with `wp plugin uninstall` on a throwaway WordPress site.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Uninstall
 */
class Test_Uninstall extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Test: uninstall removes settings, keys, the role, internal meta, and the
	 * AI account's Application Passwords, but keeps suggestions, the "Drafted
	 * by AI" record, and the AI account itself.
	 */
	public function test_uninstall_keeps_data_by_default() {
		global $wpdb;

		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );
		update_post_meta( $post_id, WP_AI_Gatekeeper_Authorship::DRAFTED_BY_META, $this->ai_user_id );
		update_post_meta( $post_id, WP_AI_Gatekeeper_Authorship::SCHEDULED_BY_META, $this->editor_id );
		WP_AI_Gatekeeper_Stale_Editor_Guard::record_merge( $post_id );
		WP_Application_Passwords::create_new_application_password( $this->ai_user_id, array( 'name' => 'Agent' ) );
		$this->assertNotEmpty( WP_Application_Passwords::get_user_application_passwords( $this->ai_user_id ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-ai-gatekeeper/wp-ai-gatekeeper.php' );
		}
		include WP_AI_GATEKEEPER_PLUGIN_DIR . 'uninstall.php';

		// Removed.
		$this->assertFalse( get_option( WP_AI_Gatekeeper_Auth::KEYS_OPTION ) );
		$this->assertFalse( get_option( WP_AI_Gatekeeper_Authorship::SETTINGS_OPTION ) );
		$this->assertNull( get_role( WP_AI_Gatekeeper_Auth::ROLE_SLUG ) );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $this->ai_user_id ) );
		$this->assertSame( '', get_post_meta( $post_id, WP_AI_Gatekeeper_Stale_Editor_Guard::META, true ) );
		$this->assertSame( '', get_post_meta( $post_id, WP_AI_Gatekeeper_Authorship::SCHEDULED_BY_META, true ) );

		// Kept.
		$this->assertSame( (string) $this->ai_user_id, get_post_meta( $post_id, WP_AI_Gatekeeper_Authorship::DRAFTED_BY_META, true ) );
		$this->assertInstanceOf( 'WP_User', get_userdata( $this->ai_user_id ) );
		$table = WP_AI_Gatekeeper_Database::get_suggestions_table();
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checking the schema.
		$this->assertSame( '<p>Hello world</p>', get_post( $post_id )->post_content );
	}
}
