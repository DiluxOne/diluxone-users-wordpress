<?php
/**
 * H-03 and M-03: the second step has a limit, and no door around it.
 *
 * The challenge and its submission are played by function, the way the
 * hooks would call them: the redirect is caught, the e-mail with the code
 * is caught, and what is asserted is what a script attacking the code
 * would run into.
 */

namespace Tests\Integration;

class TwoFactorFlowTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		// The site's own sign-in page, where the challenge is drawn and where
		// an attempt that ran out says "expired". Without one it is
		// wp-login.php, which TwoFactorSurfaceTest walks.
		diluxone_users_update_option(
			'diluxone_users_login_page',
			(int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Sign in',
					'post_content' => '[diluxone_users_login]',
				)
			)
		);

		$this->user = $this->make_user();
	}

	/**
	 * The password door: WordPress opens the session before `wp_login`, and
	 * the valid cookie is already in the response when the challenge starts.
	 * Unless the session it names is destroyed, whoever keeps that cookie is
	 * in without a second step.
	 */
	public function test_the_session_the_password_opened_does_not_survive_the_challenge(): void {
		$password = 'Correct-Horse-9';
		wp_set_password( $password, $this->user );
		$login = get_userdata( $this->user )->user_login;

		$this->expectRedirect(
			fn() => wp_signon(
				array(
					'user_login'    => $login,
					'user_password' => $password,
				)
			)
		);

		$this->assertSame( array(), \WP_Session_Tokens::get_instance( $this->user )->get_all() );
	}

	/** Starts the challenge and returns the key the screen would carry. */
	private function challenge(): string {
		wp_set_current_user( 0 );

		$url = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, 'password', false, home_url( '/after/' ) ) );

		$this->assertSame( (string) $this->user, $this->queryArg( $url, 'diluxone_users_2fa' ) );

		return $this->queryArg( $url, 'diluxone_users_key' );
	}

	/** The six digits inside the last e-mail. */
	private function mailedCode(): string {
		preg_match( '/\b(\d{6})\b/', (string) ( $this->lastMail()['message'] ?? '' ), $m );

		return $m[1] ?? '';
	}

	/** Submits a code (or a resend) and returns where it went. */
	private function submit( string $key, string $code, bool $resend = false ): string {
		$post = array(
			'diluxone_users_2fa_user'   => (string) $this->user,
			'diluxone_users_2fa_key'    => $key,
			'diluxone_users_2fa_method' => 'email',
			'diluxone_users_2fa_code'   => $code,
		);

		if ( $resend ) {
			$post['diluxone_users_2fa_resend'] = '1';
		}

		$this->postAs( 0, $post );

		return $this->expectRedirect( 'diluxone_users_2fa_handle' );
	}

	public function test_the_right_code_opens_the_session_and_ends_the_attempt(): void {
		$key = $this->challenge();
		$url = $this->submit( $key, $this->mailedCode() );

		$this->assertSame( home_url( '/after/' ), $url );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_pending', true ) );
	}

	public function test_a_wrong_code_costs_a_try_and_the_fifth_costs_the_attempt(): void {
		$key = $this->challenge();

		for ( $i = 1; $i < DILUXONE_USERS_2FA_TRIES; $i++ ) {
			$this->assertSame( 'code', $this->redirectState( $this->submit( $key, '000000' ) ), "Try $i should still be a try" );
		}

		$this->assertSame( 'expired', $this->redirectState( $this->submit( $key, '000000' ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_pending', true ), 'The attempt has to be gone' );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_after_the_limit_even_the_right_code_is_no_good(): void {
		// The count would mean nothing if the person could keep guessing
		// against the same code: the code went with the attempt.
		$key  = $this->challenge();
		$code = $this->mailedCode();

		for ( $i = 0; $i < DILUXONE_USERS_2FA_TRIES; $i++ ) {
			$this->submit( $key, '000000' );
		}

		$this->assertSame( 'expired', $this->redirectState( $this->submit( $key, $code ) ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_the_count_survives_a_reload_of_the_screen(): void {
		$key = $this->challenge();

		$this->submit( $key, '000000' );
		$this->submit( $key, '000000' );

		$this->assertSame( 2, (int) get_user_meta( $this->user, 'diluxone_users_2fa_pending', true )['tries'] );
	}

	public function test_send_it_again_waits_a_minute_between_mails(): void {
		$key = $this->challenge();
		$this->assertCount( 1, self::$mail );

		$url = $this->submit( $key, '', true );

		$this->assertCount( 1, self::$mail, 'Asked straight away, nothing goes out' );
		$this->assertSame( '', $this->redirectState( $url ), 'And nothing is claimed to have gone out' );

		// A minute later.
		$pending         = (array) get_user_meta( $this->user, 'diluxone_users_2fa_pending', true );
		$pending['sent'] = time() - DILUXONE_USERS_2FA_RESEND_WAIT;
		update_user_meta( $this->user, 'diluxone_users_2fa_pending', $pending );

		$url = $this->submit( $key, '', true );

		$this->assertCount( 2, self::$mail );
		$this->assertSame( 'sent', $this->redirectState( $url ) );
	}

	public function test_a_wrong_key_is_an_expired_attempt(): void {
		$this->challenge();

		$this->assertSame( 'expired', $this->redirectState( $this->submit( 'not-the-key', '123456' ) ) );
	}

	/* ── M-03: the doors with no screen ────────────────────────────── */

	public function test_xml_rpc_refuses_a_password_that_would_need_a_second_step(): void {
		$user = get_userdata( $this->user );

		$this->assertInstanceOf( \WP_Error::class, diluxone_users_2fa_gate( $user, true ) );
		$this->assertSame( $user, diluxone_users_2fa_gate( $user, false ), 'A request with a screen goes on to the challenge' );
		$this->assertSame( 99, has_filter( 'authenticate', 'diluxone_users_2fa_gate_xmlrpc' ) );
	}

	public function test_xml_rpc_lets_through_whoever_is_not_asked(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$user = get_userdata( $this->user );

		$this->assertSame( $user, diluxone_users_2fa_gate( $user, true ) );
	}

	public function test_a_wrong_password_stays_a_wrong_password(): void {
		$error = new \WP_Error( 'incorrect_password' );

		$this->assertSame( $error, diluxone_users_2fa_gate( $error, true ) );
	}

	public function test_application_passwords_are_off_for_whoever_is_asked_for_a_second_step(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$this->assertFalse( wp_is_application_passwords_available_for_user( get_userdata( $this->user ) ) );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$this->assertTrue( wp_is_application_passwords_available_for_user( get_userdata( $this->user ) ) );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Two requests spending the same backup code: the second read the list
	 * before the first wrote it back. The write is conditional on what was
	 * read, so the second one fails and does not get in.
	 */
	public function test_a_backup_code_spent_by_two_requests_at_once_lets_one_in(): void {
		$codes = diluxone_users_backup_generate( $this->user );

		// Right after the code is checked, somebody else spends it.
		$race = function ( $check ) use ( $codes ) {
			static $done = false;

			if ( $check && ! $done ) {
				$done = true;
				update_user_meta( $this->user, 'diluxone_users_backup_codes', array() );
			}

			return $check;
		};
		add_filter( 'check_password', $race );

		$this->assertFalse( diluxone_users_backup_use( $this->user, $codes[0] ) );

		remove_filter( 'check_password', $race );
	}

	/** A sign-in link works once, even when two requests found it valid. */
	public function test_a_link_is_burned_by_one_request_only(): void {
		$token = diluxone_users_token_create( $this->user );

		$this->assertTrue( diluxone_users_token_valid( $this->user, $token ) );
		$this->assertTrue( diluxone_users_token_burn( $this->user ) );
		$this->assertFalse( diluxone_users_token_burn( $this->user ), 'The second request finds nothing to burn' );
	}

	/** The e-mailed code works once. */
	public function test_the_mailed_code_is_spent_once(): void {
		diluxone_users_2fa_email_send( $this->user );
		$code = $this->mailedCode();

		$this->assertTrue( diluxone_users_2fa_email_verify( $this->user, $code ) );
		$this->assertFalse( diluxone_users_2fa_email_verify( $this->user, $code ) );
	}
}
