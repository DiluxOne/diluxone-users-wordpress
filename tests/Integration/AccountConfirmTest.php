<?php
/**
 * The link in the confirmation e-mail, signed in, and the e-mails themselves.
 *
 * For a request filed from the account area, the link takes the person to
 * their account, asking them to sign in first; a copy is confirmed there, a
 * deletion is asked once more; and the e-mails are the plugin's templates. A
 * site can choose WordPress's way for each, and a request filed from Tools
 * always keeps it.
 */

namespace Tests\Integration;

class AccountConfirmTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] );
		$_GET = array();

		foreach ( (array) glob( wp_privacy_exports_dir() . '*.zip' ) as $file ) {
			wp_delete_file( (string) $file );
		}

		parent::tearDown();
	}

	/**
	 * Files a request the way the account area does (or Tools does), with its key.
	 *
	 * @return array{0: int, 1: string} The request and its confirmation key.
	 */
	private function file( int $user_id, string $kind = 'remove_personal_data', bool $from_account = true ): array {
		$marker  = 'remove_personal_data' === $kind ? DILUXONE_USERS_CLOSE_KEY : DILUXONE_USERS_EXPORT_KEY;
		$request = wp_create_user_request( get_userdata( $user_id )->user_email, $kind, $from_account ? array( $marker => $user_id ) : array() );

		$this->assertIsInt( $request );

		return array( $request, wp_generate_user_request_key( $request ) );
	}

	/** Opens the e-mail's link on wp-login.php. */
	private function open_link( int $request, string $key ): void {
		$_GET = array(
			'request_id'  => $request,
			'confirm_key' => $key,
		);
	}

	public function test_opened_with_no_session_the_link_asks_to_sign_in_and_remembers_where_to(): void {
		[ $request, $key ] = $this->file( $this->make_user() );

		wp_set_current_user( 0 );
		$this->open_link( $request, $key );

		$to = $this->expectRedirect( 'diluxone_users_confirm_intercept' );

		$this->assertSame( 'confirm', $this->redirectState( $to ) );
		$this->assertSame( $request . ':' . $key, self::$cookies[ DILUXONE_USERS_CONFIRM_COOKIE ]['value'] ?? '' );
		$this->assertTrue( self::$cookies[ DILUXONE_USERS_CONFIRM_COOKIE ]['options']['httponly'] ?? false );
	}

	public function test_signed_in_the_link_goes_to_the_account(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		wp_set_current_user( $user );
		$this->open_link( $request, $key );

		$to = $this->expectRedirect( 'diluxone_users_confirm_intercept' );

		$this->assertSame( (string) $request, $this->queryArg( $to, 'diluxone-users-request' ) );
		$this->assertSame( 'request-pending', get_post_status( $request ), 'Opening the link confirms nothing yet' );
	}

	/** WordPress's way, a request from Tools, and a key that is no good: all left to WordPress. */
	public function test_what_is_not_the_accounts_to_confirm_is_left_to_wordpress(): void {
		$user = $this->make_user();

		diluxone_users_update_option( 'diluxone_users_privacy_delete_link', 'direct' );
		[ $direct, $direct_key ] = $this->file( $user );
		$this->open_link( $direct, $direct_key );
		diluxone_users_confirm_intercept();
		diluxone_users_delete_option( 'diluxone_users_privacy_delete_link' );

		[ $tools, $tools_key ] = $this->file( $this->make_user(), 'export_personal_data', false );
		$this->open_link( $tools, $tools_key );
		diluxone_users_confirm_intercept();

		[ $ours ] = $this->file( $this->make_user(), 'export_personal_data' );
		$this->open_link( $ours, 'not-the-key' );
		diluxone_users_confirm_intercept();

		$this->addToAssertionCount( 1 ); // None of them redirected.
	}

	public function test_signing_in_through_any_door_comes_back_to_the_confirmation(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		$_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] = $request . ':' . $key;

		$to = (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user );

		$this->assertSame( diluxone_users_confirm_url( $request, $key ), $to );

		// Once the request is no longer waiting, the cookie decides nothing.
		wp_update_post(
			array(
				'ID'          => $request,
				'post_status' => 'request-confirmed',
			)
		);

		$this->assertSame( home_url( '/' ), (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user ) );
	}

	/** Arriving at the account from the link, as the page reads it. */
	private function arrive( int $request, string $key ): void {
		$_GET = array(
			'diluxone-users-request' => $request,
			'diluxone-users-key'     => $key,
		);
	}

	public function test_signed_in_as_somebody_else_the_link_is_not_theirs(): void {
		[ $request, $key ] = $this->file( $this->make_user() );

		wp_set_current_user( $this->make_user() );
		$this->arrive( $request, $key );

		$this->assertSame( 'other', diluxone_users_confirm_arrived()['state'] );
		$this->assertSame( 'other', $this->redirectState( $this->expectRedirect( 'diluxone_users_confirm_arrive' ) ) );
		$this->assertSame( 'request-pending', get_post_status( $request ) );
	}

	public function test_arriving_for_a_deletion_asks_and_deletes_nothing(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		wp_set_current_user( $user );
		$this->arrive( $request, $key );

		$this->assertSame( 'ok', diluxone_users_confirm_arrived()['state'] );
		diluxone_users_confirm_arrive(); // No redirect: the page asks.
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
	}

	public function test_arriving_for_a_copy_confirms_it_and_makes_the_file(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'WordPress needs ZipArchive to write the file.' );
		}

		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user, 'export_personal_data' );

		wp_set_current_user( $user );
		$this->arrive( $request, $key );

		$this->assertSame( 'ready', $this->redirectState( $this->expectRedirect( 'diluxone_users_confirm_arrive' ) ) );
		$this->assertSame( 'request-completed', get_post_status( $request ) );
	}

	/** Presses "Yes, delete my account" as somebody. */
	private function press_yes( int $as, int $request, string $key ): string {
		wp_set_current_user( $as );

		$this->postAs(
			$as,
			array(
				'diluxone_users_request_id' => $request,
				'diluxone_users_key'        => $key,
				'_wpnonce'                  => wp_create_nonce( 'diluxone_users_confirm_close_' . $request ),
			)
		);

		return $this->expectRedirect( 'diluxone_users_confirm_close' );
	}

	public function test_the_button_closes_the_account_and_lands_on_the_sign_in_page(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		$this->assertSame( 'closed', $this->redirectState( $this->press_yes( $user, $request, $key ) ) );
		$this->assertFalse( get_userdata( $user ) );
	}

	public function test_the_button_does_nothing_for_somebody_else_or_with_a_used_key(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		$this->assertSame( 'other', $this->redirectState( $this->press_yes( $this->make_user(), $request, $key ) ) );
		$this->assertSame( 'expired', $this->redirectState( $this->press_yes( $user, $request, 'not-the-key' ) ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
	}

	public function test_the_confirmation_is_mailed_in_the_plugins_words_and_tools_keeps_wordpresss(): void {
		[ $ours ] = $this->file( $this->make_user() );
		wp_send_user_request( $ours );
		$mail = $this->lastMail();

		$this->assertStringContainsString( 'deletion of your account', (string) $mail['subject'] );
		$this->assertStringContainsString( 'action=confirmaction', (string) $mail['message'] );
		$this->assertStringNotContainsString( '###', (string) $mail['message'] );

		[ $tools ] = $this->file( $this->make_user(), 'remove_personal_data', false );
		wp_send_user_request( $tools );

		$this->assertStringContainsString( 'Confirm Action', (string) $this->lastMail()['subject'] );
	}

	public function test_the_ready_mail_sends_them_to_the_account_or_mails_the_file(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'WordPress needs ZipArchive to write the file.' );
		}

		[ $request ] = $this->file( $this->make_user(), 'export_personal_data' );
		do_action( 'user_request_action_confirmed', $request );
		$mail = $this->lastMail();

		$this->assertStringContainsString( 'is ready', (string) $mail['subject'] );
		$this->assertStringContainsString( diluxone_users_account_url( 'privacy' ), (string) $mail['message'] );
		$this->assertStringNotContainsString( '.zip', (string) $mail['message'] );

		diluxone_users_update_option( 'diluxone_users_privacy_export_file', 'link' );
		[ $linked ] = $this->file( $this->make_user(), 'export_personal_data' );
		do_action( 'user_request_action_confirmed', $linked );

		$this->assertStringContainsString( '.zip', (string) $this->lastMail()['message'] );
	}
}
