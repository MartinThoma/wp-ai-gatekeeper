<?php
/**
 * Post Editor Meta Box & Gutenberg Sidebar Asset Loader.
 *
 * Displays GitHub PR-style inline diffs, comment threads, and Merge/Reject
 * controls inside the Post Editor and enqueues the Gutenberg sidebar panel.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_AI_Gatekeeper_Meta_Box
 */
class WP_AI_Gatekeeper_Meta_Box {

	/**
	 * Initialize hooks for post editor UI and Gutenberg integration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_gutenberg_assets' ) );
	}

	/**
	 * Register the AI Suggestions meta box on post and page edit screens.
	 *
	 * @return void
	 */
	public static function register_meta_box() {
		$post_types = apply_filters( 'wp_ai_gatekeeper_post_types', array( 'post', 'page' ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'wp-ai-gatekeeper-suggestions',
				__( 'AI Gatekeeper Suggestions (PR Review)', 'wp-ai-gatekeeper' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Enqueue CSS and JS for the post editor and global AI Gatekeeper admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		$is_post_editor = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
		$is_plugin_page = ( false !== strpos( $hook_suffix, 'wp-ai-gatekeeper' ) );

		if ( ! $is_post_editor && ! $is_plugin_page ) {
			return;
		}

		wp_enqueue_style(
			'wp-ai-gatekeeper-admin',
			WP_AI_GATEKEEPER_PLUGIN_URL . 'assets/css/admin-suggestions.css',
			array(),
			WP_AI_GATEKEEPER_VERSION
		);

		wp_enqueue_script(
			'wp-ai-gatekeeper-admin',
			WP_AI_GATEKEEPER_PLUGIN_URL . 'assets/js/admin-suggestions.js',
			array(),
			WP_AI_GATEKEEPER_VERSION,
			true
		);

		wp_localize_script(
			'wp-ai-gatekeeper-admin',
			'wpAIGatekeeper',
			self::get_js_config()
		);
	}

	/**
	 * Enqueue the Gutenberg Block Editor PluginSidebar panel.
	 *
	 * @return void
	 */
	public static function enqueue_gutenberg_assets() {
		wp_enqueue_style(
			'wp-ai-gatekeeper-admin',
			WP_AI_GATEKEEPER_PLUGIN_URL . 'assets/css/admin-suggestions.css',
			array(),
			WP_AI_GATEKEEPER_VERSION
		);

		wp_enqueue_script(
			'wp-ai-gatekeeper-gutenberg',
			WP_AI_GATEKEEPER_PLUGIN_URL . 'assets/js/gutenberg-sidebar.js',
			array(
				'wp-plugins',
				'wp-edit-post',
				'wp-element',
				'wp-components',
				'wp-data',
				'wp-api-fetch',
				'wp-i18n',
				'wp-blocks',
			),
			WP_AI_GATEKEEPER_VERSION,
			true
		);

		wp_localize_script(
			'wp-ai-gatekeeper-gutenberg',
			'wpAIGatekeeper',
			self::get_js_config()
		);
	}

	/**
	 * Build localized JS configuration object.
	 *
	 * @return array
	 */
	public static function get_js_config() {
		$post_id = 0;
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return array(
			'restRoot' => esc_url_raw( rest_url( 'ai-gatekeeper/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'postId'   => $post_id,
			'isAiUser' => WP_AI_Gatekeeper_Auth::is_ai_request(),
			'queueUrl' => esc_url_raw( admin_url( 'admin.php?page=wp-ai-gatekeeper' ) ),
			'i18n'     => array(
				'confirmMerge'       => __( 'Merge this suggestion into the article? Once merged, this suggestion is permanently locked and cannot be edited.', 'wp-ai-gatekeeper' ),
				'confirmReject'      => __( 'Reject this suggestion?', 'wp-ai-gatekeeper' ),
				'mergedBadge'        => __( 'Merged (Locked)', 'wp-ai-gatekeeper' ),
				'rejectedBadge'      => __( 'Rejected', 'wp-ai-gatekeeper' ),
				'openBadge'          => __( 'Open', 'wp-ai-gatekeeper' ),
				'lockedNotice'       => __( 'This suggestion has been merged and is permanently locked.', 'wp-ai-gatekeeper' ),
				'commentPlaceholder' => __( 'Reply to the AI agent with feedback or revision instructions...', 'wp-ai-gatekeeper' ),
				'addingComment'      => __( 'Posting...', 'wp-ai-gatekeeper' ),
				'merging'            => __( 'Merging...', 'wp-ai-gatekeeper' ),
				'rejecting'          => __( 'Rejecting...', 'wp-ai-gatekeeper' ),
				'showArticle'        => __( 'Show in full article', 'wp-ai-gatekeeper' ),
				'hideArticle'        => __( 'Hide full article', 'wp-ai-gatekeeper' ),
				'loadingArticle'     => __( 'Loading article…', 'wp-ai-gatekeeper' ),
			),
		);
	}

	/**
	 * Render the Post Editor Meta Box content.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$suggestions = WP_AI_Gatekeeper_Suggestion_Service::get_suggestions(
			array(
				'post_id'  => $post->ID,
				'per_page' => 100,
			)
		);

		$open_count   = 0;
		$merged_count = 0;
		foreach ( $suggestions as $item ) {
			if ( 'open' === $item['status'] ) {
				++$open_count;
			} elseif ( 'merged' === $item['status'] ) {
				++$merged_count;
			}
		}
		?>
		<div class="wpai-meta-box-wrapper" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<div class="wpai-meta-box-header">
				<div class="wpai-summary-counts">
					<span class="wpai-count-pill wpai-count-open">
						<?php
						printf(
							/* translators: %d: number of open suggestions */
							esc_html__( '%d Open', 'wp-ai-gatekeeper' ),
							(int) $open_count
						);
						?>
					</span>
					<span class="wpai-count-pill wpai-count-merged">
						<?php
						printf(
							/* translators: %d: number of merged suggestions */
							esc_html__( '%d Merged', 'wp-ai-gatekeeper' ),
							(int) $merged_count
						);
						?>
					</span>
				</div>
				<div class="wpai-filter-tabs" role="tablist">
					<button type="button" class="button button-small wpai-filter-btn is-active" data-filter="all">
						<?php esc_html_e( 'All', 'wp-ai-gatekeeper' ); ?>
					</button>
					<button type="button" class="button button-small wpai-filter-btn" data-filter="open">
						<?php esc_html_e( 'Open', 'wp-ai-gatekeeper' ); ?>
					</button>
					<button type="button" class="button button-small wpai-filter-btn" data-filter="merged">
						<?php esc_html_e( 'Merged', 'wp-ai-gatekeeper' ); ?>
					</button>
					<button type="button" class="button button-small wpai-filter-btn" data-filter="rejected">
						<?php esc_html_e( 'Rejected', 'wp-ai-gatekeeper' ); ?>
					</button>
				</div>
			</div>

			<?php if ( empty( $suggestions ) ) : ?>
				<div class="wpai-empty-state">
					<p><?php esc_html_e( 'No AI suggestions have been submitted for this article yet.', 'wp-ai-gatekeeper' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wpai-suggestions-list">
					<?php foreach ( $suggestions as $suggestion ) : ?>
						<?php self::render_suggestion_card( $suggestion, false ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single GitHub PR-style suggestion card.
	 *
	 * @param array $suggestion      Hydrated suggestion array.
	 * @param bool  $show_post_title Whether to show the article link header (used in global queue).
	 * @return void
	 */
	public static function render_suggestion_card( $suggestion, $show_post_title = false ) {
		$status        = $suggestion['status'];
		$is_locked     = ! empty( $suggestion['is_locked'] ) || 'merged' === $status;
		$can_review    = current_user_can( 'edit_post', $suggestion['post_id'] ) && ! WP_AI_Gatekeeper_Auth::is_ai_request();
		$status_labels = array(
			'open'     => __( 'Open', 'wp-ai-gatekeeper' ),
			'merged'   => __( 'Merged (Locked)', 'wp-ai-gatekeeper' ),
			'rejected' => __( 'Rejected', 'wp-ai-gatekeeper' ),
		);
		$status_label  = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
		?>
		<div class="wpai-suggestion-card wpai-status-<?php echo esc_attr( $status ); ?>"
			id="wpai-suggestion-<?php echo esc_attr( $suggestion['id'] ); ?>"
			data-suggestion-id="<?php echo esc_attr( $suggestion['id'] ); ?>"
			data-status="<?php echo esc_attr( $status ); ?>"
			data-target-field="<?php echo esc_attr( $suggestion['target_field'] ); ?>"
			data-original-text="<?php echo esc_attr( $suggestion['original_text'] ); ?>"
			data-suggested-text="<?php echo esc_attr( $suggestion['suggested_text'] ); ?>">

			<div class="wpai-card-header">
				<div class="wpai-card-meta">
					<span class="wpai-status-badge wpai-badge-<?php echo esc_attr( $status ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
					<strong class="wpai-card-id">#<?php echo esc_html( $suggestion['id'] ); ?></strong>
					<?php if ( $show_post_title ) : ?>
						<span class="wpai-card-article">
							<?php esc_html_e( 'on', 'wp-ai-gatekeeper' ); ?>
							<a href="<?php echo esc_url( get_edit_post_link( $suggestion['post_id'] ) ); ?>">
								<?php echo esc_html( $suggestion['post_title'] ? $suggestion['post_title'] : '#' . $suggestion['post_id'] ); ?>
							</a>
						</span>
					<?php endif; ?>
					<span class="wpai-field-pill">
						<?php echo esc_html( $suggestion['target_field'] ); ?>
					</span>
					<?php if ( ! empty( $suggestion['block_id'] ) ) : ?>
						<code class="wpai-block-pill"><?php echo esc_html( $suggestion['block_id'] ); ?></code>
					<?php endif; ?>
				</div>
				<div class="wpai-card-author">
					<?php
					printf(
						/* translators: 1: author name, 2: created timestamp */
						esc_html__( 'Proposed by %1$s on %2$s', 'wp-ai-gatekeeper' ),
						esc_html( $suggestion['author_name'] ),
						esc_html( $suggestion['created_at'] )
					);
					?>
				</div>
			</div>

			<div class="wpai-card-diff">
				<?php echo wp_kses_post( $suggestion['diff_html'] ); ?>
				<button type="button" class="button-link wpai-btn-preview" aria-expanded="false" aria-controls="wpai-preview-<?php echo esc_attr( $suggestion['id'] ); ?>">
					<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
					<span class="wpai-btn-preview-label"><?php esc_html_e( 'Show in full article', 'wp-ai-gatekeeper' ); ?></span>
				</button>
				<div class="wpai-article-preview" id="wpai-preview-<?php echo esc_attr( $suggestion['id'] ); ?>" tabindex="-1" hidden></div>
			</div>

			<div class="wpai-card-thread">
				<h4 class="wpai-thread-heading">
					<?php
					printf(
						/* translators: %d: number of comments in discussion */
						esc_html__( 'Discussion (%d)', 'wp-ai-gatekeeper' ),
						count( $suggestion['comments'] )
					);
					?>
				</h4>
				<div class="wpai-comments-container">
					<?php if ( empty( $suggestion['comments'] ) ) : ?>
						<p class="wpai-no-comments"><?php esc_html_e( 'No comments yet.', 'wp-ai-gatekeeper' ); ?></p>
					<?php else : ?>
						<?php foreach ( $suggestion['comments'] as $comment ) : ?>
							<div class="wpai-thread-comment wpai-comment-<?php echo esc_attr( $comment['author_type'] ); ?>">
								<div class="wpai-comment-header">
									<span class="wpai-author-badge wpai-author-<?php echo esc_attr( $comment['author_type'] ); ?>">
										<?php echo 'ai' === $comment['author_type'] ? esc_html__( 'AI Agent', 'wp-ai-gatekeeper' ) : esc_html__( 'Human Editor', 'wp-ai-gatekeeper' ); ?>
									</span>
									<strong><?php echo esc_html( $comment['author_name'] ); ?></strong>
									<span class="wpai-comment-time"><?php echo esc_html( $comment['created_at'] ); ?></span>
								</div>
								<div class="wpai-comment-body">
									<?php echo wp_kses_post( wpautop( $comment['comment_text'] ) ); ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<?php if ( $is_locked ) : ?>
					<div class="wpai-locked-banner">
						<span class="dashicons dashicons-lock"></span>
						<?php
						if ( ! empty( $suggestion['merged_by_name'] ) && ! empty( $suggestion['merged_at'] ) ) {
							printf(
								/* translators: 1: user who merged, 2: merge timestamp */
								esc_html__( 'Merged by %1$s on %2$s. This suggestion is permanently locked and cannot be changed anymore.', 'wp-ai-gatekeeper' ),
								esc_html( $suggestion['merged_by_name'] ),
								esc_html( $suggestion['merged_at'] )
							);
						} else {
							esc_html_e( 'This suggestion has been merged and is permanently locked.', 'wp-ai-gatekeeper' );
						}
						?>
					</div>
				<?php else : ?>
					<div class="wpai-reply-box">
						<textarea
							class="wpai-comment-input widefat"
							rows="2"
							placeholder="<?php esc_attr_e( 'Add a comment or ask the AI to refine this suggestion...', 'wp-ai-gatekeeper' ); ?>"></textarea>
						<div class="wpai-card-actions">
							<button type="button" class="button wpai-btn-comment">
								<?php esc_html_e( 'Add Comment', 'wp-ai-gatekeeper' ); ?>
							</button>

							<?php if ( 'open' === $status && $can_review ) : ?>
								<div class="wpai-review-decisions">
									<button type="button" class="button button-primary wpai-btn-merge">
										<?php esc_html_e( 'Merge Suggestion', 'wp-ai-gatekeeper' ); ?>
									</button>
									<button type="button" class="button wpai-btn-reject">
										<?php esc_html_e( 'Reject', 'wp-ai-gatekeeper' ); ?>
									</button>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
				<div class="wpai-card-feedback" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}
}
