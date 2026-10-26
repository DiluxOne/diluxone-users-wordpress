<?php
/**
 * Issue #153: social sign-in failures have no group of their own, and one
 * reason covers several causes.
 *
 * The expectation: a failed social sign-in is recorded by a group of its own,
 * that can be on while `access` (every sign-in and sign-out) is off, and an
 * address the provider did not verify, under "Only verified addresses", is
 * recorded as `email_unverified` and not as the catch-all `account`.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue153Test extends TestCase {

	use ResetsWpStubs;

	/** @var mixed The database object as the test found it. */
	private $wpdb_before;

	/** @var array<int, array<string, mixed>> The rows the log tried to write. */
	private array $rows = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'COOKIEHASH' ) ) {
			define( 'COOKIEHASH', 'testhash' );
		}

		// log.php registers the table's deactivation hook as it loads.
		Functions\when( 'register_activation_hook' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( true );

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/multisite.php';
		require_once DILUXONE_USERS_DIR . 'includes/network-hub.php';
		require_once DILUXONE_USERS_DIR . 'includes/client-ip.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-door.php';
		require_once DILUXONE_USERS_DIR . 'includes/log.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso-providers.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso-popup.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso.php';

		// WordPress, which the unit bootstrap does not stub.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new \RuntimeException( 'redirect: ' . $url );
			}
		);

		// The provider: a token on the exchange, then a profile with an address
		// and no `email_verified`, as a personal Microsoft account sends it.
		Functions\when( 'wp_remote_post' )->justReturn( array( 'body' => '{"access_token":"tok-1"}' ) );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"sub":"ms-1","email":"person@example.com"}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string {
				return (string) $response['body'];
			}
		);
		Functions\when( 'sanitize_email' )->alias(
			static function ( string $email ): string {
				return trim( $email );
			}
		);
		Functions\when( 'is_email' )->alias(
			static function ( string $email ): bool {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_user_by' )->justReturn( false );

		// The log's table, as far as a write goes: it keeps what it is given.
		$this->rows        = array();
		$this->wpdb_before = $GLOBALS['wpdb'] ?? null;
		$rows              = &$this->rows;

		$GLOBALS['wpdb'] = new class( $rows ) {
			public string $prefix      = 'wp_';
			public string $base_prefix = 'wp_';

			/** @var array<int, array<string, mixed>> */
			private array $rows;

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array &$rows ) {
				$this->rows = &$rows;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, $format = null ) {
				$this->rows[] = $data;

				return 1;
			}
		};

		// Microsoft set up, tested and on; new accounts allowed from it, and
		// only for an address the provider verified.
		update_option(
			'diluxone_users_sso',
			array(
				'microsoft' => array(
					'active' => 1,
					'tested' => 1,
					'id'     => 'client-id',
					'secret' => 'client-secret',
				),
			)
		);
		update_option( 'diluxone_users_sso_register', 1 );
		update_option( 'diluxone_users_sso_verified_only', 1 );
		update_option( DILUXONE_USERS_LOG_SCHEMA_OPTION, DILUXONE_USERS_LOG_SCHEMA );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_before;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The return of a trip, as the provider sends the browser back. */
	private function return_with( array $query ): void {
		$_GET = array( 'diluxone_users_sso' => 'microsoft' ) + $query;

		diluxone_users_sso_query_snapshot();

		try {
			diluxone_users_sso_handle( null );
		} catch ( \Throwable $e ) {
			// The visitor is sent back to the sign-in with the notice. The log,
			// if it is written, was written before this point.
			unset( $e );
		}
	}

	/** What the log wrote, as one string to look into. */
	private function written(): string {
		return strtolower( (string) json_encode( $this->rows ) );
	}

	public function test_a_social_sign_in_failure_is_recorded_by_a_group_of_its_own_with_access_off(): void {
		$own = array_values( array_diff( array_keys( diluxone_users_log_groups() ), array( 'access', 'account', 'security' ) ) );

		$this->assertNotEmpty( $own, 'the log has a group for social sign-in failures besides access, account and security' );

		update_option( 'diluxone_users_log_levels', $own );

		$this->return_with(
			array(
				'error' => 'access_denied',
				'state' => 'abc123',
			)
		);

		$this->assertNotEmpty( $this->rows, 'the failure is recorded with only that group on' );
		$this->assertStringContainsString( 'microsoft', $this->written(), 'the entry names the provider' );
	}

	public function test_an_address_the_provider_did_not_verify_is_recorded_as_email_unverified(): void {
		update_option( 'diluxone_users_log_levels', array_keys( diluxone_users_log_groups() ) );

		set_transient(
			'diluxone_users_sso_STATE777',
			array(
				'provider' => 'microsoft',
				'test'     => 0,
				'browser'  => wp_hash( 'browser-secret' ),
				'user'     => 0,
			)
		);
		$_COOKIE[ diluxone_users_sso_cookie() ] = 'browser-secret';

		$this->return_with(
			array(
				'code'  => 'SECRETCODE777',
				'state' => 'STATE777',
			)
		);

		$this->assertNotEmpty( $this->rows, 'the failed sign-in is recorded' );
		$this->assertStringContainsString( 'email_unverified', $this->written(), 'the reason says the address was not verified' );
		$this->assertStringNotContainsString( 'secretcode777', $this->written(), 'the code is not kept' );
	}
}
