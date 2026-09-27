<?php
/**
 * The heart of authentication: which doors exist and what each one asks for.
 *
 * A site does not choose "one" way in: it chooses a set. It can have a
 * password and an e-mail link at the same time, it can add social networks on
 * top, and it can ask for a second factor on all of them, on some, or on
 * none. Those combinations cannot be resolved with a chain of `if`s: what is
 * needed is one place where they are enumerated and a single path everybody
 * goes through.
 *
 * That path is `diluxone_users_complete_login()`. However they get in —
 * password, link, social network — everyone ends up there, and there it is
 * decided whether the session opens or whether something else has to be
 * proved first. Without it, adding a second factor would mean remembering to
 * add it at every door, and the one that gets forgotten stays open.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** How long a half-finished second-factor attempt lives. */
const DILUXONE_USERS_2FA_WINDOW = 10 * MINUTE_IN_SECONDS;

/**
 * How many wrong codes one attempt survives.
 *
 * A six-digit code has a million answers and a ten-minute window: with no
 * limit, a script that already has the password tries them all in time. Five
 * is enough for a person mistyping twice and reading the wrong app once, and
 * nowhere near enough for a script.
 */
const DILUXONE_USERS_2FA_TRIES = 5;

/** Seconds between two e-mails with a code, so the button is not a mail cannon. */
const DILUXONE_USERS_2FA_RESEND_WAIT = 60;

/**
 * How many wrong codes an ACCOUNT survives, counting across attempts.
 *
 * The five above belong to one attempt and the attempt is thrown away when
 * they run out — which reads like a limit and is not one, because starting
 * another attempt costs whoever has the password a single request. This is
 * the count that does not reset: it belongs to the account, it survives the
 * attempt being destroyed, and only a code that is actually right clears it.
 */
const DILUXONE_USERS_2FA_LOCK_AFTER = 10;

/** The first wait once that count is reached. It doubles with every failure after. */
const DILUXONE_USERS_2FA_LOCK_WAIT = 5 * MINUTE_IN_SECONDS;

/** The longest that wait ever grows to, so a locked account is never locked for good. */
const DILUXONE_USERS_2FA_LOCK_MAX = 6 * HOUR_IN_SECONDS;

/**
 * Sets one of the plugin's cookies, the same way every time.
 *
 * Every cookie the plugin writes is HttpOnly, Secure over HTTPS and
 * SameSite=Lax — the last one is what keeps another site from riding on it.
 * The filter is the seam a test uses to look at the cookie instead of letting
 * PHP write a header the CLI has nowhere to send; a site that hands its
 * cookies to another layer can use it the same way.
 */
