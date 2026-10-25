<?php
/**
 * The activity log on screen: the rows, and what is written down at all.
 *
 * Two panels and not one, and they sit at the two ends of the Reports screen
 * on purpose. The list is a report — it is looked at, nothing on it is saved,
 * and it belongs beside the open sessions. What is recorded and for how long is
 * a setting, it has a Save button, and putting it under the table would make
 * the table read as something that gets written when the button is pressed,
 * which is the exact shape the Reports screen was created to take off these
 * screens.
 *
 * Why the setting is here at all, rather than on Security with the other
 * rules: this one decides whether the screen beside it has anything to show.
 * A log that can only be turned on two screens away from where it is read is a
 * log that stays off, and the first thing anybody does after finding an empty
 * table is look for the switch. It is last in the tab strip, it says what it is
 * in its own name, and it is the only settings tab on the screen.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The two tabs: the rows, and the rules about them. */
function diluxone_users_log_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_REPORTS,
		'activity',
		array(
			'label'    => __( 'Activity', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_log',
			// The search is a form of its own, posting to this same screen.
			// A form inside a form is thrown away by the browser, and there is
			// nothing on this tab to save anyway.
			'form'     => false,
		)
	);

	// A network's log is one table, and the network reads all of it: beside
	// its settings, in Network Admin, with the site each row happened on.
	if ( diluxone_users_log_network() ) {
		diluxone_users_register_panel(
			DILUXONE_USERS_REPORTS,
			'network-activity',
			array(
				'label'    => __( 'Activity', 'diluxone-users' ),
				'position' => 20,
				'render'   => 'diluxone_users_screen_log_network',
				'form'     => false,
			)
		);
	}

	diluxone_users_register_panel(
		DILUXONE_USERS_REPORTS,
		'logging',
		array(
			'label'    => __( 'Log settings', 'diluxone-users' ),
			'position' => 90,
			'render'   => 'diluxone_users_screen_log_settings',
			'save'     => 'diluxone_users_log_settings_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_log_panels' );

/* ── What is recorded ──────────────────────────────────────────────── */

/** Saves the groups and the retention. */
function diluxone_users_log_settings_save(): void {
	check_admin_referer( 'diluxone_users_panel_' . DILUXONE_USERS_REPORTS, 'diluxone_users_panel_nonce' );
	diluxone_users_panel_allowed();

	// Only the ones naming a group this plugin has are kept, below.
	$sent   = (array) map_deep( wp_unslash( $_POST['diluxone_users_log_levels'] ?? array() ), 'sanitize_key' );
	$groups = diluxone_users_log_groups();
	$levels = array();

	foreach ( $sent as $group ) {
		// A list inside the list is no group.
		if ( ! is_string( $group ) ) {
			continue;
		}

		$group = sanitize_key( $group );

		if ( isset( $groups[ $group ] ) ) {
			$levels[] = $group;
		}
	}

	diluxone_users_save_options(
		array(
			'diluxone_users_log_levels' => $levels,
			'diluxone_users_log_days'   => ( isset( $_POST['diluxone_users_log_days'] ) && is_scalar( $_POST['diluxone_users_log_days'] ) ? absint( wp_unslash( $_POST['diluxone_users_log_days'] ) ) : 90 ),
		)
	);
}

/** The three tick boxes and the number of days. */
function diluxone_users_screen_log_settings(): void {
	$on = diluxone_users_log_levels();

	diluxone_users_ui_aside_open();

	diluxone_users_intro(
		'network' === diluxone_users_admin_context()
			? __( 'What every site of the network writes down about what people do, and how long it keeps it. The rows are one table for the whole network: each site sees its own on its Reports › Activity, and the Activity tab beside this one has them all. A group that is not ticked here is not recorded on any site.', 'diluxone-users' )
			: __( 'What this site writes down about what people do, and how long it keeps it. Everything here decides what the Activity tab beside it can show: a group that is not ticked is not recorded, and what was never recorded cannot be looked up afterwards.', 'diluxone-users' )
	);

	diluxone_users_ui_section(
		__( 'What is written down', 'diluxone-users' ),
		__( 'A fresh install records nothing, and has no table to record it in until a group is ticked here. Each one is ticked by whoever will be paying for the rows — which is why each one says roughly what it costs.', 'diluxone-users' )
	);

	$choices = array();

	foreach ( diluxone_users_log_groups() as $group => $what ) {
		$choices[] = array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_log_levels[]',
			'id'      => 'diluxone-users-log-' . $group,
			'value'   => $group,
			'checked' => in_array( $group, $on, true ),
			'title'   => $what['label'],
			// Two sentences and not one: what it records, and what it costs.
			// The second is the half that is normally missing from a switch
			// like this, and it is the half somebody is actually deciding on.
			'help'    => $what['help'] . ' ' . $what['writes'],
		);
	}

	diluxone_users_ui_choices( $choices );

	diluxone_users_ui_section( __( 'How long it is kept', 'diluxone-users' ) );

	diluxone_users_ui_number(
		array(
			'label'  => __( 'Keep a row for', 'diluxone-users' ),
			'name'   => 'diluxone_users_log_days',
			'value'  => (string) diluxone_users_log_days(),
			'suffix' => __( 'days', 'diluxone-users' ),
			'min'    => 0,
			'help'   => __( 'Anything older goes once a day, on its own. 0 keeps everything for ever, which is a real answer for a site that has to keep it — and the one that has no end.', 'diluxone-users' ),
		)
	);

	// On a single site the way to empty the log is with the rules about it.
	// On a network it goes with the rows, on the Activity tabs: a site's
	// empties that site's, the network's empties everybody's.
	if ( 'single' === diluxone_users_admin_context() ) {
		diluxone_users_log_empty_box( 'single' );
	}

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_log_aside_state();
		}
	);
}

