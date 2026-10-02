<?php
/**
 * A photo that arrived: what is refused, and what is kept.
 *
 * The upload itself cannot be made from the command line — is_uploaded_file()
 * is false for every file here — so the checks after it are exercised through
 * diluxone_users_avatar_store(), on files written for the test with GD: the
 * weight, the type read from the content and not from the name, the size in
 * pixels, a sideload that fails, the photo kept, and the one it replaces.
 */

namespace Tests\Integration;

class CoverFieldsAvatarUploadTest extends IntegrationTestCase {

	/** @var array<int, string> Files the test wrote. */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_avatar_upload', 1 );
		diluxone_users_update_option( 'diluxone_users_avatar_max_kb', 2048 );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		parent::tearDown();
	}

	/**
	 * A file as $_FILES describes one.
	 *
	 * @return array<string, mixed>
	 */
	private function file( string $name, string $bytes ): array {
		$path = (string) tempnam( sys_get_temp_dir(), 'du-photo' );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->files[] = $path;

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'size'     => strlen( $bytes ),
			'error'    => 0,
		);
	}

	/** A PNG of this many pixels, as bytes. */
	private function png( int $width, int $height ): string {
		$image = imagecreatetruecolor( $width, $height );
		ob_start();
		imagepng( $image );

		return (string) ob_get_clean();
	}

	public function test_a_photo_heavier_than_allowed_is_refused_saying_how_much_is(): void {
		diluxone_users_update_option( 'diluxone_users_avatar_max_kb', 1 );
		$file         = $this->file( 'me.png', $this->png( 10, 10 ) );
		$file['size'] = 2048;

		$refused = diluxone_users_avatar_store( $this->make_user(), $file );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'diluxone_users_avatar_big', $refused->get_error_code() );
		$this->assertStringContainsString( size_format( KB_IN_BYTES ), $refused->get_error_message() );

		diluxone_users_update_option( 'diluxone_users_avatar_max_kb', 0 );
		$this->assertSame( 'diluxone_users_avatar_big', diluxone_users_avatar_store( $this->make_user(), $file )->get_error_code(), 'nothing allowed is one kilobyte allowed' );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function not_photos(): array {
		return array(
			'text named like a picture' => array( 'x.png', 'just some text' ),
			'a drawing that is code'    => array( 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
			'a script named like a JPG' => array( 'x.jpg', '<?php echo 1;' ),
		);
	}

	/** @dataProvider not_photos */
	public function test_what_is_not_a_photo_is_refused_whatever_its_name( string $name, string $bytes ): void {
		$refused = diluxone_users_avatar_store( $this->make_user(), $this->file( $name, $bytes ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'diluxone_users_avatar_type', $refused->get_error_code() );
	}

	public function test_a_picture_too_large_in_pixels_is_refused_and_the_largest_allowed_is_not(): void {
		$user = $this->make_user();

		$refused = diluxone_users_avatar_store( $user, $this->file( 'wide.png', $this->png( DILUXONE_USERS_AVATAR_MAX_SIDE + 1, 1 ) ) );
		$this->assertSame( 'diluxone_users_avatar_huge', $refused->get_error_code() );

		$this->assertIsInt( diluxone_users_avatar_store( $user, $this->file( 'edge.png', $this->png( DILUXONE_USERS_AVATAR_MAX_SIDE, 1 ) ) ) );
	}

	public function test_a_sideload_that_fails_leaves_the_photo_there_was(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_avatar', 4242 );

		$this->hook( 'wp_handle_sideload_prefilter', static fn( array $file ): array => array( 'error' => 'boom' ) + $file );

		$this->assertInstanceOf( \WP_Error::class, diluxone_users_avatar_store( $user, $this->file( 'me.png', $this->png( 10, 10 ) ) ) );
		$this->assertSame( '4242', (string) get_user_meta( $user, 'diluxone_users_avatar', true ) );
	}

	public function test_a_photo_is_kept_as_theirs_and_a_new_one_replaces_it(): void {
		$user = $this->make_user();

		$first = diluxone_users_avatar_store( $user, $this->file( 'one.png', $this->png( 10, 10 ) ) );

		$this->assertIsInt( $first );
		$this->assertSame( $user, (int) get_post_field( 'post_author', $first ) );
		$this->assertSame( $first, diluxone_users_avatar_id( $user ) );
		$this->assertSame( get_current_blog_id(), (int) get_user_meta( $user, 'diluxone_users_avatar_site', true ) );

		$second = diluxone_users_avatar_store( $user, $this->file( 'two.png', $this->png( 12, 12 ) ) );

		$this->assertNull( get_post( $first ), 'the old one is gone' );
		$this->assertSame( $second, diluxone_users_avatar_id( $user ) );
	}
}
