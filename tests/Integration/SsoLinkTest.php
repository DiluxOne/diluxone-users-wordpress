<?php
/**
 * H-01: an e-mail the provider did not vouch for opens nobody's account.
 *
 * Matching an existing account by e-mail hands that account over, so it
 * needs the provider's explicit yes — always. Creating a new account needs
 * only the absence of a no, unless the site asked for more.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class SsoLinkTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		MockProvider::install();

		// Social sign-in may create accounts here: the tests about what it
		// does with them start from a site that said yes.
		update_option( 'diluxone_users_sso_register', 1 );

		$this->sub = 'sub-' . wp_generate_password( 8, false );
	}

	protected function tearDown(): void {
		MockProvider::remove();
		parent::tearDown();
	}

	/** The identities a test uses are its own: users outlive the test that made them. */
	private string $sub;

	/** @return array<string, mixed> */
	private function identity( string $email, ?bool $verified, string $sub = '' ): array {
		return array(
			'id'        => '' === $sub ? $this->sub : $sub,
			'email'     => $email,
			'name'      => 'Ada',
			'last_name' => 'Lovelace',
			'verified'  => $verified,
		);
	}

	public function test_a_verified_email_links_the_existing_account(): void {
		$victim = $this->make_user();
		$email  = get_userdata( $victim )->user_email;

		$this->assertSame( $victim, diluxone_users_sso_user( MockProvider::ID, $this->identity( $email, true ) ) );
		$this->assertSame( $this->sub, get_user_meta( $victim, 'diluxone_users_sso_mock', true ) );
	}

	public function test_an_unverified_email_never_links_an_existing_account(): void {
		// Discord hands over the e-mail with verified=false. Anybody can put
		// somebody else's address on a Discord account.
		$victim = $this->make_user();
		$email  = get_userdata( $victim )->user_email;

		$this->assertSame( 0, diluxone_users_sso_user( MockProvider::ID, $this->identity( $email, false ) ) );
		$this->assertSame( '', get_user_meta( $victim, 'diluxone_users_sso_mock', true ), 'The network must not be linked' );
	}

	public function test_silence_from_the_provider_does_not_link_an_existing_account_either(): void {
		// Microsoft's userinfo says nothing about verification, and on a work
		// account the e-mail is whatever the tenant wrote. Silence is not a yes.
		$victim = $this->make_user();
		$email  = get_userdata( $victim )->user_email;

		$this->assertSame( 0, diluxone_users_sso_user( MockProvider::ID, $this->identity( $email, null ) ) );
		$this->assertSame( '', get_user_meta( $victim, 'diluxone_users_sso_mock', true ) );
	}

	public function test_an_already_linked_identity_gets_in_whatever_the_email_says(): void {
		// The link was made when the e-mail was verified; the identity is
		// now known by its id, not by its e-mail.
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );

		$this->assertSame( $user, diluxone_users_sso_user( MockProvider::ID, $this->identity( 'other@example.test', false ) ) );
	}

	public function test_a_new_account_is_created_when_the_provider_says_nothing_unless_the_site_asked_for_more(): void {
		$id = diluxone_users_sso_user( MockProvider::ID, $this->identity( 'new-one-' . $this->sub . '@example.test', null ) );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $this->sub, get_user_meta( $id, 'diluxone_users_sso_mock', true ) );

		update_option( 'diluxone_users_sso_verified_only', 1 );

		$this->assertSame( 0, diluxone_users_sso_user( MockProvider::ID, $this->identity( 'new-two-' . $this->sub . '@example.test', null, $this->sub . '-two' ) ) );
		$this->assertFalse( get_user_by( 'email', 'new-two-' . $this->sub . '@example.test' ) );
	}

	public function test_a_new_account_is_never_created_with_an_email_the_provider_called_unverified(): void {
		// It would be an account with somebody else's address: the day that
		// somebody asks for a sign-in link, the link opens an account with
		// the attacker's network on it.
		$this->assertSame( 0, diluxone_users_sso_user( MockProvider::ID, $this->identity( 'new-three-' . $this->sub . '@example.test', false, $this->sub . '-three' ) ) );
		$this->assertFalse( get_user_by( 'email', 'new-three-' . $this->sub . '@example.test' ) );
	}

	public function test_link_by_email_can_still_be_turned_off_altogether(): void {
		update_option( 'diluxone_users_sso_link_by_email', 0 );

		$victim = $this->make_user();
		$email  = get_userdata( $victim )->user_email;

		$this->assertSame( 0, diluxone_users_sso_user( MockProvider::ID, $this->identity( $email, true ) ) );
	}
}
