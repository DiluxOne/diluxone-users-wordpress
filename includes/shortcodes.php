<?php
/**
 * The shortcodes: the door that lets this fit into any design.
 *
 *   [diluxone_users_login]    the "send me the link" form + the social buttons
 *                             (title="yes" adds the "Sign in" heading)
 *   [diluxone_users_fields]   the person's fields, for editing
 *   [diluxone_users_sessions] the open sessions, with the button to close them
 *   [diluxone_users_accounts] the linked social networks
 *
 * Each one draws a template the site can replace (see includes/templates.php),
 * and the CSS they bring can be turned off from the settings. That way
 * whoever installs it and touches nothing gets something usable, and whoever
 * has a design of their own has nothing to fight.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The state arriving through the query, to know which message to show. */
function diluxone_users_state(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks which message is seen.
	return isset( $_GET['diluxone-users'] ) ? sanitize_key( wp_unslash( $_GET['diluxone-users'] ) ) : '';
}

/**
 * The second-factor challenge, when there is one half-done.
 *
 * It goes before the sign-in form and replaces it: whoever is halfway does
 * not have to type their e-mail again, they have to finish.
 *
 * @return array<string, mixed>|array{}
 */
function diluxone_users_login_challenge(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- our own nonce IS the credential.
	if ( ! isset( $_GET['diluxone_users_2fa'], $_GET['diluxone_users_key'] ) ) {
		return array();
	}

	$user_id = absint( wp_unslash( $_GET['diluxone_users_2fa'] ) );
	$key     = sanitize_text_field( wp_unslash( $_GET['diluxone_users_key'] ) );
	$method  = sanitize_key( wp_unslash( $_GET['diluxone_users_method'] ?? '' ) );
	// phpcs:enable

	if ( array() === diluxone_users_2fa_pending( $user_id, $key ) ) {
		return array();
	}

	$methods = diluxone_users_2fa_available( $user_id );

	return array(
		'user_id' => $user_id,
		'key'     => $key,
		'method'  => isset( $methods[ $method ] ) ? $method : (string) array_key_first( $methods ),
		'methods' => $methods,
	);
}

/**
 * Login shortcode.
 *
 * The "Sign in" heading is off by default because this form almost always
 * lives on a page already called that, and the theme prints the page title:
 * drawing it here too says the same thing twice. `title="yes"` brings it back
 * for anywhere else — a widget, a section of a longer page.
 *
 * @param array<string, string>|string $atts WordPress sends '' when there are none.
 */
function diluxone_users_shortcode_login( $atts = array() ): string {
	if ( is_user_logged_in() ) {
		return '';
	}

	diluxone_users_enqueue_styles();

	// The frame goes around whichever of the two steps is showing: somebody
	// who asked for a code should not watch the page change shape under them.
	ob_start();
	diluxone_users_login_frame_open();
	$frame = (string) ob_get_clean();

	ob_start();
	diluxone_users_login_frame_close();
	$close = (string) ob_get_clean();

	// Halfway there in a different way: a password reset in progress. It comes
	// before the second factor because somebody who cannot get in at all is
	// not halfway through signing in.
	$resetting = function_exists( 'diluxone_users_reset_user' ) ? diluxone_users_reset_user() : null;

	if ( $resetting instanceof WP_User ) {
		return $frame . diluxone_users_render(
			'login-reset',
			array(
				'user'  => $resetting,
				'state' => diluxone_users_state(),
			)
		) . $close;
	}

	// Halfway there: the second factor is missing and nothing else.
	$challenge = diluxone_users_login_challenge();

	if ( array() !== $challenge ) {
		return $frame . diluxone_users_render( 'login-2fa', array_merge( $challenge, array( 'state' => diluxone_users_state() ) ) ) . $close;
	}

	$email = diluxone_users_sent_address();
	$atts  = shortcode_atts( array( 'title' => 'no' ), (array) $atts, 'diluxone_users_login' );

	return $frame . diluxone_users_render(
		'login.php',
		array(
			'state'     => diluxone_users_state(),
			'email'     => $email,
			'providers' => diluxone_users_sso_for_login(),
			'minutes'   => diluxone_users_login_expiry(),
			'title'     => in_array( strtolower( (string) $atts['title'] ), array( 'yes', '1', 'true', 'on' ), true ),
		)
	) . $close;
}
add_shortcode( 'diluxone_users_login', 'diluxone_users_shortcode_login' );

