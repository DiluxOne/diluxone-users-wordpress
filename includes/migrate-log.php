<?php
/**
 * The move of each site's activity log into the network's table.
 *
 * Before the log was one table for the whole network, every site of a network
 * kept its own, `{prefix}diluxone_users_log`. Once the plugin is on for every
 * site the rows are written to one table under the network's prefix, each
 * stamped with its site (see log.php) — and on a network that has been running
 * for a while every site still has its history in a table of its own. This is
 * what brings it in.
 *
 * Site by site, in the order the network numbered them, a few thousand rows at
 * a time: the first batch on the first request that needs it, the rest from
 * cron, or all of it at once with `wp diluxone-users network migrate`. Each
 * batch is copied and written down as copied in one transaction, so a batch
 * interrupted anywhere is a batch not copied, and a run picks up where the last
 * one stopped. A site's old table is dropped only once every row it had is
 * counted in the network's; if the counts do not match, the table stays, it is
 * written down, and nothing is lost.
 *
 * The first site's table needs no move: its prefix is the network's, so its
 * table already is the network's, and its rows are stamped with it when the
 * table takes its new shape (diluxone_users_log_claim_unstamped()).
 *
 * This file and uninstall.php are, with migrate.php, the only places that read
 * the plugin's own settings with the options API directly: a move has to know
 * where it stands even while the plugin is being switched off.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Which shape the move of the sites' logs was last finished for. */
const DILUXONE_USERS_LOG_MOVED = 'diluxone_users_log_moved';

/** Where the move is, while it runs in batches. */
const DILUXONE_USERS_LOG_MOVING = 'diluxone_users_log_moving';

/** The sites whose old table was kept, because its rows did not all arrive. */
const DILUXONE_USERS_LOG_KEPT = 'diluxone_users_log_kept';

/** The cron event that carries on with a big move. */
const DILUXONE_USERS_LOG_MOVE_EVENT = 'diluxone_users_log_move';

/** How many rows one batch copies. */
const DILUXONE_USERS_LOG_MOVE_ROWS = 5000;

/** Whether every site's old log is already in the network's table. */
function diluxone_users_log_moved(): bool {
	return (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_MOVED, 0 ) >= 1;
}

/**
 * The table a site kept before the network's.
 *
 * @param int $site A site of the network.
 */
function diluxone_users_log_old_table( int $site ): string {
	global $wpdb;

	return $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';
}

/**
 * Whether a table is in the database.
 *
 * @param string $table A table name.
 */
function diluxone_users_log_old_table_exists( string $table ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check; there is no API for it and caching it would defeat the point.
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

/**
 * The next site of this network after the one given, by id.
 *
 * By id and not by position: a site deleted while the move runs would shift
 * every position after it, and the site that took its place would be skipped.
 *
 * @param int $after A site id, or 0 to start.
 * @return int The next site, or 0 when there is none.
 */
function diluxone_users_log_move_next_site( int $after ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress's own list of sites, read in order from where the move stopped; get_sites() has no "after this id".
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT 1",
			get_current_network_id(),
			$after
		)
	);
}

/**
 * Moves the sites' old logs in, a batch of rows at a time.
 *
 * @param int           $rows How many rows to copy in this call, at most.
 * @param callable|null $said Called with ($site, $copied, $dropped) as each site is finished.
 * @return bool True when the move is finished, false when rows are left.
 */
function diluxone_users_log_move( int $rows = DILUXONE_USERS_LOG_MOVE_ROWS, ?callable $said = null ): bool {
	if ( ! diluxone_users_log_network() || diluxone_users_log_moved() ) {
		return true;
	}

	// The table the rows go to, first: on a network that has just switched
	// the plugin on for everybody, this is the request that makes it.
	if ( DILUXONE_USERS_LOG_SCHEMA !== (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION ) && ! diluxone_users_log_install() ) {
		return false;
	}

	$state = diluxone_users_raw_get( DILUXONE_USERS_LOG_MOVING, null );
	$state = is_array( $state ) ? $state : array();
	$state = array_merge(
		array(
			'after'  => 0,
			'site'   => 0,
			'from'   => 0,
			'copied' => 0,
		),
		$state
	);

	$budget = max( 1, $rows );

	// Sites with nothing to bring cost a question each, and a network of
	// thousands of them is not asked all at once either.
	$sites = DILUXONE_USERS_NETWORK_BATCH;

	while ( $budget > 0 && $sites > 0 ) {
		if ( 0 === (int) $state['site'] ) {
			$next = diluxone_users_log_move_next_site( (int) $state['after'] );

			if ( 0 === $next ) {
				diluxone_users_delete_option( DILUXONE_USERS_LOG_MOVING );
				wp_clear_scheduled_hook( DILUXONE_USERS_LOG_MOVE_EVENT );

				// Last, so that a move interrupted anywhere above is a move not done.
				diluxone_users_update_option( DILUXONE_USERS_LOG_MOVED, 1 );

				return true;
			}

			$state['site']   = $next;
			$state['from']   = 0;
			$state['copied'] = 0;
		}

		$site = (int) $state['site'];
		$old  = diluxone_users_log_old_table( $site );

		// The first site's table is the network's; a site that never had a
		// table has nothing to bring.
		if ( diluxone_users_log_table() === $old || ! diluxone_users_log_old_table_exists( $old ) ) {
			--$sites;
			$state = diluxone_users_log_move_site_done( $state );
			diluxone_users_update_option( DILUXONE_USERS_LOG_MOVING, $state );
			continue;
		}

		$ask    = min( $budget, DILUXONE_USERS_LOG_MOVE_ROWS );
		$copied = diluxone_users_log_move_batch( $site, $old, $state, $ask );

		if ( $copied < 0 ) {
			// The database refused the batch: nothing of it was kept, and the
			// next run tries it again.
			diluxone_users_log_move_later();

			return false;
		}

		$budget -= max( 1, $copied );

		if ( $copied < $ask ) {
			$dropped = diluxone_users_log_move_close( $site, $old, (int) $state['copied'] );

			if ( null !== $said ) {
				$said( $site, (int) $state['copied'], $dropped );
			}

			--$sites;
			$state = diluxone_users_log_move_site_done( $state );
			diluxone_users_update_option( DILUXONE_USERS_LOG_MOVING, $state );
		}
	}

	diluxone_users_log_move_later();

	return false;
}

