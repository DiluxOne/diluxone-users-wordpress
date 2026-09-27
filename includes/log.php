<?php
/**
 * What happened on this site, written down.
 *
 * Everything else in this plugin knows what is true right now: who has a
 * session open, which networks are linked, whether the second step is on. None
 * of it knows what happened yesterday. "Somebody changed my e-mail address and
 * I did not" is a question about the past, and until now the honest answer was
 * that the site did not keep one.
 *
 * So there is a table, and the site decides what goes in it. Three decisions
 * shaped the whole file and none of them is an accident:
 *
 *   - A table of its own, not the options and not the user meta. This grows
 *     without limit by nature, and anything autoloaded that grows without
 *     limit is a site that gets slower every day for a reason nobody finds.
 *   - Out of the box it records the way in and the way out, and nothing else.
 *     A plugin that starts writing a row for every change a person makes to
 *     their profile has made a decision about somebody's disk and about
 *     somebody's privacy that was not its to make. The rest is ticked by hand,
 *     by whoever will be paying for the rows.
 *   - The screen says how big it is, in rows and in bytes, read from the
 *     database. "This may grow" is a sentence anybody can ignore; "1.284.902
 *     rows, 412 MB" is not.
 *
 * The purge is the other half of that bargain. A log with no end is a disk
 * that fills up, so the site says how long it keeps one and a daily event
 * drops what is older. Set to zero it keeps everything, which is a real answer
 * for a site that has to, and the screen says what that costs.
 *
 * ── About the queries ──
 *
 * Every query in this file goes to the plugin's own table, whose name is
 * built here out of `$wpdb->prefix` and a literal, and reaches the query as a
 * `%i` identifier placeholder like any other value. None is cached: a log is
 * written once and read from an admin screen that is asking what is true this
 * second, which is what each query's annotation says.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shape of the table, as a number.
 *
 * It is bumped when a column or an index changes, and the installer runs again
 * on any site whose stored number is not this one. It is deliberately not the
 * plugin version: the table changes far less often than the plugin does, and
 * running dbDelta on every release to discover there is nothing to do is work
 * every site pays for and nobody asked for.
 */
const DILUXONE_USERS_LOG_SCHEMA = 1;

/** Where the stored number lives. */
const DILUXONE_USERS_LOG_SCHEMA_OPTION = 'diluxone_users_log_schema';

/** The daily event that drops what is too old to keep. */
const DILUXONE_USERS_LOG_PURGE = 'diluxone_users_log_purge';

/**
 * The table's name on this site.
 *
 * `$wpdb->prefix` and not `$wpdb->base_prefix`: on a network each site keeps
 * its own log, which is the same answer WordPress gives for posts and comments
 * and the only one that makes sense for a site administrator who can only see
 * their own site.
 */
function diluxone_users_log_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'diluxone_users_log';
}

/**
 * Creates the table, or brings it up to the current shape.
 *
 * The statement is written the way dbDelta wants it and not the way that reads
 * best, and the difference is not cosmetic: dbDelta is fussy in ways that are
 * not obvious and that fail silently — two spaces after PRIMARY KEY, one column
 * per line, the key name repeated in the KEY clause. The alternative is a site
 * that looks installed and has no index.
 *
 * The indexes are the ones the screen actually asks for: by person, because
 * "what happened to this account" is the question the log exists to answer;
 * by date, because the purge and the filters both walk it; and by event,
 * because the filter for one kind of thing is the other half of the first
 * question.
 *
 * @return bool True when the table is in place afterwards. On false the schema
 *              option stays unwritten, so the next admin request tries again.
 */
