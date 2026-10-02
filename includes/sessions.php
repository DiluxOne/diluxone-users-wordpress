<?php
/**
 * Open sessions: seeing them and closing them.
 *
 * There is no table of our own. WordPress already stores each session in the
 * `session_tokens` user meta with its IP, its user agent, when it started and
 * when it expires; keeping a copy in a separate table adds a write per
 * request, drifts when a session expires on its own, and forces orphan
 * cleanup. Here it is read from where WordPress stores it and that is that.
 *
 * What is missing and is worked out at display time: which browser and which
 * system each session is from, which comes out of the user agent. Nothing is
 * persisted.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Session cookie lifetime, according to the settings.
 *
 * Only when one is set. At 0 — the default — what arrives is handed back
 * untouched: WordPress's own length, or the one another plugin on the site
 * already chose, which this has no business overriding unasked.
 */
function diluxone_users_session_duration( int $expiry, int $user_id, bool $remember ): int {
	$days = $remember
		? (int) diluxone_users_option( 'diluxone_users_session_long_days' )
		: (int) diluxone_users_option( 'diluxone_users_session_short_days' );

	return $days > 0 ? $days * DAY_IN_SECONDS : $expiry;
}
add_filter( 'auth_cookie_expiration', 'diluxone_users_session_duration', 10, 3 );

/**
 * Which browser, device and system a session is from.
 *
 * The user agent is what is looked at, and it lies if somebody wants it to,
 * but this is no security measure here: it is so the person recognises which
 * session is which.
 *
 * @return array{browser: string, os: string, device: string}
 */
function diluxone_users_user_agent( string $ua ): array {
	$browser = __( 'Unknown browser', 'diluxone-users' );
	$os      = '';

	// The order matters: Edge and Chrome also say "Safari" in their string.
	$browsers = array(
		'Edg/'    => 'Edge',
		'OPR/'    => 'Opera',
		'Firefox' => 'Firefox',
		'Chrome'  => 'Chrome',
		'Safari'  => 'Safari',
		'Trident' => 'Internet Explorer',
	);

	foreach ( $browsers as $needle => $name ) {
		if ( false !== strpos( $ua, $needle ) ) {
			$browser = $name;
			break;
		}
	}

	$systems = array(
		'/iphone|ipad|ipod/i' => 'iOS',
		'/android/i'          => 'Android',
		'/windows/i'          => 'Windows',
		'/macintosh|mac os/i' => 'macOS',
		'/linux/i'            => 'Linux',
	);

	foreach ( $systems as $pattern => $name ) {
		if ( preg_match( $pattern, $ua ) ) {
			$os = $name;
			break;
		}
	}

	$mobile = (bool) preg_match( '/mobile|android|iphone|ipod/i', $ua );

	return array(
		'browser' => $browser,
		'os'      => $os,
		'device'  => $mobile
			? __( 'Phone', 'diluxone-users' )
			: __( 'Computer', 'diluxone-users' ),
	);
}

/**
 * Can we identify a single session in order to close it?
 *
 * WordPress does not expose each session's verifier: it comes out of the user
 * meta its default manager uses. If the site changed the manager — rare but
 * possible — we touch nothing and only offer "close the others".
 */
function diluxone_users_sessions_addressable(): bool {
	return 'WP_User_Meta_Session_Tokens' === get_class( WP_Session_Tokens::get_instance( get_current_user_id() ) );
}

/**
 * One person's open sessions, most recent first.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_sessions( int $user_id ): array {
	$raw     = diluxone_users_meta_list( $user_id, 'session_tokens' );
	$current = get_current_user_id() === $user_id && function_exists( 'wp_get_session_token' )
		? hash( 'sha256', (string) wp_get_session_token() )
		: '';

	$sessions = array();

	foreach ( $raw as $verifier => $data ) {
		$ua = (string) ( $data['ua'] ?? '' );

		$sessions[] = array_merge(
			diluxone_users_user_agent( $ua ),
			array(
				'id'      => (string) $verifier,
				'ip'      => diluxone_users_session_ip_of( (array) $data ),
				'started' => (int) ( $data['login'] ?? 0 ),
				'expires' => (int) ( $data['expiration'] ?? 0 ),
				'current' => (string) $verifier === $current,
			)
		);
	}

	usort( $sessions, static fn( array $a, array $b ): int => $b['started'] <=> $a['started'] );

	return $sessions;
}

/**
 * Closes a single session.
 *
 * The user meta is written directly because WP_Session_Tokens::destroy() asks
 * for the token in the clear, which only that session's browser has. The
 * format of that meta is the default manager's, which is why it is checked
 * first.
 */
