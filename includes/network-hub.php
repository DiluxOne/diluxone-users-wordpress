<?php
/**
 * The hub: on a network, every door leads to one site, and back.
 *
 * A network applies its rules to every site, and a person's account — their
 * sign-in, their registration, their profile, their passkeys, their social
 * identities — lives on one site of it, the hub, which in this version is
 * always the network's main site. The other sites have no sign-in of their
 * own: whatever door a person finds on them (the "Sign in" item in the menu, a
 * shortcode placed on one of their pages, WordPress's own wp-login.php, a link
 * WordPress builds with wp_login_url()) takes them to the hub, carrying where
 * they were. Once they are in, whichever way they got in — the e-mail link, a
 * password, a social account, a passkey, with or without a second step — they
 * are sent back there, and made a member of that site if the network's
 * membership policy says every account belongs to every site (membership.php).
 *
 * Where they were travels as `redirect_to`, checked by
 * diluxone_users_safe_return(): an address on a site of this network and
 * nothing else. On the hub it is held in a short-lived, single-use, HttpOnly
 * cookie from the moment the sign-in page is opened, so that it survives the
 * trip to a mailbox, to a social provider or through the second step without
 * ever being written into a link or a mail. The e-mail link opened in another
 * browser has no cookie to read and lands on the hub's front page: the person
 * is signed in all the same, and the site they came from is one click away.
 *
 * What stays on a site: logging out, the password of a protected post, the
 * confirmation of a personal-data request, the end of a password reset (its
 * key is the credential, and WordPress builds that link on the main site
 * anyway), recovery mode, the interim sign-in inside the dashboard, the
 * administrator's emergency door (`?diluxone-users-admin=1`) and any POST —
 * a theme's own password form still signs people in where it posts, and the
 * network's second step still applies to it.
 *
 * A site on a domain of its own is left out of all this: it does not share
 * the network's cookies, so a session opened on the hub is not a session
 * there. Its wp-login.php stays its own, and Network Admin and the site's own
 * dashboard say that people have to sign in again there. There is no hand-off
 * between domains in this version.
 *
 * On a single site none of this runs.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The cookie that holds where somebody signing in on the hub came from. */
const DILUXONE_USERS_RETURN_COOKIE = 'diluxone_users_return';

/** How long it is held: long enough to open a mail, short enough to be forgotten. */
const DILUXONE_USERS_RETURN_TTL = 20 * MINUTE_IN_SECONDS;

/**
 * The wp-login.php actions a site of the network keeps for itself.
 *
 * Each is either something WordPress does to the session that is already open
 * (`logout`, `confirm_admin_email`), or a link whose query is the credential
 * and has to be answered where it was sent (`postpass`, `confirmaction`, `rp`,
 * `resetpass`, `enter_recovery_mode`).
 */
const DILUXONE_USERS_WP_LOGIN_LOCAL = array( 'logout', 'postpass', 'confirmaction', 'rp', 'resetpass', 'enter_recovery_mode', 'confirm_admin_email' );

/* ── Pure answers ──────────────────────────────────────────────────── */

/**
 * The return address, if it is one: an absolute http(s) address on one of
 * the hosts given.
 *
 * Pure on purpose, so the table of tricks is pinned down without WordPress.
 * A browser reads a backslash as a slash and drops tabs and line breaks, so an
 * address with any of those — as typed or once decoded — is refused whole
 * rather than cleaned: `https:\\evil.test` and `https://site%5C@evil.test`
 * would otherwise become somebody else's host on the way out. So is anything
 * without its own scheme (`//evil.test`, `/path`), anything carrying a user
 * name or password, and any host not on the list, compared without its case.
 *
 * @param string             $url   The address asked for.
 * @param array<int, string> $hosts The hosts it may be on, without ports.
 * @return string The address, or '' when it is not one.
 */
function diluxone_users_return_url_ok( string $url, array $hosts ): string {
	$url = trim( $url );

	if ( '' === $url || strlen( $url ) > 2000 ) {
		return '';
	}

	foreach ( array( $url, rawurldecode( $url ), rawurldecode( rawurldecode( $url ) ) ) as $form ) {
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\]/', $form ) ) {
			return '';
		}
	}

	if ( 1 === preg_match( '/\s/', $url ) || 1 !== preg_match( '#^https?://[^/]#i', $url ) ) {
		return '';
	}

	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}

	$allowed = array_map( 'strtolower', array_map( 'strval', $hosts ) );

	return in_array( strtolower( (string) $parts['host'] ), $allowed, true ) ? $url : '';
}