function diluxone_users_cookie_set( string $name, string $value, int $expires ): void {
	$options = array(
		'expires'  => $expires,
		'path'     => defined( 'COOKIEPATH' ) && '' !== (string) COOKIEPATH ? (string) COOKIEPATH : '/',
		'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	);

	/**
	 * Filters a cookie before it is written. Anything but an array writes nothing.
	 *
	 * @param array<string, mixed> $options The setcookie() options.
	 * @param string               $name
	 * @param string               $value
	 */
	$options = apply_filters( 'diluxone_users_cookie', $options, $name, $value );

	if ( ! is_array( $options ) ) {
		return;
	}

	setcookie( $name, $value, $options );
}

/* ── Los segundos factores disponibles ─────────────────────────────── */

/*
 * * A passkey is not on this list, and that is not an oversight: it is not a
 * * second factor but a way in that already carries both inside — something
 * * you have, the device, and something you are or know, the fingerprint or
 * * the PIN. Putting it here would mean asking three things of someone who
 * * already gave two.
 */

/**
 * The second-factor methods the plugin knows how to handle.
 *
 * It is a registry and not a fixed list: an add-on adds its own without
 * touching this, the same as the account-area sections do.
 *
 * Each one declares:
 *   label     What it is called for people.
 *   help      What it is, in one line.
 *   ready     Function that says whether THAT person already has it set up.
 *   send      Optional: what to do when the challenge starts (send the e-mail).
 *   verify    Function that validates what the person typed.
 *   position  Preference order when there is more than one.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_2fa_methods(): array {
	$methods = array(
		'email' => array(
			// Which channel the second step arrives through. It is what makes it
			// possible to decide whether it adds anything when someone already
			// came in through that same channel.
			'channel'  => 'email',
			'label'    => __( 'A code by email', 'diluxone-users' ),
			'help'     => __( 'We send a six-digit code to the address on the account. Nothing to install.', 'diluxone-users' ),
			'ready'    => static fn( int $user_id ): bool => true,
			'send'     => 'diluxone_users_2fa_email_send',
			'verify'   => 'diluxone_users_2fa_email_verify',
			'position' => 20,
		),
		'totp'  => array(
			'channel'  => 'device',
			'label'    => __( 'An authenticator app', 'diluxone-users' ),
			'help'     => __( 'The six-digit code that changes every thirty seconds, from Google Authenticator, 1Password, Aegis or whichever one you use.', 'diluxone-users' ),
			'ready'    => 'diluxone_users_totp_ready',
			'verify'   => 'diluxone_users_totp_verify',
			'position' => 10,
		),
	);

	/**
	 * Filters the second-factor methods.
	 *
	 * @param array<string, array<string, mixed>> $methods
	 */
	$methods = (array) apply_filters( 'diluxone_users_2fa_methods', $methods );

	// The ones the site turned off do not exist for anybody.
	$enabled = (array) diluxone_users_option( 'diluxone_users_2fa_methods' );

	$methods = array_filter(
		$methods,
		static fn( string $id ): bool => in_array( $id, $enabled, true ),
		ARRAY_FILTER_USE_KEY
	);

	uasort( $methods, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

	return $methods;
}

/**
 * The methods THIS person has ready to use right now.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_2fa_available( int $user_id ): array {
	return array_filter(
		diluxone_users_2fa_methods(),
		static fn( array $m ): bool => is_callable( $m['ready'] ) && call_user_func( $m['ready'], $user_id )
	);
}

/* ── The policy: who gets asked ────────────────────────────────────── */

/**
 * Does this person have to go through a second factor?
 *
 * Three things decide, in this order:
 *
 *   1. The site mode. Off is never asked; optional is asked only of whoever
 *      turned it on; required is asked of everybody.
 *   2. The chosen roles, when the list is not empty. Useful for asking it of
 *      whoever administers the site and not of the 25,000 who only watch a
 *      course.
 *   3. How they got in. A single-use link sent to their e-mail already proves
 *      whoever is coming in has that e-mail; asking them on top for a code
 *      sent to the same e-mail is asking the same thing twice. That is why it
 *      is a separate setting and comes turned off: a site that wants the
 *      second factor anyway turns it on.
 *
 * @param string $via 'password', 'link' or 'sso'.
 */
function diluxone_users_2fa_required( int $user_id, string $via ): bool {
	$mode = (string) diluxone_users_option( 'diluxone_users_2fa_mode' );

	if ( 'off' === $mode ) {
		return false;
	}

	if ( array() === diluxone_users_2fa_available( $user_id ) ) {
		return false;
	}

	if ( 'link' === $via && ! diluxone_users_2fa_worth_it_on_link( $user_id ) ) {
		return false;
	}

	if ( ! diluxone_users_scope_includes( $user_id, 'diluxone_users_2fa' ) ) {
		return false;
	}

	if ( 'required' === $mode ) {
		return true;
	}

	// Optional: only for whoever turned it on.
	return (bool) get_user_meta( $user_id, 'diluxone_users_2fa_on', true );
}

/**
 * Is it worth asking the second step of someone who came in by e-mail link?
 *
 * On automatic, yes only when there is a method that does NOT arrive by
 * e-mail. A code sent to the same inbox the person just opened to follow the
 * link proves nothing the link has not proved already; an authenticator app
 * or a key does.
 *
 * The site can force both answers, but automatic is the one that avoids both
 * the open door and the errand that serves no purpose.
 */
function diluxone_users_2fa_worth_it_on_link( int $user_id ): bool {
	$mode = (string) diluxone_users_option( 'diluxone_users_2fa_link' );

	if ( 'always' === $mode ) {
		return true;
	}

	if ( 'never' === $mode ) {
		return false;
	}

	foreach ( diluxone_users_2fa_available( $user_id ) as $method ) {
		if ( 'email' !== ( $method['channel'] ?? 'email' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The ways in the site offers, and whether this person is asked for the
 * second step on each of them.
 *
 * It exists so the security screen tells the truth. "You are not being asked"
 * is false as soon as one social network is on: the e-mail-link exception
 * belongs to the e-mail link alone, because there the second code would go to
 * the same inbox the person just opened.
 *
 * Passkeys are not on the list because they do not come through here: a
 * passkey is already two factors in one step, and that is accounted for in
 * its own box.
 *
 * @return array<string, array{label: string, asked: bool}>
 */
function diluxone_users_2fa_ways( int $user_id ): array {
	$ways = array();

	if ( diluxone_users_login_has_link() ) {
		$ways['link'] = __( 'the link we email you', 'diluxone-users' );
	}

	if ( diluxone_users_login_has_password() ) {
		$ways['password'] = __( 'your password', 'diluxone-users' );
	}

	if ( function_exists( 'diluxone_users_sso_available' ) && array() !== diluxone_users_sso_available() ) {
		$ways['sso'] = __( 'a social account', 'diluxone-users' );
	}

	$out = array();

	foreach ( $ways as $via => $label ) {
		$out[ $via ] = array(
			'label' => $label,
			'asked' => diluxone_users_2fa_required( $user_id, $via ),
		);
	}

	return $out;
}

/**
 * The ways in where the second step is actually asked for.
 *
 * @return array<int, string>
 */
function diluxone_users_2fa_ways_asked( int $user_id ): array {
	return array_values(
		wp_list_pluck(
			array_filter( diluxone_users_2fa_ways( $user_id ), static fn( array $w ): bool => $w['asked'] ),
			'label'
		)
	);
}

/**
 * Has this browser passed the second factor recently?
 *
 * The cookie grants no access: it only avoids repeating the challenge in the
 * same browser for as many days as the setting says. It is signed with the
 * site salts, so it cannot be forged, and it carries the id of whoever asked
 * for it.
 */
function diluxone_users_2fa_trusted( int $user_id ): bool {
	$days = (int) diluxone_users_option( 'diluxone_users_2fa_remember_days' );

	if ( $days <= 0 ) {
		return false;
	}

	$cookie = isset( $_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] ) ) : '';

	if ( '' === $cookie ) {
		return false;
	}

	[ $stored_id, $expires, $hash ] = array_pad( explode( '|', $cookie, 3 ), 3, '' );

	if ( (int) $stored_id !== $user_id || (int) $expires < time() ) {
		return false;
	}

	return hash_equals( diluxone_users_2fa_trust_hash( $user_id, (int) $expires ), $hash );
}

/**
 * The signature inside the trust cookie.
 *
 * It covers the epoch as well as the id and the expiry: that is what makes
 * the cookie revocable. Everything else in it is fixed for the life of the
 * account, so a cookie that only signed those could not be taken back short
 * of changing the site salts — and turning the second step off and on again,
 * or closing every session, has to take it back.
 */
function diluxone_users_2fa_trust_hash( int $user_id, int $expires ): string {
	$epoch = (string) get_user_meta( $user_id, 'diluxone_users_2fa_epoch', true );

	return wp_hash( $user_id . '|' . $expires . '|' . $epoch, 'secure_auth' );
}

/**
 * Forgets every browser this person marked as trusted.
 *
 * The cookies are not reachable — they live in other browsers — so what
 * changes is the epoch they were signed with, and every one of them stops
 * verifying at once. It is called when the second step is turned off or
 * changed, and when the person closes their other sessions: a session that
 * was closed should not come back in without the second step.
 */
function diluxone_users_2fa_forget_browsers( int $user_id ): void {
	update_user_meta( $user_id, 'diluxone_users_2fa_epoch', time() . '.' . wp_generate_password( 8, false, false ) );
}

/** What the trust cookie holds: who, until when, and the signature over both. */
function diluxone_users_2fa_trust_value( int $user_id, int $expires ): string {
	return $user_id . '|' . $expires . '|' . diluxone_users_2fa_trust_hash( $user_id, $expires );
}

/** Marks this browser so it is not asked again for a few days. */
function diluxone_users_2fa_trust( int $user_id ): void {
	$days = (int) diluxone_users_option( 'diluxone_users_2fa_remember_days' );

	if ( $days <= 0 ) {
		return;
	}

	$expires = time() + $days * DAY_IN_SECONDS;

	diluxone_users_cookie_set( 'diluxone_users_2fa_' . COOKIEHASH, diluxone_users_2fa_trust_value( $user_id, $expires ), $expires );
}

/* ── The single way in ─────────────────────────────────────────────── */

/**
 * Closes somebody's sign-in: either opens the session, or asks for the second
 * factor.
 *
 * @param int    $user_id  Who is coming in.
 * @param string $via      Through which door: 'password', 'link' or 'sso'.
 * @param bool   $remember Long session.
 * @param string $redirect Where they go afterwards.
 */
function diluxone_users_complete_login( int $user_id, string $via, bool $remember = true, string $redirect = '' ): void {
	// Every door ends here once the person has proved who they are — the link
	// clicked, the provider answered, the passkey signed — and on a network
	// that is the moment they become a member of this site, if it takes
	// members. Not before: asking for a link proves nothing.
	diluxone_users_join_site( $user_id );

	$redirect = '' !== $redirect ? $redirect : (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user_id );

	// A passkey goes straight in: it already proved both things.
	if ( 'passkey' === $via || ! diluxone_users_2fa_required( $user_id, $via ) || diluxone_users_2fa_trusted( $user_id ) ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember );

		/**
		 * Somebody came in, with everything that was needed already done.
		 *
		 * @param int    $user_id
		 * @param string $via
		 */
		do_action( 'diluxone_users_logged_in', $user_id, $via );

		wp_safe_redirect( $redirect );
		exit;
	}

	diluxone_users_2fa_challenge( $user_id, $via, $remember, $redirect );
}

/**
 * Stores the half-done sign-in and sends them to the second-factor screen.
 *
 * What is pending lives in a user meta and not in the session: the attempt
 * has to survive the person opening the screen in another tab, and it cannot
 * depend on a session cookie that does not exist yet.
 */
function diluxone_users_2fa_challenge( int $user_id, string $via, bool $remember, string $redirect ): void {
	// If some door already opened a session, it is closed: a session opened
	// before the second factor is having no second factor. Clearing the
	// cookie is not enough — the valid one is already in the response
	// headers, ahead of the expired one, and a client that keeps the first
	// is signed in. The session it names has to stop existing.
	foreach ( diluxone_users_2fa_session_tokens( $user_id ) as $token ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
	}

	wp_clear_auth_cookie();

	$nonce = diluxone_users_2fa_pending_start( $user_id, $via, $remember, $redirect );

	$methods = diluxone_users_2fa_available( $user_id );

		// Coming in by e-mail link, it starts with a method that is not another
		// e-mail: sending a code to the inbox they just opened would be making
		// them repeat the same step.
	if ( 'link' === $via ) {
		foreach ( $methods as $id => $m ) {
			if ( 'email' !== ( $m['channel'] ?? 'email' ) ) {
				$methods = array( $id => $m ) + $methods;
				break;
			}
		}
	}

	$method = (string) array_key_first( $methods );

	diluxone_users_2fa_send( $user_id, $method );

	wp_safe_redirect(
		add_query_arg(
			array(
				'diluxone_users_2fa'    => $user_id,
				'diluxone_users_key'    => $nonce,
				'diluxone_users_method' => $method,
			),
			diluxone_users_login_url()
		)
	);
	exit;
}

/**
 * Opens a pending attempt and returns the nonce that names it.
 *
 * Separate from the redirect so it can be exercised without one: what is
 * stored, how many tries it has and when it expires are the facts the
 * limits below rest on.
 */
function diluxone_users_2fa_pending_start( int $user_id, string $via, bool $remember, string $redirect ): string {
	$nonce = wp_generate_password( 32, false );

	update_user_meta(
		$user_id,
		'diluxone_users_2fa_pending',
		array(
			'nonce'    => wp_hash( $nonce ),
			'expires'  => time() + DILUXONE_USERS_2FA_WINDOW,
			'via'      => $via,
			'remember' => $remember ? 1 : 0,
			'redirect' => $redirect,
			'tries'    => 0,
			'sent'     => 0,
		)
	);

	return $nonce;
}

/**
 * Somebody's pending attempt, if it is still alive and the nonce is theirs.
 *
 * @return array<string, mixed>
 */
function diluxone_users_2fa_pending( int $user_id, string $nonce ): array {
	$pending = diluxone_users_meta_list( $user_id, 'diluxone_users_2fa_pending' );

	if ( array() === $pending || (int) ( $pending['expires'] ?? 0 ) < time() ) {
		return array();
	}

	return hash_equals( (string) ( $pending['nonce'] ?? '' ), wp_hash( $nonce ) ) ? $pending : array();
}

/* ── The count that belongs to the account ─────────────────────────── */

/**
 * Is this account's second step closed for the moment?
 *
 * Asked before a code is even looked at. While it is true nothing is counted
 * either: a lock that grows every time somebody knocks is a way of keeping
 * the owner out for ever, which is the attack this was meant to stop.
 *
 * It is asked twice in `diluxone_users_2fa_handle()` and the second answer is
 * not the first one: the verification in between is what may have closed the
 * door. Hence the tag — without it the analyser carries the first `false`
 * forward and calls the second question dead code.
 *
 * @phpstan-impure
 */
function diluxone_users_2fa_locked( int $user_id ): bool {
	return (int) get_user_meta( $user_id, 'diluxone_users_2fa_lock', true ) > time();
}

/**
 * Counts a wrong code against the account and closes the door when there
 * have been too many.
 *
 * @return int How many failures the account has now.
 */
function diluxone_users_2fa_fail( int $user_id ): int {
	return diluxone_users_2fa_close( $user_id, diluxone_users_2fa_count( $user_id, 1 ) );
}

/**
 * Moves the account's count by one, in the database.
 *
 * The increment is left to MySQL rather than read here and written back:
 * twenty codes submitted at once all read the same number, all write the same
 * number, and twenty guesses cost one. `meta_value + 1` is one statement and
 * cannot be interleaved, which is the only version of this that is a limit.
 *
 * @param int $by 1 to count a try, -1 to give back one that was not used.
 * @return int The count after the move.
 */
function diluxone_users_2fa_count( int $user_id, int $by ): int {
	global $wpdb;

	add_user_meta( $user_id, 'diluxone_users_2fa_fails', 0, true );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an increment only the database can do without a race; the cache is dropped right below.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = GREATEST( 0, CAST( meta_value AS SIGNED ) + %d ) WHERE user_id = %d AND meta_key = %s",
			$by,
			$user_id,
			'diluxone_users_2fa_fails'
		)
	);

	wp_cache_delete( $user_id, 'user_meta' );

	return (int) get_user_meta( $user_id, 'diluxone_users_2fa_fails', true );
}

