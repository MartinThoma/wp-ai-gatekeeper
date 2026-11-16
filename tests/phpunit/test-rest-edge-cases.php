<?php
/**
 * Tests: REST edge cases. Every route refuses anonymous callers, error
 * responses for missing or foreign content, draft updates, and uploads.
 *
 * @package WP_AI_Gatekeeper
 */

/**
 * Class Test_REST_Edge_Cases
 */
class Test_REST_Edge_Cases extends WP_AI_Gatekeeper_TestCase {

	/**
	 * A published post by the editor.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * An open AI suggestion on that post.
	 *
	 * @var int
	 */
	private $suggestion_id;

	/**
	 * Creates the fixtures.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id       = $this->create_editor_post( array( 'post_content' => '<p>Use legacy passwords.</p>' ) );
		$created             = WP_AI_Gatekeeper_Suggestion_Service::create_suggestion(
			$this->post_id,
			array(
				'original_text'  => 'legacy passwords',
				'suggested_text' => 'passkeys',
			),
			$this->ai_user_id
		);
		$this->suggestion_id = (int) $created['id'];
	}

	/**
	 * Sends a request as a given user (0 for anonymous).
	 *
	 * @param int    $user_id User ID.
	 * @param string $method  HTTP method.
	 * @param string $route   Route.
	 * @param array  $params  Parameters.
	 * @return WP_REST_Response
	 */
	private function request_as( $user_id, $method, $route, array $params = array() ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Data provider: every plugin route, with its required parameters.
	 * "{post}" and "{suggestion}" are replaced with fixture IDs.
	 *
	 * @return array
	 */
	public function routes() {
		$texts = array(
			'original_text'  => 'legacy',
			'suggested_text' => 'new',
		);
		return array(
			'list posts'         => array( 'GET', '/posts', array() ),
			'create draft'       => array( 'POST', '/posts', array( 'title' => 'T' ) ),
			'read post'          => array( 'GET', '/posts/{post}', array() ),
			'update draft'       => array( 'PUT', '/posts/{post}', array( 'title' => 'T' ) ),
			'upload media'       => array( 'POST', '/media', array() ),
			'list suggestions'   => array( 'GET', '/suggestions', array() ),
			'post suggestions'   => array( 'GET', '/posts/{post}/suggestions', array() ),
			'create suggestion'  => array( 'POST', '/posts/{post}/suggestions', $texts ),
			'read suggestion'    => array( 'GET', '/suggestions/{suggestion}', array() ),
			'update suggestion'  => array( 'PATCH', '/suggestions/{suggestion}', array( 'suggested_text' => 'x' ) ),
			'preview suggestion' => array( 'GET', '/suggestions/{suggestion}/preview', array() ),
			'comment'            => array( 'POST', '/suggestions/{suggestion}/comments', array( 'comment' => 'Hi' ) ),
			'merge'              => array( 'POST', '/suggestions/{suggestion}/merge', array() ),
			'reject'             => array( 'POST', '/suggestions/{suggestion}/reject', array() ),
		);
	}

	/**
	 * Test: anonymous callers get 401 from every route and change nothing.
	 *
	 * @dataProvider routes
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below /ai-gatekeeper/v1.
	 * @param array  $params Required parameters.
	 */
	public function test_every_route_refuses_anonymous_callers( $method, $route, array $params ) {
		$route    = str_replace( array( '{post}', '{suggestion}' ), array( $this->post_id, $this->suggestion_id ), $route );
		$response = $this->request_as( 0, $method, '/ai-gatekeeper/v1' . $route, $params );

		$this->assertSame( 401, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '<p>Use legacy passwords.</p>', get_post( $this->post_id )->post_content );
		$this->assertSame( 'open', WP_AI_Gatekeeper_Suggestion_Service::get_suggestion_details( $this->suggestion_id )['status'] );
	}

	/**
	 * Test: a human who cannot edit the post cannot update it.
	 */
	public function test_contributor_cannot_update_others_post() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$response    = $this->request_as( $contributor, 'PUT', "/ai-gatekeeper/v1/posts/{$this->post_id}", array( 'title' => 'Hijacked' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotSame( 'Hijacked', get_post( $this->post_id )->post_title );
	}

	/**
	 * Test: missing articles give 404 instead of a permission error.
	 */
	public function test_missing_articles_return_404() {
		$this->assertSame( 404, $this->request_as( $this->ai_user_id, 'GET', '/ai-gatekeeper/v1/posts/999999' )->get_status() );
		$this->assertSame( 404, $this->request_as( $this->ai_user_id, 'PUT', '/ai-gatekeeper/v1/posts/999999', array( 'title' => 'T' ) )->get_status() );
		$this->assertSame(
			404,
			$this->request_as(
				$this->ai_user_id,
				'POST',
				'/ai-gatekeeper/v1/posts/999999/suggestions',
				array(
					'original_text'  => 'a',
					'suggested_text' => 'b',
				)
			)->get_status()
		);
	}

	/**
	 * Test: the AI updates the content, excerpt, and tags of its own draft, and
	 * it stays a draft.
	 */
	public function test_ai_updates_own_draft() {
		$created = $this->request_as( $this->ai_user_id, 'POST', '/ai-gatekeeper/v1/posts', array( 'title' => 'Draft' ) );
		$this->assertSame( 201, $created->get_status() );
		$id  = $created->get_data()['id'];
		$tag = self::factory()->tag->create();

		$updated = $this->request_as(
			$this->ai_user_id,
			'PUT',
			"/ai-gatekeeper/v1/posts/{$id}",
			array(
				'content' => '<p>Body</p><script>alert(1)</script>',
				'excerpt' => 'Summary',
				'tags'    => array( $tag ),
			)
		);

		$this->assertSame( 200, $updated->get_status() );
		$post = get_post( $id );
		$this->assertSame( '<p>Body</p>alert(1)', $post->post_content );
		$this->assertSame( 'Summary', $post->post_excerpt );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( array( $tag ), wp_get_post_tags( $id, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * Test: merging an already merged suggestion is refused with 409.
	 */
	public function test_merging_twice_is_refused() {
		$route = "/ai-gatekeeper/v1/suggestions/{$this->suggestion_id}/merge";
		$this->assertSame( 200, $this->request_as( $this->editor_id, 'POST', $route )->get_status() );

		$again = $this->request_as( $this->editor_id, 'POST', $route );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'suggestion_already_merged', $again->get_data()['code'] );
	}

	/**
	 * Test: a multipart upload that is not a real HTTP upload is refused
	 * (WordPress only accepts files PHP received as uploads).
	 */
	public function test_multipart_upload_must_be_a_real_upload() {
		$tmp = wp_tempnam( 'fake.png' );
		file_put_contents( $tmp, 'not an upload' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		wp_set_current_user( $this->ai_user_id );
		$request = new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'fake.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => 0,
					'size'     => 13,
				),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		wp_delete_file( $tmp );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'media_upload_failed', $response->get_data()['code'] );
	}

	/**
	 * Test: a raw upload without a file name gets one from its content type.
	 */
	public function test_raw_upload_names_file_from_content_type() {
		// A valid 1x1 JPEG.
		$jpeg = base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.

		wp_set_current_user( $this->ai_user_id );
		$request = new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_body( $jpeg );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$file = get_attached_file( $response->get_data()['id'] );
		$this->assertMatchesRegularExpression( '/ai-upload-\d{8}-\d{6}(-\d+)?\.jpg$/', $file );
		wp_delete_attachment( $response->get_data()['id'], true );
	}

	/**
	 * Test: a multipart upload under another field name than "file" is used
	 * too (and, not being a real upload here, refused by WordPress).
	 */
	public function test_multipart_upload_under_other_field_name() {
		$tmp = wp_tempnam( 'fake.png' );
		file_put_contents( $tmp, 'not an upload' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		wp_set_current_user( $this->ai_user_id );
		$request = new WP_REST_Request( 'POST', '/ai-gatekeeper/v1/media' );
		$request->set_file_params(
			array(
				'image' => array(
					'name'     => 'fake.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => 0,
					'size'     => 13,
				),
			)
		);
		$response = rest_get_server()->dispatch( $request );
		wp_delete_file( $tmp );

		$this->assertSame( 'media_upload_failed', $response->get_data()['code'] );
	}
}
