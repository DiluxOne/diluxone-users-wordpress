<?php
/**
 * The second step's limits and its trusted-browser cookie (H-03, M-02, M-03).
 *
 * The facts underneath the challenge screen: how many wrong codes one
 * attempt survives, how often a code can be sent again, and what the cookie
 * that skips the challenge is signed with. Each is a function over the
 * pending meta, so each is checked here without a redirect in sight.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class TwoFactorChallengeTest extends TestCase {

	private const USER_ID = 11;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'COOKIEHASH' ) ) {
			define( 'COOKIEHASH', 'testhash' );
		}

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth-totp.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth-email.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';

		$GLOBALS['diluxone_users_test_user_meta'] = array();
		$GLOBALS['_test_wp_options']              = array();
		$GLOBALS['_test_wp_users']                = array( self::USER_ID => new \WP_User( self::USER_ID, array( 'subscriber' ) ) );
		$_COOKIE                                  = array();

		update_option( 'diluxone_users_2fa_mode', 'required' );
		update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		update_option( 'diluxone_users_2fa_remember_days', 30 );

	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function pending(): array {
		$meta = get_user_meta( self::USER_ID, 'diluxone_users_2fa_pending', true );

		return is_array( $meta ) ? $meta : array();
	}

	public function test_an_attempt_starts_with_no_tries_spent(): void {
		$nonce = diluxone_users_2fa_pending_start( self::USER_ID, 'password', true, 'https://example.test/' );

		$this->assertNotSame( array(), diluxone_users_2fa_pending( self::USER_ID, $nonce ) );
		$this->assertSame( 0, $this->pending()['tries'] );
		$this->assertNotSame( $nonce, $this->pending()['nonce'], 'The nonce is stored hashed, like every other credential here' );
	}

	public function test_each_wrong_code_costs_a_try_and_the_last_one_costs_the_attempt(): void {
		$nonce = diluxone_users_2fa_pending_start( self::USER_ID, 'password', true, '' );

		for ( $i = 1; $i < DILUXONE_USERS_2FA_TRIES; $i++ ) {
			$this->assertTrue( diluxone_users_2fa_strike( self::USER_ID, $nonce ), "Strike $i leaves the attempt alive" );
			$this->assertSame( $i, $this->pending()['tries'] );
		}

		$this->assertFalse( diluxone_users_2fa_strike( self::USER_ID, $nonce ) );
		$this->assertSame( array(), $this->pending(), 'The attempt is thrown away whole' );
		$this->assertSame( array(), diluxone_users_2fa_pending( self::USER_ID, $nonce ) );
	}

	public function test_a_strike_with_the_wrong_nonce_counts_nothing(): void {
		// Somebody else's guesses must not spend the person's tries.
		diluxone_users_2fa_pending_start( self::USER_ID, 'password', true, '' );

		$this->assertFalse( diluxone_users_2fa_strike( self::USER_ID, 'not-the-nonce' ) );
		$this->assertSame( 0, $this->pending()['tries'] );
	}

	public function test_a_fresh_attempt_starts_the_count_over(): void {
		$first = diluxone_users_2fa_pending_start( self::USER_ID, 'password', true, '' );
		diluxone_users_2fa_strike( self::USER_ID, $first );

		$second = diluxone_users_2fa_pending_start( self::USER_ID, 'password', true, '' );

		$this->assertSame( 0, $this->pending()['tries'] );
		$this->assertSame( array(), diluxone_users_2fa_pending( self::USER_ID, $first ), 'And the old nonce is dead' );
		$this->assertNotSame( array(), diluxone_users_2fa_pending( self::USER_ID, $second ) );
	}

	public function test_a_code_can_be_sent_again_only_after_the_wait(): void {
		$nonce = diluxone_users_2fa_pending_start( self::USER_ID, 'password', true, '' );

		$pending         = $this->pending();
		$pending['sent'] = time();
		update_user_meta( self::USER_ID, 'diluxone_users_2fa_pending', $pending );

		$this->assertFalse( diluxone_users_2fa_resend_allowed( self::USER_ID, $nonce ) );

		$pending['sent'] = time() - DILUXONE_USERS_2FA_RESEND_WAIT;
		update_user_meta( self::USER_ID, 'diluxone_users_2fa_pending', $pending );

		$this->assertTrue( diluxone_users_2fa_resend_allowed( self::USER_ID, $nonce ) );
	}

	public function test_nothing_is_sent_again_for_an_attempt_that_does_not_exist(): void {
		$this->assertFalse( diluxone_users_2fa_resend_allowed( self::USER_ID, 'anything' ) );
	}

	/* ── The trusted browser ───────────────────────────────────────── */

	/**
	 * The value the plugin would put in the cookie.
	 *
	 * The cookie itself — its flags, that it is set at all — is checked in the
	 * integration suite, where the real apply_filters() lets a test take it.
	 * Here the stubs' apply_filters() is a pass-through, so what is checked
	 * is the signed value, which is what the trust rests on.
	 */
	private function trust(): string {
		return diluxone_users_2fa_trust_value( self::USER_ID, time() + 30 * DAY_IN_SECONDS );
	}

	public function test_the_cookie_it_sets_is_the_cookie_it_trusts(): void {
		$_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] = $this->trust();

		$this->assertTrue( diluxone_users_2fa_trusted( self::USER_ID ) );
	}

	public function test_the_cookie_is_signed_and_a_changed_expiry_breaks_it(): void {
		[ $id, $expires, $hash ] = explode( '|', $this->trust() );

		$_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] = $id . '|' . ( (int) $expires + 86400 ) . '|' . $hash;

		$this->assertFalse( diluxone_users_2fa_trusted( self::USER_ID ) );
	}

	public function test_forgetting_the_browsers_breaks_every_cookie_signed_before(): void {
		$_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] = $this->trust();
		$this->assertTrue( diluxone_users_2fa_trusted( self::USER_ID ) );

		diluxone_users_2fa_forget_browsers( self::USER_ID );

		$this->assertFalse( diluxone_users_2fa_trusted( self::USER_ID ) );
	}

	public function test_a_cookie_made_after_forgetting_is_trusted_again(): void {
		diluxone_users_2fa_forget_browsers( self::USER_ID );

		$_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] = $this->trust();

		$this->assertTrue( diluxone_users_2fa_trusted( self::USER_ID ) );
	}

	/* ── The doors with no screen ──────────────────────────────────── */

	public function test_a_password_alone_is_refused_where_no_code_can_be_asked(): void {
		$user = $GLOBALS['_test_wp_users'][ self::USER_ID ];

		$this->assertInstanceOf( \WP_Error::class, diluxone_users_2fa_gate( $user, true ) );
		$this->assertSame( $user, diluxone_users_2fa_gate( $user, false ) );
	}

	public function test_whoever_is_not_asked_for_a_code_is_let_through(): void {
		update_option( 'diluxone_users_2fa_mode', 'off' );

		$user = $GLOBALS['_test_wp_users'][ self::USER_ID ];

		$this->assertSame( $user, diluxone_users_2fa_gate( $user, true ) );
	}

	public function test_a_wrong_password_is_passed_on_as_it_came(): void {
		$error = new \WP_Error( 'incorrect_password' );

		$this->assertSame( $error, diluxone_users_2fa_gate( $error, true ) );
		$this->assertNull( diluxone_users_2fa_gate( null, true ) );
	}
}
