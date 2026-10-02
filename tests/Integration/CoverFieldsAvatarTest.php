<?php
/**
 * The profile photo: whose it is, where it is drawn, sent and removed.
 *
 * Whoever WordPress means — an id, a user, a post, a comment by a member or
 * by e-mail — is worked out to the account. An uploaded photo is the avatar
 * while uploads are on, and an attachment that is not an image is none. The
 * form: nothing for somebody signed out or on a site without uploads, the
 * Remove button only with a photo, a refusal shown once. The handler sends
 * somebody signed out to sign in, refuses a forged nonce, removes the photo
 * with its attachment — and never deletes somebody else's attachment that a
 * meta points at — and refuses an upload on a site that takes none or a
 * request with no file in it (a file name posted as a list included).
 */

namespace Tests\Integration;

class CoverFieldsAvatarTest extends IntegrationTestCase {

	/** An attachment in this site's library, by this author. */
	private function attachment( int $author, string $file = 'photo.png', string $mime = 'image/png' ): int {
		return (int) wp_insert_attachment(
			array(
				'post_title'     => $file,
				'post_mime_type' => $mime,
				'post_author'    => $author,
				'post_status'    => 'inherit',
			),
			'2026/10/' . $file
		);
	}

	/** Makes the attachment somebody's photo, uploaded on this site. */
	private function give_photo( int $user_id, int $attachment ): void {
		update_user_meta( $user_id, 'diluxone_users_avatar', $attachment );
		update_user_meta( $user_id, 'diluxone_users_avatar_site', get_current_blog_id() );
	}

	/* ── Whose avatar ────────────────────────────────────────────────── */

	public function test_every_way_wordpress_names_somebody_reaches_the_account(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;
		$post  = wp_insert_post(
			array(
				'post_title'  => 'By them',
				'post_author' => $user,
			)
		);

		$this->assertSame( $user, diluxone_users_avatar_user_id( (string) $user ) );
		$this->assertSame( $user, diluxone_users_avatar_user_id( get_userdata( $user ) ) );
		$this->assertSame( $user, diluxone_users_avatar_user_id( get_post( $post ) ) );
		$this->assertSame( $user, diluxone_users_avatar_user_id( $email ) );
		$this->assertSame( 0, diluxone_users_avatar_user_id( 'nobody@example.test' ) );
		$this->assertSame( 0, diluxone_users_avatar_user_id( array( 'not', 'a', 'person' ) ) );

		$member = new \WP_Comment(
			(object) array(
				'user_id'              => $user,
				'comment_author_email' => '',
			)
		);
		$this->assertSame( $user, diluxone_users_avatar_user_id( $member ) );

		$by_mail = new \WP_Comment(
			(object) array(
				'user_id'              => 0,
				'comment_author_email' => $email,
			)
		);
		$this->assertSame( $user, diluxone_users_avatar_user_id( $by_mail ), 'A comment left signed out, with their address' );

		$stranger = new \WP_Comment(
			(object) array(
				'user_id'              => 0,
				'comment_author_email' => 'stranger@example.test',
			)
		);
		$this->assertSame( 0, diluxone_users_avatar_user_id( $stranger ) );
	}

	public function test_an_uploaded_image_is_the_avatar_and_anything_else_is_not(): void {
		$user = $this->make_user();
		$this->give_photo( $user, $this->attachment( $user ) );

		$this->assertStringEndsWith( '/2026/10/photo.png', diluxone_users_avatar_url( $user, 200 ) );

		$data = diluxone_users_avatar_data( array( 'size' => 64 ), $user );
		$this->assertTrue( $data['found_avatar'] );
		$this->assertStringEndsWith( '/2026/10/photo.png', $data['url'] );

		// Uploads turned off: the photo stays in the library and stops being shown.
		diluxone_users_update_option( 'diluxone_users_avatar_upload', 0 );
		$this->assertArrayNotHasKey( 'url', diluxone_users_avatar_data( array( 'size' => 64 ), $user ) );

		// A meta pointing at something that is no image.
		$this->give_photo( $user, $this->attachment( $user, 'cv.pdf', 'application/pdf' ) );
		$this->assertSame( '', diluxone_users_avatar_url( $user ) );
	}

	/* ── The form ────────────────────────────────────────────────────── */

