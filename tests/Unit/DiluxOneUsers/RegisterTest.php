<?php
/**
 * The registration form's last step: the way in it mails.
 *
 * A site that accepts the sign-in link gets the link, as always. A site that
 * signs in with a password only refuses every link, so the new account gets
 * WordPress's "choose your password" mail instead, and never a link.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class RegisterTest extends TestCase {

	use ResetsWpStubs;

	/** @var array<int, array{int, string, string}> Sign-in links mailed. */
	private array $sent = array();

	/** @var array<int, array{int, null|string, string}> WordPress's new-user mails. */
	private array $notified = array();

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
		require_once DILUXONE_USERS_DIR . 'includes/login-door.php';
		require_once DILUXONE_USERS_DIR . 'includes/register.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A valid registration posted to a site with the given sign-in method,
	 * up to the account being created as user 42.
	 */
	private function post_a_registration( string $method ): void {
		update_option( 'diluxone_users_login_method', $method );
		update_option( 'diluxone_users_register_form', true );
		update_option( 'diluxone_users_register_page', 5 );
		update_option( 'diluxone_users_fields', array() );

		$_SERVER['REMOTE_ADDR']                 = '203.0.113.8';
		$_POST['diluxone_users_register_nonce'] = 'abc123';
		$_POST['diluxone_users_email']          = 'someone@example.test';

		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/register/' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'email_exists' )->justReturn( false );
		Functions\when( 'diluxone_users_create_account' )->justReturn( 42 );
		Functions\when( 'diluxone_users_token_create' )->justReturn( 'the-token' );
		Functions\when( 'diluxone_users_login_send' )->alias(
			function ( int $user_id, string $email, string $token ): bool {
				$this->sent[] = array( $user_id, $email, $token );

				return true;
			}
		);
		Functions\when( 'wp_new_user_notification' )->alias(
			function ( int $user_id, ?string $deprecated, string $notify ): void {
				$this->notified[] = array( $user_id, $deprecated, $notify );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new \RuntimeException( 'redirect: ' . $url );
			}
		);
	}

	/** Runs the handler up to its final redirect. */
	private function run_the_handler(): void {
		try {
			diluxone_users_register_request();
		} catch ( \RuntimeException | \TypeError $e ) {
			// The final redirect, or the suite's add_query_arg() stub, which
			// takes an array where the handler passes strings. Both come after
			// the mail.
			unset( $e );
		}
	}

	public function test_a_password_only_site_mails_the_choose_your_password_key_and_no_link(): void {
		$this->post_a_registration( 'password' );

		$this->run_the_handler();

		$this->assertSame( array( array( 42, null, 'user' ) ), $this->notified, 'the new account is mailed the key to choose a password' );
		$this->assertSame( array(), $this->sent, 'and never a sign-in link the site refuses' );
	}

	/**
	 * @dataProvider link_methods
	 */
	public function test_a_site_that_takes_the_link_mails_it_and_no_password_key( string $method ): void {
		$this->post_a_registration( $method );

		$this->run_the_handler();

		$this->assertSame( array( array( 42, 'someone@example.test', 'the-token' ) ), $this->sent, 'the new account is mailed the sign-in link' );
		$this->assertSame( array(), $this->notified, 'and no key to choose a password' );
	}

	/** @return array<string, array{string}> */
	public static function link_methods(): array {
		return array(
			'link only'         => array( 'link' ),
			'link and password' => array( 'both' ),
		);
	}
}