function diluxone_users_log_install(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = diluxone_users_log_table();
	$collate = $wpdb->get_charset_collate();

	// dbDelta() reads the statement as text and cannot take placeholders; the
	// name is `$wpdb->prefix` and a literal, from the function above.
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		event varchar(32) NOT NULL DEFAULT '',
		happened datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		ip varchar(45) NOT NULL DEFAULT '',
		agent varchar(255) NOT NULL DEFAULT '',
		detail text NOT NULL,
		PRIMARY KEY  (id),
		KEY person (user_id,id),
		KEY when_it (happened),
		KEY kind (event,id)
	) {$collate};";

	dbDelta( $sql );

	// dbDelta says nothing about failure: it returns the same empty array when
	// the table is already current and when the statement was refused. Ask the
	// database instead. Recording the schema version after a failed run would
	// mean never trying again, and every write to the log would land on a
	// table that is not there.
	if ( ! diluxone_users_log_table_exists() ) {
		return false;
	}

	update_option( DILUXONE_USERS_LOG_SCHEMA_OPTION, DILUXONE_USERS_LOG_SCHEMA, false );

	return true;
}

/**
 * Whether the activity table is actually in the database.
 *
 * @return bool
 */
function diluxone_users_log_table_exists(): bool {
	global $wpdb;

	$table = diluxone_users_log_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check; there is no API for it and caching it would defeat the point.
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

/**
 * The table and the daily purge, both there and both current.
 *
 * On `admin_init` and not only on activation, for the same reason the prefix
 * migration is: an update over FTP or over git fires no activation hook, and a
 * site that updated that way would be writing into a table that is a version
 * behind. The check is one option read, and the option does not autoload, so
 * the cost of asking is a cached query on dashboard requests only.
 */
function diluxone_users_log_ready(): void {
	if ( DILUXONE_USERS_LOG_SCHEMA !== (int) get_option( DILUXONE_USERS_LOG_SCHEMA_OPTION ) ) {
		diluxone_users_log_install();
	}

	if ( ! wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', DILUXONE_USERS_LOG_PURGE );
	}
}
add_action( 'admin_init', 'diluxone_users_log_ready', 0 );

/*
 * The table is created on activation, for every site of a network when the
 * activation is network-wide, and for a site born afterwards when it is born:
 * see diluxone_users_site_setup(). The check above stays as the safety net for
 * a site whose table went missing some other way.
 */

/**
 * A plugin that is switched off leaves no event of its own behind — on every
 * site it was switched off for.
 *
 * @param bool $network_wide Whether it was deactivated for the whole network.
 */
function diluxone_users_log_unschedule( $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site ) {
			switch_to_blog( (int) $site );
			wp_clear_scheduled_hook( DILUXONE_USERS_LOG_PURGE );
			restore_current_blog();
		}

		return;
	}

	wp_clear_scheduled_hook( DILUXONE_USERS_LOG_PURGE );
}
register_deactivation_hook( DILUXONE_USERS_FILE, 'diluxone_users_log_unschedule' );

/**
 * A site deleted from the network takes its table with it.
 *
 * WordPress drops the tables it knows about and asks for the rest here.
 *
 * @param array<int, string> $tables  The tables WordPress is about to drop.
 * @param int                $site_id The site being deleted.
 * @return array<int, string>
 */
function diluxone_users_log_drop_with_site( $tables, $site_id ): array {
	global $wpdb;

	$tables   = (array) $tables;
	$tables[] = $wpdb->get_blog_prefix( (int) $site_id ) . 'diluxone_users_log';

	return $tables;
}
add_filter( 'wpmu_drop_tables', 'diluxone_users_log_drop_with_site', 10, 2 );

/* ── What there is to record ───────────────────────────────────────── */

