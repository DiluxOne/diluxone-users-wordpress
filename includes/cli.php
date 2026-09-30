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
 * to the end and in one go, rather than waiting for the first request and cron
 * to do it (see migrate.php).
 *
 *   wp diluxone-users network migrate
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
 * Moves a network's settings from each site to the network, now.
 *
 * The same move the first request after an update makes on its own, done to
 * the end in one go: for a network of thousands of sites that would rather not
 * wait for cron, or for somebody who wants to read what it found. Running it
 * again does nothing.
 *
 * ## EXAMPLES
 *
 *     wp diluxone-users network migrate
 */
function diluxone_users_cli_network_migrate(): void {
	if ( ! is_multisite() ) {
		WP_CLI::error( 'This is not a network: there is nothing to move.' );
	}

	if ( ! diluxone_users_scoped_storage_active() ) {
		WP_CLI::error( 'The plugin is not active for the whole network, so each site keeps its own settings.' );
	}

	if ( diluxone_users_network_migrated() ) {
		WP_CLI::success( 'The network’s settings were already moved.' );

		return;
	}

	while ( ! diluxone_users_network_migrate() ) {
		WP_CLI::log( 'One batch of sites done.' );
	}

	wp_clear_scheduled_hook( DILUXONE_USERS_NETWORK_MIGRATE_EVENT );

	$conflicts = (array) diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS, array() );

	foreach ( $conflicts as $conflict ) {
		WP_CLI::log( sprintf( 'Site %d had %s set differently.', (int) $conflict['site'], (string) ( $conflict['field'] ?? $conflict['key'] ) ) );
	}

	WP_CLI::success( sprintf( 'The network’s settings were moved. %d differences written down.', count( $conflicts ) ) );
}

WP_CLI::add_command( 'diluxone-users network migrate', 'diluxone_users_cli_network_migrate' );
