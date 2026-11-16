<?php
/**
 * Strict permission guardrails and REST API enforcement for AI agents.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces what the AI can and cannot do across WordPress capabilities,
 * core post/comment hooks, and REST API endpoints.
 */
class WP_AI_Gatekeeper_Permissions {

	/**
	 * Capabilities strictly forbidden for AI users.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_CAPS = array(
		'publish_post',
		'publish_posts',
		'publish_pages',
		'edit_others_posts',
		'delete_others_posts',
		'edit_published_posts',
		'delete_published_posts',
		'edit_private_posts',
		'delete_private_posts',
		'moderate_comments',
		'edit_comment',
		'delete_comment',
		'edit_pages',
		'delete_pages',
		'edit_others_pages',
		'delete_others_pages',
		'edit_published_pages',
		'delete_published_pages',
		'manage_options',
		'manage_categories',
		'manage_links',
		'edit_theme_options',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'edit_users',
		'delete_users',
		'create_users',
	);

	/**
	 * Registers capability and REST filters.
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 20, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'filter_map_meta_cap' ), 20, 4 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'filter_rest_pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_query', array( __CLASS__, 'allow_ai_read_drafts_in_wp_v2' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_wp_insert_post_data' ), 20, 1 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'block_unauthorized_ai_post_save' ), 20, 2 );
		add_filter( 'preprocess_comment', array( __CLASS__, 'block_ai_comment_creation' ), 1 );
		add_filter( 'pre_comment_approved', array( __CLASS__, 'block_ai_comment_approval' ), 1, 1 );
	}

	/**
	 * Whether a user may see a post's text (title aside).
	 *
	 * A password-protected post is readable (read_post) by every logged-in
	 * user, but its content is meant only for people who know the password.
	 * The plugin's API has no password parameter, so it shows the content only
	 * to users who can edit the post. Deliberately ignores the wp-postpass
	 * cookie, so the answer does not depend on the browser making the request.
	 *
	 * @param WP_Post|int $post    Post object or ID.
	 * @param int         $user_id User ID.
	 * @return bool
	 */
	public static function can_read_post_content( $post, $user_id ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		return '' === (string) $post->post_password || user_can( $user_id, 'edit_post', $post->ID );
	}

