<?php
/**
 * The activity log, around its edges.
 *
 * A table that cannot be brought up to date records nothing and reads as
 * empty, rather than a database error on somebody's sign-in. A list of sites
 * that holds none is nothing, not everything. A site that no longer exists is
 * named by its number. The daily purge does its work and nothing else. And
 * the settings helpers: somebody in no role is in no "some roles" scope, the
 * settings that may carry markup are stored through kses, and whoever pins a
 * setting from code is named by the file it lives in.
 */

namespace Tests\Integration;

/** A setting pinned from code, as a site's own plugin would. */
function cover_account_pin_option( $value, string $key ) {
	return $value;
}

class CoverAccountLogTest extends IntegrationTestCase {

	/** @var array<string, mixed> The log's own markers before the test. */
	private array $markers = array();

	/** Whether the test broke the table check. */
	private bool $broken = false;

	/** @var array<int, int> Sites the test made. */
	private array $sites = array();

	/** An event the log knows, recorded whatever the site ticked. */
	private string $event = '';

	/** @var array<string, int|false> Cron events that were there before the test. */
	private array $events = array();

	protected function setUp(): void {
		parent::setUp();

		$this->markers[ DILUXONE_USERS_LOG_SCHEMA_OPTION ] = diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION, null );

		$this->event = (string) array_key_first( diluxone_users_log_events() );
		add_filter( 'diluxone_users_log_records', '__return_true' );