/**
 * Closes the door if this many failures are too many.
 *
 * It also remembers at which count it closed: once the wait is over, exactly
 * one more try is let through before it closes again for longer, and that
 * count is how the try is recognised.
 *
 * @return int The count it was given.
 */
function diluxone_users_2fa_close( int $user_id, int $fails ): int {
	if ( $fails >= DILUXONE_USERS_2FA_LOCK_AFTER ) {
		// Doubling, capped twice over: the exponent so the arithmetic stays
		// an integer, and the result so an account is never locked for good.
		$over = min( 20, $fails - DILUXONE_USERS_2FA_LOCK_AFTER );
		$wait = (int) min( DILUXONE_USERS_2FA_LOCK_MAX, DILUXONE_USERS_2FA_LOCK_WAIT * ( 2 ** $over ) );

		update_user_meta( $user_id, 'diluxone_users_2fa_lock', time() + $wait );
		update_user_meta( $user_id, 'diluxone_users_2fa_lock_at', $fails );
	}

	return $fails;
}

/**
 * Takes one try from the account before a code is looked at.
 *
 * Asking whether the door is closed and then counting the failure leaves a
 * gap: a burst of requests all find it open, and every one of them gets its
 * guess before the first failure is counted. So the try is taken first, in
 * one statement, and only the ones inside the allowance are looked at — the
 * limit before the first lock, then one per wait. A try that is refused is
 * given back, so a burst cannot push the count past the next allowance.
 *
 * @return int|null The count this try holds, or null when there was none left.
 */