/**
 * Is a site's domain one of its own, neither the network's nor a subdomain of it?
 *
 * Both are compared as WordPress stores them, port included: a network at
 * `localhost:8893` has its subdomains at `<name>.localhost:8893`.
 *
 * @param string $domain  The site's domain.
 * @param string $network The network's domain.
 */
function diluxone_users_domain_is_mapped( string $domain, string $network ): bool {
	$domain  = strtolower( trim( $domain ) );
	$network = strtolower( trim( $network ) );

	if ( '' === $domain || '' === $network ) {
		return false;
	}

	return $domain !== $network && ! str_ends_with( $domain, '.' . $network );
}

/**
 * Does a request to a site's wp-login.php stay on that site?
 *
 * The rest go to the hub. Pure, like diluxone_users_should_redirect(), and for
 * the same reason.
 *
 * @param string               $action Value of `action` ('' is signing in).
 * @param array<string, mixed> $query  Equivalent to $_GET.
 * @param string               $method The request's method.
 */
function diluxone_users_wp_login_stays( string $action, array $query, string $method ): bool {
	// A POST is WordPress answering a form: a password posted by a theme's own
	// form, a new password typed after a reset. Sent elsewhere it would lose
	// what was typed; left alone, it goes through the network's rules anyway.
	if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
		return true;
	}

	if ( isset( $query['diluxone-users-admin'] ) || isset( $query['interim-login'] ) ) {
		return true;
	}

	return in_array( '' === $action ? 'login' : $action, DILUXONE_USERS_WP_LOGIN_LOCAL, true );
}

/* ── Where this site stands ────────────────────────────────────────── */

/** Is this a site of a network other than the hub? False on a single site. */
function diluxone_users_off_hub(): bool {
	return diluxone_users_scoped_storage_active() && ! diluxone_users_on_the_hub();
}

/**
 * Is a site of this network on a domain of its own?
 *
 * @param int $site_id The site; the current one when left out.
 */
function diluxone_users_site_mapped( int $site_id = 0 ): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	$site    = get_site( $site_id > 0 ? $site_id : get_current_blog_id() );
	$network = get_network();

	return $site instanceof WP_Site && $network instanceof WP_Network && diluxone_users_domain_is_mapped( (string) $site->domain, (string) $network->domain );
}

/**
 * Does this site send its doors to the hub?
 *
 * Every site of a network but the hub, and but a site on a domain of its own,
 * where a session opened on the hub would not arrive.
 */
function diluxone_users_sends_to_hub(): bool {
	// In safe mode every site keeps its own wp-login.php (safe-mode.php).
	return diluxone_users_off_hub() && ! diluxone_users_site_mapped() && ! diluxone_users_safe_mode();
}

/* ── The network's addresses ───────────────────────────────────────── */

/**
 * Every domain a site of this network is on, as WordPress stores it.
 *
 * Asked of the database once and kept until a site is added, changed or
 * deleted: a return address is checked on every sign-in, and WordPress checks
 * every safe redirect against the hosts this becomes.
 *
 * @return array<int, string>
 */
