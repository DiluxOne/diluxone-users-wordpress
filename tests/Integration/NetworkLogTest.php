<?php
/**
 * A network's activity log: one table, every row stamped with its site.
 *
 * Each site reads its own rows and empties its own; Network Admin reads every
 * site's, narrows them to one, and empties them all. The network's retention
 * is one purge, run from the main site over every row. A person's erasure is
 * one query. And a network that ran with a log per site gets each site's old
 * table moved in — in batches, a batch interrupted anywhere copied again and
 * never twice, and an old table dropped only once its rows are all counted in.
 *
 * On a single site every test here is skipped, loudly: the single-site half is
 * ActivityLogTest (which runs on both) and SingleSiteTest.
 */

namespace Tests\Integration;

class NetworkLogTest extends IntegrationTestCase {

	/** @var array<int, int> Sites made for the test, deleted after it. */
	private array $sites = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: the single-site half is ActivityLogTest and SingleSiteTest.' );
		}

		diluxone_users_log_install();
		$this->truncate( diluxone_users_log_table() );

		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access', 'account', 'security' ) );

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			foreach ( $this->sites as $site ) {
				wp_delete_site( $site );
			}

			foreach ( array( DILUXONE_USERS_LOG_MOVING, DILUXONE_USERS_LOG_KEPT ) as $key ) {
				delete_site_option( $key );
			}

			update_site_option( DILUXONE_USERS_LOG_MOVED, 1 );
			wp_clear_scheduled_hook( DILUXONE_USERS_LOG_MOVE_EVENT );
			diluxone_users_log_install();
		}

		$this->sites = array();

		parent::tearDown();
	}

	/* ── Helpers ───────────────────────────────────────────────────── */

	/** One more site on the network, deleted when the test ends. */
	private function site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/log-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Log site ' . ( count( $this->sites ) + 1 ),
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	private function truncate( string $table ): void {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB
	}

	/** Writes a row on one site, the way the plugin does. */
	private function record_on( int $site, string $event, int $user = 0, array $detail = array() ): void {
		switch_to_blog( $site );
		$this->assertTrue( diluxone_users_log_record( $event, $user, $detail ), "A row on site {$site}" );
		restore_current_blog();
	}

	/** @return array<int, int> site id => rows, of the network's table. */
	private function per_site(): array {
		global $wpdb;

		$counts = array();

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT site_id, COUNT(*) AS n FROM %i GROUP BY site_id', diluxone_users_log_table() ) ) as $row ) {
			$counts[ (int) $row->site_id ] = (int) $row->n;
		}

		ksort( $counts );

		return $counts;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** Moves a row back in time. */
	private function age_all( int $days ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET happened = %s', diluxone_users_log_table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/** What a screen draws, as a string. */
	private function draw( callable $screen ): string {
		ob_start();
		$screen();

		return (string) ob_get_clean();
	}

	/** @return array<int, string> The site of every row a report drew. */
	private function drawn_sites( string $html ): array {
		preg_match_all( '/data-diluxone-users-site="(\d+)"/', $html, $found );

		return array_values( array_unique( $found[1] ) );
	}

	/**
	 * A site's log table as it was before the network's: the first shape, no
	 * site column, with rows in it.
	 */
	private function old_table( int $site, int $rows ): string {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query(
			"CREATE TABLE {$table} (
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

		$now    = gmdate( 'Y-m-d H:i:s' );
		$values = array();

		for ( $i = 0; $i < $rows; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s)', 1, 'signed_in', $now, '203.0.113.9', 'old', '{"via":"password"}' );
		}

		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (user_id, event, happened, ip, agent, detail) VALUES " . implode( ',', $chunk ) );
		}

		return $table;
	}

	/** A network that has not moved its sites' logs in yet. */
	private function not_moved(): void {
		delete_site_option( DILUXONE_USERS_LOG_MOVED );
		delete_site_option( DILUXONE_USERS_LOG_MOVING );
		delete_site_option( DILUXONE_USERS_LOG_KEPT );
	}

	/* ── One table, every row with its site ────────────────────────── */

	public function test_the_table_is_the_networks_and_every_row_carries_its_site(): void {
		global $wpdb;

		$one = $this->site();
		$two = $this->site();

		$this->assertSame( $wpdb->base_prefix . 'diluxone_users_log', diluxone_users_log_table() );

		switch_to_blog( $one );
		$this->assertSame( $wpdb->base_prefix . 'diluxone_users_log', diluxone_users_log_table(), 'The same table from any site' );
		restore_current_blog();

		$this->record_on( get_main_site_id(), 'signed_in', 1 );
		$this->record_on( $one, 'signed_in', 1 );
		$this->record_on( $one, 'signed_out', 1 );
		$this->record_on( $two, 'signed_in', 1 );

		$this->assertSame(
			array(
				get_main_site_id() => 1,
				$one               => 2,
				$two               => 1,
			),
			$this->per_site()
		);
	}

	public function test_the_table_has_the_indexes_a_sites_screen_reads_by(): void {
		global $wpdb;

		$keys = (array) $wpdb->get_col( 'SHOW INDEX FROM ' . diluxone_users_log_table(), 2 );

		$this->assertContains( 'site', $keys );
		$this->assertContains( 'site_when', $keys );
	}

	/* ── Who sees what ─────────────────────────────────────────────── */

	public function test_a_sites_report_shows_its_own_rows_and_nobody_elses(): void {
		$one = $this->site();
		$two = $this->site();

		$this->record_on( $one, 'signed_in', 1 );
		$this->record_on( $two, 'signed_in', 1 );
		$this->record_on( $two, 'signed_out', 1 );

		wp_set_current_user( 1 );

		switch_to_blog( $two );
		$html = $this->draw( 'diluxone_users_screen_log' );
		restore_current_blog();

		$this->assertSame( array( (string) $two ), $this->drawn_sites( $html ), 'Only its own rows' );
		$this->assertSame( 2, substr_count( $html, 'data-diluxone-users-event=' ) );
		$this->assertStringNotContainsString( 'name="site"', $html, 'And no way to ask for another site’s' );

		// Asked for another site's rows by address, it still shows its own.
		$_GET['site'] = (string) $one;

		switch_to_blog( $two );
		$html = $this->draw( 'diluxone_users_screen_log' );
		restore_current_blog();

		$this->assertSame( array( (string) $two ), $this->drawn_sites( $html ) );
	}

	public function test_the_networks_report_shows_every_site_and_narrows_to_one(): void {
		$one = $this->site();
		$two = $this->site();

		$this->record_on( $one, 'signed_in', 1 );
		$this->record_on( $two, 'signed_in', 1 );
		$this->record_on( $two, 'signed_out', 1 );

		wp_set_current_user( 1 );
		$this->in_network_admin();

		$html = $this->draw( 'diluxone_users_screen_log_network' );
		$all  = $this->drawn_sites( $html );
		sort( $all );

		$this->assertSame( array( (string) $one, (string) $two ), $all, 'Every site’s rows' );
		$this->assertStringContainsString( 'name="site"', $html, 'With a filter by site' );
		$this->assertStringContainsString( 'Log site 2', $html, 'And a column naming the site' );

		$_GET['site'] = (string) $one;
		$html         = $this->draw( 'diluxone_users_screen_log_network' );

		$this->assertSame( array( (string) $one ), $this->drawn_sites( $html ), 'Narrowed to one' );
		$this->assertSame( 1, diluxone_users_log_search( array( 'site' => $one ) )['total'] );
		$this->assertSame( 3, diluxone_users_log_search( array() )['total'] );
	}

	/* ── Emptying it ───────────────────────────────────────────────── */

	public function test_a_sites_button_empties_that_sites_rows_only(): void {
		$one = $this->site();
		$two = $this->site();

		$this->record_on( $one, 'signed_in', 1 );
		$this->record_on( $two, 'signed_in', 1 );

		wp_set_current_user( 1 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty' );

		switch_to_blog( $one );
		$url = $this->expectRedirect( 'diluxone_users_log_empty' );
		restore_current_blog();

		$this->assertSame( '1', $this->queryArg( $url, 'diluxone-users-emptied' ) );
		$this->assertSame( array( $two => 1 ), $this->per_site(), 'The other site keeps its rows' );
	}

	public function test_the_networks_button_empties_every_sites_rows(): void {
		$one = $this->site();
		$two = $this->site();

		$this->record_on( $one, 'signed_in', 1 );
		$this->record_on( $two, 'signed_in', 1 );
		$this->record_on( get_main_site_id(), 'signed_in', 1 );

		wp_set_current_user( 1 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty_network' );

		$url = $this->expectRedirect( 'diluxone_users_log_empty_network' );

		$this->assertSame( '3', $this->queryArg( $url, 'diluxone-users-emptied' ) );
		$this->assertStringStartsWith( network_admin_url(), $url, 'Back to Network Admin' );
		$this->assertSame( array(), $this->per_site() );
	}

	/** A site's administrator cannot empty the network's log, from any site. */
	public function test_the_networks_button_asks_for_the_network(): void {
		$one = $this->site();

		$this->record_on( $one, 'signed_in', 1 );

		$admin = $this->make_user();
		add_user_to_blog( $one, $admin, 'administrator' );

		switch_to_blog( $one );
		wp_set_current_user( $admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty_network' );

		try {
			diluxone_users_log_empty_network();
			$this->fail( 'It went through.' );
		} catch ( \WPAjaxDieContinueException $refused ) {
			$this->assertSame( array( $one => 1 ), $this->per_site() );
		} finally {
			restore_current_blog();
		}
	}

	/* ── Keeping it small ──────────────────────────────────────────── */

	public function test_the_networks_retention_is_one_purge_on_the_main_site_over_every_site(): void {
		$one = $this->site();
		$two = $this->site();

		$this->record_on( $one, 'signed_in', 1 );
		$this->record_on( $two, 'signed_in', 1 );
		$this->age_all( 200 );
		$this->record_on( $two, 'signed_out', 1 );

		diluxone_users_update_option( 'diluxone_users_log_days', 90 );

		switch_to_blog( $one );
		$this->assertSame( 0, diluxone_users_log_purge(), 'Another site of the network does not purge the network’s table' );
		restore_current_blog();

		$this->assertSame( 2, diluxone_users_log_purge(), 'The main site purges every site’s rows' );
		$this->assertSame( array( $two => 1 ), $this->per_site() );
	}

	public function test_the_daily_event_is_the_main_sites_alone(): void {
		$one = $this->site();

		switch_to_blog( $one );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', DILUXONE_USERS_LOG_PURGE );
		diluxone_users_log_ready();
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ), 'The event it had from its own table goes' );
		restore_current_blog();

		wp_clear_scheduled_hook( DILUXONE_USERS_LOG_PURGE );
		diluxone_users_log_ready();
		$this->assertNotFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
	}

	/* ── Somebody's data ───────────────────────────────────────────── */

	public function test_erasing_a_person_takes_their_rows_from_every_site_in_one_query(): void {
		global $wpdb;

		$one    = $this->site();
		$two    = $this->site();
		$person = get_userdata( $this->make_user() );
		$other  = $this->make_user();

		$this->record_on( $one, 'signed_in', (int) $person->ID );
		$this->record_on( $two, 'signed_in', (int) $person->ID );
		$this->record_on( $two, 'signed_in', $other );

		$export = diluxone_users_log_export( $person->user_email );
		$this->assertCount( 2, $export['data'], 'The export has every site’s rows' );
		$this->assertContains( 'Log site 1', array_column( $export['data'][0]['data'], 'value' ), 'Each naming its site' );

		$deletes = array();
		$watch   = static function ( $sql ) use ( &$deletes ) {
			if ( 0 === stripos( ltrim( (string) $sql ), 'DELETE' ) && false !== strpos( (string) $sql, 'diluxone_users_log' ) ) {
				$deletes[] = (string) $sql;
			}

			return $sql;
		};

		add_filter( 'query', $watch );
		$answer = diluxone_users_log_erase( $person->user_email );
		remove_filter( 'query', $watch );

		$this->assertTrue( $answer['items_removed'] );
		$this->assertCount( 1, $deletes, 'One query for the whole network' );
		$this->assertSame( array( $two => 1 ), $this->per_site(), 'Everybody else’s rows stay' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', diluxone_users_log_table(), $person->ID ) ) );
	}

	/* ── One table, several networks ───────────────────────────────── */

	/**
	 * A second network of the installation, with one site, made as rows of
	 * WordPress's own tables: the log only asks which network a site belongs
	 * to, and the site needs no tables of its own for that.
	 *
	 * @return array{0: int, 1: int} The network's id and its site's.
	 */
	private function another_network(): array {
		global $wpdb;

		$wpdb->insert(
			$wpdb->site,
			array(
				'domain' => 'far.example.test',
				'path'   => '/',
			)
		);
		$network = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->blogs,
			array(
				'site_id'    => $network,
				'domain'     => 'far.example.test',
				'path'       => '/',
				'registered' => current_time( 'mysql', true ),
			)
		);
		$site = (int) $wpdb->insert_id;

		wp_cache_set_sites_last_changed();

		$this->far = array( $network, $site );

		return $this->far;
	}

	/** @var array<int, int> The other network and its site, removed after the test. */
	private array $far = array();

	/** Rows written on another network's site, the way that network would. */
	private function rows_on_another_network( int $site, int $user, int $rows ): void {
		global $wpdb;

		for ( $i = 0; $i < $rows; $i++ ) {
			$wpdb->insert(
				diluxone_users_log_table(),
				array(
					'site_id'  => $site,
					'user_id'  => $user,
					'event'    => 'signed_in',
					'happened' => gmdate( 'Y-m-d H:i:s' ),
					'detail'   => '{}',
				)
			);
		}
	}

	private function forget_another_network(): void {
		global $wpdb;

		if ( array() === $this->far ) {
			return;
		}

		$wpdb->delete( $wpdb->blogs, array( 'blog_id' => $this->far[1] ) );
		$wpdb->delete( $wpdb->site, array( 'id' => $this->far[0] ) );
		wp_cache_set_sites_last_changed();

		$this->far = array();
	}

	/**
	 * One table serves every network of the installation: this network's
	 * report, its "Empty it now" and its purge touch its own sites' rows and
	 * never another network's.
	 */
	public function test_the_network_reads_empties_and_purges_its_own_sites_rows_only(): void {
		$one            = $this->site();
		list( , $far )  = $this->another_network();

		try {
			$this->record_on( $one, 'signed_in', 1 );
			$this->rows_on_another_network( $far, 1, 2 );

			$this->assertNotContains( $far, diluxone_users_log_network_sites() );
			$this->assertContains( $one, diluxone_users_log_network_sites() );

			// The report.
			wp_set_current_user( 1 );
			$this->in_network_admin();

			$drawn = $this->drawn_sites( $this->draw( 'diluxone_users_screen_log_network' ) );
			$this->assertContains( (string) $one, $drawn );
			$this->assertNotContains( (string) $far, $drawn, 'Another network’s rows are not on this network’s report' );

			$_GET['site'] = (string) $far;
			$this->assertSame( array(), $this->drawn_sites( $this->draw( 'diluxone_users_screen_log_network' ) ), 'Not even asked for by address' );
			$_GET = array();

			$this->assertSame( 1, diluxone_users_log_size( diluxone_users_log_network_sites() )['rows'] );

			// The purge.
			$this->age_all( 200 );
			diluxone_users_update_option( 'diluxone_users_log_days', 90 );

			$this->assertSame( 1, diluxone_users_log_purge(), 'Its own old row' );
			$this->assertSame( array( $far => 2 ), $this->per_site(), 'Another network’s old rows are its own purge’s to take' );

			// The button.
			$this->record_on( $one, 'signed_in', 1 );
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty_network' );

			$url = $this->expectRedirect( 'diluxone_users_log_empty_network' );

			$this->assertSame( '1', $this->queryArg( $url, 'diluxone-users-emptied' ) );
			$this->assertSame( array( $far => 2 ), $this->per_site(), 'Another network’s rows stay' );
		} finally {
			$this->forget_another_network();
		}
	}

	/** A person is the installation's: erasing them reaches every network's rows. */
	public function test_erasing_a_person_reaches_every_network(): void {
		$one           = $this->site();
		list( , $far ) = $this->another_network();
		$person        = get_userdata( $this->make_user() );

		try {
			$this->record_on( $one, 'signed_in', (int) $person->ID );
			$this->rows_on_another_network( $far, (int) $person->ID, 2 );
			$this->rows_on_another_network( $far, 1, 1 );

			diluxone_users_log_erase( $person->user_email );

			$this->assertSame( array( $far => 1 ), $this->per_site(), 'Only somebody else’s row is left, on the other network' );
		} finally {
			$this->forget_another_network();
		}
	}

	/* ── The move of each site's old table ─────────────────────────── */

	/**
	 * A network that ran with a log per site: every old table's rows arrive
	 * with their site, the first site's own rows (already in the network's
	 * table, its prefix being the network's) are stamped with it, the old
	 * tables go, and running it again changes nothing.
	 */
	public function test_the_sites_old_tables_move_in_with_their_site_and_go(): void {
		global $wpdb;

		$one = $this->site();
		$two = $this->site();

		$old_one = $this->old_table( $one, 3 );
		$old_two = $this->old_table( $two, 2 );

		// The first site's rows from before the column existed.
		$wpdb->insert(
			diluxone_users_log_table(),
			array(
				'site_id'  => 0,
				'user_id'  => 1,
				'event'    => 'signed_in',
				'happened' => gmdate( 'Y-m-d H:i:s' ),
				'detail'   => '{}',
			)
		);
		diluxone_users_delete_option( DILUXONE_USERS_LOG_SCHEMA_OPTION );

		$this->not_moved();

		$said = array();

		$this->assertTrue(
			diluxone_users_log_move(
				DILUXONE_USERS_LOG_MOVE_ROWS,
				static function ( int $site, int $copied, bool $dropped ) use ( &$said ): void {
					$said[ $site ] = array( $copied, $dropped );
				}
			)
		);

		$this->assertSame(
			array(
				1    => 1,
				$one => 3,
				$two => 2,
			),
			$this->per_site()
		);
		$this->assertSame(
			array(
				$one => array( 3, true ),
				$two => array( 2, true ),
			),
			$said
		);
		$this->assertFalse( $this->table_exists( $old_one ), 'Each old table goes' );
		$this->assertFalse( $this->table_exists( $old_two ) );
		$this->assertTrue( diluxone_users_log_moved() );
		$this->assertSame( DILUXONE_USERS_LOG_SCHEMA, (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION ) );

		// Again: nothing to do, nothing doubled.
		$this->assertTrue( diluxone_users_log_move() );
		$this->not_moved();
		$this->assertTrue( diluxone_users_log_move() );
		$this->assertSame( 6, array_sum( $this->per_site() ) );
	}

	/** A big table goes in batches: the first here, the rest from cron. */
	public function test_a_big_table_moves_in_batches_and_carries_on_from_cron(): void {
		$one = $this->site();
		$old = $this->old_table( $one, 1200 );

		$this->not_moved();

		$this->assertFalse( diluxone_users_log_move( 500 ), 'Not all of it in one go' );
		$this->assertSame( array( $one => 500 ), $this->per_site() );
		$this->assertNotFalse( wp_next_scheduled( DILUXONE_USERS_LOG_MOVE_EVENT ), 'The rest is left to cron' );
		$this->assertTrue( $this->table_exists( $old ), 'The old table stays until every row is in' );

		$this->assertFalse( diluxone_users_log_move( 500 ) );
		$this->assertTrue( diluxone_users_log_move( 500 ) );

		$this->assertSame( array( $one => 1200 ), $this->per_site() );
		$this->assertFalse( $this->table_exists( $old ) );
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_MOVE_EVENT ), 'And cron has nothing left' );
	}

	/**
	 * A batch the database refuses halfway leaves nothing behind — neither
	 * rows nor the note that it was copied — and the next run copies it once.
	 */
	public function test_a_batch_that_fails_is_copied_again_and_never_twice(): void {
		$one = $this->site();
		$old = $this->old_table( $one, 30 );

		$this->not_moved();

		$this->assertFalse( diluxone_users_log_move( 10 ) );
		$this->assertSame( array( $one => 10 ), $this->per_site() );

		// The note of how far it got is refused, after the rows went in.
		$break = static function ( $sql ) {
			return false !== strpos( (string) $sql, DILUXONE_USERS_LOG_MOVING ) && 0 === stripos( ltrim( (string) $sql ), 'UPDATE' )
				? 'SELECT broken FROM nowhere'
				: $sql;
		};

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		wp_cache_flush();
		$this->assertFalse( diluxone_users_log_move( 10 ) );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );
		wp_cache_flush();

		$this->assertSame( array( $one => 10 ), $this->per_site(), 'The batch that failed left no rows' );

		while ( ! diluxone_users_log_move( 10 ) ) {
			// The rest, batch by batch.
		}

		$this->assertSame( array( $one => 30 ), $this->per_site(), 'Every row once' );
		$this->assertFalse( $this->table_exists( $old ) );
	}

	/** An old table whose rows did not all arrive stays, and is written down. */
	public function test_an_old_table_whose_rows_do_not_add_up_is_kept(): void {
		$one = $this->site();
		$old = $this->old_table( $one, 4 );

		$this->assertFalse( diluxone_users_log_move_close( $one, $old, 3 ) );
		$this->assertTrue( $this->table_exists( $old ) );
		$this->assertSame( 4, (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_KEPT, array() )[ $one ]['had'] );

		$this->assertTrue( diluxone_users_log_move_close( $one, $old, 4 ), 'The same count, and it goes' );
		$this->assertFalse( $this->table_exists( $old ) );
	}

	/** The first request after the plugin is on for the network starts it. */
	public function test_the_first_request_starts_the_move_and_cron_is_not_asked_twice(): void {
		$one = $this->site();
		$this->old_table( $one, 3 );

		$this->not_moved();

		diluxone_users_network_migrate_when_needed();

		$this->assertTrue( diluxone_users_log_moved() );
		$this->assertSame( array( $one => 3 ), $this->per_site() );
	}
}
