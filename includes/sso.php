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

	// "Use PKCE", when the form said: never on for a provider that does not
	// accept it, whatever was sent. Not saying keeps what was stored, and
	// nothing stored is on (diluxone_users_sso_pkce()).
	if ( array_key_exists( 'pkce', $values ) ) {
		$provider    = diluxone_users_sso_providers()[ $id ] ?? array();
		$new['pkce'] = ! empty( $values['pkce'] ) && ! empty( $provider['pkce_support'] ) ? 1 : 0;
	}

	// And nobody turns a provider on without testing it.
	if ( ! $new['tested'] ) {
		$new['active'] = 0;
	}

	// Over what the row held, not instead of it: whatever else something
	// keeps for this provider is not this form's to drop.
	$all[ $id ] = array_merge( (array) ( $all[ $id ] ?? array() ), $new );

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

/**
 * Does this provider's trip use PKCE?
 *
 * A provider that requires it, always; one that does not accept it, never,
 * whatever is stored. The rest, what "Use PKCE" says, and on when it was
 * never saved: a provider set up before the box existed gets it too, with
 * nothing written on the update.
 *
 * @param array<string, mixed> $provider Its row.
 */
function diluxone_users_sso_pkce( string $id, array $provider ): bool {
	if ( ! empty( $provider['pkce'] ) ) {
		return true;
	}

	if ( empty( $provider['pkce_support'] ) ) {
		return false;
	}

	$all = (array) diluxone_users_raw_get( 'diluxone_users_sso', array() );
	$row = (array) ( $all[ $id ] ?? array() );

	return ! array_key_exists( 'pkce', $row ) || ! empty( $row['pkce'] );
}

/** The S256 challenge of a PKCE verifier: its SHA-256, in base64url without padding. */
function diluxone_users_sso_pkce_challenge( string $verifier ): string {
	return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
}

/** Does it have credentials filled in? */
function diluxone_users_sso_configured( string $id ): bool {
	$c = diluxone_users_sso_credentials( $id );

	return '' !== $c['id'] && '' !== $c['secret'];
}

/**
 * Has any provider been set up, so that its round trip needs an address?
 *
 * From the moment its credentials are saved, because the live test before it
 * is turned on already comes back through the route.
 */
