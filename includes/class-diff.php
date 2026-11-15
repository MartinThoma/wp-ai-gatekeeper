<?php
/**
 * Word-level diff generator and safe snippet replacement utilities.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates GitHub PR-style inline diffs and safely applies snippet replacements.
 */
class WP_AI_Gatekeeper_Diff {

	/**
	 * Maximum DP cell count for full LCS matrix before falling back to prefix/suffix diff.
	 */
	const MAX_LCS_CELLS = 250000;

	/**
	 * Splits text into word and whitespace tokens for fine-grained diffing.
	 *
	 * @param string $text Input text.
	 * @return string[]
	 */
	public static function tokenize_words( $text ) {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		if ( '' === $normalized ) {
			return array();
		}

		$tokens = preg_split( '/(\s+)/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( false === $tokens ) {
			return array( $normalized );
		}

		return $tokens;
	}

	/**
	 * Computes word-level diff segments between original and suggested text.
	 *
	 * @param string $original  Original text snippet.
	 * @param string $suggested Proposed text snippet.
	 * @return array<int, array{type: string, text: string}>
	 */
	public static function compute_word_diff( $original, $suggested ) {
		$old_tokens = self::tokenize_words( $original );
		$new_tokens = self::tokenize_words( $suggested );

		$raw_ops = self::diff_token_arrays( $old_tokens, $new_tokens );

		// Coalesce consecutive operations of the same type.
		$coalesced = array();
		foreach ( $raw_ops as $op ) {
			$count = count( $coalesced );
			if ( $count > 0 && $coalesced[ $count - 1 ]['type'] === $op['type'] ) {
				$coalesced[ $count - 1 ]['text'] .= $op['text'];
			} else {
				$coalesced[] = $op;
			}
		}

		return $coalesced;
	}

	/**
	 * Computes line-level diff entries for unified line displays.
	 *
	 * @param string $original  Original text.
	 * @param string $suggested Proposed text.
	 * @return array<int, array{type: string, old_line: int|null, new_line: int|null, text: string}>
	 */
	public static function compute_line_diff( $original, $suggested ) {
		$old_norm  = str_replace( array( "\r\n", "\r" ), "\n", (string) $original );
		$new_norm  = str_replace( array( "\r\n", "\r" ), "\n", (string) $suggested );
		$old_lines = '' === $old_norm ? array() : explode( "\n", $old_norm );
		$new_lines = '' === $new_norm ? array() : explode( "\n", $new_norm );

		$ops     = self::diff_token_arrays( $old_lines, $new_lines );
		$result  = array();
		$old_num = 1;
		$new_num = 1;

		foreach ( $ops as $op ) {
			if ( 'equal' === $op['type'] ) {
				$result[] = array(
					'type'     => 'context',
					'old_line' => $old_num++,
					'new_line' => $new_num++,
					'text'     => $op['text'],
				);
			} elseif ( 'delete' === $op['type'] ) {
				$result[] = array(
					'type'     => 'del',
					'old_line' => $old_num++,
					'new_line' => null,
					'text'     => $op['text'],
				);
			} else {
				$result[] = array(
					'type'     => 'add',
					'old_line' => null,
					'new_line' => $new_num++,
					'text'     => $op['text'],
				);
			}
		}

		return $result;
	}

