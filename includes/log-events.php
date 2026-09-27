<?php
/**
 * Where the rows come from.
 *
 * The table and the policy are in `log.php`; this file is only the ears. It is
 * separate for one reason and it is worth saying plainly: everything here
 * listens, and nothing here is listened to. No other file in the plugin calls
 * into this one, and taking it out would leave a log that still works and
 * simply never fills — which is what makes it safe for a feature nobody asked
 * for to sit in the middle of the sign-in.
 *
 * It also listens rather than being called for a second reason, less elegant
 * and more important: the parts that would otherwise have to report — the
 * passkeys, the second step, the account area — belong to other subjects, and
 * a log that needs a line added to twelve files is a log that goes quiet the
 * next time one of them is rewritten. Every hook below is either WordPress's
 * own or one this plugin already fired for something else.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/* ── The way in and the way out ────────────────────────────────────── */

/**
 * Every door: the e-mail link, a social account, a passkey, the password —
 * directly or once the second step is done. The password arrives here through
 * diluxone_users_password_logged_in(), which says why it only fires once the
 * session really is open.
 */
function diluxone_users_log_login( int $user_id, string $via ): void {
	diluxone_users_log_record( 'signed_in', $user_id, array( 'via' => $via ) );
}
add_action( 'diluxone_users_logged_in', 'diluxone_users_log_login', 10, 2 );

/** Leaving. */
function diluxone_users_log_logout( int $user_id ): void {
	diluxone_users_log_record( 'signed_out', $user_id );
}
add_action( 'wp_logout', 'diluxone_users_log_logout', 10, 1 );

/**
 * A sign-in that was refused.
 *
 * What is kept is what was typed and why it was refused, and the address it
 * came from — which is the row somebody is looking for when they ask whether
 * an account is being guessed at. The account id is 0 on purpose even when the
 * username exists: nobody signed in, so nothing happened to that account, and
 * a row filed under it would read as though something had.
 *
 * @param string        $login What was typed in the username box.
 * @param WP_Error|null $error Why WordPress said no.
 */
function diluxone_users_log_login_failed( string $login, $error = null ): void {
	diluxone_users_log_record(
		'sign_in_failed',
		0,
		array(
			'tried'  => $login,
			'reason' => $error instanceof WP_Error ? (string) $error->get_error_code() : '',
		)
	);
}
add_action( 'wp_login_failed', 'diluxone_users_log_login_failed', 10, 2 );

/**
 * A second step that was refused.
 *
 * It needs its own row because `wp_login_failed` never fires for it: the
 * password was right and WordPress said so, and what said no came after. A
 * log that only knows about the first door reads as quiet during exactly the
 * attack the second door exists for.
 *
 * The account id is kept here, unlike the row above, because there is no
 * doubt about whose account it is: getting this far means the password was
 * already right.
 *
 * @param int    $user_id Whose second step was being answered.
 * @param string $method  The way it was being answered, or `reauth`.
 */
function diluxone_users_log_2fa_failed( int $user_id, string $method ): void {
	diluxone_users_log_record( '2fa_failed', $user_id, array( 'via' => $method ) );
}
add_action( 'diluxone_users_2fa_failed', 'diluxone_users_log_2fa_failed', 10, 2 );

/* ── What somebody changed about themselves ────────────────────────── */

/**
 * The address, the password and the public name, in one pass.
 *
 * They arrive on the same hook because WordPress makes one update out of them,
 * and they are three events because they are three different pieces of news:
 * "your e-mail address changed" is the one somebody reads when an account was
 * taken over, and it would be lost inside a row that said "the profile was
 * saved".
 *
 * The old address is kept and the old password is not. The address is the
 * person's own and it is the only thing that makes the row worth reading — it
 * says where the account used to answer. A password, in any form, is not
 * something a log writes down.
 *
 * @param int     $user_id
 * @param WP_User $old What it was before WordPress wrote.
 */
