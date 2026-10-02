<?php
/**
 * The minute between two links to one inbox counts the inbox, not its spelling.
 *
 * An address is the same inbox in capitals: WordPress finds the account
 * either way, and the link goes to the same place. The wait was kept under
 * the address as typed, so `Ana@…` and `ana@…` were two inboxes to it and the
 * same person was mailed twice in a second — and a script could go through
 * the capitals of one address to write to it again and again.
 */

namespace Tests\Integration;

class LoginThrottleCaseTest extends IntegrationTestCase {

	/** Asks for a link as this address and returns where it went. */
	private function ask( string $email ): string {
		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $email,
			)
		);

		return $this->expectRedirect( 'diluxone_users_login_request' );
	}

	public function test_the_same_address_in_capitals_waits_its_minute_like_the_first(): void {
		$email = get_userdata( $this->make_user() )->user_email;

		$this->assertSame( 'sent', $this->redirectState( $this->ask( $email ) ) );
		$this->assertCount( 1, self::$mail );

		$this->assertSame( 'sent', $this->redirectState( $this->ask( strtoupper( $email ) ) ), 'the answer is the same, whatever happened' );
		$this->assertCount( 1, self::$mail, 'and no second link goes to the same inbox within the minute' );
	}
}
