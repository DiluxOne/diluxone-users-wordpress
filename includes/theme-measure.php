<?php
/**
 * The theme's colours, measured where the palette cannot say them.
 *
 * Reading a palette by name works for themes that call their colours primary,
 * contrast, base. Many do not — some number them, classic themes publish no
 * palette at all — and for those the only honest source of "the theme's
 * colours" is what the theme actually paints. So the visitor's browser asks
 * it: the colour of a link and the ground of a button in the content area,
 * the text and the ground of the page, the edge of a field. Those answers
 * become the plugin's properties on <html>, where they win over its own
 * defaults, and are kept in the browser so the next page starts with them.
 *
 * No theme is named here: the measurement is the same for every theme.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The key the measured colours are kept under: it changes with the theme. */
function diluxone_users_measure_key(): string {
	$theme = wp_get_theme();

	return 'diluxone-users-theme-colors:' . $theme->get_stylesheet() . ':' . $theme->get( 'Version' ) . ':' . DILUXONE_USERS_VERSION;
}

/**
 * Before anything is painted: the colours measured on an earlier page, if
 * the browser kept them. Without this the plugin's own blue would show for a
 * moment on every page until the measurement below replaced it.
 *
 * Inline and in the head, on purpose: a file would be one more request in
 * front of the first paint, which is the moment this exists for. It is a
 * script of its own in the queue all the same, so it prints where WordPress
 * prints scripts and a site's content-security policy can reach it.
 */
function diluxone_users_measure_early(): void {
	if ( array() === diluxone_users_color_measured_roles() || is_admin() ) {
		return;
	}

	wp_register_script( 'diluxone-users-theme-colors-early', false, array(), DILUXONE_USERS_VERSION, false );
	wp_enqueue_script( 'diluxone-users-theme-colors-early' );
	wp_add_inline_script(
		'diluxone-users-theme-colors-early',
		'(function(k){try{var v=JSON.parse(localStorage.getItem(k)||"null");if(!v)return;var s=document.documentElement.style;for(var r in v){s.setProperty("--diluxone-users-"+r,v[r]);}}catch(e){}})(' . wp_json_encode( diluxone_users_measure_key() ) . ');'
	);
}
add_action( 'wp_enqueue_scripts', 'diluxone_users_measure_early', 1 );

/**
 * After the page is drawn: measure, apply, keep. Only on pages that carry the
 * plugin's own stylesheet — anywhere else there is nothing of it to paint —
 * which is only known once the page is drawn, so it is queued from the
 * footer, before WordPress prints the footer's scripts.
 */
function diluxone_users_measure(): void {
	$roles = diluxone_users_color_measured_roles();
	if ( array() === $roles || is_admin() || ! ( wp_style_is( 'diluxone-users', 'done' ) || wp_style_is( 'diluxone-users', 'enqueued' ) ) ) {
		return;
	}

	wp_enqueue_script( 'diluxone-users-theme-colors', DILUXONE_USERS_URL . 'assets/diluxone-users-theme-measure.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-theme-measure.js' ), true );
	wp_localize_script(
		'diluxone-users-theme-colors',
		'diluxOneUsersThemeColors',
		array(
			'roles' => array_values( $roles ),
			'key'   => diluxone_users_measure_key(),
		)
	);
}
add_action( 'wp_footer', 'diluxone_users_measure', 5 );
