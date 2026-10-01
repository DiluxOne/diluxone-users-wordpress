<?php
/**
 * Each network's logo, in SVG.
 *
 * They go inline and not as files: they are twelve drawings of under a
 * kilobyte each, and that way they inherit the size and colour of the button
 * with no extra request and no sprite to maintain.
 *
 * Each mark belongs to its owner. They are used for the one thing their
 * guidelines allow without asking permission: identifying the button you sign
 * in to that service with. That is why the ones with a brand colour keep it —
 * the Google logo is not painted another colour — and the ones that are a
 * silhouette use `currentColor`, which is how those same guidelines admit
 * them over coloured backgrounds. The GitLab drawing is Simple Icons' path
 * (https://simpleicons.org/, CC0 1.0).
 *
 * WordPress.com is the one that has no mark here, and on purpose. The "W" is
 * a WordPress Foundation trademark, its policy does not read as an invitation
 * the way the others' do, and the people who review a plugin for the
 * directory are that foundation's own volunteers. A button that identifies
 * itself in words costs nothing; an argument about a logo costs a review
 * round. Anything with no mark of its own falls back to the plain globe
 * below, so a button is never a blank square.
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

		'linkedin'  => '<path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5M2.5 9.5h5V21h-5zM10 9.5h4.8v1.6h.1c.7-1.2 2.3-2.1 4.1-2.1 4.4 0 5 2.6 5 6.1V21h-5v-5.1c0-1.5 0-3.5-2.1-3.5s-2.4 1.7-2.4 3.4V21h-5z"/>',

		'twitter'   => '<path d="M18.9 1.2h3.7l-8.1 9.2 9.5 12.4h-7.4l-5.8-7.6-6.7 7.6H.4l8.6-9.9L0 1.2h7.6l5.2 6.9zm-1.3 19.4h2L6.5 3.3H4.3z"/>',

		'facebook'  => '<path d="M23 12a11 11 0 1 0-12.7 10.9v-7.7H7.5V12h2.8V9.6c0-2.8 1.6-4.3 4.2-4.3 1.2 0 2.5.2 2.5.2v2.7h-1.4c-1.4 0-1.8.9-1.8 1.7V12h3.1l-.5 3.2h-2.6v7.7A11 11 0 0 0 23 12"/>',

		'github'    => '<path d="M12 .3a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.2 4.9 18.2 5.2 18.2 5.2c.6 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .3"/>',

		'yahoo'     => '<path d="M1 5.4h4.6l2.7 6.9 2.7-6.9h4.5L9.7 21.7H5.1l1.9-4.4zm17.6 8.7c1.3 0 2.4 1.1 2.4 2.4s-1.1 2.4-2.4 2.4-2.4-1.1-2.4-2.4 1.1-2.4 2.4-2.4M17.1 2.3H22l-4.4 10.2h-3.4z"/>',

		'twitch'    => '<path d="M4.3 0 1.7 4.7v16.5h5.6V24h3l2.8-2.8h4.5L23.3 16V0zm16.7 15-3.2 3.2h-4.9l-2.8 2.8v-2.8H6.4V1.9H21zM17.5 6v5.8h-1.9V6zm-5.2 0v5.8h-1.9V6z"/>',

		'discord'   => '<path d="M19.3 5.3A16.9 16.9 0 0 0 15.1 4l-.2.4a15.7 15.7 0 0 1 3.7 1.2A12.6 12.6 0 0 0 12 4.4a12.7 12.7 0 0 0-6.6 1.2 15.6 15.6 0 0 1 3.7-1.2L8.9 4a16.9 16.9 0 0 0-4.2 1.3C2 9.2 1.3 13 1.6 16.7A17 17 0 0 0 6.8 19l.9-1.3a11 11 0 0 1-1.7-.8l.4-.3a12.1 12.1 0 0 0 11.2 0l.4.3a11 11 0 0 1-1.7.8l.9 1.3a17 17 0 0 0 5.2-2.3c.4-4.3-.7-8-3.1-11.4M8.5 14.5c-1 0-1.9-.9-1.9-2.1s.8-2.1 1.9-2.1 1.9 1 1.9 2.1-.8 2.1-1.9 2.1m6.9 0c-1 0-1.9-.9-1.9-2.1s.8-2.1 1.9-2.1 1.9 1 1.9 2.1-.8 2.1-1.9 2.1"/>',

		'gitlab'    => '<path d="m23.6004 9.5927-.0337-.0862L20.3.9814a.851.851 0 0 0-.3362-.405.8748.8748 0 0 0-.9997.0539.8748.8748 0 0 0-.29.4399l-2.2055 6.748H7.5375l-2.2057-6.748a.8573.8573 0 0 0-.29-.4412.8748.8748 0 0 0-.9997-.0537.8585.8585 0 0 0-.3361.405L.4332 9.5015l-.0325.0862a6.0657 6.0657 0 0 0 2.0119 7.0105l.0113.0087.03.0213 4.976 3.7264 2.462 1.8633 1.4995 1.1321a1.0085 1.0085 0 0 0 1.2197 0l1.4995-1.1321 2.4619-1.8633 5.006-3.7489.0125-.01a6.0682 6.0682 0 0 0 2.0094-7.003z"/>',

		'amazon'    => '<path d="M14.7 12.4c-.5.4-1.2.4-1.8.4-1 0-1.9-.4-1.9-1.5 0-1.4 1.3-1.9 2.9-1.9h.8v-.5c0-1.1-.1-2-1.4-2-1 0-1.5.7-1.6 1.5l-1.9-.2c.3-2.1 2.2-2.8 3.8-2.8.9 0 2 .2 2.7.9.9.8.8 2 .8 3.2v2.9c0 .9.4 1.2.7 1.7 0 .2 0 .4-.1.5-.4.3-1.1.9-1.4 1.2h-.1c-.5-.4-.6-.6-1-1zm-.1-3.7h-.5c-1.2 0-2.5.3-2.5 1.7 0 .7.4 1.2 1 1.2.5 0 .9-.3 1.2-.8.3-.6.3-1.1.3-1.8zM20.6 18.2C18.3 20 15 21 12.2 21c-3.9 0-7.4-1.5-10.1-3.9-.2-.2 0-.5.2-.3 2.9 1.7 6.5 2.7 10.2 2.7 2.5 0 5.2-.5 7.7-1.6.4-.2.7.2.4.3M21.6 17c-.3-.4-2-.2-2.8-.1-.2 0-.3-.2-.1-.3 1.3-.9 3.5-.7 3.8-.3.3.4-.1 2.6-1.3 3.7-.2.2-.4.1-.3-.1.3-.8.9-2.5.7-2.9"/>',
	);

	/**
	 * Filters the network logos.
	 *
	 * @param array<string, string> $paths
	 */
	return apply_filters( 'diluxone_users_sso_icon_paths', $paths );
}
