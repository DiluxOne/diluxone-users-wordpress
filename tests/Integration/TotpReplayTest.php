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
}