function diluxone_users_2fa_reserve( int $user_id ): ?int {
	$fails   = diluxone_users_2fa_count( $user_id, 1 );
	$lock_at = (int) get_user_meta( $user_id, 'diluxone_users_2fa_lock_at', true );

	if ( $fails <= DILUXONE_USERS_2FA_LOCK_AFTER || ( $lock_at > 0 && $fails === $lock_at + 1 ) ) {
		return $fails;
	}

	diluxone_users_2fa_count( $user_id, -1 );

	return null;
}

/** A code that was right: the account starts from zero again. */
function diluxone_users_2fa_forgive( int $user_id ): void {
	delete_user_meta( $user_id, 'diluxone_users_2fa_fails' );
	delete_user_meta( $user_id, 'diluxone_users_2fa_lock' );
	delete_user_meta( $user_id, 'diluxone_users_2fa_lock_at' );
}

/**
 * Counts a wrong code against the attempt.
 *
 * Returns whether the attempt is still alive. On the last strike it is
 * thrown away whole: the person starts over from the first step. That much is
 * about the screen, not about safety — starting over costs one request, so
 * the limit that means anything is `diluxone_users_2fa_fail()` above, which
 * this attempt cannot reset by dying.
 */
function diluxone_users_2fa_strike( int $user_id, string $nonce ): bool {
	$pending = diluxone_users_2fa_pending( $user_id, $nonce );

	if ( array() === $pending ) {
		return false;
	}

	$pending['tries'] = (int) ( $pending['tries'] ?? 0 ) + 1;

	if ( $pending['tries'] >= DILUXONE_USERS_2FA_TRIES ) {
		delete_user_meta( $user_id, 'diluxone_users_2fa_pending' );

		return false;
	}

	update_user_meta( $user_id, 'diluxone_users_2fa_pending', $pending );

	return true;
}

