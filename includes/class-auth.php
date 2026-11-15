<?php
/**
 * Authentication, AI role management, and API Key verification.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the restricted AI role and API key authentication.
 */
class WP_AI_Gatekeeper_Auth {

	/**
	 * Role slug assigned to AI agents.
	 */
	const ROLE_SLUG = 'ai_contributor';

	/**
	 * Option name storing API key records (hashed).
	 */
	const KEYS_OPTION = 'wp_ai_gatekeeper_api_keys';

	/**
	 * Option name storing default AI user ID.
	 */
	const DEFAULT_USER_OPTION = 'wp_ai_gatekeeper_default_ai_user_id';

	/**
	 * Token prefix for generated API keys.
	 */
	const KEY_PREFIX = 'wpai_';

	/**
	 * Whether the current request authenticated via a valid AI API key.
	 *
	 * @var bool
	 */
	private static $is_ai_key_request = false;

	/**
	 * Active key ID used on this request.
	 *
	 * @var string|null
	 */
	private static $active_key_id = null;

	/**
	 * Authentication error encountered when verifying an explicit AI key.
	 *
	 * @var WP_Error|null
	 */
	private static $auth_error = null;

	/**
	 * Re-entrancy guard for determine_current_user.
	 *
	 * @var bool
	 */
	private static $determining_user = false;

