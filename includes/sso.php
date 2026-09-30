<?php
/**
 * Signing in with a social account (SSO over OAuth 2).
 *
 * Deliberately modelled on Nextend Social Login: one provider per network,
 * with its client ID and its secret, a callback URL you copy and paste into
 * the provider's console, and the account linked by e-mail.
 *
 * What changes from Nextend: every provider shares a single flow. The engine
 * is here; the provider table lives in sso-providers.php.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * A provider's stored credentials.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_credentials( string $id ): array {
	$all = (array) diluxone_users_raw_get( 'diluxone_users_sso', array() );

	return array(
		'active' => ! empty( $all[ $id ]['active'] ),
		'id'     => (string) ( $all[ $id ]['id'] ?? '' ),
		'secret' => (string) ( $all[ $id ]['secret'] ?? '' ),
	);
}

/**
 * Saves one provider's credentials without touching anyone else's.
 *
 * @param array<string, mixed> $values
 */
function diluxone_users_sso_save_credentials( string $id, array $values ): void {
	$all      = (array) diluxone_users_raw_get( 'diluxone_users_sso', array() );
	$previous = diluxone_users_sso_credentials( $id );

	$new = array(
		'active' => empty( $values['active'] ) ? 0 : 1,
		'id'     => sanitize_text_field( (string) ( $values['id'] ?? '' ) ),
		'secret' => sanitize_text_field( (string) ( $values['secret'] ?? '' ) ),
	);

	// If the credentials changed, what was tested was something else.
	$changed = $new['id'] !== $previous['id'] || $new['secret'] !== $previous['secret'];

	$new['tested'] = $changed ? 0 : ( diluxone_users_sso_tested( $id ) ? 1 : 0 );

	// And nobody turns a provider on without testing it.
	if ( ! $new['tested'] ) {
		$new['active'] = 0;
	}

	$all[ $id ] = $new;

	// Not autoloaded: the secrets are read on the few requests that talk to a
	// provider, and have no business in memory on every other page.
	diluxone_users_update_option( 'diluxone_users_sso', $all, false );
}

/**
 * What state a provider is in.
 *
 * The four states are Nextend's and the order matters: one that has not been
 * tested cannot be turned on. Testing it means doing the real round trip
 * against the provider — the only way to know the ID, the secret and the
 * callback URL are right, and that nobody finds out they are not by failing
 * to get in.
 *
 * @return string not-configured | not-tested | disabled | enabled
 */
function diluxone_users_sso_state( string $id ): string {
	if ( ! diluxone_users_sso_configured( $id ) ) {
		return 'not-configured';
	}

	if ( ! diluxone_users_sso_tested( $id ) ) {
		return 'not-tested';
	}

	return diluxone_users_sso_credentials( $id )['active'] ? 'enabled' : 'disabled';
}

/** Was it tested, and did it work? */
function diluxone_users_sso_tested( string $id ): bool {
	$all = (array) diluxone_users_raw_get( 'diluxone_users_sso', array() );

	return ! empty( $all[ $id ]['tested'] );
}

/** Marks a provider as tested, or takes the mark away. */
function diluxone_users_sso_set_tested( string $id, bool $tested ): void {
	$all = (array) diluxone_users_raw_get( 'diluxone_users_sso', array() );

	$all[ $id ]           = (array) ( $all[ $id ] ?? array() );
	$all[ $id ]['tested'] = $tested ? 1 : 0;

	diluxone_users_update_option( 'diluxone_users_sso', $all );
}

/** Does it have credentials filled in? */
function diluxone_users_sso_configured( string $id ): bool {
	$c = diluxone_users_sso_credentials( $id );

	return '' !== $c['id'] && '' !== $c['secret'];
}

/** Is it ready for people to use? Configured, tested and turned on. */
function diluxone_users_sso_ready( string $id ): bool {
	return 'enabled' === diluxone_users_sso_state( $id );
}

/**
 * The providers that can be shown today.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_available(): array {
	return array_filter(
		diluxone_users_sso_providers(),
		static fn( array $p, string $id ): bool => diluxone_users_sso_ready( $id ),
		ARRAY_FILTER_USE_BOTH
	);
}

/**
 * The providers the sign-in form offers.
 *
 * Which is not the same list as the one that works: a site can have Google
 * set up and verified and still not want its button on the form — because it
 * is winding the provider down, or because the buttons belong somewhere else
 * on that page and the site places them itself. Whoever already linked an
 * account keeps being able to use it from the account area either way; this
 * is the form, not the plumbing.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_sso_for_login(): array {
	return diluxone_users_option( 'diluxone_users_sso_login' ) ? diluxone_users_sso_available() : array();
}

/**
 * The URL segment where the round trip with the networks lives.
 *
 * It can be moved with the filter in case a site already has a page at /sso/.
 * Changing it forces a pass over the providers' consoles, because the
 * callback URL is registered over there.
 */
