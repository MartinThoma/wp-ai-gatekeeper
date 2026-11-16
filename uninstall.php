<?php
/**
 * Uninstall handler for WP AI Gatekeeper.
 *
 * Always removed: settings, API keys, the AI role, Application Passwords of
 * AI accounts, and internal post meta. Merged changes are ordinary post
 * content and revisions, so published articles are unaffected.
 *
 * Kept unless "Delete all plugin data" is enabled on the settings page:
 * suggestions with their discussions (two database tables) and the "Drafted
 * by AI" record on posts.
 *
 * Never deleted: the AI service account. It may be the author of posts, and
 * deleting a user means deciding what happens to them; do that under Users
 * if the account is no longer needed. Without its role it can do nothing.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wp_ai_gatekeeper_delete_data = (bool) get_option( 'wp_ai_gatekeeper_delete_data_on_uninstall', false );

// AI accounts lose their role below; also revoke any Application Passwords so
// nothing can sign in as them afterwards.
$wp_ai_gatekeeper_ai_users = get_users(
	array(
		'role'   => 'ai_contributor',
		'fields' => 'ID',
	)
);
if ( class_exists( 'WP_Application_Passwords' ) ) {
	foreach ( $wp_ai_gatekeeper_ai_users as $wp_ai_gatekeeper_ai_user ) {
		WP_Application_Passwords::delete_all_application_passwords( (int) $wp_ai_gatekeeper_ai_user );
	}
}

delete_option( 'wp_ai_gatekeeper_api_keys' );
delete_option( 'wp_ai_gatekeeper_db_version' );
delete_option( 'wp_ai_gatekeeper_version' );
delete_site_transient( 'wp_ai_gatekeeper_update_manifest' );
delete_option( 'wp_ai_gatekeeper_default_ai_user_id' );
delete_option( 'wp_ai_gatekeeper_settings' );
delete_option( 'wp_ai_gatekeeper_delete_data_on_uninstall' );

remove_role( 'ai_contributor' );

// Internal bookkeeping that only matters while the plugin runs.
delete_post_meta_by_key( '_wpai_merge_count' );
delete_post_meta_by_key( '_wpai_scheduled_by' );

if ( $wp_ai_gatekeeper_delete_data ) {
	delete_post_meta_by_key( '_wpai_drafted_by' );

	$wp_ai_gatekeeper_suggestions_table = $wpdb->prefix . 'ai_suggestions';
	$wp_ai_gatekeeper_comments_table    = $wpdb->prefix . 'ai_suggestion_comments';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wp_ai_gatekeeper_comments_table}" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wp_ai_gatekeeper_suggestions_table}" );
}
