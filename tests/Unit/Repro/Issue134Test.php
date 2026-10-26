<?php
/**
 * Issue #134: the e-mail link creates the account before the address is proven.
 *
 * With registration by the e-mail link on, asking for a link with an address
 * that has no account must create no user: the account is created when the
 * link is opened, since opening it is what proves the address is theirs.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue134Test extends TestCase {

	use ResetsWpStubs;

	/** @var int How many times WordPress was asked to insert a user. */
	private int $inserted = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/multisite.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';

		$this->inserted = 0;

		// The e-mail link is a way in and it registers people; nobody has the address.
		update_option( 'diluxone_users_login_method', 'link' );
		update_option( 'diluxone_users_login_register', 1 );

		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'sanitize_title' )->alias(
			static fn( string $title ): string => trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $title ) ), '-' )
		);
		// Refusing keeps the rest of the account's creation (joining sites) out
		// of the test; what matters is whether WordPress was asked at all.
		Functions\when( 'wp_insert_user' )->alias(
			function () {
				++$this->inserted;
				return new \WP_Error( 'refused', 'The test never creates a user.' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_asking_for_a_link_with_an_unknown_address_creates_no_account(): void {
		diluxone_users_user_for( 'nobody-yet@example.test' );

		$this->assertSame(
			0,
			$this->inserted,
			'no user is created when the link is asked for; it is created when the link is opened'
		);
	}
}
