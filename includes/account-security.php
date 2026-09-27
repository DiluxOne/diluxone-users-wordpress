<?php
/**
 * What the person can do with their own security.
 *
 * Turning the second factor on, registering their authenticator app, writing
 * down their backup codes and closing sessions. All from the front end, on
 * the page the site has chosen, without going through the WordPress dashboard
 * — which most people on a site like this never see.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Does the site offer this person a second factor? */
function diluxone_users_2fa_offered( int $user_id ): bool {
	if ( 'off' === (string) diluxone_users_option( 'diluxone_users_2fa_mode' ) || array() === diluxone_users_2fa_methods() ) {
		return false;
	}

	// The same question the sign-in asks, in the same words: who is in the
	// scope. Reading the list of roles directly disagreed with it whenever the
	// scope was "everybody" with roles still ticked from before.
	return diluxone_users_scope_includes( $user_id, 'diluxone_users_2fa' );
}

/** Do they have it turned on? With the required mode, always. */
function diluxone_users_2fa_on( int $user_id ): bool {
	if ( ! diluxone_users_2fa_offered( $user_id ) ) {
		return false;
	}

	return 'required' === (string) diluxone_users_option( 'diluxone_users_2fa_mode' )
		|| (bool) get_user_meta( $user_id, 'diluxone_users_2fa_on', true );
}

/** Can they turn it off, or does the site demand it? */
function diluxone_users_2fa_can_turn_off( int $user_id ): bool {
	return 'required' !== (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
}

/** Saves what the person did on their security screen. */
function diluxone_users_security_submit(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( diluxone_users_login_url() );
		exit;
	}

	check_admin_referer( 'diluxone_users_security' );

	$user_id = get_current_user_id();
	$target  = diluxone_users_account_url( 'security' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
	$action = sanitize_key( wp_unslash( $_POST['diluxone_users_security'] ?? '' ) );
	$code   = sanitize_text_field( wp_unslash( $_POST['diluxone_users_code'] ?? '' ) );
	// phpcs:enable

	switch ( $action ) {
		case 'code':
			// The e-mail code, for whoever has no app: it has to be asked for
			// before it can be typed.
			if ( isset( diluxone_users_2fa_available( $user_id )['email'] ) ) {
				diluxone_users_2fa_email_send( $user_id );
			}

			wp_safe_redirect( add_query_arg( 'diluxone-users', 'codesent', $target ) );
			exit;

		case 'on':
			if ( array() === diluxone_users_2fa_available( $user_id ) ) {
				wp_safe_redirect( add_query_arg( 'diluxone-users', 'nomethod', $target ) );
				exit;
			}

			update_user_meta( $user_id, 'diluxone_users_2fa_on', 1 );
			diluxone_users_notify_security( $user_id, __( 'Two-step verification was turned on.', 'diluxone-users' ) );

				// The backup codes are generated when it is turned on, not later:
				// the moment to write them down is before needing them.
			if ( 0 === diluxone_users_backup_left( $user_id ) ) {
				diluxone_users_backup_stash( $user_id, diluxone_users_backup_generate( $user_id ) );
			}

			wp_safe_redirect( add_query_arg( 'diluxone-users', 'on', $target ) );
			exit;

		case 'off':
			if ( ! diluxone_users_2fa_can_turn_off( $user_id ) ) {
				wp_safe_redirect( add_query_arg( 'diluxone-users', 'required', $target ) );
				exit;
			}

			diluxone_users_security_confirm( $user_id, $code, $target );

			delete_user_meta( $user_id, 'diluxone_users_2fa_on' );
			// The browsers trusted while it was on are forgotten with it: if
			// it comes back on, they start from the challenge again.
			diluxone_users_2fa_forget_browsers( $user_id );
			diluxone_users_notify_security( $user_id, __( 'Two-step verification was turned off.', 'diluxone-users' ) );
			wp_safe_redirect( add_query_arg( 'diluxone-users', 'off', $target ) );
			exit;

		case 'totp':
			if ( ! diluxone_users_totp_activate( $user_id, $code ) ) {
				wp_safe_redirect( add_query_arg( 'diluxone-users', 'badcode', $target ) );
				exit;
			}

			update_user_meta( $user_id, 'diluxone_users_2fa_on', 1 );
			diluxone_users_notify_security( $user_id, __( 'An authenticator app was set up.', 'diluxone-users' ) );

			if ( 0 === diluxone_users_backup_left( $user_id ) ) {
				diluxone_users_backup_stash( $user_id, diluxone_users_backup_generate( $user_id ) );
			}

			wp_safe_redirect( add_query_arg( 'diluxone-users', 'totp', $target ) );
			exit;

		case 'totp_off':
			diluxone_users_security_confirm( $user_id, $code, $target );

			diluxone_users_totp_forget( $user_id );
			diluxone_users_2fa_forget_browsers( $user_id );
			diluxone_users_notify_security( $user_id, __( 'The authenticator app was removed.', 'diluxone-users' ) );
			wp_safe_redirect( add_query_arg( 'diluxone-users', 'totpoff', $target ) );
			exit;

		case 'backup':
			diluxone_users_security_confirm( $user_id, $code, $target );

			diluxone_users_backup_stash( $user_id, diluxone_users_backup_generate( $user_id ) );
			wp_safe_redirect( add_query_arg( 'diluxone-users', 'backup', $target ) );
			exit;
	}

	wp_safe_redirect( $target );
	exit;
}
add_action( 'admin_post_diluxone_users_security', 'diluxone_users_security_submit' );

/**
 * Stops an action that weakens the account unless a current code came with it.
 *
 * Turning the second step off, removing the app, replacing the backup codes:
 * with a stolen session — or a script the theme let in — each of those
 * removes the one thing still standing between the thief and the account. A
 * session is not enough for them; proof of the second step is, by any method
 * the person has ready. Without it, this sends them back and does not return.
 */
function diluxone_users_security_confirm( int $user_id, string $code, string $target ): void {
	if ( diluxone_users_2fa_reauth( $user_id, $code ) ) {
		return;
	}

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'reauth', $target ) );
	exit;
}

/**
 * The freshly generated codes, if they can still be shown.
 *
 * They live sealed in a one-minute transient and not in the user meta: they
 * are stored hashed, so this is the only window for seeing them, and that
 * window has to close on its own. The sealing and the opening are in
 * `auth.php`, beside the hashing they would otherwise undo.
 *
 * They are also gone on reading. The box says "shown only this once", and it
 * was saying it while they went on appearing on every reload for fifteen
 * minutes: either it is true or it should not be said.
 *
 * @return array<int, string>
 */
function diluxone_users_backup_fresh( int $user_id ): array {
	return diluxone_users_backup_unstash( $user_id );
}
