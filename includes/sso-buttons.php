<?php
/**
 * The network buttons: how they look and how they are drawn.
 *
 * The plugin assembles the markup — logo, text, classes — and the settings
 * choose the appearance: whoever administers should not have to write CSS to
 * stop the buttons being a bare link.
 *
 * The button stylesheet is enqueued whenever they are drawn, even with the
 * plugin styles turned off: the brand colour and the shape are not the
 * plugin's decoration, they are the option whoever administers has just
 * chosen. The site can override it; turning it off in silence would be
 * another matter.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The three possible finishes, with their name for the settings screen.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_button_skins(): array {
	return array(
		'brand' => __( 'Each brand’s colour', 'diluxone-users' ),
		'light' => __( 'White with a border', 'diluxone-users' ),
		'dark'  => __( 'Dark', 'diluxone-users' ),
	);
}

/**
 * The shapes a button can take.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_button_shapes(): array {
	return array(
		'rounded' => __( 'Rounded corners', 'diluxone-users' ),
		'pill'    => __( 'Pill', 'diluxone-users' ),
		'square'  => __( 'Square corners', 'diluxone-users' ),
	);
}

/**
 * What the button shows.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_button_contents(): array {
	return array(
		'icon-text' => __( 'Logo and text', 'diluxone-users' ),
		'icon'      => __( 'Logo only', 'diluxone-users' ),
	);
}

/**
 * How many per row.
 *
 * @return array<int, string>
 */
function diluxone_users_sso_button_columns(): array {
	return array(
		1 => __( 'One per row', 'diluxone-users' ),
		2 => __( 'Two per row', 'diluxone-users' ),
		0 => __( 'As many as fit', 'diluxone-users' ),
	);
}

/**
 * A button's text, using the template from the settings.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_sso_button_text( array $provider ): string {
	$template = trim( (string) diluxone_users_option( 'diluxone_users_sso_button_text' ) );

	if ( '' === $template ) {
		/* translators: %s: name of the social network */
		$template = __( 'Continue with %s', 'diluxone-users' );
	}

	/*
	 * Replaced and not `sprintf`ed. The box is free text somebody types, and
	 * `sprintf` reads every `%` in it as an instruction: "Continue with %s
	 * (50% off)" is a ValueError on PHP 8, thrown while drawing the public
	 * sign-in page. One placeholder is all this ever needed.
	 */
	return str_replace( '%s', (string) $provider['name'], $template );
}

/** The container classes, according to the settings. */
function diluxone_users_sso_buttons_class(): string {
	$columns = (int) diluxone_users_option( 'diluxone_users_sso_button_columns' );

	return sprintf(
		'diluxone-users-socials diluxone-users-socials--%1$s diluxone-users-socials--%2$s diluxone-users-socials--%3$s diluxone-users-socials--cols-%4$d',
		sanitize_html_class( (string) diluxone_users_option( 'diluxone_users_sso_button_skin' ) ),
		sanitize_html_class( (string) diluxone_users_option( 'diluxone_users_sso_button_shape' ) ),
		sanitize_html_class( (string) diluxone_users_option( 'diluxone_users_sso_button_show' ) ),
		in_array( $columns, array( 0, 1, 2 ), true ) ? $columns : 2
	);
}

/**
 * One button.
 *
 * With "logo only" the name stays in the markup, hidden from sight and
 * available to a screen reader: a button with no accessible name is a link
 * that cannot be read.
 *
 * @param string               $id       Provider identifier.
 * @param array<string, mixed> $provider Its row of the table.
 * @param string               $url      Where it goes. Empty for the preview.
 */
function diluxone_users_sso_button( string $id, array $provider, string $url = '' ): string {
	$text = diluxone_users_sso_button_text( $provider );
	$icon = diluxone_users_sso_icon( $id );

	return sprintf(
		'<a class="diluxone-users-social diluxone-users-social--%1$s" style="--diluxone-users-brand: %2$s" href="%3$s"%4$s>%5$s<span class="diluxone-users-social__text">%6$s</span></a>',
		esc_attr( $id ),
		// A colour and not "whatever is in that key": it is printed inside a
		// `style` attribute, where `esc_attr` stops the attribute being broken
		// out of and says nothing about the declaration itself. The table is
		// this plugin's, but `diluxone_users_sso_providers` is anybody's.
		esc_attr( (string) sanitize_hex_color( (string) $provider['color'] ) ),
		'' === $url ? '#' : esc_url( $url ),
		'' === $url ? ' tabindex="-1" aria-hidden="true"' : '',
		$icon,
		esc_html( $text )
	);
}

/**
 * Every button there is to show.
 *
 * @param array<string, array<string, mixed>>|null $providers For the admin
 *        preview; when not passed, the ones that are turned on.
 * @param bool                                     $live      Whether the links really sign in.
 */
function diluxone_users_sso_buttons( ?array $providers = null, bool $live = true ): string {
	$providers = null === $providers ? diluxone_users_sso_available() : $providers;

	if ( array() === $providers ) {
		return '';
	}

	diluxone_users_sso_enqueue_button_styles();

	$html = '';

	foreach ( $providers as $id => $provider ) {
		$html .= diluxone_users_sso_button( $id, $provider, $live ? diluxone_users_sso_login_url( $id ) : '' );
	}

	return sprintf( '<div class="%1$s">%2$s</div>', esc_attr( diluxone_users_sso_buttons_class() ), $html );
}

/** The button stylesheet. Enqueued once, and late: it can be drawn from a shortcode. */
function diluxone_users_sso_enqueue_button_styles(): void {
	if ( wp_style_is( 'diluxone-users-social', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_style( 'diluxone-users-social', DILUXONE_USERS_URL . 'assets/diluxone-users-social.css', array(), diluxone_users_asset_version( 'assets/diluxone-users-social.css' ) );
}