/**
 * The state once a site is finished: the next one starts after it.
 *
 * @param array<string, int> $state Where the move is.
 * @return array<string, int>
 */
function diluxone_users_log_move_site_done( array $state ): array {
	$state['after']  = (int) $state['site'];
	$state['site']   = 0;
	$state['from']   = 0;
	$state['copied'] = 0;

	return $state;
}

/**
 * Copies one batch of one site's rows, and writes down that it did, together.
 *
 * The batch is the next rows by id after the last one copied. The copy and the
 * note of how far it got are one transaction: if either fails, neither
 * happened, and the same batch is copied again next time — never twice.
 *
 * @param int                $site  The site the rows are from.
 * @param string             $old   Its old table.
 * @param array<string, int> $state Where the move is; updated on success.
 * @param int                $rows  How many rows, at most.
 * @return int How many rows were copied, or -1 when the database refused.
 */
function diluxone_users_log_move_batch( int $site, string $old, array &$state, int $rows ): int {
	global $wpdb;

	$from = (int) $state['from'];

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables, copied once; nothing here is worth caching.
	$upto = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT MAX(id) FROM ( SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d ) AS batch',
			$old,
			$from,
			max( 1, $rows )
		)
	);

	if ( 0 === $upto ) {
		return 0;
	}

	$wpdb->query( 'START TRANSACTION' );

	$copied = $wpdb->query(
		$wpdb->prepare(
			'INSERT INTO %i (site_id, user_id, event, happened, ip, agent, detail)
			 SELECT %d, user_id, event, happened, ip, agent, detail
			   FROM %i
			  WHERE id > %d AND id <= %d
		   ORDER BY id ASC',
			diluxone_users_log_table(),
			$site,
			$old,
			$from,
			$upto
		)
	);

	if ( false === $copied ) {
		$wpdb->query( 'ROLLBACK' );

		return -1;
	}

	$next           = $state;
	$next['from']   = $upto;
	$next['copied'] = (int) $state['copied'] + (int) $copied;

	if ( ! diluxone_users_update_option( DILUXONE_USERS_LOG_MOVING, $next ) ) {
		$wpdb->query( 'ROLLBACK' );

		return -1;
	}

	$wpdb->query( 'COMMIT' );
	// phpcs:enable

	$state = $next;

	return (int) $copied;
}

/**
 * A site whose rows are all copied: its old table goes, if every row arrived.
 *
 * "Arrived" is counted, not assumed: the rows the move copied, against the rows
 * the old table has. The same number, and the old table is dropped; any other,
 * and it stays where it is and is written down, and the rows that did arrive
 * stay too — the report shows them, and nothing was lost.
 *
 * @param int    $site   The site.
 * @param string $old    Its old table.
 * @param int    $copied How many rows the move copied from it.
 * @return bool Whether the old table was dropped.
 */
function diluxone_users_log_move_close( int $site, string $old, int $copied ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the old table's rows, counted now.
	$had = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old ) );

	if ( $had !== $copied ) {
		$kept          = (array) diluxone_users_raw_get( DILUXONE_USERS_LOG_KEPT, array() );
		$kept[ $site ] = array(
			'table'  => $old,
			'had'    => $had,
			'copied' => $copied,
		);

		diluxone_users_update_option( DILUXONE_USERS_LOG_KEPT, $kept );

		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own old table, every row of which is now in the network's.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );

	return true;
}

/** The next batch, a minute from now. */
function diluxone_users_log_move_later(): void {
	if ( ! wp_next_scheduled( DILUXONE_USERS_LOG_MOVE_EVENT ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, DILUXONE_USERS_LOG_MOVE_EVENT );
	}
}

/**
 * The next batches, from cron.
 *
 * Ten of them in one run: cron is not somebody's page load, and a network
 * with a million rows would otherwise take a batch a minute for three hours.
 */
function diluxone_users_log_move_run(): void {
	for ( $run = 0; $run < 10; $run++ ) {
		if ( diluxone_users_log_move() ) {
			return;
		}
	}
}
add_action( DILUXONE_USERS_LOG_MOVE_EVENT, 'diluxone_users_log_move_run' );

/**
 * Starts the move on the first request that needs it.
 *
 * Called by the network's own move (migrate.php), after the settings: the
 * log's retention is one of them. One batch here, which on most networks is
 * the whole move, and the rest from cron. Under WP-CLI it is the command that
 * moves it, to the end and saying what it found.
 */
function diluxone_users_log_move_when_needed(): void {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! diluxone_users_log_network() || diluxone_users_log_moved() ) {
		return;
	}

	if ( is_array( diluxone_users_raw_get( DILUXONE_USERS_LOG_MOVING, null ) ) && wp_next_scheduled( DILUXONE_USERS_LOG_MOVE_EVENT ) ) {
		return;
	}

	diluxone_users_log_move();
}
