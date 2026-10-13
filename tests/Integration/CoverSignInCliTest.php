<?php
/**
 * The WP-CLI commands, run against a stand-in for WP-CLI.
 *
 * WP-CLI is not loaded inside PHPUnit. The command functions are declared
 * anyway — PHP binds a file's top-level functions when it compiles it, before
 * includes/cli.php returns for want of WP-CLI — so each test loads the
 * stand-in (Support/CoverSignInCli.php), runs a command and reads what it
 * printed. WP_CLI::error() exits in WP-CLI and throws here. Each test runs in
 * a process of its own, so the stand-in class never reaches the other tests.
 *
 * The WP_CLI::add_command() lines are the only ones left unrun: they belong
 * to the load, which happened without WP-CLI.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSignInStop;

class CoverSignInCliTest extends IntegrationTestCase {

	/** The highest user ID before the test: the membership sync is kept to the test's own accounts. */
	private int $mark = 0;

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/Support/CoverSignInCli.php';

		\WP_CLI::$lines = array();

		global $wpdb;
		$this->mark = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	protected function tearDown(): void {
		remove_filter( 'pre_update_site_option_' . DILUXONE_USERS_MEMBERSHIP_QUEUE, array( $this, 'only_the_test_s_accounts' ) );
		remove_all_filters( 'diluxone_users_membership_batch' );

		if ( function_exists( 'diluxone_users_membership_unlock' ) ) {
			diluxone_users_membership_unlock();
		}

		parent::tearDown();
	}

	/**
	 * Runs a command and returns what it printed, and whether it ended in an error.
	 *
	 * @param array<int, mixed> $args
	 * @return array{lines: array<int, array{0: string, 1: string}>, error: string}
	 */
	private function run_command( callable $command, array $args = array() ): array {
		$error = '';

		try {
			$command( ...$args );
		} catch ( CoverSignInStop $e ) {
			$error = $e->getMessage();
		}

		return array(
			'lines' => \WP_CLI::$lines,
			'error' => $error,
		);
	}

	/**
	 * The `pre_update_site_option_{queue}` filter: the sync's job starts after
	 * every account that was there before the test.
	 *
	 * @param mixed $jobs
	 * @return mixed
	 */
	public function only_the_test_s_accounts( $jobs ) {
		foreach ( (array) $jobs as $i => $job ) {
			if ( is_array( $job ) && 'all' === ( $job['kind'] ?? '' ) ) {
				$jobs[ $i ]['after'] = max( (int) ( $job['after'] ?? 0 ), $this->mark );
			}
		}

		return $jobs;
	}

	/* ── wp diluxone-users login ─────────────────────────────────────── */

	public function test_the_login_command_wants_an_address(): void {
		$out = $this->run_command( 'diluxone_users_cli_login', array( array( 'not an address' ) ) );

		$this->assertSame( 'A valid e-mail address is needed.', $out['error'] );

		$out = $this->run_command( 'diluxone_users_cli_login', array( array() ) );
		$this->assertSame( 'A valid e-mail address is needed.', $out['error'] );
	}

	public function test_the_login_command_says_when_nobody_has_the_address(): void {
		$out = $this->run_command( 'diluxone_users_cli_login', array( array( 'nobody-' . wp_generate_password( 6, false ) . '@example.test' ) ) );

		$this->assertStringStartsWith( 'There is no account with the e-mail ', $out['error'] );
		$this->assertSame( array(), self::$mail );
	}

	public function test_the_login_command_prints_a_link_that_signs_in_once_and_mails_nothing(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;

		$out = $this->run_command( 'diluxone_users_cli_login', array( array( $email ) ) );

		$this->assertSame( '', $out['error'] );
		$this->assertSame( array(), self::$mail, 'printed, not sent' );
		$this->assertCount( 2, $out['lines'] );
		$url = $out['lines'][0][1];
		$this->assertSame( (string) $user, $this->queryArg( $url, 'diluxone_users_login' ) );
		$this->assertTrue( diluxone_users_token_valid( $user, $this->queryArg( $url, 'diluxone_users_token' ) ) );
		$this->assertSame( sprintf( 'It expires in %d minutes and works once.', diluxone_users_login_expiry() ), $out['lines'][1][1] );
	}

	public function test_the_login_command_sends_it_too_when_asked(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;

		$out = $this->run_command( 'diluxone_users_cli_login', array( array( $email ), array( 'send' => true ) ) );

		$this->assertSame( array( 'log', 'E-mail sent.' ), $out['lines'][0] );
		$this->assertSame( $email, $this->lastMail()['to'] );
		$this->assertStringContainsString( $out['lines'][1][1], (string) $this->lastMail()['message'] );
	}

	public function test_the_login_command_says_when_the_mail_did_not_go(): void {
		$user = $this->make_user();
		$fail = static fn(): bool => false;
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		add_filter( 'pre_wp_mail', $fail );

		try {
			$out = $this->run_command( 'diluxone_users_cli_login', array( array( get_userdata( $user )->user_email ), array( 'send' => '1' ) ) );
		} finally {
			remove_filter( 'pre_wp_mail', $fail );
		}

		$this->assertSame( array( 'log', 'The e-mail could not be sent.' ), $out['lines'][0] );
	}

	/* ── wp diluxone-users network membership sync ───────────────────── */

	public function test_syncing_a_single_site_is_refused(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case; on a network the counterparts are the other membership-sync cases of this class.' );
		}

		$out = $this->run_command( 'diluxone_users_cli_membership_sync' );

		$this->assertStringStartsWith( 'This is not a network', $out['error'] );
	}

	public function test_syncing_before_the_policy_is_confirmed_adds_nobody(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_syncing_a_single_site_is_refused.' );
		}

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 0 );

		$out = $this->run_command( 'diluxone_users_cli_membership_sync' );

		$this->assertStringContainsString( 'has not been confirmed', $out['error'] );
		$this->assertSame( array(), diluxone_users_membership_queue() );
	}

	public function test_syncing_under_another_policy_adds_nobody(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_syncing_a_single_site_is_refused.' );
		}

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP, 'click' );

		$out = $this->run_command( 'diluxone_users_cli_membership_sync' );

		$this->assertSame( sprintf( 'The network’s membership policy is “%s”: only “all” adds everybody to every site.', diluxone_users_membership() ), $out['error'] );
		$this->assertSame( array(), diluxone_users_membership_queue() );
	}

	public function test_syncing_while_another_run_holds_the_lock_waits_for_it(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_syncing_a_single_site_is_refused.' );
		}

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP, 'all' );
		add_filter( 'pre_update_site_option_' . DILUXONE_USERS_MEMBERSHIP_QUEUE, array( $this, 'only_the_test_s_accounts' ) );
		$this->assertTrue( diluxone_users_membership_lock() );

		$out = $this->run_command( 'diluxone_users_cli_membership_sync' );

		$this->assertSame( 'Another run is adding people right now. Try again in a few minutes.', $out['error'] );
		diluxone_users_membership_queue_save( array() );
	}

	public function test_syncing_adds_every_account_to_every_live_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_syncing_a_single_site_is_refused.' );
		}

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP, 'all' );
		add_filter( 'pre_update_site_option_' . DILUXONE_USERS_MEMBERSHIP_QUEUE, array( $this, 'only_the_test_s_accounts' ) );

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-signin-cli-' . strtolower( wp_generate_password( 6, false ) ) . '/',
			)
		);
		diluxone_users_membership_queue_save( array() );
		$person = (int) wpmu_create_user( 'cli' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), 'cli-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test' );
		wpmu_create_user( 'cli' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), 'cli-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test' );
		diluxone_users_membership_queue_save( array() );

		// One account per batch: the command reports between batches.
		add_filter( 'diluxone_users_membership_batch', static fn(): int => 1 );

		$out = $this->run_command( 'diluxone_users_cli_membership_sync' );

		$this->assertSame( '', $out['error'] );
		$this->assertStringStartsWith( 'Looking at ', $out['lines'][0][1] );
		$this->assertMatchesRegularExpression( '/^all: \d+ of \d+\.$/', $out['lines'][1][1], 'progress between batches' );
		$this->assertSame( array( 'success', 'Every account is a member of every live site, except where an administrator removed it.' ), end( $out['lines'] ) );
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) );
		$this->assertSame( array(), diluxone_users_membership_queue() );
		$this->assertTrue( is_user_member_of_blog( $person, $site ) );
		$this->assertTrue( is_user_member_of_blog( $person, get_main_site_id() ) );
	}
}
