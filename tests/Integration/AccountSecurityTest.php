<?php
/**
 * M-01 and M-02: what a session alone cannot do to the second step.
 *
 * Turning it off or replacing the backup codes asks for a current code; and
 * whatever an add-on remembered about the second step is forgotten the moment
 * it changes or the other sessions close. The authenticator app's half of
 * these, and the remembered browser's, are Users+ Pro's tests now.
 */

namespace Tests\Integration;

class AccountSecurityTest extends IntegrationTestCase {

	private int $user;

	/** @var array<int, int> Whose remembered browsers were forgotten, in order. */
	private array $forgotten = array();

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$this->user = $this->make_user();

		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );

		$this->forgotten = array();
		$this->hook(
			'diluxone_users_2fa_forget',
			function ( int $user_id ): void {
				$this->forgotten[] = $user_id;
			}
		);
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

	/** Asks for a code by e-mail, as the button does, and reads it out of the mail. */
	private function emailed(): string {
		$this->submit( 'code' );
		preg_match( '/\b(\d{6})\b/', (string) $this->lastMail()['message'], $m );

		return $m[1];
	}

	public function test_turning_it_off_with_a_session_alone_does_nothing(): void {
		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'off' ) ) );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );

		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'off', '000000' ) ) );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertSame( array(), $this->forgotten, 'nothing forgotten either' );
	}

	public function test_turning_it_off_with_an_emailed_code_works(): void {
		$this->assertSame( 'off', $this->redirectState( $this->submit( 'off', $this->emailed() ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	public function test_a_backup_code_counts_as_the_second_step_too(): void {
		$codes = diluxone_users_backup_generate( $this->user, 2 );

		$this->assertSame( 'off', $this->redirectState( $this->submit( 'off', $codes[0] ) ) );
		$this->assertSame( 1, diluxone_users_backup_left( $this->user ), 'And it was spent' );
	}

	public function test_new_backup_codes_need_a_code_as_well(): void {
		$before = diluxone_users_backup_generate( $this->user, 3 );

		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'backup' ) ) );
		$this->assertTrue( diluxone_users_backup_use( $this->user, $before[0] ), 'The old codes are still the codes' );
	}

	public function test_where_the_site_requires_it_the_answer_is_required_not_reauth(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );

		$this->assertSame( 'required', $this->redirectState( $this->submit( 'off' ) ) );
	}

	/* ── M-02: what was remembered ───────────────────────────────────── */

	public function test_turning_the_second_step_off_forgets_what_was_remembered(): void {
		$this->submit( 'off', $this->emailed() );

		$this->assertSame( array( $this->user ), $this->forgotten );
	}

	public function test_closing_the_other_sessions_forgets_it_too(): void {
		// Whoever closes their other sessions lost a device; that device must
		// not walk back in with the password alone.
		diluxone_users_sessions_close_others( $this->user );

		$this->assertSame( array( $this->user ), $this->forgotten );
	}

	/**
	 * The account offers the second step to exactly the people the sign-in
	 * asks it of. Roles chosen while Users+ Pro was on are not read without
	 * it: everybody is offered it, as everybody is asked.
	 */
	public function test_the_offer_follows_the_scope_the_sign_in_follows(): void {
		update_option( 'diluxone_users_2fa_scope', 'some' );
		update_site_option( 'diluxone_users_2fa_scope', 'some' );

		try {
			$this->assertTrue( diluxone_users_2fa_offered( $this->make_user( 'subscriber' ) ) );

			$this->hook( 'diluxone_users_2fa_in_scope', '__return_false' );

			$this->assertFalse( diluxone_users_2fa_offered( $this->make_user( 'subscriber' ) ), 'an add-on that narrows it narrows the offer' );
		} finally {
			delete_option( 'diluxone_users_2fa_scope' );
			delete_site_option( 'diluxone_users_2fa_scope' );
		}
	}

	/* ── What no other test asked ─────────────────────────────────── */

	/** New backup codes with a good code replace the old set, and are shown once. */
	public function test_new_backup_codes_replace_the_old_set(): void {
		$before = diluxone_users_backup_generate( $this->user, 3 );

		$this->assertSame( 'backup', $this->redirectState( $this->submit( 'backup', $this->emailed() ) ) );

		$this->assertFalse( diluxone_users_backup_use( $this->user, $before[1] ), 'the old ones are gone' );
		$this->assertGreaterThan( 3, diluxone_users_backup_left( $this->user ), 'a full new set' );
		$this->assertNotSame( array(), diluxone_users_backup_fresh( $this->user ) );
	}

	/** An e-mailed code is spent by the first action it confirms, whichever. */
	public function test_an_emailed_code_is_spent_by_the_first_action(): void {
		$code = $this->emailed();

		$this->assertSame( 'backup', $this->redirectState( $this->submit( 'backup', $code ) ) );
		$this->assertSame( 'reauth', $this->redirectState( $this->submit( 'off', $code ) ), 'the same code again' );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	/** "Send me a code" where e-mail is no method sends nothing, and says what it always says. */
	public function test_asking_for_a_code_where_e_mail_is_no_method_sends_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array() );

		$this->assertSame( 'codesent', $this->redirectState( $this->submit( 'code' ) ) );
		$this->assertSame( array(), self::$mail );
	}

	/** Turning the step off is told to the person — unless the site said never to. */
	public function test_turning_it_off_is_told_unless_the_site_says_never(): void {
		$this->submit( 'off', $this->emailed() );
		$this->assertStringContainsString( 'Two-step verification was turned off.', (string) $this->lastMail()['message'] );

		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_update_option( 'diluxone_users_notice_rules', array( 'diluxone_users_notify_security' => 'never' ) );
		$code       = $this->emailed();
		self::$mail = array();

		$this->submit( 'off', $code );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertSame( array(), self::$mail );
	}
}
