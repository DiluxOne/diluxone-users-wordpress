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
 * The three WP_CLI::add_command() lines are the only ones left unrun: they
 * belong to the load, which happened without WP-CLI.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSignInStop;

class CoverSignInCliTest extends IntegrationTestCase {

	/** The highest user ID before the test: the membership sync is kept to the test's own accounts. */
	private int $mark = 0;

	/** @var array<string, mixed>|null What the network's moves held before the test undid them, put back after it. */
	private ?array $moves = null;

	/** @var array<int, string> Old per-site log tables the test made. */
	private array $old_tables = array();

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

		if ( null !== $this->moves ) {
			global $wpdb;

			foreach ( $this->moves as $key => $value ) {
				if ( null === $value ) {
					delete_site_option( $key );
				} else {
					update_site_option( $key, $value );
				}
			}

			foreach ( $this->old_tables as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
			}

			wp_clear_scheduled_hook( DILUXONE_USERS_NETWORK_MIGRATE_EVENT );
			wp_clear_scheduled_hook( DILUXONE_USERS_LOG_MOVE_EVENT );
			$this->moves = null;
		}

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

	/* ── wp diluxone-users network migrate ───────────────────────────── */

	public function test_migrating_a_single_site_is_refused(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case; on a network the counterpart is test_migrating_a_network_already_moved_says_so_and_succeeds.' );
		}

		$out = $this->run_command( 'diluxone_users_cli_network_migrate' );

