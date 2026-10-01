<?php
/**
 * Passkeys (WebAuthn).
 *
 * A passkey is a private key that lives on the person's device or in their
 * keychain and never leaves it. The site stores only the public one. There is
 * nothing to steal from the database, nothing to reuse on another site, and
 * it cannot be phished: the browser refuses to sign for a domain that is not
 * the one that registered the key.
 *
 * What is implemented here is the verification, which is the part that
 * matters:
 *
 *   - The challenge is issued by the server, lives briefly and is used once.
 *   - The operation type, the origin and the domain hash are all checked.
 *   - The "user present" flag is required, and the "user verified" one when
 *     the site asks for it.
 *   - The signature is verified against the stored public key, over exactly
 *     the bytes the standard prescribes.
 *
 * Registration leans on `getPublicKey()`, which modern browsers already
 * return in DER format: that way no CBOR interpreter is needed to read the
 * attestation object, the most fragile part of any WebAuthn implementation.
 *
 * Manufacturer attestation is deliberately NOT verified: it is there to
 * require specific key brands in corporate environments, and on an open site
 * it only adds surface for error.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** How long a challenge lives. Short: it is a round trip of seconds. */
const DILUXONE_USERS_PASSKEY_TTL = 5 * MINUTE_IN_SECONDS;

/* ── Base64url, which is how all of this travels ───────────────────── */