function diluxone_users_log_profile_update( int $user_id, $old ): void {
	$now = get_userdata( $user_id );

	if ( ! $now instanceof WP_User || ! $old instanceof WP_User ) {
		return;
	}

	if ( $now->user_email !== $old->user_email ) {
		diluxone_users_log_record(
			'email_changed',
			$user_id,
			array(
				'was' => $old->user_email,
				'now' => $now->user_email,
			)
		);
	}

	if ( $now->user_pass !== $old->user_pass ) {
		diluxone_users_log_record( 'password_changed', $user_id );
	}

	if ( $now->display_name !== $old->display_name ) {
		diluxone_users_log_record(
			'name_changed',
			$user_id,
			array(
				'was' => $old->display_name,
				'now' => $now->display_name,
			)
		);
	}
}
add_action( 'profile_update', 'diluxone_users_log_profile_update', 10, 2 );

/**
 * The details this site asks people for.
 *
 * The field keys and not the values: what somebody put in a field the site
 * called "document number" is exactly the kind of thing a log has no business
 * keeping a second copy of, for ninety days, next to their address. Which
 * fields moved is what answers "was this me".
 *
 * And the keys of this plugin's fields, not the keys of what was posted. What
 * arrives is the whole submission — a registration form carries a password box
 * and a nonce and a redirect — and a row listing those would be noise with the
 * word "password" in it, which is the kind of row that gets a log read as
 * holding something it does not. Nothing matching means nothing of this
 * plugin's was touched, and then there is no news and no row: it is the case of
 * an account being created, where the fields did not change, they began.
 *
 * @param int                  $user_id
 * @param array<string, mixed> $input What was submitted.
 * @param string               $group The group of fields it was limited to, or ''.
 */
function diluxone_users_log_fields_saved( int $user_id, array $input, string $group = '' ): void {
	$keys = array();

	foreach ( diluxone_users_fields( $group ) as $field ) {
		if ( array_key_exists( (string) $field['key'], $input ) ) {
			$keys[] = (string) $field['key'];
		}
	}

	if ( array() === $keys ) {
		return;
	}

	diluxone_users_log_record( 'profile_saved', $user_id, array( 'fields' => implode( ', ', $keys ) ) );
}
add_action( 'diluxone_users_fields_saved', 'diluxone_users_log_fields_saved', 10, 3 );

/* ── What somebody changed about their security ────────────────────── */

/**
 * The three things worth knowing, all of which live in user meta.
 *
 * This is the part that looks odd and is not. The second step, the passkeys
 * and the open sessions are each stored in one meta key, and each is changed
 * from a different file for a different reason — none of which is "so that the
 * log hears about it". Asking those files to report would mean a line added to
 * each of them, and a line that goes missing the next time one is rewritten.
 *
 * So what is listened to is the write itself. `update_user_metadata` and
 * `delete_user_metadata` are filters and they are used here as the only thing
 * that answers the question these events need answering: what was it BEFORE.
 * They run before the row is written, so the old value is still readable, and
 * the decision is always a comparison — on from off, one passkey fewer, a
 * session that is gone. Both hand back what they were given untouched: a
 * listener that changed the answer would be deciding whether somebody's
 * passkey gets saved, which is not this file's business.
 *
 * @param mixed  $check     What the filters before decided. Always handed back.
 * @param int    $object_id Whose meta.
 * @param string $meta_key
 * @param mixed  $value     What is about to be written.
 * @return mixed
 */
function diluxone_users_log_meta_written( $check, $object_id, $meta_key, $value ) {
	if ( null === $check ) {
		diluxone_users_log_meta_change( (int) $object_id, (string) $meta_key, $value );
	}

	return $check;
}
add_filter( 'update_user_metadata', 'diluxone_users_log_meta_written', 10, 4 );

/**
 * The same, for the write that takes a key away entirely.
 *
 * `$delete_all` is the bulk case — every row of one key, for everybody — which
 * is a site being cleaned up or a plugin being removed, and not news about any
 * one account. It is left alone rather than writing one row per person.
 *
 * @param mixed  $check
 * @param int    $object_id
 * @param string $meta_key
 * @param mixed  $value
 * @param bool   $delete_all
 * @return mixed
 */