/**
 * The three groups, each a tick box on the settings tab.
 *
 * They are groups and not thirteen separate switches because nobody wants to
 * answer thirteen questions about a log: what a site decides is whether it
 * cares about the door, about what people change in their account, or about
 * what they change in their security. `writes` is the half of the answer that
 * is usually missing — roughly how many rows this group costs — and it is said
 * per group because that is the unit somebody is ticking.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_log_groups(): array {
	return array(
		'access'   => array(
			'label'  => __( 'Ways in and out', 'diluxone-users' ),
			'help'   => __( 'Every sign-in, every sign-out, and every attempt that was refused.', 'diluxone-users' ),
			'writes' => __( 'A few rows per person per day: this is the group that grows with how often people come back.', 'diluxone-users' ),
		),
		'account'  => array(
			'label'  => __( 'Changes to the account', 'diluxone-users' ),
			'help'   => __( 'The e-mail address, the password, the public name, and the details this site asks people for.', 'diluxone-users' ),
			'writes' => __( 'Almost nothing: most people change these once and never again.', 'diluxone-users' ),
		),
		'security' => array(
			'label'  => __( 'Changes to the security', 'diluxone-users' ),
			'help'   => __( 'Two-step verification turned on or off, a passkey added or removed, sessions closed.', 'diluxone-users' ),
			'writes' => __( 'Almost nothing, and it is the group worth having when an account is taken over.', 'diluxone-users' ),
		),
	);
}

/**
 * Every event this plugin knows how to write, and the group it belongs to.
 *
 * The map is the whole rule: an event that is not in it is never written, and
 * an event whose group is not ticked is never written either. Both answers are
 * decided here, by `diluxone_users_log_records()`, and nowhere else — the
 * places that report an event just report it, and none of them carries a copy
 * of the policy.
 *
 * @return array<string, string>
 */
function diluxone_users_log_events(): array {
	return array(
		'signed_in'        => 'access',
		'signed_out'       => 'access',
		'sign_in_failed'   => 'access',
		'2fa_failed'       => 'access',
		'email_changed'    => 'account',
		'password_changed' => 'account',
		'name_changed'     => 'account',
		'profile_saved'    => 'account',
		'2fa_on'           => 'security',
		'2fa_off'          => 'security',
		'passkey_added'    => 'security',
		'passkey_removed'  => 'security',
		'sessions_closed'  => 'security',
	);
}

/**
 * What each event is called on screen.
 *
 * Separate from the map above because the map is policy and this is wording:
 * a translator reads this list and nothing else, and a new event added to the
 * map with no sentence here falls back to its own slug rather than to nothing.
 *
 * @return array<string, string>
 */
function diluxone_users_log_labels(): array {
	return array(
		'signed_in'        => __( 'Signed in', 'diluxone-users' ),
		'signed_out'       => __( 'Signed out', 'diluxone-users' ),
		'sign_in_failed'   => __( 'Sign-in refused', 'diluxone-users' ),
		'2fa_failed'       => __( 'Second step refused', 'diluxone-users' ),
		'email_changed'    => __( 'E-mail address changed', 'diluxone-users' ),
		'password_changed' => __( 'Password changed', 'diluxone-users' ),
		'name_changed'     => __( 'Public name changed', 'diluxone-users' ),
		'profile_saved'    => __( 'Details saved', 'diluxone-users' ),
		'2fa_on'           => __( 'Two-step verification turned on', 'diluxone-users' ),
		'2fa_off'          => __( 'Two-step verification turned off', 'diluxone-users' ),
		'passkey_added'    => __( 'Passkey added', 'diluxone-users' ),
		'passkey_removed'  => __( 'Passkey removed', 'diluxone-users' ),
		'sessions_closed'  => __( 'Sessions closed', 'diluxone-users' ),
	);
}

/** One event's name, or its slug when nobody has written one. */
function diluxone_users_log_label( string $event ): string {
	$labels = diluxone_users_log_labels();

	return (string) ( $labels[ $event ] ?? $event );
}

/**
 * The groups this site is recording, read defensively.
 *
 * Whatever is stored is filtered against the groups that exist: a site that
 * came from an option written by hand, or from a group this plugin no longer
 * has, gets the groups it really has and not a warning. An option that is not
 * a list at all counts as nothing ticked, which is the safe way round — a
 * broken option must not start writing rows nobody asked for.
 *
 * @return array<int, string>
 */
function diluxone_users_log_levels(): array {
	$stored = diluxone_users_option( 'diluxone_users_log_levels' );
	$groups = diluxone_users_log_groups();
	$on     = array();

	foreach ( is_array( $stored ) ? $stored : array() as $group ) {
		if ( is_string( $group ) && isset( $groups[ $group ] ) ) {
			$on[] = $group;
		}
	}

	return array_values( array_unique( $on ) );
}

