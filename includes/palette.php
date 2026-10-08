<?php
/**
 * The short palette: the colours the plugin offers when it is asked to choose.
 *
 * A colour picker offers sixteen million answers and most of them are wrong
 * for a button: a yellow that white letters vanish on, a grey a link cannot be
 * told from the text around it by. So the plugin offers a few, each one
 * chosen so the two jobs an accent has are done properly — white letters on a
 * filled button, and the colour itself as a link on a white panel, both at
 * WCAG AA (4.5:1) — and one dark neutral for a site whose brand is not a
 * colour at all. Any colour is Users+ Pro's (`diluxone_users_any_color`).
 *
 * The grounds the account area can sit on are a palette of their own, because
 * the job is the opposite one: near-white shades the plugin's own text reads on
 * at 7:1 or more.
 *
 * What is stored is the hex, as it always was. A hex nothing offers today —
 * chosen while Users+ Pro was on — is kept, and drawn as the nearest colour of
 * the palette, nearest as the eye sees it (OKLab), until somebody picks again.
 *
 * Pure functions on purpose: the arithmetic is pinned by the unit suite.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The accent the plugin ships with, which is the first of the palette. */
function diluxone_users_accent_default(): string {
	return '#2b59d6';
}

/**
 * The accents offered, in the order they are drawn.
 *
 * Eight, the deep end of each hue: a blue, a violet, a raspberry, a red, a
 * burnt orange, a green, a teal and a graphite. Every one reads at AA under
 * white letters and on white (the unit suite checks), which is what rules out
 * the bright yellows and the light greens a picker invites.
 *
 * @return array<string, array{name: string, color: string}> Keyed by slug.
 */
function diluxone_users_accent_palette(): array {
	return array(
		'blue'      => array(
			'name'  => __( 'Blue', 'diluxone-users' ),
			'color' => '#2b59d6',
		),
		'violet'    => array(
			'name'  => __( 'Violet', 'diluxone-users' ),
			'color' => '#6d28d9',
		),
		'raspberry' => array(
			'name'  => __( 'Raspberry', 'diluxone-users' ),
			'color' => '#be185d',
		),
		'red'       => array(
			'name'  => __( 'Red', 'diluxone-users' ),
			'color' => '#b91c1c',
		),
		'orange'    => array(
			'name'  => __( 'Burnt orange', 'diluxone-users' ),
			'color' => '#c2410c',
		),
		'green'     => array(
			'name'  => __( 'Green', 'diluxone-users' ),
			'color' => '#15803d',
		),
		'teal'      => array(
			'name'  => __( 'Teal', 'diluxone-users' ),
			'color' => '#0f766e',
		),
		'graphite'  => array(
			'name'  => __( 'Graphite', 'diluxone-users' ),
			'color' => '#334155',
		),
	);
}

/**
 * The shades the account area can sit on.
 *
 * Four near-whites, cool to warm. The first is the plugin's own second surface,
 * the grey it already draws the other row of a list in.
 *
 * @return array<string, array{name: string, color: string}> Keyed by slug.
 */
function diluxone_users_ground_palette(): array {
	return array(
		'mist'  => array(
			'name'  => __( 'Mist', 'diluxone-users' ),
			'color' => '#f5f6f8',
		),
		'sky'   => array(
			'name'  => __( 'Sky', 'diluxone-users' ),
			'color' => '#eef3fb',
		),
		'sage'  => array(
			'name'  => __( 'Sage', 'diluxone-users' ),
			'color' => '#eef6f1',
		),
		'paper' => array(
			'name'  => __( 'Paper', 'diluxone-users' ),
			'color' => '#f7f5f0',
		),
	);
}

/**
 * Whether a colour setting takes any colour, or one of the palette's.
 *
 * Users+ Pro answers true: that is its "any colour".
 */
function diluxone_users_any_color(): bool {
	/**
	 * Filters whether the colour settings take any colour.
	 *
	 * False, the accent, the account area's ground and wp-login.php's colour
	 * are one of the short palettes; a colour stored that is none of them is
	 * kept and drawn as the nearest one. True, any hex is an answer.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $any Whether any colour is an answer.
	 */
	return (bool) apply_filters( 'diluxone_users_any_color', false );
}

/** A six-digit hex in lower case, or '' for anything that is not one. */
function diluxone_users_hex( string $value ): string {
	$value = strtolower( trim( $value ) );

	if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $short ) ) {
		return '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
	}

	return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
}

/**
 * The three channels of a hex, linear, from 0 to 1.
 *
 * @return array{0: float, 1: float, 2: float}
 */