/**
 * May another code go out for this attempt right now?
 *
 * The attempt remembers when the last one went out; the button only works
 * once that was long enough ago. Without this, "send it again" is a way of
 * flooding somebody else's inbox from the sign-in page.
 */
function diluxone_users_2fa_resend_allowed( int $user_id, string $nonce ): bool {
	$pending = diluxone_users_2fa_pending( $user_id, $nonce );

	if ( array() === $pending ) {
		return false;
	}

	return time() - (int) ( $pending['sent'] ?? 0 ) >= DILUXONE_USERS_2FA_RESEND_WAIT;
}

/** Fires whatever that method needs in order to start (send the e-mail). */
function diluxone_users_2fa_send( int $user_id, string $method ): void {
	$methods = diluxone_users_2fa_available( $user_id );

	if ( ! isset( $methods[ $method ]['send'] ) || ! is_callable( $methods[ $method ]['send'] ) ) {
		return;
	}

	call_user_func( $methods[ $method ]['send'], $user_id );

	// The attempt keeps the time of the last send: that is what the resend
	// limit is measured from.
	$pending = diluxone_users_meta_list( $user_id, 'diluxone_users_2fa_pending' );

	if ( array() !== $pending ) {
		$pending['sent'] = time();
		update_user_meta( $user_id, 'diluxone_users_2fa_pending', $pending );
	}
}

/**
 * Is this code good right now, whichever method it belongs to?
 *
 * For the actions that weaken the account — turning the second step off,
 * removing the app, replacing the backup codes — a session is not enough: a
 * session can be stolen, and whoever stole it must not be able to remove the
 * one thing that was still in their way. What is asked for is proof of the
 * second step itself, by any method the person has ready, backup codes
 * included.
 *
 * It counts against the same account limit the sign-in challenge does, and
 * for the same reason: this is the door that turns the second step off, so a
 * stolen session must not be able to guess its way through it either. Every
 * wrong code here also runs the backup list, which is eight password hashes —
 * without the count that is a way of spending somebody else's CPU as well.
 */
