<?php
/**
 * Plugin Name:       WP AI Gatekeeper
 * Description:       Enterprise-safe AI governance for WordPress with strict permission guardrails, human approval gates, and GitHub PR-style change suggestions.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP AI Gatekeeper Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-gatekeeper
 * Domain Path:       /languages
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AI_GATEKEEPER_VERSION', '1.0.0' );
define( 'WP_AI_GATEKEEPER_DB_VERSION', '1.0.0' );
define( 'WP_AI_GATEKEEPER_PLUGIN_FILE', __FILE__ );
define( 'WP_AI_GATEKEEPER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_GATEKEEPER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AI_GATEKEEPER_TEXT_DOMAIN', 'wp-ai-gatekeeper' );

require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-database.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-auth.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-permissions.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-diff.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-markup-guard.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-suggestion-service.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/admin/class-meta-box.php';
require_once WP_AI_GATEKEEPER_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';

/**
 * Main plugin coordinator singleton.
 */
final class WP_AI_Gatekeeper {

	/**
	 * Singleton instance.
	 *
	 * @var WP_AI_Gatekeeper|null
	 */
	private static $instance = null;

	/**
	 * Returns singleton instance.
	 *
	 * @return WP_AI_Gatekeeper
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to wire WordPress hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Initializes subsystems after all plugins load.
	 */
	public function on_plugins_loaded() {
		load_plugin_textdomain(
			WP_AI_GATEKEEPER_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WP_AI_GATEKEEPER_PLUGIN_FILE ) ) . '/languages'
		);

		$this->maybe_upgrade_database();

		WP_AI_Gatekeeper_Auth::init();
		WP_AI_Gatekeeper_Permissions::init();

		if ( is_admin() ) {
			WP_AI_Gatekeeper_Meta_Box::init();
			WP_AI_Gatekeeper_Admin_Menu::init();
		}
	}

	/**
	 * Registers custom REST API routes.
	 */
	public function register_rest_routes() {
		$controller = new WP_AI_Gatekeeper_REST_Controller();
		$controller->register_routes();
	}

	/**
	 * Runs database schema upgrades if plugin DB version changed.
	 */
	private function maybe_upgrade_database() {
		$installed_version = get_option( 'wp_ai_gatekeeper_db_version', '' );
		if ( WP_AI_GATEKEEPER_DB_VERSION !== $installed_version ) {
			WP_AI_Gatekeeper_Database::create_tables();
			update_option( 'wp_ai_gatekeeper_db_version', WP_AI_GATEKEEPER_DB_VERSION );
		}
	}

	/**
	 * Plugin activation routine.
	 */
	public static function activate() {
		WP_AI_Gatekeeper_Database::create_tables();
		update_option( 'wp_ai_gatekeeper_db_version', WP_AI_GATEKEEPER_DB_VERSION );

		WP_AI_Gatekeeper_Auth::activate();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation routine.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'WP_AI_Gatekeeper', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_AI_Gatekeeper', 'deactivate' ) );

WP_AI_Gatekeeper::instance();