/**
 * Is this event one this site writes down?
 *
 * The one question the whole feature turns on, and the reason it is a function
 * of its own rather than three lines inside the writer: it is answerable
 * without a database, which is what lets a test ask it about every event and
 * every combination of groups in a millisecond.
 */
function diluxone_users_log_records( string $event ): bool {
	$events = diluxone_users_log_events();

	if ( ! isset( $events[ $event ] ) ) {
		return false;
	}

	/**
	 * Filters whether one event is recorded.
	 *
	 * The tick boxes decide first; this is for the site that wants one event
	 * out of a group it otherwise keeps — a shop that logs everything except
	 * the sign-in of the account its own cron runs as.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $records Whether the group it belongs to is ticked.
	 * @param string $event   The event's slug.
	 * @param string $group   The group it belongs to.
	 */
	return (bool) apply_filters(
		'diluxone_users_log_records',
		in_array( $events[ $event ], diluxone_users_log_levels(), true ),
		$event,
		$events[ $event ]
	);
}

/* ── Writing ───────────────────────────────────────────────────────── */

/**
 * Writes one row, if this site records this event.
 *
 * Everything that reports an event calls this and asks nothing first: the
 * policy lives in one place, and a caller that had to check would be a caller
 * that can get it wrong.
 *
 * The address comes from `diluxone_users_client_ip()`, which is the one that
 * knows about the proxy settings — a log full of the load balancer's address
 * is a log of nothing. The user agent is cut to what the column holds rather
 * than letting MySQL cut it, because in strict mode MySQL does not cut it, it
 * refuses the whole row.
 *
 * @param string               $event   One of diluxone_users_log_events().
 * @param int                  $user_id Whose account it is about, or 0 when nobody is known.
 * @param array<string, mixed> $detail  What else is worth keeping, as scalars.
 * @return bool Whether a row was written.
 */
