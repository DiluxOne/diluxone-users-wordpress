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
