<?php
/**
 * The "e-mail only" mode: closing the password door.
 *
 * How people get in is the site's choice, and there are three possible
 * answers: the e-mail link only, username and password only, or both. This
 * file deals with nothing but the first, which is the only one that needs to
 * close anything: designing a screen without a password is not enough,
 * because `/wp-login.php` is still open with its form and with the native
 * registration. With either of the other two, nothing happens here.
 *
 * One deliberate escape hatch remains:
 * `/wp-login.php?diluxone-users-admin=1` shows the native form. It is no
 * secret — the password still provides the security — but it avoids being
 * locked out of the site if the mail or the social provider fails.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The wp-login.php actions that HAVE to keep working.
 *
 * `logout` and `postpass` are ordinary flows; `rp` / `resetpass` are the end
 * of a reset that only an admin can start from the dashboard; and
 * `confirmaction` is the one confirming the GDPR personal-data requests,
 * which WordPress sends by e-mail and which has no other URL.
 */
const DILUXONE_USERS_LOGIN_ALLOWED = array( 'logout', 'postpass', 'rp', 'resetpass', 'confirmaction' );

/**
 * Sends the "choose a new password" link to the site's own page.
 *
 * WordPress builds that address itself and offers no clean filter for it, so
 * the link still points at wp-login.php — and this catches it there and hands
 * it on, arguments and all. One screen fewer that looks like somebody else's
 * site at the moment somebody is worried about their account.
 *
 * Only when the site has a page to hand it to, and only when the site has
 * said it wants its own screens: left alone means left alone.
 */