function diluxone_users_sso_base(): string {
	return trim( (string) apply_filters( 'diluxone_users_sso_base', 'sso' ), '/' );
}

/**
 * The callback URL to paste into the provider's console.
 *
 * It is an address with a path and no parameters on purpose: Microsoft Entra
 * flatly rejects a callback URL with a query string ("URL may not contain a
 * query string") and Apple does the same. What used to be
 * `/?diluxone_users_sso=google` left those two out, so the path is the road
 * that works for everyone.
 *
 * With "plain" permalinks there is no possible path and it falls back to the
 * parameter, the only thing WordPress can resolve in that mode.
 */
function diluxone_users_sso_redirect_uri( string $id ): string {
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		return add_query_arg( 'diluxone_users_sso', $id, home_url( '/' ) );
	}

	return home_url( '/' . diluxone_users_sso_base() . '/' . $id . '/' );
}

/** The URL that fires the round trip. */
function diluxone_users_sso_login_url( string $id ): string {
	return add_query_arg( 'diluxone_users_go', 1, diluxone_users_sso_redirect_uri( $id ) );
}

/**
 * The URL that links a network to the account of whoever is signed in.
 *
 * The same trip as signing in, with a nonce on top: linking writes to an
 * account, and a link somebody else can make a signed-in person click must
 * not be able to do that. The handler asks for the nonce whenever the trip
 * starts with a session.
 */
function diluxone_users_sso_link_url( string $id ): string {
	return wp_nonce_url( diluxone_users_sso_login_url( $id ), 'diluxone_users_sso_link_' . $id, 'diluxone_users_nonce' );
}

/** The cookie that ties an outbound trip to the browser that started it. */
function diluxone_users_sso_cookie(): string {
	return 'diluxone_users_sso_' . COOKIEHASH;
}

/** The live-test URL, to open in a window of its own. */
function diluxone_users_sso_test_url( string $id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'diluxone_users_go'   => 1,
				'diluxone_users_test' => 1,
			),
			diluxone_users_sso_redirect_uri( $id )
		),
		'diluxone_users_sso_test_' . $id,
		'diluxone_users_nonce'
	);
}

/** The rule that makes /sso/<network>/ possible. */
function diluxone_users_sso_rule(): void {
	add_rewrite_rule(
		'^' . preg_quote( diluxone_users_sso_base(), '/' ) . '/([a-z0-9_-]+)/?$',
		'index.php?diluxone_users_sso=$matches[1]',
		'top'
	);
}
add_action( 'init', 'diluxone_users_sso_rule' );

/**
 * Without this WordPress throws away the value the rule captured.
 *
 * @param array<int, string> $vars
 * @return array<int, string>
 */
function diluxone_users_sso_query_var( array $vars ): array {
	$vars[] = 'diluxone_users_sso';

	return $vars;
}
add_filter( 'query_vars', 'diluxone_users_sso_query_var' );

/* ── Reading each provider's profile ───────────────────────────────── */

/*
 * Every mapper answers one more question besides who this is: did the
 * provider vouch for the e-mail? Three answers — yes, no, and it said nothing
 * — and they are kept apart on purpose, because the flow below treats "it
 * said nothing" differently from "it said no". An e-mail nobody vouched for
 * is an e-mail anybody can type into a profile, and matching accounts by it
 * would hand the account to whoever typed it.
 */

/**
 * A provider's "the e-mail is verified" claim, read as one of three answers.
 *
 * @param mixed $claim What the provider sent, or null when it sent nothing.
 * @return bool|null True or false when it said so; null when it did not.
 */
