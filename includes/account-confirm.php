<?php
/**
 * The link in the confirmation e-mail, signed in.
 *
 * WordPress's link confirms the request the moment it is opened: whoever has
 * the e-mail has the account's data, or closes the account. For a request
 * filed from the account area the site can ask for more (Account → Sections →
 * Your data), and does by default:
 *
 *   - The link takes the person to their account, and asks them to sign in
 *     first if they are not. The sign-in proves the account as well as the
 *     mailbox, and it has to be the account that asked.
 *   - A copy is confirmed there and then. Closing the account is not: the
 *     account shows once more what is lost, and nothing happens until they
 *     press the button.
 *
 * Signing in may happen in another tab, through any door, including an
 * e-mail link of its own, so where they were going is kept in a short-lived
 * cookie and every door's `diluxone_users_login_redirect` sends them back to
 * it. Opened in a browser without that cookie, the confirmation link simply
 * works again once they are signed in: it stays good until it is used.
 *
 * What confirms is WordPress's own action, `user_request_action_confirmed`,
 * the one its link fires: WordPress marks the request confirmed and tells the
 * administrator, and this plugin carries it out when the site says so.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Where somebody who signs in to confirm is going, while they sign in. */
const DILUXONE_USERS_CONFIRM_COOKIE = 'diluxone_users_confirm';

/**
 * The request, if it was filed from the account area and its link asks for
 * the account.
 */
function diluxone_users_confirm_request( int $request_id ): ?WP_User_Request {
	$request = wp_get_user_request( $request_id );

	if ( ! $request instanceof WP_User_Request ) {
		return null;
	}

	$ours = 'remove_personal_data' === $request->action_name
		? diluxone_users_closing_request( $request_id ) > 0
		: diluxone_users_exporting_request( $request_id );

	$option = 'remove_personal_data' === $request->action_name ? 'diluxone_users_privacy_delete_link' : 'diluxone_users_privacy_export_link';

	return $ours && 'direct' !== diluxone_users_option( $option ) ? $request : null;
}

/** The account's page that confirms it. */
function diluxone_users_confirm_url( int $request_id, string $key ): string {
	return add_query_arg(
		array(
			'diluxone-users-request' => $request_id,
			'diluxone-users-key'     => rawurlencode( $key ),
		),
		diluxone_users_account_url( 'privacy' )
	);
}

/**
 * Sends somebody with no session to sign in, and remembers where they were going.
 *
 * @return never
 */
function diluxone_users_confirm_sign_in( int $request_id, string $key ): void {
	diluxone_users_cookie_set( DILUXONE_USERS_CONFIRM_COOKIE, $request_id . ':' . $key, time() + 20 * MINUTE_IN_SECONDS );

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'confirm', diluxone_users_login_url() ) );
	exit;
}

/**
 * The link in the e-mail, before WordPress confirms anything.
 *
 * `login_form_confirmaction` runs on wp-login.php before its own handling. A
 * link that is not good any more is left to WordPress, which says why.
 */
function diluxone_users_confirm_intercept(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the confirmation key is what vouches for this link, and it is checked below.
	$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
	$key        = isset( $_GET['confirm_key'] ) ? sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) ) : '';
	// phpcs:enable

	if ( null === diluxone_users_confirm_request( $request_id ) || true !== wp_validate_user_request_key( $request_id, $key ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		diluxone_users_confirm_sign_in( $request_id, $key );
	}

	wp_safe_redirect( diluxone_users_confirm_url( $request_id, $key ) );
	exit;
}
add_action( 'login_form_confirmaction', 'diluxone_users_confirm_intercept' );

/**
 * Back to the confirmation, whichever door they signed in through.
 *
 * The cookie is dropped once its request is no longer waiting; until then it
 * only decides where a sign-in lands, and the account checks the rest.
 *
 * @param string $redirect Where they would go.
 */
function diluxone_users_confirm_after_sign_in( $redirect ): string {
	$held = isset( $_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] ) ) : '';

	if ( '' === $held || ! str_contains( $held, ':' ) ) {
		return (string) $redirect;
	}

	[ $request_id, $key ] = explode( ':', $held, 2 );

	if ( null === diluxone_users_confirm_request( (int) $request_id ) || true !== wp_validate_user_request_key( (int) $request_id, $key ) ) {
		return (string) $redirect;
	}

	return diluxone_users_confirm_url( (int) $request_id, $key );
}
add_filter( 'diluxone_users_login_redirect', 'diluxone_users_confirm_after_sign_in', 99 );

