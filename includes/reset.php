<?php
/**
 * Choosing a new password, on the site's own page.
 *
 * WordPress sends that link to wp-login.php and there is no clean filter for
 * the address it builds, so the link still goes there — and from there it is
 * sent on, with the same arguments, to the page the site uses for signing in.
 * One screen fewer that looks like somebody else's site at the exact moment
 * somebody is worried about their account.
 *
 * The key is handled the way WordPress handles it, and for the same reason:
 * it arrives in the address, is moved into a cookie, and the address is
 * cleaned. A key left in the URL leaks through the referrer of anything the
 * page loads, and lands in the browser history of a shared computer.
 *
 * On a site with no passwords none of this is ever reached: there is nothing
 * to reset. It is here for the sites that do have them.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The cookie the key travels in, the same shape WordPress uses. */
function diluxone_users_reset_cookie(): string {
	return 'diluxone-users-reset-' . COOKIEHASH;
}

/**
 * Takes the key out of the address and into a cookie.
 *
 * Runs before anything is printed, because it redirects.
 *
 * Only a key WordPress accepts is kept: it is checked with
 * check_password_reset_key() before the cookie is written, and one that is
 * expired, used or made up is answered "expired" with nothing kept.
 */
function diluxone_users_reset_catch(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link from an e-mail, opened by somebody who is not signed in, cannot carry a nonce: the reset key WordPress issued is the credential, and check_password_reset_key() validates it before anything is kept.
	if ( ! isset( $_GET['diluxone_users_key'], $_GET['diluxone_users_login'] ) || ! is_string( $_GET['diluxone_users_key'] ) || ! is_string( $_GET['diluxone_users_login'] ) ) {
		return;
	}

	$key   = sanitize_text_field( wp_unslash( $_GET['diluxone_users_key'] ) );
	$login = sanitize_user( wp_unslash( $_GET['diluxone_users_login'] ) );
	// phpcs:enable

	// Same address, without the key in it.
	$clean = remove_query_arg( array( 'diluxone_users_key', 'diluxone_users_login' ) );

	if ( ! check_password_reset_key( $key, $login ) instanceof WP_User ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', $clean ) );
		exit;
	}

	// Until the browser closes, and only for this site's path.
	diluxone_users_cookie_set( diluxone_users_reset_cookie(), $login . ':' . $key, 0 );

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'reset', $clean ) );
	exit;
}
add_action( 'template_redirect', 'diluxone_users_reset_catch' );

/**
 * Whose reset is in progress, if any.
 *
 * @return WP_User|null
 */
function diluxone_users_reset_user(): ?WP_User {
	$cookie = isset( $_COOKIE[ diluxone_users_reset_cookie() ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ diluxone_users_reset_cookie() ] ) ) : '';

	if ( false === strpos( $cookie, ':' ) ) {
		return null;
	}

	[ $login, $key ] = explode( ':', $cookie, 2 );

	$user = check_password_reset_key( sanitize_text_field( $key ), sanitize_user( $login ) );

	return $user instanceof WP_User ? $user : null;
}

/** Forgets the key, whatever happened. */
function diluxone_users_reset_forget(): void {
	diluxone_users_cookie_set( diluxone_users_reset_cookie(), ' ', time() - YEAR_IN_SECONDS );
}

/**
 * Saves the new password.
 *
 * It ends on the sign-in page either way: with the password changed, or
 * saying why not.
 */
function diluxone_users_reset_save(): void {
	$back = diluxone_users_login_url();

	if ( ! isset( $_POST['diluxone_users_reset_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_reset_nonce'] ) ), 'diluxone_users_reset' ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', $back ) );
		exit;
	}

	$user = diluxone_users_reset_user();

	if ( ! $user instanceof WP_User ) {
		// Expired, used, or tampered with. The three look the same from here
		// and the answer is the same: ask for another one.
		diluxone_users_reset_forget();
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', $back ) );
		exit;
	}

	/*
	 * `is_scalar` before the cast, and it is not defensiveness for its own
	 * sake: posting `diluxone_users_pass[]=x` made both of these the literal
	 * string "Array", which compares equal to itself, and the account's
	 * password was then set to the word Array.
	 */
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is not sanitised, it is used as typed.
	$typed   = wp_unslash( $_POST['diluxone_users_pass'] ?? '' );
	$typed2  = wp_unslash( $_POST['diluxone_users_pass2'] ?? '' );
	$pass    = is_scalar( $typed ) ? (string) $typed : '';
	$confirm = is_scalar( $typed2 ) ? (string) $typed2 : '';
	// phpcs:enable

	if ( '' === $pass || $pass !== $confirm ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'nomatch', $back ) );
		exit;
	}

	reset_password( $user, $pass );
	diluxone_users_reset_forget();

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'changed', $back ) );
	exit;
}
add_action( 'admin_post_nopriv_diluxone_users_reset', 'diluxone_users_reset_save' );
add_action( 'admin_post_diluxone_users_reset', 'diluxone_users_reset_save' );

/**
 * A new password ends every session the old one opened.
 *
 * Somebody resets a password because the old one may be in somebody else's
 * hands, and whoever holds it may already be signed in: a reset that leaves
 * their sessions open has changed the lock and left the door ajar. On
 * `after_password_reset`, which WordPress fires for the site's own reset page
 * and for wp-login.php alike. A reset signs nobody in, so every session goes.
 *
 * @param WP_User $user
 */
function diluxone_users_reset_ends_sessions( $user ): void {
	if ( $user instanceof WP_User ) {
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
	}
}
add_action( 'after_password_reset', 'diluxone_users_reset_ends_sessions' );
