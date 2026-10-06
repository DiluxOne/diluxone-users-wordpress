<?php
/**
 * Each network's logo, in SVG.
 *
 * They go inline and not as files: they are a handful of drawings of under a
 * kilobyte each, and that way they inherit the size and colour of the button
 * with no extra request and no sprite to maintain.
 *
 * Each mark belongs to its owner. They are used for the one thing their
 * guidelines allow without asking permission: identifying the button you sign
 * in to that service with. That is why the ones with a brand colour keep it —
 * the Google logo is not painted another colour — and the ones that are a
 * silhouette use `currentColor`, which is how those same guidelines admit
 * them over coloured backgrounds. A provider an add-on registers brings its
 * own mark through `diluxone_users_sso_icon_paths`; anything with no mark of
 * its own falls back to the plain globe below, so a button is never a blank
 * square.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Does this network's logo have a colour of its own?
 *
 * The coloured ones are never recoloured: they are drawn the same over a
 * white button as over a dark one. The silhouettes take the colour of the
 * button text.
 */
function diluxone_users_sso_icon_is_colored( string $id ): bool {
	return in_array( $id, array( 'google', 'microsoft' ), true );
}

/**
 * A network's SVG, ready to print.
 *
 * @param string $id Provider identifier.
 * @return string SVG, or an empty string when that network has no logo.
 */
function diluxone_users_sso_icon( string $id ): string {
	$paths = diluxone_users_sso_icon_paths();

	// The drawings can come from a filter, so they are someone else's markup
	// by the time they get here: only paths, groups and their geometry pass.
	$path = wp_kses( (string) ( $paths[ $id ] ?? $paths['fallback'] ), diluxone_users_ui_svg_tags() );

	return sprintf(
		'<svg class="diluxone-users-social__logo" width="20" height="20" viewBox="0 0 24 24" fill="%1$s" aria-hidden="true" focusable="false">%2$s</svg>',
		diluxone_users_sso_icon_is_colored( $id ) ? 'none' : 'currentColor',
		$path
	);
}

/**
 * Each brand's drawing.
 *
 * @return array<string, string>
 */
function diluxone_users_sso_icon_paths(): array {
	$paths = array(

		// The plain globe, for a network that has no mark here — because it
		// was left out, or because an add-on registered a provider of its own.
		'fallback'  => '<path d="M12 1.2a10.8 10.8 0 1 0 0 21.6 10.8 10.8 0 0 0 0-21.6m0 1.4c1.3 0 2.7 1.7 3.4 4.5a20 20 0 0 1-6.8 0c.7-2.8 2.1-4.5 3.4-4.5M8.3 3.3c-.6 1-1.1 2.3-1.4 3.7a15 15 0 0 1-2.4-.8 9.5 9.5 0 0 1 3.8-2.9m7.4 0a9.5 9.5 0 0 1 3.8 2.9 15 15 0 0 1-2.4.8c-.3-1.4-.8-2.7-1.4-3.7M3.6 7.4c.9.4 1.9.7 3 .9A25 25 0 0 0 6.4 12H2.7c.1-1.7.4-3.2.9-4.6m16.8 0c.5 1.4.8 2.9.9 4.6h-3.7a25 25 0 0 0-.2-3.7c1.1-.2 2.1-.5 3-.9M7.8 8.6a22 22 0 0 0 8.4 0c.1 1.1.2 2.2.2 3.4H7.6c0-1.2.1-2.3.2-3.4M2.7 13.4h3.7c0 1.3.1 2.5.2 3.7-1.1.2-2.1.5-3 .9-.5-1.4-.8-2.9-.9-4.6m5.1 0h8.4c0 1.2-.1 2.3-.2 3.4a22 22 0 0 0-8 0c-.1-1.1-.2-2.2-.2-3.4m9.8 0h3.7c-.1 1.7-.4 3.2-.9 4.6-.9-.4-1.9-.7-3-.9.1-1.2.2-2.4.2-3.7M12 18.3c1.2 0 2.3.1 3.3.3-.7 2.5-2 4-3.3 4s-2.6-1.5-3.3-4c1-.2 2.1-.3 3.3-.3m-5.4.7c.3 1.3.8 2.5 1.3 3.4a9.5 9.5 0 0 1-3.6-2.7c.7-.3 1.5-.5 2.3-.7m10.8 0c.8.2 1.6.4 2.3.7a9.5 9.5 0 0 1-3.6 2.7c.5-.9 1-2.1 1.3-3.4"/>',

		'google'    => '<path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47a5.53 5.53 0 0 1-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>'
			. '<path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09A12 12 0 0 0 12 24z"/>'
			. '<path fill="#FBBC05" d="M5.27 14.29A7.2 7.2 0 0 1 4.89 12c0-.8.14-1.57.38-2.29V6.62H1.29A12 12 0 0 0 0 12c0 1.94.46 3.77 1.29 5.38l3.98-3.09z"/>'
			. '<path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.7 0 3.99 2.47 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/>',

		'microsoft' => '<path fill="#F25022" d="M2 2h9.4v9.4H2z"/>'
			. '<path fill="#7FBA00" d="M12.6 2H22v9.4h-9.4z"/>'
			. '<path fill="#00A4EF" d="M2 12.6h9.4V22H2z"/>'
			. '<path fill="#FFB900" d="M12.6 12.6H22V22h-9.4z"/>',
	);

	/**
	 * Filters the network logos.
	 *
	 * @param array<string, string> $paths
	 */
	return apply_filters( 'diluxone_users_sso_icon_paths', $paths );
}