function diluxone_users_network_domains(): array {
	global $wpdb;

	if ( ! is_multisite() ) {
		return array();
	}

	$key     = 'diluxone_users_domains_' . get_current_network_id();
	$domains = get_site_transient( $key );

	if ( is_array( $domains ) ) {
		return array_map( 'strval', $domains );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached right below, and forgotten when a site changes.
	$domains = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT domain FROM {$wpdb->blogs} WHERE site_id = %d", get_current_network_id() ) );
	$domains = array_values( array_unique( array_map( static fn( $domain ): string => strtolower( (string) $domain ), $domains ) ) );

	set_site_transient( $key, $domains, DAY_IN_SECONDS );

	return $domains;
}

/** A site was added, changed or deleted: its domain may be new, or gone. */
function diluxone_users_network_domains_forget(): void {
	if ( is_multisite() ) {
		delete_site_transient( 'diluxone_users_domains_' . get_current_network_id() );
	}
}
add_action( 'wp_insert_site', 'diluxone_users_network_domains_forget' );
add_action( 'wp_update_site', 'diluxone_users_network_domains_forget' );
add_action( 'wp_delete_site', 'diluxone_users_network_domains_forget' );

/**
 * The hosts of this network, without ports: what an address is checked against.
 *
 * On a single site, the site's own.
 *
 * @return array<int, string>
 */
function diluxone_users_network_hosts(): array {
	if ( ! is_multisite() ) {
		return array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	$hosts = array();

	foreach ( diluxone_users_network_domains() as $domain ) {
		$host = (string) wp_parse_url( 'http://' . $domain, PHP_URL_HOST );

		if ( '' !== $host ) {
			$hosts[] = strtolower( $host );
		}
	}

	return array_values( array_unique( $hosts ) );
}

/**
 * Where somebody may be sent back to: an address on a site of this network.
 *
 * @param string $url The address asked for.
 * @return string The address, or '' when it is not one of this network's.
 */
function diluxone_users_safe_return( string $url ): string {
	return diluxone_users_return_url_ok( $url, diluxone_users_network_hosts() );
}

/**
 * The network's domains, for WordPress's safe redirects.
 *
 * On a subdomain network the sites are other hosts, and wp_safe_redirect()
 * only knows the current one: coming back to `beta.example.com` from the hub
 * would be refused. Only this network's hosts are added — never a host from
 * the request, and never another network's.
 *
 * @param array<int, string> $hosts The hosts allowed so far.
 * @return array<int, string>
 */
function diluxone_users_allowed_hosts( $hosts ): array {
	$hosts = (array) $hosts;

	if ( ! is_multisite() ) {
		return $hosts;
	}

	return array_values( array_unique( array_merge( $hosts, diluxone_users_network_hosts() ) ) );
}
add_filter( 'allowed_redirect_hosts', 'diluxone_users_allowed_hosts' );

/**
 * The sites of this network on a domain of their own.
 *
 * A site on a domain of its own does not share the network's cookies, so a
 * session opened anywhere else is not a session there: people sign in again.
 * That is how WordPress works with a mapped domain and not something this
 * plugin can fix from inside, so the network — and the site — are told.
 *
 * @return array<int, array{id: int, name: string, domain: string}>
 */
function diluxone_users_mapped_sites(): array {
	if ( ! is_multisite() ) {
		return array();
	}

	$network = get_network();

	if ( ! $network instanceof WP_Network ) {
		return array();
	}

	$mapped = array_values(
		array_filter(
			diluxone_users_network_domains(),
			static fn( string $domain ): bool => diluxone_users_domain_is_mapped( $domain, (string) $network->domain )
		)
	);

	if ( array() === $mapped ) {
		return array();
	}

	$sites = array();

	foreach ( get_sites(
		array(
			'number'     => 0,
			'network_id' => get_current_network_id(),
			'domain__in' => $mapped,
		)
	) as $site ) {
		$sites[] = array(
			'id'     => (int) $site->blog_id,
			'name'   => (string) get_blog_option( (int) $site->blog_id, 'blogname' ),
			'domain' => strtolower( (string) $site->domain ),
		);
	}

	return $sites;
}

/**
 * Which site of this network an address is on, or 0.
 *
 * @param string $url An address already checked by diluxone_users_safe_return().
 */
function diluxone_users_site_for_url( string $url ): int {
	$parts = wp_parse_url( $url );

	if ( ! is_multisite() || ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return 0;
	}

	$domain = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	$site   = get_site_by_path( $domain, (string) ( $parts['path'] ?? '/' ) );

	// Without the port, as WordPress itself looks a site up when the port is
	// the scheme's own.
	if ( ! $site instanceof WP_Site && isset( $parts['port'] ) ) {
		$site = get_site_by_path( strtolower( (string) $parts['host'] ), (string) ( $parts['path'] ?? '/' ) );
	}

	return $site instanceof WP_Site && (int) $site->network_id === get_current_network_id() ? (int) $site->blog_id : 0;
}

/* ── Sending people to the hub ─────────────────────────────────────── */

/**
 * Where the person is standing, to come back to.
 *
 * The address being looked at, when it is a page of the site or of its
 * dashboard; the site's front page from an AJAX call, a form's handler or
 * wp-login.php itself, where coming back to the same address would only send
 * them round again.
 */
function diluxone_users_here(): string {
	$home = home_url( '/' );

	if ( wp_doing_ajax() || in_array( $GLOBALS['pagenow'] ?? '', array( 'wp-login.php', 'admin-post.php', 'admin-ajax.php' ), true ) ) {
		return $home;
	}

	if ( 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
		return $home;
	}

	$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
	$uri  = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

	if ( '' === $host ) {
		return $home;
	}

	// The host is the request's, and is only kept if it is one of the
	// network's: that is the check, not a formality.
	$here = diluxone_users_safe_return( set_url_scheme( 'http://' . $host . '/' . ltrim( $uri, '/' ) ) );

	return '' !== $here ? $here : $home;
}

/**
 * An address on the hub, carrying where to come back to.
 *
 * @param string $url  The hub's address.
 * @param string $back Where to come back to; where the person is when empty.
 */
function diluxone_users_with_return( string $url, string $back = '' ): string {
	$back = diluxone_users_safe_return( $back );
	$back = '' !== $back ? $back : diluxone_users_here();

	return add_query_arg( 'redirect_to', rawurlencode( $back ), $url );
}

/**
 * The hub's wp-login.php, with the arguments given.
 *
 * @param array<string, string> $args Query arguments, not yet encoded.
 */
function diluxone_users_hub_wp_login( array $args = array() ): string {
	return add_query_arg( array_map( 'rawurlencode', $args ), get_site_url( diluxone_users_hub_site_id(), 'wp-login.php', 'login' ) );
}

/**
 * A site's wp-login.php, sent to the hub's.
 *
 * At priority 1 of `login_init`, before anything of the site's own — the
 * takeover of WordPress's screens, the reset hand-off — has a say: on this
 * site there is no sign-in to take over. The action and the return address
 * go along, and the hub then applies its own rules to them.
 */
function diluxone_users_wp_login_to_hub(): void {
	if ( ! diluxone_users_sends_to_hub() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which screen wp-login.php was asked for, to send it on.
	$query = array();

	foreach ( array( 'diluxone-users-admin', 'interim-login', 'action', 'reauth' ) as $name ) {
		if ( isset( $_GET[ $name ] ) ) {
			$query[ $name ] = sanitize_key( wp_unslash( $_GET[ $name ] ) );
		}
	}

	$asked = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
	// phpcs:enable

	$action = (string) ( $query['action'] ?? '' );

	if ( diluxone_users_wp_login_stays( $action, $query, sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
		return;
	}

	$return = diluxone_users_safe_return( $asked );
	$args   = array( 'redirect_to' => '' !== $return ? $return : home_url( '/' ) );

	if ( '' !== $action && 'login' !== $action ) {
		$args['action'] = $action;
	}

	if ( isset( $query['reauth'] ) ) {
		$args['reauth'] = '1';
	}

	wp_safe_redirect( diluxone_users_hub_wp_login( $args ) );
	exit;
}
add_action( 'login_init', 'diluxone_users_wp_login_to_hub', 1 );

/**
 * WordPress's own sign-in address, on a site that sends people to the hub.
 *
 * Whatever asks for it — the dashboard sending somebody with no session away,
 * a theme's "Log in" link, another plugin — gets the hub's, with where they
 * were going.
 *
 * @param string $url          The address WordPress built.
 * @param string $redirect     Where WordPress meant to send them afterwards.
 * @param bool   $force_reauth Whether to ask again even with a session.
 */
function diluxone_users_core_login_url( $url, $redirect = '', $force_reauth = false ): string {
	if ( ! diluxone_users_sends_to_hub() ) {
		return (string) $url;
	}

	$return = diluxone_users_safe_return( (string) $redirect );
	$args   = array( 'redirect_to' => '' !== $return ? $return : diluxone_users_here() );

	if ( $force_reauth ) {
		$args['reauth'] = '1';
	}

	return diluxone_users_hub_wp_login( $args );
}
add_filter( 'login_url', 'diluxone_users_core_login_url', 20, 3 );

/**
 * WordPress's own registration address, on a site that sends people to the hub.
 *
 * The hub's registration page when it has one open, WordPress's form on the
 * hub otherwise; the hub decides what that form does.
 *
 * @param string $url The address WordPress built.
 */
function diluxone_users_core_register_url( $url ): string {
	if ( ! diluxone_users_sends_to_hub() ) {
		return (string) $url;
	}

	if ( diluxone_users_register_form_open() ) {
		return diluxone_users_register_url();
	}

	return diluxone_users_hub_wp_login(
		array(
			'action'      => 'register',
			'redirect_to' => diluxone_users_here(),
		)
	);
}
add_filter( 'register_url', 'diluxone_users_core_register_url', 20 );

/**
 * "Lost your password?", on a site that sends people to the hub.
 *
 * After diluxone_users_lost_password_url(), which may already have chosen the
 * hub's sign-in page; if it left WordPress's form, it is the hub's form.
 *
 * @param string $url      The address so far.
 * @param string $redirect Where WordPress meant to send them afterwards.
 */
function diluxone_users_core_lostpassword_url( $url, $redirect = '' ): string {
	if ( ! diluxone_users_sends_to_hub() ) {
		return (string) $url;
	}

	if ( ! str_contains( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), 'wp-login.php' ) ) {
		return diluxone_users_with_return( (string) $url, (string) $redirect );
	}

	$return = diluxone_users_safe_return( (string) $redirect );

	return diluxone_users_hub_wp_login(
		array(
			'action'      => 'lostpassword',
			'redirect_to' => '' !== $return ? $return : diluxone_users_here(),
		)
	);
}
add_filter( 'lostpassword_url', 'diluxone_users_core_lostpassword_url', 20, 2 );

/* ── Coming back ───────────────────────────────────────────────────── */

/**
 * Holds where somebody arriving at the hub's sign-in came from.
 *
 * On the hub's wp-login.php (`login_init`, before the hub's own takeover sends
 * it to the sign-in page) and on its sign-in and registration pages. An
 * address that is not one of this network's is dropped without a word.
 * Somebody who arrives with a session already open has nothing to sign in to,
 * and goes straight back.
 */
function diluxone_users_return_capture(): void {
	if ( ! diluxone_users_scoped_storage_active() || ! diluxone_users_on_the_hub() ) {
		return;
	}

	$page = 'template_redirect' === current_filter();

	if ( $page ) {
		$doors = array_filter( array( diluxone_users_page_here( 'diluxone_users_login_page' ), diluxone_users_page_here( 'diluxone_users_register_page' ) ) );

		if ( array() === $doors || ! is_page( $doors ) ) {
			return;
		}
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an address to come back to, checked against the network's hosts before it is kept.
	$return = diluxone_users_safe_return( isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '' );

	if ( '' === $return ) {
		return;
	}

	if ( $page && is_user_logged_in() ) {
		diluxone_users_return_forget();
		wp_safe_redirect( $return );
		exit;
	}

	diluxone_users_return_hold( $return );
}
add_action( 'login_init', 'diluxone_users_return_capture', 2 );
add_action( 'template_redirect', 'diluxone_users_return_capture', 1 );

/**
 * Keeps the return address for the rest of the sign-in.
 *
 * In the cookie, for the requests to come, and in `$_COOKIE`, for the rest of
 * this one: the password form drawn on this same page reads it to fill its
 * own `redirect_to`, and a cookie only arrives with the next request.
 *
 * @param string $url An address already checked.
 */
function diluxone_users_return_hold( string $url ): void {
	$_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] = $url;

	diluxone_users_cookie_set( DILUXONE_USERS_RETURN_COOKIE, $url, time() + DILUXONE_USERS_RETURN_TTL );
}

/** The return address held for this browser, checked again, or ''. */
function diluxone_users_return_held(): string {
	if ( ! isset( $_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] ) ) {
		return '';
	}

	return diluxone_users_safe_return( esc_url_raw( wp_unslash( $_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] ) ) );
}

/** Forgets it: it is used once. */
function diluxone_users_return_forget(): void {
	if ( ! isset( $_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] ) ) {
		return;
	}

	unset( $_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] );

	diluxone_users_cookie_set( DILUXONE_USERS_RETURN_COOKIE, '', time() - YEAR_IN_SECONDS );
}