	/**
	 * Computes LCS diff operations over two string arrays with prefix/suffix trimming.
	 *
	 * @param string[] $old_tokens Original tokens.
	 * @param string[] $new_tokens New tokens.
	 * @return array<int, array{type: string, text: string}>
	 */
	private static function diff_token_arrays( array $old_tokens, array $new_tokens ) {
		$old_len = count( $old_tokens );
		$new_len = count( $new_tokens );

		$prefix = 0;
		while ( $prefix < $old_len && $prefix < $new_len && $old_tokens[ $prefix ] === $new_tokens[ $prefix ] ) {
			$prefix++;
		}

		$suffix = 0;
		while (
			$suffix < ( $old_len - $prefix )
			&& $suffix < ( $new_len - $prefix )
			&& $old_tokens[ $old_len - 1 - $suffix ] === $new_tokens[ $new_len - 1 - $suffix ]
		) {
			$suffix++;
		}

		$ops = array();
		for ( $i = 0; $i < $prefix; $i++ ) {
			$ops[] = array(
				'type' => 'equal',
				'text' => $old_tokens[ $i ],
			);
		}

		$mid_old = array_slice( $old_tokens, $prefix, $old_len - $prefix - $suffix );
		$mid_new = array_slice( $new_tokens, $prefix, $new_len - $prefix - $suffix );
		$m       = count( $mid_old );
		$n       = count( $mid_new );

		if ( $m > 0 || $n > 0 ) {
			if ( 0 === $m ) {
				foreach ( $mid_new as $tok ) {
					$ops[] = array(
						'type' => 'insert',
						'text' => $tok,
					);
				}
			} elseif ( 0 === $n ) {
				foreach ( $mid_old as $tok ) {
					$ops[] = array(
						'type' => 'delete',
						'text' => $tok,
					);
				}
			} elseif ( ( $m * $n ) > self::MAX_LCS_CELLS ) {
				foreach ( $mid_old as $tok ) {
					$ops[] = array(
						'type' => 'delete',
						'text' => $tok,
					);
				}
				foreach ( $mid_new as $tok ) {
					$ops[] = array(
						'type' => 'insert',
						'text' => $tok,
					);
				}
			} else {
				$dp = array_fill( 0, $m + 1, array_fill( 0, $n + 1, 0 ) );
				for ( $i = $m - 1; $i >= 0; $i-- ) {
					for ( $j = $n - 1; $j >= 0; $j-- ) {
						if ( $mid_old[ $i ] === $mid_new[ $j ] ) {
							$dp[ $i ][ $j ] = $dp[ $i + 1 ][ $j + 1 ] + 1;
						} else {
							$dp[ $i ][ $j ] = max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
						}
					}
				}

				$i = 0;
				$j = 0;
				while ( $i < $m && $j < $n ) {
					if ( $mid_old[ $i ] === $mid_new[ $j ] ) {
						$ops[] = array(
							'type' => 'equal',
							'text' => $mid_old[ $i ],
						);
						$i++;
						$j++;
					} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
						$ops[] = array(
							'type' => 'delete',
							'text' => $mid_old[ $i ],
						);
						$i++;
					} else {
						$ops[] = array(
							'type' => 'insert',
							'text' => $mid_new[ $j ],
						);
						$j++;
					}
				}

				while ( $i < $m ) {
					$ops[] = array(
						'type' => 'delete',
						'text' => $mid_old[ $i ],
					);
					$i++;
				}

				while ( $j < $n ) {
					$ops[] = array(
						'type' => 'insert',
						'text' => $mid_new[ $j ],
					);
					$j++;
				}
			}
		}

		for ( $i = $old_len - $suffix; $i < $old_len; $i++ ) {
			$ops[] = array(
				'type' => 'equal',
				'text' => $old_tokens[ $i ],
			);
		}

