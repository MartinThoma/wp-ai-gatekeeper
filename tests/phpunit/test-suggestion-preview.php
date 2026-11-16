<?php
/**
 * Tests for the "show in full article" suggestion preview.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_Suggestion_Preview
 */
class Test_Suggestion_Preview extends WP_AI_Gatekeeper_TestCase {

	/**
	 * Create an AI suggestion directly through the service.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $original  Original text.
	 * @param string $suggested Suggested text.
	 * @param string $field     Target field.
	 * @return int Suggestion ID.
	 */
	private function ai_suggestion( $post_id, $original, $suggested, $field = 'post_content' ) {
		$result = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$post_id,
			array(
				'target_field'   => $field,
				'original_text'  => $original,
				'suggested_text' => $suggested,
			),
			$this->ai_user_id
		);
		$this->assertIsArray( $result );
		return $result['id'];
	}

	/**
	 * Fetch the preview as the editor.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return WP_REST_Response
	 */
	private function preview( $suggestion_id ) {
		return $this->dispatch_rest( 'GET', "/ai-gatekeeper/v1/suggestions/{$suggestion_id}/preview", array(), false );
	}

	/**
	 * Three-paragraph article used by most tests.
	 *
	 * @return int Post ID.
	 */
	private function article() {
		return $this->create_editor_post(
			array(
				'post_title'   => 'Account Security',
				'post_content' => "<!-- wp:paragraph --><p>First paragraph.</p><!-- /wp:paragraph -->\n\n"
					. "<!-- wp:paragraph --><p>Employees use legacy passwords today.</p><!-- /wp:paragraph -->\n\n"
					. '<!-- wp:paragraph --><p>Last paragraph.</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Test: open suggestion preview shows the whole article with the change in place.
	 */
	public function test_open_suggestion_shows_whole_article_with_change_in_place() {
		$id   = $this->ai_suggestion( $this->article(), 'legacy passwords', 'passkeys' );
		$resp = $this->preview( $id );

		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$html = $data['html'];

		$this->assertTrue( $data['highlighted'] );
		$this->assertNull( $data['notice'] );
		$this->assertStringContainsString( 'Account Security', $html );
		$this->assertStringContainsString( '<del class="wpai-diff-word-del">legacy passwords</del><ins class="wpai-diff-word-ins">passkeys</ins>', $html );

		$first  = strpos( $html, 'First paragraph.' );
		$change = strpos( $html, 'wpai-preview-change' );
		$last   = strpos( $html, 'Last paragraph.' );
		$this->assertTrue( false !== $first && $first < $change && $change < $last, 'Change is shown in its place in the article.' );
	}

	/**
	 * Test: preview strips scripts from trusted post content.
	 */
	public function test_preview_strips_scripts_from_trusted_content() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Hello world</p><script>alert(1)</script>' ) );
		$id      = $this->ai_suggestion( $post_id, 'Hello', 'Hi' );

		$html = $this->preview( $id )->get_data()['html'];
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'wpai-preview-change', $html );
	}

	/**
	 * Test: change inside block attributes is not highlighted but the article is shown.
	 */
	public function test_change_in_block_attributes_falls_back_without_highlight() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<!-- wp:heading {"level":2} --><h2>Big Title</h2><!-- /wp:heading -->' ) );
		$id      = $this->ai_suggestion( $post_id, '"level":2', '"level":3' );

		$data = $this->preview( $id )->get_data();
		$this->assertFalse( $data['highlighted'] );
		$this->assertNotEmpty( $data['notice'] );
		$this->assertStringContainsString( 'Big Title', $data['html'] );
		$this->assertStringNotContainsString( 'wpai-preview-change', $data['html'] );
	}

	/**
	 * Test: merged suggestion highlights the merged text.
	 */
	public function test_merged_suggestion_highlights_merged_text() {
		$id = $this->ai_suggestion( $this->article(), 'legacy passwords', 'passkeys' );
		wp_set_current_user( $this->editor_id );
		$this->assertIsArray( WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id ) );

		$data = $this->preview( $id )->get_data();
		$this->assertTrue( $data['highlighted'] );
		$this->assertStringContainsString( '<ins class="wpai-diff-word-ins">passkeys</ins>', $data['html'] );
		$this->assertStringContainsString( '<del class="wpai-diff-word-del">legacy passwords</del>', $data['html'], 'Shows what the merge replaced.' );
	}

	/**
	 * Test: when the original text was edited away, the current article is shown with a notice.
	 */
	public function test_conflicted_suggestion_shows_current_article_with_notice() {
		$post_id = $this->article();
		$id      = $this->ai_suggestion( $post_id, 'legacy passwords', 'passkeys' );
		wp_set_current_user( $this->editor_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>Rewritten by hand.</p>',
			)
		);

		$data = $this->preview( $id )->get_data();
		$this->assertFalse( $data['highlighted'] );
		$this->assertNotEmpty( $data['notice'] );
		$this->assertStringContainsString( 'Rewritten by hand.', $data['html'] );
	}

	/**
	 * Test: title suggestion is highlighted in the title.
	 */
	public function test_title_suggestion_is_highlighted_in_title() {
		$id   = $this->ai_suggestion( $this->article(), 'Account Security', 'Account and Data Security', 'post_title' );
		$data = $this->preview( $id )->get_data();

		$this->assertTrue( $data['highlighted'] );
		$this->assertMatchesRegularExpression( '#<h1[^>]*>.*wpai-preview-change.*</h1>#s', $data['html'] );
		$this->assertStringContainsString( 'First paragraph.', $data['html'] );
	}

	/**
	 * Test: plain-text changes are highlighted word by word.
	 */
	public function test_plain_text_change_is_highlighted_word_by_word() {
		$id   = $this->ai_suggestion( $this->article(), 'Account Security', 'Account and Data Security', 'post_title' );
		$html = $this->preview( $id )->get_data()['html'];

		$this->assertStringContainsString( 'Account <ins class="wpai-diff-word-ins">and Data </ins>Security', $html );
		$this->assertStringNotContainsString( '<del', $html );
	}

	/**
	 * Test: a rewritten phrase reads as old text, then new text (no interleaving).
	 */
	public function test_rewrite_reads_as_old_then_new() {
		$id   = $this->ai_suggestion( $this->article(), 'use legacy passwords today', 'use passkeys with hardware keys today' );
		$html = $this->preview( $id )->get_data()['html'];

		$this->assertStringContainsString(
			'use <del class="wpai-diff-word-del">legacy passwords</del><ins class="wpai-diff-word-ins">passkeys with hardware keys</ins> today',
			$html
		);
	}

	/**
	 * Test: a contributor cannot preview suggestions on a post they cannot read.
	 */
	public function test_contributor_cannot_preview_unreadable_post() {
		$post_id = $this->create_editor_post(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Secret plans.</p>',
			)
		);
		$id      = $this->ai_suggestion( $post_id, 'Secret', 'Top secret' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$resp = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/ai-gatekeeper/v1/suggestions/{$id}/preview" ) );
		$this->assertSame( 403, $resp->get_status() );
	}

	/**
	 * Test: unknown suggestion returns 404.
	 */
	public function test_unknown_suggestion_returns_404() {
		$this->assertSame( 404, $this->preview( 999999 )->get_status() );
	}

	/**
	 * Test: when merged text was edited afterwards, the current article is
	 * shown with a notice instead of a wrong highlight.
	 */
	public function test_edited_merged_text_shows_notice() {
		$post_id = $this->article();
		$id      = $this->ai_suggestion( $post_id, 'legacy passwords', 'passkeys' );
		wp_set_current_user( $this->editor_id );
		WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => str_replace( 'passkeys', 'hardware keys', get_post( $post_id )->post_content ),
			)
		);

		$data = $this->preview( $id )->get_data();
		$this->assertFalse( $data['highlighted'] );
		$this->assertStringContainsString( 'merged text has been edited', $data['notice'] );
		$this->assertStringContainsString( 'hardware keys', $data['html'] );
	}

	/**
	 * Test: a change that includes markup is highlighted as a whole (word
	 * boundaries could split tags).
	 */
	public function test_markup_change_is_highlighted_as_a_whole() {
		$post_id = $this->create_editor_post( array( 'post_content' => '<p>Employees use <em>legacy</em> passwords today.</p>' ) );
		$id      = $this->ai_suggestion( $post_id, '<em>legacy</em> passwords', 'passkeys' );

		$data = $this->preview( $id )->get_data();
		$this->assertTrue( $data['highlighted'] );
		$this->assertStringContainsString( '<del class="wpai-diff-word-del"><em>legacy</em> passwords</del><ins class="wpai-diff-word-ins">passkeys</ins>', $data['html'] );
	}

	/**
	 * Test: a merged deletion cannot be located afterwards (nothing was
	 * inserted), so the current article is shown with a notice.
	 */
	public function test_merged_deletion_shows_notice() {
		$post_id = $this->article();
		$id      = $this->ai_suggestion( $post_id, ' legacy', '' );
		wp_set_current_user( $this->editor_id );
		$this->assertIsArray( WP_AI_Gatekeeper_Suggestion_Service::merge_suggestion( $id, $this->editor_id ) );

		$data = $this->preview( $id )->get_data();
		$this->assertFalse( $data['highlighted'] );
		$this->assertNotEmpty( $data['notice'] );
		$this->assertStringContainsString( 'Employees use passwords today.', $data['html'] );
	}

	/**
	 * Test: rendering a preview restores the global post afterwards, so the
	 * page around it (e.g. the post editor) keeps working with its own post.
	 */
	public function test_preview_restores_global_post() {
		global $post;
		$own  = $this->create_editor_post( array( 'post_title' => 'Page being edited' ) );
		$post = get_post( $own ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the editor's post.
		setup_postdata( $post );

		$id = $this->ai_suggestion( $this->article(), 'legacy passwords', 'passkeys' );
		$this->preview( $id );

		$this->assertSame( $own, $GLOBALS['post']->ID );
		$this->assertSame( 'Page being edited', get_the_title() );
		$post = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset.
	}
}
