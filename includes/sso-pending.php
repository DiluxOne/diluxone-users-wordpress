<?php
/**
 * A social sign-in whose address is proved by mail.
 *
 * With "Only verified addresses" on, a provider that does not confirm the
 * address it hands over used to end the sign-in in an error. The address can
 * be proved another way, the way the sign-in link proves it: a link mailed to
 * it. So the return from the provider stops here, before anything is created
 * or linked. A pending sign-in is kept, the link goes to that address, and
 * the person waits on the sign-in page, which shows the address half hidden.
 * Opening the link is what creates or finds the account, links the identity
 * and opens the session, through the same function every door ends in.
 *
 * The rules are the sign-in link's (login.php), copied and not shared:
 *   - Only the token's hash is kept, compared in constant time, and deleting
 *     the record is the check, so a link works exactly once.
 *   - The identity and the provider come from the record kept on the server,
 *     never from the link, which carries a handle and a token and nothing else.
 *   - The address never travels in a URL. The page shows it from a cookie
 *     only this browser has, already masked.
 *   - The link works only in the browser that went to the provider. The
 *     identity in the record is whoever started the trip, and the inbox is
 *     somebody else's when the address was typed in for them: opened
 *     anywhere, the mail would link a stranger's network to that account.
 *     So the trip leaves a secret in its browser, the record keeps its hash,
 *     and a link opened without it is expired, as the trip itself is
 *     (diluxone_users_sso_same_browser()).
 *   - Whatever happened, the answer is the same screen: throttled, unknown,
 *     sent.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The cookie holding the handle of this browser's pending sign-in. */
const DILUXONE_USERS_SSO_PENDING_COOKIE = 'diluxone_users_sso_pending';

/** The cookie holding the masked address the holding screen shows. */
const DILUXONE_USERS_SSO_PENDING_SHOWN = 'diluxone_users_sso_sent';

/** The cookie holding the secret that ties a pending sign-in to its browser. */
const DILUXONE_USERS_SSO_PENDING_BROWSER = 'diluxone_users_sso_browser';

/** Where a pending sign-in is kept: a network transient, like the link's throttle. */
function diluxone_users_sso_pending_key( string $handle ): string {
	return 'diluxone_users_sso_pending_' . $handle;
}

/**
 * The pending sign-in behind a handle, or an empty array.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_pending_get( string $handle ): array {
	if ( 1 !== preg_match( '/^[a-z0-9]{32}$/', $handle ) ) {
		return array();
	}

	$record = get_site_transient( diluxone_users_sso_pending_key( $handle ) );

	return is_array( $record ) ? $record : array();
}

/** The handle this browser holds, or ''. */
function diluxone_users_sso_pending_handle(): string {
	$handle = isset( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_COOKIE ] ) && is_string( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_COOKIE ] ) ) : '';

	return 1 === preg_match( '/^[a-z0-9]{32}$/', $handle ) ? $handle : '';
}

/** The secret this browser holds for its pending sign-in, or ''. */
function diluxone_users_sso_pending_secret(): string {
	return isset( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_BROWSER ] ) && is_string( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_BROWSER ] ) ? sanitize_key( wp_unslash( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_BROWSER ] ) ) : '';
}

/**
 * Is this the browser that started the pending sign-in?
 *
 * @param array<string, mixed> $record
 */
function diluxone_users_sso_pending_same_browser( array $record ): bool {
	$secret = diluxone_users_sso_pending_secret();
	$hash   = (string) ( $record['browser'] ?? '' );

	return '' !== $secret && '' !== $hash && hash_equals( $hash, wp_hash( $secret ) );
}

/** The masked address the holding screen shows, or ''. */
function diluxone_users_sso_pending_shown(): string {
	return isset( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_SHOWN ] ) && is_string( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_SHOWN ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ DILUXONE_USERS_SSO_PENDING_SHOWN ] ) ) : '';
}

/** The holding screen: the sign-in page in its `social-pending` state. */
function diluxone_users_sso_pending_url(): string {
	return add_query_arg( 'diluxone-users', 'social-pending', diluxone_users_login_url() );
}

/**
 * Starts a pending sign-in: keeps it, mails the link, shows the holding screen.
 *
 * Counted per machine first, on the sign-in link's count, because a pending
 * sign-in is a row written and a mail sent for a stranger. One that would
 * create an account is counted as the registration form counts one. Past
 * either ceiling nothing is kept or sent; within them the record is kept and
 * the per-address wait decides whether the mail goes now, or on "Send it
 * again". The screen is the same every time.
 *
 * @param array<string, mixed> $identity What the provider said, cleaned.
 */