function diluxone_users_log_record( string $event, int $user_id = 0, array $detail = array() ): bool {
	global $wpdb;

	if ( ! diluxone_users_log_records( $event ) ) {
		return false;
	}

	// A site whose table is not there yet (or went missing) records nothing
	// rather than a database error on somebody's sign-in: the dashboard's
	// check puts the table back the next time an administrator passes by.
	if ( DILUXONE_USERS_LOG_SCHEMA !== (int) get_option( DILUXONE_USERS_LOG_SCHEMA_OPTION ) ) {
		return false;
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table, written once per event.
	$written = $wpdb->insert(
		diluxone_users_log_table(),
		array(
			'user_id'  => max( 0, $user_id ),
			'event'    => substr( $event, 0, 32 ),
			'happened' => (string) current_time( 'mysql', true ),
			'ip'       => substr( diluxone_users_client_ip(), 0, 45 ),
			'agent'    => substr( $agent, 0, 255 ),
			'detail'   => (string) wp_json_encode( diluxone_users_log_detail( $detail ) ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	return false !== $written;
}

/**
 * What a detail is allowed to be.
 *
 * Scalars, and nothing else. The temptation with a free-form column is to drop
 * a whole object in it "just in case", and what that buys is a log holding a
 * password hash, a session token or somebody's full profile, kept for ninety
 * days, exported to anybody who asks for their data. The callers pass what
 * they mean to keep, and this makes sure that is all that arrives.
 *
 * @param array<string, mixed> $detail
 * @return array<string, string>
 */
function diluxone_users_log_detail( array $detail ): array {
	$clean = array();

	foreach ( $detail as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
		}
	}

	return $clean;
}

/* ── Keeping it small ──────────────────────────────────────────────── */

/** How many days this site keeps a row. 0 means for ever. */
function diluxone_users_log_days(): int {
	return max( 0, (int) diluxone_users_option( 'diluxone_users_log_days' ) );
}

/**
 * Drops what is older than the site said it keeps.
 *
 * In batches, and it is not caution for its own sake: a site that had the log
 * on for a year and then sets it to thirty days asks this to delete millions
 * of rows in one statement, which locks the table for as long as it takes and
 * takes the site down with it. Ten thousand at a time, fifty batches at most
 * in one run, and the rest goes tomorrow — a purge that is a day behind is not
 * a problem, and a site that is down for two minutes is.
 *
 * @return int How many rows went.
 */
function diluxone_users_log_purge(): int {
	global $wpdb;

	$days = diluxone_users_log_days();

	if ( 0 === $days ) {
		return 0;
	}

	$table = diluxone_users_log_table();
	$edge  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$gone  = 0;

	// Batch after batch until a batch comes back short, and never more than
	// fifty in one run: a site that writes more than ten thousand rows a day
	// still catches up, and one that has a backlog of millions spreads it
	// over a few nights instead of one long lock.
	for ( $batch = 0; $batch < 50; $batch++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a delete is not cached.
		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE happened < %s LIMIT 10000', $table, $edge ) );
		$gone   += $deleted;

		if ( $deleted < 10000 ) {
			break;
		}
	}

	return $gone;
}
/**
 * The daily event, which wants nothing back.
 *
 * The purge returns how many rows went because that is what a caller asking
 * for it wants — a test, a tool, the next batch. An action callback that
 * returns something is a callback somebody will one day read as meaning
 * something to WordPress, so the hook gets a wrapper that answers nothing.
 */
function diluxone_users_log_purge_run(): void {
	diluxone_users_log_purge();
}
add_action( DILUXONE_USERS_LOG_PURGE, 'diluxone_users_log_purge_run' );

/**
 * How big this is on this site, right now.
 *
 * The number this whole feature was asked for. It is read from the database
 * and not estimated: `COUNT(*)` for the rows, because InnoDB's own row count
 * in `information_schema` is an estimate that can be out by half, and the size
 * from `information_schema` because that is the only place it exists.
 *
 * `COUNT(*)` on a log is exactly as expensive as the log is big, and that is
 * the point rather than a flaw: the number costs what the decision costs. The
 * retention is what keeps it cheap, which is the sentence the screen is
 * making.
 *
 * @return array{rows: int, bytes: int, oldest: string} Oldest is a GMT datetime, or '' when the table is empty.
 */
function diluxone_users_log_size(): array {
	global $wpdb;

	$table = diluxone_users_log_table();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, and the number from now.
	$rows   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	$oldest = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(happened) FROM %i', $table ) );

	// A site whose database user cannot read information_schema — some managed
	// hosts — gets a size of zero rather than a broken screen, and the screen
	// says rows either way.
	$bytes = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
			$table
		)
	);

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return array(
		'rows'   => $rows,
		'bytes'  => $bytes,
		'oldest' => $oldest,
	);
}

/* ── Reading ───────────────────────────────────────────────────────── */

/**
 * The rows, filtered and paginated.
 *
 * There are four filters and they are all optional, which is sixteen shapes of
 * query. Written as sixteen literal strings this file would be unreadable, and
 * assembled from pieces neither the analysers nor a reviewer could tell what
 * reaches the database — which is the trade the sessions report chose the
 * other way round, and it only had one filter.
 *
 * So there is one query, and every filter carries its own "or nothing was
 * asked" beside it: `%d = 0 OR l.user_id = %d`. Every value goes through
 * `prepare()` and the string itself never changes, so what runs is exactly
 * what is written here. MySQL folds the constant half away before it plans
 * anything, so an unused filter costs nothing and the indexes are still used.
 *
 * The join is LEFT and not INNER on purpose: a refused sign-in belongs to
 * nobody, and an account deleted last week still has the rows that say what it
 * did. An INNER join would hide exactly the two cases a log is opened for.
 *
 * @param array<string, mixed> $filters who (free text), event, from and to (Y-m-d, site time).
 * @param int                  $page    From 1.
 * @param int                  $per     How many per page.
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function diluxone_users_log_search( array $filters = array(), int $page = 1, int $per = 20 ): array {
	global $wpdb;

	$table  = diluxone_users_log_table();
	$page   = max( 1, $page );
	$per    = max( 1, min( 200, $per ) );
	$offset = ( $page - 1 ) * $per;

	$who   = trim( (string) ( $filters['who'] ?? '' ) );
	$like  = '' === $who ? '' : '%' . $wpdb->esc_like( $who ) . '%';
	$event = (string) ( $filters['event'] ?? '' );
	$event = isset( diluxone_users_log_events()[ $event ] ) ? $event : '';

	// The dates arrive as a day in the site's own time zone and the column is
	// GMT. Without the conversion a site at UTC-3 asking for "today" misses
	// the first three hours of it and gets the last three of yesterday.
	$from = diluxone_users_log_day_start( (string) ( $filters['from'] ?? '' ) );
	$to   = diluxone_users_log_day_end( (string) ( $filters['to'] ?? '' ) );

	/*
	 * The WHERE is built from the filters that are actually set, and that is
	 * a correctness fix rather than a tidying.
	 *
	 * It used to be four fixed conditions of the shape
	 * `( %s = '' OR l.happened >= %s )`, so that an unset filter compared
	 * itself away. For the two text columns that works. For `happened`, which
	 * is a DATETIME, comparing against `''` is not false — under
	 * `STRICT_TRANS_TABLES`, which is the default of MySQL 5.7 and up and of
	 * MariaDB 10.2 and up, it is `Incorrect DATETIME value: ''` and the whole
	 * statement fails. `get_var()` then answers null, the count is 0 and the
	 * screen says "nothing matches" over a table with rows in it.
	 *
	 * It survived every test because wp-env's MySQL runs a laxer sql_mode
	 * than a real host does, which is the whole lesson: this is a query that
	 * was only ever exercised where it could not fail.
	 */
	$where = array( '1=1' );
	$args  = array();

	if ( '' !== $who ) {
		// And the refused sign-ins, filed under no account on purpose, whose
		// typed name is what somebody searching for an account is after: is
		// somebody guessing at it?
		$where[] = '( u.user_email LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s OR ( l.user_id = 0 AND l.detail LIKE %s ) )';
		$args[]  = $like;
		$args[]  = $like;
		$args[]  = $like;
		$args[]  = $like;
	}

	if ( '' !== $event ) {
		$where[] = 'l.event = %s';
		$args[]  = $event;
	}

	if ( '' !== $from ) {
		$where[] = 'l.happened >= %s';
		$args[]  = $from;
	}

	if ( '' !== $to ) {
		$where[] = 'l.happened <= %s';
		$args[]  = $to;
	}

	$where = implode( ' AND ', $where );

	// The table is the first placeholder of both statements.
	array_unshift( $args, $table );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built above out of literals and every value in it is a placeholder filled from $args, all through prepare(); the plugin's own table, read as it is now.
	$count_sql = "SELECT COUNT(*)
	   FROM %i l
	   LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
	  WHERE {$where}";

	$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );

	$rows_sql = "SELECT l.id, l.user_id, l.event, l.happened, l.ip, l.agent, l.detail,
	        u.user_login, u.user_email, u.display_name
	   FROM %i l
	   LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
	  WHERE {$where}
   ORDER BY l.id DESC
	  LIMIT %d OFFSET %d";

	$found = $wpdb->get_results(
		$wpdb->prepare( $rows_sql, array_merge( $args, array( $per, $offset ) ) ),
		ARRAY_A
	);
	// phpcs:enable

	$rows = array();

	foreach ( (array) $found as $row ) {
		$detail = json_decode( (string) $row['detail'], true );

		$rows[] = array(
			'id'       => (int) $row['id'],
			'user_id'  => (int) $row['user_id'],
			'event'    => (string) $row['event'],
			'happened' => (int) strtotime( (string) $row['happened'] . ' UTC' ),
			'ip'       => (string) $row['ip'],
			'agent'    => (string) $row['agent'],
			'detail'   => is_array( $detail ) ? $detail : array(),
			'login'    => (string) ( $row['user_login'] ?? '' ),
			'email'    => (string) ( $row['user_email'] ?? '' ),
			'name'     => (string) ( $row['display_name'] ?? '' ),
		);
	}

	return array(
		'rows'  => $rows,
		'total' => $total,
	);
}

