<?php
/**
 * What a social network says about the e-mail it hands over (H-01).
 *
 * The mappers are where the provider's answer becomes one of three: yes,
 * no, nothing. And diluxone_users_sso_email_trusted() is where those three
 * become "may this e-mail open an existing account" — never on a no, never
 * on silence — and "may it start a new one". The mappers of the networks
 * Users+ Pro brings are tested there.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
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

	/* ── What the three answers are allowed to do ──────────────────── */

	public function test_only_a_yes_may_open_an_existing_account(): void {
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => false ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array(), true ), 'A mapper that forgot the key is a mapper that said nothing' );
	}

	public function test_a_new_account_needs_only_the_absence_of_a_no(): void {
		// "Only verified addresses" off, saved that way.
		update_option( 'diluxone_users_sso_verified_only', 0 );

		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), false ) );
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => null ), false ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => false ), false ) );
	}

	public function test_the_site_can_ask_for_a_yes_on_new_accounts_too(): void {
		update_option( 'diluxone_users_sso_verified_only', 1 );

		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), false ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null ), false ) );
	}

	public function test_with_verified_only_off_nothing_changes(): void {
		update_option( 'diluxone_users_sso_verified_only', 0 );

		// Exactly the answers of before: a yes opens both, silence only a new
		// account, a no neither.
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => false ), true ) );
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => true ), false ) );
		$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => null ), false ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => false ), false ) );
	}

	public function test_an_address_proved_by_mail_is_trusted_whatever_the_provider_said(): void {
		update_option( 'diluxone_users_sso_verified_only', 1 );

		foreach ( array( true, null, false ) as $verified ) {
			$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => $verified, 'proved' => true ), true ) );
			$this->assertTrue( diluxone_users_sso_email_trusted( array( 'verified' => $verified, 'proved' => true ), false ) );
		}
	}

	public function test_only_a_real_true_counts_as_proved(): void {
		update_option( 'diluxone_users_sso_verified_only', 1 );

		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null, 'proved' => 1 ), true ) );
		$this->assertFalse( diluxone_users_sso_email_trusted( array( 'verified' => null, 'proved' => 'yes' ), false ) );
	}

	public function test_what_a_provider_sends_never_carries_the_proof(): void {
		Monkey\Functions\stubs( array( 'sanitize_email' ) );

		$clean = diluxone_users_sso_clean( array( 'id' => '1', 'email' => 'a@example.test', 'proved' => true ) );

		$this->assertArrayNotHasKey( 'proved', $clean );
	}
}