function diluxone_users_sso_pending_start( string $id, array $identity ): void {
	$email = (string) $identity['email'];
	$done  = diluxone_users_sso_pending_url();
	$life  = diluxone_users_login_expiry() * MINUTE_IN_SECONDS;

	diluxone_users_cookie_set( DILUXONE_USERS_SSO_PENDING_SHOWN, diluxone_users_mask_email( $email ), time() + $life );

	if ( ! diluxone_users_ip_burst( 'link', diluxone_users_login_burst() ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	if ( diluxone_users_sso_target( array_merge( $identity, array( 'proved' => true ) ) ) < 0 && ! diluxone_users_register_allowed() ) {
		wp_safe_redirect( $done );
		exit;
	}

	$handle = strtolower( wp_generate_password( 32, false, false ) );
	$secret = strtolower( wp_generate_password( 32, false, false ) );
	$record = array(
		'provider' => $id,
		'identity' => array(
			'id'        => (string) $identity['id'],
			'email'     => $email,
			'name'      => (string) ( $identity['name'] ?? '' ),
			'last_name' => (string) ( $identity['last_name'] ?? '' ),
		),
		'hash'     => '',
		'browser'  => wp_hash( $secret ),
		'expires'  => time() + $life,
		// On a network, where the person was going when they came to the
		// hub: the cookie that holds it may be gone, or belong to another
		// browser, by the time the link is opened.
		'return'   => diluxone_users_hub_on() ? diluxone_users_return_held() : '',
	);

	set_site_transient( diluxone_users_sso_pending_key( $handle ), $record, $life );
	diluxone_users_cookie_set( DILUXONE_USERS_SSO_PENDING_COOKIE, $handle, time() + $life );
	diluxone_users_cookie_set( DILUXONE_USERS_SSO_PENDING_BROWSER, $secret, time() + $life );

	diluxone_users_sso_pending_send( $handle, $record, $secret );

	wp_safe_redirect( $done );
	exit;
}

/**
 * Mails a fresh link for a pending sign-in, unless that inbox was written to
 * a moment ago.
 *
 * The wait is the sign-in link's own, on the same key: one inbox is one
 * inbox, whichever form wrote to it. A new token replaces the old one, so
 * only the newest link works, and the record lives as long as that link.
 *
 * @param array<string, mixed> $record
 * @param string               $secret The browser's secret, whose cookie lives as long as the link.
 * @return bool Whether a link was sent.
 */
function diluxone_users_sso_pending_send( string $handle, array $record, string $secret ): bool {
	$email    = (string) ( $record['identity']['email'] ?? '' );
	$throttle = 'diluxone_users_throttle_' . md5( strtolower( $email ) );

	if ( '' === $email || get_site_transient( $throttle ) ) {
		return false;
	}

	set_site_transient( $throttle, 1, max( 1, (int) diluxone_users_option( 'diluxone_users_login_throttle' ) ) );

	$life  = diluxone_users_login_expiry() * MINUTE_IN_SECONDS;
	$token = wp_generate_password( 40, false, false );

	$record['hash']    = wp_hash( $token );
	$record['expires'] = time() + $life;

	set_site_transient( diluxone_users_sso_pending_key( $handle ), $record, $life );
	diluxone_users_cookie_set( DILUXONE_USERS_SSO_PENDING_COOKIE, $handle, time() + $life );
	diluxone_users_cookie_set( DILUXONE_USERS_SSO_PENDING_SHOWN, diluxone_users_mask_email( $email ), time() + $life );
	diluxone_users_cookie_set( DILUXONE_USERS_SSO_PENDING_BROWSER, $secret, time() + $life );

	$url = add_query_arg(
		array(
			'diluxone_users_sso_pending' => $handle,
			'diluxone_users_token'       => $token,
		),
		home_url( '/' )
	);

	$provider = diluxone_users_sso_providers()[ (string) ( $record['provider'] ?? '' ) ] ?? array();
	$network  = (string) ( $provider['name'] ?? $record['provider'] ?? '' );

	// Like the sign-in link, the link is written nowhere on the way out: it
	// is a credential (see diluxone_users_login_send()).

	/**
	 * Filters the e-mail that confirms a social account's address before it is sent.
	 *
	 * @since 1.0.0
	 *
	 * @param array{subject: string, body: string} $message
	 * @param string                               $email
	 * @param string                               $url
	 * @param string                               $network The provider's name.
	 */
	$message = apply_filters( 'diluxone_users_sso_confirm_email', diluxone_users_mail_sso_confirm( $email, $url, $network ), $email, $url, $network );

	diluxone_users_mail_sent( wp_mail( $email, (string) $message['subject'], (string) $message['body'] ) );

	return true;
}

/**
 * "Send it again", from the holding screen.
 *
 * Always back to the same screen, whatever happened: no pending sign-in in
 * this browser, or not this browser's, a machine past its count, an inbox
 * written to a moment ago, or a new link on its way.
 */
function diluxone_users_sso_pending_resend(): void {
	$done = diluxone_users_sso_pending_url();

	if ( ! isset( $_POST['diluxone_users_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_nonce'] ) ), 'diluxone_users_sso_resend' ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	$handle = diluxone_users_sso_pending_handle();
	$record = diluxone_users_sso_pending_get( $handle );

	if ( array() === $record || ! diluxone_users_sso_pending_same_browser( $record ) || diluxone_users_safe_mode() || ! diluxone_users_ip_burst( 'link', diluxone_users_login_burst() ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	diluxone_users_sso_pending_send( $handle, $record, diluxone_users_sso_pending_secret() );

	wp_safe_redirect( $done );
	exit;
}
add_action( 'admin_post_nopriv_diluxone_users_sso_resend', 'diluxone_users_sso_pending_resend' );
add_action( 'admin_post_diluxone_users_sso_resend', 'diluxone_users_sso_pending_resend' );

/**
 * Opens the link: the address is proved, and the sign-in goes on.
 *
 * Only in the browser that went to the provider, unlike the sign-in link:
 * the inbox proves the address, not that its owner started this trip, and
 * the identity linked is the trip's. A link that is missing, wrong, spent,
 * past its time or opened in another browser gets the sign-in link's own
 * answer, and nothing is created or linked. A provider turned off since gets
 * the social sign-in's.
 */
function diluxone_users_sso_pending_consume(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token IS the credential.
	if ( ! isset( $_GET['diluxone_users_sso_pending'], $_GET['diluxone_users_token'] ) || diluxone_users_safe_mode() ) {
		return;
	}

	$handle = sanitize_key( wp_unslash( $_GET['diluxone_users_sso_pending'] ) );
	$token  = sanitize_text_field( wp_unslash( $_GET['diluxone_users_token'] ) );
	// phpcs:enable

	$record = diluxone_users_sso_pending_get( $handle );
	$hash   = (string) ( $record['hash'] ?? '' );

	// Read, then spent: the delete is the check, so two requests carrying the
	// same link cannot both get in. Another browser spends nothing, so the
	// one that waits can still open it.
	if ( '' === $token || '' === $hash || (int) ( $record['expires'] ?? 0 ) < time() || ! hash_equals( $hash, wp_hash( $token ) ) || ! diluxone_users_sso_pending_same_browser( $record ) || ! delete_site_transient( diluxone_users_sso_pending_key( $handle ) ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', diluxone_users_login_url() ) );
		exit;
	}

	diluxone_users_sso_pending_forget();

	$id = (string) ( $record['provider'] ?? '' );

	// Turned off between the mail and the click: what an administrator turns
	// off disappears, links already in somebody's inbox included.
	if ( ! isset( diluxone_users_sso_providers()[ $id ] ) || ! diluxone_users_sso_ready( $id ) ) {
		diluxone_users_sso_fail();
	}

	$identity = array_merge(
		array(
			'id'        => '',
			'email'     => '',
			'name'      => '',
			'last_name' => '',
		),
		(array) ( $record['identity'] ?? array() ),
		array(
			'verified' => null,
			'proved'   => true,
		)
	);

	$user_id = diluxone_users_sso_user( $id, $identity );

	if ( $user_id <= 0 ) {
		diluxone_users_sso_fail();
	}

	// On a network, back to the site the person came from: kept with the
	// record, and held again here, where the hub's way back reads it.
	$return = diluxone_users_hub_on() ? diluxone_users_safe_return( (string) ( $record['return'] ?? '' ) ) : '';

	if ( '' !== $return ) {
		diluxone_users_return_hold( $return );
	}

	/** See the filter of the same name in includes/login.php. */
	$redirect = (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user_id );

	diluxone_users_complete_login( $user_id, 'sso', true, $redirect );
}
add_action( 'init', 'diluxone_users_sso_pending_consume' );

/** Forgets this browser's pending sign-in: it is over. */
function diluxone_users_sso_pending_forget(): void {
	foreach ( array( DILUXONE_USERS_SSO_PENDING_COOKIE, DILUXONE_USERS_SSO_PENDING_SHOWN, DILUXONE_USERS_SSO_PENDING_BROWSER ) as $name ) {
		if ( isset( $_COOKIE[ $name ] ) ) {
			unset( $_COOKIE[ $name ] );
			diluxone_users_cookie_set( $name, '', time() - YEAR_IN_SECONDS );
		}
	}
}