	/**
	 * Strips forbidden primitive capabilities from AI users.
	 *
	 * @param array   $allcaps All capabilities for the user.
	 * @param array   $caps    Required primitive caps.
	 * @param array   $args    Capability check arguments.
	 * @param WP_User $user    User object.
	 * @return array
	 */
	public static function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! ( $user instanceof WP_User ) || ! WP_AI_Gatekeeper_Auth::is_ai_user( $user->ID ) ) {
			return $allcaps;
		}

		foreach ( self::FORBIDDEN_CAPS as $forbidden_cap ) {
			$allcaps[ $forbidden_cap ] = false;
		}

		return $allcaps;
	}

	/**
	 * Enforces fine-grained meta capability rules for AI users:
	 * - Read all draft & published articles.
	 * - Edit/delete ONLY own draft articles and own uploaded attachments.
	 * - Never publish, unpublish, or moderate/edit comments.
	 *
	 * @param array  $caps    Mapped primitive capabilities.
	 * @param string $cap     Meta capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Extra arguments (e.g. post ID).
	 * @return array
	 */
	public static function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! WP_AI_Gatekeeper_Auth::is_ai_user( $user_id ) ) {
			return $caps;
		}

		if ( in_array( $cap, self::FORBIDDEN_CAPS, true ) ) {
			return array( 'do_not_allow' );
		}

		if ( 'read_post' === $cap ) {
			$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post ) {
				return array( 'do_not_allow' );
			}

			if ( in_array( $post->post_type, array( 'post', 'attachment' ), true )
				&& in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future', 'inherit' ), true )
			) {
				return array( 'read' );
			}

			return array( 'do_not_allow' );
		}

		if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ) {
			$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post ) {
				return array( 'do_not_allow' );
			}

			if ( 'attachment' === $post->post_type && (int) $post->post_author === (int) $user_id ) {
				return array( 'upload_files' );
			}

			if ( 'post' === $post->post_type
				&& (int) $post->post_author === (int) $user_id
				&& 'draft' === $post->post_status
			) {
				return array( 'delete_post' === $cap ? 'delete_posts' : 'edit_posts' );
			}

			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Intercepts standard WordPress REST routes for AI users to prevent
	 * comment mutations, publication state changes, and editing non-owned/published posts.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request Incoming request.
	 * @return mixed
	 */
	public static function filter_rest_pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			return $result;
		}

		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );

		// Completely block WordPress comments endpoint for AI agents.
		if ( preg_match( '#^/wp/v2/comments(?:/|$)#', $route ) ) {
			return new WP_Error(
				'rest_ai_comments_forbidden',
				__( 'AI agents are not permitted to add, edit, or moderate WordPress comments.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		// Protect wp/v2/posts mutations.
		if ( preg_match( '#^/wp/v2/posts(?:/(?P<id>\d+))?$#', $route, $matches ) ) {
			if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				if ( $request->has_param( 'status' ) && 'draft' !== $request->get_param( 'status' ) ) {
					return new WP_Error(
						'rest_ai_status_forbidden',
						__( 'AI agents cannot publish, unpublish, or change publication status. Posts must remain in draft status.', 'wp-ai-gatekeeper' ),
						array( 'status' => 403 )
					);
				}

				if ( ! empty( $matches['id'] ) ) {
					$post = get_post( (int) $matches['id'] );
					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error(
							'rest_post_invalid_id',
							__( 'Invalid post ID.', 'wp-ai-gatekeeper' ),
							array( 'status' => 404 )
						);
					}

					if ( get_current_user_id() !== (int) $post->post_author || 'draft' !== $post->post_status ) {
						return new WP_Error(
							'rest_ai_cannot_edit_post',
							__( 'AI agents can only edit their own draft articles. Submit a change suggestion via /ai-gatekeeper/v1/posts/{id}/suggestions instead.', 'wp-ai-gatekeeper' ),
							array( 'status' => 403 )
						);
					}
				}
			}
		}

		// Block mutations on pages and sensitive core endpoints for AI agents.
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			if ( preg_match( '#^/wp/v2/(pages|settings|plugins|themes|users|statuses|types)(?:/|$)#', $route ) ) {
				return new WP_Error(
					'rest_ai_route_forbidden',
					__( 'AI agents are not permitted to modify this WordPress resource.', 'wp-ai-gatekeeper' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}

	/**
	 * Allows AI users querying /wp/v2/posts to read both draft and published posts.
	 *
	 * @param array           $args    WP_Query arguments.
	 * @param WP_REST_Request $request REST request.
	 * @return array
	 */
	public static function allow_ai_read_drafts_in_wp_v2( $args, $request ) {
		if ( ! WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			return $args;
		}

		$status_param = $request->get_param( 'status' );
		if ( empty( $status_param ) ) {
			$args['post_status'] = array( 'publish', 'draft' );
		}

		return $args;
	}

	/**
	 * Forces AI-created/edited posts to remain in 'draft' status at the DB write boundary.
	 *
	 * @param array $data Slashed post data.
	 * @return array
	 */
	public static function filter_wp_insert_post_data( $data ) {
		if ( ! WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			return $data;
		}

		if ( isset( $data['post_type'] ) && 'post' === $data['post_type'] ) {
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * Aborts wp_insert_post / wp_update_post if an AI user attempts to modify
	 * a post they do not own or that is not a draft.
	 *
	 * @param bool  $maybe_empty Whether post appears empty.
	 * @param array $postarr     Post data array.
	 * @return bool
	 */
	public static function block_unauthorized_ai_post_save( $maybe_empty, $postarr ) {
		if ( $maybe_empty || ! WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			return $maybe_empty;
		}

		$post_type = isset( $postarr['post_type'] ) ? $postarr['post_type'] : 'post';

		if ( 'revision' === $post_type && ! empty( $postarr['post_parent'] ) ) {
			$parent = get_post( (int) $postarr['post_parent'] );
			if ( $parent
				&& 'post' === $parent->post_type
				&& get_current_user_id() === (int) $parent->post_author
				&& 'draft' === $parent->post_status
			) {
				return $maybe_empty;
			}
			return true;
		}

		if ( ! in_array( $post_type, array( 'post', 'attachment' ), true ) ) {
			return true;
		}

		if ( ! empty( $postarr['ID'] ) ) {
			$existing = get_post( (int) $postarr['ID'] );
			if ( ! $existing ) {
				return true;
			}

			if ( 'attachment' === $existing->post_type ) {
				return get_current_user_id() !== (int) $existing->post_author;
			}

			if ( 'post' === $existing->post_type ) {
				if ( get_current_user_id() !== (int) $existing->post_author || 'draft' !== $existing->post_status ) {
					return true;
				}
			}
		}

		return $maybe_empty;
	}

	/**
	 * Blocks AI users from creating WordPress blog comments via core hooks.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public static function block_ai_comment_creation( $commentdata ) {
		if ( WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			wp_die(
				esc_html__( 'AI agents are not allowed to add WordPress comments.', 'wp-ai-gatekeeper' ),
				esc_html__( 'Forbidden', 'wp-ai-gatekeeper' ),
				array( 'response' => 403 )
			);
		}
		return $commentdata;
	}

	/**
	 * Blocks AI users from approving or inserting comments.
	 *
	 * @param int|string|WP_Error $approved Approval status.
	 * @return int|string|WP_Error
	 */
	public static function block_ai_comment_approval( $approved ) {
		if ( WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			return new WP_Error(
				'ai_comments_forbidden',
				__( 'AI agents are not permitted to add or moderate WordPress comments.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}
		return $approved;
	}
}