/** Encodes as base64url, which is how WebAuthn sends and expects everything. */
function diluxone_users_b64url_encode( string $bytes ): string {
	return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

/** B64url decode. */
function diluxone_users_b64url_decode( string $text ): string {
	$text = strtr( $text, '-_', '+/' );

	return (string) base64_decode( str_pad( $text, strlen( $text ) % 4 ? strlen( $text ) + 4 - strlen( $text ) % 4 : 0, '=' ), true );
}

/* ── Who we are as far as the browser is concerned ─────────────────── */

/**
 * The address passkeys belong to: this site's, or on a network the hub's.
 *
 * On a network people keep their passkeys, like the rest of their account,
 * on the hub, and sign in with them there: one domain for every site, so a
 * key made once opens every site of the network, whichever site sent them to
 * sign in.
 */
function diluxone_users_passkey_home(): string {
	return diluxone_users_scoped_storage_active() ? (string) get_home_url( diluxone_users_hub_site_id() ) : (string) home_url();
}

/**
 * The domain the passkey is registered against.
 *
 * A passkey is tied to this value: if it changes, the existing ones stop
 * working. That is why it comes from the host and not from an option somebody
 * could change without knowing what it does.
 */
function diluxone_users_passkey_rp_id(): string {
	$host = wp_parse_url( diluxone_users_passkey_home(), PHP_URL_HOST );

	/**
	 * Filters the passkey domain.
	 *
	 * The only sensible reason to touch it is to go up a level — from
	 * `account.site.com` to `site.com` — and share them across subdomains.
	 *
	 * @param string $rp_id
	 */
	return (string) apply_filters( 'diluxone_users_passkey_rp_id', is_string( $host ) ? $host : '' );
}

/** The exact origin the browser has to declare. */
function diluxone_users_passkey_origin(): string {
	$parts = wp_parse_url( diluxone_users_passkey_home() );

	return sprintf( '%s://%s%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '', isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
}

/* ── Each person's keys ────────────────────────────────────────────── */

/**
 * Somebody's passkeys.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_passkeys( int $user_id ): array {
	$keys = get_user_meta( $user_id, 'diluxone_users_passkeys', true );

	return is_array( $keys ) ? array_values( $keys ) : array();
}

/**
 * The keys of this person that work here.
 *
 * On a network of subdomains each site is its own passkey domain, while the
 * list is the person's everywhere: a key made on one site cannot be used on
 * another, so it is neither listed nor counted there. A key saved without its
 * domain is shown everywhere, as it always was.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_passkeys_here( int $user_id ): array {
	$here = diluxone_users_passkey_rp_id();

	return array_values(
		array_filter(
			diluxone_users_passkeys( $user_id ),
			static fn( array $k ): bool => ! isset( $k['rp'] ) || '' === (string) $k['rp'] || (string) $k['rp'] === $here
		)
	);
}

/**
 * Stores one person's list of passkeys.
 *
 * @param array<int, array<string, mixed>> $keys
 */
function diluxone_users_passkeys_save( int $user_id, array $keys ): void {
	$keys = array_values( $keys );

	// The index is kept in step with the list: one row per key, written when
	// the key arrives and removed when it goes, so that the lookup by
	// credential id below never has to open anybody's list to find its owner.
	$before = array_map( 'strval', array_column( diluxone_users_passkeys( $user_id ), 'id' ) );
	$after  = array_map( 'strval', array_column( $keys, 'id' ) );

	foreach ( array_diff( $before, $after ) as $gone ) {
		delete_user_meta( $user_id, diluxone_users_passkey_index_key( $gone ) );
	}

	foreach ( array_diff( $after, $before ) as $new ) {
		update_user_meta( $user_id, diluxone_users_passkey_index_key( $new ), 1 );
	}

	if ( array() === $keys ) {
		// Somebody who took their last key off has no key, and an empty array
		// left behind is a row that answers "yes" to every query asking who
		// has one.
		delete_user_meta( $user_id, 'diluxone_users_passkeys' );

		return;
	}

	update_user_meta( $user_id, 'diluxone_users_passkeys', $keys );
}

/**
 * The meta key that indexes one credential id.
 *
 * A meta key, not a meta value, because that is what the users table is
 * indexed by: finding the owner becomes one indexed query, whatever the
 * number of accounts. The id is hashed so the key stays a fixed length.
 */
function diluxone_users_passkey_index_key( string $id ): string {
	return 'diluxone_users_pk_' . hash( 'sha256', $id );
}

/** Is this credential id in this person's list? */
function diluxone_users_passkey_belongs( int $user_id, string $id ): bool {
	foreach ( diluxone_users_passkeys( $user_id ) as $key ) {
		if ( hash_equals( (string) $key['id'], $id ) ) {
			return true;
		}
	}

	return false;
}

/** Removes one by its identifier. */
function diluxone_users_passkey_forget( int $user_id, string $id ): void {
	diluxone_users_passkeys_save(
		$user_id,
		array_filter( diluxone_users_passkeys( $user_id ), static fn( array $k ): bool => $k['id'] !== $id )
	);
}

/**
 * Who a passkey belongs to.
 *
 * The lookup is by meta because at sign-in time there is no session yet: the
 * passkey says who they are before anybody has given their e-mail.
 */
function diluxone_users_passkey_owner( string $id ): int {
	if ( '' === $id ) {
		return 0;
	}

	$indexed = get_users(
		array(
			'meta_key' => diluxone_users_passkey_index_key( $id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'   => 'ID',
			'number'   => 2,
			// The network's accounts, not only this site's members: a passkey
			// belongs to a person, wherever they signed up.
			'blog_id'  => 0,
		)
	);

	// The index says who; the list confirms it, so a stray index row on its
	// own opens nothing.
	foreach ( $indexed as $user_id ) {
		if ( diluxone_users_passkey_belongs( (int) $user_id, $id ) ) {
			return (int) $user_id;
		}
	}

	/*
	 * And that is the whole lookup. There used to be a second pass here for
	 * keys stored before the index existed, which opened up to five hundred
	 * accounts' lists one by one. There are no such keys — the index has been
	 * written since the first key this plugin ever registered — so all that
	 * pass could do was five hundred queries, on an unauthenticated request,
	 * for a credential id somebody made up.
	 */
	return 0;
}

/* ── Challenges ────────────────────────────────────────────────────── */

/** Issues a challenge and stores it so it can be compared later. */
function diluxone_users_passkey_challenge_new( string $scope ): string {
	$challenge = diluxone_users_b64url_encode( random_bytes( 32 ) );

	set_transient( 'diluxone_users_pk_' . $scope . '_' . md5( $challenge ), 1, DILUXONE_USERS_PASSKEY_TTL );

	return $challenge;
}

/** Consumes it: if it existed, deletes it and returns true. Single use. */
function diluxone_users_passkey_challenge_use( string $scope, string $challenge ): bool {
	// The delete is the check: of two requests answering the same
	// challenge, only the one that removed it goes on.
	return delete_transient( 'diluxone_users_pk_' . $scope . '_' . md5( $challenge ) );
}

/* ── Verification ──────────────────────────────────────────────────── */

/**
 * Checks the `clientDataJSON` the browser returns.
 *
 * @return array<string, mixed>|null
 */
function diluxone_users_passkey_client_data( string $json, string $type, string $scope ): ?array {
	$data = json_decode( $json, true );

	if ( ! is_array( $data ) ) {
		return null;
	}

	if ( ( $data['type'] ?? '' ) !== $type ) {
		return null;
	}

	// The origin has to be exactly ours: it is what makes a passkey unusable
	// from a cloned site.
	if ( ( $data['origin'] ?? '' ) !== diluxone_users_passkey_origin() ) {
		return null;
	}

	if ( ! diluxone_users_passkey_challenge_use( $scope, (string) ( $data['challenge'] ?? '' ) ) ) {
		return null;
	}

	return $data;
}

/**
 * Checks the `authenticatorData`.
 *
 * It is 37 fixed bytes and then the optional part: the domain hash, a flags
 * byte and a counter.
 *
 * @return array{flags: int, counter: int}|null
 */
function diluxone_users_passkey_auth_data( string $bytes ): ?array {
	if ( strlen( $bytes ) < 37 ) {
		return null;
	}

	if ( ! hash_equals( substr( $bytes, 0, 32 ), hash( 'sha256', diluxone_users_passkey_rp_id(), true ) ) ) {
		return null;
	}

	$flags = ord( $bytes[32] );

	// Bit 0: somebody was present. Without it, any process could sign.
	if ( 0 === ( $flags & 0x01 ) ) {
		return null;
	}

	// Bit 2: who they are was verified too (fingerprint, face, PIN).
	if ( diluxone_users_option( 'diluxone_users_passkey_verify' ) && 0 === ( $flags & 0x04 ) ) {
		return null;
	}

	return array(
		'flags'   => $flags,
		'counter' => (int) ( ( (array) unpack( 'N', substr( $bytes, 33, 4 ) ) )[1] ?? 0 ),
	);
}

/** Builds a usable public key out of the DER the browser sent. */
function diluxone_users_passkey_pem( string $der ): string {
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

/**
 * Is the signature from that key and over that data?
 *
 * What is signed is `authenticatorData` concatenated with the SHA-256 of
 * `clientDataJSON`. It is not a choice: it is in the standard and anything
 * else fails to validate.
 */
function diluxone_users_passkey_signature_ok( string $der, int $alg, string $auth_data, string $client_json, string $signature ): bool {
	$key = openssl_pkey_get_public( diluxone_users_passkey_pem( $der ) );

	if ( false === $key ) {
		return false;
	}

	// -7 is ECDSA with P-256 and SHA-256; -257 is RSA with SHA-256. Those are
	// the two real passkeys use.
	$digest = -257 === $alg ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA256;

	if ( ! in_array( $alg, array( -7, -257 ), true ) ) {
		return false;
	}

	return 1 === openssl_verify( $auth_data . hash( 'sha256', $client_json, true ), $signature, $key, $digest );
}

/* ── The round trip with the browser ───────────────────────────────── */

/** Does the site offer passkeys? */
function diluxone_users_passkeys_enabled(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_passkey_enabled' );
}

/**
 * The data needed to start a registration.
 *
 * @return array<string, mixed>
 */
function diluxone_users_passkeys_register_options(): array {
	$user = wp_get_current_user();

	return array(
		'challenge'               => diluxone_users_passkey_challenge_new( 'reg' ),
		'rp'                      => array(
			'id'   => diluxone_users_passkey_rp_id(),
			'name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		),
		'user'                    => array(
			// The user id travels as opaque bytes: nothing identifying the
			// person outside the site is sent to the authenticator. The seed
			// says 'upfw' and stays that way: it is what the authenticator
			// stored alongside each passkey. Changing it changes the identity
			// of whoever already has one, and their key stops recognising itself.
		'id'              => diluxone_users_b64url_encode( hash( 'sha256', 'upfw|' . $user->ID . '|' . wp_salt(), true ) ),
			'name'        => $user->user_email,
			'displayName' => diluxone_users_display_name( $user ),
		),
		'excludeCredentials'      => array_map(
			static fn( array $k ): array => array(
				'id'   => $k['id'],
				'type' => 'public-key',
			),
			diluxone_users_passkeys_here( $user->ID )
		),
		'authenticatorAttachment' => 'device' === (string) diluxone_users_option( 'diluxone_users_passkey_where' ) ? 'platform' : null,
		'userVerification'        => diluxone_users_option( 'diluxone_users_passkey_verify' ) ? 'required' : 'preferred',
		'residentKey'             => 'preferred',
	);
}

/**
 * The data needed to start a sign-in.
 *
 * @return array<string, mixed>
 */
function diluxone_users_passkeys_login_options(): array {
	return array(
		'challenge'        => diluxone_users_passkey_challenge_new( 'log' ),
		'rpId'             => diluxone_users_passkey_rp_id(),
		'userVerification' => diluxone_users_option( 'diluxone_users_passkey_verify' ) ? 'required' : 'preferred',
	);
}

/**
 * The six values a browser sends for a passkey, by name.
 *
 * Everything here is base64url or JSON that the two handlers then decode and
 * verify — nothing is stored as it arrives and nothing is printed — but the
 * whole `$_POST` used to travel in, and a call site that hands over the
 * request tells the next reader nothing about what is actually read.
 *
 * @return array<string, string>
 */
function diluxone_users_passkeys_posted(): array {
	$sent = array();

	foreach ( array( 'id', 'publicKey', 'algorithm', 'clientDataJSON', 'authenticatorData', 'signature', 'label' ) as $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checks the nonce for the two steps that can have one; the other two are verified by signature.
		if ( ! isset( $_POST[ $name ] ) ) {
			continue;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- base64url and JSON, decoded and verified by the handler; sanitising here would corrupt the bytes a signature is checked over.
		$value = wp_unslash( $_POST[ $name ] );

		$sent[ $name ] = is_scalar( $value ) ? (string) $value : '';
	}

	return $sent;
}

/** The whole dialogue with the browser goes through here. */
function diluxone_users_passkeys_ajax(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is verified per step.
	$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );

	// On a network, passkeys are made and used on the hub, whose domain they
	// belong to. Nothing is drawn elsewhere that would ask; a call made by
	// hand is told where to go.
	if ( diluxone_users_off_hub() ) {
		wp_send_json_error(
			array(
				/* translators: %s: the name of the site where the network's accounts live */
				'message'  => sprintf( __( 'Passkeys are used on %s, where your account is.', 'diluxone-users' ), diluxone_users_hub_name() ),
				'redirect' => diluxone_users_login_url(),
			),
			403
		);
	}

	if ( ! diluxone_users_passkeys_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'This site does not use passkeys.', 'diluxone-users' ) ), 400 );
	}

	// The two registration operations require a session and a nonce; the
	// sign-in ones cannot require a session, because opening one is the point.
	if ( in_array( $step, array( 'register-options', 'register' ), true ) ) {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'diluxone_users_passkeys', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Reload the page.', 'diluxone-users' ) ), 403 );
		}
	}

	/*
	 * The two sign-in steps cannot ask for a session, so they ask the machine
	 * instead. `login-options` writes a challenge — a row in the options table
	 * — for anybody who asks, and a loop asking for nothing else fills that
	 * table with rows nothing will read until WordPress next sweeps expired
	 * transients. The ceiling is high enough that a person with several keys
	 * on several devices never meets it.
	 */
	if ( in_array( $step, array( 'login-options', 'login' ), true )
		&& ! diluxone_users_ip_burst( 'passkey', 60, 10 * MINUTE_IN_SECONDS ) ) {
		wp_send_json_error( array( 'message' => __( 'Too many attempts. Wait a moment and try again.', 'diluxone-users' ) ), 429 );
	}

	switch ( $step ) {
		case 'register-options':
			wp_send_json_success( diluxone_users_passkeys_register_options() );
				// wp_send_json_* answers and stops: there is no fall-through to the next case.

		case 'register':
			wp_send_json( diluxone_users_passkeys_register( diluxone_users_passkeys_posted() ) );
				// wp_send_json_* answers and stops: there is no fall-through to the next case.

		case 'login-options':
			wp_send_json_success( diluxone_users_passkeys_login_options() );
				// wp_send_json_* answers and stops: there is no fall-through to the next case.

		case 'login':
			wp_send_json( diluxone_users_passkeys_login( diluxone_users_passkeys_posted() ) );
	}
	// phpcs:enable

	wp_send_json_error( array( 'message' => __( 'Unknown step.', 'diluxone-users' ) ), 400 );
}
add_action( 'wp_ajax_diluxone_users_passkeys', 'diluxone_users_passkeys_ajax' );
add_action( 'wp_ajax_nopriv_diluxone_users_passkeys', 'diluxone_users_passkeys_ajax' );

/**
 * Registers a new passkey.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function diluxone_users_passkeys_register( array $post ): array {
	$user_id = get_current_user_id();
	$id      = sanitize_text_field( (string) ( $post['id'] ?? '' ) );
	$der     = diluxone_users_b64url_decode( (string) ( $post['publicKey'] ?? '' ) );
	$alg     = (int) ( $post['algorithm'] ?? 0 );
	$json    = (string) ( $post['clientDataJSON'] ?? '' );

	if ( '' === $id || '' === $der || null === diluxone_users_passkey_client_data( $json, 'webauthn.create', 'reg' ) ) {
		return array(
			'success' => false,
			'data'    => array( 'message' => __( 'That did not check out. Try again.', 'diluxone-users' ) ),
		);
	}

	if ( ! in_array( $alg, array( -7, -257 ), true ) || false === openssl_pkey_get_public( diluxone_users_passkey_pem( $der ) ) ) {
		return array(
			'success' => false,
			'data'    => array( 'message' => __( 'That key is of a kind this site cannot verify.', 'diluxone-users' ) ),
		);
	}

	$keys = diluxone_users_passkeys( $user_id );

	foreach ( $keys as $key ) {
		if ( hash_equals( (string) $key['id'], $id ) ) {
			return array(
				'success' => true,
				'data'    => array( 'message' => __( 'That one was already here.', 'diluxone-users' ) ),
			);
		}
	}

	// One credential id, one account. A key that two accounts claim can only
	// open the one the lookup finds first, and the other person is locked out
	// of a key that is theirs: the second claim is refused instead.
	$owner = diluxone_users_passkey_owner( $id );

	if ( $owner > 0 && $owner !== $user_id ) {
		return array(
			'success' => false,
			'data'    => array( 'message' => __( 'That passkey is already registered to another account.', 'diluxone-users' ) ),
		);
	}

	$keys[] = array(
		'id'      => $id,
		'key'     => base64_encode( $der ),
		'alg'     => $alg,
		'label'   => diluxone_users_passkey_clean_label( (string) ( $post['label'] ?? '' ) ),
		'created' => time(),
		'used'    => 0,
		'counter' => 0,
		// The domain the key was made for. The list is the person's, on every
		// site of a network, and a key only works on its own domain.
		'rp'      => diluxone_users_passkey_rp_id(),
	);

	diluxone_users_passkeys_save( $user_id, $keys );

	diluxone_users_notify_security(
		$user_id,
		sprintf(
				/* translators: %s: the name given to the passkey */
			__( 'A passkey was added: %s.', 'diluxone-users' ),
			end( $keys )['label']
		)
	);

	return array(
		'success' => true,
		'data'    => array( 'message' => __( 'Passkey saved.', 'diluxone-users' ) ),
	);
}

