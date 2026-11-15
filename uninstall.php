<?php
/**
 * Uninstall handler for WP AI Gatekeeper.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$delete_data = (bool) get_option( 'wp_ai_gatekeeper_delete_data_on_uninstall', false );

delete_option( 'wp_ai_gatekeeper_api_keys' );
delete_option( 'wp_ai_gatekeeper_db_version' );
delete_option( 'wp_ai_gatekeeper_default_ai_user_id' );
delete_option( 'wp_ai_gatekeeper_settings' );
delete_option( 'wp_ai_gatekeeper_delete_data_on_uninstall' );

remove_role( 'ai_contributor' );

if ( $delete_data ) {
	$suggestions_table = $wpdb->prefix . 'ai_suggestions';
	$comments_table    = $wpdb->prefix . 'ai_suggestion_comments';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$comments_table}" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$suggestions_table}" );
}
