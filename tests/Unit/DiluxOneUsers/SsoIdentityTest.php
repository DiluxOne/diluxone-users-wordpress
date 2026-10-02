<?php
/**
 * What each social network says about the e-mail it hands over (H-01).
 *
 * The mappers are where the provider's answer becomes one of three: yes,
 * no, nothing. And diluxone_users_sso_email_trusted() is where those three
 * become "may this e-mail open an existing account" — never on a no, never
 * on silence — and "may it start a new one".
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SsoIdentityTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		// The engine and its provider table together: once the engine is in the
		// process, any test that asks which networks are on needs the table too.
		require_once DILUXONE_USERS_DIR . 'includes/sso-providers.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso.php';

		$GLOBALS['_test_wp_options'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** GitHub's second endpoint answers with this list of e-mails. */
	private function githubEmails( array $rows ): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => json_encode( $rows ) ) );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ): string => (string) $r['body'] );
	}

	public function test_google_says_yes(): void {
		$identity = diluxone_users_sso_map_oidc( array( 'sub' => '1', 'email' => 'a@example.test', 'email_verified' => true ), 't' );

		$this->assertTrue( $identity['verified'] );
	}

	public function test_a_provider_that_sends_the_word_is_read_the_same(): void {
		$this->assertTrue( diluxone_users_sso_map_oidc( array( 'email_verified' => 'true' ), 't' )['verified'] );
		$this->assertFalse( diluxone_users_sso_map_oidc( array( 'email_verified' => 'false' ), 't' )['verified'] );
	}

	public function test_microsoft_says_nothing_and_nothing_is_what_is_recorded(): void {
		// Its userinfo has no email_verified claim. Silence must not become a yes.
		$identity = diluxone_users_sso_map_oidc( array( 'sub' => '1', 'email' => 'a@example.test' ), 't' );

		$this->assertNull( $identity['verified'] );
	}

	public function test_discord_says_no_when_the_person_never_confirmed(): void {
		$identity = diluxone_users_sso_map_discord( array( 'id' => '1', 'email' => 'a@example.test', 'verified' => false ), 't' );

		$this->assertFalse( $identity['verified'] );
	}

	public function test_github_yes_comes_only_from_the_verified_primary_email(): void {
		$this->githubEmails( array(
			array( 'email' => 'public@example.test', 'primary' => false, 'verified' => false ),
			array( 'email' => 'real@example.test', 'primary' => true, 'verified' => true ),
		) );

		$identity = diluxone_users_sso_map_github( array( 'id' => '1', 'email' => 'public@example.test' ), 't' );

		$this->assertSame( 'real@example.test', $identity['email'] );
		$this->assertTrue( $identity['verified'] );
	}

	public function test_github_public_email_alone_vouches_for_nothing(): void {
		// The profile's e-mail is the public one, which anybody can type in.
		$this->githubEmails( array() );

		$identity = diluxone_users_sso_map_github( array( 'id' => '1', 'email' => 'public@example.test' ), 't' );

		$this->assertSame( 'public@example.test', $identity['email'] );
		$this->assertNull( $identity['verified'] );
	}

	public function test_facebook_only_hands_over_confirmed_emails(): void {
		$this->assertTrue( diluxone_users_sso_map_facebook( array( 'id' => '1', 'email' => 'a@example.test' ), 't' )['verified'] );
		$this->assertNull( diluxone_users_sso_map_facebook( array( 'id' => '1' ), 't' )['verified'] );
	}

	public function test_x_hands_over_no_email_at_all(): void {
		$identity = diluxone_users_sso_map_twitter( array( 'data' => array( 'id' => '1', 'name' => 'Ada' ) ), 't' );

		$this->assertSame( '', $identity['email'] );
		$this->assertNull( $identity['verified'] );
	}

	/* ── What the three answers are allowed to do ──────────────────── */

	public function test_only_a_yes_may_open_an_existing_account(): void {
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => false ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array(), true ), 'A mapper that forgot the key is a mapper that said nothing' );
	}

	public function test_a_new_account_needs_only_the_absence_of_a_no(): void {
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), false ) );
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => null ), false ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => false ), false ) );
	}

	public function test_the_site_can_ask_for_a_yes_on_new_accounts_too(): void {
		update_option( 'diluxone_users_sso_verified_only', 1 );

		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), false ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null ), false ) );
	}
}
