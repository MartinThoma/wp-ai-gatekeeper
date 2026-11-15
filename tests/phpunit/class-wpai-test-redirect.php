<?php
/**
 * Exception used by tests to stop at a redirect instead of the handler's exit.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Thrown instead of redirecting, so tests stop before the handler's exit.
 */
class WPAI_Test_Redirect extends Exception {}
