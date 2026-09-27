<?php
/**
 * M-01 and M-02: what a session alone cannot do to the second step.
 *
 * Turning it off, removing the app or replacing the backup codes asks for a
 * current code; and the browsers trusted to skip the challenge stop being
 * trusted the moment the second step changes or the other sessions close.
 */

namespace Tests\Integration;

class AccountSecurityTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		update_option( 'diluxone_users_2fa_mode', 'optional' );
		update_option( 'diluxone_users_2fa_methods', array( 'totp', 'email' ) );

		$this->user = $this->make_user();

		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
		update_user_meta( $this->user, 'diluxone_users_totp', diluxone_users_totp_secret_new() );
	}

	/** Submits the security form as the person and returns where it went. */
	private function submit( string $action, string $code = '' ): string {
		// The nonce belongs to the person: they are signed in before it is made.
		wp_set_current_user( $this->user );

		$this->postAs(
			$this->user,
			array(
				'_wpnonce'                => wp_create_nonce( 'diluxone_users_security' ),
				'diluxone_users_security' => $action,
				'diluxone_users_code'     => $code,
			)
		);

		return $this->expectRedirect( 'diluxone_users_security_submit' );
	}

	private function totp(): string {
		return diluxone_users_totp_code( diluxone_users_totp_secret( $this->user ), time() );
	}

	public function test_turning_it_off_with_a_session_alone_does_nothing(): void {
		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'off' ) ) );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );

		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'off', '000000' ) ) );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	public function test_turning_it_off_with_the_code_from_the_app_works(): void {
		$this->assertSame( 'off', $this->redirectState( $this->submit( 'off', $this->totp() ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	public function test_a_backup_code_counts_as_the_second_step_too(): void {
		$codes = diluxone_users_backup_generate( $this->user, 2 );

		$this->assertSame( 'totpoff', $this->redirectState( $this->submit( 'totp_off', $codes[0] ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_totp', true ) );
		$this->assertSame( 1, diluxone_users_backup_left( $this->user ), 'And it was spent' );
	}

	public function test_the_emailed_code_works_for_whoever_has_no_app(): void {
		delete_user_meta( $this->user, 'diluxone_users_totp' );

		$this->assertSame( 'codesent', $this->redirectState( $this->submit( 'code' ) ) );
		preg_match( '/\b(\d{6})\b/', (string) $this->lastMail()['message'], $m );

		$this->assertSame( 'backup', $this->redirectState( $this->submit( 'backup', $m[1] ) ) );
	}

	public function test_new_backup_codes_need_a_code_as_well(): void {
		$before = diluxone_users_backup_generate( $this->user, 3 );

		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'backup' ) ) );
		$this->assertTrue( diluxone_users_backup_use( $this->user, $before[0] ), 'The old codes are still the codes' );
	}

	public function test_where_the_site_requires_it_the_answer_is_required_not_reauth(): void {
		update_option( 'diluxone_users_2fa_mode', 'required' );

		$this->assertSame( 'required', $this->redirectState( $this->submit( 'off' ) ) );
	}

	/* ── M-02: the trusted browser ───────────────────────────────────── */

	/** Marks this browser trusted and puts the cookie where the check reads it. */
	private function trust(): void {
		update_option( 'diluxone_users_2fa_remember_days', 30 );

		diluxone_users_2fa_trust( $this->user );

		$name              = 'diluxone_users_2fa_' . COOKIEHASH;
		$_COOKIE[ $name ] = self::$cookies[ $name ]['value'];
	}

	public function test_a_trusted_browser_is_trusted_and_the_cookie_is_same_site(): void {
		$this->trust();

		$this->assertTrue( diluxone_users_2fa_trusted( $this->user ) );
		$this->assertSame( 'Lax', self::$cookies[ 'diluxone_users_2fa_' . COOKIEHASH ]['options']['samesite'] );
		$this->assertTrue( self::$cookies[ 'diluxone_users_2fa_' . COOKIEHASH ]['options']['httponly'] );
	}

	public function test_the_cookie_cannot_be_moved_to_another_account(): void {
		$this->trust();

		$this->assertFalse( diluxone_users_2fa_trusted( $this->make_user() ) );
	}

	public function test_turning_the_second_step_off_forgets_the_trusted_browsers(): void {
		$this->trust();
		$this->submit( 'off', $this->totp() );

		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ) );
	}

	public function test_removing_the_app_forgets_them_too(): void {
		$this->trust();
		$this->submit( 'totp_off', $this->totp() );

		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ) );
	}

	public function test_closing_the_other_sessions_forgets_them(): void {
		// Whoever closes their other sessions lost a device; that device must
		// not walk back in with the password alone.
		$this->trust();
		diluxone_users_sessions_close_others( $this->user );

		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ) );
	}

	/**
	 * The account offers the second step to exactly the people the sign-in
	 * asks it of: "everybody" means everybody, even with roles still ticked
	 * from when it was "only some".
	 */
	public function test_the_offer_follows_the_scope_the_sign_in_follows(): void {
		update_option( 'diluxone_users_2fa_mode', 'optional' );
		update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		update_option( 'diluxone_users_2fa_roles', array( 'administrator' ) );
		update_option( 'diluxone_users_2fa_scope', 'all' );

		$this->assertTrue( diluxone_users_2fa_offered( $this->make_user( 'subscriber' ) ) );

		update_option( 'diluxone_users_2fa_scope', 'some' );

		$this->assertFalse( diluxone_users_2fa_offered( $this->make_user( 'subscriber' ) ) );
	}
}