function diluxone_users_log_meta_deleted( $check, $object_id, $meta_key, $value, $delete_all = false ) {
	if ( null === $check && ! $delete_all ) {
		diluxone_users_log_meta_change( (int) $object_id, (string) $meta_key, array() );
	}

	return $check;
}
add_filter( 'delete_user_metadata', 'diluxone_users_log_meta_deleted', 10, 5 );

/**
 * Works out which piece of news a meta write is, if it is any.
 *
 * @param int    $user_id
 * @param string $key
 * @param mixed  $value What is about to be stored.
 */
function diluxone_users_log_meta_change( int $user_id, string $key, $value ): void {
	if ( 'diluxone_users_2fa_on' === $key ) {
		$was = (bool) get_user_meta( $user_id, $key, true );
		$now = ! is_array( $value ) && (bool) $value;

		if ( $was !== $now ) {
			diluxone_users_log_record( $now ? '2fa_on' : '2fa_off', $user_id );
		}

		return;
	}

	if ( 'diluxone_users_passkeys' === $key ) {
		// `get_user_meta()` answers '' for a key nobody has, and `(array) ''`
		// is a list with one empty string in it — so a cast alone counts the
		// first passkey anybody registers as no change at all, and the one
		// event this group exists for is the one that goes missing. The list
		// is asked whether it is a list.
		$stored = get_user_meta( $user_id, $key, true );
		$was    = is_array( $stored ) ? count( $stored ) : 0;
		$now    = is_array( $value ) ? count( $value ) : 0;

		if ( $now > $was ) {
			diluxone_users_log_record( 'passkey_added', $user_id, array( 'keys' => $now ) );
		} elseif ( $now < $was ) {
			diluxone_users_log_record( 'passkey_removed', $user_id, array( 'keys' => $now ) );
		}

		return;
	}

	if ( 'session_tokens' === $key ) {
		diluxone_users_log_sessions_change( $user_id, (array) $value );
	}
}

/**
 * Sessions that were closed, told apart from sessions that ran out.
 *
 * WordPress rewrites this key for three different reasons and only one of them
 * is worth a row. Signing in adds a session; housekeeping drops the ones that
 * had already expired; and closing sessions — from the account area, from the
 * report, or by signing out — takes away a session that was still good. Only
 * the third is something somebody did.
 *
 * So what is counted on each side is the sessions that have NOT expired. A
 * housekeeping pass takes away only expired ones, so both counts match and
 * nothing is written. That is also why this cannot be a simple "did the number
 * go down": it goes down every time WordPress tidies up, and a log that
 * announced "sessions closed" every few days for no reason is a log nobody
 * reads.
 *
 * The session doing the asking is left out of the count. Somebody signing out
 * closes their own session, and that is the `signed_out` row — counting it
 * again here would file every sign-out twice under two different names.
 *
 * @param array<string, mixed> $after What is about to be stored.
 */
function diluxone_users_log_sessions_change( int $user_id, array $after ): void {
	$stored = get_user_meta( $user_id, 'session_tokens', true );
	$before = is_array( $stored ) ? $stored : array();

	$gone = array_diff(
		array_keys( diluxone_users_log_live_sessions( $before ) ),
		array_keys( diluxone_users_log_live_sessions( $after ) )
	);

	if ( get_current_user_id() === $user_id && function_exists( 'wp_get_session_token' ) ) {
		$mine = (string) wp_get_session_token();
		$gone = array_diff( $gone, array( '' === $mine ? '' : hash( 'sha256', $mine ) ) );
	}

	if ( array() === $gone ) {
		return;
	}

	diluxone_users_log_record( 'sessions_closed', $user_id, array( 'closed' => count( $gone ) ) );
}

/**
 * Out of a stored session list, the ones that had not expired yet.
 *
 * @param array<string, mixed> $sessions
 * @return array<string, mixed>
 */
function diluxone_users_log_live_sessions( array $sessions ): array {
	$now = time();

	return array_filter(
		$sessions,
		static function ( $session ) use ( $now ): bool {
			return is_array( $session ) && (int) ( $session['expiration'] ?? 0 ) > $now;
		}
	);
}