function diluxone_users_sso_verified_claim( $claim ): ?bool {
	if ( null === $claim ) {
		return null;
	}

	// Google answers with a boolean, others with the word: both are read.
	return filter_var( $claim, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
}

/**
 * OpenID Connect (Google, Microsoft, LinkedIn, Yahoo, Twitch, GitLab): the
 * profile already comes with standard names.
 *
 * `email_verified` is a standard claim, and not every one of them sends it:
 * Microsoft's userinfo does not, and there the e-mail of a work account is
 * whatever its tenant wrote in it. That is why the absence is passed on as an
 * absence and not read as a yes.
 *
 * @param array<string, mixed> $data
 * @return array{id: string, email: string, name: string, last_name: string, verified: bool|null}
 */
function diluxone_users_sso_map_oidc( array $data, string $token ): array {
	return array(
		'id'        => (string) ( $data['sub'] ?? '' ),
		'email'     => (string) ( $data['email'] ?? '' ),
		'name'      => (string) ( $data['given_name'] ?? $data['preferred_username'] ?? '' ),
		'last_name' => (string) ( $data['family_name'] ?? '' ),
		'verified'  => diluxone_users_sso_verified_claim( $data['email_verified'] ?? null ),
	);
}

/**
 * Facebook uses first_name / last_name.
 *
 * It sends no claim because it needs none: an e-mail only appears in the
 * profile once Facebook has confirmed it, and an unconfirmed one is left out.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function diluxone_users_sso_map_facebook( array $data, string $token ): array {
	$email = (string) ( $data['email'] ?? '' );

	return array(
		'id'        => (string) ( $data['id'] ?? '' ),
		'email'     => $email,
		'name'      => (string) ( $data['first_name'] ?? '' ),
		'last_name' => (string) ( $data['last_name'] ?? '' ),
		'verified'  => '' !== $email ? true : null,
	);
}

/**
 * GitHub sends a single `name` field and hides the e-mail when it is private:
 * it has to be asked for separately, keeping the verified primary one.
 *
 * The list is always asked for and not only when the profile has no e-mail:
 * the one in the profile is the public address, which GitHub lets anybody
 * write and confirms nothing about. The list is the only place that says
 * which ones were confirmed.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function diluxone_users_sso_map_github( array $data, string $token ): array {
	$email    = '';
	$verified = null;

	foreach ( (array) diluxone_users_sso_get( 'https://api.github.com/user/emails', $token ) as $row ) {
		if ( is_array( $row ) && ! empty( $row['primary'] ) && ! empty( $row['verified'] ) ) {
			$email    = (string) $row['email'];
			$verified = true;
			break;
		}
	}

	if ( '' === $email ) {
		$email = (string) ( $data['email'] ?? '' );
	}

	return array_merge(
		diluxone_users_sso_split_name( (string) ( $data['name'] ?? '' ) ),
		array(
			'id'       => (string) ( $data['id'] ?? '' ),
			'email'    => $email,
			'verified' => $verified,
		)
	);
}

/**
 * WordPress.com returns the profile under keys of its own.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function diluxone_users_sso_map_wordpress( array $data, string $token ): array {
	return array_merge(
		diluxone_users_sso_split_name( (string) ( $data['display_name'] ?? '' ) ),
		array(
			'id'       => (string) ( $data['ID'] ?? '' ),
			'email'    => (string) ( $data['email'] ?? '' ),
			'verified' => diluxone_users_sso_verified_claim( $data['email_verified'] ?? null ),
		)
	);
}

/**
 * Discord: the e-mail only comes if the `email` scope was asked for.
 *
 * And it comes whether or not the person confirmed it — `verified` says
 * which. This is the provider that made the question unavoidable.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function diluxone_users_sso_map_discord( array $data, string $token ): array {
	return array_merge(
		diluxone_users_sso_split_name( (string) ( $data['global_name'] ?? $data['username'] ?? '' ) ),
		array(
			'id'       => (string) ( $data['id'] ?? '' ),
			'email'    => (string) ( $data['email'] ?? '' ),
			'verified' => diluxone_users_sso_verified_claim( $data['verified'] ?? null ),
		)
	);
}

/**
 * Amazon: name / email / user_id.
 *
 * Login with Amazon only hands over the address of the Amazon account, which
 * Amazon confirmed before the account could be used.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function diluxone_users_sso_map_amazon( array $data, string $token ): array {
	$email = (string) ( $data['email'] ?? '' );

	return array_merge(
		diluxone_users_sso_split_name( (string) ( $data['name'] ?? '' ) ),
		array(
			'id'       => (string) ( $data['user_id'] ?? '' ),
			'email'    => $email,
			'verified' => '' !== $email ? true : null,
		)
	);
}

/**
 * X (Twitter) does not return the e-mail, no matter what scope it is asked for.
 *
 * It is left empty on purpose: the flow above knows what to do with that —
 * linking to an account that is already in is fine, creating a new account is
 * not, because the e-mail is the site's notion of identity.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function diluxone_users_sso_map_twitter( array $data, string $token ): array {
	$user = (array) ( $data['data'] ?? array() );

	return array_merge(
		diluxone_users_sso_split_name( (string) ( $user['name'] ?? '' ) ),
		array(
			'id'       => (string) ( $user['id'] ?? '' ),
			'email'    => '',
			'verified' => null,
		)
	);
}

/**
 * Splits a full name into first name and last name.
 *
 * @return array{name: string, last_name: string}
 */
function diluxone_users_sso_split_name( string $full ): array {
	$parts = preg_split( '/\s+/u', trim( $full ) );
	$parts = is_array( $parts ) ? $parts : array();

	return array(
		'name'      => (string) ( $parts[0] ?? '' ),
		'last_name' => trim( implode( ' ', array_slice( $parts, 1 ) ) ),
	);
}

/* ── The round trip ────────────────────────────────────────────────── */

/**
 * An authenticated GET that returns JSON.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_get( string $url, string $token ): array {
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				// GitHub rejects requests with no user agent.
				'User-Agent'    => 'diluxone-users',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array();
	}

	return (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
}

/**
 * Sends them to the provider's screen.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_sso_authorize( string $id, array $provider, bool $test = false ): void {
	$credentials = diluxone_users_sso_credentials( $id );
	$state       = wp_generate_password( 24, false, false );
	$browser     = wp_generate_password( 32, false, false );

	/*
	 * The `state` alone proves the callback answers a trip this site started.
	 * It does not prove it answers a trip THIS browser started: the state
	 * travels in the URL, and a URL is something one person can make another
	 * open. So the trip is tied to two more things. A secret that only lives
	 * in this browser's cookie, of which the server keeps the hash; and who
	 * was signed in when it started, so that a trip begun by nobody cannot
	 * end in somebody's account, and a trip begun by one account cannot end
	 * in another.
	 */
	$saved = array(
		'provider' => $id,
		'test'     => $test ? 1 : 0,
		'browser'  => wp_hash( $browser ),
		'user'     => get_current_user_id(),
	);

	diluxone_users_cookie_set( diluxone_users_sso_cookie(), $browser, time() + 10 * MINUTE_IN_SECONDS );

	// PKCE: instead of the secret, the hash of a random value is sent, and the
	// value itself is sent on the exchange. That way a code stolen on the way
	// back is no use to anyone. X requires it; the rest do not mind.
	$verifier = empty( $provider['pkce'] ) ? '' : wp_generate_password( 64, false, false );

	if ( '' !== $verifier ) {
		$saved['verifier'] = $verifier;
	}

	// The `state` is what stops anyone from forging a callback: it is stored
	// server-side and has to come back identical.
	set_transient( 'diluxone_users_sso_' . $state, $saved, 10 * MINUTE_IN_SECONDS );

	$args = array_merge(
		array(
			'client_id'     => rawurlencode( $credentials['id'] ),
			'redirect_uri'  => rawurlencode( diluxone_users_sso_redirect_uri( $id ) ),
			'response_type' => 'code',
			'scope'         => rawurlencode( $provider['scope'] ),
			'state'         => $state,
		),
		$provider['extra']
	);

	if ( '' !== $verifier ) {
		$args['code_challenge']        = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$args['code_challenge_method'] = 'S256';
	}

	// The provider is another domain, so it is added — for this redirect only
	// — to the hosts a safe redirect may go to, and the redirect is a safe
	// one: a provider list somebody filtered into sending people elsewhere
	// is stopped at a host that is not the one it names.
	$url   = add_query_arg( $args, $provider['authorize'] );
	$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
	$allow = static function ( array $hosts ) use ( $host ): array {
		$hosts[] = $host;

		return $hosts;
	};

	add_filter( 'allowed_redirect_hosts', $allow );
	wp_safe_redirect( $url );
	remove_filter( 'allowed_redirect_hosts', $allow );
	exit;
}

