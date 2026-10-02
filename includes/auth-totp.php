<?php
/**
 * The authenticator-app second factor (TOTP).
 *
 * It is RFC 6238 and it is thirty lines of arithmetic: a shared secret, the
 * time divided into thirty-second windows, an HMAC-SHA1 and six digits. It is
 * implemented here and not with a library because an external dependency in a
 * WordPress plugin is a far bigger maintenance problem than this file, and
 * because what has to be done is written in a public document that has not
 * changed since 2011.
 *
 * What is NOT done here is inventing cryptography: PHP does the HMAC.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The base32 alphabet, which is how these secrets are written. */
const DILUXONE_USERS_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** How many seconds each code lasts. It is 30 in every app. */
const DILUXONE_USERS_TOTP_STEP = 30;

/** How many digits. Also 6 in every one. */
const DILUXONE_USERS_TOTP_DIGITS = 6;

/**
 * How many windows backwards and forwards are accepted.
 *
 * One. Without it, a clock two seconds out rejects correct codes; with more,
 * the window of a stolen code is stretched for no reason.
 */
const DILUXONE_USERS_TOTP_DRIFT = 1;

/** A fresh secret, in base32 and of the length the RFC recommends. */
function diluxone_users_totp_secret_new( int $length = 32 ): string {
	$secret = '';
	$bytes  = random_bytes( max( 1, $length ) );

	for ( $i = 0; $i < $length; $i++ ) {
		$secret .= DILUXONE_USERS_BASE32[ ord( $bytes[ $i ] ) & 31 ];
	}

	return $secret;
}

/** From base32 to the raw bytes the HMAC eats. */
function diluxone_users_base32_decode( string $secret ): string {
	$secret = strtoupper( preg_replace( '/[^A-Z2-7]/i', '', $secret ) ?? '' );

	if ( '' === $secret ) {
		return '';
	}

	$bits = '';

	foreach ( str_split( $secret ) as $char ) {
		$bits .= str_pad( decbin( (int) strpos( DILUXONE_USERS_BASE32, $char ) ), 5, '0', STR_PAD_LEFT );
	}

	$bytes = '';

	foreach ( str_split( $bits, 8 ) as $chunk ) {
		if ( 8 === strlen( $chunk ) ) {
			$bytes .= chr( (int) bindec( $chunk ) );
		}
	}

	return $bytes;
}

/**
 * The code matching a secret at a given moment.
 *
 * @param int $timestamp Moment; 0 = now.
 */
function diluxone_users_totp_code( string $secret, int $timestamp = 0 ): string {
	$key = diluxone_users_base32_decode( $secret );

	if ( '' === $key ) {
		return '';
	}

	$counter = intdiv( 0 === $timestamp ? time() : $timestamp, DILUXONE_USERS_TOTP_STEP );

	// The counter goes as eight bytes, big-endian. pack('J') exists since PHP 5.6.
	$hash = hash_hmac( 'sha1', pack( 'J', $counter ), $key, true );

	// "Dynamic truncation": the last nibble says where to read the four bytes
	// that matter from. It is straight out of RFC 4226, §5.4.
	$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
	$number = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
		| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
		| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
		| ( ord( $hash[ $offset + 3 ] ) & 0xFF );

	return str_pad( (string) ( $number % ( 10 ** DILUXONE_USERS_TOTP_DIGITS ) ), DILUXONE_USERS_TOTP_DIGITS, '0', STR_PAD_LEFT );
}

/** Does this code match this secret, now or a moment ago? */
function diluxone_users_totp_check( string $secret, string $code, ?int $now = null ): bool {
	return null !== diluxone_users_totp_step( $secret, $code, $now );
}

/**
 * The time step this code belongs to, or null when it matches none.
 *
 * The step, not the code, is what makes a code spent: the same six digits can
 * come back in a later step, and a code from the step before the last one
 * used is still inside the drift window for another thirty seconds.
 */
function diluxone_users_totp_step( string $secret, string $code, ?int $now = null ): ?int {
	$code = preg_replace( '/\D/', '', $code ) ?? '';

	if ( strlen( $code ) !== DILUXONE_USERS_TOTP_DIGITS ) {
		return null;
	}

	// The moment is a parameter so a test can name it: read twice, time()
	// can fall on both sides of a thirty-second boundary.
	$now = $now ?? time();

	for ( $i = -DILUXONE_USERS_TOTP_DRIFT; $i <= DILUXONE_USERS_TOTP_DRIFT; $i++ ) {
		$timestamp = $now + $i * DILUXONE_USERS_TOTP_STEP;

		if ( hash_equals( diluxone_users_totp_code( $secret, $timestamp ), $code ) ) {
			return intdiv( $timestamp, DILUXONE_USERS_TOTP_STEP );
		}
	}

	return null;
}