function diluxone_users_reset_to_site(): void {
	if ( diluxone_users_safe_mode() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the key is the credential and is checked on arrival.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	if ( ! in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
		return;
	}

	// It used to ride on "what happens to wp-login.php", which is a different
	// question: somebody could have every screen taken over and still be sent
	// to WordPress's box to type a new password, or the other way round, and
	// neither was asked anywhere. Now the screen that asks about forgetting a
	// password is the one that decides where the new one is typed.
	if ( isset( $_GET['diluxone-users-admin'] ) || 'site' !== (string) diluxone_users_option( 'diluxone_users_lost_password' ) ) {
		return;
	}

	$page = diluxone_users_login_page_live() ? diluxone_users_page_url( 'diluxone_users_login_page' ) : '';

	if ( '' === $page || ! isset( $_GET['key'], $_GET['login'] ) || ! is_string( $_GET['key'] ) || ! is_string( $_GET['login'] ) ) {
		return;
	}

	$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
	$login = sanitize_user( wp_unslash( $_GET['login'] ) );
	// phpcs:enable

	wp_safe_redirect(
		add_query_arg(
			array(
				'diluxone_users_key'   => rawurlencode( $key ),
				'diluxone_users_login' => rawurlencode( $login ),
			),
			$page
		)
	);
	exit;
}
add_action( 'login_init', 'diluxone_users_reset_to_site', 5 );

/**
 * Does this request have to be taken out of wp-login.php?
 *
 * A pure function on purpose: it decides from the action and the query alone,
 * touching neither globals nor the database, so it can be tested without
 * booting WordPress.
 *
 * @param string               $action Value of `action` (empty string = login).
 * @param array<string, mixed> $query  Equivalent to $_GET.
 */
function diluxone_users_should_redirect( string $action, array $query = array() ): bool {
	// Escape hatch for administrators.
	if ( isset( $query['diluxone-users-admin'] ) ) {
		return false;
	}

	// The interstitial login is the modal appearing inside the dashboard when
	// the session expires. Taking it out of there would break the screen that
	// opened it.
	if ( isset( $query['interim-login'] ) ) {
		return false;
	}

	$action = '' === $action ? 'login' : $action;

	if ( in_array( $action, DILUXONE_USERS_LOGIN_ALLOWED, true ) ) {
		return false;
	}

	/*
	 * Asking for a reset is not the same screen as choosing the new password.
	 * WordPress's form is where it is asked for; where it is chosen is the
	 * question on the Access screen. So unless this site answers "nobody
	 * resets anything, the link is the way back in", the asking form is left
	 * reachable — taking it over would send somebody who wants a new password
	 * to a form that sends them a link instead, which is not what they asked.
	 */
	if ( 'lostpassword' === $action && 'link' !== (string) diluxone_users_option( 'diluxone_users_lost_password' ) ) {
		return false;
	}

	// login, register, lostpassword and any unknown action go to the site
	// sign-in page.
	return true;
}

/**
 * Does a POST to wp-login.php get through while its screens are taken over?
 *
 * Taking the screens over means nobody SEES wp-login.php; it never meant that
 * nothing may be SENT to it. The password form the site's own page draws is
 * WordPress's, and it posts to wp-login.php with no action — so on a site
 * where the password is a way in, that POST is the site's own sign-in and
 * goes through. Every other POST is to a form the site closed, and is stopped.
 *
 * A pure function for the same reason as diluxone_users_should_redirect():
 * the two of them together are the whole verdict, and a table of cases pins
 * them down without WordPress.
 *
 * @param string $action       Value of `action` (empty string = login).
 * @param bool   $has_password Whether the site lets people in with a password.
 */
function diluxone_users_wp_login_post_allowed( string $action, bool $has_password ): bool {
	return in_array( $action, array( '', 'login' ), true ) && $has_password;
}

/**
 * Does the site take over WordPress's own screens?
 *
 * Three answers. 'auto' is what the plugin always did: take them over only
 * while "only a link" is the way in, because then the native form cannot work
 * anyway. 'mine' takes them over whatever else is true — a site with its own
 * sign-in page usually wants one door, not two. 'wp' leaves them alone, and
 * the admin says plainly that two doors will be open.
 */
function diluxone_users_wp_screens(): string {
	$mode = (string) diluxone_users_option( 'diluxone_users_wp_screens' );

	return in_array( $mode, array( 'auto', 'mine', 'wp' ), true ) ? $mode : 'auto';
}

/**
 * Are WordPress's own sign-in screens being taken over right now?
 *
 * With the e-mail link as the only way in, always, whatever is stored. 'wp'
 * means "leave the second door open", and on a site that closed the password
 * that door is the one place a password would still open: the native form
 * posts a username and a password and WordPress signs them in, while every
 * screen of this plugin says a password opens nothing here. The stored answer
 * is kept — it is what applies the day a password is a way in again.
 */
function diluxone_users_wp_screens_taken(): bool {
	if ( diluxone_users_login_only_link() ) {
		return true;
	}

	return 'mine' === diluxone_users_wp_screens();
}

/** Sends wp-login.php to the sign-in page. */
function diluxone_users_block_wp_login(): void {
	// The emergency switch gives wp-login.php back to WordPress (safe-mode.php).
	if ( diluxone_users_safe_mode() || ! diluxone_users_wp_screens_taken() ) {
		return;
	}

	// With no other door, wp-login.php is the only one there is: closing it
	// would leave the site with no way in. It is compared against the resolved
	// URL and not against the setting, because a site can supply it through a
	// filter instead of an option.
	if ( diluxone_users_login_url() === wp_login_url() ) {
		return;
	}

	/*
	 * The escape hatch has to survive the form being submitted. The argument
	 * arrives in the query the first time, and WordPress's own form posts to
	 * wp-login.php with nothing after the question mark — so an administrator
	 * who opened the hatch, saw the form and pressed the button was stopped by
	 * the next line with no way to explain themselves. It is carried through
	 * as a hidden field, and read from both places. The action is read the
	 * same way, because that is how wp-login.php itself reads it.
	 */
	$request = array();

	// Three names, and they are the three `diluxone_users_should_redirect()`
	// reads: the hatch, WordPress's interim login, and the action. Merging the
	// two superglobals whole was a way of saying "whatever arrives", when what
	// arrives is only ever asked three questions.
	foreach ( array( 'diluxone-users-admin', 'interim-login', 'action' ) as $name ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- reading which screen wp-login.php was asked for, before anything is done with it.
		if ( isset( $_POST[ $name ] ) ) {
			$request[ $name ] = sanitize_key( wp_unslash( $_POST[ $name ] ) );
			continue;
		}

		if ( isset( $_GET[ $name ] ) ) {
			$request[ $name ] = sanitize_key( wp_unslash( $_GET[ $name ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	}

	$action = (string) ( $request['action'] ?? '' );

	if ( ! diluxone_users_should_redirect( $action, $request ) ) {
		return;
	}

	if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
		// The site's own page draws WordPress's password form, and that form
		// posts here: the screen is taken over, the endpoint is not.
		if ( diluxone_users_wp_login_post_allowed( $action, diluxone_users_login_has_password() ) ) {
			return;
		}

		// A POST to a form the site closed: do not redirect in silence, stop.
		wp_die(
			esc_html__( 'This site signs you in without a password: with your email or with a social account.', 'diluxone-users' ),
			esc_html__( 'Sign in', 'diluxone-users' ),
			array( 'response' => 403 )
		);
	}

	// Somebody asking to register goes to the registration page when the site
	// has one. Sending them to the sign-in form instead is answering a
	// different question from the one they asked.
	if ( 'register' === $action && function_exists( 'diluxone_users_register_form_open' ) && diluxone_users_register_form_open() ) {
		wp_safe_redirect( diluxone_users_register_url() );
		exit;
	}

	wp_safe_redirect( diluxone_users_login_url() );
	exit;
}
add_action( 'login_init', 'diluxone_users_block_wp_login' );

/**
 * WordPress's own registration form stays closed while the e-mail link is the
 * only way in.
 *
 * That form creates accounts with a password, and on a site where a password
 * opens nothing it would be handing out keys to a door the site bricked up.
 * So `users_can_register` reads as off for as long as that lasts — and only
 * for that. There used to be a three-way option of the plugin's own here
 * (respect the site / force open / force closed) that filtered the same
 * setting, and it made Settings → General show one thing and save another
 * without a word. Now there is one switch, WordPress's, shown in two places,
 * and this one rule on top of it, said out loud in both.
 *
 * @param mixed $value What came from the option.
 * @return mixed
 */
function diluxone_users_block_registration( $value ) {
	return diluxone_users_login_only_link() ? 0 : $value;
}
add_filter( 'option_users_can_register', 'diluxone_users_block_registration' );

/** Is WordPress's registration form locked shut by the plugin right now? */
function diluxone_users_wp_registration_locked(): bool {
	return diluxone_users_login_only_link();
}

/**
 * Says so next to WordPress's own checkbox.
 *
 * Settings → General → "Anyone can register" is the same switch the plugin
 * shows on the Access screen. While it is locked, ticking it there saves and
 * changes nothing, which is the kind of thing a person has to be told where
 * they are standing — not on another screen.
 */
function diluxone_users_wp_registration_notice(): void {
	if ( ! diluxone_users_wp_registration_locked() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
		esc_html__( '“Anyone can register” is off and locked: on this site the e-mail link is the only way in, and that form would create accounts with a password. The same switch, and the reason, are on the Access screen.', 'diluxone-users' ),
		esc_url( diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'register' ) ) ),
		esc_html__( 'Open it →', 'diluxone-users' )
	);
}
/** Only on that one screen: the notice has nothing to say anywhere else. */
function diluxone_users_wp_registration_notice_hook(): void {
	add_action( 'admin_notices', 'diluxone_users_wp_registration_notice' );
}
add_action( 'load-options-general.php', 'diluxone_users_wp_registration_notice_hook' );

/* ── The dashboard profile ─────────────────────────────────────────── */

/**
 * What happens when somebody opens `wp-admin/profile.php`.
 *
 * A site that built its account area on the front end does not want half the
 * data edited on another screen, with another look and other rules — the edit
 * limits and required fields configured here do not apply there. People can
 * be sent to the account area, or have the door closed.
 *
 * Whoever administers is always left out of this: they are the person who has
 * to be able to fix what broke, and the dashboard profile is where it gets
 * fixed.
 */
function diluxone_users_wp_profile_guard(): void {
	$mode = (string) diluxone_users_option( 'diluxone_users_wp_profile' );

	// Whoever can edit users is never reached by this, whatever is configured:
	// they are the person who has to be able to fix what broke, and the
	// dashboard profile is where it gets fixed. On a network that is the super
	// admin; on a single site, the administrator.
	if ( 'allow' === $mode || current_user_can( 'edit_users' ) || diluxone_users_safe_mode() ) {
		return;
	}

	if ( ! diluxone_users_scope_includes( get_current_user_id(), 'diluxone_users_wp_profile' ) ) {
		return;
	}

	$screen = basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ) );

	if ( 'profile.php' !== $screen ) {
		return;
	}

	if ( 'redirect' === $mode ) {
		$target = diluxone_users_account_url( 'details' );

		wp_safe_redirect( '' !== $target ? $target : home_url( '/' ) );
		exit;
	}

	wp_die(
		esc_html__( 'Your details are edited from your account on the site, not from here.', 'diluxone-users' ),
		esc_html__( 'Not from here', 'diluxone-users' ),
		array(
			'response'  => 403,
			'back_link' => true,
		)
	);
}
add_action( 'admin_init', 'diluxone_users_wp_profile_guard' );

/**
 * Carries the escape hatch into the form WordPress draws.
 *
 * Without it the argument is lost the moment the button is pressed, and the
 * person it exists for — the administrator of a site with no other way in —
 * is the one it locks out.
 */
function diluxone_users_login_hatch_field(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only decides whether a hidden field is printed.
	if ( ! isset( $_GET['diluxone-users-admin'] ) ) {
		return;
	}

	echo '<input type="hidden" name="diluxone-users-admin" value="1">';
}
add_action( 'login_form', 'diluxone_users_login_hatch_field' );