function diluxone_users_linear_rgb( string $hex ): array {
	$hex      = diluxone_users_hex( $hex );
	$channels = array( 0.0, 0.0, 0.0 );

	if ( '' === $hex ) {
		return $channels;
	}

	foreach ( array( 0, 1, 2 ) as $i ) {
		$c              = hexdec( substr( $hex, 1 + 2 * $i, 2 ) ) / 255;
		$channels[ $i ] = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
	}

	return $channels;
}

/** The contrast ratio between two colours, as WCAG measures it: 1 to 21. */
function diluxone_users_contrast( string $one, string $two ): float {
	$luminance = static function ( string $hex ): float {
		list( $r, $g, $b ) = diluxone_users_linear_rgb( $hex );

		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	};

	$a = $luminance( $one );
	$b = $luminance( $two );

	return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
}

/**
 * A colour in OKLab, where a distance is a difference the eye sees.
 *
 * In plain RGB a dark blue is "nearer" a dark red than a lighter blue, which is
 * not how anybody would pick the colour standing in for theirs.
 *
 * @return array{0: float, 1: float, 2: float} L, a, b.
 */
function diluxone_users_oklab( string $hex ): array {
	list( $r, $g, $b ) = diluxone_users_linear_rgb( $hex );

	$l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
	$m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
	$s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

	$l = $l ** ( 1 / 3 );
	$m = $m ** ( 1 / 3 );
	$s = $s ** ( 1 / 3 );

	return array(
		0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
		1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
		0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
	);
}

/**
 * Is this colour one of the palette's?
 *
 * @param array<string, array{name: string, color: string}> $palette
 */
function diluxone_users_palette_has( array $palette, string $hex ): bool {
	$hex = diluxone_users_hex( $hex );

	if ( '' === $hex ) {
		return false;
	}

	foreach ( $palette as $swatch ) {
		if ( diluxone_users_hex( (string) $swatch['color'] ) === $hex ) {
			return true;
		}
	}

	return false;
}

/**
 * The colour of the palette nearest to this one, as the eye sees it.
 *
 * @param array<string, array{name: string, color: string}> $palette
 * @return string The slug, or '' with an empty palette or no colour.
 */
function diluxone_users_palette_nearest( string $hex, array $palette ): string {
	if ( '' === diluxone_users_hex( $hex ) ) {
		return '';
	}

	list( $l, $a, $b ) = diluxone_users_oklab( $hex );
	$best              = '';
	$gap               = INF;

	foreach ( $palette as $slug => $swatch ) {
		list( $l2, $a2, $b2 ) = diluxone_users_oklab( (string) $swatch['color'] );
		$distance             = ( $l - $l2 ) ** 2 + ( $a - $a2 ) ** 2 + ( $b - $b2 ) ** 2;

		if ( $distance < $gap ) {
			$gap  = $distance;
			$best = (string) $slug;
		}
	}

	return $best;
}

/**
 * The colour drawn for what is stored.
 *
 * A colour of the palette is itself. Anything else — kept from a time it was
 * an answer — is the nearest colour of the palette, and when that one does
 * not read under white letters (`$readable`: a palette somebody filtered down
 * to pastels) the default stands in, which always does. Nothing stored, or
 * something that is not a colour, is the default.
 *
 * @param array<string, array{name: string, color: string}> $palette
 */
function diluxone_users_palette_color( string $stored, array $palette, string $fallback, bool $readable ): string {
	$hex = diluxone_users_hex( $stored );

	if ( '' === $hex ) {
		return $fallback;
	}

	if ( diluxone_users_palette_has( $palette, $hex ) ) {
		return $hex;
	}

	$nearest = diluxone_users_palette_nearest( $hex, $palette );

	if ( '' === $nearest ) {
		return $fallback;
	}

	$colour = diluxone_users_hex( (string) $palette[ $nearest ]['color'] );

	if ( $readable && diluxone_users_contrast( $colour, '#ffffff' ) < 4.5 ) {
		return $fallback;
	}

	return $colour;
}

/**
 * What a form's answer to a colour question is worth storing as.
 *
 * A colour of the palette, in lower case. The colour already stored, when it
 * comes back as it went out: chosen while Pro was on, it stays chosen until
 * another one is picked. Any colour, when any colour is an answer. Otherwise
 * nothing — a hex sent by hand is no answer the screen offered.
 *
 * @param array<string, array{name: string, color: string}> $palette
 */
function diluxone_users_color_answer( string $posted, string $stored, array $palette, bool $any ): string {
	$hex = diluxone_users_hex( $posted );

	if ( '' === $hex ) {
		return '';
	}

	if ( $any || diluxone_users_palette_has( $palette, $hex ) ) {
		return $hex;
	}

	return '' !== $stored && diluxone_users_hex( $stored ) === $hex ? $hex : '';
}
