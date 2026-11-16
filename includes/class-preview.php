<?php
/**
 * Renders a suggestion inside the complete article ("show in full article").
 *
 * The article is rendered like the front end (the_content), then filtered with
 * wp_kses_post so trusted markup such as scripts cannot run in wp-admin. The
 * changed span is replaced by a marker before rendering and swapped for a
 * <del>/<ins> highlight afterwards. Highlighting only happens when the marker
 * sits in plain text both before and after rendering; otherwise the article is
 * shown without a highlight and a notice explains why.
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
 * Builds the full-article preview for a suggestion.
 */
class WP_AI_Gatekeeper_Preview {

	/**
	 * Placeholder for the changed span (Unicode private-use characters survive
	 * rendering and kses and never occur in real content).
	 */
	const MARKER = "\u{E000}wpai-change\u{E001}";

	/**
	 * Builds the preview.
	 *
	 * @param object  $row  Suggestion database row.
	 * @param WP_Post $post Target post.
	 * @return array{html: string, highlighted: bool, notice: string|null}
	 */
	public static function build( $row, WP_Post $post ) {
		$field  = (string) $row->target_field;
		$fields = array(
			'post_title'   => (string) $post->post_title,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_content' => (string) $post->post_content,
		);

		$span = self::locate( $row, $fields[ $field ] );
		if ( null === $span ) {
			return array(
				'html'        => self::render( $fields, $post, $field ),
				'highlighted' => false,
				'notice'      => 'merged' === $row->status
					? __( 'The merged text has been edited since, so it cannot be highlighted. Showing the current article.', 'wp-ai-gatekeeper' )
					: __( 'The text this suggestion changes is no longer in the article. Showing the current article.', 'wp-ai-gatekeeper' ),
			);
		}

		$in_text = 'text' === WP_AI_Gatekeeper_Markup_Guard::context_at( $fields[ $field ], $span[0] )
			&& 'text' === WP_AI_Gatekeeper_Markup_Guard::context_at( $fields[ $field ], $span[0] + $span[1] );

		if ( $in_text ) {
			$marked           = $fields;
			$marked[ $field ] = substr_replace( $fields[ $field ], self::MARKER, $span[0], $span[1] );
			$html             = self::render( $marked, $post, $field );
			$pos              = strpos( $html, self::MARKER );

			if ( false !== $pos
				&& 1 === substr_count( $html, self::MARKER )
				&& 'text' === WP_AI_Gatekeeper_Markup_Guard::context_at( $html, $pos )
			) {
				return array(
					'html'        => substr_replace( $html, self::change_html( $row ), $pos, strlen( self::MARKER ) ),
					'highlighted' => true,
					'notice'      => null,
				);
			}
		}

		return array(
			'html'        => self::render( $fields, $post, $field ),
			'highlighted' => false,
			'notice'      => __( 'This change is inside HTML markup or block settings, so it cannot be highlighted in the rendered article. See the diff above.', 'wp-ai-gatekeeper' ),
		);
	}

	/**
	 * Finds the [offset, length] of the changed span in the field, or null.
	 *
	 * Open and rejected suggestions point at the original text; merged ones at
	 * the text that was merged in.
	 *
	 * @param object $row     Suggestion row.
	 * @param string $content Field content.
	 * @return array{0: int, 1: int}|null
	 */
	private static function locate( $row, $content ) {
		$needle = 'merged' === $row->status ? (string) $row->suggested_text : (string) $row->original_text;
		if ( '' === $needle ) {
			return null;
		}

		$found = WP_AI_Gatekeeper_Diff::apply_suggestion_to_text(
			$content,
			$needle,
			'',
			(string) ( $row->context_before ?? '' ),
			(string) ( $row->context_after ?? '' )
		);

		return is_wp_error( $found ) ? null : array( $found['offset'], $found['length'] );
	}

	/**
	 * Renders title, excerpt (if any or targeted), and content as safe HTML.
	 *
	 * @param array   $fields Field values (possibly containing the marker).
	 * @param WP_Post $post   Post, for template tags used by blocks/shortcodes.
	 * @param string  $field  Targeted field.
	 * @return string
	 */
	private static function render( array $fields, WP_Post $post, $field ) {
		$previous_post   = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below; blocks read the global post.
		setup_postdata( $post );

		$content = apply_filters( 'the_content', $fields['post_content'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
		if ( $previous_post instanceof WP_Post ) {
			setup_postdata( $previous_post );
		}

		$html = '<h1 class="wpai-preview-title">' . wp_kses_data( $fields['post_title'] ) . '</h1>';
		if ( '' !== $fields['post_excerpt'] || 'post_excerpt' === $field ) {
			$html .= '<p class="wpai-preview-excerpt"><span class="wpai-preview-label">' . esc_html__( 'Excerpt', 'wp-ai-gatekeeper' ) . '</span> '
				. wp_kses_data( $fields['post_excerpt'] ) . '</p>';
		}
		$html .= '<div class="wpai-preview-content">' . $content . '</div>';

		return wp_kses_post( $html );
	}

	/**
	 * Inline highlight of what is removed and added. For plain text, unchanged
	 * leading/trailing words stay plain and the rest reads as "old, then new"
	 * (word-level interleaving is hard to read in prose). Changes containing
	 * markup are highlighted as a whole, since word boundaries could split tags.
	 *
	 * @param object $row Suggestion row.
	 * @return string
	 */
	private static function change_html( $row ) {
		$original  = (string) $row->original_text;
		$suggested = (string) $row->suggested_text;
		$html      = '<span class="wpai-preview-change">';

		if ( WP_AI_Gatekeeper_Markup_Guard::is_markup_edit( $original, $suggested ) ) {
			if ( '' !== $original ) {
				$html .= '<del class="wpai-diff-word-del">' . wp_kses_post( force_balance_tags( $original ) ) . '</del>';
			}
			if ( '' !== $suggested ) {
				$html .= '<ins class="wpai-diff-word-ins">' . wp_kses_post( force_balance_tags( $suggested ) ) . '</ins>';
			}
			return $html . '</span>';
		}

		$old = WP_AI_Gatekeeper_Diff::tokenize_words( $original );
		$new = WP_AI_Gatekeeper_Diff::tokenize_words( $suggested );
		$n_o = count( $old );
		$n_n = count( $new );

		$prefix = 0;
		while ( $prefix < $n_o && $prefix < $n_n && $old[ $prefix ] === $new[ $prefix ] ) {
			++$prefix;
		}
		$suffix = 0;
		while ( $suffix < $n_o - $prefix && $suffix < $n_n - $prefix
			&& $old[ $n_o - 1 - $suffix ] === $new[ $n_n - 1 - $suffix ] ) {
			++$suffix;
		}

		$removed = implode( '', array_slice( $old, $prefix, $n_o - $prefix - $suffix ) );
		$added   = implode( '', array_slice( $new, $prefix, $n_n - $prefix - $suffix ) );

		$html .= esc_html( implode( '', array_slice( $old, 0, $prefix ) ) );
		if ( '' !== $removed ) {
			$html .= '<del class="wpai-diff-word-del">' . esc_html( $removed ) . '</del>';
		}
		if ( '' !== $added ) {
			$html .= '<ins class="wpai-diff-word-ins">' . esc_html( $added ) . '</ins>';
		}
		$html .= esc_html( implode( '', array_slice( $old, $n_o - $suffix ) ) );
		return $html . '</span>';
	}
}
