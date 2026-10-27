<?php
/**
 * The required fields an account came in without, asked for once it is in.
 *
 * An account made by the e-mail link or by a social network was never shown
 * the registration form, so it has nothing in the fields the site marked as
 * required — the name a certificate needs, say. Nothing asked for them
 * afterwards either. Asking is Users+ Pro's (`diluxone_users_ask_missing`,
 * false here); when it asks, at the moment a sign-in hands the person their
 * destination, the destination is wrapped: they go first to a short step on
 * the account page that asks for those fields and nothing else, and from it on
 * to where they were going.
 *
 * Nothing is stored about the person: the step is decided again at every
 * sign-in, so leaving it without saving shows it again the next time, and no
 * other request is held up — it is a page, not a gate. It is asked at the
 * three places a session is opened and the person sent on: the end of
 * diluxone_users_complete_login() (the link, a social network, an add-on's
 * door), the end of the second step, and WordPress's `login_redirect` for a
 * password with no second step.
 *
 * The step is the account area's own form — the same template, controls,
 * checks and save as Details — drawn by the account area in place of its
 * sections. With no account page chosen there is nowhere to draw it, and it
 * is not asked.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The query argument that turns the account page into the step. */
const DILUXONE_USERS_COMPLETE_VAR = 'diluxone-users-complete';

/**
 * The active, required fields this person can fill in and has not.
 *
 * A field they may not edit, or whose key is not one a field may write, is
 * left out: asking for what the save would refuse is a form that never ends.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_missing_required( int $user_id ): array {
	return array_values(
		array_filter(
			diluxone_users_fields(),
			static fn( array $field ): bool => ! empty( $field['required'] )
				&& diluxone_users_field_key_allowed( (string) $field['key'] )
				&& diluxone_users_field_editable( $field, $user_id )
				&& '' === trim( diluxone_users_value( $user_id, (string) $field['key'] ) )
		)
	);
}

/** Does the site mark any active field as required? */
function diluxone_users_has_required_fields(): bool {
	foreach ( diluxone_users_fields() as $field ) {
		if ( ! empty( $field['required'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Is the step asked for at all?
 *
 * Not, as the plugin has it: a sign-in sends somebody straight to where they
 * were going. Asking is Users+ Pro's, through `diluxone_users_ask_missing`,
 * and even then only while a field is required and an account page is
 * chosen, which is where the step is drawn. In safe mode nothing of the
 * plugin's redirects, so neither does this.
 */
function diluxone_users_ask_missing_on(): bool {
	/**
	 * Filters whether a sign-in asks for the required fields an account has
	 * no answer for.
	 *
	 * False, as the plugin has it: nobody is asked.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $ask Whether to ask.
	 */
	return ! diluxone_users_safe_mode()
		&& (bool) apply_filters( 'diluxone_users_ask_missing', false )
		&& '' !== diluxone_users_page_url( 'diluxone_users_account_page' )
		&& diluxone_users_has_required_fields();
}

/**
 * Where a sign-in sends this person: the step first, when it is owed.
 *
 * @param int    $user_id  Who just signed in.
 * @param string $redirect Where they were going.
 * @return string The step, carrying where they were going, or `$redirect`.
 */
function diluxone_users_ask_missing_url( int $user_id, string $redirect ): string {
	if ( $user_id <= 0 || ! diluxone_users_ask_missing_on() || array() === diluxone_users_missing_required( $user_id ) ) {
		return $redirect;
	}

	return diluxone_users_ask_missing_step( '' !== $redirect ? $redirect : home_url( '/' ) );
}

/**
 * The step's own address on the account page, carrying where it goes on to.
 *
 * @param string $to Where the person goes once the step is done or skipped.
 */
function diluxone_users_ask_missing_step( string $to ): string {
	return add_query_arg(
		array(
			DILUXONE_USERS_COMPLETE_VAR => '1',
			'redirect_to'               => rawurlencode( $to ),
		),
		diluxone_users_page_url( 'diluxone_users_account_page' )
	);
}

/**
 * A password typed on wp-login.php, with no second step: the same.
 *
 * Late, after everything else that decides where a password sign-in lands
 * (the way back to a site of the network, its mark at 20), so the step
 * carries the destination they settled on. A password that meets the second
 * step never gets here — it is asked when that step is finished.
 *
 * @param string           $redirect  Where WordPress will send them.
 * @param string           $requested Where they asked to go.
 * @param WP_User|WP_Error $user      Who signed in, or why not.
 */
function diluxone_users_ask_missing_password( $redirect, $requested = '', $user = null ): string {
	return $user instanceof WP_User ? diluxone_users_ask_missing_url( (int) $user->ID, (string) $redirect ) : (string) $redirect;
}
add_filter( 'login_redirect', 'diluxone_users_ask_missing_password', 99, 3 );

/**
 * Where the step goes on to, checked: one of the site's own addresses (or the
 * network's), and home when it is anything else.
 */
function diluxone_users_ask_missing_destination( string $url ): string {
	return '' !== $url ? wp_validate_redirect( $url, home_url( '/' ) ) : home_url( '/' );
}

/** Is the account page being asked for the step? */
function diluxone_users_ask_missing_asked(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks what to draw for the person signed in.
	return isset( $_GET[ DILUXONE_USERS_COMPLETE_VAR ] );
}

/**
 * Draws the step, when it is asked for and something is still missing.
 *
 * @return bool Whether it drew it; the account area draws its sections when not.
 */
function diluxone_users_ask_missing_form(): bool {
	if ( ! is_user_logged_in() || ! diluxone_users_ask_missing_asked() || ! diluxone_users_ask_missing_on() ) {
		return false;
	}

	$user_id = get_current_user_id();
	$fields  = diluxone_users_missing_required( $user_id );

	if ( array() === $fields ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an address to go on to, checked against the site's own hosts before it is used.
	$asked = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
	$to    = diluxone_users_ask_missing_destination( $asked );

	diluxone_users_enqueue_styles();

	// In the account area's own frame, so it is as wide as the page it stands
	// in for, follows the "how wide" setting and takes a shape's stylesheet.
	// It draws no menu, hence the layout 'none', and no header.
	$template = diluxone_users_account_template();
	$classes  = diluxone_users_account_classes( $template, 'none', diluxone_users_account_width(), false );
	$style    = diluxone_users_account_style( $template );

	echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . ( '' === $style ? '' : ' style="' . esc_attr( $style ) . '"' ) . '>';
	echo '<div class="diluxone-users-account__body">';
	echo '<div class="diluxone-users diluxone-users-account__section diluxone-users-complete">';

	// The title first and the line under it; the template is asked for no
	// title of its own, so a theme's copy of it keeps working unchanged.
	echo '<h2 class="diluxone-users-fields__title">' . esc_html__( 'Complete your details', 'diluxone-users' ) . '</h2>';
	echo '<p class="diluxone-users-complete__intro">' . esc_html__( 'Before you go on, the site needs a few details it does not have yet.', 'diluxone-users' ) . '</p>';

	diluxone_users_template_part(
		'fields.php',
		array(
			'user_id'  => $user_id,
			'fields'   => $fields,
			'group'    => '',
			'title'    => '',
			'state'    => diluxone_users_state(),
			'redirect' => $to,
			'skip'     => $to,
		)
	);

	echo '</div></div></div>';

	return true;
}
