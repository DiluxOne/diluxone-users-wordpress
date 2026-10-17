<?php
/**
 * Issue 56: a new account from the registration form on a password-only site.
 *
 * The form creates the account with a password nobody knows and sends a
 * sign-in link, but on a site whose sign-in method is "password" the link is
 * refused as expired. Whoever registers has no way in.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue56Test extends TestCase {

	use ResetsWpStubs;

	/** @var array<int, array<int, mixed>> The sign-in links sent. */
	private array $sent = array();

	/** @var int How many accounts the registration created. */
	private int $created = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		require_once DILUXONE_USERS_DIR . 'includes/register.php';

		$this->sent    = array();
		$this->created = 0;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_person_who_registers_on_a_password_only_site_is_not_sent_a_link_the_site_refuses(): void {
		// The default: sign-in is by password, the link is not accepted.
		update_option( 'diluxone_users_login_method', 'password' );
		update_option( 'diluxone_users_register_form', 1 );

		$this->assertFalse( diluxone_users_login_has_link(), 'the control: this site refuses sign-in links' );

		$_POST['diluxone_users_register_nonce'] = 'nonce';
		$_POST['diluxone_users_email']          = 'new.person@example.test';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'email_exists' )->justReturn( false );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);

		Functions\when( 'diluxone_users_register_url' )->justReturn( 'https://example.test/register/' );
		Functions\when( 'diluxone_users_network_takes_accounts' )->justReturn( true );
		Functions\when( 'diluxone_users_posted_fields' )->justReturn( array() );
		Functions\when( 'diluxone_users_register_missing' )->justReturn( array() );
		Functions\when( 'diluxone_users_register_answers' )->justReturn( array() );
		Functions\when( 'diluxone_users_register_allowed' )->justReturn( true );
		Functions\when( 'diluxone_users_save' )->justReturn( array() );
		Functions\when( 'diluxone_users_token_create' )->justReturn( 'token' );
		Functions\when( 'diluxone_users_create_account' )->alias(
			function (): int {
				++$this->created;
				return 42;
			}
		);
		Functions\when( 'diluxone_users_login_send' )->alias(
			function ( ...$args ): bool {
				$this->sent[] = $args;
				return true;
			}
		);

		try {
			diluxone_users_register_request();
		} catch ( \Throwable $e ) {
			// The redirect stands in for `exit`; the suite's add_query_arg
			// takes an array and may throw first. The link is sent before both.
			unset( $e );
		}

		$this->assertSame( 1, $this->created, 'the registration got as far as creating the account' );

		$this->assertTrue(
			array() === $this->sent || diluxone_users_login_has_link(),
			'a sign-in link was sent to the new account on a site that does not accept sign-in links'
		);
	}
}