/** A day in the site's time zone, as the GMT moment it starts. '' stays ''. */
function diluxone_users_log_day_start( string $day ): string {
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? (string) get_gmt_from_date( $day . ' 00:00:00' ) : '';
}

/** The same day, as the GMT moment it ends. */
function diluxone_users_log_day_end( string $day ): string {
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? (string) get_gmt_from_date( $day . ' 23:59:59' ) : '';
}

/**
 * Everything one person's account did, for the exporter and the eraser.
 *
 * It goes by id and not through the search above: the search finds people by
 * what they are called, which is the right question for a screen and the wrong
 * one entirely for somebody's data — two accounts can share a display name.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_log_of( int $user_id, int $page = 1, int $per = 500 ): array {
	global $wpdb;

	$table  = diluxone_users_log_table();
	$per    = max( 1, min( 1000, $per ) );
	$offset = ( max( 1, $page ) - 1 ) * $per;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read as it is now.
	$found = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, event, happened, ip, agent, detail
			   FROM %i
			  WHERE user_id = %d
		   ORDER BY id ASC
			  LIMIT %d OFFSET %d',
			$table,
			$user_id,
			$per,
			$offset
		),
		ARRAY_A
	);

	$rows = array();

	foreach ( (array) $found as $row ) {
		$detail = json_decode( (string) $row['detail'], true );

		$rows[] = array(
			'id'       => (int) $row['id'],
			'event'    => (string) $row['event'],
			'happened' => (int) strtotime( (string) $row['happened'] . ' UTC' ),
			'ip'       => (string) $row['ip'],
			'agent'    => (string) $row['agent'],
			'detail'   => is_array( $detail ) ? $detail : array(),
		);
	}

	return $rows;
}

/**
 * The refused sign-ins that typed one of these names.
 *
 * They are filed under nobody (see diluxone_users_log_login_failed()), and
 * what ties them to a person is what was typed: their username or their
 * address. The detail is JSON, so the name is looked for as the JSON it was
 * written as.
 *
 * @param array<int, string> $names A login and an address, usually.
 * @return array<int, array{id: int, event: string, happened: int, ip: string, agent: string, detail: array<string, mixed>}>
 */