/**
 * Exchanges the code for an access token.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_sso_token( string $id, array $provider, string $code, string $verifier = '' ): string {
	$credentials = diluxone_users_sso_credentials( $id );

	$body = array(
		'client_id'     => $credentials['id'],
		'client_secret' => $credentials['secret'],
		'code'          => $code,
		'redirect_uri'  => diluxone_users_sso_redirect_uri( $id ),
		'grant_type'    => 'authorization_code',
	);

	if ( '' !== $verifier ) {
		$body['code_verifier'] = $verifier;
	}

	$headers = array( 'Accept' => 'application/json' );

	// X wants the secret in Basic auth and not in the body.
	if ( 'twitter' === $id ) {
		$headers['Authorization'] = 'Basic ' . base64_encode( $credentials['id'] . ':' . $credentials['secret'] );
		unset( $body['client_secret'] );
	}

	$response = wp_remote_post(
		$provider['token'],
		array(
			'timeout' => 15,
			'headers' => $headers,
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$parsed = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );

	return (string) ( $parsed['access_token'] ?? '' );
}

/**
 * May this e-mail be taken as the person's, on the provider's word?
 *
 * Two different questions hide in it. Matching an EXISTING account by e-mail
 * hands that account over, so it needs the provider's explicit yes — always,
 * whatever the setting says, because a provider that said nothing might be
 * one where the e-mail is a text field. Creating a NEW account with the
 * e-mail hands over nothing yet, so there the explicit no is what closes the
 * door, and the "verified only" setting decides what silence means.
 *
 * @param array<string, mixed> $identity What the mapper returned.
 * @param bool                 $linking  Whether an existing account is at stake.
 */
