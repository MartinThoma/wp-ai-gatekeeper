<?php
/**
 * Tests: the word diff. Whatever the input, the operations must rebuild
 * both texts exactly, so the review UI never shows a wrong change.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Diff
 */
class Test_Diff extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Data provider: text pairs covering every diff path.
	 *
	 * @return array
	 */
	public function pairs() {
		$many_old = implode( ' ', array_map( static fn( $i ) => "old{$i}", range( 1, 600 ) ) );
		$many_new = implode( ' ', array_map( static fn( $i ) => "new{$i}", range( 1, 600 ) ) );

		return array(
			'identical'                     => array( 'same text', 'same text' ),
			'insertion only'                => array( 'one three', 'one two three' ),
			'deletion only'                 => array( 'one two three', 'one three' ),
			'replacement'                   => array( 'use legacy passwords', 'use passkeys' ),
			'insertions before and after'   => array( 'alpha', 'beta alpha gamma' ),
			'trailing insertions in middle' => array( 'a x b', 'a y z w b' ),
			'trailing deletions in middle'  => array( 'a y z w b', 'a x b' ),
			'from empty'                    => array( '', 'new text' ),
			'to empty'                      => array( 'old text', '' ),
			'line endings and whitespace'   => array( "a\r\nb  c", "a\nb c" ),
			'too large for LCS falls back'  => array( $many_old, $many_new ),
		);
	}

	/**
	 * Test: the operations rebuild both texts, and contain no change for
	 * identical input.
	 *
	 * @dataProvider pairs
	 *
	 * @param string $original  Original.
	 * @param string $suggested Suggested.
	 */
	public function test_diff_rebuilds_both_texts( $original, $suggested ) {
		$ops    = WP_AI_Gatekeeper_Diff::compute_word_diff( $original, $suggested );
		$before = '';
		$after  = '';
		foreach ( $ops as $op ) {
			$this->assertContains( $op['type'], array( 'equal', 'delete', 'insert' ) );
			if ( 'insert' !== $op['type'] ) {
				$before .= $op['text'];
			}
			if ( 'delete' !== $op['type'] ) {
				$after .= $op['text'];
			}
		}

		$normalize = static function ( $text ) {
			return str_replace( array( "\r\n", "\r" ), "\n", $text );
		};
		$this->assertSame( $normalize( $original ), $before );
		$this->assertSame( $normalize( $suggested ), $after );

		if ( $original === $suggested && '' !== $original ) {
			$this->assertSame( array( 'equal' ), array_unique( array_column( $ops, 'type' ) ) );
		}
	}

	/**
	 * Test: the large-input fallback shows the whole block as replaced instead
	 * of spending minutes on an exact diff.
	 */
	public function test_large_input_falls_back_to_block_replacement() {
		$data = $this->pairs()['too large for LCS falls back'];
		$ops  = WP_AI_Gatekeeper_Diff::compute_word_diff( $data[0], $data[1] );

		$this->assertSame( array( 'delete', 'insert' ), array_column( $ops, 'type' ) );
	}

	/**
	 * Test: tokenizing keeps whitespace as its own tokens, and empty text has none.
	 */
	public function test_tokenize_words() {
		$this->assertSame( array(), WP_AI_Gatekeeper_Diff::tokenize_words( '' ) );
		$this->assertSame( array( 'a', ' ', 'b', "\n", 'c' ), WP_AI_Gatekeeper_Diff::tokenize_words( "a b\r\nc" ) );
	}

	/**
	 * Test: an empty original text is refused.
	 */
	public function test_empty_original_text_is_refused() {
		$result = WP_AI_Gatekeeper_Diff::apply_suggestion_to_text( 'content', '', 'x' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_original_text', $result->get_error_code() );
	}

	/**
	 * Test: invalid UTF-8 cannot be split into words and is kept as one token.
	 */
	public function test_invalid_utf8_is_one_token() {
		$this->assertSame( array( "a \xff b" ), WP_AI_Gatekeeper_Diff::tokenize_words( "a \xff b" ) );
	}

	/**
	 * Test: 300 random text pairs from a small vocabulary (fixed seed, so the
	 * run is repeatable) all rebuild both texts.
	 */
	public function test_random_pairs_rebuild_both_texts() {
		mt_srand( 20261116 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Repeatable random input.
		$words  = array( 'a', 'b', 'c', 'd', 'e' );
		$random = static function () use ( $words ) {
			$out = array();
			for ( $i = mt_rand( 0, 8 ); $i > 0; $i-- ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Seeded for repeatability.
				$out[] = $words[ mt_rand( 0, 4 ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Seeded for repeatability.
			}
			return implode( ' ', $out );
		};

		for ( $n = 0; $n < 300; $n++ ) {
			$this->test_diff_rebuilds_both_texts( $random(), $random() );
		}
		mt_srand(); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Back to random.
	}
}