/* ── What the plugin sees ──────────────────────────────────────────── */

/** Somebody's confirmed secret. Empty when they have not activated it yet. */
function diluxone_users_totp_secret( int $user_id ): string {
	return (string) get_user_meta( $user_id, 'diluxone_users_totp', true );
}

/** Do they have the app set up and confirmed? */
function diluxone_users_totp_ready( int $user_id ): bool {
	return '' !== diluxone_users_totp_secret( $user_id );
}

/**
 * The secret they are trying out right now, generating it if needed.
 *
 * It is stored apart from the definitive one: until they type a correct code
 * nothing is activated, so nobody is locked out for having opened the screen
 * and walked away.
 */
function diluxone_users_totp_pending( int $user_id ): string {
	$secret = (string) get_user_meta( $user_id, 'diluxone_users_totp_pending', true );

	if ( '' === $secret ) {
		$secret = diluxone_users_totp_secret_new();
		update_user_meta( $user_id, 'diluxone_users_totp_pending', $secret );
	}

	return $secret;
}

/** Verifies against the active secret. It is the registry callback. */
function diluxone_users_totp_verify( int $user_id, string $code ): bool {
	$secret = diluxone_users_totp_secret( $user_id );

	if ( '' === $secret ) {
		return false;
	}

	$step = diluxone_users_totp_step( $secret, $code );

	if ( null === $step ) {
		return false;
	}

	// A code is used once, and so is every code before it: whoever glimpses
	// one over a shoulder cannot type it, nor the one from the step before,
	// once the person has. The write is conditional on the value it read, so
	// two requests carrying the same code cannot both pass.
	$last = get_user_meta( $user_id, 'diluxone_users_totp_step', true );

	if ( '' === $last || false === $last ) {
		return (bool) add_user_meta( $user_id, 'diluxone_users_totp_step', $step, true );
	}

	if ( $step <= (int) $last ) {
		return false;
	}

	return (bool) update_user_meta( $user_id, 'diluxone_users_totp_step', $step, $last );
}

/**
 * The URI every authenticator app understands.
 *
 * The issuer goes in twice — in the label and as a parameter — because old
 * apps read one and new ones the other, and that way the entry is named
 * properly in both.
 */
function diluxone_users_totp_uri( int $user_id, string $secret ): string {
	$user   = get_userdata( $user_id );
	$issuer = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$label  = $issuer . ':' . ( $user instanceof WP_User ? $user->user_email : (string) $user_id );

	return 'otpauth://totp/' . rawurlencode( $label ) . '?' . http_build_query(
		array(
			'secret' => $secret,
			'issuer' => $issuer,
			'digits' => DILUXONE_USERS_TOTP_DIGITS,
			'period' => DILUXONE_USERS_TOTP_STEP,
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);
}

/** The secret in groups of four, so it can be typed without mistakes. */
function diluxone_users_totp_readable( string $secret ): string {
	return trim( chunk_split( $secret, 4, ' ' ) );
}

/** Activates the app if the code they typed is right. */
function diluxone_users_totp_activate( int $user_id, string $code ): bool {
	$secret = (string) get_user_meta( $user_id, 'diluxone_users_totp_pending', true );
	$step   = '' === $secret ? null : diluxone_users_totp_step( $secret, $code );

	if ( null === $step ) {
		return false;
	}

	update_user_meta( $user_id, 'diluxone_users_totp', $secret );
	delete_user_meta( $user_id, 'diluxone_users_totp_pending' );

	// The code that turned the app on is spent like any other: typed again
	// as the first sign-in code, by whoever watched it typed, it is refused.
	update_user_meta( $user_id, 'diluxone_users_totp_step', $step );

	return true;
}

/** Removes it. */
function diluxone_users_totp_forget( int $user_id ): void {
	delete_user_meta( $user_id, 'diluxone_users_totp' );
	delete_user_meta( $user_id, 'diluxone_users_totp_pending' );
	delete_user_meta( $user_id, 'diluxone_users_totp_step' );
}
