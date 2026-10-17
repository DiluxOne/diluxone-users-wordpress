<?php
/**
 * Issue 56: a new account from the registration form on a password-only site.
 *
 * The form mails a sign-in link, and a site that signs in with a password only
 * refuses every link, so the person has no way in. Whatever the fix chooses
 * (ask for a password, mail a link that sets one), the form must not send a
 * link the site does not accept.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue56Test extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/multisite.php';
		require_once DILUXONE_USERS_DIR . 'includes/network-hub.php';
		require_once DILUXONE_USERS_DIR . 'includes/client-ip.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		require_once DILUXONE_USERS_DIR . 'includes/register.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_person_who_registers_on_a_password_only_site_is_not_sent_a_link_the_site_refuses(): void {
		// The default sign-in method, a registration form with a page, and no
		// field beyond the address.
		update_option( 'diluxone_users_login_method', 'password' );
		update_option( 'diluxone_users_register_form', true );
		update_option( 'diluxone_users_register_page', 5 );
		update_option( 'diluxone_users_fields', array() );

		$_SERVER['REMOTE_ADDR']                = '203.0.113.7';
		$_POST['diluxone_users_register_nonce'] = 'abc123';
		$_POST['diluxone_users_email']          = 'new.person@example.test';

		$created = array();
		$sent    = array();

		// WordPress, which the unit bootstrap does not stub.
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/register/' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'email_exists' )->justReturn( false );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new \RuntimeException( 'redirect: ' . $url );
			}
		);

		// Making the account and mailing the link are the two steps around the
		// one under test; both only record that they were reached.
		Functions\when( 'diluxone_users_create_account' )->alias(
			static function ( string $email ) use ( &$created ): int {
				$created[] = $email;

				return 42;
			}
		);
		Functions\when( 'diluxone_users_login_send' )->alias(
			static function ( int $user_id, string $email, string $token ) use ( &$sent ): bool {
				$sent[] = array( $user_id, $email, $token );

				return true;
			}
		);

		$this->assertFalse( diluxone_users_login_has_link(), 'the control: a password-only site accepts no link' );

		try {
			diluxone_users_register_request();
		} catch ( \Throwable $e ) {
			// The final redirect: the suite's add_query_arg() stub takes an
			// array and the handler passes strings, so it may be a TypeError.
			// The link, if there is one, was sent before this point.
			unset( $e );
		}

		$this->assertSame( array( 'new.person@example.test' ), $created, 'the handler got as far as creating the account' );
		$this->assertTrue(
			array() === $sent || diluxone_users_login_has_link(),
			'a person who registers on a password-only site must not be mailed a sign-in link the site refuses'
		);
	}
}
