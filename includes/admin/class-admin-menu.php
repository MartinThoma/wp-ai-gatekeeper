<?php
/**
 * Global Admin Menu, Suggestion Review Queue, and API Key Settings Page.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_AI_Gatekeeper_Admin_Menu
 */
class WP_AI_Gatekeeper_Admin_Menu {

	/**
	 * Initialize admin menu and form handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_form_actions' ) );
	}

	/**
	 * Register the top-level AI Gatekeeper menu and submenus.
	 *
	 * @return void
	 */
	public static function register_menus() {
		$open_suggestions = WP_AI_Gatekeeper_Suggestion_Service::get_suggestions(
			array(
				'status'   => 'open',
				'per_page' => 200,
			)
		);
		$open_count       = count( $open_suggestions );

		$menu_title = __( 'AI Gatekeeper', 'wp-ai-gatekeeper' );
		if ( $open_count > 0 ) {
			$menu_title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
				$open_count
			);
		}

		add_menu_page(
			__( 'AI Gatekeeper — Suggestion Review Queue', 'wp-ai-gatekeeper' ),
			$menu_title,
			'edit_others_posts',
			'wp-ai-gatekeeper',
			array( __CLASS__, 'render_queue_page' ),
			'dashicons-shield-alt',
			26
		);

		add_submenu_page(
			'wp-ai-gatekeeper',
			__( 'Suggestion Review Queue', 'wp-ai-gatekeeper' ),
			__( 'Suggestions Queue', 'wp-ai-gatekeeper' ),
			'edit_others_posts',
			'wp-ai-gatekeeper',
			array( __CLASS__, 'render_queue_page' )
		);

