<?php
/**
 * Whether a provider's trip uses PKCE.
 *
 * diluxone_users_sso_pkce() decides it from the provider's row and from what
 * "Use PKCE" stored for it: a provider that requires it always, one that does
 * not accept it never, and the rest what the box says, on when it says
 * nothing.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class SsoPkceTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso-providers.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @param array<string, mixed> $row */
	private function stored( string $id, array $row ): void {
		$GLOBALS['_test_wp_options']['diluxone_users_sso'] = array( $id => $row );
	}

	public function test_a_provider_that_supports_it_and_has_nothing_saved_uses_it(): void {
		$row = diluxone_users_sso_provider_row( 'acme', array( 'pkce_support' => true ) );

		$this->assertTrue( diluxone_users_sso_pkce( 'acme', $row ) );
	}

	public function test_google_and_microsoft_support_it_and_a_row_that_says_nothing_does_not(): void {
		$this->assertTrue( diluxone_users_sso_provider_row( 'google', array( 'pkce_support' => true ) )['pkce_support'] );
		$this->assertFalse( diluxone_users_sso_provider_row( 'acme', array() )['pkce_support'] );
		$this->assertFalse( diluxone_users_sso_pkce( 'acme', diluxone_users_sso_provider_row( 'acme', array() ) ) );
	}

	public function test_the_box_unticked_turns_it_off_and_ticked_on(): void {
		$row = diluxone_users_sso_provider_row( 'acme', array( 'pkce_support' => true ) );

		$this->stored( 'acme', array( 'pkce' => 0 ) );
		$this->assertFalse( diluxone_users_sso_pkce( 'acme', $row ) );

		$this->stored( 'acme', array( 'pkce' => 1 ) );
		$this->assertTrue( diluxone_users_sso_pkce( 'acme', $row ) );
	}
}