/**
 * The person's fields.
 *
 * `group` limits it to the fields of one group, so a site can split the form
 * into two blocks the way ours does (what is needed on top, the optional part
 * below).
 *
 * @param array<string, string>|string $atts WordPress sends '' when there are none.
 */
function diluxone_users_shortcode_fields( $atts = array() ): string {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'group' => '',
			'title' => '',
		),
		(array) $atts,
		'diluxone_users_fields_save'
	);

	diluxone_users_enqueue_styles();

	return diluxone_users_render(
		'fields.php',
		array(
			'user_id' => get_current_user_id(),
			'fields'  => diluxone_users_fields( (string) $atts['group'] ),
			'group'   => (string) $atts['group'],
			'title'   => (string) $atts['title'],
			'state'   => diluxone_users_state(),
		)
	);
}
add_shortcode( 'diluxone_users_fields', 'diluxone_users_shortcode_fields' );

/** Saves the [diluxone_users_fields] form. */
function diluxone_users_save_fields_form(): void {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You have to sign in first.', 'diluxone-users' ) );
	}

	check_admin_referer( 'diluxone_users_fields_save' );

	$group   = sanitize_key( wp_unslash( $_POST['diluxone_users_group'] ?? '' ) );
	$missing = diluxone_users_save( get_current_user_id(), diluxone_users_posted_fields( $group ), $group );
	$back    = wp_get_referer();
	$back    = $back ? $back : home_url( '/' );

	wp_safe_redirect( add_query_arg( 'diluxone-users', array() === $missing ? 'saved' : 'missing', $back ) );
	exit;
}
add_action( 'admin_post_diluxone_users_fields_save', 'diluxone_users_save_fields_form' );

/**
 * Sessions shortcode.
 *
 * @param array<string, string>|string $atts WordPress sends '' when there are none.
 */
function diluxone_users_shortcode_sessions( $atts = array() ): string {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	diluxone_users_enqueue_styles();

	return diluxone_users_render(
		'sessions.php',
		array(
			'sessions'      => diluxone_users_sessions( get_current_user_id() ),
			'can_close_one' => diluxone_users_sessions_addressable(),
			'state'         => diluxone_users_state(),
		)
	);
}
add_shortcode( 'diluxone_users_sessions', 'diluxone_users_shortcode_sessions' );

/**
 * The person's social networks. Shortcode: [diluxone_users_accounts]
 *
 * The `only` attribute splits the list: `linked` are the ones they already
 * sign in with and `available` the ones they could add. Without it, all of
 * them together. A site showing them in two separate boxes does not have to
 * filter anything itself.
 *
 * @param array<string, string>|string $atts WordPress sends '' when there are none.
 */
function diluxone_users_shortcode_accounts( $atts = array() ): string {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	diluxone_users_enqueue_styles();

	$atts    = shortcode_atts( array( 'only' => '' ), (array) $atts, 'diluxone_users_accounts' );
	$user_id = get_current_user_id();

	// Where it came from, to go back there after the trip to the provider:
	// the path WordPress resolved, which holds the section, under the home
	// URL — not the raw request URI, which on a site in a subfolder would
	// carry the folder twice. Written only when it changes.
	$here = '' !== (string) $GLOBALS['wp']->request ? home_url( user_trailingslashit( (string) $GLOBALS['wp']->request ) ) : ( is_singular() ? (string) get_permalink() : home_url( '/' ) );

	if ( get_transient( 'diluxone_users_sso_back_' . $user_id ) !== $here ) {
		set_transient( 'diluxone_users_sso_back_' . $user_id, $here, 10 * MINUTE_IN_SECONDS );
	}

	// Two lists on one page (the linked ones and the rest) are one answer:
	// what just happened is said by the first of them only.
	static $told = false;

	$state = $told ? '' : diluxone_users_state();
	$told  = true;

	$linked    = diluxone_users_sso_linked( $user_id );
	$providers = diluxone_users_sso_available();

	if ( 'linked' === $atts['only'] ) {
		$providers = array_filter( $providers, static fn( string $id ): bool => in_array( $id, $linked, true ), ARRAY_FILTER_USE_KEY );
	} elseif ( 'available' === $atts['only'] ) {
		$providers = array_filter( $providers, static fn( string $id ): bool => ! in_array( $id, $linked, true ), ARRAY_FILTER_USE_KEY );
	}

	return diluxone_users_render(
		'accounts.php',
		array(
			'providers' => $providers,
			'linked'    => $linked,
			'state'     => $state,
			'only'      => (string) $atts['only'],
		)
	);
}
add_shortcode( 'diluxone_users_accounts', 'diluxone_users_shortcode_accounts' );