		add_submenu_page(
			'wp-ai-gatekeeper',
			__( 'AI Gatekeeper — API Keys & Guardrails', 'wp-ai-gatekeeper' ),
			__( 'API Keys & Settings', 'wp-ai-gatekeeper' ),
			'manage_options',
			'wp-ai-gatekeeper-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Handle API key creation, revocation, deletion, and AI role sync actions.
	 *
	 * @return void
	 */
	public static function handle_form_actions() {
		if ( ! isset( $_POST['wpai_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'wpai_settings_action', 'wpai_settings_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['wpai_action'] ) );

		if ( 'create_key' === $action ) {
			$label   = isset( $_POST['key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['key_label'] ) ) : '';
			$user_id = isset( $_POST['ai_user_id'] ) ? absint( $_POST['ai_user_id'] ) : 0;

			$created = WP_AI_Gatekeeper_Auth::create_api_key( $label, $user_id );
			if ( ! is_wp_error( $created ) ) {
				set_transient(
					'wpai_new_api_key_' . get_current_user_id(),
					$created,
					60
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-gatekeeper-settings&updated=key_created' ) );
			exit;
		}

		if ( 'revoke_key' === $action && isset( $_POST['key_id'] ) ) {
			WP_AI_Gatekeeper_Auth::revoke_api_key( sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-gatekeeper-settings&updated=key_revoked' ) );
			exit;
		}

		if ( 'delete_key' === $action && isset( $_POST['key_id'] ) ) {
			WP_AI_Gatekeeper_Auth::delete_api_key( sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-gatekeeper-settings&updated=key_deleted' ) );
			exit;
		}

		if ( 'sync_ai_user' === $action ) {
			WP_AI_Gatekeeper_Auth::activate();
			wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-gatekeeper-settings&updated=role_synced' ) );
			exit;
		}

		if ( 'save_authorship' === $action ) {
			WP_AI_Gatekeeper_Authorship::set_enabled( ! empty( $_POST['reassign_author_on_publish'] ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-gatekeeper-settings&updated=settings_saved' ) );
			exit;
		}
	}

	/**
	 * Render the Global Suggestion Review Queue admin page.
	 *
	 * @return void
	 */
	public static function render_queue_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-gatekeeper' ) );
		}

		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_filter   = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$query_args = array(
			'per_page' => 100,
		);
		if ( in_array( $status_filter, array( 'open', 'merged', 'rejected' ), true ) ) {
			$query_args['status'] = $status_filter;
		}
		if ( $post_filter > 0 ) {
			$query_args['post_id'] = $post_filter;
		}

		$suggestions = WP_AI_Gatekeeper_Suggestion_Service::get_suggestions( $query_args );
		$base_url    = admin_url( 'admin.php?page=wp-ai-gatekeeper' );
		?>
		<div class="wrap wpai-admin-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'AI Gatekeeper — Pull Request Style Suggestion Queue', 'wp-ai-gatekeeper' ); ?>
			</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-gatekeeper-settings' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'API Keys & Guardrails', 'wp-ai-gatekeeper' ); ?>
			</a>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'open', $base_url ) ); ?>"
						class="<?php echo 'open' === $status_filter ? 'current' : ''; ?>">
						<?php esc_html_e( 'Open', 'wp-ai-gatekeeper' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'merged', $base_url ) ); ?>"
						class="<?php echo 'merged' === $status_filter ? 'current' : ''; ?>">
						<?php esc_html_e( 'Merged (Locked)', 'wp-ai-gatekeeper' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'rejected', $base_url ) ); ?>"
						class="<?php echo 'rejected' === $status_filter ? 'current' : ''; ?>">
						<?php esc_html_e( 'Rejected', 'wp-ai-gatekeeper' ); ?>
					</a> |
				</li>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base_url ) ); ?>"
						class="<?php echo 'all' === $status_filter ? 'current' : ''; ?>">
						<?php esc_html_e( 'All Suggestions', 'wp-ai-gatekeeper' ); ?>
					</a>
				</li>
			</ul>

			<div class="wpai-queue-container">
				<?php if ( empty( $suggestions ) ) : ?>
					<div class="wpai-empty-state">
						<p><?php esc_html_e( 'No suggestions match the current filter.', 'wp-ai-gatekeeper' ); ?></p>
					</div>
				<?php else : ?>
					<div class="wpai-suggestions-list">
						<?php foreach ( $suggestions as $suggestion ) : ?>
							<?php WP_AI_Gatekeeper_Meta_Box::render_suggestion_card( $suggestion, true ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the API Keys & Security Guardrails settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-gatekeeper' ) );
		}

		$new_key = get_transient( 'wpai_new_api_key_' . get_current_user_id() );
		if ( $new_key ) {
			delete_transient( 'wpai_new_api_key_' . get_current_user_id() );
		}

		$keys               = WP_AI_Gatekeeper_Auth::list_api_keys();
		$ai_users           = get_users(
			array(
				'role' => WP_AI_Gatekeeper_Auth::ROLE_SLUG,
			)
		);
		$default_ai_user_id = WP_AI_Gatekeeper_Auth::ensure_default_ai_user();
		?>
		<div class="wrap wpai-admin-wrap">
			<h1><?php esc_html_e( 'AI Gatekeeper — API Keys & Enterprise Guardrails', 'wp-ai-gatekeeper' ); ?></h1>

			<?php if ( ! empty( $new_key['raw_key'] ) ) : ?>
				<div class="notice notice-success wpai-new-key-notice">
					<p>
						<strong><?php esc_html_e( 'New AI API Key Created! Copy this key now — it will never be shown again:', 'wp-ai-gatekeeper' ); ?></strong>
					</p>
					<p>
						<code class="wpai-raw-key-display"><?php echo esc_html( $new_key['raw_key'] ); ?></code>
					</p>
					<p class="description">
						<?php esc_html_e( 'Send via header: X-WP-AI-Key: <key> or Authorization: Bearer <key>', 'wp-ai-gatekeeper' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="wpai-settings-grid">
				<div class="wpai-settings-card">
					<h2><?php esc_html_e( 'Generate AI Agent API Key', 'wp-ai-gatekeeper' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Each API key authenticates requests as a restricted AI Gatekeeper Agent user.', 'wp-ai-gatekeeper' ); ?>
					</p>
					<form method="post" action="">
						<?php wp_nonce_field( 'wpai_settings_action', 'wpai_settings_nonce' ); ?>
						<input type="hidden" name="wpai_action" value="create_key" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="key_label"><?php esc_html_e( 'Key Label', 'wp-ai-gatekeeper' ); ?></label>
								</th>
								<td>
									<input type="text" id="key_label" name="key_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Editorial Assistant Bot', 'wp-ai-gatekeeper' ); ?>" required />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="ai_user_id"><?php esc_html_e( 'AI Service Account', 'wp-ai-gatekeeper' ); ?></label>
								</th>
								<td>
									<select id="ai_user_id" name="ai_user_id">
										<?php foreach ( $ai_users as $ai_user ) : ?>
											<option value="<?php echo esc_attr( $ai_user->ID ); ?>" <?php selected( $ai_user->ID, $default_ai_user_id ); ?>>
												<?php echo esc_html( sprintf( '%s (%s)', $ai_user->display_name, $ai_user->user_login ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Generate API Key', 'wp-ai-gatekeeper' ) ); ?>
					</form>
				</div>

				<div class="wpai-settings-card">
					<h2><?php esc_html_e( 'Active Security Policy (Enforced in Core & REST)', 'wp-ai-gatekeeper' ); ?></h2>
					<ul class="wpai-policy-list">
						<li class="wpai-policy-allow">
							<strong><?php esc_html_e( 'Read All Articles:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'AI can read all published and draft posts/pages.', 'wp-ai-gatekeeper' ); ?>
						</li>
						<li class="wpai-policy-allow">
							<strong><?php esc_html_e( 'Create & Edit Own Drafts:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'AI can create new drafts and edit drafts it authored.', 'wp-ai-gatekeeper' ); ?>
						</li>
						<li class="wpai-policy-allow">
							<strong><?php esc_html_e( 'Upload Media:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'AI can upload images and media attachments.', 'wp-ai-gatekeeper' ); ?>
						</li>
						<li class="wpai-policy-allow">
							<strong><?php esc_html_e( 'PR-Style Suggestions & Dialogue:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'AI can propose changes on any article, explain its reasoning, read human feedback, and update open suggestions.', 'wp-ai-gatekeeper' ); ?>
						</li>
						<li class="wpai-policy-deny">
							<strong><?php esc_html_e( 'No Publishing / Unpublishing:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'AI cannot publish, schedule, or unpublish any article.', 'wp-ai-gatekeeper' ); ?>
						</li>
						<li class="wpai-policy-deny">
							<strong><?php esc_html_e( 'No Comment Moderation or Creation:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'AI cannot create, edit, or moderate WordPress blog comments.', 'wp-ai-gatekeeper' ); ?>
						</li>
						<li class="wpai-policy-deny">
							<strong><?php esc_html_e( 'Immutable Merged Suggestions:', 'wp-ai-gatekeeper' ); ?></strong>
							<?php esc_html_e( 'Only humans can merge suggestions. Once merged, a suggestion is permanently locked.', 'wp-ai-gatekeeper' ); ?>
						</li>
					</ul>
					<form method="post" action="">
						<?php wp_nonce_field( 'wpai_settings_action', 'wpai_settings_nonce' ); ?>
						<input type="hidden" name="wpai_action" value="sync_ai_user" />
						<?php submit_button( __( 'Re-Sync AI Role & Service Account', 'wp-ai-gatekeeper' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="wpai-settings-card">
					<h2><?php esc_html_e( 'Authorship', 'wp-ai-gatekeeper' ); ?></h2>
					<?php if ( isset( $_GET['updated'] ) && 'settings_saved' === $_GET['updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
						<div class="notice notice-success inline"><p><?php esc_html_e( 'Settings saved.', 'wp-ai-gatekeeper' ); ?></p></div>
					<?php endif; ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'wpai_settings_action', 'wpai_settings_nonce' ); ?>
						<input type="hidden" name="wpai_action" value="save_authorship" />
						<p>
							<label>
								<input type="checkbox" name="reassign_author_on_publish" value="1" <?php checked( WP_AI_Gatekeeper_Authorship::is_enabled() ); ?> />
								<?php esc_html_e( 'When an AI draft is published, make the person who publishes it the author', 'wp-ai-gatekeeper' ); ?>
							</label>
						</p>
						<p class="description">
							<?php esc_html_e( 'The AI stays recorded as "Drafted by AI" in the posts list and the API, but not on the public site. For scheduled posts, the person who scheduled the post becomes the author.', 'wp-ai-gatekeeper' ); ?>
						</p>
						<?php submit_button( __( 'Save', 'wp-ai-gatekeeper' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<h2><?php esc_html_e( 'Configured API Keys', 'wp-ai-gatekeeper' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'wp-ai-gatekeeper' ); ?></th>
						<th><?php esc_html_e( 'Key Prefix', 'wp-ai-gatekeeper' ); ?></th>
						<th><?php esc_html_e( 'AI Account', 'wp-ai-gatekeeper' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-ai-gatekeeper' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'wp-ai-gatekeeper' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wp-ai-gatekeeper' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-ai-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $keys ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No API keys generated yet.', 'wp-ai-gatekeeper' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $keys as $key_row ) : ?>
							<?php
							$key_user  = ! empty( $key_row['user_id'] ) ? get_userdata( (int) $key_row['user_id'] ) : false;
							$is_active = empty( $key_row['revoked'] );
							$key_label = isset( $key_row['name'] ) ? $key_row['name'] : ( isset( $key_row['label'] ) ? $key_row['label'] : '' );
							?>
							<tr>
								<td><strong><?php echo esc_html( $key_label ); ?></strong></td>
								<td><code><?php echo esc_html( $key_row['key_prefix'] ); ?>...</code></td>
								<td><?php echo esc_html( $key_user ? $key_user->user_login : '#' . $key_row['user_id'] ); ?></td>
								<td>
									<?php if ( $is_active ) : ?>
										<span class="wpai-status-badge wpai-badge-open"><?php esc_html_e( 'Active', 'wp-ai-gatekeeper' ); ?></span>
									<?php else : ?>
										<span class="wpai-status-badge wpai-badge-rejected"><?php esc_html_e( 'Revoked', 'wp-ai-gatekeeper' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo ! empty( $key_row['last_used_at'] ) ? esc_html( $key_row['last_used_at'] ) : esc_html__( 'Never', 'wp-ai-gatekeeper' ); ?></td>
								<td><?php echo esc_html( $key_row['created_at'] ); ?></td>
								<td>
									<?php if ( $is_active ) : ?>
										<form method="post" action="" style="display:inline;">
											<?php wp_nonce_field( 'wpai_settings_action', 'wpai_settings_nonce' ); ?>
											<input type="hidden" name="wpai_action" value="revoke_key" />
											<input type="hidden" name="key_id" value="<?php echo esc_attr( $key_row['id'] ); ?>" />
											<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'wp-ai-gatekeeper' ); ?></button>
										</form>
									<?php endif; ?>
									<form method="post" action="" style="display:inline;">
										<?php wp_nonce_field( 'wpai_settings_action', 'wpai_settings_nonce' ); ?>
										<input type="hidden" name="wpai_action" value="delete_key" />
										<input type="hidden" name="key_id" value="<?php echo esc_attr( $key_row['id'] ); ?>" />
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'wp-ai-gatekeeper' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
