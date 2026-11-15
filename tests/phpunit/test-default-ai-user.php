<?php
/**
 * Tests: finding or creating the default AI account never changes the role of
 * an existing user (previously an administrator named "ai-assistant" was
 * demoted on activation and on every settings page view).
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Default_Ai_User
 */
class Test_Default_Ai_User extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Start from a site without any AI account.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( $this->ai_user_id );
		delete_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION );
		$this->assertSame( array(), get_users( array( 'role' => WP_AI_Gatekeeper_Auth::ROLE_SLUG ) ) );
	}

	/**
	 * Create a human user with a given login.
	 *
	 * @param string $login Login.
	 * @param string $role  Role.
	 * @param string $email Optional email.
	 * @return int
	 */
	private function human( $login, $role = 'administrator', $email = '' ) {
		return self::factory()->user->create(
			array(
				'user_login' => $login,
				'role'       => $role,
				'user_email' => $email ? $email : $login . '@people.example',
			)
		);
	}

	/**
	 * Assert a user has exactly these roles.
	 *
	 * @param string[] $roles   Expected roles.
	 * @param int      $user_id User ID.
	 */
	private function assertRoles( array $roles, $user_id ) {
		clean_user_cache( $user_id );
		$this->assertSame( $roles, array_values( get_userdata( $user_id )->roles ) );
	}

	/**
	 * Test: an administrator named ai-assistant keeps their role; the AI gets another login.
	 */
	public function test_existing_administrator_is_not_demoted() {
		$admin = $this->human( 'ai-assistant' );

		WP_AI_Gatekeeper_Auth::activate();
		$ai_id = (int) get_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION );

		$this->assertRoles( array( 'administrator' ), $admin );
		$this->assertNotSame( $admin, $ai_id );
		$this->assertSame( 'ai-assistant-2', get_userdata( $ai_id )->user_login );
		$this->assertRoles( array( WP_AI_Gatekeeper_Auth::ROLE_SLUG ), $ai_id );
	}

	/**
	 * Test: repeated activation and settings page views reuse the same account.
	 */
	public function test_repeated_calls_reuse_the_account() {
		$this->human( 'ai-assistant' );
		$first = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();

		WP_AI_Gatekeeper_Auth::activate();
		$this->assertSame( $first, WP_AI_Gatekeeper_Auth::ensure_default_ai_user() );
		$this->assertCount( 1, get_users( array( 'role' => WP_AI_Gatekeeper_Auth::ROLE_SLUG ) ) );
	}

	/**
	 * Test: an admin's deliberate role change of the AI account is respected.
	 */
	public function test_role_change_by_admin_is_respected() {
		$original = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();
		get_userdata( $original )->set_role( 'editor' );

		$replacement = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();

		$this->assertRoles( array( 'editor' ), $original );
		$this->assertNotSame( $original, $replacement );
		$this->assertRoles( array( WP_AI_Gatekeeper_Auth::ROLE_SLUG ), $replacement );
	}

	/**
	 * Test: if the setting is lost, an existing AI account is found by role instead of duplicated.
	 */
	public function test_existing_ai_account_is_found_by_role() {
		$custom = self::factory()->user->create(
			array(
				'user_login' => 'editorial-bot',
				'role'       => WP_AI_Gatekeeper_Auth::ROLE_SLUG,
			)
		);

		$this->assertSame( $custom, WP_AI_Gatekeeper_Auth::ensure_default_ai_user() );
		$this->assertSame( $custom, (int) get_option( WP_AI_Gatekeeper_Auth::DEFAULT_USER_OPTION ) );
	}

	/**
	 * Test: the first free login is used.
	 */
	public function test_first_free_login_is_used() {
		$this->human( 'ai-assistant' );
		$this->human( 'ai-assistant-2', 'author' );

		$ai_id = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();
		$this->assertSame( 'ai-assistant-3', get_userdata( $ai_id )->user_login );
	}

	/**
	 * Test: a taken email address does not block account creation.
	 */
	public function test_taken_email_does_not_block_creation() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->human( 'someone-else', 'subscriber', 'ai-assistant@' . $host );

		$ai_id = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();
		$this->assertIsInt( $ai_id );
		$this->assertNotSame( 'ai-assistant@' . $host, get_userdata( $ai_id )->user_email );
	}

	/**
	 * Test: opening the settings page does not demote anyone.
	 */
	public function test_settings_page_view_does_not_demote() {
		$admin = $this->human( 'ai-assistant' );
		wp_set_current_user( $admin );

		ob_start();
		WP_AI_Gatekeeper_Admin_Menu::render_settings_page();
		ob_end_clean();

		$this->assertRoles( array( 'administrator' ), $admin );
	}
}