function diluxone_users_2fa_reauth( int $user_id, string $code ): bool {
	if ( '' === trim( $code ) || diluxone_users_2fa_locked( $user_id ) ) {
		return false;
	}

	$fails = diluxone_users_2fa_reserve( $user_id );

	if ( null === $fails ) {
		return false;
	}

	if ( diluxone_users_backup_use( $user_id, $code ) ) {
		diluxone_users_2fa_forgive( $user_id );

		return true;
	}

	foreach ( diluxone_users_2fa_available( $user_id ) as $method ) {
		if ( is_callable( $method['verify'] ) && call_user_func( $method['verify'], $user_id, $code ) ) {
			diluxone_users_2fa_forgive( $user_id );

			return true;
		}
	}

	diluxone_users_2fa_close( $user_id, $fails );

	/** This action is documented in includes/auth.php */
	do_action( 'diluxone_users_2fa_failed', $user_id, 'reauth' );

	return false;
}

/**
 * Validates what the person typed and, if it is right, lets them in.
 *
 * Backup codes are always tried, whichever method was chosen: they are
 * precisely for when the method is not at hand.
 *
 * Every answer passes through the account's own count, and this is the only
 * place that can clear it: a code that is right is the single piece of
 * evidence that the person guessing is the owner.
 */
function diluxone_users_2fa_verify( int $user_id, string $method, string $code ): bool {
	if ( diluxone_users_2fa_locked( $user_id ) ) {
		return false;
	}

	$fails = diluxone_users_2fa_reserve( $user_id );

	if ( null === $fails ) {
		return false;
	}

	if ( diluxone_users_backup_use( $user_id, $code ) ) {
		diluxone_users_2fa_forgive( $user_id );

		return true;
	}

	$methods = diluxone_users_2fa_available( $user_id );

	if ( isset( $methods[ $method ] ) && is_callable( $methods[ $method ]['verify'] )
		&& call_user_func( $methods[ $method ]['verify'], $user_id, $code ) ) {
		diluxone_users_2fa_forgive( $user_id );

		return true;
	}

	diluxone_users_2fa_close( $user_id, $fails );

	/**
	 * Fires when a second-factor code was refused.
	 *
	 * The log listens to this one. It exists because a wrong second step
	 * never reaches `wp_login_failed` — WordPress said yes to the password
	 * and this plugin is what said no afterwards — so without it the one
	 * event worth seeing when an account is under attack is the one the log
	 * never hears about.
	 *
	 * @param int    $user_id Whose second step was being answered.
	 * @param string $method  The way it was being answered, or `reauth`.
	 */
	do_action( 'diluxone_users_2fa_failed', $user_id, $method );

	return false;
}

/**
 * The second-factor screen and its submission.
 *
 * It lives on `init` like the rest of the plugin's doors, so that the sign-in
 * page is a page of the site and not wp-login.php.
 */
function diluxone_users_2fa_handle(): void {
	// phpcs:disable WordPress.Security.NonceVerification -- our own nonce IS the credential.
	if ( ! isset( $_POST['diluxone_users_2fa_user'], $_POST['diluxone_users_2fa_key'] ) ) {
		return;
	}

	$user_id = absint( $_POST['diluxone_users_2fa_user'] );
	$key     = sanitize_text_field( wp_unslash( $_POST['diluxone_users_2fa_key'] ) );
	$method  = sanitize_key( wp_unslash( $_POST['diluxone_users_2fa_method'] ?? '' ) );
	$code    = sanitize_text_field( wp_unslash( $_POST['diluxone_users_2fa_code'] ?? '' ) );
	$trust   = isset( $_POST['diluxone_users_2fa_trust'] );
	$resend  = isset( $_POST['diluxone_users_2fa_resend'] );
	// phpcs:enable

	$pending = diluxone_users_2fa_pending( $user_id, $key );

	if ( array() === $pending ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', diluxone_users_login_url() ) );
		exit;
	}

	$back = add_query_arg(
		array(
			'diluxone_users_2fa'    => $user_id,
			'diluxone_users_key'    => $key,
			'diluxone_users_method' => $method,
		),
		diluxone_users_login_url()
	);

	if ( $resend ) {
		// Asked too soon, nothing goes out and nothing is claimed: the screen
		// comes back as it was, with the code that is already on its way.
		if ( ! diluxone_users_2fa_resend_allowed( $user_id, $key ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		diluxone_users_2fa_send( $user_id, $method );
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'sent', $back ) );
		exit;
	}

	if ( diluxone_users_2fa_locked( $user_id ) ) {
		// Said before anything is looked at, and said plainly: an account
		// that is waiting out its own wait should read that, not "the code is
		// wrong" over and over with no way of telling the difference.
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'locked', $back ) );
		exit;
	}

	if ( '' === $code || ! diluxone_users_2fa_verify( $user_id, $method, $code ) ) {
		$state = diluxone_users_2fa_locked( $user_id ) ? 'locked' : 'code';

		// A wrong code costs a try; the last one costs the attempt, and the
		// person is back at the first step as if the window had closed.
		if ( ! diluxone_users_2fa_strike( $user_id, $key ) ) {
			wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', diluxone_users_login_url() ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'diluxone-users', $state, $back ) );
		exit;
	}

	// The attempt is spent by whoever deletes it: two right codes sent at
	// once open one session, not two.
	if ( ! delete_user_meta( $user_id, 'diluxone_users_2fa_pending' ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', diluxone_users_login_url() ) );
		exit;
	}

	if ( $trust ) {
		diluxone_users_2fa_trust( $user_id );
	}

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, ! empty( $pending['remember'] ) );

	do_action( 'diluxone_users_logged_in', $user_id, (string) $pending['via'] );

	wp_safe_redirect( (string) $pending['redirect'] );
	exit;
}
add_action( 'init', 'diluxone_users_2fa_handle', 5 );