function diluxone_users_session_close( int $user_id, string $id ): bool {
	if ( ! diluxone_users_sessions_addressable() ) {
		return false;
	}

	$sessions = diluxone_users_meta_list( $user_id, 'session_tokens' );

	if ( ! isset( $sessions[ $id ] ) ) {
		return false;
	}

	unset( $sessions[ $id ] );

	if ( array() === $sessions ) {
		delete_user_meta( $user_id, 'session_tokens' );
	} else {
		update_user_meta( $user_id, 'session_tokens', $sessions );
	}

	return true;
}

/**
 * Closes every session but the one in use.
 *
 * The browsers that were trusted to skip the second step are forgotten at
 * the same time. Somebody closing their other sessions is somebody who lost
 * a device, and the device they lost must not walk back in with the
 * password alone because it was trusted a week ago.
 */
function diluxone_users_sessions_close_others( int $user_id ): void {
	$manager = WP_Session_Tokens::get_instance( $user_id );

	diluxone_users_2fa_forget_browsers( $user_id );

	if ( get_current_user_id() === $user_id && function_exists( 'wp_get_session_token' ) ) {
		$manager->destroy_others( (string) wp_get_session_token() );
		return;
	}

	$manager->destroy_all();
}

/** Handles the buttons in the session list. */
function diluxone_users_sessions_action(): void {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You have to sign in first.', 'diluxone-users' ), '', array( 'response' => 401 ) );
	}

	check_admin_referer( 'diluxone_users_sessions' );

	$user_id = get_current_user_id();
	$id      = sanitize_text_field( wp_unslash( $_POST['diluxone_users_session'] ?? '' ) );

	if ( '' !== $id ) {
		diluxone_users_session_close( $user_id, $id );
	} else {
		diluxone_users_sessions_close_others( $user_id );
	}

	$back = wp_get_referer();

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'sessions', $back ? $back : home_url( '/' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_users_sessions', 'diluxone_users_sessions_action' );

/**
 * The users with open sessions, searched and paginated.
 *
 * There is no user dropdown: with 25,000 accounts, a <select> is half a
 * megabyte of HTML on every load of the screen. It is searched and paginated
 * against the database.
 *
 * The query starts from the `session_tokens` user meta, which is indexed by
 * meta_key: whoever has no open session does not even have the row, and that
 * cuts 25,000 users down to the few who are in.
 *
 * @param string $search Free text: e-mail, username or name.
 * @param int    $page   Page, from 1.
 * @param int    $per    How many per page.
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function diluxone_users_sessions_search( string $search = '', int $page = 1, int $per = 20 ): array {
	global $wpdb;

	$page   = max( 1, $page );
	$per    = max( 1, min( 200, $per ) );
	$offset = ( $page - 1 ) * $per;
	$search = trim( $search );

	// The sessions live in user meta and WordPress has no API that queries
	// them, so this goes to the database directly. It is deliberately not
	// cached: this is an admin screen opened to see the state right now.
	//
	// Each query is written out in full instead of being assembled from
	// pieces. Building it in a variable and preparing that is just as safe,
	// and it is what this used to do — but neither the static analysers nor a
	// reviewer can follow a string that arrives from somewhere else, so what
	// they see is a query of unknown origin. Written out, every one of them is
	// verifiable where it stands. The repetition is the price.
	//
	// Only the people of this site: on a network the accounts and their
	// sessions are the network's, and a site's administrator has no business
	// reading the addresses of everybody else's members. A member is whoever
	// has a role here, which WordPress keeps under this site's own prefix.
	$members = $wpdb->get_blog_prefix() . 'capabilities';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
	if ( '' === $search ) {
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->usermeta} m
				   INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
				   INNER JOIN {$wpdb->usermeta} c ON c.user_id = u.ID AND c.meta_key = %s
				  WHERE m.meta_key = 'session_tokens'",
				$members
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.display_name, m.meta_value
				   FROM {$wpdb->usermeta} m
				   INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
				   INNER JOIN {$wpdb->usermeta} c ON c.user_id = u.ID AND c.meta_key = %s
				  WHERE m.meta_key = 'session_tokens'
			   ORDER BY u.user_email ASC
				  LIMIT %d OFFSET %d",
				$members,
				$per,
				$offset
			)
		);
	} else {
		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->usermeta} m
				   INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
				   INNER JOIN {$wpdb->usermeta} c ON c.user_id = u.ID AND c.meta_key = %s
				  WHERE m.meta_key = 'session_tokens'
				    AND ( u.user_email LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s )",
				$members,
				$like,
				$like,
				$like
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.display_name, m.meta_value
				   FROM {$wpdb->usermeta} m
				   INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
				   INNER JOIN {$wpdb->usermeta} c ON c.user_id = u.ID AND c.meta_key = %s
				  WHERE m.meta_key = 'session_tokens'
				    AND ( u.user_email LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s )
			   ORDER BY u.user_email ASC
				  LIMIT %d OFFSET %d",
				$members,
				$like,
				$like,
				$like,
				$per,
				$offset
			)
		);
	}
	// phpcs:enable

	$out = array();

	foreach ( (array) $rows as $row ) {
		$tokens = maybe_unserialize( $row->meta_value );
		$tokens = is_array( $tokens ) ? $tokens : array();

		if ( array() === $tokens ) {
			continue;
		}

		// The most recent session is the one describing the row; the rest only
		// count towards the number.
		usort( $tokens, static fn( $a, $b ): int => (int) ( $b['login'] ?? 0 ) <=> (int) ( $a['login'] ?? 0 ) );
		$last = $tokens[0];

		$out[] = array_merge(
			diluxone_users_user_agent( (string) ( $last['ua'] ?? '' ) ),
			array(
				'user_id'  => (int) $row->ID,
				'login'    => (string) $row->user_login,
				'email'    => (string) $row->user_email,
				'name'     => (string) $row->display_name,
				'sessions' => count( $tokens ),
				'ip'       => diluxone_users_session_ip_of( (array) $last ),
				'started'  => (int) ( $last['login'] ?? 0 ),
				'expires'  => (int) ( $last['expiration'] ?? 0 ),
			)
		);
	}

	return array(
		'rows'  => $out,
		'total' => $total,
	);
}

/** Closes every session of one user. For whoever administers only. */
function diluxone_users_sessions_admin_close(): void {
	check_admin_referer( 'diluxone_users_sessions_admin' );

	// Two questions. First, before anything of the request is read, whether
	// this is somebody who administers the site — the report the button lives
	// on asks for `manage_options`, and so does the button: on a single site
	// `edit_user` alone is granted by `edit_users`, so a role that may edit
	// users could otherwise sign an administrator out. Then whether they may
	// act on this person: on a network, `edit_user` is where WordPress keeps a
	// site's administrator off a super admin.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ), '', array( 'response' => 403 ) );
	}

	// One id, or nobody: absint() reads a list as 1, the first account.
	$user_id = isset( $_POST['diluxone_users_user'] ) && is_scalar( $_POST['diluxone_users_user'] ) ? absint( wp_unslash( $_POST['diluxone_users_user'] ) ) : 0;

	// Somebody there is: an id that is no account was answered "closed".
	if ( $user_id <= 0 || ! get_userdata( $user_id ) instanceof WP_User || ! current_user_can( 'edit_user', $user_id ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ), '', array( 'response' => 403 ) );
	}

	diluxone_users_2fa_forget_browsers( $user_id );
	WP_Session_Tokens::get_instance( $user_id )->destroy_all();

	$back = wp_get_referer();
	wp_safe_redirect( add_query_arg( 'diluxone_users_done', 'closed', $back ? $back : diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => 'sessions' ) ) ) );
	exit;
}
add_action( 'admin_post_diluxone_users_sessions_admin', 'diluxone_users_sessions_admin_close' );