/**
 * The name stored for a key.
 *
 * The person chooses it: the keys are theirs and they will have several — the
 * phone, the laptop, the physical key — and "Passkey, Passkey, Passkey" tells
 * nobody which one to remove when they lose one. If they write nothing, the
 * device is proposed.
 */
function diluxone_users_passkey_clean_label( string $label ): string {
	$label = trim( sanitize_text_field( $label ) );

	if ( '' === $label ) {
		return diluxone_users_passkey_label();
	}

	return mb_substr( $label, 0, 60 );
}

/** Renames a key. */
function diluxone_users_passkey_rename( int $user_id, string $id, string $label ): void {
	$keys = diluxone_users_passkeys( $user_id );

	foreach ( $keys as $i => $key ) {
		if ( hash_equals( (string) $key['id'], $id ) ) {
			$keys[ $i ]['label'] = diluxone_users_passkey_clean_label( $label );
			diluxone_users_passkeys_save( $user_id, $keys );

			return;
		}
	}
}

/** A reasonable name for the key, taken from the browser. */
function diluxone_users_passkey_label(): string {
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	foreach ( array(
		'iPhone'    => 'iPhone',
		'iPad'      => 'iPad',
		'Android'   => 'Android',
		'Macintosh' => 'Mac',
		'Windows'   => 'Windows',
		'Linux'     => 'Linux',
	) as $needle => $name ) {
		if ( false !== stripos( $agent, $needle ) ) {
			return $name;
		}
	}

	return __( 'Passkey', 'diluxone-users' );
}

