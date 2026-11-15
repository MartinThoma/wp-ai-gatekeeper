<?php
/**
 * Business logic for GitHub PR-style AI suggestions, dialogue comments, and merging.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates lifecycle operations on AI article change suggestions.
 */
class WP_AI_Gatekeeper_Suggestion_Service {

	const STATUS_OPEN     = 'open';
	const STATUS_MERGED   = 'merged';
	const STATUS_REJECTED = 'rejected';

	const ALLOWED_FIELDS = array( 'post_content', 'post_title', 'post_excerpt' );

	/**
	 * Creates a new change suggestion on an existing article (draft or published).
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $params  Suggestion payload.
	 * @param int   $user_id Authoring user ID.
	 * @return array|WP_Error Hydrated suggestion details or WP_Error.
	 */
	public static function create_suggestion( $post_id, array $params, $user_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || 'post' !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error(
				'post_not_found',
				__( 'Target article was not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		$target_field = isset( $params['target_field'] ) ? sanitize_key( $params['target_field'] ) : 'post_content';
		if ( ! in_array( $target_field, self::ALLOWED_FIELDS, true ) ) {
			return new WP_Error(
				'invalid_target_field',
				__( 'target_field must be one of post_content, post_title, or post_excerpt.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		$original_text  = isset( $params['original_text'] ) ? (string) $params['original_text'] : '';
		$suggested_text = isset( $params['suggested_text'] ) ? (string) $params['suggested_text'] : '';
		$context_before = isset( $params['context_before'] ) ? (string) $params['context_before'] : '';
		$context_after  = isset( $params['context_after'] ) ? (string) $params['context_after'] : '';
		$block_id       = isset( $params['block_id'] ) ? sanitize_text_field( (string) $params['block_id'] ) : '';
		$comment_text   = isset( $params['comment'] ) ? trim( (string) $params['comment'] ) : '';

		if ( '' === $original_text ) {
			return new WP_Error(
				'missing_original_text',
				__( 'original_text is required and cannot be empty.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		if ( $original_text === $suggested_text ) {
			return new WP_Error(
				'identical_suggestion',
				__( 'suggested_text must differ from original_text.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		$dry_run = self::apply_guarded(
			(string) $post->{$target_field},
			$target_field,
			$original_text,
			$suggested_text,
			$context_before,
			$context_after,
			$user_id
		);

		if ( is_wp_error( $dry_run ) ) {
			return self::as_dry_run_error( $dry_run );
		}
		$suggested_text = $dry_run['suggested_text'];

		$suggestion_id = WP_AI_Gatekeeper_Database::insert_suggestion(
			array(
				'post_id'        => $post->ID,
				'author_user_id' => absint( $user_id ),
				'target_field'   => $target_field,
				'block_id'       => $block_id,
				'original_text'  => $original_text,
				'suggested_text' => $suggested_text,
				'context_before' => '' !== $context_before ? $context_before : null,
				'context_after'  => '' !== $context_after ? $context_after : null,
				'status'         => self::STATUS_OPEN,
			)
		);

		if ( is_wp_error( $suggestion_id ) ) {
			return $suggestion_id;
		}

		if ( '' !== $comment_text ) {
			$author = self::resolve_actor_info( $user_id );
			WP_AI_Gatekeeper_Database::insert_comment(
				array(
					'suggestion_id'       => $suggestion_id,
					'author_user_id'      => absint( $user_id ),
					'author_type'         => $author['type'],
					'author_name'         => $author['name'],
					'event_type'          => 'initial_explanation',
					'comment_text'        => sanitize_textarea_field( $comment_text ),
					'suggestion_snapshot' => $suggested_text,
				)
			);
		}

		return self::get_suggestion_details( $suggestion_id );
	}

	/**
	 * Updates an open suggestion (e.g. AI iterating after reading human feedback).
	 * Strictly refuses modifications once merged or rejected.
	 *
	 * @param int   $suggestion_id Suggestion ID.
	 * @param array $params        Update fields (suggested_text, optional original_text/context/comment).
	 * @param int   $user_id       Acting user ID.
	 * @return array|WP_Error
	 */
	public static function update_suggestion( $suggestion_id, array $params, $user_id ) {
		$suggestion = WP_AI_Gatekeeper_Database::get_suggestion( $suggestion_id );
		if ( ! $suggestion ) {
			return new WP_Error(
				'suggestion_not_found',
				__( 'Suggestion not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( self::STATUS_MERGED === $suggestion->status ) {
			return new WP_Error(
				'suggestion_already_merged',
				__( 'This suggestion has already been merged and cannot be changed anymore.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		if ( self::STATUS_OPEN !== $suggestion->status ) {
			return new WP_Error(
				'suggestion_not_open',
				__( 'Only open suggestions can be updated.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		$is_author = (int) $suggestion->author_user_id === (int) $user_id;
		$can_edit  = user_can( $user_id, 'edit_post', (int) $suggestion->post_id );
		if ( ! $is_author && ! $can_edit ) {
			return new WP_Error(
				'suggestion_update_forbidden',
				__( 'You do not have permission to update this suggestion.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		$post = get_post( (int) $suggestion->post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Target article no longer exists.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		$new_original  = array_key_exists( 'original_text', $params ) ? (string) $params['original_text'] : (string) $suggestion->original_text;
		$new_suggested = array_key_exists( 'suggested_text', $params ) ? (string) $params['suggested_text'] : (string) $suggestion->suggested_text;
		$new_pre       = array_key_exists( 'context_before', $params ) ? (string) $params['context_before'] : (string) ( $suggestion->context_before ?? '' );
		$new_post_ctx  = array_key_exists( 'context_after', $params ) ? (string) $params['context_after'] : (string) ( $suggestion->context_after ?? '' );
		$new_block_id  = array_key_exists( 'block_id', $params ) ? sanitize_text_field( (string) $params['block_id'] ) : (string) $suggestion->block_id;
		$comment_text  = isset( $params['comment'] ) ? trim( (string) $params['comment'] ) : '';

		if ( '' === $new_original ) {
			return new WP_Error(
				'missing_original_text',
				__( 'original_text cannot be empty.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		if ( $new_original === $new_suggested ) {
			return new WP_Error(
				'identical_suggestion',
				__( 'suggested_text must differ from original_text.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		// Guarded with the suggestion author's capabilities: an AI suggestion stays
		// filtered even when a trusted human edits it.
		$dry_run = self::apply_guarded(
			(string) $post->{$suggestion->target_field},
			$suggestion->target_field,
			$new_original,
			$new_suggested,
			$new_pre,
			$new_post_ctx,
			(int) $suggestion->author_user_id
		);

		if ( is_wp_error( $dry_run ) ) {
			return self::as_dry_run_error( $dry_run );
		}
		$new_suggested = $dry_run['suggested_text'];

		$update_res = WP_AI_Gatekeeper_Database::update_suggestion(
			$suggestion->id,
			array(
				'original_text'  => $new_original,
				'suggested_text' => $new_suggested,
				'context_before' => '' !== $new_pre ? $new_pre : null,
				'context_after'  => '' !== $new_post_ctx ? $new_post_ctx : null,
				'block_id'       => $new_block_id,
			)
		);

		if ( is_wp_error( $update_res ) ) {
			return $update_res;
		}

		$actor = self::resolve_actor_info( $user_id );
		WP_AI_Gatekeeper_Database::insert_comment(
			array(
				'suggestion_id'       => $suggestion->id,
				'author_user_id'      => absint( $user_id ),
				'author_type'         => $actor['type'],
				'author_name'         => $actor['name'],
				'event_type'          => 'suggestion_updated',
				'comment_text'        => '' !== $comment_text
					? sanitize_textarea_field( $comment_text )
					: __( 'Updated the suggested change.', 'wp-ai-gatekeeper' ),
				'suggestion_snapshot' => $new_suggested,
			)
		);

		return self::get_suggestion_details( $suggestion->id );
	}

	/**
	 * Adds a review dialogue comment to an open suggestion.
	 * Both humans and the AI can comment while open; locked permanently after merge.
	 *
	 * @param int    $suggestion_id Suggestion ID.
	 * @param string $comment_text  Comment body.
	 * @param int    $user_id       Commenting user ID.
	 * @return array|WP_Error
	 */
	public static function add_comment( $suggestion_id, $comment_text, $user_id ) {
		$suggestion = WP_AI_Gatekeeper_Database::get_suggestion( $suggestion_id );
		if ( ! $suggestion ) {
			return new WP_Error(
				'suggestion_not_found',
				__( 'Suggestion not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( self::STATUS_MERGED === $suggestion->status ) {
			return new WP_Error(
				'suggestion_already_merged',
				__( 'This suggestion has already been merged and cannot be commented on or changed anymore.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		if ( self::STATUS_OPEN !== $suggestion->status ) {
			return new WP_Error(
				'suggestion_not_open',
				__( 'Comments can only be added to open suggestions.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		$is_ai    = WP_AI_Gatekeeper_Auth::is_ai_user( $user_id );
		$can_edit = user_can( $user_id, 'edit_post', (int) $suggestion->post_id );
		if ( ! $is_ai && ! $can_edit ) {
			return new WP_Error(
				'suggestion_comment_forbidden',
				__( 'You do not have permission to comment on this suggestion.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		$comment_text = trim( (string) $comment_text );
		if ( '' === $comment_text ) {
			return new WP_Error(
				'empty_comment',
				__( 'Comment text cannot be empty.', 'wp-ai-gatekeeper' ),
				array( 'status' => 400 )
			);
		}

		$actor    = self::resolve_actor_info( $user_id );
		$inserted = WP_AI_Gatekeeper_Database::insert_comment(
			array(
				'suggestion_id'       => $suggestion->id,
				'author_user_id'      => absint( $user_id ),
				'author_type'         => $actor['type'],
				'author_name'         => $actor['name'],
				'event_type'          => 'comment',
				'comment_text'        => sanitize_textarea_field( $comment_text ),
				'suggestion_snapshot' => null,
			)
		);

		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		WP_AI_Gatekeeper_Database::update_suggestion(
			$suggestion->id,
			array( 'updated_at' => current_time( 'mysql', true ) )
		);

		return self::get_suggestion_details( $suggestion->id );
	}

	/**
	 * Merges an open suggestion into the target article.
	 * Strictly restricted to human editors; locks the suggestion permanently.
	 *
	 * @param int    $suggestion_id Suggestion ID.
	 * @param int    $user_id       Human reviewer user ID.
	 * @param string $comment       Optional merge note.
	 * @return array|WP_Error
	 */
	public static function merge_suggestion( $suggestion_id, $user_id, $comment = '' ) {
		if ( WP_AI_Gatekeeper_Auth::is_ai_user( $user_id ) ) {
			return new WP_Error(
				'ai_cannot_merge',
				__( 'AI agents are not allowed to merge suggestions. A human editor must approve and merge changes.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		$suggestion = WP_AI_Gatekeeper_Database::get_suggestion( $suggestion_id );
		if ( ! $suggestion ) {
			return new WP_Error(
				'suggestion_not_found',
				__( 'Suggestion not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		// Check the article exists first: a permission error would be misleading.
		$post = get_post( (int) $suggestion->post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Target article no longer exists.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( ! user_can( $user_id, 'edit_post', (int) $suggestion->post_id ) ) {
			return new WP_Error(
				'merge_forbidden',
				__( 'You do not have permission to edit this article or merge suggestions.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		if ( self::STATUS_MERGED === $suggestion->status ) {
			return new WP_Error(
				'suggestion_already_merged',
				__( 'This suggestion has already been merged and cannot be changed anymore.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		if ( self::STATUS_OPEN !== $suggestion->status ) {
			return new WP_Error(
				'suggestion_not_open',
				__( 'Only open suggestions can be merged.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		// Re-checked against the current content: the context of the span may have
		// changed since the suggestion was made, and older rows were never filtered.
		$target_field = $suggestion->target_field;
		$applied      = self::apply_guarded(
			(string) $post->{$target_field},
			$target_field,
			(string) $suggestion->original_text,
			(string) $suggestion->suggested_text,
			(string) ( $suggestion->context_before ?? '' ),
			(string) ( $suggestion->context_after ?? '' ),
			(int) $suggestion->author_user_id
		);

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$update_result = wp_update_post(
			array(
				'ID'          => $post->ID,
				$target_field => wp_slash( $applied['content'] ),
			),
			true
		);

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		$revisions   = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 1 ) );
		$revision_id = 0;
		if ( ! empty( $revisions ) ) {
			$latest_rev  = reset( $revisions );
			$revision_id = (int) $latest_rev->ID;
		}

		$now          = current_time( 'mysql', true );
		$merge_update = array(
			'status'             => self::STATUS_MERGED,
			'merged_by'          => absint( $user_id ),
			'merged_at'          => $now,
			'merged_revision_id' => $revision_id > 0 ? $revision_id : null,
			'updated_at'         => $now,
		);
		if ( $applied['suggested_text'] !== (string) $suggestion->suggested_text ) {
			// Record exactly what was merged (only differs for pre-guard rows).
			$merge_update['suggested_text'] = $applied['suggested_text'];
		}
		WP_AI_Gatekeeper_Database::update_suggestion( $suggestion->id, $merge_update );

		$actor        = self::resolve_actor_info( $user_id );
		$comment_body = trim( (string) $comment );
		WP_AI_Gatekeeper_Database::insert_comment(
			array(
				'suggestion_id'       => $suggestion->id,
				'author_user_id'      => absint( $user_id ),
				'author_type'         => 'human',
				'author_name'         => $actor['name'],
				'event_type'          => 'merged',
				'comment_text'        => '' !== $comment_body
					? sanitize_textarea_field( $comment_body )
					: __( 'Merged this suggestion into the article.', 'wp-ai-gatekeeper' ),
				'suggestion_snapshot' => null,
			)
		);

		$details                 = self::get_suggestion_details( $suggestion->id );
		$updated_post            = get_post( $post->ID );
		$details['updated_post'] = $updated_post ? array(
			'id'           => (int) $updated_post->ID,
			'post_title'   => (string) $updated_post->post_title,
			'post_content' => (string) $updated_post->post_content,
			'post_excerpt' => (string) $updated_post->post_excerpt,
			'post_status'  => (string) $updated_post->post_status,
		) : null;

		return $details;
	}

	/**
	 * Rejects an open suggestion. Strictly restricted to human editors.
	 *
	 * @param int    $suggestion_id Suggestion ID.
	 * @param int    $user_id       Human reviewer user ID.
	 * @param string $comment       Optional rejection reason.
	 * @return array|WP_Error
	 */
	public static function reject_suggestion( $suggestion_id, $user_id, $comment = '' ) {
		if ( WP_AI_Gatekeeper_Auth::is_ai_user( $user_id ) ) {
			return new WP_Error(
				'ai_cannot_reject',
				__( 'AI agents are not allowed to reject suggestions. Only a human reviewer can reject changes.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		$suggestion = WP_AI_Gatekeeper_Database::get_suggestion( $suggestion_id );
		if ( ! $suggestion ) {
			return new WP_Error(
				'suggestion_not_found',
				__( 'Suggestion not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		if ( ! user_can( $user_id, 'edit_post', (int) $suggestion->post_id ) ) {
			return new WP_Error(
				'reject_forbidden',
				__( 'You do not have permission to edit this article or reject suggestions.', 'wp-ai-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		if ( self::STATUS_MERGED === $suggestion->status ) {
			return new WP_Error(
				'suggestion_already_merged',
				__( 'This suggestion has already been merged and cannot be rejected or changed anymore.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		if ( self::STATUS_OPEN !== $suggestion->status ) {
			return new WP_Error(
				'suggestion_not_open',
				__( 'Only open suggestions can be rejected.', 'wp-ai-gatekeeper' ),
				array( 'status' => 409 )
			);
		}

		WP_AI_Gatekeeper_Database::update_suggestion(
			$suggestion->id,
			array(
				'status'     => self::STATUS_REJECTED,
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		$actor        = self::resolve_actor_info( $user_id );
		$comment_body = trim( (string) $comment );
		WP_AI_Gatekeeper_Database::insert_comment(
			array(
				'suggestion_id'       => $suggestion->id,
				'author_user_id'      => absint( $user_id ),
				'author_type'         => 'human',
				'author_name'         => $actor['name'],
				'event_type'          => 'rejected',
				'comment_text'        => '' !== $comment_body
					? sanitize_textarea_field( $comment_body )
					: __( 'Rejected this suggestion.', 'wp-ai-gatekeeper' ),
				'suggestion_snapshot' => null,
			)
		);

		return self::get_suggestion_details( $suggestion->id );
	}

	/**
	 * Fetches a single hydrated suggestion with diff and comments.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return array|WP_Error
	 */
	public static function get_suggestion_details( $suggestion_id ) {
		$row = WP_AI_Gatekeeper_Database::get_suggestion( $suggestion_id );
		if ( ! $row ) {
			return new WP_Error(
				'suggestion_not_found',
				__( 'Suggestion not found.', 'wp-ai-gatekeeper' ),
				array( 'status' => 404 )
			);
		}

		$comments = WP_AI_Gatekeeper_Database::get_comments_for_suggestion( $row->id );
		return self::hydrate_suggestion( $row, $comments );
	}

	/**
	 * Lists suggestions with pagination, filtering, and status summary counts.
	 *
	 * @param array $args Filter arguments.
	 * @return array{items: array, total: int, counts: array<string, int>}
	 */
	public static function list_suggestions( array $args = array() ) {
		$rows  = WP_AI_Gatekeeper_Database::query_suggestions( $args );
		$total = WP_AI_Gatekeeper_Database::count_suggestions( $args );

		$ids          = array_map(
			static function ( $r ) {
				return (int) $r->id;
			},
			$rows
		);
		$comments_map = WP_AI_Gatekeeper_Database::get_comments_map( $ids );

		$items = array();
		foreach ( $rows as $row ) {
			$row_comments = isset( $comments_map[ (int) $row->id ] ) ? $comments_map[ (int) $row->id ] : array();
			$items[]      = self::hydrate_suggestion( $row, $row_comments );
		}

		$post_id = ! empty( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;

		return array(
			'items'  => $items,
			'total'  => $total,
			'counts' => WP_AI_Gatekeeper_Database::get_status_counts( $post_id ),
		);
	}

	/**
	 * Convenience wrapper returning the flat list of hydrated suggestion items.
	 *
	 * @param array $args Filter arguments.
	 * @return array
	 */
	public static function get_suggestions( array $args = array() ) {
		if ( isset( $args['per_page'] ) && ! isset( $args['limit'] ) ) {
			$args['limit'] = absint( $args['per_page'] );
		}
		$result = self::list_suggestions( $args );
		return isset( $result['items'] ) ? $result['items'] : array();
	}

	/**
	 * Hydrates a raw suggestion row with post metadata, diff, applicability, and thread comments.
	 *
	 * @param object $row      Database row from ai_suggestions.
	 * @param array  $comments Chronological comment rows.
	 * @return array
	 */
	private static function hydrate_suggestion( $row, array $comments ) {
		$post          = get_post( (int) $row->post_id );
		$author_info   = self::resolve_actor_info( (int) $row->author_user_id );
		$merged_by     = ! empty( $row->merged_by ) ? self::resolve_actor_info( (int) $row->merged_by ) : null;
		$is_applicable = false;
		$conflict_msg  = null;

		if ( self::STATUS_OPEN === $row->status && $post ) {
			$check = self::apply_guarded(
				(string) $post->{$row->target_field},
				(string) $row->target_field,
				(string) $row->original_text,
				(string) $row->suggested_text,
				(string) ( $row->context_before ?? '' ),
				(string) ( $row->context_after ?? '' ),
				(int) $row->author_user_id
			);
			if ( is_wp_error( $check ) ) {
				$is_applicable = false;
				$conflict_msg  = $check->get_error_message();
			} else {
				$is_applicable = true;
			}
		}

		$formatted_comments = array();
		$last_author_type   = null;
		foreach ( $comments as $c ) {
			$last_author_type     = $c->author_type;
			$formatted_comments[] = array(
				'id'                  => (int) $c->id,
				'suggestion_id'       => (int) $c->suggestion_id,
				'author_user_id'      => (int) $c->author_user_id,
				'author_type'         => (string) $c->author_type,
				'author_name'         => (string) $c->author_name,
				'event_type'          => (string) $c->event_type,
				'comment_text'        => (string) $c->comment_text,
				'suggestion_snapshot' => null !== $c->suggestion_snapshot ? (string) $c->suggestion_snapshot : null,
				'created_at'          => (string) $c->created_at,
			);
		}

		$needs_ai_reply = ( self::STATUS_OPEN === $row->status && 'human' === $last_author_type );

		return array(
			'id'                 => (int) $row->id,
			'post_id'            => (int) $row->post_id,
			'post_title'         => $post ? (string) get_the_title( $post ) : __( '(Deleted Post)', 'wp-ai-gatekeeper' ),
			'post_status'        => $post ? (string) $post->post_status : 'deleted',
			'post_edit_url'      => $post ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			'post_view_url'      => $post ? (string) get_permalink( $post->ID ) : '',
			'author_user_id'     => (int) $row->author_user_id,
			'author_name'        => $author_info['name'],
			'author_type'        => $author_info['type'],
			'target_field'       => (string) $row->target_field,
			'block_id'           => (string) $row->block_id,
			'original_text'      => (string) $row->original_text,
			'suggested_text'     => (string) $row->suggested_text,
			'context_before'     => null !== $row->context_before ? (string) $row->context_before : '',
			'context_after'      => null !== $row->context_after ? (string) $row->context_after : '',
			'status'             => (string) $row->status,
			'is_locked'          => ( self::STATUS_MERGED === $row->status ),
			'is_applicable'      => $is_applicable,
			'conflict_reason'    => $conflict_msg,
			'needs_ai_reply'     => $needs_ai_reply,
			'merged_by'          => $row->merged_by ? (int) $row->merged_by : null,
			'merged_by_name'     => $merged_by ? $merged_by['name'] : null,
			'merged_at'          => $row->merged_at ? (string) $row->merged_at : null,
			'merged_revision_id' => $row->merged_revision_id ? (int) $row->merged_revision_id : null,
			'created_at'         => (string) $row->created_at,
			'updated_at'         => (string) $row->updated_at,
			'word_diff'          => WP_AI_Gatekeeper_Diff::compute_word_diff( $row->original_text, $row->suggested_text ),
			'diff_html'          => WP_AI_Gatekeeper_Diff::render_inline_html( $row->original_text, $row->suggested_text ),
			'comments'           => $formatted_comments,
		);
	}

	/**
	 * Dry-runs a suggestion against field content, enforcing the markup guard
	 * when the author lacks unfiltered_html (always the case for AI users).
	 *
	 * @param string $content        Current field content.
	 * @param string $target_field   Target field.
	 * @param string $original_text  Text to replace.
	 * @param string $suggested_text Replacement text.
	 * @param string $context_before Optional preceding context.
	 * @param string $context_after  Optional trailing context.
	 * @param int    $author_id      Suggestion author's user ID.
	 * @return array|WP_Error Diff::apply_suggestion_to_text() result plus the
	 *                        (possibly filtered) 'suggested_text'.
	 */
	private static function apply_guarded( $content, $target_field, $original_text, $suggested_text, $context_before, $context_after, $author_id ) {
		$trusted   = WP_AI_Gatekeeper_Markup_Guard::is_trusted_author( $author_id );
		$sanitized = $trusted ? $suggested_text : WP_AI_Gatekeeper_Markup_Guard::sanitize( $suggested_text, $target_field );

		if ( $sanitized !== $suggested_text && $sanitized === $original_text ) {
			return new WP_Error(
				'suggestion_unsafe_markup',
				__( 'The suggestion only changes HTML that AI suggestions are not allowed to add.', 'wp-ai-gatekeeper' ),
				array( 'status' => 422 )
			);
		}

		$applied = WP_AI_Gatekeeper_Diff::apply_suggestion_to_text( $content, $original_text, $sanitized, $context_before, $context_after );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		if ( ! $trusted ) {
			$span = WP_AI_Gatekeeper_Markup_Guard::check_span(
				$content,
				$applied['offset'],
				$applied['length'],
				WP_AI_Gatekeeper_Markup_Guard::is_markup_edit( $original_text, $sanitized )
			);
			if ( is_wp_error( $span ) ) {
				return $span;
			}
		}

		$applied['suggested_text'] = $sanitized;
		return $applied;
	}

	/**
	 * Maps a dry-run failure to a REST error, keeping guard errors distinct.
	 *
	 * @param WP_Error $error Dry-run error.
	 * @return WP_Error
	 */
	private static function as_dry_run_error( WP_Error $error ) {
		if ( 'suggestion_unsafe_markup' === $error->get_error_code() ) {
			return $error;
		}
		return new WP_Error( 'original_text_not_found', $error->get_error_message(), array( 'status' => 422 ) );
	}

	/**
	 * Resolves display name and actor type ('ai' or 'human') for a user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{type: string, name: string}
	 */
	private static function resolve_actor_info( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		$type = WP_AI_Gatekeeper_Auth::is_ai_user( $user_id ) ? 'ai' : 'human';
		$name = $user ? $user->display_name : ( 'ai' === $type ? __( 'AI Assistant', 'wp-ai-gatekeeper' ) : __( 'Human Editor', 'wp-ai-gatekeeper' ) );

		return array(
			'type' => $type,
			'name' => $name,
		);
	}
}