		return $ops;
	}

	/**
	 * Renders GitHub PR-style stacked removal/addition diff HTML with inline word highlights.
	 *
	 * @param string $original  Original text snippet.
	 * @param string $suggested Proposed text snippet.
	 * @return string Safe HTML output.
	 */
	public static function render_inline_html( $original, $suggested ) {
		$segments = self::compute_word_diff( $original, $suggested );

		$old_html = '';
		$new_html = '';

		foreach ( $segments as $seg ) {
			$escaped = esc_html( $seg['text'] );
			if ( 'equal' === $seg['type'] ) {
				$old_html .= $escaped;
				$new_html .= $escaped;
			} elseif ( 'delete' === $seg['type'] ) {
				$old_html .= '<del class="wpai-diff-word-del">' . $escaped . '</del>';
			} elseif ( 'insert' === $seg['type'] ) {
				$new_html .= '<ins class="wpai-diff-word-ins">' . $escaped . '</ins>';
			}
		}

		$html  = '<div class="wpai-diff-box">';
		$html .= '<div class="wpai-diff-row wpai-diff-row-del">';
		$html .= '<span class="wpai-diff-gutter" aria-hidden="true">-</span>';
		$html .= '<div class="wpai-diff-code">' . ( '' !== $old_html ? $old_html : '<span class="wpai-diff-empty">(' . esc_html__( 'empty', 'wp-ai-gatekeeper' ) . ')</span>' ) . '</div>';
		$html .= '</div>';
		$html .= '<div class="wpai-diff-row wpai-diff-row-add">';
		$html .= '<span class="wpai-diff-gutter" aria-hidden="true">+</span>';
		$html .= '<div class="wpai-diff-code">' . ( '' !== $new_html ? $new_html : '<span class="wpai-diff-empty">(' . esc_html__( 'deleted', 'wp-ai-gatekeeper' ) . ')</span>' ) . '</div>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Applies an inline snippet suggestion to article field text safely.
	 *
	 * @param string $content        Current field content.
	 * @param string $original_text  Exact text to replace.
	 * @param string $suggested_text Replacement text.
	 * @param string $context_before Optional preceding text for disambiguation.
	 * @param string $context_after  Optional trailing text for disambiguation.
	 * @return array{content: string, match_count: int}|WP_Error
	 */
	public static function apply_suggestion_to_text( $content, $original_text, $suggested_text, $context_before = '', $context_after = '' ) {
		$content        = (string) $content;
		$original_text  = (string) $original_text;
		$suggested_text = (string) $suggested_text;
		$context_before = (string) $context_before;
		$context_after  = (string) $context_after;

		if ( '' === $original_text ) {
			return new WP_Error(
				'invalid_original_text',
				__( 'Original text cannot be empty.', 'wp-ai-gatekeeper' ),
				array( 'status' => 422 )
			);
		}

		$attempts = array(
			array(
				'orig' => $original_text,
				'sugg' => $suggested_text,
				'pre'  => $context_before,
				'post' => $context_after,
			),
		);

		$orig_lf = str_replace( array( "\r\n", "\r" ), "\n", $original_text );
		$pre_lf  = str_replace( array( "\r\n", "\r" ), "\n", $context_before );
		$post_lf = str_replace( array( "\r\n", "\r" ), "\n", $context_after );
		if ( $orig_lf !== $original_text || $pre_lf !== $context_before || $post_lf !== $context_after ) {
			$attempts[] = array(
				'orig' => $orig_lf,
				'sugg' => str_replace( array( "\r\n", "\r" ), "\n", $suggested_text ),
				'pre'  => $pre_lf,
				'post' => $post_lf,
			);
		}

		foreach ( $attempts as $attempt ) {
			$has_context = ( '' !== $attempt['pre'] || '' !== $attempt['post'] );

			if ( $has_context ) {
				$needle = $attempt['pre'] . $attempt['orig'] . $attempt['post'];
				$pos    = strpos( $content, $needle );
				if ( false !== $pos ) {
					$replace_offset = $pos + strlen( $attempt['pre'] );
					$updated        = substr_replace( $content, $attempt['sugg'], $replace_offset, strlen( $attempt['orig'] ) );
					return array(
						'content'     => $updated,
						'match_count' => substr_count( $content, $needle ),
					);
				}
			} else {
				$pos = strpos( $content, $attempt['orig'] );
				if ( false !== $pos ) {
					$updated = substr_replace( $content, $attempt['sugg'], $pos, strlen( $attempt['orig'] ) );
					return array(
						'content'     => $updated,
						'match_count' => substr_count( $content, $attempt['orig'] ),
					);
				}
			}
		}

		if ( '' !== $context_before || '' !== $context_after ) {
			if ( false !== strpos( $content, $original_text ) || false !== strpos( $content, $orig_lf ) ) {
				return new WP_Error(
					'suggestion_context_mismatch',
					__( 'The surrounding context for this suggestion changed in the article. Ask the AI to refresh its suggestion.', 'wp-ai-gatekeeper' ),
					array( 'status' => 409 )
				);
			}
		}

		return new WP_Error(
			'suggestion_conflict',
			__( 'The original text could not be found in the current article. Ask the AI to update its suggestion.', 'wp-ai-gatekeeper' ),
			array( 'status' => 409 )
		);
	}
}
