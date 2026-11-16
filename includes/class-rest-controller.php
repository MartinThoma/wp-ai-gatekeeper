<?php
/**
 * Custom REST API endpoints for WP AI Gatekeeper collaboration.
 *
 * @package WP_AI_Gatekeeper
 */

// @codeCoverageIgnoreStart
// Only runs when a file is opened directly, never inside WordPress or the tests.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

/**
 * Registers and handles all /ai-gatekeeper/v1 REST endpoints.
 */
class WP_AI_Gatekeeper_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-gatekeeper/v1';

	/**
	 * Registers all plugin routes.
	 */
	public function register_routes() {
		// Articles: list & create draft.
		register_rest_route(
			$this->namespace,
			'/posts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_posts' ),
					'permission_callback' => array( $this, 'read_posts_permissions_check' ),
					'args'                => array(
						'status'   => array(
							'type'    => 'string',
							'default' => 'all',
							'enum'    => array( 'all', 'draft', 'publish' ),
						),
						'search'   => array(
							'type' => 'string',
						),
						'author'   => array(
							'type' => 'integer',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_draft_post' ),
					'permission_callback' => array( $this, 'create_draft_permissions_check' ),
					'args'                => array(
						'title'          => array(
							'type'     => 'string',
							'required' => true,
						),
						'content'        => array(
							'type'    => 'string',
							'default' => '',
						),
						'excerpt'        => array(
							'type'    => 'string',
							'default' => '',
						),
						'status'         => array(
							'type' => 'string',
						),
						'featured_media' => array(
							'type' => 'integer',
						),
						'categories'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'tags'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		// Single article: read & edit own draft.
		register_rest_route(
			$this->namespace,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_single_post' ),
					'permission_callback' => array( $this, 'read_single_post_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_draft_post' ),
					'permission_callback' => array( $this, 'update_draft_permissions_check' ),
					'args'                => array(
						'title'          => array( 'type' => 'string' ),
						'content'        => array( 'type' => 'string' ),
						'excerpt'        => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
						'featured_media' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		// Media upload endpoint.
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_media' ),
					'permission_callback' => array( $this, 'upload_media_permissions_check' ),
				),
			)
		);

		// Global suggestions queue.
		register_rest_route(
			$this->namespace,
			'/suggestions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suggestions' ),
					'permission_callback' => array( $this, 'list_suggestions_permissions_check' ),
					'args'                => array(
						'post_id'        => array( 'type' => 'integer' ),
						'status'         => array(
							'type'    => 'string',
							'default' => 'open',
							'enum'    => array( 'open', 'merged', 'rejected', 'all' ),
						),
						'needs_ai_reply' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'page'           => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'       => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
			)
		);

		// Per-post suggestions list & create.
		register_rest_route(
			$this->namespace,
			'/posts/(?P<id>\d+)/suggestions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_suggestions' ),
					'permission_callback' => array( $this, 'post_suggestions_permissions_check' ),
					'args'                => array(
						'status'         => array(
							'type'    => 'string',
							'default' => 'all',
							'enum'    => array( 'open', 'merged', 'rejected', 'all' ),
						),
						'needs_ai_reply' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'page'           => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'       => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_suggestion' ),
					'permission_callback' => array( $this, 'create_suggestion_permissions_check' ),
					'args'                => array(
						'target_field'   => array(
							'type'    => 'string',
							'default' => 'post_content',
							'enum'    => WP_AI_Gatekeeper_Suggestion_Service::ALLOWED_FIELDS,
						),
						'block_id'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'original_text'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'suggested_text' => array(
							'type'     => 'string',
							'required' => true,
						),
						'context_before' => array(
							'type'    => 'string',
							'default' => '',
						),
						'context_after'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'comment'        => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		// Single suggestion: read & update (until merged).
		register_rest_route(
			$this->namespace,
			'/suggestions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suggestion' ),
					'permission_callback' => array( $this, 'list_suggestions_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_suggestion' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
					'args'                => array(
						'suggested_text' => array( 'type' => 'string' ),
						'original_text'  => array( 'type' => 'string' ),
						'context_before' => array( 'type' => 'string' ),
						'context_after'  => array( 'type' => 'string' ),
						'block_id'       => array( 'type' => 'string' ),
						'comment'        => array( 'type' => 'string' ),
					),
				),
			)
		);

		// Suggestion shown inside the complete rendered article.
		register_rest_route(
			$this->namespace,
			'/suggestions/(?P<id>\d+)/preview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_suggestion_preview' ),
					'permission_callback' => array( $this, 'list_suggestions_permissions_check' ),
				),
			)
		);

		// Add dialogue comment on a suggestion.
		register_rest_route(
			$this->namespace,
			'/suggestions/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_suggestion_comment' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
					'args'                => array(
						'comment' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// Human-only merge endpoint.
		register_rest_route(
			$this->namespace,
			'/suggestions/(?P<id>\d+)/merge',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'merge_suggestion' ),
					'permission_callback' => array( $this, 'human_review_permissions_check' ),
					'args'                => array(
						'comment' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		// Human-only reject endpoint.
		register_rest_route(
			$this->namespace,
			'/suggestions/(?P<id>\d+)/reject',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reject_suggestion' ),
					'permission_callback' => array( $this, 'human_review_permissions_check' ),
					'args'                => array(
						'comment' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
	}

	/**
	 * Checks basic authenticated read capability.
	 *
	 * @return true|WP_Error
	 */
	public function read_posts_permissions_check() {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication is required to read articles.', 'wp-ai-gatekeeper' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}
		return true;
	}

	/**
	 * Checks permission to read a specific post (draft or published).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function read_single_post_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error(
				'post_not_found',
				__( 'Article not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to read this article.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks permission to list a post's suggestions: humans must be able to edit
	 * the post (suggestions and discussions are editorial, not public).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function post_suggestions_permissions_check( $request ) {
		$readable = $this->read_single_post_permissions_check( $request );
		if ( is_wp_error( $readable ) ) {
			return $readable;
		}

		if ( ! WP_AI_Gatekeeper_Suggestion_Service::can_view_post_suggestions( get_current_user_id(), (int) $request['id'] ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view suggestions for this article.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks permission to create a new draft article.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function create_draft_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to create drafts.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		if ( WP_AI_Gatekeeper_Auth::is_ai_user() && $request->has_param( 'status' ) && 'draft' !== $request->get_param( 'status' ) ) {
			return new WP_Error(
				'rest_ai_status_forbidden',
				__( 'AI agents cannot publish or set non-draft statuses. Articles must be created as drafts.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks permission to edit a draft article.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function update_draft_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error(
				'post_not_found',
				__( 'Article not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			if ( $request->has_param( 'status' ) && 'draft' !== $request->get_param( 'status' ) ) {
				return new WP_Error(
					'rest_ai_status_forbidden',
					__( 'AI agents cannot publish, unpublish, or change publication status.', 'wp-ai-gatekeeper' ),
					array( 'status' => 403 )
				);
			}

			if ( get_current_user_id() !== (int) $post->post_author || 'draft' !== $post->post_status ) {
				return new WP_Error(
					'rest_ai_cannot_edit_post',
					__( 'AI agents can only directly edit their own draft articles. Submit a suggestion via /ai-gatekeeper/v1/posts/{id}/suggestions instead.', 'wp-ai-gatekeeper' ),
					array( 'status' => 403 )
				);
			}
		} elseif ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit this article.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks permission to upload media files.
	 *
	 * @return true|WP_Error
	 */
	public function upload_media_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to upload media.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks permission to view the suggestions queue.
	 *
	 * @return true|WP_Error
	 */
	public function list_suggestions_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		if ( ! WP_AI_Gatekeeper_Auth::is_ai_user() && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to inspect suggestions.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks permission to create a suggestion on an article.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function create_suggestion_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error(
				'post_not_found',
				__( 'Target article not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		// The AI may suggest on any post it can read in full; humans need edit rights.
		$allowed = WP_AI_Gatekeeper_Auth::is_ai_user()
			? current_user_can( 'read_post', $post->ID ) && WP_AI_Gatekeeper_Permissions::can_read_post_content( $post, get_current_user_id() )
			: current_user_can( 'edit_post', $post->ID );
		if ( ! $allowed ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to suggest changes for this article.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Checks generic logged-in status; fine-grained checks run in the suggestion service.
	 *
	 * @return true|WP_Error
	 */
	public function logged_in_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Strictly allows only human editors (never AI agents) to merge or reject suggestions.
	 *
	 * @return true|WP_Error
	 */
	public function human_review_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication is required.', 'wp-ai-gatekeeper' ),
				array( 'status' => 401 )
			);
		}

		if ( WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			return new WP_Error(
				'rest_ai_review_forbidden',
				__( 'AI agents cannot merge or reject suggestions. A human editor must perform this action.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to review or merge suggestions.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Lists all draft and published articles.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_posts( $request ) {
		$status_param = $request->get_param( 'status' );
		$post_status  = 'all' === $status_param ? array( 'publish', 'draft' ) : array( sanitize_key( $status_param ) );
		$page         = max( 1, (int) $request->get_param( 'page' ) );
		$per_page     = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			// ID breaks ties, so posts modified in the same second keep a stable order across pages.
			'orderby'        => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
		);

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$query_args['s'] = sanitize_text_field( $search );
		}

		$author = $request->get_param( 'author' );
		if ( ! empty( $author ) ) {
			$query_args['author'] = absint( $author );
		}

		// Restrict the query itself to posts the caller may read, so that `total`
		// and `total_pages` do not count (and reveal) posts dropped afterwards.
		$restriction = $this->readable_posts_sql( ! empty( $query_args['s'] ) );
		$add_where   = static function ( $where ) use ( $restriction ) {
			return $where . $restriction;
		};
		if ( '' !== $restriction ) {
			add_filter( 'posts_where', $add_where );
		}
		$query = new WP_Query( $query_args );
		if ( '' !== $restriction ) {
			remove_filter( 'posts_where', $add_where );
		}

		$posts    = $query->posts;
		$post_ids = wp_list_pluck( $posts, 'ID' );
		$counts   = WP_AI_Gatekeeper_Database::get_open_counts_by_post( $post_ids );

		$items = array();
		foreach ( $posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$items[] = $this->format_post( $post, $counts[ $post->ID ] ?? 0 );
			}
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			),
			200
		);
	}

	/**
	 * SQL appended to the post list's WHERE clause so the query only matches
	 * posts the current user may read (read_post), in the same way the
	 * per-post check does afterwards:
	 * - Drafts: users who cannot edit others' posts only see their own. The AI
	 *   is exempt; by design it reads every draft.
	 * - Search: WordPress excludes password-protected posts from searches only
	 *   for logged-out visitors. Every caller here is logged in, so a search
	 *   would reveal whether hidden content contains a phrase. Protected posts
	 *   are only searched for users who can edit others' posts, or their own.
	 *
	 * @param bool $is_search Whether the query searches.
	 * @return string SQL starting with " AND", or '' for no restriction.
	 */
	private function readable_posts_sql( $is_search ) {
		global $wpdb;

		if ( current_user_can( 'edit_others_posts' ) ) {
			return '';
		}

		$user_id = get_current_user_id();
		$sql     = '';
		if ( ! WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			$sql .= $wpdb->prepare( " AND ( {$wpdb->posts}.post_status = 'publish' OR {$wpdb->posts}.post_author = %d )", $user_id );
		}
		if ( $is_search ) {
			$sql .= $wpdb->prepare( " AND ( {$wpdb->posts}.post_password = '' OR {$wpdb->posts}.post_author = %d )", $user_id );
		}
		return $sql;
	}

	/**
	 * Reads a single article by ID.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_single_post( $request ) {
		$post   = get_post( (int) $request['id'] );
		$counts = WP_AI_Gatekeeper_Database::get_open_counts_by_post( array( $post->ID ) );

		return new WP_REST_Response(
			$this->format_post( $post, $counts[ $post->ID ] ?? 0 ),
			200
		);
	}

	/**
	 * Creates a new draft article.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_draft_post( $request ) {
		$post_data = array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_title'   => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'post_content' => wp_kses_post( (string) $request->get_param( 'content' ) ),
			'post_excerpt' => sanitize_textarea_field( (string) $request->get_param( 'excerpt' ) ),
		);

		$post_id = wp_insert_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->assign_post_extras( $post_id, $request );

		$post = get_post( $post_id );
		return new WP_REST_Response( $this->format_post( $post, 0 ), 201 );
	}

	/**
	 * Updates an existing draft article authored by the AI (or editable by human).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_draft_post( $request ) {
		$post_id   = (int) $request['id'];
		$post_data = array( 'ID' => $post_id );

		if ( $request->has_param( 'title' ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
		}

		if ( $request->has_param( 'content' ) ) {
			$post_data['post_content'] = wp_kses_post( (string) $request->get_param( 'content' ) );
		}

		if ( $request->has_param( 'excerpt' ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( (string) $request->get_param( 'excerpt' ) );
		}

		if ( WP_AI_Gatekeeper_Auth::is_ai_user() ) {
			$post_data['post_status'] = 'draft';
		}

		$updated = wp_update_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->assign_post_extras( $post_id, $request );

		$post   = get_post( $post_id );
		$counts = WP_AI_Gatekeeper_Database::get_open_counts_by_post( array( $post_id ) );
		return new WP_REST_Response( $this->format_post( $post, $counts[ $post_id ] ?? 0 ), 200 );
	}

	/**
	 * Uploads an image/media file into the WordPress Media Library.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media( $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$files       = $request->get_file_params();
		$upload_file = null;

		if ( ! empty( $files['file'] ) ) {
			$upload_file = $files['file'];
		} elseif ( ! empty( $files ) ) {
			$upload_file = reset( $files );
		}

		$attachment_id = 0;
		$parent_id     = absint( $request->get_param( 'post_id' ) );
		if ( $parent_id > 0 && ! current_user_can( 'edit_post', $parent_id ) ) {
			$parent_id = 0;
		}

		if ( ! empty( $upload_file ) ) {
			$movefile = wp_handle_upload( $upload_file, array( 'test_form' => false ) );
			if ( isset( $movefile['error'] ) ) {
				return new WP_Error(
					'media_upload_failed',
					$movefile['error'],
					array( 'status' => 400 )
				);
			}

			// WordPress only accepts files PHP received as real HTTP uploads, which
			// PHPUnit cannot produce; the raw-body path below shares the same code.
			$attachment_id = $this->insert_attachment_from_upload( $movefile, $parent_id, $request ); // @codeCoverageIgnore
		} else {
			$raw_body = (string) $request->get_body(); // Null for internally built requests.
			if ( '' === $raw_body ) {
				return new WP_Error(
					'missing_media_file',
					__( 'No media file was provided. Send multipart form-data "file" or raw binary body with Content-Disposition filename.', 'wp-ai-gatekeeper' ),
					array( 'status' => 400 )
				);
			}

			$filename = $this->extract_filename_from_headers( $request );
			$tmp_file = wp_tempnam( $filename );
			if ( ! $tmp_file ) {
				// @codeCoverageIgnoreStart
				// Only when the server's temporary directory is unusable.
				return new WP_Error(
					'media_temp_failed',
					__( 'Could not allocate temporary file for upload.', 'wp-ai-gatekeeper' ),
					array( 'status' => 500 )
				);
				// @codeCoverageIgnoreEnd
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $tmp_file, $raw_body );

			$sideload = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			);

			$movefile = wp_handle_sideload( $sideload, array( 'test_form' => false ) );
			if ( isset( $movefile['error'] ) ) {
				wp_delete_file( $tmp_file );
				return new WP_Error(
					'media_upload_failed',
					$movefile['error'],
					array( 'status' => 400 )
				);
			}

			$attachment_id = $this->insert_attachment_from_upload( $movefile, $parent_id, $request );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment = get_post( $attachment_id );
		$metadata   = wp_get_attachment_metadata( $attachment_id );

		return new WP_REST_Response(
			array(
				'id'            => (int) $attachment_id,
				'source_url'    => (string) wp_get_attachment_url( $attachment_id ),
				'mime_type'     => (string) get_post_mime_type( $attachment_id ),
				'title'         => $attachment ? (string) $attachment->post_title : '',
				'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'caption'       => $attachment ? (string) $attachment->post_excerpt : '',
				'description'   => $attachment ? (string) $attachment->post_content : '',
				'media_details' => is_array( $metadata ) ? $metadata : array(),
			),
			201
		);
	}

	/**
	 * Lists global suggestions with filters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_suggestions( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		$result = WP_AI_Gatekeeper_Suggestion_Service::list_suggestions(
			array(
				'post_id'        => absint( $request->get_param( 'post_id' ) ),
				'post_ids'       => WP_AI_Gatekeeper_Suggestion_Service::visible_post_ids( get_current_user_id() ),
				'status'         => sanitize_key( (string) $request->get_param( 'status' ) ),
				'needs_ai_reply' => (bool) $request->get_param( 'needs_ai_reply' ),
				'limit'          => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Lists suggestions for a specific post.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_post_suggestions( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		$result = WP_AI_Gatekeeper_Suggestion_Service::list_suggestions(
			array(
				'post_id'        => (int) $request['id'],
				'status'         => sanitize_key( (string) $request->get_param( 'status' ) ),
				'needs_ai_reply' => (bool) $request->get_param( 'needs_ai_reply' ),
				'limit'          => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Creates a change suggestion on an existing article.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_suggestion( $request ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			(int) $request['id'],
			$request->get_params(),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Fetches a single suggestion with diff and dialogue comments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_suggestion( $request ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! WP_AI_Gatekeeper_Suggestion_Service::can_view_post_suggestions( get_current_user_id(), (int) $result['post_id'] ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view this suggestion.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Renders the complete article with the suggestion highlighted in place.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_suggestion_preview( $request ) {
		$row  = WP_AI_Gatekeeper_Database::get_suggestion( (int) $request['id'] );
		$post = $row ? get_post( (int) $row->post_id ) : null;
		if ( ! $row || ! $post ) {
			return new WP_Error(
				'suggestion_not_found',
				__( 'Suggestion not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( ! WP_AI_Gatekeeper_Suggestion_Service::can_view_post_suggestions( get_current_user_id(), $post->ID ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view this article.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		return new WP_REST_Response( WP_AI_Gatekeeper_Preview::build( $row, $post ), 200 );
	}

	/**
	 * Updates an open suggestion (fails with 409 if already merged).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_suggestion( $request ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::update_suggestion(
			(int) $request['id'],
			$request->get_params(),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Appends a human or AI comment to an open suggestion dialogue thread.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_suggestion_comment( $request ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::add_comment(
			(int) $request['id'],
			(string) $request->get_param( 'comment' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Merges an open suggestion into the article (human-only).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function merge_suggestion( $request ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request->get_param( 'comment' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Rejects an open suggestion (human-only).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_suggestion( $request ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::reject_suggestion(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request->get_param( 'comment' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Formats a WP_Post object for REST responses.
	 *
	 * @param WP_Post $post                   Post object.
	 * @param int     $open_suggestions_count Open suggestion count.
	 * @return array
	 */
	private function format_post( WP_Post $post, $open_suggestions_count = 0 ) {
		$author     = get_userdata( (int) $post->post_author );
		$drafted_by = WP_AI_Gatekeeper_Authorship::drafted_by( $post->ID );
		$readable   = WP_AI_Gatekeeper_Permissions::can_read_post_content( $post, get_current_user_id() );

		return array(
			'id'                     => (int) $post->ID,
			'title'                  => (string) $post->post_title,
			'status'                 => (string) $post->post_status,
			'author'                 => (int) $post->post_author,
			'author_name'            => $author ? (string) $author->display_name : '',
			'is_ai_authored'         => $drafted_by > 0 || WP_AI_Gatekeeper_Auth::is_ai_user( (int) $post->post_author ),
			'drafted_by'             => $drafted_by > 0 ? $drafted_by : null,
			'excerpt'                => $readable ? (string) $post->post_excerpt : '',
			'content_raw'            => $readable ? (string) $post->post_content : '',
			'content_rendered'       => $readable ? apply_filters( 'the_content', $post->post_content ) : '', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			// True when the post is password-protected and the excerpt and content above were withheld.
			'content_protected'      => ! $readable,
			'featured_media'         => (int) get_post_thumbnail_id( $post->ID ),
			'date_gmt'               => (string) $post->post_date_gmt,
			'modified_gmt'           => (string) $post->post_modified_gmt,
			'link'                   => (string) get_permalink( $post->ID ),
			'open_suggestions_count' => (int) $open_suggestions_count,
		);
	}

	/**
	 * Assigns optional featured media, categories, and tags on draft save.
	 *
	 * @param int             $post_id Post ID.
	 * @param WP_REST_Request $request REST request.
	 */
	private function assign_post_extras( $post_id, $request ) {
		if ( $request->has_param( 'featured_media' ) ) {
			$media_id = absint( $request->get_param( 'featured_media' ) );
			if ( $media_id > 0 && 'attachment' === get_post_type( $media_id ) ) {
				set_post_thumbnail( $post_id, $media_id );
			} elseif ( 0 === $media_id ) {
				delete_post_thumbnail( $post_id );
			}
		}

		if ( $request->has_param( 'categories' ) && is_array( $request->get_param( 'categories' ) ) ) {
			$cat_ids = array_map( 'absint', $request->get_param( 'categories' ) );
			wp_set_post_categories( $post_id, $cat_ids );
		}

		if ( $request->has_param( 'tags' ) && is_array( $request->get_param( 'tags' ) ) ) {
			$tag_ids = array_map( 'absint', $request->get_param( 'tags' ) );
			wp_set_post_tags( $post_id, $tag_ids );
		}
	}

	/**
	 * Creates attachment post & metadata after successful upload/sideload.
	 *
	 * @param array           $movefile  wp_handle_upload result.
	 * @param int             $parent_id Optional parent post ID.
	 * @param WP_REST_Request $request   REST request.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function insert_attachment_from_upload( array $movefile, $parent_id, $request ) {
		$file_path = $movefile['file'];
		$file_url  = $movefile['url'];
		$mime_type = $movefile['type'];

		$title       = $request->get_param( 'title' );
		$caption     = $request->get_param( 'caption' );
		$description = $request->get_param( 'description' );

		$attachment = array(
			'guid'           => $file_url,
			'post_mime_type' => $mime_type,
			'post_title'     => ! empty( $title ) ? sanitize_text_field( $title ) : preg_replace( '/\.[^.]+$/', '', wp_basename( $file_path ) ),
			'post_content'   => ! empty( $description ) ? sanitize_textarea_field( $description ) : '',
			'post_excerpt'   => ! empty( $caption ) ? sanitize_textarea_field( $caption ) : '',
			'post_status'    => 'inherit',
			'post_author'    => get_current_user_id(),
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path, $parent_id, true );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( is_array( $attach_data ) ) {
			wp_update_attachment_metadata( $attachment_id, $attach_data );
		}

		$alt_text = $request->get_param( 'alt_text' );
		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * Extracts sanitized filename from Content-Disposition header for raw binary uploads.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private function extract_filename_from_headers( $request ) {
		$disposition = $request->get_header( 'content_disposition' );
		if ( $disposition && preg_match( '/filename\*?=(?:UTF-8\'\')?"?([^";\r\n]+)"?/i', $disposition, $matches ) ) {
			return sanitize_file_name( rawurldecode( trim( $matches[1] ) ) );
		}

		$content_type = $request->get_header( 'content_type' );
		$ext          = 'bin';
		if ( $content_type && false !== strpos( $content_type, '/' ) ) {
			$parts = explode( '/', explode( ';', $content_type )[0] );
			$ext   = sanitize_key( end( $parts ) );
			if ( 'jpeg' === $ext ) {
				$ext = 'jpg';
			}
		}

		return 'ai-upload-' . gmdate( 'Ymd-His' ) . '.' . $ext;
	}
}