		$this->events[ DILUXONE_USERS_LOG_PURGE ] = wp_next_scheduled( DILUXONE_USERS_LOG_PURGE );
	}

	protected function tearDown(): void {
		remove_filter( 'query', array( $this, 'no_table' ) );
		remove_filter( 'diluxone_users_log_records', '__return_true' );
		remove_filter( 'diluxone_users_option', __NAMESPACE__ . '\cover_account_pin_option', 10 );
		remove_filter( 'diluxone_users_option', '__return_false', 99 );
		remove_filter( 'diluxone_users_option', 'strtolower', 98 );

		foreach ( $this->markers as $key => $value ) {
			if ( null === $value ) {
				diluxone_users_delete_option( $key );
			} else {
				diluxone_users_update_option( $key, $value, false );
			}
		}

		foreach ( $this->events as $event => $when ) {
			if ( false === $when ) {
				wp_clear_scheduled_hook( $event );
			}
		}

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		parent::tearDown();
	}

	/** The `query` filter: the database answers that the log's table is not there. */
	public function no_table( string $query ): string {
		if ( 0 === stripos( ltrim( $query ), 'SHOW TABLES LIKE' ) && 1 === preg_match( '/diluxone\W*_users\W*_log/', $query ) ) {
			return 'SELECT NULL FROM DUAL WHERE 1 = 0';
		}

		return $query;
	}

	/** A log a shape behind whose table cannot be made. */
	private function break_the_table(): void {
		diluxone_users_delete_option( DILUXONE_USERS_LOG_SCHEMA_OPTION );
		add_filter( 'query', array( $this, 'no_table' ) );
	}

	/* ── A table that cannot be brought up to date ─────────────────── */

	public function test_a_table_that_cannot_be_made_records_nothing_and_reads_as_empty(): void {
		$this->break_the_table();

		$this->assertFalse( diluxone_users_log_current() );
		$this->assertFalse( diluxone_users_log_current(), 'Refused once, not tried again on the same request' );
		$this->assertFalse( diluxone_users_log_record( $this->event, 1 ), 'A sign-in is not a database error' );
		$this->assertSame(
			array(
				'rows'   => 0,
				'bytes'  => 0,
				'oldest' => '',
			),
			diluxone_users_log_size()
		);
		$this->assertSame(
			array(
				'rows'  => array(),
				'total' => 0,
			),
			diluxone_users_log_search()
		);
		$this->assertSame( array(), diluxone_users_log_of( 1 ) );
		$this->assertSame( array(), diluxone_users_log_tried( array( 'admin' ) ) );
		$this->assertSame( 0, diluxone_users_log_forget( 1 ) );
		$this->assertSame( 0, diluxone_users_log_empty_rows( get_current_blog_id() ) );
	}

	/* ── The rest of the log ───────────────────────────────────────── */

	public function test_the_dashboard_brings_a_table_a_shape_behind_up_to_date(): void {
		diluxone_users_delete_option( DILUXONE_USERS_LOG_SCHEMA_OPTION );

		diluxone_users_log_ready();

		$this->assertSame( DILUXONE_USERS_LOG_SCHEMA, (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION ) );
		$this->assertTrue( diluxone_users_log_current() );
	}

	public function test_a_list_of_no_sites_is_nothing_not_everything(): void {
		diluxone_users_log_record( $this->event, 1 );

		$this->assertSame(
			array(
				'rows'  => array(),
				'total' => 0,
			),
			diluxone_users_log_search( array( 'sites' => array() ) )
		);
		$this->assertGreaterThan( 0, diluxone_users_log_search()['total'], 'While with no list, there is a row' );
	}

	public function test_a_site_that_no_longer_exists_is_named_by_its_number(): void {
		$this->assertSame( 'Site 987654', diluxone_users_log_site_name( 987654 ) );
	}

	public function test_deleting_something_that_is_no_site_removes_no_row(): void {
		diluxone_users_log_record( $this->event, 1 );
		$before = diluxone_users_log_search()['total'];

		diluxone_users_log_site_deleted( 'not a site' );

		$this->assertSame( $before, diluxone_users_log_search()['total'] );
	}

	public function test_a_profile_update_with_nothing_to_compare_records_nothing(): void {
		$before = diluxone_users_log_search()['total'];

		diluxone_users_log_profile_update( 1, null );
		diluxone_users_log_profile_update( 987654321, get_userdata( 1 ) );

		$this->assertSame( $before, diluxone_users_log_search()['total'] );
	}

	public function test_the_daily_purge_runs_from_its_event(): void {
		diluxone_users_log_record( $this->event, 1 );
		$row = (int) diluxone_users_log_search()['rows'][0]['id'];

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET happened = %s WHERE id = %d', diluxone_users_log_table(), gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), $row ) );

		do_action( DILUXONE_USERS_LOG_PURGE );

		$this->assertNotContains( $row, array_map( 'intval', array_column( diluxone_users_log_search( array(), 1, 200 )['rows'], 'id' ) ), 'A row past every retention is gone' );
	}

	/* ── The settings helpers ──────────────────────────────────────── */

	public function test_somebody_who_is_not_there_is_in_no_some_roles_scope(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_2fa_roles', array( 'subscriber' ) );

		$this->assertFalse( diluxone_users_scope_includes( 987654321, 'diluxone_users_2fa' ) );
		$this->assertTrue( diluxone_users_scope_includes( $this->make_user(), 'diluxone_users_2fa' ), 'While a subscriber is' );
	}

	public function test_a_setting_that_may_carry_markup_is_stored_through_kses(): void {
		$this->in_network_admin();
		wp_set_current_user( 1 );

		diluxone_users_save_options( array( 'diluxone_users_login_legal' => 'By signing in you accept the <a href="/terms/">terms</a>.<script>alert(1)</script>' ) );

		$stored = (string) diluxone_users_raw_get( 'diluxone_users_login_legal', '' );

		if ( ! diluxone_users_option_editable_here( 'diluxone_users_login_legal' ) ) {
			$this->assertSame( '', $stored, 'Not editable from here, not written' );

			return;
		}

		$this->assertStringContainsString( '<a href="/terms/">terms</a>', $stored );
		$this->assertStringNotContainsString( '<script', $stored );
	}

	public function test_whoever_pins_a_setting_from_code_is_named_by_its_file(): void {
		global $wp_filter;

		$had = isset( $wp_filter['diluxone_users_option'] );

		if ( ! $had ) {
			$this->assertSame( array(), diluxone_users_option_forced_by(), 'Nobody hooked, nobody named' );
		}

		$before = count( diluxone_users_option_forced_by() );

		add_filter( 'diluxone_users_option', __NAMESPACE__ . '\cover_account_pin_option', 10, 2 );
		add_filter( 'diluxone_users_option', '__return_false', 99 );
		add_filter( 'diluxone_users_option', 'strtolower', 98 );
		add_filter( 'diluxone_users_option', static fn( $v ) => $v, 97 );
		$this->hook( 'diluxone_users_option', array( $this, 'unnamed' ), 96 );

		$who = diluxone_users_option_forced_by();

		$this->assertCount( $before + 3, $who, 'a closure and a method have no name to give' );

		$this->assertContains( __NAMESPACE__ . '\cover_account_pin_option() — diluxone-users-wordpress/tests/Integration/CoverAccountLogTest.php', $who, 'A plugin’s file, by its folder and name' );
		$this->assertContains( '__return_false() — wp-includes/functions.php', $who, 'A file outside the plugins, by its folder and name, never the server’s path' );
		$this->assertContains( 'strtolower() — ', $who, 'PHP’s own has no file to name' );

		foreach ( $who as $line ) {
			$this->assertStringNotContainsString( ABSPATH, $line );
		}

		$this->assertTrue( diluxone_users_option_forced( 'diluxone_users_2fa_mode' ), 'Pinned: the screen can say so' );
	}

	/**
	 * A method hooked to the settings: left out of the list of who pinned them.
	 *
	 * @param mixed $value The setting.
	 * @return mixed
	 */
	public function unnamed( $value ) {
		return $value;
	}

	public function test_a_link_lasts_between_a_minute_and_a_day(): void {
		foreach ( array( 0 => 1, -5 => 1, 15 => 15, 1440 => 1440, 99999 => 1440 ) as $stored => $minutes ) {
			diluxone_users_update_option( 'diluxone_users_login_expiry', $stored );
			$this->assertSame( $minutes, diluxone_users_login_expiry(), "stored: $stored" );
		}
	}

	public function test_a_flash_message_is_taken_once_and_only_as_words(): void {
		$user = $this->make_user();

		diluxone_users_flash_set( $user, 'saved', 'Done.' );
		$this->assertSame( 'Done.', diluxone_users_flash_take( $user, 'saved' ) );
		$this->assertSame( '', diluxone_users_flash_take( $user, 'saved' ), 'taken once' );

		set_transient( 'diluxone_users_flash_saved_' . $user, array( 'x' ) );
		$this->assertSame( '', diluxone_users_flash_take( $user, 'saved' ), 'what is not words is nothing to say' );
		$this->assertFalse( get_transient( 'diluxone_users_flash_saved_' . $user ), 'and it is not kept' );
	}
}
