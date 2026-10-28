<?php
/**
 * H-04: the sign-in form creates accounts, and creating accounts is counted
 * per machine — the same count the registration form keeps.
 *
 * #134: asking creates none. An address nobody has gets the link of a
 * pending sign-up, and the account is created when that link is opened.
 *
 * Before, only the address was throttled, and a script with a new address
 * each time was never throttled at all: unlimited accounts, and a sign-in
 * link mailed from the site to every address it typed.
 */

namespace Tests\Integration;

class LoginRequestTest extends IntegrationTestCase {

	/** Addresses are the test's own: accounts outlive the test that made them. */
	private string $run;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_login_register', 1 );

		$this->run = wp_generate_password( 8, false );
	}

	private function email( string $name ): string {
		return $name . '-' . $this->run . '@example.test';
	}

	/** Asks for a link as this address, from this machine, and returns where it went. */
	private function ask( string $email, string $ip = '203.0.113.7' ): string {
		$_SERVER['REMOTE_ADDR'] = $ip;

		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $email,
			)
		);

		return $this->expectRedirect( 'diluxone_users_login_request' );
	}

	/**
	 * Opens the link of a pending sign-up from a mail, as its reader would.
	 *
	 * @param array<string, mixed> $mail
	 */
	private function open( array $mail ): string {
		$this->assertSame( 1, preg_match( '/diluxone_users_signup=([a-z0-9]+)(?:&amp;|&#038;|&)diluxone_users_token=([A-Za-z0-9]+)/', (string) ( $mail['message'] ?? '' ), $link ), 'The mail carries the sign-up link' );

		$_GET = array(
			'diluxone_users_signup' => $link[1],
			'diluxone_users_token'  => $link[2],
		);

		return $this->expectRedirect( 'diluxone_users_signup_consume' );
	}

	public function test_one_machine_creates_at_most_the_burst_of_accounts_and_the_answer_never_changes(): void {
		$burst = diluxone_users_register_burst();

		for ( $i = 1; $i <= $burst + 1; $i++ ) {
			$url = $this->ask( $this->email( "new-$i" ) );

			$this->assertSame( 'sent', $this->redirectState( $url ), 'The screen says the same thing whatever happened' );
		}

		$this->assertFalse( email_exists( $this->email( "new-$burst" ) ), 'Asking creates no account' );
		$this->assertCount( $burst, self::$mail, 'No link goes out for the one past the burst' );

		$this->open( $this->lastMail() );

		$this->assertGreaterThan( 0, email_exists( $this->email( "new-$burst" ) ), 'Opening the link creates it' );
		$this->assertFalse( email_exists( $this->email( 'new-' . ( $burst + 1 ) ) ), 'The one past the burst is not created' );
	}

	public function test_opening_the_link_creates_the_account_once_and_signs_it_in(): void {
		$email = $this->email( 'opens' );

		$this->ask( $email );

		$this->assertFalse( email_exists( $email ), 'Asking for the link creates no account' );
		$this->assertSame( $email, $this->lastMail()['to'] ?? null );

		$mail = $this->lastMail();
		$this->open( $mail );

		$id = (int) email_exists( $email );
		$this->assertGreaterThan( 0, $id, 'Opening the link creates the account' );
		$this->assertSame( $id, get_current_user_id(), 'And signs it in' );

		wp_set_current_user( 0 );

		$this->assertSame( 'expired', $this->redirectState( $this->open( $mail ) ), 'The link works once' );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_link_opened_after_the_door_closed_creates_nothing(): void {
		$email = $this->email( 'closed-later' );

		$this->ask( $email );
		diluxone_users_update_option( 'diluxone_users_login_register', 0 );

		$this->assertSame( 'expired', $this->redirectState( $this->open( $this->lastMail() ) ) );
		$this->assertFalse( email_exists( $email ) );
	}

	public function test_another_machine_has_its_own_count(): void {
		for ( $i = 1; $i <= diluxone_users_register_burst(); $i++ ) {
			$this->ask( $this->email( "first-$i" ), '203.0.113.7' );
		}

		$before = count( self::$mail );
		$this->ask( $this->email( 'elsewhere' ), '198.51.100.9' );

		$this->assertCount( $before + 1, self::$mail, 'The other machine still gets its link' );
	}

	public function test_an_existing_account_neither_spends_nor_needs_the_count(): void {
		$existing = get_userdata( $this->make_user() )->user_email;

		for ( $i = 1; $i <= diluxone_users_register_burst(); $i++ ) {
			$this->ask( $this->email( "spend-$i" ) );
		}

		$before = count( self::$mail );
		$this->ask( $existing );

		$this->assertCount( $before + 1, self::$mail, 'Whoever already has an account still gets their link' );
	}

	public function test_with_the_link_not_creating_accounts_nothing_is_created_and_nothing_is_counted(): void {
		diluxone_users_update_option( 'diluxone_users_login_register', 0 );

		$this->ask( $this->email( 'nobody' ) );

		$this->assertFalse( email_exists( $this->email( 'nobody' ) ) );
		$this->assertCount( 0, self::$mail );
		$this->assertTrue( diluxone_users_register_allowed(), 'The count was not spent on an account that could not be made' );
	}

	public function test_without_the_nonce_nothing_happens(): void {
		$this->postAs( 0, array( 'diluxone_users_email' => $this->email( 'x' ) ) );

		$this->assertSame( 'error', $this->redirectState( $this->expectRedirect( 'diluxone_users_login_request' ) ) );
		$this->assertFalse( email_exists( $this->email( 'x' ) ) );
		$this->assertCount( 0, self::$mail );
	}

	/**
	 * #228: a public name is not a way in. Typed in the box, it is not an
	 * address, so nothing is sent to the account it belongs to, and the
	 * answer is the one anything that is not an address gets.
	 */
	public function test_a_public_name_typed_sends_no_link(): void {
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );
		update_option( 'diluxone_users_handle_login', 1 );

		$user = $this->make_user();
		diluxone_users_handle_save( $user, 'juan-' . $this->run );

		$url = $this->ask( 'juan-' . $this->run );

		$this->assertSame( 'email', $this->redirectState( $url ) );
		$this->assertCount( 0, self::$mail, 'no link to the account the name belongs to' );
	}

	/**
	 * #228: a new account's profile address never answers to another
	 * account's login, which WordPress itself does not check.
	 */
	public function test_a_new_accounts_public_name_avoids_another_accounts_login(): void {
		$login = 'juan' . $this->run;
		wp_insert_user(
			array(
				'user_login'    => $login,
				'user_email'    => $login . '-classic@example.test',
				'user_pass'     => wp_generate_password(),
				'user_nicename' => 'classic-' . $login,
			)
		);

		$id = diluxone_users_create_account( $login . '@example.org' );

		$this->assertGreaterThan( 0, $id );
		$this->assertNotSame( $login, get_userdata( $id )->user_nicename );
		$this->assertFalse( diluxone_users_handle_taken( get_userdata( $id )->user_nicename, $id ), 'and no other account answers to it' );
	}

	/** The address is not in the URL either when it was typed as one. */
	public function test_the_address_does_not_travel_in_the_url(): void {
		$email = $this->email( 'private' );
		$url   = $this->ask( $email );

		$this->assertStringNotContainsString( 'email=', $url );
		$this->assertSame( $email, self::$cookies['diluxone_users_sent']['value'] ?? null );
	}
}