/**
 * The password goes through here too.
 *
 * WordPress opens the session before firing `wp_login`, so what is done is to
 * close it again straight away and send them to the challenge. It is ugly and
 * it is what there is: there is no hook between "the password was right" and
 * "the cookie is set".
 */
function diluxone_users_2fa_after_password( string $login, WP_User $user ): void {
	if ( ! diluxone_users_2fa_required( (int) $user->ID, 'password' ) || diluxone_users_2fa_trusted( (int) $user->ID ) ) {
		return;
	}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verified it while authenticating.
	$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
	$redirect = '' !== $redirect ? $redirect : (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), (int) $user->ID );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$remember = ! empty( $_POST['rememberme'] );

	diluxone_users_2fa_challenge( (int) $user->ID, 'password', $remember, $redirect );
}
add_action( 'wp_login', 'diluxone_users_2fa_after_password', 10, 2 );

/**
 * The sessions this request opened, by person.
 *
 * `wp_signon()` creates the session and sends its cookie before `wp_login`
 * fires, and nothing hands the session over afterwards. This listens as it
 * is created, so the challenge can destroy exactly that one and no other
 * session the person has open elsewhere.
 *
 * @param int         $user_id Whose sessions.
 * @param string|null $token   A session to remember; null to only read.
 * @return array<int, string>
 */
function diluxone_users_2fa_session_tokens( int $user_id, ?string $token = null ): array {
	static $tokens = array();

	if ( null !== $token && '' !== $token ) {
		$tokens[ $user_id ][] = $token;
	}

	return $tokens[ $user_id ] ?? array();
}

/**
 * Hears every session this request opens.
 *
 * @param string $cookie     The cookie value (unused).
 * @param int    $expire     When the cookie expires (unused).
 * @param int    $expiration When the session expires (unused).
 * @param int    $user_id    Whose session.
 * @param string $scheme     The cookie scheme (unused).
 * @param string $token      The session token.
 */
function diluxone_users_2fa_hear_session( $cookie, $expire, $expiration, $user_id, $scheme, $token ): void {
	diluxone_users_2fa_session_tokens( (int) $user_id, (string) $token );
}
add_action( 'set_logged_in_cookie', 'diluxone_users_2fa_hear_session', 10, 6 );

/*
 * The doors with no screen.
 *
 * XML-RPC and application passwords authenticate with the password alone and
 * never fire `wp_login`, so nothing above sees them: an account asked for a
 * second step on every screen was open to a script with the password and no
 * screen. Neither of those ways in can ask for a code, so for whoever is
 * asked for one they are closed.
 */

/**
 * Says no to an application password for anybody the second step applies to.
 *
 * It is the same filter WordPress consults everywhere — the REST API, XML-RPC
 * and the profile screen — so closing it here closes it in all three, and
 * the profile says plainly that they are not available.
 *
 * @param bool    $available What WordPress decided.
 * @param WP_User $user      Whose password it would be.
 */
function diluxone_users_2fa_no_app_passwords( bool $available, WP_User $user ): bool {
	return $available && ! diluxone_users_2fa_required( (int) $user->ID, 'password' );
}
add_filter( 'wp_is_application_passwords_available_for_user', 'diluxone_users_2fa_no_app_passwords', 10, 2 );

/**
 * Refuses a password sign-in that arrived somewhere no code can be asked for.
 *
 * Pure on purpose — whether this is such a request is passed in — so the
 * verdict can be tested without defining the constant that names it.
 *
 * @param WP_User|WP_Error|null $user    What the filters before decided.
 * @param bool                  $machine A request with no screen to ask on.
 * @return WP_User|WP_Error|null
 */
function diluxone_users_2fa_gate( $user, bool $machine ) {
	if ( ! $machine || ! $user instanceof WP_User || ! diluxone_users_2fa_required( (int) $user->ID, 'password' ) ) {
		return $user;
	}

	return new WP_Error(
		'diluxone_users_2fa',
		__( 'This account asks for a second step when signing in, and this way in cannot ask for it.', 'diluxone-users' )
	);
}

/**
 * The gate on XML-RPC, where wp_authenticate() runs with no screen behind it.
 *
 * Late in the chain so the password has been checked first: a wrong password
 * keeps saying "wrong password", and only a right one meets this.
 *
 * @param WP_User|WP_Error|null $user
 * @return WP_User|WP_Error|null
 */