	public function test_the_form_is_drawn_only_for_a_person_on_a_site_that_takes_photos(): void {
		$this->assertSame( '', diluxone_users_shortcode_avatar(), 'Signed out' );

		$user = $this->make_user();
		wp_set_current_user( $user );

		diluxone_users_update_option( 'diluxone_users_avatar_upload', 0 );
		$this->assertSame( '', diluxone_users_shortcode_avatar(), 'Uploads off' );

		diluxone_users_update_option( 'diluxone_users_avatar_upload', 1 );
		diluxone_users_flash_set( $user, 'avatar', 'That is not a photo.' );

		$html = diluxone_users_shortcode_avatar();

		$this->assertStringContainsString( 'diluxone-users-notice--error">That is not a photo.', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( 'diluxone_users_avatar' ) . '"', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_avatar_remove"', $html, 'Nothing to remove' );

		$this->give_photo( $user, $this->attachment( $user ) );
		$again = diluxone_users_shortcode_avatar();

		$this->assertStringContainsString( 'name="diluxone_users_avatar_remove"', $again );
		$this->assertStringNotContainsString( 'diluxone-users-notice--error', $again, 'A refusal is shown once' );
	}

	/* ── Removing ────────────────────────────────────────────────────── */

	public function test_removing_takes_the_attachment_with_it_and_never_somebody_elses(): void {
		$user  = $this->make_user();
		$other = $this->make_user();

		diluxone_users_avatar_delete( $user );
		$this->assertSame( 0, diluxone_users_avatar_id( $user ), 'Nothing to remove is fine' );

		$theirs = $this->attachment( $other );
		$this->give_photo( $user, $theirs );

		diluxone_users_avatar_delete( $user );

		$this->assertInstanceOf( \WP_Post::class, get_post( $theirs ), 'Another person’s picture is never deleted' );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_avatar', true ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_avatar_site', true ) );

		$mine = $this->attachment( $user );
		$this->give_photo( $user, $mine );

		diluxone_users_avatar_delete( $user );

		$this->assertNull( get_post( $mine ) );
		$this->assertSame( 0, diluxone_users_avatar_id( $user ) );
	}

	/* ── Uploading ───────────────────────────────────────────────────── */

	public function test_an_upload_is_refused_on_a_site_without_photos_or_with_no_file(): void {
		$user = $this->make_user();

		diluxone_users_update_option( 'diluxone_users_avatar_upload', 0 );
		$this->assertSame( 'diluxone_users_avatar_off', diluxone_users_avatar_upload( $user, array( 'tmp_name' => __FILE__ ) )->get_error_code() );

		diluxone_users_update_option( 'diluxone_users_avatar_upload', 1 );
		$this->assertSame( 'diluxone_users_avatar_none', diluxone_users_avatar_upload( $user, array() )->get_error_code() );
		$this->assertSame(
			'diluxone_users_avatar_none',
			diluxone_users_avatar_upload( $user, array( 'tmp_name' => __FILE__, 'name' => 'x.png', 'size' => 10 ) )->get_error_code(),
			'A path that did not arrive with this request is not an upload'
		);
	}

	public function test_the_accepted_types_are_photographs_and_never_svg(): void {
		$this->assertSame( array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), diluxone_users_avatar_types() );
	}

	/* ── The handler ─────────────────────────────────────────────────── */

	public function test_the_handler_sends_somebody_signed_out_to_sign_in(): void {
		$this->postAs( 0, array() );

		$this->assertSame( diluxone_users_login_url(), $this->expectRedirect( 'diluxone_users_avatar_submit' ) );
	}

	public function test_the_handler_refuses_a_forged_nonce_and_removes_nothing(): void {
		$user  = $this->make_user();
		$photo = $this->attachment( $user );
		$this->give_photo( $user, $photo );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                     => 'forged',
				'diluxone_users_avatar_remove' => '1',
			)
		);

		$this->expectDie( 'diluxone_users_avatar_submit', self::EXPIRED, 403 );
		$this->assertSame( $photo, diluxone_users_avatar_id( $user ) );
	}

	public function test_the_handler_removes_the_photo_when_asked(): void {
		$user  = $this->make_user();
		$photo = $this->attachment( $user );
		$this->give_photo( $user, $photo );
		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                     => wp_create_nonce( 'diluxone_users_avatar' ),
				'diluxone_users_avatar_remove' => '1',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_avatar_submit' );

		$this->assertSame( 'saved', $this->redirectState( $url ) );
		$this->assertStringStartsWith( diluxone_users_account_url( 'details' ), $url );
		$this->assertNull( get_post( $photo ) );
	}

	public function test_the_handler_carries_a_refused_upload_to_the_next_page_on_the_server(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );
		$this->postAs( $user, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_avatar' ) ) );

		// A file field posted as lists, which is not a file.
		$_FILES['diluxone_users_avatar_file'] = array(
			'tmp_name' => array( '/etc/passwd' ),
			'name'     => array( 'x.png' ),
			'size'     => array( 1 ),
		);

		try {
			$url = $this->expectRedirect( 'diluxone_users_avatar_submit' );
		} finally {
			unset( $_FILES['diluxone_users_avatar_file'] );
		}

		$this->assertSame( diluxone_users_account_url( 'details' ), $url );
		$this->assertSame( 'No file arrived.', diluxone_users_flash_take( $user, 'avatar' ) );

		// With uploads off, whatever arrives.
		diluxone_users_update_option( 'diluxone_users_avatar_upload', 0 );
		$this->expectRedirect( 'diluxone_users_avatar_submit' );
		$this->assertSame( 'This site does not accept profile photos.', diluxone_users_flash_take( $user, 'avatar' ) );
	}
}