function diluxone_users_sso_email_trusted( array $identity, bool $linking ): bool {
	$verified = $identity['verified'] ?? null;

	if ( true === $verified ) {
		return true;
	}

	if ( false === $verified || $linking ) {
		return false;
	}

	return ! diluxone_users_option( 'diluxone_users_sso_verified_only' );
}

/**
 * The account a social identity is linked to, or 0.
 *
 * Among the network's accounts, not only this site's members: somebody linked
 * on the site next door is the same somebody here. The oldest link wins if a
 * duplicate was ever written, so the answer is always the same one.
 */
function diluxone_users_sso_owner( string $id, string $identity ): int {
	if ( '' === $identity ) {
		return 0;
	}

	$found = get_users(
		array(
			'meta_key'   => 'diluxone_users_sso_' . $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $identity, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 1,
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'fields'     => 'ID',
			'blog_id'    => 0,
		)
	);

	return array() === $found ? 0 : (int) $found[0];
}

/**
 * What a provider said about somebody, cleaned like anything else that comes
 * from outside before it is compared or stored: the name and the id as text,
 * the address as an address.
 *
 * @param array<string, mixed> $identity What the provider's map returned.
 * @return array{id: string, email: string, name: string, last_name: string, verified: bool|null}
 */
function diluxone_users_sso_clean( array $identity ): array {
	$verified = $identity['verified'] ?? null;

	return array(
		'id'        => sanitize_text_field( (string) ( $identity['id'] ?? '' ) ),
		'email'     => sanitize_email( (string) ( $identity['email'] ?? '' ) ),
		'name'      => sanitize_text_field( (string) ( $identity['name'] ?? '' ) ),
		'last_name' => sanitize_text_field( (string) ( $identity['last_name'] ?? '' ) ),
		'verified'  => is_bool( $verified ) ? $verified : null,
	);
}

/**
 * Finds or creates the account behind a social identity, and links it.
 *
 * Linking is by e-mail, the same as Nextend's "Link accounts by email"
 * setting: if there is already an account with that e-mail, it belongs to the
 * same person. That holds only because the provider verified the e-mail,
 * which is why it is asked for before believing it.
 *
 * @param array<string, mixed> $identity
 */
function diluxone_users_sso_user( string $id, array $identity ): int {
	$meta     = 'diluxone_users_sso_' . $id;
	$existing = diluxone_users_sso_owner( $id, $identity['id'] );

	if ( $existing > 0 ) {
		return diluxone_users_sso_role_blocked( $existing ) ? 0 : $existing;
	}

	if ( '' === $identity['email'] || ! is_email( $identity['email'] ) ) {
		return 0;
	}

	$known = get_user_by( 'email', $identity['email'] );

	// The account already exists with that e-mail but without this network
	// linked: it is the same person, and the network is added to it. That is
	// what makes signing in with Google today and GitHub tomorrow one account.
	if ( $known ) {
		if ( ! diluxone_users_option( 'diluxone_users_sso_link_by_email' ) || ! diluxone_users_sso_email_trusted( $identity, true ) ) {
			return 0;
		}

		$user_id = (int) $known->ID;
	} else {
		if ( ! diluxone_users_option( 'diluxone_users_sso_register' ) || ! diluxone_users_sso_email_trusted( $identity, false ) ) {
			return 0;
		}

		// Its own switch decides, not the e-mail link's: a site can create
		// accounts from social sign-in and not from a typed address.
		$user_id = diluxone_users_create_account( $identity['email'] );
	}

	if ( $user_id <= 0 || diluxone_users_sso_role_blocked( $user_id ) ) {
		return 0;
	}

	update_user_meta( $user_id, $meta, $identity['id'] );

	// The name is only filled in when it was empty: what the person typed on
	// the site wins over whatever the social network says.
	foreach ( array(
		'first_name' => 'name',
		'last_name'  => 'last_name',
	) as $field => $from ) {
		if ( '' !== $identity[ $from ] && '' === (string) get_user_meta( $user_id, $field, true ) ) {
			update_user_meta( $user_id, $field, $identity[ $from ] );
		}
	}

	return $user_id;
}

/**
 * Is this role kept out of signing in with a social account?
 *
 * The account with the most power is the one most worth protecting, and an
 * administrator account that gets in through Google depends on that Google
 * account not being lost. With e-mail-link access always available, closing
 * the social door to a role leaves nobody out.
 *
 * Asked of the allowed list and not of a blocked one. The setting used to be
 * the roles that could not — the only rule in the plugin written backwards,
 * where an empty list meant "everybody may" and a reader had to work that out
 * from the absence of anything.
 *
 * @param int $user_id User to check.
 */
function diluxone_users_sso_role_blocked( int $user_id ): bool {
	if ( 'some' !== diluxone_users_option( 'diluxone_users_sso_scope' ) ) {
		return false;
	}

	$allowed = (array) diluxone_users_option( 'diluxone_users_sso_roles' );
	$user    = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return false;
	}

	// "Only some roles" with none ticked reaches nobody, which is what it
	// says. It is the answer somebody is halfway through giving, and the one
	// the screen warns about rather than quietly reading as "everybody".
	return array() === array_intersect( $allowed, (array) $user->roles );
}

