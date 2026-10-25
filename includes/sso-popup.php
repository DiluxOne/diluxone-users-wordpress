<?php
/**
 * The social round trip in a popup.
 *
 * The buttons stay plain links to the round trip, so a browser with no script,
 * or one that blocks the popup, goes out and back in the whole window as it
 * always did. With the script (assets/diluxone-users-sso-popup.js) the same
 * trip runs in a window of its own, and the page that opened it stays where it
 * was.
 *
 * The popup's return ends where the whole window's would have ended — the
 * account, the second step, the required fields, the error — but that address
 * is kept here, server-side, for two minutes, under a random name held in an
 * HttpOnly cookie. The popup draws a page that only tells the page that opened
 * it "it is over" (and whether it may reload in place), and closes. That page
 * then asks the resume address where to go, and is sent there. Nothing that
 * crosses from one window to the other carries an address, a code, a state, a
 * key or a nonce.
 *
 * On a network all of it is the hub's, like the rest of the round trip.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** How long the address the popup's return ended on waits for the page that opened it. */
const DILUXONE_USERS_SSO_RESUME_TTL = 2 * MINUTE_IN_SECONDS;

/**
 * The address the popup opens: the button's own, saying it is a popup.
 *
 * `door` when the button is on one of the plugin's own sign-in or registration
 * pages, where the page is left for wherever a sign-in goes, and `1` anywhere
 * else, where it may reload in place.
 *
 * @param string $url  The button's address.
 * @param bool   $door Whether the button is on a sign-in or registration door.
 */
function diluxone_users_sso_popup_url( string $url, bool $door ): string {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'diluxone_users_popup=' . ( $door ? 'door' : '1' );
}

/**
 * What the popup tells the page that opened it: `in` or `next`.
 *
 * `in` is a plain sign-in that has nowhere of its own to go — the default
 * destination, the front page — begun from a page that is not a door: that
 * page reloads where it is, signed in. Everything else is `next`, and the page
 * goes on through the resume address to where the whole window would have
 * gone: a return held for another site, the destination a filter chose, the
 * second step, the required fields, the account's notice of a link, the error.
 *
 * @param bool   $signed_in Whether the return opened a session that the trip did not start with.
 * @param bool   $door      Whether the trip began on a sign-in or registration door.
 * @param string $location  Where the return was about to send the browser.
 * @param string $home      The site's front page, the default destination.
 */
function diluxone_users_sso_popup_kind( bool $signed_in, bool $door, string $location, string $home ): string {
	if ( ! $signed_in || $door ) {
		return 'next';
	}

	return rtrim( $location, '/' ) === rtrim( $home, '/' ) ? 'in' : 'next';
}

/**
 * The address the page that opened the popup goes to when it is told `next`.
 *
 * On the same route as the round trip, so on a network it is the hub's.
 */
function diluxone_users_sso_resume_url( string $id ): string {
	return add_query_arg( 'diluxone_users_resume', 1, diluxone_users_sso_redirect_uri( $id ) );
}

/** The cookie naming the address that waits for the page that opened the popup. */
function diluxone_users_sso_resume_cookie(): string {
	return 'diluxone_users_sso_resume_' . COOKIEHASH;
}

/**
 * Is the button being drawn on one of the plugin's sign-in or registration doors?
 *
 * The page of its own, or the pages chosen for signing in and registering.
 */
function diluxone_users_sso_on_door(): bool {
	if ( in_array( diluxone_users_door_view(), array( 'login', 'register' ), true ) ) {
		return true;
	}

	$doors = array_filter( array( diluxone_users_page_here( 'diluxone_users_login_page' ), diluxone_users_page_here( 'diluxone_users_register_page' ) ) );

	return array() !== $doors && is_page( $doors );
}

