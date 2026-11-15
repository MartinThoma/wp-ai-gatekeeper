<?php
/**
 * Authorship of AI drafts: when a human publishes a post the AI drafted, the
 * human becomes the author (they took editorial responsibility) and the AI is
 * recorded as "drafted by" in post meta, shown in the posts list and the API.
 *
 * Runs on wp_after_insert_post, which fires for the classic editor, the REST
 * API / block editor, and wp_publish_post() (scheduled posts via cron). Cron
 * has no logged-in user, so whoever schedules an AI draft is remembered.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reassigns AI drafts to their human publisher.
 */
class WP_AI_Gatekeeper_Authorship {

	/**
	 * Plugin settings option (shared with future settings).
	 */
	const SETTINGS_OPTION = 'wp_ai_gatekeeper_settings';

	/**
	 * Post meta: user ID of the AI that drafted the post.
	 */
	const DRAFTED_BY_META = '_wpai_drafted_by';

	/**
	 * Post meta: user ID of the human who scheduled an AI draft.
	 */
	const SCHEDULED_BY_META = '_wpai_scheduled_by';

	/**
	 * Posts list column key.
	 */
	const COLUMN = 'wpai_drafted_by';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert_post' ), 10, 4 );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Whether publishing reassigns AI drafts (on unless turned off).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return ! is_array( $settings ) || ! array_key_exists( 'reassign_author_on_publish', $settings )
			|| (bool) $settings['reassign_author_on_publish'];
	}

	/**
	 * Saves the setting.
	 *
	 * @param bool $enabled Whether to reassign authorship on publish.
	 */
	public static function set_enabled( $enabled ) {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['reassign_author_on_publish'] = (bool) $enabled;
		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Returns the AI user ID that drafted a post, or 0.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function drafted_by( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::DRAFTED_BY_META, true );
	}

	/**
	 * Remembers who scheduled an AI draft and reassigns it when it is published.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post after the save.
	 * @param bool         $update      Whether this was an update.
	 * @param WP_Post|null $post_before Post before the save.
	 */
	public static function on_after_insert_post( $post_id, $post, $update, $post_before ) {
		if ( 'post' !== $post->post_type || ! self::is_enabled()
			|| ! WP_AI_Gatekeeper_Auth::is_ai_user( (int) $post->post_author )
		) {
			return;
		}

		$was = $post_before instanceof WP_Post ? $post_before->post_status : '';
		$now = $post->post_status;
		if ( $now === $was ) {
			return;
		}

		$human = self::current_human();

		if ( 'future' === $now ) {
			if ( $human ) {
				update_post_meta( $post_id, self::SCHEDULED_BY_META, $human );
			}
			return;
		}

		if ( 'publish' !== $now ) {
			return;
		}

		if ( ! $human ) {
			$scheduler = (int) get_post_meta( $post_id, self::SCHEDULED_BY_META, true );
			$human     = $scheduler && self::is_human( $scheduler ) ? $scheduler : 0;
		}
		if ( ! $human ) {
			return; // Nobody to credit; keep the AI as author rather than guess.
		}

		self::reassign( $post_id, (int) $post->post_author, $human );

		// Callers such as the REST controller build their response from this
		// same object after the hook, so keep it in sync with the database.
		$post->post_author = (string) $human;
	}

	/**
	 * Moves authorship to the human and records the AI.
	 *
	 * Writes post_author directly: wp_update_post() here would re-enter the
	 * save that is still finishing and bump the modified date.
	 *
	 * @param int $post_id Post ID.
	 * @param int $ai_id   AI user ID.
	 * @param int $human   Human user ID.
	 */
	private static function reassign( $post_id, $ai_id, $human ) {
		global $wpdb;

		update_post_meta( $post_id, self::DRAFTED_BY_META, $ai_id );
		delete_post_meta( $post_id, self::SCHEDULED_BY_META );

		$wpdb->update( $wpdb->posts, array( 'post_author' => $human ), array( 'ID' => $post_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See method doc; cache is cleaned below.
		clean_post_cache( $post_id );
	}

	/**
	 * Returns the current user's ID if it is a human (not an AI user), else 0.
	 *
	 * @return int
	 */
	private static function current_human() {
		$user_id = get_current_user_id();
		return self::is_human( $user_id ) ? $user_id : 0;
	}

	/**
	 * Whether a user exists and is not an AI user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function is_human( $user_id ) {
		return $user_id > 0 && get_userdata( $user_id ) && ! WP_AI_Gatekeeper_Auth::is_ai_user( $user_id );
	}

	/**
	 * Adds the "AI draft" column after Author in the posts list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'author' === $key ) {
				$result[ self::COLUMN ] = __( 'Drafted by AI', 'wp-ai-gatekeeper' );
			}
		}
		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = __( 'Drafted by AI', 'wp-ai-gatekeeper' );
		}
		return $result;
	}

	/**
	 * Renders the "AI draft" column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$ai_id  = self::drafted_by( $post_id );
		$author = (int) get_post_field( 'post_author', $post_id );
		if ( ! $ai_id && WP_AI_Gatekeeper_Auth::is_ai_user( $author ) ) {
			$ai_id = $author; // Still an unpublished AI draft.
		}

		$user = $ai_id ? get_userdata( $ai_id ) : false;
		if ( ! $user ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No', 'wp-ai-gatekeeper' ) . '</span>';
			return;
		}
		echo esc_html( $user->display_name );
	}
}