/**
 * Signs in with a passkey.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function diluxone_users_passkeys_login( array $post ): array {
	$id        = sanitize_text_field( (string) ( $post['id'] ?? '' ) );
	$json      = (string) ( $post['clientDataJSON'] ?? '' );
	$auth_data = diluxone_users_b64url_decode( (string) ( $post['authenticatorData'] ?? '' ) );
	$signature = diluxone_users_b64url_decode( (string) ( $post['signature'] ?? '' ) );

	$failure = array(
		'success' => false,
		'data'    => array( 'message' => __( 'That passkey did not check out.', 'diluxone-users' ) ),
	);

	if ( '' === $id || null === diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' ) ) {
		return $failure;
	}

	$auth = diluxone_users_passkey_auth_data( $auth_data );

	if ( null === $auth ) {
		return $failure;
	}

	$user_id = diluxone_users_passkey_owner( $id );

	if ( $user_id <= 0 ) {
		return $failure;
	}

	$keys  = diluxone_users_passkeys( $user_id );
	$found = null;

	foreach ( $keys as $i => $key ) {
		if ( hash_equals( (string) $key['id'], $id ) ) {
			$found = $i;
			break;
		}
	}

	if ( null === $found ) {
		return $failure;
	}

	$der = (string) base64_decode( (string) $keys[ $found ]['key'], true );

	if ( ! diluxone_users_passkey_signature_ok( $der, (int) $keys[ $found ]['alg'], $auth_data, $json, $signature ) ) {
		return $failure;
	}

	// The counter can only go up. If it goes down, the key was cloned; that is
	// logged and the sign-in goes on, because many synced passkeys always
	// return zero and refusing there would lock out half the internet.
	if ( $auth['counter'] > 0 && $auth['counter'] <= (int) $keys[ $found ]['counter'] ) {
		do_action( 'diluxone_users_passkey_counter_warning', $user_id, $id );
	}

	$keys[ $found ]['counter'] = $auth['counter'];
	$keys[ $found ]['used']    = time();

	diluxone_users_passkeys_save( $user_id, $keys );

	// A passkey is already two factors in one step: something you have plus
	// something you are or know. Asking for a code on top would be asking three.
	// The browser assigns this to `location`, so it goes through the same
	// check `wp_safe_redirect()` would apply if a header were being sent: a
	// filter is allowed to choose the page, not the scheme.
	$redirect = wp_validate_redirect(
		(string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user_id ),
		home_url( '/' )
	);

	// The same membership rule every other door applies once it is proved who
	// somebody is (see diluxone_users_complete_login()).
	diluxone_users_join_site( $user_id );

	diluxone_users_open_session( $user_id, 'passkey', true );

	return array(
		'success' => true,
		'data'    => array( 'redirect' => $redirect ),
	);
}

/* ── What people see ───────────────────────────────────────────────── */

