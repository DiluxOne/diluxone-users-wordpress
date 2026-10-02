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
		$this->hook( 'wp_is_application_passwords_available', '__return_true' );

		$this->assertFalse( wp_is_application_passwords_available_for_user( get_userdata( $this->user ) ) );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$this->assertTrue( wp_is_application_passwords_available_for_user( get_userdata( $this->user ) ) );
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
		$this->hook( 'check_password', $race );

		$this->assertFalse( diluxone_users_backup_use( $this->user, $codes[0] ) );

		// The control: with nobody racing it, a code of a fresh set gets in.
		remove_filter( 'check_password', $race );
		$fresh = diluxone_users_backup_generate( $this->user );
		$this->assertTrue( diluxone_users_backup_use( $this->user, $fresh[0] ) );
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

	/**
	 * "Send it again" on the app sends nothing — the app has nothing to send —
	 * and so does not say it sent something. "Sent" sent the person to an
	 * inbox with nothing new in it.
	 */
	public function test_sending_the_app_again_says_nothing_was_sent(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp', 'email' ) );
		update_user_meta( $this->user, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );

		$key = $this->challenge();
		$this->assertSame( array(), self::$mail, 'the app goes first, and nothing is mailed' );
		$this->assertSame( 0, (int) diluxone_users_meta_list( $this->user, 'diluxone_users_2fa_pending' )['sent'] );

		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $this->user,
				'diluxone_users_2fa_key'    => $key,
				'diluxone_users_2fa_method' => 'totp',
				'diluxone_users_2fa_resend' => '1',
			)
		);
		$url = $this->expectRedirect( 'diluxone_users_2fa_handle' );

		$this->assertSame( array(), self::$mail );
		$this->assertNotSame( 'sent', $this->redirectState( $url ) );
		$this->assertSame( 'totp', $this->queryArg( $url, 'diluxone_users_method' ), 'back on the app' );
	}

	/* ── What no other test asked ─────────────────────────────────── */

	/** The right key and the right code, after the attempt ran out: no. */
	public function test_the_right_code_after_the_attempt_ran_out_is_refused(): void {
		$key  = $this->challenge();
		$code = $this->mailedCode();

		$pending            = diluxone_users_meta_list( $this->user, 'diluxone_users_2fa_pending' );
		$pending['expires'] = time() - 1;
		update_user_meta( $this->user, 'diluxone_users_2fa_pending', $pending );

		$this->assertSame( 'expired', $this->redirectState( $this->submit( $key, $code ) ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, self::$cookies );
	}

	/** One person's attempt is no key to another account. */
	public function test_an_attempt_opens_no_other_account(): void {
		$key   = $this->challenge();
		$code  = $this->mailedCode();
		$other = $this->make_user();

		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $other,
				'diluxone_users_2fa_key'    => $key,
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => $code,
			)
		);

		$this->assertSame( diluxone_users_2fa_restart_url(), $this->expectRedirect( 'diluxone_users_2fa_handle' ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, (int) diluxone_users_meta_list( $this->user, 'diluxone_users_2fa_pending' )['tries'], 'the real attempt took no strike' );
	}

	/** A method the site turned off answers nothing, even with its right code. */
	public function test_a_method_turned_off_cannot_answer_even_with_its_code(): void {
		update_user_meta( $this->user, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp' ) );
		update_user_meta( $this->user, 'diluxone_users_2fa_email', array( 'hash' => wp_hash( '123456' ), 'expires' => time() + 600 ) );

		$this->assertFalse( diluxone_users_2fa_verify( $this->user, 'email', '123456' ) );
		$this->assertSame( 1, (int) get_user_meta( $this->user, 'diluxone_users_2fa_fails', true ), 'counted' );
		$this->assertNotSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_email', true ), 'and the e-mailed code not spent' );
	}

	/** A password sign-in's way back cannot leave the site through the second step. */
	public function test_the_way_back_of_a_password_sign_in_stays_on_the_site(): void {
		$this->postAs( 0, array( 'redirect_to' => 'https://evil.example/x' ) );

		$url = $this->expectRedirect( fn() => diluxone_users_2fa_after_password( get_userdata( $this->user )->user_login, get_userdata( $this->user ) ) );
		$key = $this->queryArg( $url, 'diluxone_users_key' );

		$final = $this->submit( $key, $this->mailedCode() );

		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $final, PHP_URL_HOST ), 'back on the site, not on evil.example' );
	}

	/** Sending again replaces the code: the first one mailed stops working. */
	public function test_sending_again_replaces_the_code(): void {
		diluxone_users_2fa_email_send( $this->user );
		preg_match( '/\b(\d{6})\b/', (string) $this->lastMail()['message'], $first );
		diluxone_users_2fa_email_send( $this->user );
		$second = $this->mailedCode();

		if ( $first[1] !== $second ) {
			$this->assertFalse( diluxone_users_2fa_email_verify( $this->user, $first[1] ) );
		}

		$this->assertTrue( diluxone_users_2fa_email_verify( $this->user, substr( $second, 0, 3 ) . '-' . substr( $second, 3 ) ), 'and is read the way people type it' );
	}

	/**
	 * "Remember me" survives the second step: the session the code opens
	 * lasts as long as the one the password would have.
	 *
	 * @return array<int, int> The expiry of the auth cookie each run set.
	 */
	public function test_remember_me_survives_the_second_step(): void {
		$expiries = array();
		$this->hook(
			'set_logged_in_cookie',
			static function ( $cookie, $expire ) use ( &$expiries ): void {
				$expiries[] = (int) $expire;
			},
			10,
			2
		);

		foreach ( array( true, false ) as $remember ) {
			wp_set_current_user( 0 );
			$url = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, 'password', $remember, home_url( '/after/' ) ) );
			$this->submit( $this->queryArg( $url, 'diluxone_users_key' ), $this->mailedCode() );
		}

		$this->assertGreaterThan( 0, $expiries[0], 'remembered: a cookie with a date' );
		$this->assertSame( 0, $expiries[1], 'not remembered: a cookie for the browser session' );
	}

	/** No "trust this browser" cookie unless the box was ticked. */
	public function test_no_trust_cookie_unless_the_box_was_ticked(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 14 );

		$this->submit( $this->challenge(), $this->mailedCode() );

		$this->assertSame( array(), preg_grep( '/^diluxone_users_2fa_/', array_keys( self::$cookies ) ) );
	}
}
