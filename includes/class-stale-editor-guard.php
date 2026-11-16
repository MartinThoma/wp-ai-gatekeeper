<?php
/**
 * Stops an editor that was opened before a merge from saving its older copy
 * of the article over the merge.
 *
 * Merging writes the post on the server. An editor still open on the old text
 * (another tab, window, or device; WordPress's post lock only warns about
 * *other* users) would write that text back on its next save or autosave.
 *
 * Each merge bumps a counter in post meta. Editor pages send the counter they
 * were loaded with, and saves carrying an older value are refused:
 * - Block editor: an apiFetch middleware adds a header to requests for the
 *   post, checked before /wp/v2/posts/{id} and its /autosaves run.
 * - Classic editor: a hidden form field, checked before post.php saves.
 * - Classic autosave (via Heartbeat): the stale post update is aborted.
 * Heartbeat also reports the stale state so the editor can warn right away.
 *
 * Requests without the counter (other plugins, scripts, direct REST clients)
 * are not affected.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses saves from editors that predate the latest merge.
 */
class WP_AI_Gatekeeper_Stale_Editor_Guard {

	/**
	 * Post meta holding the number of merges into the post.
	 */
	const META = '_wpai_merge_count';

	/**
	 * Request header carrying the editor's merge count (block editor).
	 */
	const HEADER = 'X-WPAI-Merge-Count';

	/**
	 * Form field carrying the editor's merge count (classic editor).
	 */
	const FIELD = 'wpai_merge_count';

	/**
	 * Heartbeat data key.
	 */
	const HEARTBEAT_KEY = 'wpai_merge_check';

	/**
	 * Post whose autosave in the current Heartbeat request must be dropped.
	 *
	 * @var int
	 */
	private static $blocked_autosave_post = 0;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'check_rest_save' ), 10, 3 );
		add_action( 'edit_form_top', array( __CLASS__, 'render_field' ) );
		add_action( 'admin_action_editpost', array( __CLASS__, 'check_classic_save' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat_received' ), 1, 2 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'block_stale_autosave' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Number of merges into a post so far.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function merge_count( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::META, true );
	}

	/**
	 * Records a merge into a post. Editors loaded before it become stale.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function record_merge( $post_id ) {
		update_post_meta( (int) $post_id, self::META, self::merge_count( $post_id ) + 1 );
	}

	/**
	 * Whether an editor loaded with the given merge count is out of date.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $base    Merge count the editor was loaded with.
	 * @return bool
	 */
	public static function is_stale( $post_id, $base ) {
		return is_numeric( $base ) && (int) $base < self::merge_count( $post_id );
	}

	/**
	 * Message shown when a save is refused or an editor is out of date.
	 *
	 * @return string
	 */
	public static function stale_message() {
		return __( 'This article was changed by a merged AI suggestion after you opened it. Saving now would undo that change. Copy any edits you want to keep, then reload the editor.', 'wp-ai-gatekeeper' );
	}

	/**
	 * Refuses block editor saves and autosaves from a stale editor.
	 *
	 * @param mixed           $response Null to continue, or an earlier error.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function check_rest_save( $response, $handler, $request ) {
		if ( null !== $response ) {
			return $response;
		}
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true )
			|| ! preg_match( '#^/wp/v2/posts/(\d+)(?:/autosaves)?$#', $request->get_route(), $matches )
		) {
			return $response;
		}

		if ( self::is_stale( (int) $matches[1], $request->get_header( self::HEADER ) ) ) {
			return new WP_Error( 'wpai_stale_editor', self::stale_message(), array( 'status' => 409 ) );
		}
		return $response;
	}

	/**
	 * Adds the merge count to the classic editor form.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_field( $post ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		printf(
			'<input type="hidden" name="%1$s" value="%2$d" />',
			esc_attr( self::FIELD ),
			(int) self::merge_count( $post->ID )
		);
	}

	/**
	 * Refuses a classic editor save from a stale form. Runs before post.php
	 * verifies the nonce; it only ever refuses, never acts on the input.
	 *
	 * @return void
	 */
	public static function check_classic_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only check; post.php verifies the nonce before saving.
		if ( ! isset( $_POST[ self::FIELD ], $_POST['post_ID'] ) ) {
			return;
		}
		$post_id = absint( $_POST['post_ID'] );
		$base    = sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( self::is_stale( $post_id, $base ) ) {
			wp_die(
				esc_html( self::stale_message() ),
				esc_html__( 'Article changed since you opened it', 'wp-ai-gatekeeper' ),
				array(
					'response'  => 409,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Reports whether the open editor is stale, and drops a stale classic
	 * autosave in the same Heartbeat request.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data     Heartbeat data from the browser.
	 * @return array
	 */
	public static function heartbeat_received( $response, $data ) {
		if ( empty( $data[ self::HEARTBEAT_KEY ] ) || ! is_array( $data[ self::HEARTBEAT_KEY ] ) ) {
			return $response;
		}

		$check   = $data[ self::HEARTBEAT_KEY ];
		$post_id = isset( $check['post_id'] ) ? absint( $check['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return $response;
		}

		$stale                           = self::is_stale( $post_id, isset( $check['merge_count'] ) ? $check['merge_count'] : null );
		$response[ self::HEARTBEAT_KEY ] = array( 'stale' => $stale );

		if ( $stale && isset( $data['wp_autosave']['post_id'] ) && absint( $data['wp_autosave']['post_id'] ) === $post_id ) {
			self::$blocked_autosave_post = $post_id;
		}
		return $response;
	}

	/**
	 * Aborts the post update of a stale classic autosave (marked in
	 * heartbeat_received, which runs before core's autosave handler).
	 *
	 * @param bool  $maybe_empty Whether WordPress considers the post empty.
	 * @param array $postarr     Post data.
	 * @return bool
	 */
	public static function block_stale_autosave( $maybe_empty, $postarr ) {
		if ( self::$blocked_autosave_post && isset( $postarr['ID'] ) && (int) $postarr['ID'] === self::$blocked_autosave_post ) {
			return true;
		}
		return $maybe_empty;
	}

	/**
	 * Loads the editor script that sends the merge count and shows the warning.
	 *
	 * @param string $hook_suffix Admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		global $post;
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
			|| ! $post instanceof WP_Post || 'post' !== $post->post_type
			|| ! current_user_can( 'edit_post', $post->ID )
		) {
			return;
		}

		wp_enqueue_script(
			'wp-ai-gatekeeper-stale-editor',
			WP_AI_GATEKEEPER_PLUGIN_URL . 'assets/js/stale-editor-guard.js',
			array( 'jquery', 'heartbeat', 'wp-api-fetch' ),
			WP_AI_GATEKEEPER_VERSION,
			true
		);
		wp_localize_script(
			'wp-ai-gatekeeper-stale-editor',
			'wpAIGatekeeperStaleGuard',
			array(
				'postId'       => (int) $post->ID,
				'mergeCount'   => self::merge_count( $post->ID ),
				'header'       => self::HEADER,
				'heartbeatKey' => self::HEARTBEAT_KEY,
				'i18n'         => array(
					'stale'  => self::stale_message(),
					'reload' => __( 'Reload editor', 'wp-ai-gatekeeper' ),
				),
			)
		);
	}
}