/**
 * The request exactly as it arrived.
 *
 * WordPress deletes `$_GET['error']` while it resolves the URL — it uses it
 * to record its own 404 — and an OAuth provider answers with precisely
 * `error` when somebody cancels the authorisation. So the copy is taken
 * early, on `init`, which runs before that happens.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_query( bool $fresh = false ): array {
	static $query = null;

	if ( null === $query || $fresh ) {
		$query = array();

		// By name, and not the whole of `$_GET`. The seven below are every
		// parameter this file ever asks for — `diluxone_users_sso_param()` and
		// `diluxone_users_sso_has()` are the only readers, and they are only
		// ever called with these. Copying the request wholesale said nothing
		// about which of it mattered, to a reader or to a reviewer.
		foreach ( array( 'diluxone_users_sso', 'diluxone_users_go', 'diluxone_users_nonce', 'diluxone_users_test', 'state', 'code', 'error', 'error_description' ) as $name ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- this is the copy; every use below verifies the state transient, the browser cookie and the nonce.
			if ( ! isset( $_GET[ $name ] ) ) {
				continue;
			}

			$query[ $name ] = is_scalar( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ) : '';
			// phpcs:enable
		}
	}

	return $query;
}

/**
 * The copy of the request, taken early. diluxone_users_sso_param() reads it.
 *
 * It takes it afresh each time it runs, and it runs once per request: that
 * is also what lets a test that plays several requests in one process set
 * the query and take it again.
 */
function diluxone_users_sso_query_snapshot(): void {
	diluxone_users_sso_query( true );
}
add_action( 'init', 'diluxone_users_sso_query_snapshot', 0 );

/** One request parameter, already free of slashes. */
function diluxone_users_sso_param( string $key ): string {
	$query = diluxone_users_sso_query();

	return isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) ? (string) $query[ $key ] : '';
}

/** Did this parameter arrive at all, even empty? */
function diluxone_users_sso_has( string $key ): bool {
	return array_key_exists( $key, diluxone_users_sso_query() );
}

/**
 * The single entry point: fires the outbound trip and handles the return.
 *
 * @param WP|null $wp The object `parse_request` passes, with the route resolved.
 */