/**
 * Back where they came from, whichever door they came in by.
 *
 * Early, at 5: it sets where a sign-in lands by default, and a site's own
 * filter at 10 can still send them elsewhere — and the confirmation of a
 * personal-data request, at 99, still wins. Asked with nobody yet (the
 * password form, as it is drawn) it only reads; asked for somebody, it spends
 * the cookie and makes them a member of that site, as signing in there would
 * have.
 *
 * @param string $redirect Where they would go.
 * @param int    $user_id  Who, or 0 while nobody has signed in yet.
 */
function diluxone_users_return_after_sign_in( $redirect, $user_id = 0 ): string {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return (string) $redirect;
	}

	$held = diluxone_users_return_held();

	if ( (int) $user_id <= 0 ) {
		return '' !== $held ? $held : (string) $redirect;
	}

	diluxone_users_return_forget();

	if ( '' === $held ) {
		return (string) $redirect;
	}

	diluxone_users_return_join( (int) $user_id, $held );

	return diluxone_users_join_mark( $held, (int) $user_id );
}
add_filter( 'diluxone_users_login_redirect', 'diluxone_users_return_after_sign_in', 5, 2 );

/**
 * A password typed on the hub: the return address is in the form, and the cookie is spent.
 *
 * WordPress sends them to the form's `redirect_to` itself, and checks it with
 * wp_safe_redirect(); what is left is the membership of the site it names.
 * Before the second step (at 10), which may keep them on the hub for a code
 * and takes the same address along.
 *
 * @param string  $login The username typed.
 * @param WP_User $user  Who signed in.
 */
