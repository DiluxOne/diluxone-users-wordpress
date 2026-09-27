<?php
/**
 * The second factor by e-mail.
 *
 * It is the weakest of the three — whoever has the e-mail has the code — and
 * at the same time the only one that requires installing nothing, so it tends
 * to be the one that gets people to turn the second factor on. That is what
 * it is worth: having one at all.
 *
 * The code is stored hashed and with an expiry, the same as the sign-in link,
 * and for the same reason: a user meta holding a code in the clear is a
 * temporary password written in the database.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** How long the code sent by e-mail is good for. */
const DILUXONE_USERS_2FA_EMAIL_TTL = 10 * MINUTE_IN_SECONDS;

/** Generates, stores and sends the code. */
function diluxone_users_2fa_email_send( int $user_id ): bool {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return false;
	}

	$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

	update_user_meta(
		$user_id,
		'diluxone_users_2fa_email',
		array(
			'hash'    => wp_hash( $code ),
			'expires' => time() + DILUXONE_USERS_2FA_EMAIL_TTL,
		)
	);

	$subject = sprintf(
		/* translators: %s: site name */
		__( 'Your code for %s', 'diluxone-users' ),
		wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
	);

	$body = sprintf(
		/* translators: 1: the code, 2: how many minutes it lasts */
		__( "Your sign-in code is:\n\n%1\$s\n\nIt is good for %2\$d minutes. If you did not ask for it, ignore this message: without the code nobody gets in.", 'diluxone-users' ),
		$code,
		(int) ( DILUXONE_USERS_2FA_EMAIL_TTL / MINUTE_IN_SECONDS )
	);

	/**
	 * Filters the second-factor e-mail.
	 *
	 * @param array{subject: string, body: string} $mail
	 * @param int                                  $user_id
	 * @param string                               $code
	 */
	$mail = (array) apply_filters(
		'diluxone_users_2fa_email',
		array(
			'subject' => $subject,
			'body'    => $body,
		),
		$user_id,
		$code
	);

	return wp_mail( $user->user_email, (string) $mail['subject'], (string) $mail['body'] );
}

/** Is the code they typed the one that was sent, and is it still alive? */
function diluxone_users_2fa_email_verify( int $user_id, string $code ): bool {
	// With no meta stored this returns '', and `(array) ''` is `array( '' )`: an
	// array that is not empty. The type is asked about, not the content.
	$stored = get_user_meta( $user_id, 'diluxone_users_2fa_email', true );
	$code   = preg_replace( '/\D/', '', $code ) ?? '';

	if ( ! is_array( $stored ) || ! isset( $stored['hash'], $stored['expires'] ) ) {
		return false;
	}

	if ( (int) $stored['expires'] < time() || '' === $code ) {
		return false;
	}

	if ( ! hash_equals( (string) $stored['hash'], wp_hash( $code ) ) ) {
		return false;
	}

	// Single use, and the delete is the check: of two requests carrying the
	// same code, only the one that removed it gets through.
	return delete_user_meta( $user_id, 'diluxone_users_2fa_email' );
}
