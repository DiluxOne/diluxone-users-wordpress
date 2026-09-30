<?php
/**
 * The activity log against a real database.
 *
 * The unit suite already asks the only question that can be answered without
 * one — does this site record this event — so nothing here repeats it. What is
 * left is everything that only a real MySQL and a real WordPress can be wrong
 * about, and all of it has been wrong at some point in some plugin:
 *
 *   - a table that dbDelta accepted and created without its indexes;
 *   - a write that MySQL refuses in strict mode because a column is too short,
 *     which on a live site is a sign-in that fatals;
 *   - a filter that finds nothing because the dates were compared in the site's
 *     time zone against a column stored in GMT;
 *   - a purge that deletes the lot, or nothing;
 *   - the listeners: a hook signature that changed, a meta write that reports
 *     the wrong thing, a sign-out filed twice under two names.
 *
 * Everything below writes through the same call the plugin uses and reads
 * through the same call the screen uses. Nothing pokes at the table by hand
 * except to empty it between tests.
 */

namespace Tests\Integration;

class ActivityLogTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The table exists on a site that has run the installer, and the test
		// database is not one: the plugin is activated by wp-env before this
		// code is ever loaded.
		diluxone_users_log_install();
		$this->empty_table();

		$this->recording( array( 'access', 'account', 'security' ) );
	}

	public function test_a_failed_install_does_not_record_the_schema_version(): void {
		global $wpdb;

		$table = diluxone_users_log_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( DILUXONE_USERS_LOG_SCHEMA_OPTION );

		// Make the CREATE impossible, the way a missing grant would. dbDelta
		// reports nothing either way, which is the whole point.
		$suppress = $wpdb->suppress_errors( true );
		$break    = static function ( $query ) use ( $table ) {
			return str_replace( "CREATE TABLE {$table} ", "CREATE TABLE `no such db`.`{$table}` ", (string) $query );
		};
		add_filter( 'query', $break );

		$installed = diluxone_users_log_install();

		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $installed, 'the failure reaches the caller' );
		$this->assertFalse( diluxone_users_log_table_exists(), 'nothing was created' );
		$this->assertFalse(
			get_option( DILUXONE_USERS_LOG_SCHEMA_OPTION ),
			'the schema option stays unwritten, so the next admin request tries again '
			. 'instead of writing every log line into a table that is not there'
		);

		$this->assertTrue( diluxone_users_log_install(), 'and the retry works' );
		$this->assertSame(
			DILUXONE_USERS_LOG_SCHEMA,
			(int) get_option( DILUXONE_USERS_LOG_SCHEMA_OPTION )
		);
	}

	/** Not a row in it, so a count is a count of what the test did. */
	private function empty_table(): void {
		global $wpdb;

		$table = diluxone_users_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/** Leaves the site recording exactly these groups. */
	private function recording( array $groups ): void {
		diluxone_users_update_option( 'diluxone_users_log_levels', $groups );
	}

	/**
	 * Every statement the plugin sent while the callable ran.
	 *
	 * @param callable $run What to watch.
	 * @return array<int, string>
	 */
	private function sqlWhile( callable $run ): array {
		$seen = array();

		$watch = static function ( $sql ) use ( &$seen ) {
			$seen[] = (string) $sql;

			return $sql;
		};

		add_filter( 'query', $watch );
		$run();
		remove_filter( 'query', $watch );

		return $seen;
	}

	/**
	 * The WHERE of every statement above that reads the log table.
	 *
	 * The WHERE and not the whole statement: the row query SELECTs
	 * `l.happened` as a column, so the name is in there whether or not
	 * anybody filtered by it. What is being judged is the condition.
	 *
	 * @param callable $run What to watch.
	 * @return array<int, string>
	 */
	private function logWheresWhile( callable $run ): array {
		$table  = diluxone_users_log_table();
		$wheres = array();

		foreach ( $this->sqlWhile( $run ) as $sql ) {
			if ( false === strpos( $sql, $table ) || 0 !== stripos( ltrim( $sql ), 'SELECT' ) ) {
				continue;
			}

			$at = stripos( $sql, 'WHERE' );

			if ( false === $at ) {
				continue;
			}

			$where = substr( $sql, $at );
			$end   = stripos( $where, 'ORDER BY' );

			$wheres[] = false === $end ? $where : substr( $where, 0, $end );
		}

		return $wheres;
	}

	/** Every row there is, newest first. */
	private function rows( array $filters = array() ): array {
		return diluxone_users_log_search( $filters, 1, 200 )['rows'];
	}

	/** The events of every row there is, newest first. */
	private function events( array $filters = array() ): array {
		return array_column( $this->rows( $filters ), 'event' );
	}

	/** Moves a row back in time, which is the only way to test a purge. */
	private function age_row( int $id, int $days ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			diluxone_users_log_table(),
			array( 'happened' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/* ── The table itself ──────────────────────────────────────────── */

	public function test_the_table_is_created_with_the_indexes_it_is_read_by(): void {
		global $wpdb;

		$table = diluxone_users_log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$keys = (array) $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );

		// dbDelta accepts a CREATE TABLE whose KEY clauses it does not
		// understand and creates the table without them. The screen still
		// works; it just walks the whole log on every query, which nobody
		// notices until the log is big.
		$this->assertContains( 'person', $keys );
		$this->assertContains( 'when_it', $keys );
		$this->assertContains( 'kind', $keys );
	}

	public function test_running_the_installer_twice_changes_nothing(): void {
		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user, array( 'via' => 'link' ) );
		diluxone_users_log_install();

		$this->assertCount( 1, $this->rows() );
	}

	public function test_a_user_agent_longer_than_the_column_does_not_lose_the_row(): void {
		// MySQL in strict mode does not cut an oversized value, it refuses the
		// whole row — so a browser with a long user agent would be a sign-in
		// that writes nothing, or worse, that fatals.
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'Mozilla/5.0 (a very long agent) ', 40 );

		$user = $this->make_user();

		$this->assertTrue( diluxone_users_log_record( 'signed_in', $user ) );
		$this->assertCount( 1, $this->rows() );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/* ── What is written, and what is not ──────────────────────────── */

	public function test_a_group_that_is_not_ticked_writes_nothing_to_the_table(): void {
		$this->recording( array( 'access' ) );

		$user = $this->make_user();

		$this->assertTrue( diluxone_users_log_record( 'signed_in', $user ) );
		$this->assertFalse( diluxone_users_log_record( 'password_changed', $user ) );

		$this->assertSame( array( 'signed_in' ), $this->events() );
	}

	public function test_the_site_can_veto_one_event_it_otherwise_records(): void {
		$quiet = static function ( bool $records, string $event ): bool {
			return 'signed_in' === $event ? false : $records;
		};

		add_filter( 'diluxone_users_log_records', $quiet, 10, 2 );

		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user );
		diluxone_users_log_record( 'signed_out', $user );

		remove_filter( 'diluxone_users_log_records', $quiet, 10 );

		$this->assertSame( array( 'signed_out' ), $this->events() );
	}

	public function test_a_row_keeps_what_it_was_given_and_hands_it_back(): void {
		$user = $this->make_user();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		diluxone_users_log_record( 'sessions_closed', $user, array( 'closed' => 3 ) );

		$row = $this->rows()[0];

		$this->assertSame( 'sessions_closed', $row['event'] );
		$this->assertSame( $user, $row['user_id'] );
		$this->assertSame( '203.0.113.44', $row['ip'] );
		$this->assertSame( '3', $row['detail']['closed'] );
		$this->assertGreaterThan( time() - 120, $row['happened'] );
	}

	public function test_emptying_it_takes_every_row_and_says_how_many(): void {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		diluxone_users_log_record( 'sessions_closed', 0 );
		diluxone_users_log_record( 'sessions_closed', 0 );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty' );

		$url = $this->expectRedirect( 'diluxone_users_log_empty' );

		$this->assertSame( array(), $this->rows() );
		$this->assertSame( '2', $this->queryArg( $url, 'diluxone-users-emptied' ) );
	}

	public function test_emptying_it_asks_for_the_right_to(): void {
		wp_set_current_user( $this->make_user() );
		diluxone_users_log_record( 'sessions_closed', 0 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty' );

		$this->expectException( \WPAjaxDieContinueException::class );

		try {
			diluxone_users_log_empty();
		} finally {
			$this->assertCount( 1, $this->rows() );
		}
	}

	/* ── Finding one row among many ────────────────────────────────── */

	public function test_the_filters_find_by_person_by_kind_and_by_day(): void {
		$one = $this->make_user();
		$two = $this->make_user();

		wp_update_user(
			array(
				'ID'           => $one,
				'display_name' => 'Aurelia Perro',
			)
		);

		// Naming her is itself a row — that is the listener two tests below.
		// Here it is scaffolding, so the table starts at nothing again.
		$this->empty_table();

		diluxone_users_log_record( 'signed_in', $one );
		diluxone_users_log_record( 'signed_out', $one );
		diluxone_users_log_record( 'signed_in', $two );

		$this->assertCount( 3, $this->rows() );
		$this->assertCount( 2, $this->rows( array( 'who' => 'Aurelia' ) ) );
		$this->assertCount( 2, $this->rows( array( 'event' => 'signed_in' ) ) );
		$this->assertCount( 1, $this->rows( array( 'who' => 'Aurelia', 'event' => 'signed_in' ) ) );

		// Today in the site's own time zone. It is the case that broke on a
		// site at UTC-3: the column is GMT, and asking for "today" without
		// converting missed the first hours of it.
		$today = (string) wp_date( 'Y-m-d' );

		$this->assertCount( 3, $this->rows( array( 'from' => $today, 'to' => $today ) ) );
		$this->assertCount( 0, $this->rows( array( 'from' => (string) wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ) ) );
		$this->assertCount( 0, $this->rows( array( 'to' => (string) wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ) ) );
	}

	public function test_a_filter_nobody_filled_in_is_not_a_filter(): void {
		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user );

		// The "or nothing was asked" half of every clause in the query. Get it
		// wrong and an empty search box finds nothing at all, which reads
		// exactly like a log that was never on.
		$this->assertCount(
			1,
			$this->rows(
				array(
					'who'   => '',
					'event' => '',
					'from'  => '',
					'to'    => '',
				)
			)
		);
	}

	public function test_a_refused_sign_in_belongs_to_nobody_and_is_still_listed(): void {
		diluxone_users_log_record( 'sign_in_failed', 0, array( 'tried' => 'quien@example.test' ) );

		$rows = $this->rows();

		// It is the row an INNER join would have hidden, and the reason the
		// query joins the other way.
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['user_id'] );
		$this->assertSame( 'quien@example.test', $rows[0]['detail']['tried'] );
	}

	public function test_the_pages_do_not_overlap_and_do_not_skip(): void {
		$user = $this->make_user();

		for ( $i = 0; $i < 7; $i++ ) {
			diluxone_users_log_record( 'signed_in', $user, array( 'via' => (string) $i ) );
		}

		$first  = diluxone_users_log_search( array(), 1, 3 );
		$second = diluxone_users_log_search( array(), 2, 3 );

		$this->assertSame( 7, $first['total'] );
		$this->assertCount( 3, $first['rows'] );
		$this->assertSame(
			array(),
			array_intersect( array_column( $first['rows'], 'id' ), array_column( $second['rows'], 'id' ) )
		);
	}

	/* ── Keeping it small ──────────────────────────────────────────── */

	public function test_the_purge_drops_what_is_past_the_retention_and_keeps_the_rest(): void {
		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user );
		diluxone_users_log_record( 'signed_out', $user );

		$old = $this->rows()[1]['id'];
		$this->age_row( $old, 200 );

		diluxone_users_update_option( 'diluxone_users_log_days', 90 );

		$this->assertSame( 1, diluxone_users_log_purge() );
		$this->assertSame( array( 'signed_out' ), $this->events() );
	}

	public function test_keeping_it_for_ever_deletes_nothing(): void {
		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user );
		$this->age_row( $this->rows()[0]['id'], 4000 );

		diluxone_users_update_option( 'diluxone_users_log_days', 0 );

		$this->assertSame( 0, diluxone_users_log_purge() );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_the_screen_reads_the_size_out_of_the_database(): void {
		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user );
		diluxone_users_log_record( 'signed_out', $user );

		$size = diluxone_users_log_size();

		$this->assertSame( 2, $size['rows'] );
		$this->assertGreaterThan( 0, $size['bytes'] );
		$this->assertNotSame( '', $size['oldest'] );
	}

	/* ── The listeners ─────────────────────────────────────────────── */

	public function test_signing_in_and_out_writes_one_row_each(): void {
		$user = get_userdata( $this->make_user() );

		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'wp_logout', (int) $user->ID );

		$this->assertSame( array( 'signed_out', 'signed_in' ), $this->events() );
		$this->assertSame( 'password', $this->rows()[1]['detail']['via'] );
	}

	public function test_a_refused_password_is_filed_with_what_was_typed(): void {
		do_action( 'wp_login_failed', 'nadie', new \WP_Error( 'incorrect_password', 'no' ) );

		$row = $this->rows()[0];

		$this->assertSame( 'sign_in_failed', $row['event'] );
		$this->assertSame( 'nadie', $row['detail']['tried'] );
		$this->assertSame( 'incorrect_password', $row['detail']['reason'] );
	}

	public function test_changing_the_address_and_the_name_are_two_different_rows(): void {
		$user = $this->make_user();
		$was  = get_userdata( $user )->user_email;

		wp_update_user(
			array(
				'ID'           => $user,
				'user_email'   => 'nueva-' . wp_generate_password( 6, false ) . '@example.test',
				'display_name' => 'Otro Nombre',
			)
		);

		$events = $this->events();

		$this->assertContains( 'email_changed', $events );
		$this->assertContains( 'name_changed', $events );
		$this->assertNotContains( 'password_changed', $events );

		$this->assertSame( $was, $this->rows( array( 'event' => 'email_changed' ) )[0]['detail']['was'] );
	}

	public function test_a_new_password_is_a_row_and_the_password_is_not_in_it(): void {
		$user = $this->make_user();

		wp_update_user(
			array(
				'ID'        => $user,
				'user_pass' => 'una-contrasena-nueva-larga',
			)
		);

		$rows = $this->rows( array( 'event' => 'password_changed' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( array(), $rows[0]['detail'] );
	}

	public function test_turning_the_second_step_on_and_off_are_two_rows_and_saying_it_twice_is_not(): void {
		$user = $this->make_user();

		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );
		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );
		delete_user_meta( $user, 'diluxone_users_2fa_on' );

		$this->assertSame( array( '2fa_off', '2fa_on' ), $this->events() );
	}

	public function test_the_second_step_writes_nothing_while_that_group_is_off(): void {
		$this->recording( array( 'access' ) );

		$user = $this->make_user();

		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );

		$this->assertSame( array(), $this->events() );
	}

	public function test_a_passkey_arriving_and_leaving_are_told_apart(): void {
		$user = $this->make_user();

		diluxone_users_passkeys_save( $user, array( array( 'id' => 'una', 'counter' => 0 ) ) );
		diluxone_users_passkeys_save(
			$user,
			array( array( 'id' => 'una', 'counter' => 0 ), array( 'id' => 'otra', 'counter' => 0 ) )
		);
		// The counter going up on a sign-in rewrites the same list. It is not
		// a passkey arriving and it must not read as one.
		diluxone_users_passkeys_save(
			$user,
			array( array( 'id' => 'una', 'counter' => 7 ), array( 'id' => 'otra', 'counter' => 0 ) )
		);
		diluxone_users_passkeys_save( $user, array() );

		$this->assertSame( array( 'passkey_removed', 'passkey_added', 'passkey_added' ), $this->events() );
		$this->assertSame( '0', $this->rows()[0]['detail']['keys'] );
	}

	public function test_sessions_closed_by_hand_are_a_row_and_sessions_that_expired_are_not(): void {
		$user = $this->make_user();

		$sessions = array(
			'aaa' => array( 'expiration' => time() + HOUR_IN_SECONDS ),
			'bbb' => array( 'expiration' => time() + HOUR_IN_SECONDS ),
			'ccc' => array( 'expiration' => time() - HOUR_IN_SECONDS ),
		);

		update_user_meta( $user, 'session_tokens', $sessions );

		// WordPress tidying up after itself: the expired one goes, and nothing
		// happened that anybody did.
		unset( $sessions['ccc'] );
		update_user_meta( $user, 'session_tokens', $sessions );

		$this->assertSame( array(), $this->events( array( 'event' => 'sessions_closed' ) ) );

		// Somebody closing a session that was still good.
		unset( $sessions['bbb'] );
		update_user_meta( $user, 'session_tokens', $sessions );

		$rows = $this->rows( array( 'event' => 'sessions_closed' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]['detail']['closed'] );
	}

	/* ── Somebody's data ───────────────────────────────────────────── */

	public function test_the_export_hands_back_that_person_and_only_that_person(): void {
		$mine  = $this->make_user();
		$other = $this->make_user();

		diluxone_users_log_record( 'signed_in', $mine, array( 'via' => 'link' ) );
		diluxone_users_log_record( 'signed_in', $other );

		$this->assertArrayHasKey( 'diluxone-users-log', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );

		$export = diluxone_users_log_export( (string) get_userdata( $mine )->user_email );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'diluxone-users-log', $export['data'][0]['group_id'] );

		$values = array_column( $export['data'][0]['data'], 'value' );

		$this->assertContains( 'link', $values );
	}

	public function test_the_export_of_somebody_who_is_not_here_is_empty_and_finished(): void {
		$export = diluxone_users_log_export( 'nadie@example.test' );

		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	public function test_the_erasure_takes_that_person_and_leaves_everybody_else(): void {
		$mine  = $this->make_user();
		$other = $this->make_user();

		diluxone_users_log_record( 'signed_in', $mine );
		diluxone_users_log_record( 'signed_in', $other );
		diluxone_users_log_record( 'sign_in_failed', 0, array( 'tried' => 'x@example.test' ) );

		$this->assertArrayHasKey( 'diluxone-users-log', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		$answer = diluxone_users_log_erase( (string) get_userdata( $mine )->user_email );

		$this->assertTrue( $answer['items_removed'] );
		$this->assertTrue( $answer['done'] );

		$left = $this->rows();

		$this->assertCount( 2, $left );
		$this->assertSame( array( 0, $other ), array_column( $left, 'user_id' ) );
	}

	/**
	 * A refused sign-in is filed under nobody, and what was typed is the
	 * person's own address or username: it is their data, so it goes out with
	 * their export, and the erasure says it was kept and why instead of
	 * answering a silent "nothing retained".
	 */
	public function test_refused_sign_ins_that_typed_somebodys_name_follow_that_person(): void {
		$mine = get_userdata( $this->make_user() );

		diluxone_users_log_record( 'sign_in_failed', 0, array( 'tried' => $mine->user_email ) );
		diluxone_users_log_record( 'sign_in_failed', 0, array( 'tried' => $mine->user_login ) );
		diluxone_users_log_record( 'sign_in_failed', 0, array( 'tried' => 'somebody-else@example.test' ) );

		$export = diluxone_users_log_export( $mine->user_email );

		$this->assertCount( 2, $export['data'] );

		$answer = diluxone_users_log_erase( $mine->user_email );

		$this->assertTrue( $answer['items_retained'] );
		$this->assertNotEmpty( $answer['messages'] );
		$this->assertCount( 3, $this->rows(), 'Security evidence is not erased on request' );
	}

	public function test_deleting_an_account_takes_its_rows_with_it(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$user = $this->make_user();

		diluxone_users_log_record( 'signed_in', $user );
		$this->assertCount( 1, $this->rows() );

		wp_delete_user( $user );

		$this->assertCount( 0, $this->rows() );
	}

	/* ── The shape of the query, which is what a real host judges ──── */

	/*
	 * These read the SQL rather than the rows, and the reason is worth
	 * writing down because it is a limit of this whole suite.
	 *
	 * The search used to build its WHERE out of
	 * `( %s = '' OR l.happened >= %s )`, so an unset filter compared itself
	 * away. Against a DATETIME column that is not a comparison every engine
	 * agrees on: MySQL 8 answers `Incorrect DATETIME value: ''` and kills the
	 * statement, so the Activity screen reported "nothing matches" over a
	 * table with rows in it. MariaDB — which is what wp-env runs, at every
	 * sql_mode including the one the site that broke was using — answers
	 * true and carries on.
	 *
	 * So no test that inserts rows here and counts them back can catch this:
	 * the engine under the suite is not the engine under the plugin. What can
	 * be checked anywhere is the shape — that a filter nobody set is not in
	 * the query at all — and that is the rule the fix rests on.
	 */

	public function test_an_unset_filter_is_not_in_the_query_at_all(): void {
		$wheres = $this->logWheresWhile(
			static function (): void {
				diluxone_users_log_search( array(), 1, 20 );
			}
		);

		$this->assertNotEmpty( $wheres, 'The search has to read the log table' );

		foreach ( $wheres as $sql ) {
			$this->assertStringNotContainsString(
				'happened',
				$sql,
				'A date filter nobody set must not appear in the query: comparing a DATETIME with the empty string is an error on MySQL.'
			);
			$this->assertStringNotContainsString( "= ''", $sql, 'Nothing is compared against the empty string' );
			$this->assertStringNotContainsString( 'l.event =', $sql, 'An event filter nobody set must not appear either' );
			$this->assertStringNotContainsString( 'LIKE', $sql, 'A name filter nobody set must not appear either' );
		}
	}

	public function test_a_filter_that_was_set_is_in_the_query_with_its_own_value(): void {
		$today = wp_date( 'Y-m-d' );

		$wheres = $this->logWheresWhile(
			static function () use ( $today ): void {
				diluxone_users_log_search(
					array(
						'event' => 'signed_in',
						'from'  => $today,
					),
					1,
					20
				);
			}
		);

		$this->assertNotEmpty( $wheres );

		foreach ( $wheres as $sql ) {
			$this->assertStringContainsString( 'l.happened >=', $sql );
			$this->assertStringContainsString( "l.event = 'signed_in'", $sql );
			$this->assertStringNotContainsString( "= ''", $sql );
			// The one that was NOT set stays out.
			$this->assertStringNotContainsString( 'l.happened <=', $sql );
		}
	}

	public function test_the_filters_still_return_what_they_should(): void {
		$user = $this->make_user();
		$who  = get_userdata( $user );

		diluxone_users_log_record( 'signed_in', $user, array( 'via' => 'password' ) );
		diluxone_users_log_record( 'signed_out', $user );
		diluxone_users_log_record( 'signed_in', 1, array( 'via' => 'password' ) );

		$today = wp_date( 'Y-m-d' );

		$this->assertSame( 3, diluxone_users_log_search( array() )['total'], 'No filter sees every row' );
		$this->assertSame( 2, diluxone_users_log_search( array( 'event' => 'signed_in' ) )['total'] );
		$this->assertSame( 2, diluxone_users_log_search( array( 'who' => $who->user_email ) )['total'] );
		$this->assertSame( 3, diluxone_users_log_search( array( 'from' => $today ) )['total'] );
		$this->assertSame(
			1,
			diluxone_users_log_search(
				array(
					'who'   => $who->user_email,
					'event' => 'signed_out',
				)
			)['total'],
			'Two filters narrow together'
		);
	}
}