/** The popup script, for the page that draws a live button. Enqueued late: it can be drawn from a shortcode. */
function diluxone_users_sso_enqueue_popup(): void {
	if ( wp_script_is( 'diluxone-users-sso-popup', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_script( 'diluxone-users-sso-popup', DILUXONE_USERS_URL . 'assets/diluxone-users-sso-popup.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-sso-popup.js' ), true );
}

/**
 * Marks a popup trip in this browser, for a return whose state cannot be read.
 *
 * The state says it is a popup trip; but a return with a forged state, or one
 * that took longer than the state lives, has none to say so, and its error
 * still has to reach the page that opened the popup. So the trip is also kept
 * under the hash of the secret only this browser holds (the trip's cookie):
 * nothing in the return's query can set it.
 *
 * @param string $browser The trip's browser secret, as it goes in the cookie.
 * @param bool   $door    Whether the trip began on a door.
 * @param int    $user    Who was signed in when it began.
 */
function diluxone_users_sso_popup_mark( string $browser, bool $door, int $user ): void {
	set_transient(
		'diluxone_users_sso_popup_' . wp_hash( $browser ),
		array(
			'door' => $door ? 1 : 0,
			'user' => $user,
		),
		10 * MINUTE_IN_SECONDS
	);
}

/** The browser's popup mark, keyed by the trip's cookie. */
function diluxone_users_sso_popup_mark_key(): string {
	$name   = diluxone_users_sso_cookie();
	$cookie = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';

	return '' === $cookie ? '' : 'diluxone_users_sso_popup_' . wp_hash( $cookie );
}

/**
 * The popup trip a return answers, or null for a trip in the whole window.
 *
 * From the stored state when there is one, and from this browser's mark when
 * there is none (diluxone_users_sso_popup_mark()); never from the query.
 *
 * @param mixed $stored The state as it was stored, or anything else when it could not be read.
 * @return array{door: bool, user: int}|null
 */
function diluxone_users_sso_popup_trip( $stored ): ?array {
	if ( is_array( $stored ) ) {
		if ( empty( $stored['popup'] ) || ! empty( $stored['test'] ) ) {
			return null;
		}

		return array(
			'door' => ! empty( $stored['door'] ),
			'user' => (int) ( $stored['user'] ?? 0 ),
		);
	}

	$key  = diluxone_users_sso_popup_mark_key();
	$mark = '' === $key ? false : get_transient( $key );

	if ( ! is_array( $mark ) ) {
		return null;
	}

	return array(
		'door' => ! empty( $mark['door'] ),
		'user' => (int) ( $mark['user'] ?? 0 ),
	);
}

/**
 * What the request's popup catch holds: the trip, or null when there is none.
 *
 * @param array{door: bool, user: int, id: string}|null $set   The trip to hold.
 * @param bool                                          $write Whether to write $set (null forgets it).
 * @return array{door: bool, user: int, id: string}|null
 */
function diluxone_users_sso_popup_context( ?array $set = null, bool $write = false ): ?array {
	static $context = null;

	if ( $write ) {
		$context = $set;
	}

	return $context;
}

/**
 * From here on, the redirect that ends this request ends it in the popup.
 *
 * Only for a popup trip's return (or its refusal at the start), and only for
 * this request: auth.php and fields-missing.php redirect as they always do,
 * and the redirect is caught on its way out. Before everything else on
 * `wp_redirect`, so what is caught is the address as it was decided.
 *
 * @param string                       $id   The provider.
 * @param array{door: bool, user: int} $trip The popup trip.
 */
function diluxone_users_sso_popup_catch( string $id, array $trip ): void {
	diluxone_users_sso_popup_context( $trip + array( 'id' => $id ), true );

	add_filter( 'wp_redirect', 'diluxone_users_sso_popup_finish', 0 );
}

/**
 * The end of a popup trip: the address is kept, and the bridge page drawn.
 *
 * The `wp_redirect` filter, while a popup trip's return is being answered.
 *
 * @param string $location Where the return was about to send the browser.
 * @return string The address untouched, when there is no popup trip to end.
 */
function diluxone_users_sso_popup_finish( $location ) {
	remove_filter( 'wp_redirect', 'diluxone_users_sso_popup_finish', 0 );

	$trip = diluxone_users_sso_popup_context();

	diluxone_users_sso_popup_context( null, true );

	if ( null === $trip || ! is_string( $location ) || '' === $location ) {
		return $location;
	}

	$kind = diluxone_users_sso_popup_kind( 0 === $trip['user'] && get_current_user_id() > 0, $trip['door'], $location, home_url( '/' ) );

	// The address stays here; the browser only holds a random name for it.
	$name = wp_generate_password( 32, false, false );

	set_transient( 'diluxone_users_sso_resume_' . wp_hash( $name ), $location, DILUXONE_USERS_SSO_RESUME_TTL );
	diluxone_users_cookie_set( diluxone_users_sso_resume_cookie(), $name, time() + DILUXONE_USERS_SSO_RESUME_TTL );

	$mark = diluxone_users_sso_popup_mark_key();

	if ( '' !== $mark ) {
		delete_transient( $mark );
	}

	diluxone_users_sso_popup_bridge( $kind, diluxone_users_sso_resume_url( $trip['id'] ) );
}

/**
 * The site's origin, the only one the bridge page speaks to.
 *
 * On a network, the hub's: the round trip runs there, and so does the page
 * with the buttons.
 */
function diluxone_users_sso_popup_origin(): string {
	$parts = wp_parse_url( home_url( '/' ) );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	return $parts['scheme'] . '://' . $parts['host'] . ( empty( $parts['port'] ) ? '' : ':' . $parts['port'] );
}

/**
 * The page the popup ends on: it tells the page that opened it, and closes.
 *
 * Like the live test's result page, a standalone page with its own script and
 * no inline one. What it hands the script is the kind, the site's origin and
 * the resume address — nothing the return carried.
 *
 * @param string $kind   `in` or `next` (diluxone_users_sso_popup_kind()).
 * @param string $resume The resume address, for a popup that finds nobody to tell.
 * @return never
 */
function diluxone_users_sso_popup_bridge( string $kind, string $resume ): void {
	diluxone_users_no_cache();

	wp_register_script( 'diluxone-users-sso-bridge', DILUXONE_USERS_URL . 'assets/diluxone-users-sso-bridge.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-sso-bridge.js' ), true );

	?><!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="robots" content="noindex">
		<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	</head>
	<body data-diluxone-users-sso-bridge data-kind="<?php echo esc_attr( 'in' === $kind ? 'in' : 'next' ); ?>" data-origin="<?php echo esc_attr( diluxone_users_sso_popup_origin() ); ?>" data-resume="<?php echo esc_url( $resume ); ?>">
		<p><a href="<?php echo esc_url( $resume ); ?>"><?php esc_html_e( 'Back to the site', 'diluxone-users' ); ?></a></p>
		<?php wp_print_scripts( 'diluxone-users-sso-bridge' ); ?>
	</body>
	</html>
	<?php
	exit;
}

/**
 * The resume address: where the popup's return ended, for the page that opened it.
 *
 * Read and spent: the address is deleted as it is read, the cookie with it,
 * and it is checked again on the way out. Nothing waiting — it was used, it
 * expired, it was never there — is the sign-in page. It opens no session:
 * whatever the return did, it did in the popup.
 */
function diluxone_users_sso_resume(): void {
	diluxone_users_no_cache();

	$cookie = diluxone_users_sso_resume_cookie();
	$name   = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
	$to     = '';

	if ( '' !== $name ) {
		$key = 'diluxone_users_sso_resume_' . wp_hash( $name );
		$to  = (string) get_transient( $key );

		if ( ! delete_transient( $key ) ) {
			$to = '';
		}

		unset( $_COOKIE[ $cookie ] );
		diluxone_users_cookie_set( $cookie, '', time() - YEAR_IN_SECONDS );
	}

	wp_safe_redirect( '' !== $to ? $to : diluxone_users_login_url() );
	exit;
}
