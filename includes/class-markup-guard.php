<?php
/**
 * Keeps suggestions from untrusted authors (the AI, or any user without
 * unfiltered_html) from introducing markup a privileged reviewer would
 * otherwise merge unfiltered.
 *
 * Merging runs wp_update_post() as the reviewer, so kses would follow the
 * *reviewer's* capabilities. Filtering only the suggested snippet is not
 * enough either: a snippet without tags can still land inside an existing tag
 * (attribute injection) or inside a trusted <script>. So two rules apply:
 *
 * 1. Suggested text containing markup is filtered with kses (minus elements
 *    that switch the tokenizer into raw-text mode for the rest of the page).
 * 2. The replaced span must start and end in a safe context of the current
 *    field: plain text for markup edits; plain text or an HTML comment (block
 *    delimiters) for text-only edits. Anything inside a tag, raw-text element
 *    (script, style, textarea, ...), or right after a literal "<" is refused.
 *
 * The tokenizer is deliberately conservative: when it cannot be sure an
 * offset is plain text, it reports a non-text context and the edit is refused.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes and validates untrusted suggestion markup.
 */
class WP_AI_Gatekeeper_Markup_Guard {

	/**
	 * Elements whose content the HTML tokenizer does not parse as markup.
	 *
	 * @var string[]
	 */
	const RAW_TEXT_ELEMENTS = array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext' );

	/**
	 * Whether a user's suggestions may contain unfiltered HTML.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_trusted_author( $user_id ) {
		return ! WP_AI_Gatekeeper_Auth::is_ai_user( (int) $user_id ) && user_can( (int) $user_id, 'unfiltered_html' );
	}

	/**
	 * Whether a suggestion touches markup (as opposed to plain text only).
	 *
	 * @param string $original_text  Original text.
	 * @param string $suggested_text Suggested text.
	 * @return bool
	 */
	public static function is_markup_edit( $original_text, $suggested_text ) {
		return false !== strpbrk( (string) $original_text . (string) $suggested_text, '<>' );
	}

	/**
	 * Filters suggested text the way core filters the field for users without
	 * unfiltered_html, minus raw-text elements.
	 *
	 * @param string $suggested_text Suggested text.
	 * @param string $target_field   post_content, post_excerpt, or post_title.
	 * @return string
	 */
	public static function sanitize( $suggested_text, $target_field ) {
		$suggested_text = (string) $suggested_text;
		if ( false === strpbrk( $suggested_text, '<>' ) ) {
			return $suggested_text;
		}

		$allowed = wp_kses_allowed_html( 'post_title' === $target_field ? 'data' : 'post' );
		foreach ( self::RAW_TEXT_ELEMENTS as $element ) {
			unset( $allowed[ $element ] );
		}

		// Core's pre_kses step for block attributes parses the input as a whole
		// document and serializes it again, which closes blocks the fragment
		// leaves open (their closer is in the article, outside the replaced span)
		// and rewrites stray closers. Run kses without it, then filter the block
		// attributes delimiter by delimiter, keeping the structure as submitted.
		$priority = has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' );
		if ( false !== $priority ) {
			remove_filter( 'pre_kses', 'wp_pre_kses_block_attributes', $priority );
		}
		try {
			$filtered = wp_kses( $suggested_text, $allowed );
		} finally {
			if ( false !== $priority ) {
				add_filter( 'pre_kses', 'wp_pre_kses_block_attributes', $priority, 3 );
			}
		}

		return self::filter_block_delimiters( $filtered, $allowed );
	}

