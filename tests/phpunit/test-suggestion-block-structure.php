<?php
/**
 * Regression tests: filtering AI suggestions keeps their block delimiter
 * comments exactly as submitted (a fragment may legitimately leave a block
 * open or close one opened outside it), while block attributes are still
 * filtered.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Suggestion_Block_Structure
 */
class Test_Suggestion_Block_Structure extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Article with a heading followed by a paragraph.
	 *
	 * @var string
	 */
	const ARTICLE = "<!-- wp:heading {\"level\":3} -->\n<h3>TOP5 tools</h3>\n<!-- /wp:heading -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Here they are.</p>\n<!-- /wp:paragraph -->";

	/**
	 * Create a suggestion as the AI and return the REST response.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $original  Original text.
	 * @param string $suggested Suggested text.
	 * @return WP_REST_Response
	 */
	private function ai_suggest( $post_id, $original, $suggested ) {
		return $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/posts/{$post_id}/suggestions",
			array(
				'original_text'  => $original,
				'suggested_text' => $suggested,
			)
		);
	}

	/**
	 * Asserts every block opened in the content is closed exactly once.
	 *
	 * @param string $content Post content.
	 * @param string $block   Block name without "core/".
	 * @return void
	 */
	private function assert_balanced( $content, $block ) {
		$this->assertSame(
			preg_match_all( '/<!-- wp:' . $block . '[ {]/', $content ),
			substr_count( $content, '<!-- /wp:' . $block . ' -->' ),
			"Every {$block} block is closed exactly once:\n{$content}"
		);
	}

	/**
	 * Test: a suggestion whose original text starts with a block opener (its
	 * closer follows after the matched span) and that inserts blocks before it
	 * is stored unchanged and merges into balanced block markup. Core's block
	 * attribute filter used to append a closer after the heading.
	 */
	public function test_fragment_with_open_block_is_stored_and_merged_unchanged() {
		$post_id   = $this->create_editor_post( array( 'post_content' => self::ARTICLE ) );
		$original  = "<!-- wp:heading {\"level\":3} -->\n<h3>TOP5 tools</h3>";
		$suggested = "<!-- wp:paragraph -->\n<p>A short intro.</p>\n<!-- /wp:paragraph -->\n\n" . $original;

		$created = $this->ai_suggest( $post_id, $original, $suggested );
		$this->assertSame( 201, $created->get_status() );
		$data = $created->get_data();
		$this->assertSame( $suggested, $data['suggested_text'] );
		$this->assertFalse( $data['suggested_text_filtered'] );

		$merged = $this->dispatch_rest( 'POST', "/ai-gatekeeper/v1/suggestions/{$data['id']}/merge", array(), false );
		$this->assertSame( 200, $merged->get_status() );

		$content = get_post( $post_id )->post_content;
		$this->assertSame( "<!-- wp:paragraph -->\n<p>A short intro.</p>\n<!-- /wp:paragraph -->\n\n" . self::ARTICLE, $content );
		$this->assert_balanced( $content, 'heading' );
		$this->assert_balanced( $content, 'paragraph' );
	}

	/**
	 * Test: a fragment that starts by closing a block opened before the span is
	 * stored unchanged.
	 */
	public function test_fragment_starting_with_a_closer_is_stored_unchanged() {
		$post_id   = $this->create_editor_post( array( 'post_content' => self::ARTICLE ) );
		$original  = "</h3>\n<!-- /wp:heading -->";
		$suggested = "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Inserted.</p>\n<!-- /wp:paragraph -->";

		$created = $this->ai_suggest( $post_id, $original, $suggested );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( $suggested, $created->get_data()['suggested_text'] );

		$this->dispatch_rest( 'POST', "/ai-gatekeeper/v1/suggestions/{$created->get_data()['id']}/merge", array(), false );
		$content = get_post( $post_id )->post_content;
		$this->assertStringContainsString( '<p>Inserted.</p>', $content );
		$this->assert_balanced( $content, 'heading' );
		$this->assert_balanced( $content, 'paragraph' );
	}

	/**
	 * Test: markup hidden in block attributes as JSON escapes is still
	 * filtered, and the response says the text was changed.
	 */
	public function test_escaped_markup_in_block_attributes_is_filtered_and_flagged() {
		$post_id   = $this->create_editor_post( array( 'post_content' => self::ARTICLE ) );
		$suggested = "<!-- wp:paragraph {\"content\":\"\\u003cscript\\u003ealert(1)\\u003c/script\\u003eok\"} -->\n<p>Here they are.</p>\n<!-- /wp:paragraph -->";

		$created = $this->ai_suggest( $post_id, "<!-- wp:paragraph -->\n<p>Here they are.</p>\n<!-- /wp:paragraph -->", $suggested );
		$this->assertSame( 201, $created->get_status() );
		$data = $created->get_data();

		$this->assertTrue( $data['suggested_text_filtered'] );
		$this->assertStringNotContainsString( 'script', $data['suggested_text'] );
		$this->assertStringStartsWith( '<!-- wp:paragraph {"content":"alert(1)ok"} -->', $data['suggested_text'] );
		$this->assertStringEndsWith( "<p>Here they are.</p>\n<!-- /wp:paragraph -->", $data['suggested_text'], 'The block structure is kept.' );
	}

	/**
	 * Test: updating a suggestion reports filtering too.
	 */
	public function test_update_reports_filtering() {
		$post_id = $this->create_editor_post( array( 'post_content' => self::ARTICLE ) );
		$created = $this->ai_suggest( $post_id, 'Here they are.', 'Here they come.' );
		$this->assertFalse( $created->get_data()['suggested_text_filtered'] );

		$updated = $this->dispatch_rest(
			'PUT',
			"/ai-gatekeeper/v1/suggestions/{$created->get_data()['id']}",
			array( 'suggested_text' => 'Here <em onclick="x()">they</em> come.' )
		);
		$this->assertSame( 200, $updated->get_status() );
		$this->assertTrue( $updated->get_data()['suggested_text_filtered'] );
		$this->assertSame( 'Here <em>they</em> come.', $updated->get_data()['suggested_text'] );
	}

	/**
	 * Test: unchanged attributes keep their exact text, invalid attribute JSON
	 * is dropped like core's parser does, and void blocks keep their slash.
	 */
	public function test_filter_block_delimiters() {
		$allowed = wp_kses_allowed_html( 'post' );

		$untouched = "<!-- wp:heading {\"level\":3,  \"x\":\"a\"} -->\n<!-- wp:spacer {\"height\":\"20px\"} /-->\n<!-- /wp:heading -->";
		$this->assertSame( $untouched, WP_AI_Gatekeeper_Markup_Guard::filter_block_delimiters( $untouched, $allowed ) );

		$this->assertSame(
			'<!-- wp:paragraph -->',
			WP_AI_Gatekeeper_Markup_Guard::filter_block_delimiters( '<!-- wp:paragraph {nope} -->', $allowed )
		);
		$this->assertSame(
			'<!-- wp:acme/box {"html":"ok"} /-->',
			WP_AI_Gatekeeper_Markup_Guard::filter_block_delimiters( '<!-- wp:acme/box {"html":"<iframe></iframe>ok"} /-->', $allowed )
		);
		$this->assertSame( 'No comments here.', WP_AI_Gatekeeper_Markup_Guard::filter_block_delimiters( 'No comments here.', $allowed ) );
	}

	/**
	 * Test: sanitizing restores core's block attribute filter for everything else.
	 */
	public function test_core_block_attribute_filter_is_restored() {
		$priority = has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' );
		WP_AI_Gatekeeper_Markup_Guard::sanitize( '<!-- wp:paragraph --><p>x</p>', 'post_content' );
		$this->assertSame( $priority, has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' ) );
	}
}
