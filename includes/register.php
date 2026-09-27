<?php
/**
 * A registration form of its own.
 *
 * On most sites the plugin builds, signing in and signing up are the same
 * step: somebody types their address, gets a link, and the account appears if
 * it was not there. That is the best answer when it fits, and it is still the
 * default.
 *
 * It does not always fit. A site that asks for more than an address before
 * letting somebody in — a name, a country, whatever the fields say is
 * required — needs a form that asks for those things, and a page to put it
 * on. A site where somebody is approved before they get in needs a moment
 * between "I want an account" and "here is your link". Both of those need a
 * registration that is a step of its own, which is what this is.
 *
 * The address is still the identity and there is still no password: what
 * arrives after this form is the same sign-in link as always.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The page holding the registration form, or '' when there is none. */
function diluxone_users_register_url(): string {
	$page = (int) diluxone_users_option( 'diluxone_users_register_page' );

	return $page > 0 ? (string) get_permalink( $page ) : '';
}

/**
 * Is the registration form the way in on this site?
 *
 * Both halves have to be true: the site has to have chosen the form, and the
 * form has to be somewhere. A mode with no page is a door with no doorway,
 * and the admin says so rather than pretending.
 */
function diluxone_users_register_form_open(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_register_form' ) && '' !== diluxone_users_register_url();
}

/**
 * The fields the form asks for, beyond the address.
 *
 * Only the required ones, and only the ones a person can fill in: a
 * registration form that asks for everything is a registration form nobody
 * finishes. The rest is waiting for them in their account afterwards.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_register_fields(): array {
	$fields = array_filter(
		diluxone_users_fields( '', false ),
		static function ( array $field ): bool {
			return (bool) $field['active'] && (bool) $field['required'] && 'never' !== $field['edit'];
		}
	);

	/**
	 * Filters the fields the registration form asks for.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $fields
	 */
	return array_values( (array) apply_filters( 'diluxone_users_register_fields', $fields ) );
}

/**
 * Which of the answers the form asks for did not arrive.
 *
 * Every field on that form is required — that is the whole of what puts it
 * there — and the browser does enforce it, right up until it does not:
 * `novalidate` is one attribute away in the inspector, and a form posted by a
 * script never had a browser to enforce anything. So the answer that counts
 * is this one.
 *
 * It is asked before the account is created and not after it is saved, even
 * though `diluxone_users_save()` does report what it could not save. By then
 * the person exists: refusing would mean deleting an account seconds after
 * making it, and not refusing leaves one that can never satisfy the very
 * fields the site said it could not do without.
 *
 * @param array<string, mixed> $input The form, unslashed.
 * @return array<int, string> The labels of what is missing.
 */
function diluxone_users_register_missing( array $input ): array {
	$missing = array();

	foreach ( diluxone_users_register_fields() as $field ) {
		$raw = isset( $input[ $field['key'] ] ) && is_scalar( $input[ $field['key'] ] )
			? (string) $input[ $field['key'] ]
			: '';

		if ( '' === diluxone_users_sanitize( $field, $raw ) ) {
			$missing[] = (string) $field['label'];
		}
	}

	return $missing;
}

/** The whole registration form. Shortcode: [diluxone_users_register] */
function diluxone_users_shortcode_register(): string {
	if ( is_user_logged_in() ) {
		return '';
	}

	diluxone_users_enqueue_styles();

	ob_start();
	diluxone_users_login_frame_open();
	$frame = (string) ob_get_clean();

	ob_start();
	diluxone_users_login_frame_close();
	$close = (string) ob_get_clean();

	return $frame . diluxone_users_render(
		'register.php',
		array(
			'state'     => diluxone_users_state(),
			'email'     => '',
			'fields'    => diluxone_users_register_fields(),
			'open'      => 'closed' !== diluxone_users_register_mode(),
			'providers' => diluxone_users_sso_for_login(),
		)
	) . $close;
}
add_shortcode( 'diluxone_users_register', 'diluxone_users_shortcode_register' );

/**
 * How many accounts one machine may create in an hour.
 *
 * Not one: a household, an office or a school share an address, and two
 * people signing up minutes apart is ordinary. Six is high enough that a real
 * group never meets it and low enough that a script filling the users table
 * overnight does, on the first minute.
 */
function diluxone_users_register_burst(): int {
	/**
	 * Filters how many accounts one address may create per hour.
	 *
	 * @since 1.0.0
	 *
	 * @param int $burst
	 */
	return max( 1, (int) apply_filters( 'diluxone_users_register_burst', 6 ) );
}

/**
 * Is this machine allowed to create another account right now?
 *
 * Counted per IP and not per e-mail address, which was the first attempt and
 * stopped nothing at all: a script uses a different address every time, so a
 * key built from the address is a new key every time. The address it types is
 * the one thing it can change freely; where it is typing from is not.
 *
 * Counting is the side effect of asking, so there is one place that can
 * forget to count.
 */
function diluxone_users_register_allowed(): bool {
	return diluxone_users_ip_burst( 'register', diluxone_users_register_burst() );
}

/**
 * Handles the registration form.
 *
 * Unlike the sign-in form, this one does NOT answer the same thing whatever
 * happened: somebody filling in a registration form has to be told that the
 * address is already taken, or they will fill it in again. The sign-in form
 * stays silent because there the silence is what stops an address being
 * tested for existence; here the form is the place where an account is
 * created, so it has to say what happened.
 */
function diluxone_users_register_request(): void {
	$back = diluxone_users_register_url();
	$back = '' === $back ? home_url( '/' ) : $back;

	if ( ! isset( $_POST['diluxone_users_register_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_register_nonce'] ) ), 'diluxone_users_register' ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', $back ) );
		exit;
	}

	if ( 'closed' === diluxone_users_register_mode() ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'closed', $back ) );
		exit;
	}

	$email = sanitize_email( wp_unslash( $_POST['diluxone_users_email'] ?? '' ) );

	if ( '' === $email || ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'email', $back ) );
		exit;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; each field is sanitised by type inside.
	if ( array() !== diluxone_users_register_missing( diluxone_users_posted_fields() ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'missing', $back ) );
		exit;
	}

	if ( ! diluxone_users_register_allowed() ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'slow', $back ) );
		exit;
	}

	if ( email_exists( $email ) ) {
		// Not an error to hide: whoever is here wanted an account and already
		// has one, so the useful thing is the way in, not a shrug.
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'taken', $back ) );
		exit;
	}

	$user_id = diluxone_users_create_account( $email );

	if ( 0 === $user_id ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', $back ) );
		exit;
	}

	// The answers arrive with the form and are saved before the first sign-in:
	// asking again on the other side would be asking twice. What it reports as
	// missing was already refused above, before there was an account to save
	// it to.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; each field is sanitised by type.
	diluxone_users_save( $user_id, diluxone_users_posted_fields() );

	/**
	 * Fires once an account has been created from the registration form.
	 *
	 * This is where an add-on holds it for approval, sends a welcome, or
	 * counts it against a quota.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id
	 * @param string $email
	 */
	do_action( 'diluxone_users_registered', $user_id, $email );

	diluxone_users_login_send( $user_id, $email, diluxone_users_token_create( $user_id ) );

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'registered', $back ) );
	exit;
}
add_action( 'admin_post_nopriv_diluxone_users_registro', 'diluxone_users_register_request' );
add_action( 'admin_post_diluxone_users_registro', 'diluxone_users_register_request' );