	/**
	 * Filters the attributes of each block delimiter comment the way core's
	 * filter_block_kses() does, without re-serializing the block structure.
	 * Delimiters whose attributes are unchanged keep their exact text.
	 *
	 * Block attributes need their own filtering: kses only sees the comment's
	 * raw text, but the JSON can hide markup as < escapes that dynamic
	 * blocks would render.
	 *
	 * @param string $html    Fragment that already went through kses.
	 * @param array  $allowed Allowed HTML.
	 * @return string
	 */
	public static function filter_block_delimiters( $html, array $allowed ) {
		if ( false === strpos( $html, '<!--' ) ) {
			return $html;
		}

		// Same delimiter grammar as WP_Block_Parser::next_token().
		$pattern = '/<!--[ \t\f\r\n]+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->).)*+)?}[ \t\f\r\n]+)?(?P<void>\/)?-->/s';

		return (string) preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $allowed ) {
				if ( empty( $m['attrs'] ) ) {
					return $m[0];
				}

				$name  = ( '' !== $m['namespace'] ? $m['namespace'] : 'core/' ) . $m['name'];
				$attrs = json_decode( trim( $m['attrs'] ), true );
				if ( is_array( $attrs ) ) {
					$filtered = filter_block_kses_value( $attrs, $allowed, array(), array( 'blockName' => $name ) );
					if ( $filtered === $attrs ) {
						return $m[0];
					}
					$attrs_text = serialize_block_attributes( $filtered ) . ' ';
				} else {
					// Core's parser ignores attributes that are not a JSON object.
					$attrs_text = '';
				}

				return '<!-- ' . $m['closer'] . 'wp:' . $m['namespace'] . $m['name'] . ' ' . $attrs_text . ( ! empty( $m['void'] ) ? '/' : '' ) . '-->';
			},
			$html
		);
	}

	/**
	 * Verifies the replaced span [offset, offset + length) is in a safe context.
	 *
	 * @param string $content        Field content before the change.
	 * @param int    $offset         Byte offset of the replaced span.
	 * @param int    $length         Byte length of the replaced span.
	 * @param bool   $is_markup_edit Whether the suggestion touches markup.
	 * @return true|WP_Error
	 */
	public static function check_span( $content, $offset, $length, $is_markup_edit ) {
		$allowed = $is_markup_edit ? array( 'text' ) : array( 'text', 'comment' );
		$start   = self::context_at( $content, $offset );
		$end     = self::context_at( $content, $offset + $length );

		if ( in_array( $start, $allowed, true ) && in_array( $end, $allowed, true ) ) {
			return true;
		}

		return new WP_Error(
			'suggestion_unsafe_markup',
			__( 'AI suggestions cannot change text inside HTML tags, attributes, or script/style content. Suggest a replacement for the whole element instead.', 'wp-ai-gatekeeper' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Returns the tokenizer context at a byte offset: 'text', 'tag', 'comment', or 'rawtext'.
	 *
	 * An offset is a gap between two bytes; "text" means inserting there cannot
	 * change how any existing markup is tokenized.
	 *
	 * @param string $html   HTML fragment.
	 * @param int    $offset Byte offset (0..strlen).
	 * @return string
	 */
	public static function context_at( $html, $offset ) {
		$html   = (string) $html;
		$len    = strlen( $html );
		$offset = max( 0, min( (int) $offset, $len ) );
		$i      = 0;

		while ( $i < $offset ) {
			$lt = strpos( $html, '<', $i );
			if ( false === $lt || $lt >= $offset ) {
				return 'text';
			}

			$next = $lt + 1 < $len ? $html[ $lt + 1 ] : '';

			// Comment, including the abrupt "<!-->" and "<!--->" forms.
			if ( '<!--' === substr( $html, $lt, 4 ) ) {
				$end = self::comment_end( $html, $lt );
				if ( $offset < $end ) {
					return 'comment';
				}
				$i = $end;
				continue;
			}

			// Start or end tag.
			$is_end_tag = '/' === $next;
			$name_start = $is_end_tag ? $lt + 2 : $lt + 1;
			if ( $name_start < $len && preg_match( '/[A-Za-z]/', $html[ $name_start ] ) ) {
				$name_len = strcspn( $html, " \t\n\f\r/>", $name_start );
				$name     = strtolower( substr( $html, $name_start, $name_len ) );
				$end      = self::tag_end( $html, $name_start + $name_len );
				if ( $offset < $end ) {
					return 'tag';
				}

				if ( ! $is_end_tag && in_array( $name, self::RAW_TEXT_ELEMENTS, true ) ) {
					$close = self::raw_text_close( $html, $name, $end );
					if ( $offset <= $close ) {
						return 'rawtext';
					}
					$i = $close;
					continue;
				}

				$i = $end;
				continue;
			}

			// Bogus comment ("<!doctype>", "<?x>", "</ x>", "</>"): ends at the next ">".
			if ( '!' === $next || '?' === $next || $is_end_tag ) {
				$gt  = strpos( $html, '>', $lt + 1 );
				$end = false === $gt ? $len + 1 : $gt + 1;
				if ( $offset < $end ) {
					return 'tag';
				}
				$i = $end;
				continue;
			}

			// Literal "<". Text inserted right after it could start a tag.
			if ( $offset === $lt + 1 ) {
				return 'tag';
			}
			$i = $lt + 1;
		}

		return 'text';
	}

	/**
	 * Returns the offset just past a comment starting at $lt.
	 *
	 * Unterminated tokens return strlen + 1 so that the end of the document
	 * still counts as inside them (rendering appends theme markup afterwards).
	 *
	 * @param string $html HTML.
	 * @param int    $lt   Offset of "<!--".
	 * @return int
	 */
	private static function comment_end( $html, $lt ) {
		$body = $lt + 4;
		if ( '>' === substr( $html, $body, 1 ) ) {
			return $body + 1;
		}
		if ( '->' === substr( $html, $body, 2 ) ) {
			return $body + 2;
		}

		$ends = array();
		foreach ( array( '-->', '--!>' ) as $closer ) {
			$pos = strpos( $html, $closer, $body );
			if ( false !== $pos ) {
				$ends[] = $pos + strlen( $closer );
			}
		}

		return empty( $ends ) ? strlen( $html ) + 1 : min( $ends );
	}

	/**
	 * Returns the offset just past the ">" closing a tag, following the HTML
	 * attribute states (quotes only open a value after "=").
	 *
	 * @param string $html HTML.
	 * @param int    $j    Offset right after the tag name.
	 * @return int
	 */
	private static function tag_end( $html, $j ) {
		$len   = strlen( $html );
		$state = 'before_attr';
		$quote = '';
		$space = " \t\n\f\r";

		for ( ; $j < $len; $j++ ) {
			$c = $html[ $j ];

			if ( 'quoted' === $state ) {
				if ( $c === $quote ) {
					$state = 'after_value';
				}
				continue;
			}
			if ( 'unquoted' === $state ) {
				if ( '>' === $c ) {
					return $j + 1;
				}
				if ( false !== strpos( $space, $c ) ) {
					$state = 'before_attr';
				}
				continue;
			}
			if ( '>' === $c ) {
				return $j + 1;
			}
			if ( 'before_value' === $state ) {
				if ( false !== strpos( $space, $c ) ) {
					continue;
				}
				if ( '"' === $c || "'" === $c ) {
					$quote = $c;
					$state = 'quoted';
				} else {
					$state = 'unquoted';
				}
				continue;
			}
			if ( '=' === $c && ( 'attr_name' === $state || 'after_attr_name' === $state ) ) {
				$state = 'before_value';
				continue;
			}
			if ( false !== strpos( $space, $c ) ) {
				if ( 'attr_name' === $state ) {
					$state = 'after_attr_name';
				} elseif ( 'after_value' === $state ) {
					$state = 'before_attr';
				}
				continue;
			}
			if ( '/' === $c ) {
				$state = 'before_attr';
				continue;
			}
			$state = 'attr_name';
		}

		return $len + 1;
	}

	/**
	 * Returns the offset of the end tag closing a raw-text element.
	 *
	 * @param string $html  HTML.
	 * @param string $name  Lowercase element name.
	 * @param int    $from  Offset where the raw content starts.
	 * @return int Offset of "</name", or strlen when unclosed.
	 */
	private static function raw_text_close( $html, $name, $from ) {
		$len = strlen( $html );
		if ( 'plaintext' === $name ) {
			return $len;
		}

		if ( ! preg_match( '#</' . $name . '[\t\n\f\r />]#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return $len;
		}
		$close = $m[0][1];

		// "<!--" inside a script enables escape states where "</script>" may not
		// close it. Don't try to model them: treat the rest as script.
		if ( 'script' === $name && false !== strpos( substr( $html, $from, $close - $from ), '<!--' ) ) {
			return $len;
		}

		return $close;
	}
}