	/**
	 * Initializes auth hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_role' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_authentication_errors' ), 20 );
		add_filter( 'wp_is_application_passwords_available', array( __CLASS__, 'allow_application_passwords_in_staging' ) );
	}

	/**
	 * Enables Application Passwords in staging/local/development environments even over HTTP.
	 *
	 * @param bool $available Default availability.
	 * @return bool
	 */
	public static function allow_application_passwords_in_staging( $available ) {
		if ( function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'staging', 'local', 'development' ), true ) ) {
			return true;
		}
		return $available;
	}

	/**
	 * Activation routine: register role and create default AI user.
	 */
	public static function activate() {
		self::sync_role_capabilities();
		self::ensure_default_ai_user();
	}

	/**
	 * Returns capability map for the AI contributor role.
	 *
	 * @return array<string, bool>
	 */
	public static function get_role_capabilities() {
		return array(
			'read'                   => true,
			'edit_posts'             => true,
			'delete_posts'           => true,
			'upload_files'           => true,
			'publish_posts'          => false,
			'edit_published_posts'   => false,
			'delete_published_posts' => false,
			'edit_others_posts'      => false,
			'delete_others_posts'    => false,
			'moderate_comments'      => false,
			'edit_comment'           => false,
			'manage_categories'      => false,
			'manage_options'         => false,
			'edit_pages'             => false,
			'publish_pages'          => false,
			'edit_others_pages'      => false,
		);
	}

	/**
	 * Registers the AI role if it does not already exist.
	 */
	public static function register_role() {
		if ( null === get_role( self::ROLE_SLUG ) ) {
			add_role(
				self::ROLE_SLUG,
				__( 'AI Gatekeeper Agent', 'wp-ai-gatekeeper' ),
				self::get_role_capabilities()
			);
		}
	}

	/**
	 * Ensures the AI role exists with exact up-to-date capabilities.
	 */
	public static function sync_role_capabilities() {
		remove_role( self::ROLE_SLUG );
		add_role(
			self::ROLE_SLUG,
			__( 'AI Gatekeeper Agent', 'wp-ai-gatekeeper' ),
			self::get_role_capabilities()
		);
	}

	/**
	 * Base login for the AI service account; "-2", "-3", ... are appended when taken.
	 */
	const DEFAULT_LOGIN = 'ai-assistant';

	/**
	 * Ensures a dedicated AI agent WordPress user exists and returns its ID.
	 *
	 * Never changes the role of an existing user: set_role() strips all other
	 * roles, so re-roling a user who happens to be called "ai-assistant" would
	 * demote an administrator, and re-roling the AI account would undo an
	 * admin's deliberate change. Runs on activation, re-sync, and every view of
	 * the settings page, so this must be safe to call repeatedly.
	 *
	 * Order: the configured account if it still has the AI role; else the
	 * oldest account with the AI role; else a new account under the first free
	 * login.
	 *
	 * @return int|WP_Error
	 */
	public static function ensure_default_ai_user() {
		self::register_role();

		$stored_id = (int) get_option( self::DEFAULT_USER_OPTION, 0 );
		if ( $stored_id > 0 && self::is_ai_user( $stored_id ) ) {
			return $stored_id;
		}

		$existing = get_users(
			array(
				'role'    => self::ROLE_SLUG,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		if ( ! empty( $existing ) ) {
			$user_id = (int) $existing[0];
			update_option( self::DEFAULT_USER_OPTION, $user_id );
			return $user_id;
		}

		$login  = self::DEFAULT_LOGIN;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			++$suffix;
			$login = self::DEFAULT_LOGIN . '-' . $suffix;
		}

		$email = $login . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_email( $email ) ) {
			$email = $login . '@example.invalid';
		}
		if ( email_exists( $email ) ) {
			$email = $login . '-' . wp_generate_password( 6, false, false ) . '@example.invalid';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'display_name' => __( 'AI Editorial Assistant', 'wp-ai-gatekeeper' ),
				'role'         => self::ROLE_SLUG,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_option( self::DEFAULT_USER_OPTION, (int) $user_id );
		return (int) $user_id;
	}

	/**
	 * Generates and stores a new API key bound to an AI contributor user.
	 *
	 * @param string $name    Human-readable label for the key.
	 * @param int    $user_id Optional AI user ID (defaults to dedicated AI user).
	 * @return array|WP_Error Key metadata plus one-time raw_key.
	 */
	public static function generate_api_key( $name, $user_id = 0 ) {
		$name = sanitize_text_field( trim( (string) $name ) );
		if ( '' === $name ) {
			$name = __( 'AI Agent Key', 'wp-ai-gatekeeper' );
		}

		if ( ! $user_id ) {
			$user_id = self::ensure_default_ai_user();
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! in_array( self::ROLE_SLUG, (array) $user->roles, true ) ) {
			return new WP_Error(
				'invalid_ai_user',
				__( 'API keys can only be bound to a user with the AI Gatekeeper Agent role.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		try {
			$random = bin2hex( random_bytes( 16 ) );
			$key_id = bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			$random = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
			$key_id = substr( md5( uniqid( '', true ) ), 0, 16 );
		}

		$raw_key    = self::KEY_PREFIX . $random;
		$key_hash   = hash( 'sha256', $raw_key );
		$key_prefix = substr( $raw_key, 0, 12 );

		$record = array(
			'id'           => $key_id,
			'name'         => $name,
			'key_prefix'   => $key_prefix,
			'key_hash'     => $key_hash,
			'user_id'      => (int) $user_id,
			'created_at'   => current_time( 'mysql', true ),
			'last_used_at' => null,
			'revoked'      => false,
		);

		$keys   = self::get_raw_keys();
		$keys[] = $record;
		update_option( self::KEYS_OPTION, $keys, false );

		return array(
			'record'  => self::sanitize_key_record( $record ),
			'raw_key' => $raw_key,
		);
	}

	/**
	 * Returns stored key records from options.
	 *
	 * @return array
	 */
	private static function get_raw_keys() {
		$keys = get_option( self::KEYS_OPTION, array() );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Removes secret hash from a key record for safe display.
	 *
	 * @param array $record Raw key record.
	 * @return array
	 */
	private static function sanitize_key_record( array $record ) {
		unset( $record['key_hash'] );
		return $record;
	}

	/**
	 * Lists all API key metadata without hashes.
	 *
	 * @return array
	 */
	public static function list_api_keys() {
		$keys   = self::get_raw_keys();
		$output = array();
		foreach ( $keys as $record ) {
			if ( is_array( $record ) ) {
				$output[] = self::sanitize_key_record( $record );
			}
		}
		return array_reverse( $output );
	}

	/**
	 * Revokes an API key by ID.
	 *
	 * @param string $key_id Key ID.
	 * @return bool
	 */
	public static function revoke_api_key( $key_id ) {
		$keys  = self::get_raw_keys();
		$found = false;

		foreach ( $keys as $idx => $record ) {
			if ( isset( $record['id'] ) && $record['id'] === $key_id ) {
				$keys[ $idx ]['revoked'] = true;
				$found                   = true;
				break;
			}
		}

		if ( $found ) {
			update_option( self::KEYS_OPTION, $keys, false );
		}

		return $found;
	}

	/**
	 * Permanently deletes an API key record by ID.
	 *
	 * @param string $key_id Key ID.
	 * @return bool
	 */
	public static function delete_api_key( $key_id ) {
		$keys      = self::get_raw_keys();
		$remaining = array();
		$found     = false;

		foreach ( $keys as $record ) {
			if ( isset( $record['id'] ) && $record['id'] === $key_id ) {
				$found = true;
				continue;
			}
			$remaining[] = $record;
		}

		if ( $found ) {
			update_option( self::KEYS_OPTION, $remaining, false );
		}

		return $found;
	}

	/**
	 * Extracts a wpai_ API token from request headers.
	 *
	 * @return string|null
	 */
	public static function extract_token_from_request() {
		if ( ! empty( $_SERVER['HTTP_X_WP_AI_KEY'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$header = trim( (string) wp_unslash( $_SERVER['HTTP_X_WP_AI_KEY'] ) );
			if ( 0 === strpos( $header, self::KEY_PREFIX ) ) {
				return $header;
			}
			if ( '' !== $header ) {
				return $header;
			}
		}

		$auth_header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$auth_header = trim( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$auth_header = trim( (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( isset( $headers['Authorization'] ) ) {
				$auth_header = trim( (string) $headers['Authorization'] );
			} elseif ( isset( $headers['authorization'] ) ) {
				$auth_header = trim( (string) $headers['authorization'] );
			}
		}

		if ( 0 === stripos( $auth_header, 'Bearer ' ) ) {
			$token = trim( substr( $auth_header, 7 ) );
			if ( 0 === strpos( $token, self::KEY_PREFIX ) ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Authenticates requests presenting a valid AI API key.
	 *
	 * @param int|bool $user_id Currently determined user ID.
	 * @return int|bool
	 */
	public static function determine_current_user( $user_id ) {
		if ( self::$determining_user ) {
			return $user_id;
		}

		$token = self::extract_token_from_request();
		if ( null === $token || '' === $token ) {
			return $user_id;
		}

		self::$determining_user = true;

		$token_hash = hash( 'sha256', $token );
		$keys       = self::get_raw_keys();
		$matched    = null;
		$match_idx  = null;

		foreach ( $keys as $idx => $record ) {
			if ( empty( $record['key_hash'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $record['key_hash'], $token_hash ) ) {
				$matched   = $record;
				$match_idx = $idx;
				break;
			}
		}

		if ( null === $matched || ! empty( $matched['revoked'] ) ) {
			self::$auth_error       = new WP_Error(
				'rest_ai_invalid_key',
				__( 'The provided AI API key is invalid or has been revoked.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
			self::$determining_user = false;
			return 0;
		}

		$target_user_id = isset( $matched['user_id'] ) ? (int) $matched['user_id'] : 0;
		$user           = $target_user_id ? get_userdata( $target_user_id ) : false;

		if ( ! $user || ! in_array( self::ROLE_SLUG, (array) $user->roles, true ) ) {
			self::$auth_error       = new WP_Error(
				'rest_ai_invalid_user',
				__( 'The AI API key is not linked to an active AI Gatekeeper Agent user.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
			self::$determining_user = false;
			return 0;
		}

		self::$is_ai_key_request = true;
		self::$active_key_id     = $matched['id'];
		self::$auth_error        = null;

		$now_mysql = current_time( 'mysql', true );
		$last_used = isset( $matched['last_used_at'] ) ? strtotime( (string) $matched['last_used_at'] ) : 0;
		if ( ! $last_used || ( time() - $last_used ) > 60 ) {
			$keys[ $match_idx ]['last_used_at'] = $now_mysql;
			update_option( self::KEYS_OPTION, $keys, false );
		}

		self::$determining_user = false;
		return $target_user_id;
	}

	/**
	 * Resolves REST authentication state for AI API key requests.
	 *
	 * @param WP_Error|null|bool $errors Existing authentication errors.
	 * @return WP_Error|null|bool
	 */
	public static function rest_authentication_errors( $errors ) {
		if ( null !== self::$auth_error ) {
			return self::$auth_error;
		}

		if ( self::$is_ai_key_request ) {
			return true;
		}

		return $errors;
	}

	/**
	 * Returns true if the request authenticated via an AI API key.
	 *
	 * @return bool
	 */
	public static function is_ai_key_request() {
		return self::$is_ai_key_request;
	}

	/**
	 * Checks whether a given (or current) user is an AI agent.
	 *
	 * @param int|null $user_id Optional user ID.
	 * @return bool
	 */
	public static function is_ai_user( $user_id = null ) {
		if ( null === $user_id ) {
			if ( self::$is_ai_key_request ) {
				return true;
			}
			$user_id = get_current_user_id();
		}

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return in_array( self::ROLE_SLUG, (array) $user->roles, true );
	}

	/**
	 * Checks whether a given (or current) user is a human reviewer able to edit posts.
	 *
	 * @param int|null $user_id Optional user ID.
	 * @return bool
	 */
	public static function is_human_reviewer( $user_id = null ) {
		$uid = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $uid <= 0 || self::is_ai_user( $uid ) ) {
			return false;
		}
		return user_can( $uid, 'edit_posts' );
	}

	/**
	 * Alias for is_ai_user() for checking if current request is by an AI agent.
	 *
	 * @return bool
	 */
	public static function is_ai_request() {
		return self::is_ai_user();
	}

	/**
	 * Alias for generate_api_key().
	 *
	 * @param string $name    Human-readable label for the key.
	 * @param int    $user_id Optional AI user ID.
	 * @return array|WP_Error
	 */
	public static function create_api_key( $name, $user_id = 0 ) {
		return self::generate_api_key( $name, $user_id );
	}

	/**
	 * Alias for list_api_keys().
	 *
	 * @return array
	 */
	public static function get_api_keys() {
		return self::list_api_keys();
	}
}
