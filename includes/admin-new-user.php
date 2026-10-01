<?php
/**
 * An account made from the dashboard gets the same username as every other.
 *
 * Every door the plugin opens — the sign-up form, the e-mail link, a social
 * account — creates the account with the e-mail as its username. WordPress's
 * own Add New User screen asked for a username of its own, so an account made
 * there ended up as "claudia" beside a hundred others that are e-mails. Here
 * that screen takes the username from the e-mail too: the field is filled and
 * hidden in the browser, and the server sets it again before WordPress reads
 * it, so the result does not depend on the browser.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether accounts made from the dashboard take the e-mail as username.
 *
 * @since 1.0.0
 */
function diluxone_users_admin_login_is_email(): bool {
	// Not on a network: there a username may only hold lowercase letters and
	// numbers, so an address is refused as one, and every account made from
	// that screen would fail.
	if ( is_multisite() ) {
		return false;
	}

	/**
	 * Filters whether Add New User uses the e-mail as the username.
	 *
	 * @param bool $email True to use the e-mail, as every other door does.
	 */
	return (bool) apply_filters( 'diluxone_users_admin_login_is_email', true );
}

/**
 * Before user-new.php handles the form: the username is the e-mail.
 * load-user-new.php runs before the page reads $_POST, which is why it is
 * set here and not later.
 */
function diluxone_users_admin_new_user_login(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- user-new.php verifies its own nonce right after this; nothing is saved here.
	if ( ! diluxone_users_admin_login_is_email() || 'createuser' !== sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) || empty( $_POST['email'] ) || ! is_string( $_POST['email'] ) ) {
		return;
	}
	// $_POST arrives slashed and WordPress unslashes it when it reads it, so
	// what is put back has to be slashed the same way.
	$_POST['user_login'] = wp_slash( sanitize_email( wp_unslash( $_POST['email'] ) ) );
	// phpcs:enable
}
add_action( 'load-user-new.php', 'diluxone_users_admin_new_user_login' );

/** On the screen: the username row fills itself from the e-mail and steps aside. */
function diluxone_users_admin_new_user_script( string $hook ): void {
	if ( 'user-new.php' !== $hook || ! diluxone_users_admin_login_is_email() ) {
		return;
	}

	wp_enqueue_script( 'diluxone-users-new-user', DILUXONE_USERS_URL . 'assets/diluxone-users-new-user.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-new-user.js' ), true );

	wp_localize_script(
		'diluxone-users-new-user',
		'diluxOneUsersNewUser',
		array(
			'note' => __( 'The username is the e-mail address, as for every account on this site.', 'diluxone-users' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'diluxone_users_admin_new_user_script' );
