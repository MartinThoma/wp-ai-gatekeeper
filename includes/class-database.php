<?php
/**
 * Database schema and query layer for AI suggestions and review comments.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles custom database tables for suggestions and comment threads.
 */
class WP_AI_Gatekeeper_Database {

	/**
	 * Returns suggestions table name.
	 *
	 * @return string
	 */
	public static function get_suggestions_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_suggestions';
	}

	/**
	 * Returns suggestion comments table name.
	 *
	 * @return string
	 */
	public static function get_comments_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_suggestion_comments';
	}

	/**
	 * Creates or upgrades custom plugin database tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate   = $wpdb->get_charset_collate();
		$suggestions_table = self::get_suggestions_table();
		$comments_table    = self::get_comments_table();

		$sql_suggestions = "CREATE TABLE {$suggestions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			author_user_id bigint(20) unsigned NOT NULL,
			target_field varchar(32) NOT NULL DEFAULT 'post_content',
			block_id varchar(128) NOT NULL DEFAULT '',
			original_text longtext NOT NULL,
			suggested_text longtext NOT NULL,
			context_before text DEFAULT NULL,
			context_after text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			merged_by bigint(20) unsigned DEFAULT NULL,
			merged_at datetime DEFAULT NULL,
			merged_revision_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY author_user_id (author_user_id)
		) {$charset_collate};";

		$sql_comments = "CREATE TABLE {$comments_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			suggestion_id bigint(20) unsigned NOT NULL,
			author_user_id bigint(20) unsigned NOT NULL,
			author_type varchar(16) NOT NULL DEFAULT 'human',
			author_name varchar(128) NOT NULL DEFAULT '',
			event_type varchar(32) NOT NULL DEFAULT 'comment',
			comment_text longtext NOT NULL,
			suggestion_snapshot longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY suggestion_id (suggestion_id)
		) {$charset_collate};";

		dbDelta( $sql_suggestions );
		dbDelta( $sql_comments );
	}

	/**
	 * Inserts a new suggestion record.
	 *
	 * @param array $data Suggestion fields.
	 * @return int|WP_Error Inserted ID or WP_Error.
	 */
	public static function insert_suggestion( array $data ) {
		global $wpdb;

		$table = self::get_suggestions_table();
		$now   = current_time( 'mysql', true );

		$row = array(
			'post_id'        => absint( $data['post_id'] ),
			'author_user_id' => absint( $data['author_user_id'] ),
			'target_field'   => sanitize_key( $data['target_field'] ?? 'post_content' ),
			'block_id'       => sanitize_text_field( $data['block_id'] ?? '' ),
			'original_text'  => (string) ( $data['original_text'] ?? '' ),
			'suggested_text' => (string) ( $data['suggested_text'] ?? '' ),
			'context_before' => isset( $data['context_before'] ) ? (string) $data['context_before'] : null,
			'context_after'  => isset( $data['context_after'] ) ? (string) $data['context_after'] : null,
			'status'         => sanitize_key( $data['status'] ?? 'open' ),
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$inserted = $wpdb->insert( $table, $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error(
				'db_insert_failed',
				__( 'Could not save the suggestion to the database.', 'wp-ai-gatekeeper' ),
				array( 'status' => 500 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetches a single suggestion row by ID.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return object|null
	 */
	public static function get_suggestion( $suggestion_id ) {
		global $wpdb;

		$table = self::get_suggestions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $suggestion_id ) ) );
	}

	/**
	 * Updates a suggestion row.
	 *
	 * @param int   $suggestion_id Suggestion ID.
	 * @param array $data          Fields to update.
	 * @return bool|WP_Error
	 */
	public static function update_suggestion( $suggestion_id, array $data ) {
		global $wpdb;

		$table = self::get_suggestions_table();
		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
		}

		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'post_id', 'author_user_id', 'merged_by', 'merged_revision_id' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => absint( $suggestion_id ) ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'db_update_failed',
				__( 'Could not update the suggestion in the database.', 'wp-ai-gatekeeper' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Builds WHERE clause and params for suggestion queries.
	 *
	 * @param array $args Query filters.
	 * @return array{where_sql: string, params: array}
	 */
	private static function build_query_where( array $args ) {
		$comments_table = self::get_comments_table();
		$clauses        = array( '1=1' );
		$params         = array();

		if ( ! empty( $args['post_id'] ) ) {
			$clauses[] = 's.post_id = %d';
			$params[]  = absint( $args['post_id'] );
		}

		if ( ! empty( $args['author_user_id'] ) ) {
			$clauses[] = 's.author_user_id = %d';
			$params[]  = absint( $args['author_user_id'] );
		}

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$clauses[] = 's.status = %s';
			$params[]  = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['needs_ai_reply'] ) ) {
			$clauses[] = "s.status = 'open'";
			$clauses[] = "(
				SELECT c.author_type
				FROM {$comments_table} c
				WHERE c.suggestion_id = s.id
				ORDER BY c.created_at DESC, c.id DESC
				LIMIT 1
			) = 'human'";
		}

		return array(
			'where_sql' => implode( ' AND ', $clauses ),
			'params'    => $params,
		);
	}

	/**
	 * Queries suggestions matching filters.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function query_suggestions( array $args = array() ) {
		global $wpdb;

		$table  = self::get_suggestions_table();
		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 20;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$allowed_orderby = array( 'id', 'created_at', 'updated_at', 'status', 'post_id' );
		$orderby         = isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$where_data = self::build_query_where( $args );
		$sql        = "SELECT s.* FROM {$table} s WHERE {$where_data['where_sql']} ORDER BY s.{$orderby} {$order}, s.id {$order} LIMIT %d OFFSET %d";
		$params     = array_merge( $where_data['params'], array( $limit, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Counts suggestions matching filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count_suggestions( array $args = array() ) {
		global $wpdb;

		$table      = self::get_suggestions_table();
		$where_data = self::build_query_where( $args );
		$sql        = "SELECT COUNT(*) FROM {$table} s WHERE {$where_data['where_sql']}";

		if ( empty( $where_data['params'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where_data['params'] ) );
	}

	/**
	 * Returns summary counts by status for UI tabs and badges.
	 *
	 * @param int $post_id Optional post ID filter.
	 * @return array<string, int>
	 */
	public static function get_status_counts( $post_id = 0 ) {
		$base_args = $post_id ? array( 'post_id' => absint( $post_id ) ) : array();

		return array(
			'open'           => self::count_suggestions( array_merge( $base_args, array( 'status' => 'open' ) ) ),
			'needs_ai_reply' => self::count_suggestions( array_merge( $base_args, array( 'needs_ai_reply' => true ) ) ),
			'merged'         => self::count_suggestions( array_merge( $base_args, array( 'status' => 'merged' ) ) ),
			'rejected'       => self::count_suggestions( array_merge( $base_args, array( 'status' => 'rejected' ) ) ),
			'all'            => self::count_suggestions( array_merge( $base_args, array( 'status' => 'all' ) ) ),
		);
	}

	/**
	 * Inserts a comment or timeline event into a suggestion thread.
	 *
	 * @param array $data Comment fields.
	 * @return int|WP_Error
	 */
	public static function insert_comment( array $data ) {
		global $wpdb;

		$table = self::get_comments_table();
		$now   = current_time( 'mysql', true );

		$row = array(
			'suggestion_id'       => absint( $data['suggestion_id'] ),
			'author_user_id'      => absint( $data['author_user_id'] ),
			'author_type'         => in_array( $data['author_type'] ?? 'human', array( 'ai', 'human' ), true ) ? $data['author_type'] : 'human',
			'author_name'         => sanitize_text_field( $data['author_name'] ?? '' ),
			'event_type'          => sanitize_key( $data['event_type'] ?? 'comment' ),
			'comment_text'        => (string) ( $data['comment_text'] ?? '' ),
			'suggestion_snapshot' => isset( $data['suggestion_snapshot'] ) ? (string) $data['suggestion_snapshot'] : null,
			'created_at'          => $now,
		);

		$inserted = $wpdb->insert(
			$table,
			$row,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'db_comment_insert_failed',
				__( 'Could not save the suggestion comment.', 'wp-ai-gatekeeper' ),
				array( 'status' => 500 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieves chronological comments for a suggestion.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return array
	 */
	public static function get_comments_for_suggestion( $suggestion_id ) {
		global $wpdb;

		$table = self::get_comments_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE suggestion_id = %d ORDER BY created_at ASC, id ASC",
				absint( $suggestion_id )
			)
		);
	}

	/**
	 * Fetches comments grouped by suggestion ID to prevent N+1 queries.
	 *
	 * @param int[] $suggestion_ids List of suggestion IDs.
	 * @return array<int, array>
	 */
	public static function get_comments_map( array $suggestion_ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $suggestion_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::get_comments_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT * FROM {$table} WHERE suggestion_id IN ({$placeholders}) ORDER BY created_at ASC, id ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) );

		$map = array();
		foreach ( $ids as $id ) {
			$map[ $id ] = array();
		}
		foreach ( $rows as $row ) {
			$map[ (int) $row->suggestion_id ][] = $row;
		}

		return $map;
	}

	/**
	 * Returns open suggestion counts keyed by post ID.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, int>
	 */
	public static function get_open_counts_by_post( array $post_ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::get_suggestions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT post_id, COUNT(*) AS cnt FROM {$table} WHERE status = 'open' AND post_id IN ({$placeholders}) GROUP BY post_id";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) );

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->post_id ] = (int) $row->cnt;
		}

		return $counts;
	}
}