function diluxone_users_sso_routed(): bool {
	foreach ( array_keys( diluxone_users_sso_providers() ) as $id ) {
		if ( diluxone_users_sso_configured( (string) $id ) ) {
			return true;
		}
	}

	return false;
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
	// Shut while the emergency switch is on (safe-mode.php).
	if ( diluxone_users_safe_mode() ) {
		return array();
	}

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
	// On a network, always the hub's: people sign in there, the providers'
	// consoles hold one address per network, and the round trip starts and
	// ends on the site that keeps its state.
	return (string) diluxone_users_on_hub(
		static function () use ( $id ): string {
			if ( '' === (string) get_option( 'permalink_structure' ) ) {
				return add_query_arg( 'diluxone_users_sso', $id, home_url( '/' ) );
			}

			return home_url( '/' . diluxone_users_sso_base() . '/' . $id . '/' );
		}
	);
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

/**
 * The rule that makes /sso/<network>/ possible, on every site.
 *
 * On a network the round trip only ever reaches the hub, whose address is the
 * one the providers are given. Another site keeps the rule all the same: a
 * site that ran on its own before it joined the network may still have its
 * own /sso/<network>/ in a provider's console or a bookmark, and that address
 * has to reach diluxone_users_sso_handle(), which sends it on to the hub, the
 * way the same address written with a query parameter already does — not a
 * 404.
 *
 * Only once a provider has been set up: until then /sso/ is whatever the site
 * had there, and the plugin adds nothing to its rewrite rules.
 */
function diluxone_users_sso_rule(): void {
	if ( ! diluxone_users_sso_routed() ) {
		return;
	}

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
 * OpenID Connect (Google, Microsoft, and any provider that speaks it): the
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
				// Some providers' APIs refuse a request with no user agent.
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
function diluxone_users_sso_authorize( string $id, array $provider, bool $test = false, string $popup = '' ): void {
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

	// A trip in a popup ends in one (sso-popup.php). Never the live test's:
	// that one has its own window and its own page.
	if ( '' !== $popup && ! $test ) {
		$saved['popup'] = 1;
		$saved['door']  = 'door' === $popup ? 1 : 0;

		diluxone_users_sso_popup_mark( $browser, 'door' === $popup, get_current_user_id() );
	}

	diluxone_users_cookie_set( diluxone_users_sso_cookie(), $browser, time() + 10 * MINUTE_IN_SECONDS );

	// PKCE: instead of the secret, the hash of a random value is sent, and the
	// value itself is sent on the exchange. That way a code stolen on the way
	// back is no use to anyone. Sent to a provider that accepts it, unless the
	// administrator said not to (diluxone_users_sso_pkce()).
	$verifier = diluxone_users_sso_pkce( $id, $provider ) ? wp_generate_password( 64, false, false ) : '';

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
		$args['code_challenge']        = diluxone_users_sso_pkce_challenge( $verifier );
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

	// A provider whose row says `basic` wants the secret in an HTTP Basic
	// header and not in the body.
	if ( 'basic' === ( $provider['token_auth'] ?? 'post' ) ) {
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
 * An address proved by mail — the person opened the link sent to it, see
 * sso-pending.php — is theirs whatever the provider said: the inbox is the
 * proof the provider did not give. Only that path sets `proved`;
 * diluxone_users_sso_clean() drops it from anything a provider sends.
 *
 * @param array<string, mixed> $identity What the mapper returned.
 * @param bool                 $linking  Whether an existing account is at stake.
 */
function diluxone_users_sso_email_trusted( array $identity, bool $linking ): bool {
	if ( true === ( $identity['proved'] ?? null ) ) {
		return true;
	}

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
			'meta_key'   => 'diluxone_users_sso_' . $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup, on sign-in, of the account a social identity belongs to.
			'meta_value' => $identity, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the same lookup.
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
 * Where an identity nobody has linked yet would land, without doing it.
 *
 * The rules of matching by e-mail and of creating, asked once: by
 * diluxone_users_sso_user(), which then does it, and by the confirmation by
 * mail (sso-pending.php), which has to know before it mails a link whether
 * opening it would get the person anywhere.
 *
 * @param array<string, mixed> $identity
 * @param string|null          $why      Set to why, when it returns 0: role, no_email, link_off,
 *                                       email_unverified, register_off or register_role.
 * @return int The existing account it would be linked to, -1 when a new one
 *             would be created, 0 when it is refused.
 */
function diluxone_users_sso_target( array $identity, ?string &$why = null ): int {
	$email = (string) ( $identity['email'] ?? '' );

	if ( '' === $email || ! is_email( $email ) ) {
		$why = 'no_email';

		return 0;
	}

	$known = get_user_by( 'email', $email );

	// The account already exists with that e-mail but without this network
	// linked: it is the same person, and the network is added to it. That is
	// what makes signing in with Google today and Microsoft tomorrow one account.
	if ( $known ) {
		if ( ! diluxone_users_option( 'diluxone_users_sso_link_by_email' ) ) {
			$why = 'link_off';

			return 0;
		}

		if ( ! diluxone_users_sso_email_trusted( $identity, true ) ) {
			$why = 'email_unverified';

			return 0;
		}

		if ( diluxone_users_sso_role_blocked( (int) $known->ID ) ) {
			$why = 'role';

			return 0;
		}

		return (int) $known->ID;
	}

	if ( ! diluxone_users_option( 'diluxone_users_sso_register' ) || ! diluxone_users_network_takes_accounts() ) {
		$why = 'register_off';

		return 0;
	}

	if ( ! diluxone_users_sso_email_trusted( $identity, false ) ) {
		$why = 'email_unverified';

		return 0;
	}

	// An account social sign-in would then refuse is not created: the
	// role a new account gets has to be one that may use it.
	if ( ! diluxone_users_sso_role_allowed( 0, 'register' ) ) {
		$why = 'register_role';

		return 0;
	}

	return -1;
}

/**
 * Does this return from the provider wait for the address to be proved by mail?
 *
 * Only when that is the one thing missing: the identity is linked to nobody
 * yet, the site asks for verified addresses, the provider did not vouch for
 * this one — it said nothing, or said no — and, once proved, the address
 * would get the person somewhere: the existing account, while "Link accounts
 * by email" is on, or a new one, while "Create accounts" is. Anything else
 * is answered as it was before. It sits beside the rules it asks, and the
 * step itself is sso-pending.php's.
 *
 * @param array<string, mixed> $identity What the provider said, cleaned.
 */
function diluxone_users_sso_pending_wanted( string $id, array $identity ): bool {
	if ( ! diluxone_users_option( 'diluxone_users_sso_verified_only' ) || true === ( $identity['verified'] ?? null ) ) {
		return false;
	}

	$sub   = (string) ( $identity['id'] ?? '' );
	$email = (string) ( $identity['email'] ?? '' );

	if ( '' === $sub || '' === $email || ! is_email( $email ) || diluxone_users_sso_owner( $id, $sub ) > 0 ) {
		return false;
	}

	return 0 !== diluxone_users_sso_target( array_merge( $identity, array( 'proved' => true ) ) );
}


/**
 * Finds or creates the account behind a social identity, and links it.
 *
 * Linking is by e-mail, the same as Nextend's "Link accounts by email"
 * setting: if there is already an account with that e-mail, it belongs to the
 * same person. That holds only because the provider verified the e-mail,
 * which is why it is asked for before believing it.
 *
 * When it finds nobody, `$why` says which door stayed shut, as the slug the
 * activity log keeps (diluxone_users_sso_log_refused()).
 *
 * @param array<string, mixed> $identity
 * @param string|null          $why      Set to why, when it returns 0: role, no_email, link_off,
 *                                       email_unverified, register_off, register_role or create_failed.
 */
function diluxone_users_sso_user( string $id, array $identity, ?string &$why = null ): int {
	$meta     = 'diluxone_users_sso_' . $id;
	$existing = diluxone_users_sso_owner( $id, (string) ( $identity['id'] ?? '' ) );

	if ( $existing > 0 ) {
		if ( diluxone_users_sso_role_blocked( $existing ) ) {
			$why = 'role';

			return 0;
		}

		return $existing;
	}

	$user_id = diluxone_users_sso_target( $identity, $why );

	if ( 0 === $user_id ) {
		return 0;
	}

	// Its own switch decides, not the e-mail link's: a site can create
	// accounts from social sign-in and not from a typed address.
	if ( $user_id < 0 ) {
		$user_id = diluxone_users_create_account( (string) $identity['email'] );

		if ( $user_id <= 0 ) {
			$why = 'create_failed';

			return 0;
		}
	}

	if ( diluxone_users_sso_role_blocked( $user_id ) ) {
		$why = 'role';

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
 * May this person — or, with `register`, a new account — sign in with a
 * social account?
 *
 * Every role may, as the plugin has it. Keeping some out — the account with
 * the most power is the one most worth protecting, and an administrator who
 * gets in through Google depends on that Google account not being lost — is
 * Users+ Pro's "only some roles", through `diluxone_users_sso_role_allowed`.
 * With e-mail-link access always available, closing the social door to a role
 * leaves nobody out.
 *
 * @param int    $user_id The account, or 0 for one about to be created.
 * @param string $context 'account' for an existing account, 'register' for a
 *                        new one, which gets diluxone_users_register_role().
 */
function diluxone_users_sso_role_allowed( int $user_id, string $context = 'account' ): bool {
	/**
	 * Filters whether an account may sign in with a social account.
	 *
	 * True, as the plugin has it: every role may. On `register` the account
	 * is not there yet, `$user_id` is 0, and the role it would get is
	 * diluxone_users_register_role(): false is no account created.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allowed Whether it may.
	 * @param int    $user_id The account, or 0 on `register`.
	 * @param string $context 'account' or 'register'.
	 */
	return (bool) apply_filters( 'diluxone_users_sso_role_allowed', true, $user_id, $context );
}

/**
 * Is this account kept out of signing in with a social account?
 *
 * @param int $user_id User to check.
 */
function diluxone_users_sso_role_blocked( int $user_id ): bool {
	return get_userdata( $user_id ) instanceof WP_User && ! diluxone_users_sso_role_allowed( $user_id, 'account' );
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
		// parameter this file and sso-popup.php ever ask for — `diluxone_users_sso_param()` and
		// `diluxone_users_sso_has()` are the only readers, and they are only
		// ever called with these. Copying the request wholesale said nothing
		// about which of it mattered, to a reader or to a reviewer.
		foreach ( array( 'diluxone_users_sso', 'diluxone_users_go', 'diluxone_users_nonce', 'diluxone_users_test', 'diluxone_users_popup', 'diluxone_users_resume', 'state', 'code', 'error', 'error_description' ) as $name ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a copy of the query string, taken before WordPress can clear it; nothing is done with it here, and what acts on it validates the OAuth `state` it carries against the transient it was issued with.
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
	// The network can come from the route — /sso/google/ — or from the
	// parameter, which is what stayed registered in the older consoles and on
	// sites with plain permalinks.
	$path = $wp instanceof WP && isset( $wp->query_vars['diluxone_users_sso'] );
	$id   = $path
		? sanitize_key( (string) $wp->query_vars['diluxone_users_sso'] )
		: sanitize_key( diluxone_users_sso_param( 'diluxone_users_sso' ) );

	// The emergency switch shuts the door: the route is nothing, as if the
	// plugin were not there (safe-mode.php).
	if ( '' === $id || diluxone_users_safe_mode() ) {
		return;
	}

	// On a network the round trip is the hub's, and its state is kept there:
	// an old address on another site goes to the hub's sign-in, or to the
	// account for somebody already in, and starts nothing here.
	if ( diluxone_users_off_hub() ) {
		wp_safe_redirect( is_user_logged_in() ? diluxone_users_account_url() : diluxone_users_login_url() );
		exit;
	}

	// The page that opened a popup, coming for where the popup's return ended.
	if ( diluxone_users_sso_has( 'diluxone_users_resume' ) ) {
		diluxone_users_sso_resume();
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
		// Started in a popup, a refusal here ends in the popup too, and the
		// page that opened it shows it (sso-popup.php).
		$popup = $test ? '' : ( 'door' === diluxone_users_sso_param( 'diluxone_users_popup' ) ? 'door' : ( diluxone_users_sso_has( 'diluxone_users_popup' ) ? '1' : '' ) );

		if ( '' !== $popup ) {
			diluxone_users_sso_popup_catch(
				$id,
				array(
					'door' => 'door' === $popup,
					'user' => get_current_user_id(),
				)
			);
		}

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

		// Out to the provider in the whole popup: that redirect is not the end.
		remove_filter( 'wp_redirect', 'diluxone_users_sso_popup_finish', 0 );
		diluxone_users_sso_popup_context( null, true );

		diluxone_users_sso_authorize( $id, $provider, $test, $popup );
	}

	// On a return the provider may answer with an error instead of a code:
	// showing it is half the value of the test.
	if ( diluxone_users_sso_has( 'error' ) ) {
		$detail     = sanitize_text_field( '' !== diluxone_users_sso_param( 'error_description' ) ? diluxone_users_sso_param( 'error_description' ) : diluxone_users_sso_param( 'error' ) );
		$state_data = get_transient( 'diluxone_users_sso_' . sanitize_text_field( diluxone_users_sso_param( 'state' ) ) );

		if ( is_array( $state_data ) && ! empty( $state_data['test'] ) ) {
			diluxone_users_sso_test_result( $provider, false, $detail );
		}

		$popup = diluxone_users_sso_popup_trip( $state_data );

		if ( null !== $popup ) {
			diluxone_users_sso_popup_catch( $id, $popup );
		}

		// The provider's own code, never its free-text description: that one
		// is whatever the provider wrote, and the log keeps short slugs. OAuth's
		// codes are a few words long; anything longer is cut, so a stranger's
		// return cannot fill the row with a URL's worth of text.
		$error = substr( sanitize_key( diluxone_users_sso_param( 'error' ) ), 0, 64 );

		diluxone_users_sso_log_refused( $id, 'access_denied' === $error ? 'cancelled' : $error );
		diluxone_users_sso_fail();
	}

	$code  = sanitize_text_field( diluxone_users_sso_param( 'code' ) );
	$state = sanitize_text_field( diluxone_users_sso_param( 'state' ) );

	if ( '' === $code || '' === $state ) {
		return;
	}

	// Read, then spent: only the request that deletes the state goes on, so
	// one return from the provider cannot be replayed into two sessions.
	$stored = get_transient( 'diluxone_users_sso_' . $state );

	if ( ! delete_transient( 'diluxone_users_sso_' . $state ) ) {
		$stored = null;
	}

	// A popup trip's return ends in the popup, whatever it ends in: the flag
	// is the stored state's, or this browser's mark when there is no state to
	// read, never the query's (sso-popup.php).
	$popup = diluxone_users_sso_popup_trip( $stored );

	if ( null !== $popup ) {
		diluxone_users_sso_popup_catch( $id, $popup );
	}

	if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $id ) {
		diluxone_users_sso_log_refused( $id, 'state' );
		diluxone_users_sso_fail();
	}

	// The return has to come from the browser that left, and find the same
	// person — or the same nobody — that was signed in when it left. See
	// diluxone_users_sso_authorize() for what each of the two stops.
	if ( ! diluxone_users_sso_same_browser( $stored ) || (int) ( $stored['user'] ?? -1 ) !== get_current_user_id() ) {
		diluxone_users_sso_log_refused( $id, 'browser' );
		diluxone_users_sso_fail();
	}

	$is_test = ! empty( $stored['test'] );

	// The return of a network that was turned off between the outbound trip
	// and the return signs nobody in. The test does go on: it is precisely the
	// step before turning it on.
	if ( ! $is_test && ! diluxone_users_sso_ready( $id ) ) {
		diluxone_users_sso_log_refused( $id, 'off' );
		diluxone_users_sso_fail();
	}

	$token = diluxone_users_sso_token( $id, $provider, $code, (string) ( $stored['verifier'] ?? '' ) );

	if ( '' === $token ) {
		if ( $is_test ) {
			diluxone_users_sso_test_result( $provider, false, __( 'The provider did not hand over an access token. Check the client ID and the secret.', 'diluxone-users' ) );
		}

		diluxone_users_sso_log_refused( $id, 'token' );
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
		$back = (string) get_transient( 'diluxone_users_sso_back_' . get_current_user_id() );
		// Kept to the site's own hosts here, before the answer is added to it:
		// left to wp_safe_redirect(), an address elsewhere fell back to the
		// dashboard and the person was never told the link was made.
		$back  = wp_validate_redirect( '' !== $back ? $back : home_url( '/' ), home_url( '/' ) );
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

			$network = (string) $provider['name'];

			diluxone_users_notify_security(
				get_current_user_id(),
				static fn (): string => sprintf(
					/* translators: %s: name of the social network */
					__( 'The %s account was linked, and it now gets into this account.', 'diluxone-users' ),
					$network
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'diluxone-users', 'linked', $back ) );
		exit;
	}

	// A provider that did not vouch for the address, on a site that asks for
	// it to be vouched for: the address is proved by mail before anything is
	// created or linked (sso-pending.php). This ends the request, and it is a
	// return that signed nobody in, so the log keeps it with its reason.
	if ( diluxone_users_sso_pending_wanted( $id, $identity ) ) {
		diluxone_users_sso_log_refused( $id, 'email_unverified' );
		diluxone_users_sso_pending_start( $id, $identity );
	}

	$why     = null;
	$user_id = diluxone_users_sso_user( $id, $identity, $why );

	if ( $user_id <= 0 ) {
		diluxone_users_sso_log_refused( $id, $why ?? 'account' );
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

/**
 * Records a return from a provider that signed nobody in.
 *
 * The visitor only gets the one notice; the administrator gets the row, with
 * the network and why. Never the code nor the state: the row says what went
 * wrong, not what would have let somebody in. The account is 0 for the same
 * reason as a refused password sign-in: nobody signed in.
 *
 * @param string $id     The network's slug.
 * @param string $reason Why, as a slug: cancelled, state, browser, off, token, one of
 *                       diluxone_users_sso_user()'s, account or the provider's error.
 */
function diluxone_users_sso_log_refused( string $id, string $reason ): void {
	diluxone_users_log_record(
		'sso_failed',
		0,
		array(
			'provider' => $id,
			'reason'   => $reason,
		)
	);
}

/**
 * Back to the sign-in screen with the notice that it did not work.
 *
 * In a popup trip the redirect is caught on its way out, and the page that
 * opened the popup is the one sent there (diluxone_users_sso_popup_finish()).
 */
function diluxone_users_sso_fail(): void {
	wp_safe_redirect( add_query_arg( 'diluxone-users', 'social', diluxone_users_login_url() ) );
	exit;
}

/** Unlinks a network from the current user. */
function diluxone_users_sso_unlink(): void {
	check_admin_referer( 'diluxone_users_sso_unlink' );

	$id = sanitize_key( wp_unslash( $_POST['diluxone_users_provider'] ?? '' ) );

	// One of the providers, and nothing else under the prefix.
	if ( isset( diluxone_users_sso_providers()[ $id ] ) && is_user_logged_in() ) {
		delete_user_meta( get_current_user_id(), 'diluxone_users_sso_' . $id );

		$network = (string) ( diluxone_users_sso_providers()[ $id ]['name'] ?? $id );

		diluxone_users_notify_security(
			get_current_user_id(),
			static fn (): string => sprintf(
				/* translators: %s: name of the social network */
				__( 'The %s account was unlinked.', 'diluxone-users' ),
				$network
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