function diluxone_users_2fa_gate_xmlrpc( $user ) {
	return diluxone_users_2fa_gate( $user, defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
}
add_filter( 'authenticate', 'diluxone_users_2fa_gate_xmlrpc', 99 );

/* ── Backup codes ──────────────────────────────────────────────────── */

/**
 * Generates a fresh set of backup codes.
 *
 * They are stored hashed, like a password: if somebody walks off with the
 * database, they walk off with hashes. They are returned in the clear exactly
 * once, which is when the person has to write them down.
 *
 * @return array<int, string>
 */
function diluxone_users_backup_generate( int $user_id, int $many = 8 ): array {
	$plain  = array();
	$hashes = array();

	for ( $i = 0; $i < $many; $i++ ) {
		$code     = strtolower( wp_generate_password( 10, false, false ) );
		$plain[]  = $code;
		$hashes[] = wp_hash_password( $code );
	}

	update_user_meta( $user_id, 'diluxone_users_backup_codes', $hashes );

	return $plain;
}

/**
 * The key the codes travel under, from the salts and not from the database.
 *
 * `wp_salt()` reads `wp-config.php` — a file outside the database — which is
 * the whole point: whoever walks off with a database dump walks off with a
 * box they have no key to. WordPress guarantees the sodium functions exist
 * even where PHP was built without the extension, because core loads its own
 * implementation in `wp-includes/sodium_compat/`.
 */
function diluxone_users_backup_key(): string {
	return sodium_crypto_generichash( wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Keeps the freshly generated codes for as long as one redirect takes.
 *
 * They are hashed for storage and then have to survive the hop between "save"
 * and the screen that shows them. Writing them into the options table as they
 * read would undo the hashing in one line — an options row holding eight
 * working second factors, for whoever can read the database or a backup taken
 * in that window. So they go in sealed, the key is not in there with them,
 * and the window is a minute rather than a quarter of an hour.
 *
 * @param array<int, string> $codes
 */
function diluxone_users_backup_stash( int $user_id, array $codes ): void {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$box   = sodium_crypto_secretbox( (string) wp_json_encode( array_values( $codes ) ), $nonce, diluxone_users_backup_key() );

	set_transient( 'diluxone_users_backup_' . $user_id, base64_encode( $nonce . $box ), MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a binary value going into a text column, not obfuscation.
}

/**
 * Opens it again, once.
 *
 * Anything that does not open — a truncated row, a site whose salts were
 * rotated between the two requests — is an empty list and not an error: the
 * codes exist and are hashed, and the way out of here is to ask for a new set.
 *
 * @return array<int, string>
 */
function diluxone_users_backup_unstash( int $user_id ): array {
	$stored = get_transient( 'diluxone_users_backup_' . $user_id );

	if ( ! is_string( $stored ) || '' === $stored ) {
		return array();
	}

	delete_transient( 'diluxone_users_backup_' . $user_id );

	$raw = (string) base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading back what was written above.

	if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return array();
	}

	$plain = sodium_crypto_secretbox_open(
		substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		diluxone_users_backup_key()
	);

	if ( ! is_string( $plain ) ) {
		return array();
	}

	$codes = json_decode( $plain, true );

	return is_array( $codes ) ? array_values( array_map( 'strval', $codes ) ) : array();
}

/**
 * How many backup codes they have left unused.
 *
 * The type is asked about rather than cast, for the same reason as in
 * `diluxone_users_2fa_email_verify()`: with no meta stored `get_user_meta()`
 * answers `''`, and `(array) ''` is `array( '' )` — an array of one. So an
 * account that had never been given a single code counted as having one, the
 * screen said "1 left", and — worse — the two places that generate the set
 * only do it `if ( 0 === diluxone_users_backup_left() )`, which was never
 * true. Turning the second step on handed nobody any codes at all.
 */
function diluxone_users_backup_left( int $user_id ): int {
	$codes = get_user_meta( $user_id, 'diluxone_users_backup_codes', true );

	return is_array( $codes ) ? count( $codes ) : 0;
}

/**
 * Uses a backup code, if what they typed is one.
 *
 * It is deleted on use: a single-use code that can be used twice is not a
 * single-use code.
 */
function diluxone_users_backup_use( int $user_id, string $code ): bool {
	$code   = strtolower( trim( str_replace( array( ' ', '-' ), '', $code ) ) );
	$hashes = diluxone_users_meta_list( $user_id, 'diluxone_users_backup_codes' );

	$before = $hashes;

	foreach ( $hashes as $i => $hash ) {
		if ( wp_check_password( $code, (string) $hash, $user_id ) ) {
			unset( $hashes[ $i ] );

			// Written only over the list that was read: of two requests
			// spending the same code, the second finds the list changed and
			// its write fails, so it does not get in.
			return (bool) update_user_meta( $user_id, 'diluxone_users_backup_codes', array_values( $hashes ), $before );
		}
	}

	return false;
}