function diluxone_users_log_tried( array $names ): array {
	global $wpdb;

	$table = diluxone_users_log_table();
	$rows  = array();

	foreach ( array_unique( array_filter( $names ) ) as $name ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read as it is now.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event, happened, ip, agent, detail
				   FROM %i
				  WHERE user_id = 0 AND event = %s AND detail LIKE %s
			   ORDER BY id ASC
				  LIMIT 1000',
				$table,
				'sign_in_failed',
				'%' . $wpdb->esc_like( '"tried":' . wp_json_encode( $name ) ) . '%'
			),
			ARRAY_A
		);

		foreach ( (array) $found as $row ) {
			$detail = json_decode( (string) $row['detail'], true );

			$rows[ (int) $row['id'] ] = array(
				'id'       => (int) $row['id'],
				'event'    => (string) $row['event'],
				'happened' => (int) strtotime( (string) $row['happened'] . ' UTC' ),
				'ip'       => (string) $row['ip'],
				'agent'    => (string) $row['agent'],
				'detail'   => is_array( $detail ) ? $detail : array(),
			);
		}
	}

	return array_values( $rows );
}

/**
 * Everything about one person, gone.
 *
 * @return int How many rows went.
 */
function diluxone_users_log_forget( int $user_id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a delete is not cached.
	return (int) $wpdb->delete( diluxone_users_log_table(), array( 'user_id' => $user_id ), array( '%d' ) );
}
