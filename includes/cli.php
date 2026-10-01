<?php
/**
 * WP-CLI commands.
 *
 * It exists for one concrete case: a site without passwords where the mail
 * does not go out. If somebody is locked out there — the session expired, the
 * social provider fails — there is no door. With access to the server, this
 * command prints the link in the terminal instead of sending it.
 *
 *   wp diluxone-users login pablo@example.com
 *
 * And one for networks: moving the settings from each site to the network,
 * and each site's activity log into the network's table, to the end and in one
 * go, rather than waiting for the first request and cron to do it (see
 * migrate.php and migrate-log.php).
 *
 *   wp diluxone-users network migrate
 *
 * And one for a network whose policy is that every account is a member of
 * every site: adding the accounts and sites that were already there, to the
 * end (see membership.php).
 *
 *   wp diluxone-users network membership sync
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Prints a sign-in link for an e-mail address.
 *
 * ## OPTIONS
 *
 * <email>
 * : The account e-mail.
 *
 * [--send]
 * : Besides printing it, send it by e-mail.
 *
 * ## EXAMPLES
 *
 *     wp diluxone-users login pablo@example.com
 *     wp diluxone-users login pablo@example.com --send
 *
 * @param array<int, string>    $args
 * @param array<string, string> $options
 */
function diluxone_users_cli_login( array $args, array $options = array() ): void {
	$email = sanitize_email( $args[0] ?? '' );

	if ( '' === $email || ! is_email( $email ) ) {
		WP_CLI::error( 'A valid e-mail address is needed.' );
	}

	$user = get_user_by( 'email', $email );

	if ( ! $user ) {
		WP_CLI::error( sprintf( 'There is no account with the e-mail %s.', $email ) );
	}

	$token = diluxone_users_token_create( (int) $user->ID );
	$url   = diluxone_users_login_link( (int) $user->ID, $token );

	if ( ! empty( $options['send'] ) ) {
		$sent = diluxone_users_login_send( (int) $user->ID, $email, $token );
		WP_CLI::log( $sent ? 'E-mail sent.' : 'The e-mail could not be sent.' );
	}

	WP_CLI::log( $url );
	WP_CLI::log( sprintf( 'It expires in %d minutes and works once.', diluxone_users_login_expiry() ) );
}

WP_CLI::add_command( 'diluxone-users login', 'diluxone_users_cli_login' );

/**
 * Moves a network's settings from each site to the network, now, and each
 * site's activity log into the network's table.
 *
 * The same moves the first request after an update makes on its own, done to
 * the end in one go: for a network of thousands of sites, or of millions of
 * rows, that would rather not wait for cron, or for somebody who wants to read
 * what they found. Running it again does nothing.
 *
 * ## EXAMPLES
 *
 *     wp diluxone-users network migrate
 */
function diluxone_users_cli_network_migrate(): void {
	if ( ! is_multisite() ) {
		WP_CLI::error( 'This is not a network: there is nothing to move.' );
	}

	if ( diluxone_users_network_migrated() ) {
		WP_CLI::log( 'The network’s settings were already moved.' );
	} else {
		while ( ! diluxone_users_network_migrate() ) {
			WP_CLI::log( 'One batch of sites done.' );
		}

		wp_clear_scheduled_hook( DILUXONE_USERS_NETWORK_MIGRATE_EVENT );

		$conflicts = (array) diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS, array() );

		foreach ( $conflicts as $conflict ) {
			WP_CLI::log( sprintf( 'Site %d had %s set differently.', (int) $conflict['site'], (string) ( $conflict['field'] ?? $conflict['key'] ) ) );
		}

		WP_CLI::log( sprintf( 'The network’s settings were moved. %d differences written down.', count( $conflicts ) ) );
	}

	diluxone_users_cli_log_move();

	WP_CLI::success( 'The network’s settings and activity log are where they belong.' );
}

WP_CLI::add_command( 'diluxone-users network migrate', 'diluxone_users_cli_network_migrate' );

/** The sites' activity logs, moved into the network's table to the end. */
function diluxone_users_cli_log_move(): void {
	if ( diluxone_users_log_moved() ) {
		WP_CLI::log( 'The sites’ activity logs were already in the network’s table.' );

		return;
	}

	$said = static function ( int $site, int $copied, bool $dropped ): void {
		WP_CLI::log(
			$dropped
				? sprintf( 'Site %d: %d rows moved, its old table dropped.', $site, $copied )
				: sprintf( 'Site %d: %d rows moved, and its old table kept: it has a different number of rows.', $site, $copied )
		);
	};

	$tries = 0;

	while ( ! diluxone_users_log_move( DILUXONE_USERS_LOG_MOVE_ROWS, $said ) ) {
		// A batch the database refused comes back unchanged. Three in a row
		// is not going to change by trying a fourth.
		$state = diluxone_users_raw_get( DILUXONE_USERS_LOG_MOVING, null );
		$tries = $state === ( $last ?? null ) ? $tries + 1 : 0;
		$last  = $state;

		if ( $tries >= 3 ) {
			WP_CLI::error( 'The database refused to copy the activity log. Nothing was lost; run it again once it is fixed.' );
		}
	}

	wp_clear_scheduled_hook( DILUXONE_USERS_LOG_MOVE_EVENT );

	$kept = (array) diluxone_users_raw_get( DILUXONE_USERS_LOG_KEPT, array() );

	WP_CLI::log( sprintf( 'The sites’ activity logs are in the network’s table. %d old tables kept.', count( $kept ) ) );
}

/**
 * Makes every account of the network a member of every live site, now.
 *
 * What "Sync everyone now" on Network Admin › Membership starts, done to the
 * end in one go instead of by cron. Only under the "every site" policy; a
 * removal an administrator made stays, super admins are left out, and each
 * site gives its own role.
 *
 * ## EXAMPLES
 *
 *     wp diluxone-users network membership sync
 */
function diluxone_users_cli_membership_sync(): void {
	if ( ! is_multisite() ) {
		WP_CLI::error( 'This is not a network: every account is a member of the only site there is.' );
	}

	if ( ! diluxone_users_membership_confirmed() ) {
		WP_CLI::error( 'The network’s membership policy has not been confirmed: until it is, nobody is added anywhere. Confirm it on Network Admin › Membership.' );
	}

	if ( 'all' !== diluxone_users_membership() ) {
		WP_CLI::error( sprintf( 'The network’s membership policy is “%s”: only “all” adds everybody to every site.', diluxone_users_membership() ) );
	}

	$pairs = diluxone_users_membership_enqueue_all();

	WP_CLI::log( sprintf( 'Looking at %d additions (accounts times live sites).', $pairs ) );

	do {
		$drained = diluxone_users_membership_drain( 1 );

		if ( null === $drained ) {
			WP_CLI::error( 'Another run is adding people right now. Try again in a few minutes.' );
		}

		foreach ( diluxone_users_membership_queue() as $job ) {
			WP_CLI::log( sprintf( '%s: %d of %d.', $job['kind'], $job['done'], $job['total'] ) );
		}
	} while ( true !== $drained );

	diluxone_users_on_hub( static fn() => wp_clear_scheduled_hook( DILUXONE_USERS_MEMBERSHIP_EVENT ) );

	WP_CLI::success( 'Every account is a member of every live site, except where an administrator removed it.' );
}

WP_CLI::add_command( 'diluxone-users network membership sync', 'diluxone_users_cli_membership_sync' );