function diluxone_users_sso_handle( $wp = null ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the `state` plays that part.
	// The network can come from the route — /sso/google/ — or from the
	// parameter, which is what stayed registered in the older consoles and on
	// sites with plain permalinks.
	$path = $wp instanceof WP && isset( $wp->query_vars['diluxone_users_sso'] );
	$id   = $path
		? sanitize_key( (string) $wp->query_vars['diluxone_users_sso'] )
		: sanitize_key( diluxone_users_sso_param( 'diluxone_users_sso' ) );

	if ( '' === $id ) {
		return;
	}

	$providers = diluxone_users_sso_providers();

	// Having credentials filled in is enough. Requiring it to be turned on
	// here left the live test with no way out: a network cannot be turned on
	// without testing it, and the test would not start because the network was
	// not turned on. Who may fire what is decided further down.
	if ( ! isset( $providers[ $id ] ) || ! diluxone_users_sso_configured( $id ) ) {
		// The route is only reached on purpose: an old link to a network that
		// is gone has to say so, not draw the front page.
		if ( $path ) {
			diluxone_users_sso_fail();
		}

		return;
	}

	$provider = $providers[ $id ];

	// Test mode: only for whoever administers the site, and with their nonce.
	// It signs nobody in; it does the round trip and reports how it went.
	$test = diluxone_users_sso_has( 'diluxone_users_test' )
		&& current_user_can( 'manage_options' )
		&& wp_verify_nonce( sanitize_key( diluxone_users_sso_param( 'diluxone_users_nonce' ) ), 'diluxone_users_sso_test_' . $id );

	if ( diluxone_users_sso_has( 'diluxone_users_go' ) ) {
		// Heading out to authorise belongs to a network that is on, or to the
		// administrator's test. A half-configured network sends nobody anywhere.
		if ( ! $test && ! diluxone_users_sso_ready( $id ) ) {
			diluxone_users_sso_fail();
		}

		// Started with a session, the trip ends by linking the network to that
		// account: that is a write, and it needs the nonce the account screen
		// puts on its own link, so that no other page can start it.
		if ( ! $test && is_user_logged_in() && ! wp_verify_nonce( sanitize_key( diluxone_users_sso_param( 'diluxone_users_nonce' ) ), 'diluxone_users_sso_link_' . $id ) ) {
			diluxone_users_sso_fail();
		}

		// Every trip stores its state for ten minutes, and starting one needs
		// no account: counted per machine like the other doors that write
		// something for a stranger, or a script fills the options table.
		if ( ! $test && ! diluxone_users_ip_burst( 'sso', diluxone_users_login_burst() ) ) {
			diluxone_users_sso_fail();
		}

		diluxone_users_sso_authorize( $id, $provider, $test );
	}

	// On a return the provider may answer with an error instead of a code:
	// showing it is half the value of the test.
	if ( diluxone_users_sso_has( 'error' ) ) {
		$detail     = sanitize_text_field( '' !== diluxone_users_sso_param( 'error_description' ) ? diluxone_users_sso_param( 'error_description' ) : diluxone_users_sso_param( 'error' ) );
		$state_data = get_transient( 'diluxone_users_sso_' . sanitize_text_field( diluxone_users_sso_param( 'state' ) ) );

		if ( is_array( $state_data ) && ! empty( $state_data['test'] ) ) {
			diluxone_users_sso_test_result( $provider, false, $detail );
		}

		diluxone_users_sso_fail();
	}

	$code  = sanitize_text_field( diluxone_users_sso_param( 'code' ) );
	$state = sanitize_text_field( diluxone_users_sso_param( 'state' ) );

	if ( '' === $code || '' === $state ) {
		return;
	}
	// phpcs:enable

	// Read, then spent: only the request that deletes the state goes on, so
	// one return from the provider cannot be replayed into two sessions.
	$stored = get_transient( 'diluxone_users_sso_' . $state );

	if ( ! delete_transient( 'diluxone_users_sso_' . $state ) ) {
		$stored = null;
	}

	if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $id ) {
		diluxone_users_sso_fail();
	}

	// The return has to come from the browser that left, and find the same
	// person — or the same nobody — that was signed in when it left. See
	// diluxone_users_sso_authorize() for what each of the two stops.
	if ( ! diluxone_users_sso_same_browser( $stored ) || (int) ( $stored['user'] ?? -1 ) !== get_current_user_id() ) {
		diluxone_users_sso_fail();
	}

	$is_test = ! empty( $stored['test'] );

	// The return of a network that was turned off between the outbound trip
	// and the return signs nobody in. The test does go on: it is precisely the
	// step before turning it on.
	if ( ! $is_test && ! diluxone_users_sso_ready( $id ) ) {
		diluxone_users_sso_fail();
	}

	$token = diluxone_users_sso_token( $id, $provider, $code, (string) ( $stored['verifier'] ?? '' ) );

	if ( '' === $token ) {
		if ( $is_test ) {
			diluxone_users_sso_test_result( $provider, false, __( 'The provider did not hand over an access token. Check the client ID and the secret.', 'diluxone-users' ) );
		}

		diluxone_users_sso_fail();
	}

	$identity = diluxone_users_sso_clean( (array) call_user_func( $provider['map'], diluxone_users_sso_get( $provider['profile'], $token ), $token ) );

	// The test ends here: nobody is signed in, it is only recorded as working.
	if ( $is_test ) {
		if ( '' === $identity['id'] ) {
			diluxone_users_sso_test_result( $provider, false, __( 'The token worked but the profile came back empty. The app is probably missing the permissions this provider needs.', 'diluxone-users' ) );
		}

		diluxone_users_sso_set_tested( $id, true );
		diluxone_users_sso_test_result( $provider, true, $identity['email'] );
	}

	// If they are already in, this is a link from the profile, not a sign-in.
	if ( is_user_logged_in() ) {
		$back  = (string) get_transient( 'diluxone_users_sso_back_' . get_current_user_id() );
		$back  = '' !== $back ? $back : home_url( '/' );
		$owner = '' !== $identity['id'] ? diluxone_users_sso_owner( $id, $identity['id'] ) : 0;

		// One identity, one account: the same social account linked to two
		// accounts would open whichever the lookup finds first, and the other
		// would never be reached with it.
		if ( $owner > 0 && get_current_user_id() !== $owner ) {
			wp_safe_redirect( add_query_arg( 'diluxone-users', 'taken', $back ) );
			exit;
		}

		if ( '' !== $identity['id'] ) {
			update_user_meta( get_current_user_id(), 'diluxone_users_sso_' . $id, $identity['id'] );

			diluxone_users_notify_security(
				get_current_user_id(),
				sprintf(
					/* translators: %s: name of the social network */
					__( 'The %s account was linked, and it now gets into this account.', 'diluxone-users' ),
					$provider['name']
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'diluxone-users', 'linked', $back ) );
		exit;
	}

	$user_id = diluxone_users_sso_user( $id, $identity );

	if ( $user_id <= 0 ) {
		diluxone_users_sso_fail();
	}

		/** See the filter of the same name in includes/login.php. */
	$redirect = (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user_id );

	// Same as the e-mail link: the session is opened by the common path, which
	// is also the one that knows whether a second factor is missing.
	diluxone_users_complete_login( $user_id, 'sso', true, $redirect );
}

