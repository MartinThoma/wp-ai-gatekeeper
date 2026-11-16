<?php
/**
 * PHPUnit bootstrap file for WP AI Gatekeeper.
 *
 * @package WP_AI_Gatekeeper
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to WordPress test suite.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
} elseif ( file_exists( dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 *
 * Tables and the AI role/user are created per test in set_up(); doing it here
 * would run before pluggable functions (get_user_by, etc.) are available.
 */
function _manually_load_wp_ai_gatekeeper_plugin() {
	require dirname( __DIR__, 2 ) . '/wp-ai-gatekeeper.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_wp_ai_gatekeeper_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

require_once __DIR__ . '/class-wp-ai-gatekeeper-testcase.php';
require_once __DIR__ . '/class-wpai-test-redirect.php';
require_once __DIR__ . '/apache-request-headers.php';
