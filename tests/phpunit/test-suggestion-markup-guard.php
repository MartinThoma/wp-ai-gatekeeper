<?php
/**
 * Regression tests: AI suggestions must not smuggle unfiltered HTML into posts
 * when a privileged (unfiltered_html) user merges them.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Suggestion_Markup_Guard
 */
class Test_Suggestion_Markup_Guard extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Create a suggestion as the AI and return the REST response.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $original  Original text.
	 * @param string $suggested Suggested text.
	 * @param string $field     Target field.
	 * @return WP_REST_Response
	 */
	private function ai_suggest( $post_id, $original, $suggested, $field = 'post_content' ) {
		return $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/posts/{$post_id}/suggestions",
			array(
				'target_field'   => $field,
				'original_text'  => $original,
				'suggested_text' => $suggested,
			),
			true
		);
	}

	/**
	 * Merge a suggestion as the editor and return the REST response.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return WP_REST_Response
	 */
	private function editor_merge( $suggestion_id ) {
		return $this->dispatch_rest( 'POST', "/ai-gatekeeper/v1/suggestions/{$suggestion_id}/merge", array(), false );
	}

	/**
	 * Test: editor has unfiltered_html so merge does not filter.
	 */
	public function test_editor_has_unfiltered_html_so_merge_does_not_filter() {
		$this->assertTrue( user_can( $this->editor_id, 'unfiltered_html' ), 'Precondition: on single site, editors bypass kses.' );
		$this->assertFalse( user_can( $this->ai_user_id, 'unfiltered_html' ) );
	}

	/**
	 * Test: script in suggested text is stripped before storage and merge.
	 */
	public function test_script_in_suggested_text_is_stripped_before_storage_and_merge() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );

		$resp = $this->ai_suggest( $post_id, 'Hello world', 'Hello <script>alert(1)</script><strong>world</strong>' );
		$this->assertSame( 201, $resp->get_status() );
		$this->assertStringNotContainsString( '<script', $resp->get_data()['suggested_text'] );
		$this->assertStringContainsString( '<strong>world</strong>', $resp->get_data()['suggested_text'], 'Allowed markup is kept.' );

		$merge = $this->editor_merge( $resp->get_data()['id'] );
		$this->assertSame( 200, $merge->get_status() );
		$content = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringContainsString( '<strong>world</strong>', $content );
	}

	/**
	 * Test: event handler attribute in suggested markup is stripped.
	 */
	public function test_event_handler_attribute_in_suggested_markup_is_stripped() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );

		$resp = $this->ai_suggest( $post_id, '<p>Hello world</p>', '<p onclick="alert(1)">Hello <a href="javascript:alert(1)">world</a></p>' );
		$this->assertSame( 201, $resp->get_status() );
		$this->editor_merge( $resp->get_data()['id'] );

		$content = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString( 'onclick', $content );
		$this->assertStringNotContainsString( 'javascript:', $content );
	}

	/**
	 * Test: attribute splice inside existing tag is rejected.
	 */
	public function test_attribute_splice_inside_existing_tag_is_rejected() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p class="intro">Hello world</p>' ) );

		$resp = $this->ai_suggest( $post_id, 'intro', 'intro" onclick="alert(1)' );
		$this->assertSame( 422, $resp->get_status() );
		$this->assertSame( 'suggestion_unsafe_markup', $resp->get_data()['code'] );
	}

	/**
	 * Test: edit inside trusted script is rejected.
	 */
	public function test_edit_inside_trusted_script_is_rejected() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<script>var greeting = "hello";</script><p>Hi there</p>' ) );

		$resp = $this->ai_suggest( $post_id, 'hello', 'hello"; alert(document.cookie); "' );
		$this->assertSame( 422, $resp->get_status() );
		$this->assertSame( 'suggestion_unsafe_markup', $resp->get_data()['code'] );
	}

	/**
	 * Test: text after literal less than cannot form a tag.
	 */
	public function test_text_after_literal_less_than_cannot_form_a_tag() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>1 < 2 is true</p>' ) );

		$resp = $this->ai_suggest( $post_id, ' 2 is true', 'img src=x onerror=alert(1)' );
		$this->assertSame( 422, $resp->get_status() );
		$this->assertSame( 'suggestion_unsafe_markup', $resp->get_data()['code'] );
	}

	/**
	 * Test: plain text edit keeps trusted markup elsewhere.
	 */
	public function test_plain_text_edit_keeps_trusted_markup_elsewhere() {
		$trusted = '<script>var greeting = "hello";</script><iframe src="https://example.com/embed"></iframe>';
		$post_id = $this->create_editor_post( array( 'post_content' => $trusted . '<p>Hi there</p>' ) );

		$resp = $this->ai_suggest( $post_id, 'Hi there', 'Hello there' );
		$this->assertSame( 201, $resp->get_status() );
		$this->assertSame( 200, $this->editor_merge( $resp->get_data()['id'] )->get_status() );

		$this->assertSame( $trusted . '<p>Hello there</p>', get_post( $post_id )->post_content );
	}

	/**
	 * Test: text edit inside block comment attributes is allowed.
	 */
	public function test_text_edit_inside_block_comment_attributes_is_allowed() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->' ) );

		$resp = $this->ai_suggest( $post_id, '"level":2', '"level":3' );
		$this->assertSame( 201, $resp->get_status() );
	}

	/**
	 * Test: title suggestion is filtered like core titles.
	 */
	public function test_title_suggestion_is_filtered_like_core_titles() {
		$post_id = $this->create_editor_post( array( 'post_title' => 'Security Guide' ) );

		$resp = $this->ai_suggest( $post_id, 'Security Guide', 'Security <img src=x onerror=alert(1)><em>Guide</em>', 'post_title' );
		$this->assertSame( 201, $resp->get_status() );
		$this->editor_merge( $resp->get_data()['id'] );

		$title = get_post( $post_id )->post_title;
		$this->assertStringNotContainsString( '<img', $title );
		$this->assertStringContainsString( '<em>Guide</em>', $title );
	}

	/**
	 * Test: suggestion consisting only of disallowed markup is rejected.
	 */
	public function test_suggestion_consisting_only_of_disallowed_markup_is_rejected() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );

		$resp = $this->ai_suggest( $post_id, '<p>Hello world</p>', '<p>Hello world</p><script></script>' );
		$this->assertSame( 422, $resp->get_status() );
		$this->assertSame( 'suggestion_unsafe_markup', $resp->get_data()['code'] );
	}

	/**
	 * Test: AI update is guarded too.
	 */
	public function test_ai_update_is_guarded_too() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p class="intro">Hello world</p>' ) );
		$created = $this->ai_suggest( $post_id, 'Hello', 'Hi' );
		$this->assertSame( 201, $created->get_status() );

		$resp = $this->dispatch_rest(
			'PATCH',
			'/ai-gatekeeper/v1/suggestions/' . $created->get_data()['id'],
			array(
				'original_text'  => 'intro',
				'suggested_text' => 'intro" onclick="alert(1)',
			),
			true
		);
		$this->assertSame( 422, $resp->get_status() );
	}

	/**
	 * Test: legacy unsanitized suggestion is sanitized at merge.
	 */
	public function test_legacy_unsanitized_suggestion_is_sanitized_at_merge() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );

		// Simulate a row stored before the guard existed.
		$suggestion_id = WP_AI_Gatekeeper_Database::insert_suggestion(
			array(
				'post_id'        => $post_id,
				'author_user_id' => $this->ai_user_id,
				'target_field'   => 'post_content',
				'original_text'  => 'Hello world',
				'suggested_text' => 'Hello <script>alert(1)</script>world',
				'status'         => 'open',
			)
		);

		$merge = $this->editor_merge( $suggestion_id );
		$this->assertSame( 200, $merge->get_status() );
		$this->assertStringNotContainsString( '<script', get_post( $post_id )->post_content );
		$this->assertStringNotContainsString( '<script', $merge->get_data()['suggested_text'], 'Stored text is updated to what was merged.' );
	}

	/**
	 * Test: trusted human suggestions are not filtered.
	 */
	public function test_trusted_human_suggestions_are_not_filtered() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p>' ) );

		$resp = $this->dispatch_rest(
			'POST',
			"/ai-gatekeeper/v1/posts/{$post_id}/suggestions",
			array(
				'original_text'  => '<p>Hello world</p>',
				'suggested_text' => '<p>Hello world</p><iframe src="https://example.com/embed"></iframe>',
			),
			false
		);
		$this->assertSame( 201, $resp->get_status() );
		$this->assertStringContainsString( '<iframe', $resp->get_data()['suggested_text'] );
	}

	/**
	 * Tokenizer contexts at a byte offset ("|" marks the offset in the case name).
	 *
	 * @return array
	 */
	public function context_cases() {
		return array(
			'plain text'                             => array( 'ab|c', 'text' ),
			'start of document'                      => array( '|<p>x</p>', 'text' ),
			'right after a tag'                      => array( '<p>|x</p>', 'text' ),
			'right before a tag'                     => array( '<p>x|</p>', 'text' ),
			'inside tag name'                        => array( '<p|>x</p>', 'tag' ),
			'inside attribute value'                 => array( '<p class="a|b">x</p>', 'tag' ),
			'after quoted ">" in attribute'          => array( '<img alt="a>b|" src=x>', 'tag' ),
			'after tag with quoted ">"'              => array( '<img alt="a>b" src=x>|', 'text' ),
			'quote in attribute name is not a value' => array( '<a b"c>|', 'text' ),
			'unquoted value'                         => array( '<a href=x|y>', 'tag' ),
			'unterminated tag at end'                => array( 'x <img src=x|', 'tag' ),
			'right after literal <'                  => array( '1 <| 2', 'tag' ),
			'after literal < and text'               => array( '1 < 2|', 'text' ),
			'inside comment'                         => array( '<!-- wp:x {"a":1|} -->', 'comment' ),
			'comment with ">" inside'                => array( '<!-- a>b| -->', 'comment' ),
			'after comment'                          => array( '<!-- a -->|x', 'text' ),
			'abrupt empty comment'                   => array( '<!-->|x', 'text' ),
			'inside script'                          => array( '<script>var a = 1 > 0|;</script>', 'rawtext' ),
			'script start'                           => array( '<script>|x</script>', 'rawtext' ),
			'script before close'                    => array( '<script>x|</script>', 'rawtext' ),
			'after script'                           => array( '<script>x</script>|y', 'text' ),
			'script with </scriptx>'                 => array( '<script>a</scriptx>b|</script>', 'rawtext' ),
			'script with </style> inside'            => array( '<script>"</style>"|</script>', 'rawtext' ),
			'script double-escape is conservative'   => array( '<script><!--<script></script>|x</script>', 'rawtext' ),
			'uppercase SCRIPT'                       => array( '<SCRIPT>a|</SCRIPT>', 'rawtext' ),
			'inside textarea'                        => array( '<textarea><b>|</b></textarea>', 'rawtext' ),
			'inside bogus comment'                   => array( '<!doctype| html>', 'tag' ),
			'after bogus comment'                    => array( '<!doctype html>|x', 'text' ),
			'after end tag'                          => array( '<p>x</p>|y', 'text' ),
			'abrupt comment "<!--->"'                => array( '<!--->|x', 'text' ),
			'comment closed by "--!>"'               => array( '<!-- a --!>|x', 'text' ),
			'unterminated comment'                   => array( '<!-- a|', 'comment' ),
			'after unquoted value and space'         => array( '<a href=x title="y>z|">', 'tag' ),
			'after tag with unquoted values'         => array( '<a href=x y=z>|w', 'text' ),
			'space before quoted value'              => array( '<a href= "x>y|">', 'tag' ),
			'spaces around "="'                      => array( '<a b = "c>d|">', 'tag' ),
			'second quoted attribute'                => array( '<a b="c" d="e>f|">', 'tag' ),
			'self-closing slash'                     => array( '<br/>|x', 'text' ),
			'plaintext runs to the end'              => array( '<plaintext>a</plaintext>|b', 'rawtext' ),
			'unclosed script runs to the end'        => array( '<script>a|', 'rawtext' ),
		);
	}

	/**
	 * Tokenizer context detection.
	 *
	 * @dataProvider context_cases
	 *
	 * @param string $marked   HTML with "|" at the offset to test.
	 * @param string $expected Expected context.
	 */
	public function test_context_at( $marked, $expected ) {
		$offset = strpos( $marked, '|' );
		$html   = str_replace( '|', '', $marked );
		$this->assertSame( $expected, WP_AI_Gatekeeper_Markup_Guard::context_at( $html, $offset ) );
	}
}
