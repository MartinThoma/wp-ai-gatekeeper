<?php
/**
 * Stand-in for apache_request_headers(), which PHP only provides under
 * Apache. Lets tests exercise the plugin's fallback for servers that strip
 * the Authorization header from $_SERVER.
 *
 * Tests set $GLOBALS['wpai_test_apache_headers']; it is empty otherwise.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! function_exists( 'apache_request_headers' ) ) {
	/**
	 * Returns the request headers a test configured.
	 *
	 * @return array<string, string>
	 */
	function apache_request_headers() {
		return isset( $GLOBALS['wpai_test_apache_headers'] ) ? (array) $GLOBALS['wpai_test_apache_headers'] : array();
	}
}
