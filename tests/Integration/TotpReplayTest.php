<?php
/**
 * A code from the app is spent once, and so is every code before it.
 *
 * The drift window accepts the code of the step before the current one, so a
 * guard that only remembers the last code typed lets the previous step's code
 * through after the current one was used: whoever read it over a shoulder had
 * another thirty seconds. What is remembered now is the step, and a code is
 * accepted only for a step later than the last one used.
 */

namespace Tests\Integration;

class TotpReplayTest extends IntegrationTestCase {

	private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->make_user();
		update_user_meta( $this->user, 'diluxone_users_totp', self::SECRET );
	}

	protected function tearDown(): void {
		delete_user_meta( $this->user, 'diluxone_users_totp' );
		delete_user_meta( $this->user, 'diluxone_users_totp_step' );

		parent::tearDown();
	}

	public function test_the_current_code_works_once(): void {
		$code = diluxone_users_totp_code( self::SECRET );

		$this->assertTrue( diluxone_users_totp_verify( $this->user, $code ) );
		$this->assertFalse( diluxone_users_totp_verify( $this->user, $code ) );
	}

	public function test_the_previous_step_is_refused_once_the_current_one_was_used(): void {
		$this->assertTrue( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET ) ) );

		$this->assertFalse( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET, time() - 30 ) ) );
	}

	public function test_a_later_step_still_works(): void {
		$this->assertTrue( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET, time() - 30 ) ) );

		$this->assertTrue( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET ) ) );
	}

	public function test_a_wrong_code_does_not_spend_anything(): void {
		$this->assertFalse( diluxone_users_totp_verify( $this->user, '000000' === diluxone_users_totp_code( self::SECRET ) ? '111111' : '000000' ) );

		$this->assertTrue( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET ) ) );
	}

	/**
	 * The code that turned the app on is spent: typed again straight away as
	 * a sign-in code — by somebody who watched it typed — it is refused.
	 */
	public function test_the_code_that_turned_the_app_on_is_spent(): void {
		delete_user_meta( $this->user, 'diluxone_users_totp' );
		$secret = diluxone_users_totp_pending( $this->user );
		$code   = diluxone_users_totp_code( $secret );

		$this->assertTrue( diluxone_users_totp_activate( $this->user, $code ) );
		$this->assertFalse( diluxone_users_totp_verify( $this->user, $code ) );
	}

	/** Removing the app forgets its secret and its last step; a new one is another secret. */
	public function test_removing_the_app_forgets_it(): void {
		$this->assertTrue( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET ) ) );

		diluxone_users_totp_forget( $this->user );

		$this->assertFalse( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( self::SECRET ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_totp_step', true ) );
		$this->assertNotSame( self::SECRET, diluxone_users_totp_pending( $this->user ) );
		delete_user_meta( $this->user, 'diluxone_users_totp_pending' );
	}
}