/**
 * What the account's confirmation page is looking at.
 *
 * @return array{request: WP_User_Request|null, key: string, state: string}
 *         state: '' when there is nothing to confirm, 'ok' when the signed-in
 *         person can confirm it, 'other' when it is somebody else's, 'expired'
 *         when the link is no good any more.
 */
function diluxone_users_confirm_arrived(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the confirmation key is what vouches for this link, and it is checked below.
	$request_id = isset( $_GET['diluxone-users-request'] ) ? absint( $_GET['diluxone-users-request'] ) : 0;
	$key        = isset( $_GET['diluxone-users-key'] ) ? sanitize_text_field( wp_unslash( $_GET['diluxone-users-key'] ) ) : '';
	// phpcs:enable

	$nothing = array(
		'request' => null,
		'key'     => '',
		'state'   => '',
	);

	if ( $request_id <= 0 || '' === $key ) {
		return $nothing;
	}

	$request = diluxone_users_confirm_request( $request_id );

	if ( null === $request ) {
		return $nothing;
	}

	if ( true !== wp_validate_user_request_key( $request_id, $key ) ) {
		return array(
			'request' => $request,
			'key'     => '',
			'state'   => 'expired',
		);
	}

	$user = wp_get_current_user();

	return array(
		'request' => $request,
		'key'     => $key,
		'state'   => $user->exists() && 0 === strcasecmp( $user->user_email, $request->email ) ? 'ok' : 'other',
	);
}

/**
 * Arriving at the account from the link.
 *
 * Without a session, to sign in first. A copy is confirmed on arrival, and the
 * page comes back without the key in the address. An account closing is left
 * for the page to ask about.
 */
function diluxone_users_confirm_arrive(): void {
	$arrived = diluxone_users_confirm_arrived();

	if ( '' === $arrived['state'] || ! $arrived['request'] instanceof WP_User_Request ) {
		return;
	}

	$request = $arrived['request'];

	if ( ! is_user_logged_in() && 'expired' !== $arrived['state'] ) {
		diluxone_users_confirm_sign_in( (int) $request->ID, $arrived['key'] );
	}

	if ( 'ok' !== $arrived['state'] ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', $arrived['state'], diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	if ( 'export_personal_data' !== $request->action_name ) {
		return;
	}

	diluxone_users_confirm_now( (int) $request->ID );

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'request-completed' === get_post_status( (int) $request->ID ) ? 'ready' : 'confirmed', diluxone_users_account_url( 'privacy' ) ) );
	exit;
}
add_action( 'template_redirect', 'diluxone_users_confirm_arrive' );

/** Confirms it, the way WordPress's own link does. */
function diluxone_users_confirm_now( int $request_id ): void {
	/** This action is documented in wp-login.php */
	do_action( 'user_request_action_confirmed', $request_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own action, fired as its confirmation link fires it.

	if ( isset( $_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] ) ) {
		diluxone_users_cookie_set( DILUXONE_USERS_CONFIRM_COOKIE, '', time() - YEAR_IN_SECONDS );
	}
}

/**
 * "Yes, delete my account", pressed on the account.
 *
 * Checked again from the start: the key, that it is still waiting, and that
 * the account signed in is the one that asked. When the account is closed
 * there and then, the session it had is gone with it and the person lands on
 * the sign-in page, told so.
 */
function diluxone_users_confirm_close(): void {
	$request_id = isset( $_POST['diluxone_users_request_id'] ) ? absint( $_POST['diluxone_users_request_id'] ) : 0;

	check_admin_referer( 'diluxone_users_confirm_close_' . $request_id );

	$key     = isset( $_POST['diluxone_users_key'] ) ? sanitize_text_field( wp_unslash( $_POST['diluxone_users_key'] ) ) : '';
	$request = diluxone_users_confirm_request( $request_id );
	$user    = wp_get_current_user();

	if ( null === $request || 'remove_personal_data' !== $request->action_name || true !== wp_validate_user_request_key( $request_id, $key ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	if ( ! $user->exists() || 0 !== strcasecmp( $user->user_email, $request->email ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'other', diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	diluxone_users_confirm_now( $request_id );

	if ( 'request-completed' !== get_post_status( $request_id ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'confirmed', diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	wp_clear_auth_cookie();
	wp_safe_redirect( add_query_arg( 'diluxone-users', 'closed', diluxone_users_login_url() ) );
	exit;
}
add_action( 'admin_post_diluxone_users_confirm_close', 'diluxone_users_confirm_close' );