		$this->assertSame( 'This is not a network: there is nothing to move.', $out['error'] );
	}

	public function test_migrating_a_network_already_moved_says_so_and_succeeds(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_migrating_a_single_site_is_refused.' );
		}

		// Moved, whatever this tests network had done before — the state this
		// is about — and put back as found after.
		$version = diluxone_users_raw_get( DILUXONE_USERS_NETWORK_VERSION_OPTION, null );
		$moved   = diluxone_users_raw_get( DILUXONE_USERS_LOG_MOVED, null );
		diluxone_users_update_option( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_VERSION );
		diluxone_users_update_option( DILUXONE_USERS_LOG_MOVED, 1 );

		try {
			$out = $this->run_command( 'diluxone_users_cli_network_migrate' );
		} finally {
			foreach ( array( DILUXONE_USERS_NETWORK_VERSION_OPTION => $version, DILUXONE_USERS_LOG_MOVED => $moved ) as $key => $was ) {
				if ( null === $was ) {
					diluxone_users_delete_option( $key );
				} else {
					diluxone_users_update_option( $key, $was );
				}
			}
		}

		$this->assertSame( '', $out['error'] );
		$this->assertSame(
			array(
				array( 'log', 'The network’s settings were already moved.' ),
				array( 'log', 'The sites’ activity logs were already in the network’s table.' ),
				array( 'success', 'The network’s settings and activity log are where they belong.' ),
			),
			$out['lines']
		);
	}

	public function test_migrating_a_network_that_never_moved_moves_it_and_reports_what_it_found(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_migrating_a_single_site_is_refused.' );
		}

		global $wpdb;

		// Undone for the test, and put back after it exactly as found.
		$this->moves = array();
		foreach ( array( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_MIGRATING, DILUXONE_USERS_NETWORK_CONFLICTS, DILUXONE_USERS_NETWORK_CONFLICTS_SEEN, DILUXONE_USERS_LOG_MOVED, DILUXONE_USERS_LOG_MOVING, DILUXONE_USERS_LOG_KEPT ) as $key ) {
			$this->moves[ $key ] = get_site_option( $key, null );
			delete_site_option( $key );
		}

		// A site that kept a setting of its own, different from the network's,
		// and a log table of its own with two rows in it.
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-signin-migrate-' . strtolower( wp_generate_password( 6, false ) ) . '/',
			)
		);
		update_blog_option( $site, 'diluxone_users_2fa_mode', 'required' );

		$old                = $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';
		$this->old_tables[] = $old;
		$wpdb->query( "DROP TABLE IF EXISTS {$old}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( // phpcs:ignore WordPress.DB
			"CREATE TABLE {$old} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(32) NOT NULL DEFAULT '',
				happened datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				ip varchar(45) NOT NULL DEFAULT '',
				agent varchar(255) NOT NULL DEFAULT '',
				detail text NOT NULL,
				PRIMARY KEY  (id)
			)"
		);
		$wpdb->query( "INSERT INTO {$old} (user_id, event, happened, detail) VALUES (1, 'signed_in', UTC_TIMESTAMP(), '{}'), (1, 'signed_in', UTC_TIMESTAMP(), '{}')" ); // phpcs:ignore WordPress.DB

		$this->assertFalse( diluxone_users_network_migrated() );

		$out   = $this->run_command( 'diluxone_users_cli_network_migrate' );
		$lines = array_column( $out['lines'], 1 );

		$this->assertSame( '', $out['error'] );
		$this->assertContains( sprintf( 'Site %d had %s set differently.', $site, 'diluxone_users_2fa_mode' ), $lines );
		$this->assertCount( 1, preg_grep( '/^The network’s settings were moved\. [1-9]\d* differences written down\.$/u', $lines ) );
		$this->assertContains( sprintf( 'Site %d: 2 rows moved, its old table dropped.', $site ), $lines );
		$this->assertContains( 'The sites’ activity logs are in the network’s table. 0 old tables kept.', $lines );
		$this->assertSame( array( 'success', 'The network’s settings and activity log are where they belong.' ), end( $out['lines'] ) );
		$this->assertTrue( diluxone_users_network_migrated() );
		$this->assertTrue( diluxone_users_log_moved() );
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_NETWORK_MIGRATE_EVENT ) );
	}

	/** A database that keeps refusing the copy is given up on after three tries, and nothing is lost. */
	public function test_migrating_gives_up_on_a_database_that_refuses_the_copy(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_migrating_a_single_site_is_refused.' );
		}

		global $wpdb;

		$this->moves = array();
		foreach ( array( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_LOG_MOVED, DILUXONE_USERS_LOG_MOVING, DILUXONE_USERS_LOG_KEPT ) as $key ) {
			$this->moves[ $key ] = get_site_option( $key, null );
			delete_site_option( $key );
		}
		update_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_VERSION );

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-signin-refused-' . strtolower( wp_generate_password( 6, false ) ) . '/',
			)
		);
		$old                = $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';
		$this->old_tables[] = $old;
		$wpdb->query( "CREATE TABLE {$old} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL DEFAULT 0, event varchar(32) NOT NULL DEFAULT '', happened datetime NOT NULL DEFAULT '0000-00-00 00:00:00', ip varchar(45) NOT NULL DEFAULT '', agent varchar(255) NOT NULL DEFAULT '', detail text NOT NULL, PRIMARY KEY  (id) )" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO {$old} (user_id, event, happened, detail) VALUES (1, 'signed_in', UTC_TIMESTAMP(), '{}'), (1, 'signed_in', UTC_TIMESTAMP(), '{}')" ); // phpcs:ignore WordPress.DB

		$table = diluxone_users_log_table();
		$this->hook( 'query', static fn( $sql ) => false !== stripos( (string) $sql, 'INSERT INTO `' . $table . '`' ) ? 'INSERT INTO diluxone_users_no_such_table VALUES (1)' : $sql );
		$suppress = $wpdb->suppress_errors( true );

		try {
			$out = $this->run_command( 'diluxone_users_cli_network_migrate' );
		} finally {
			remove_all_filters( 'query' );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertStringContainsString( 'refused to copy', $out['error'] );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old}" ), 'the old rows are where they were' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE site_id = %d", $site ) ) ); // phpcs:ignore WordPress.DB
		$this->assertFalse( diluxone_users_log_moved() );
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