/**
 * The script, only where it is needed.
 *
 * It is enqueued from the shortcode that needs it and not across the whole
 * site: the same rule as the stylesheet.
 */
function diluxone_users_passkeys_enqueue(): void {
	if ( ! diluxone_users_passkeys_enabled() || wp_script_is( 'diluxone-users-passkeys', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_script( 'diluxone-users-passkeys', DILUXONE_USERS_URL . 'assets/diluxone-users-passkeys.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-passkeys.js' ), true );

	wp_localize_script(
		'diluxone-users-passkeys',
		'diluxOneUsersPasskeys',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'diluxone_users_passkeys' ),
			'texts' => array(
				'error' => __( 'That did not work. Try again.', 'diluxone-users' ),
				'old'   => __( 'This browser is too old for passkeys.', 'diluxone-users' ),
			),
		)
	);
}

/**
 * Renaming or removing a passkey from the profile.
 *
 * Both live in the same form — the name and the buttons are on the same row —
 * so they are one action with two buttons.
 */
function diluxone_users_passkeys_manage(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( diluxone_users_login_url() );
		exit;
	}

	check_admin_referer( 'diluxone_users_passkey' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
	$user_id   = get_current_user_id();
	$id        = sanitize_text_field( wp_unslash( $_POST['diluxone_users_passkey'] ?? '' ) );
	$operation = sanitize_key( wp_unslash( $_POST['diluxone_users_passkey_do'] ?? '' ) );

	if ( 'delete' === $operation ) {
		diluxone_users_passkey_forget( $user_id, $id );
		diluxone_users_notify_security( $user_id, __( 'A passkey was removed.', 'diluxone-users' ) );
		$notice = 'passkeyoff';
	} else {
		diluxone_users_passkey_rename( $user_id, $id, sanitize_text_field( wp_unslash( $_POST['diluxone_users_passkey_label'] ?? '' ) ) );
		$notice = 'passkeyname';
	}
	// phpcs:enable

	wp_safe_redirect( add_query_arg( 'diluxone-users', $notice, diluxone_users_account_url( 'security' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_users_passkey', 'diluxone_users_passkeys_manage' );