/*
 * It goes on `parse_request` and not on `init` because that is where
 * WordPress has already resolved the route: before that, /sso/google/ is not
 * anything yet.
 */
add_action( 'parse_request', 'diluxone_users_sso_handle' );

/**
 * Does this browser hold the secret the trip left in it?
 *
 * @param array<string, mixed> $stored What was saved when the trip started.
 */
function diluxone_users_sso_same_browser( array $stored ): bool {
	$name   = diluxone_users_sso_cookie();
	$cookie = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';

	return '' !== $cookie && hash_equals( (string) ( $stored['browser'] ?? '' ), wp_hash( $cookie ) );
}

/** Back to the sign-in screen with the notice that it did not work. */
function diluxone_users_sso_fail(): void {
	wp_safe_redirect( add_query_arg( 'diluxone-users', 'social', diluxone_users_login_url() ) );
	exit;
}

/** Unlinks a network from the current user. */
function diluxone_users_sso_unlink(): void {
	check_admin_referer( 'diluxone_users_sso_unlink' );

	$id = sanitize_key( wp_unslash( $_POST['diluxone_users_provider'] ?? '' ) );

	if ( '' !== $id && is_user_logged_in() ) {
		delete_user_meta( get_current_user_id(), 'diluxone_users_sso_' . $id );

		diluxone_users_notify_security(
			get_current_user_id(),
			sprintf(
					/* translators: %s: name of the social network */
				__( 'The %s account was unlinked.', 'diluxone-users' ),
				diluxone_users_sso_providers()[ $id ]['name'] ?? $id
			)
		);
	}

	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : home_url( '/' ) );
	exit;
}
add_action( 'admin_post_diluxone_users_sso_unlink', 'diluxone_users_sso_unlink' );

/**
 * Which networks does this person have linked?
 *
 * @return array<int<0, max>, string>
 */
function diluxone_users_sso_linked( int $user_id ): array {
	$linked = array();

	foreach ( array_keys( diluxone_users_sso_providers() ) as $id ) {
		if ( '' !== (string) get_user_meta( $user_id, 'diluxone_users_sso_' . $id, true ) ) {
			$linked[] = $id;
		}
	}

	return $linked;
}

/**
 * The test result, in the window that opened it.
 *
 * It is a standalone page and not a redirect because the test runs in a
 * window of its own: what there is to do is report what happened and close it.
 *
 * @param array<string, mixed> $provider
 * @param bool                 $ok
 * @param string               $detail E-mail received, or the error.
 */
function diluxone_users_sso_test_result( array $provider, bool $ok, string $detail = '' ): void {
	$title = $ok
		? __( 'It works', 'diluxone-users' )
		: __( 'It did not work', 'diluxone-users' );

	$message = $ok
		? sprintf(
				/* translators: %s: provider name */
			__( 'The round trip with %s finished correctly. You can enable the button now.', 'diluxone-users' ),
			$provider['name']
		)
		: sprintf(
				/* translators: %s: provider name */
			__( 'The round trip with %s failed.', 'diluxone-users' ),
			$provider['name']
		);

	if ( $ok && '' !== $detail ) {
			/* translators: %s: e-mail address */
		$detail = sprintf( __( 'The provider handed over this email: %s', 'diluxone-users' ), $detail );
	}

	nocache_headers();

	// A page of its own, with its own two files: the look, and the button
	// that reloads the dashboard behind it and closes this window.
	wp_register_style( 'diluxone-users-sso-test', DILUXONE_USERS_URL . 'assets/diluxone-users-sso-test.css', array(), diluxone_users_asset_version( 'assets/diluxone-users-sso-test.css' ) );
	wp_register_script( 'diluxone-users-sso-test', DILUXONE_USERS_URL . 'assets/diluxone-users-sso-test.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-sso-test.js' ), true );

	?><!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<title><?php echo esc_html( $title ); ?></title>
		<?php wp_print_styles( 'diluxone-users-sso-test' ); ?>
	</head>
	<body>
		<div class="diluxone-users-sso-test <?php echo esc_attr( $ok ? 'is-ok' : 'is-failed' ); ?>">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( '' !== $detail ) : ?>
				<p class="diluxone-users-sso-test__detail"><?php echo esc_html( $detail ); ?></p>
			<?php endif; ?>
			<button type="button" data-diluxone-users-sso-test-close>
				<?php esc_html_e( 'Close', 'diluxone-users' ); ?>
			</button>
		</div>
		<?php wp_print_scripts( 'diluxone-users-sso-test' ); ?>
	</body>
	</html>
	<?php
	exit;
}
