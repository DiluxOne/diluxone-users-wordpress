<?php
/**
 * Issue #140: a failed social sign-in leaves no trace in the activity log.
 *
 * The visitor gets the one message and an administrator, who only has the
 * activity log, cannot tell why. The expectation: with the log on, a social
 * sign-in that is cancelled at the provider, or that comes back with a state
 * nobody issued, writes a row saying why, and the row keeps no code and no
 * state in clear.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue140Test extends TestCase {

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

		// Microsoft set up and the activity log on, every group.
		update_option(
			'diluxone_users_sso',
			array(
				'microsoft' => array(
					'active' => 1,
					'id'     => 'client-id',
					'secret' => 'client-secret',
				),
			)
		);
		update_option( 'diluxone_users_log_levels', array( 'access', 'account', 'security' ) );
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
			// The visitor is sent back to the sign-in with the notice (here the
			// suite's add_query_arg() stub may raise before the redirect). The
			// log, if it is written, was written before this point.
			unset( $e );
		}
	}

	/** What the log wrote, as one string to look into. */
	private function written(): string {
		return strtolower( (string) json_encode( $this->rows ) );
	}

	public function test_a_sign_in_cancelled_at_the_provider_is_recorded_naming_the_provider_and_cancelled(): void {
		$this->return_with(
			array(
				'error'             => 'access_denied',
				'error_description' => 'The user cancelled the authorization',
				'state'             => 'abc123',
			)
		);

		$this->assertNotEmpty( $this->rows, 'the activity log has an entry for the failed sign-in' );
		$this->assertStringContainsString( 'microsoft', $this->written(), 'the entry names the provider' );
		$this->assertStringContainsString( 'cancel', $this->written(), 'the entry says it was cancelled' );
	}

	public function test_a_return_with_a_state_nobody_issued_is_recorded_without_the_code_or_the_state(): void {
		$this->return_with(
			array(
				'code'  => 'SECRETCODE777',
				'state' => 'SECRETSTATE888',
			)
		);

		$this->assertNotEmpty( $this->rows, 'the activity log has an entry for the failed sign-in' );
		$this->assertStringContainsString( 'microsoft', $this->written(), 'the entry names the provider' );
		$this->assertStringNotContainsString( 'secretcode777', $this->written(), 'the code is not kept' );
		$this->assertStringNotContainsString( 'secretstate888', $this->written(), 'the state is not kept' );
	}
}