function diluxone_users_return_password( $login, $user ): void {
	// One of the plugin's own doors: the way back was spent when it chose
	// where to send them (diluxone_users_announce_wp_login()).
	if ( ! diluxone_users_scoped_storage_active() || ! $user instanceof WP_User || '' !== diluxone_users_own_door() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verified the password; this is where it sends them next, checked below.
	$return = diluxone_users_safe_return( isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '' );

	diluxone_users_return_forget();

	if ( '' !== $return ) {
		diluxone_users_return_join( (int) $user->ID, $return );
	}
}
add_action( 'wp_login', 'diluxone_users_return_password', 1, 2 );

/**
 * The way back from the hub: the site they came from, and its membership.
 *
 * Signing in on the hub for another site is signing in for that site: under
 * the "every site" policy they are made a member of it, as signing in there
 * would have (diluxone_users_join_site(), run on that site); under "click" and
 * "invite" only of the hub. Either way the activity log is told where they
 * came from (diluxone_users_sign_in_from()).
 *
 * @param int    $user_id Who.
 * @param string $url     Where they are going back to.
 */
function diluxone_users_return_join( int $user_id, string $url ): void {
	$site = diluxone_users_site_for_url( $url );

	if ( $site <= 0 || get_current_blog_id() === $site ) {
		return;
	}

	diluxone_users_sign_in_from( $site );

	switch_to_blog( $site );
	diluxone_users_join_site( $user_id );
	restore_current_blog();
}

/**
 * The site of the network a sign-in on the hub came from, for the activity log.
 *
 * Held for the rest of the request: the row is written when the session opens,
 * a moment after the way back was read.
 *
 * @param int|null $site The site, to say it; null to ask.
 * @return int The site, or 0 when the sign-in came from the hub itself.
 */
function diluxone_users_sign_in_from( ?int $site = null ): int {
	static $from = 0;

	if ( null !== $site ) {
		$from = max( 0, $site );
	}

	return $from;
}

/**
 * The same, from the address the sign-in goes back to.
 *
 * For the second step, finished in a request of its own, whose way back was
 * kept with the challenge.
 *
 * @param string $url Where they are going back to.
 */
function diluxone_users_sign_in_from_url( string $url ): void {
	if ( ! is_multisite() ) {
		return;
	}

	$site = diluxone_users_site_for_url( diluxone_users_safe_return( $url ) );

	if ( $site > 0 && get_current_blog_id() !== $site ) {
		diluxone_users_sign_in_from( $site );
	}
}

/* ── What a site that is not the hub draws ─────────────────────────── */

/**
 * The shortcodes that belong to the hub, and what each one is a door to.
 *
 * @return array<string, 'login'|'register'|'account'>
 */
function diluxone_users_hub_shortcodes(): array {
	return array(
		'diluxone_users_login'         => 'login',
		'diluxone_users_register'      => 'register',
		'diluxone_users_account'       => 'account',
		'diluxone_users_account_nav'   => 'account',
		'diluxone_users_fields'        => 'account',
		'diluxone_users_avatar'        => 'account',
		'diluxone_users_handle'        => 'account',
		'diluxone_users_accounts'      => 'account',
		'diluxone_users_sessions'      => 'account',
		'diluxone_users_notifications' => 'account',
	);
}

/**
 * On a site that is not the hub, the plugin's shortcodes are a way to the hub.
 *
 * A link and not a redirect: the shortcode is one piece of a page the site
 * wrote, and taking the whole page away because of one piece of it would be
 * the surprise. The sign-in and the registration become a button to the hub's
 * pages that comes back here; the account and its pieces, a button to the
 * account on the hub. What drew nothing before — the account's pieces to a
 * stranger, the sign-in to somebody already in — still draws nothing.
 *
 * @param false|string          $output What to draw instead, false to draw it.
 * @param string                $tag    The shortcode.
 * @param array<string, string> $attr   Its attributes.
 * @return false|string
 */
function diluxone_users_shortcode_off_hub( $output, $tag, $attr = array() ) {
	$doors = diluxone_users_hub_shortcodes();

	if ( false !== $output || ! isset( $doors[ $tag ] ) || ! diluxone_users_off_hub() ) {
		return $output;
	}

	$door = $doors[ $tag ];
	$in   = is_user_logged_in();

	if ( ( 'account' !== $door && $in ) || ( 'account' === $door && ! $in && 'diluxone_users_account' !== $tag ) ) {
		return '';
	}

	if ( 'account' === $door && ! $in ) {
		$door = 'login';
	}

	if ( 'register' === $door ) {
		$url = diluxone_users_register_form_open() ? diluxone_users_register_url() : diluxone_users_login_url();
	} elseif ( 'login' === $door ) {
		$url = diluxone_users_login_url();
	} else {
		$url = diluxone_users_account_url();
	}

	diluxone_users_enqueue_styles();

	return diluxone_users_render(
		'hub-door',
		array(
			'door' => $door,
			'url'  => $url,
			'hub'  => diluxone_users_hub_name(),
		)
	);
}
add_filter( 'pre_do_shortcode_tag', 'diluxone_users_shortcode_off_hub', 10, 3 );

/**
 * The actions posted to admin-post.php that belong to the hub.
 *
 * The forms that send them are drawn on the hub only; one posted to another
 * site — by hand, or from a page cached before — goes to the hub instead of
 * writing anything here.
 *
 * @return array<int, string>
 */
function diluxone_users_hub_posts(): array {
	return array(
		'diluxone_users_link_request',
		'diluxone_users_signup',
		'diluxone_users_reset',
		'diluxone_users_avatar',
		'diluxone_users_handle',
		'diluxone_users_fields_save',
		'diluxone_users_sso_unlink',
		'diluxone_users_security',
		'diluxone_users_sessions',
		'diluxone_users_notifications',
		'diluxone_users_data_request',
		'diluxone_users_passkey',
	);
}

/** Sends a form posted to a site that is not the hub to the hub, before its handler runs. */
function diluxone_users_post_to_hub(): void {
	if ( ! diluxone_users_off_hub() || diluxone_users_safe_mode() ) {
		return;
	}

	wp_safe_redirect( is_user_logged_in() ? diluxone_users_account_url() : diluxone_users_login_url() );
	exit;
}

foreach ( diluxone_users_hub_posts() as $diluxone_users_hub_post ) {
	add_action( 'admin_post_' . $diluxone_users_hub_post, 'diluxone_users_post_to_hub', 0 );
	add_action( 'admin_post_nopriv_' . $diluxone_users_hub_post, 'diluxone_users_post_to_hub', 0 );
}
unset( $diluxone_users_hub_post );

/* ── A site on a domain of its own ─────────────────────────────────── */

/**
 * Tells the administrator of a site on a domain of its own what that means.
 *
 * On its dashboard, where they look first: signing in on the hub does not
 * reach this site, so its people sign in again here.
 */
function diluxone_users_mapped_site_notice(): void {
	if ( ! diluxone_users_off_hub() || ! diluxone_users_site_mapped() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning" data-diluxone-users-mapped><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: this site's domain, 2: the name of the site where the network's accounts live */
				__( 'This site is on a domain of its own (%1$s). People must sign in again on this site: signing in on %2$s does not carry over to it. A domain of its own is not supported by DiluxOne Users+ in this version.', 'diluxone-users' ),
				(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				diluxone_users_hub_name()
			)
		)
	);
}

/** Only on the dashboard's front page. */
function diluxone_users_mapped_site_notice_hook(): void {
	add_action( 'admin_notices', 'diluxone_users_mapped_site_notice' );
}
add_action( 'load-index.php', 'diluxone_users_mapped_site_notice_hook' );