/**
 * Emptying the log: this site's rows, or on a network's own screen every row.
 *
 * Emptying it is not a setting, so it is a link and not a field of the form
 * around it: a form inside a form is thrown away by the browser, and pressing
 * Save should never be what deletes a year of rows.
 *
 * @param string $where 'single' (a site on its own, on Log settings), 'site'
 *                      (a site of a network, with its rows) or 'network'
 *                      (Network Admin, with everybody's).
 */
function diluxone_users_log_empty_box( string $where ): void {
	$network = 'network' === $where;
	$size    = diluxone_users_log_size( $network ? diluxone_users_log_network_sites() : diluxone_users_log_site() );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only which notice to show after the redirect.
	if ( isset( $_GET['diluxone-users-emptied'] ) ) {
		diluxone_users_notice(
			sprintf(
				/* translators: %s: number of rows deleted */
				__( 'The activity log was emptied: %s rows deleted.', 'diluxone-users' ),
				number_format_i18n( absint( wp_unslash( $_GET['diluxone-users-emptied'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count shown once after the redirect, cast to a number.
			)
		);
	}

	$says = array(
		'single'  => __( 'Every row goes, whatever its age, and the Activity tab starts again from nothing. What is recorded from then on follows the settings above.', 'diluxone-users' ),
		'site'    => __( 'Every row of this site goes, whatever its age, and this tab starts again from nothing. What is recorded from then on follows the network’s log settings.', 'diluxone-users' ),
		'network' => __( 'Every row of every site goes, whatever its age, and every site’s Activity tab starts again from nothing. What is recorded from then on follows the log settings.', 'diluxone-users' ),
	);

	diluxone_users_ui_section( __( 'Empty it now', 'diluxone-users' ), $says[ $where ] ?? $says['site'] );

	if ( $size['rows'] > 0 ) {
		$action = $network ? 'diluxone_users_log_empty_network' : 'diluxone_users_log_empty';

		printf(
			'<p><a class="button button-link-delete" href="%1$s" data-diluxone-users-confirm="%2$s">%3$s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action ) ),
			esc_attr(
				$network
					? sprintf(
						/* translators: %s: number of rows */
						__( 'The %s rows of every site’s activity log are deleted for good. It cannot be undone. Go ahead?', 'diluxone-users' ),
						number_format_i18n( $size['rows'] )
					)
					: sprintf(
						/* translators: %s: number of rows */
						__( 'The %s rows of the activity log are deleted for good. It cannot be undone. Go ahead?', 'diluxone-users' ),
						number_format_i18n( $size['rows'] )
					)
			),
			esc_html__( 'Delete every row', 'diluxone-users' )
		);
	} else {
		printf( '<p class="description">%s</p>', esc_html__( 'There is nothing in it.', 'diluxone-users' ) );
	}
}

/* ── The rows ──────────────────────────────────────────────────────── */

/**
 * How this site stands, for the head of either rail.
 *
 * Both tabs open with the same sentence because it is the same fact, and it is
 * the one this feature was asked for: not "a log may grow", which is true of
 * every site ever installed, but what this table weighs on this site this
 * afternoon.
 */
function diluxone_users_log_aside_state(): void {
	$on      = diluxone_users_log_levels();
	$groups  = diluxone_users_log_groups();
	$context = diluxone_users_admin_context();

	// In Network Admin the sentence is about every site and the whole table.
	if ( 'network' === $context ) {
		diluxone_users_log_network_state( $on, $groups );

		return;
	}

	$size = diluxone_users_log_size( diluxone_users_log_site() );

	$names = array();

	foreach ( $on as $group ) {
		$names[] = '<code>' . esc_html( (string) $groups[ $group ]['label'] ) . '</code>';
	}

	/*
	 * The groups are named in the middle of the sentence and never at the end
	 * of one. A `<code>` span carries its own padding, so a full stop written
	 * straight after it lands a space away from the word and reads as a typo —
	 * which is exactly what it looked like on the first draft of this rail.
	 */
	$line = array() === $names
		? esc_html__( 'Nothing is being recorded on this site.', 'diluxone-users' )
		: sprintf(
			/* translators: %s: the groups being recorded, each in <code>. */
			esc_html__( 'This site records %s and nothing else.', 'diluxone-users' ),
			implode( ', ', $names )
		);

	$bytes = esc_html( $size['bytes'] > 0 ? (string) size_format( $size['bytes'] ) : __( 'a size this database will not report', 'diluxone-users' ) );

	// A site of a network has its rows in the network's table, and a database
	// weighs tables: the size is the whole table's, and the sentence says so.
	$line .= ' ' . ( 'single' === $context
		? sprintf(
			/* translators: 1: number of rows, 2: size on disk, e.g. "4 MB". */
			esc_html__( 'The table holds %1$s rows and takes %2$s.', 'diluxone-users' ),
			esc_html( number_format_i18n( $size['rows'] ) ),
			$bytes
		)
		: sprintf(
			/* translators: 1: number of rows, 2: size on disk, e.g. "4 MB". */
			esc_html__( 'This site has %1$s rows in the network’s table, which takes %2$s for every site.', 'diluxone-users' ),
			esc_html( number_format_i18n( $size['rows'] ) ),
			$bytes
		) );

	$line .= diluxone_users_log_oldest_line( $size['oldest'] );

	diluxone_users_ui_aside_state( $line, array() === $on ? 'off' : 'active', diluxone_users_log_kept_for() );
}

/**
 * The same sentence for the network: what every site records, and the table.
 *
 * @param array<int, string>                  $on     The groups recorded.
 * @param array<string, array<string, mixed>> $groups Every group.
 */
function diluxone_users_log_network_state( array $on, array $groups ): void {
	$names = array();

	foreach ( $on as $group ) {
		$names[] = '<code>' . esc_html( (string) $groups[ $group ]['label'] ) . '</code>';
	}

	$line = array() === $names
		? esc_html__( 'Nothing is being recorded on any site of the network.', 'diluxone-users' )
		: sprintf(
			/* translators: %s: the groups being recorded, each in <code>. */
			esc_html__( 'Every site of the network records %s and nothing else, in one table for the whole network.', 'diluxone-users' ),
			implode( ', ', $names )
		);

	$size = diluxone_users_log_size( diluxone_users_log_network_sites() );

	$line .= ' ' . sprintf(
		/* translators: 1: number of rows, 2: size on disk, e.g. "4 MB". */
		esc_html__( 'The table holds %1$s rows and takes %2$s.', 'diluxone-users' ),
		esc_html( number_format_i18n( $size['rows'] ) ),
		esc_html( $size['bytes'] > 0 ? (string) size_format( $size['bytes'] ) : __( 'a size this database will not report', 'diluxone-users' ) )
	);

	$line .= diluxone_users_log_oldest_line( $size['oldest'] );

	diluxone_users_ui_aside_state( $line, array() === $on ? 'off' : 'active', diluxone_users_log_kept_for() );
}

/**
 * " The oldest row is from …", or nothing when there is no row.
 *
 * @param string $oldest A GMT datetime, or ''.
 */
function diluxone_users_log_oldest_line( string $oldest ): string {
	if ( '' === $oldest ) {
		return '';
	}

	return ' ' . sprintf(
		/* translators: %s: a date. */
		esc_html__( 'The oldest row is from %s.', 'diluxone-users' ),
		esc_html( (string) wp_date( 'j M Y', (int) strtotime( $oldest . ' UTC' ) ) )
	);
}

/** How long a row is kept, for the pill at the head of the rail. */
function diluxone_users_log_kept_for(): string {
	$days = diluxone_users_log_days();

	return 0 === $days
		? __( 'kept for ever', 'diluxone-users' )
		: sprintf(
			/* translators: %s: a number of days. */
			__( 'kept %s days', 'diluxone-users' ),
			number_format_i18n( $days )
		);
}

/**
 * Which door somebody came in by, named for whoever is reading the report.
 *
 * Not `diluxone_users_via_label()`, which is the same fact written for the
 * person it happened to — "your password", "a link sent to your email". In a
 * column about somebody else that is the wrong voice and, worse, the wrong
 * person: an administrator reading "your password" on a row about a stranger
 * reads it twice. Here it is the name of the door and nothing more.
 */
function diluxone_users_log_via( string $via ): string {
	$doors = array(
		'password' => __( 'Password', 'diluxone-users' ),
		'link'     => __( 'E-mail link', 'diluxone-users' ),
		'sso'      => __( 'Social account', 'diluxone-users' ),
	);

	/**
	 * Filters the names of the doors in the activity log.
	 *
	 * The `$via` an add-on's way in opens the session with
	 * (`diluxone_users_open_session()`), and what the report calls it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $doors Via => its name.
	 */
	$doors = (array) apply_filters( 'diluxone_users_log_doors', $doors );

	return (string) ( $doors[ $via ] ?? $via );
}

/**
 * What a refused social sign-in says: the network, and why, in words.
 *
 * The row keeps the network's slug and a short reason (diluxone_users_sso_log_refused());
 * a reason this list does not know is the provider's own error code, and is
 * shown as it came.
 *
 * @param string $provider The network's slug.
 * @param string $reason   Why, as the row keeps it.
 */
function diluxone_users_log_sso_refused( string $provider, string $reason ): string {
	$providers = diluxone_users_sso_providers();
	$name      = (string) ( $providers[ $provider ]['name'] ?? $provider );

	$reasons = array(
		'cancelled' => __( 'cancelled at the provider', 'diluxone-users' ),
		'state'     => __( 'a return nobody started here', 'diluxone-users' ),
		'browser'   => __( 'came back in another browser or session', 'diluxone-users' ),
		'off'       => __( 'the network was turned off', 'diluxone-users' ),
		'token'     => __( 'the provider handed over no access token', 'diluxone-users' ),
		'account'   => __( 'no account found or created', 'diluxone-users' ),
	);

	$why = (string) ( $reasons[ $reason ] ?? $reason );

	return '' === $why ? $name : $name . ' · ' . $why;
}

/**
 * The site a sign-in on the hub came from, or 0.
 *
 * @param array<string, mixed> $row One row as diluxone_users_log_search() returns it.
 */
function diluxone_users_log_from( array $row ): int {
	return 'signed_in' === (string) $row['event'] ? absint( ( (array) $row['detail'] )['from_site'] ?? 0 ) : 0;
}

/**
 * What one row says, beyond its name.
 *
 * The event's own label answers "what happened"; this answers "to what". It is
 * built from the detail the row was written with: a handful of short strings,
 * and for a refused sign-in the name that was typed in the username box. That
 * one is somebody's input, and it is escaped like any other on the way out.
 *
 * @param array<string, mixed> $row     One row as diluxone_users_log_search() returns it.
 * @param bool                 $network Whether it is drawn on the network's report.
 */
function diluxone_users_log_says( array $row, bool $network = false ): string {
	$detail = (array) $row['detail'];

	switch ( (string) $row['event'] ) {
		case 'signed_in':
			$says = isset( $detail['via'] ) ? diluxone_users_log_via( (string) $detail['via'] ) : '';

			// On a site's own report, a sign-in that happened on the hub for
			// this site says where it happened; the network's report has a
			// column for it.
			if ( ! $network && (int) $row['site_id'] !== diluxone_users_log_site() ) {
				$says = trim(
					$says . ' · ' . sprintf(
						/* translators: %s: the name of the site where the person signed in */
						__( 'on %s', 'diluxone-users' ),
						diluxone_users_log_site_name( (int) $row['site_id'] )
					),
					' ·'
				);
			}

			return $says;

		case 'sign_in_failed':
			if ( 'sso' === (string) ( $detail['via'] ?? '' ) ) {
				return diluxone_users_log_sso_refused( (string) ( $detail['provider'] ?? '' ), (string) ( $detail['reason'] ?? '' ) );
			}

			return (string) ( $detail['tried'] ?? '' );

		case 'email_changed':
		case 'name_changed':
			return trim( (string) ( $detail['was'] ?? '' ) . ' → ' . (string) ( $detail['now'] ?? '' ), ' →' );

		case 'profile_saved':
			return (string) ( $detail['fields'] ?? '' );

		case 'sessions_closed':
			return sprintf(
				/* translators: %s: how many sessions were closed at once. */
				__( '%s closed', 'diluxone-users' ),
				number_format_i18n( (int) ( $detail['closed'] ?? 0 ) )
			);
	}

	/**
	 * Filters what a row of an add-on's event says, beyond its name.
	 *
	 * Built from the detail the event was recorded with. Plain text: it is
	 * escaped where it is printed.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $says    '' unless something answers.
	 * @param array<string, mixed> $row     The row, as the report reads it.
	 * @param bool                 $network Whether it is drawn on the network's report.
	 */
	return (string) apply_filters( 'diluxone_users_log_says', '', $row, $network );
}

/**
 * The rows, filtered.
 *
 * Four filters, and they are the four questions a log is opened with: who,
 * what, and between when and when. There is no dropdown of people for the same
 * reason the sessions report has none — a site with twenty-five thousand
 * accounts would ship half a megabyte of HTML on every load, and what somebody
 * needs is to find one person, not to scroll past the rest.
 *
 * The table is a wide block and the rail stays all the same. What the rail
 * carries here is not a cross-reference: it is how much this table weighs,
 * which is the one thing on the screen that is not a row.
 */
function diluxone_users_screen_log(): void {
	diluxone_users_log_report( false );
}

/**
 * The network's rows, every site's, in Network Admin.
 *
 * The same report, with the fifth question a network's log is opened with —
 * on which site — as a filter and as a column.
 */
function diluxone_users_screen_log_network(): void {
	diluxone_users_log_report( true );
}

/**
 * The sites a network's report can be narrowed to, or null when there are
 * too many to list.
 *
 * A dropdown for a network of a few dozen sites; past a hundred, the list is a
 * page of HTML nobody scrolls, and the filter is the site's number — which is
 * also what the Site column of every row links to.
 *
 * @return array<int, string>|null Site id => name.
 */
function diluxone_users_log_site_choices(): ?array {
	$sites = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 101,
			'network_id' => get_current_network_id(),
			'orderby'    => 'id',
			'order'      => 'ASC',
		)
	);

	if ( count( $sites ) > 100 ) {
		return null;
	}

	$choices = array();

	foreach ( $sites as $site ) {
		$choices[ (int) $site ] = diluxone_users_log_site_name( (int) $site );
	}

	return $choices;
}

/**
 * One report, for a site or for the network.
 *
 * @param bool $network Every site's rows, with a Site filter and column.
 */
function diluxone_users_log_report( bool $network ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- it is a read-only search.
	$who   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$event = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
	$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
	$site  = $network && isset( $_GET['site'] ) ? absint( wp_unslash( $_GET['site'] ) ) : 0;
	$page  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	$per   = isset( $_GET['per'] ) ? max( 5, min( 200, absint( wp_unslash( $_GET['per'] ) ) ) ) : 20;
	// phpcs:enable

	$tab     = $network ? 'network-activity' : 'activity';
	$filters = array(
		// A site's report is its own rows, whatever is asked — and on a
		// network the sign-ins on the hub that were for it.
		'site'     => $network ? $site : diluxone_users_log_site(),
		'arrivals' => ! $network && diluxone_users_log_network(),
		'who'      => $who,
		'event'    => $event,
		'from'     => $from,
		'to'       => $to,
	);

	// The network's report is its own sites' rows: on an installation of
	// several networks the table has the others' too.
	if ( $network ) {
		$filters['sites'] = diluxone_users_log_network_sites();
	}

	$result  = diluxone_users_log_search( $filters, $page, $per );
	$total   = $result['total'];
	$pages   = (int) max( 1, ceil( $total / $per ) );
	$labels  = diluxone_users_log_labels();
	$groups  = diluxone_users_log_groups();
	$columns = $network ? 8 : 6;
	$keep    = array(
		'tab'   => $tab,
		's'     => $who,
		'event' => $event,
		'from'  => $from,
		'to'    => $to,
		'per'   => $per,
	);

	if ( $site > 0 ) {
		$keep['site'] = $site;
	}

	diluxone_users_ui_aside_open();

	diluxone_users_intro(
		$network
			? __( 'What happened on every site of the network, newest first, with the site each row happened on. It shows only what was being recorded at the time: a group ticked this morning has nothing in it from yesterday.', 'diluxone-users' )
			: __( 'What happened on this site, newest first. It shows only what was being recorded at the time: a group ticked this morning has nothing in it from yesterday.', 'diluxone-users' )
	);

	?>
	<form method="get" class="diluxone-users-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( DILUXONE_USERS_REPORTS ); ?>">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">

		<label class="screen-reader-text" for="diluxone-users-log-s"><?php esc_html_e( 'Search', 'diluxone-users' ); ?></label>
		<input type="search" id="diluxone-users-log-s" name="s" value="<?php echo esc_attr( $who ); ?>" placeholder="<?php esc_attr_e( 'Email, username or name…', 'diluxone-users' ); ?>">

		<?php
		if ( $network ) :
			$diluxone_users_sites = diluxone_users_log_site_choices();
			?>
			<label class="screen-reader-text" for="diluxone-users-log-site"><?php esc_html_e( 'Site', 'diluxone-users' ); ?></label>
			<?php if ( null === $diluxone_users_sites ) : ?>
				<input type="number" id="diluxone-users-log-site" name="site" min="0" class="small-text" value="<?php echo esc_attr( $site > 0 ? (string) $site : '' ); ?>" placeholder="<?php esc_attr_e( 'Site ID', 'diluxone-users' ); ?>">
			<?php else : ?>
				<select id="diluxone-users-log-site" name="site">
					<option value="0"><?php esc_html_e( 'Every site', 'diluxone-users' ); ?></option>
					<?php foreach ( $diluxone_users_sites as $diluxone_users_id => $diluxone_users_name ) : ?>
						<option value="<?php echo esc_attr( (string) $diluxone_users_id ); ?>" <?php selected( $site, $diluxone_users_id ); ?>><?php echo esc_html( $diluxone_users_name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		<?php endif; ?>

		<label class="screen-reader-text" for="diluxone-users-log-event"><?php esc_html_e( 'What happened', 'diluxone-users' ); ?></label>
		<select id="diluxone-users-log-event" name="event">
			<option value=""><?php esc_html_e( 'Anything that happened', 'diluxone-users' ); ?></option>
			<?php foreach ( diluxone_users_log_events() as $slug => $group ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $event, $slug ); ?>>
					<?php
					printf(
						'%s — %s',
						esc_html( (string) $groups[ $group ]['label'] ),
						esc_html( (string) ( $labels[ $slug ] ?? $slug ) )
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="diluxone-users-log-from"><?php esc_html_e( 'From', 'diluxone-users' ); ?></label>
		<input type="date" id="diluxone-users-log-from" name="from" value="<?php echo esc_attr( $from ); ?>">

		<label class="screen-reader-text" for="diluxone-users-log-to"><?php esc_html_e( 'To', 'diluxone-users' ); ?></label>
		<input type="date" id="diluxone-users-log-to" name="to" value="<?php echo esc_attr( $to ); ?>">

		<?php submit_button( __( 'Search', 'diluxone-users' ), 'secondary', '', false ); ?>

		<a class="button" href="
		<?php
		echo esc_url(
			diluxone_users_admin_url(
				DILUXONE_USERS_REPORTS,
				array(
					'tab' => $tab,
					'per' => $per,
				)
			)
		);
		?>
			"><?php esc_html_e( 'Clear', 'diluxone-users' ); ?></a>

		<label class="diluxone-users-search__by">
			<?php esc_html_e( 'Show', 'diluxone-users' ); ?>
			<select name="per" data-diluxone-users-autosubmit>
				<?php foreach ( array( 10, 20, 50, 100 ) as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per, $option ); ?>><?php echo esc_html( number_format_i18n( $option ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php esc_html_e( 'per page', 'diluxone-users' ); ?>
		</label>

		<span class="diluxone-users-search__account">
			<?php
			printf(
				/* translators: 1: number of rows, 2: current page, 3: total pages */
				esc_html__( '%1$s rows · page %2$d of %3$d', 'diluxone-users' ),
				esc_html( number_format_i18n( $total ) ),
				(int) $page,
				(int) $pages
			);
			?>
		</span>
	</form>

	<?php diluxone_users_ui_wide_open(); ?>

	<?php
	/*
	 * Without `fixed`, which every other table on these screens carries. A
	 * fixed layout gives six columns a sixth of the width each, and one of
	 * these six holds an e-mail address: at a sixth it broke mid-word, one
	 * letter left on a line of its own. The rest of the columns here are a
	 * date, a short name and an address, so letting the browser size them is
	 * the whole fix, and it is one word rather than a column of widths in the
	 * stylesheet that only this screen would ever use.
	 */
	?>
	<table class="wp-list-table widefat striped diluxone-users-list" data-diluxone-users-log>
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'diluxone-users' ); ?></th>
				<?php if ( $network ) : ?>
					<th><?php esc_html_e( 'Site', 'diluxone-users' ); ?></th>
					<th><?php esc_html_e( 'From', 'diluxone-users' ); ?></th>
				<?php endif; ?>
				<th class="diluxone-users-list__name"><?php esc_html_e( 'Person', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'What happened', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'IP', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Device', 'diluxone-users' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['rows'] ) : ?>
				<tr><td colspan="<?php echo esc_attr( (string) $columns ); ?>"><?php esc_html_e( 'Nothing matches that.', 'diluxone-users' ); ?></td></tr>
			<?php endif; ?>

			<?php
			foreach ( $result['rows'] as $row ) :
				$agent = diluxone_users_user_agent( (string) $row['agent'] );
				?>
				<tr data-diluxone-users-event="<?php echo esc_attr( (string) $row['event'] ); ?>" data-diluxone-users-site="<?php echo esc_attr( (string) $row['site_id'] ); ?>">
					<td><?php echo esc_html( (string) wp_date( 'j M Y, H:i', (int) $row['happened'] ) ); ?></td>
					<?php if ( $network ) : ?>
						<td><a href="<?php echo esc_url( diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array_merge( $keep, array( 'site' => (int) $row['site_id'] ) ) ) ); ?>"><?php echo esc_html( diluxone_users_log_site_name( (int) $row['site_id'] ) ); ?></a></td>
						<td data-diluxone-users-from="<?php echo esc_attr( (string) diluxone_users_log_from( $row ) ); ?>"><?php echo esc_html( diluxone_users_log_from( $row ) > 0 ? diluxone_users_log_site_name( diluxone_users_log_from( $row ) ) : '—' ); ?></td>
					<?php endif; ?>
					<td class="diluxone-users-list__name">
						<?php if ( $row['user_id'] > 0 && '' !== $row['email'] ) : ?>
							<strong><a href="<?php echo esc_url( (string) get_edit_user_link( (int) $row['user_id'] ) ); ?>"><?php echo esc_html( '' !== $row['name'] ? (string) $row['name'] : (string) $row['login'] ); ?></a></strong>
							<span class="diluxone-users-list__mail"><?php echo esc_html( (string) $row['email'] ); ?></span>
						<?php else : ?>
							<?php
							// Nobody: a refused sign-in belongs to no account, and
							// so does a row whose account has since been deleted.
							?>
							<span class="diluxone-users-list__mail">—</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( diluxone_users_log_label( (string) $row['event'] ) ); ?></td>
					<td><?php echo esc_html( diluxone_users_log_says( $row, $network ) ); ?></td>
					<td><code><?php echo esc_html( (string) $row['ip'] ); ?></code></td>
					<td><?php echo esc_html( trim( $agent['browser'] . ( '' !== $agent['os'] ? ' · ' . $agent['os'] : '' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => diluxone_users_admin_url( DILUXONE_USERS_REPORTS, $keep ) . '&paged=%#%',
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => '&lsaquo;',
						'next_text' => '&rsaquo;',
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
	<?php
	diluxone_users_ui_wide_close();

	// On a network the way to empty the rows comes with them: a site's own,
	// or in Network Admin everybody's.
	if ( $network ) {
		diluxone_users_log_empty_box( 'network' );
	} elseif ( 'single' !== diluxone_users_admin_context() ) {
		diluxone_users_log_empty_box( 'site' );
	}

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_log_aside_state();
		}
	);
}

/**
 * Empties this site's activity log, every row.
 *
 * Its own endpoint, capability and nonce, and nothing else on the way: the
 * setting that decides how long rows are kept is not involved, and on a
 * network it is this site's rows and no other's.
 *
 * @return never
 */
function diluxone_users_log_empty(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'diluxone_users_log_empty' );

	$gone = diluxone_users_log_empty_rows( diluxone_users_log_site() );

	wp_safe_redirect(
		diluxone_users_admin_url(
			DILUXONE_USERS_REPORTS,
			array(
				'tab'                    => 'single' === diluxone_users_admin_context() ? 'logging' : 'activity',
				'diluxone-users-emptied' => $gone,
			)
		)
	);
	exit;
}
add_action( 'admin_post_diluxone_users_log_empty', 'diluxone_users_log_empty' );

/**
 * Empties the network's activity log: every row of every site of this network.
 *
 * Only whoever administers the network, and only where the log is the
 * network's: a site administrator's way to empty the log is their own site's,
 * above, and it never reaches another site's rows.
 *
 * @return never
 */
function diluxone_users_log_empty_network(): void {
	if ( ! diluxone_users_log_network() || ! current_user_can( DILUXONE_USERS_NETWORK_CAP ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'diluxone_users_log_empty_network' );

	$gone = diluxone_users_log_empty_rows( diluxone_users_log_network_sites() );

	wp_safe_redirect(
		diluxone_users_admin_url(
			DILUXONE_USERS_REPORTS,
			array(
				'tab'                    => 'network-activity',
				'diluxone-users-emptied' => $gone,
			)
		)
	);
	exit;
}
add_action( 'admin_post_diluxone_users_log_empty_network', 'diluxone_users_log_empty_network' );
