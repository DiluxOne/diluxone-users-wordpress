<?php
/**
 * The colours of the active theme, borrowed.
 *
 * A users plugin lands inside somebody else's design, and until now the only
 * way for it to match was for the site to write CSS repointing every token by
 * hand. That is a stylesheet a site has to maintain, and it is the first
 * thing that goes stale.
 *
 * Themes already publish their palette: `theme.json` declares it and
 * WordPress hands it over through wp_get_global_settings(). So the plugin can
 * ask. Which colour of that palette plays which part is the one thing nobody
 * can know for certain — `primary` means the brand in most themes and
 * something else in some — so it is guessed where the slug says so; choosing
 * it by hand is Users+ Pro's (`diluxone_users_color_map`).
 *
 * Several themes, Astra among them, publish values that are themselves custom
 * properties: `var(--ast-global-color-0)`. Those are kept as they are rather
 * than resolved, and that is the better answer — the plugin then follows the
 * theme live, including whatever the theme does in dark mode.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The parts of the plugin a colour can play, and what each one paints.
 *
 * Two strings per part and not one, because the row that asks about it has
 * two jobs: name the part, so the answer chosen beside it is about something,
 * and say where in the plugin that colour turns up, so the answer can be
 * given by somebody who has never read the stylesheet. "Accent — buttons,
 * links, the open section" was one line doing both and doing neither: a list
 * of nouns with a dash in front of it reads as a translation of the word
 * before the dash, not as an example of where to look.
 *
 * @return array<string, array{name: string, paints: string}>
 */
function diluxone_users_color_roles(): array {
	return array(
		'accent'      => array(
			'name'   => __( 'Accent', 'diluxone-users' ),
			'paints' => __( 'Filled buttons, links, and the section of the menu somebody is reading.', 'diluxone-users' ),
		),
		'accent-ink'  => array(
			'name'   => __( 'On the accent', 'diluxone-users' ),
			'paints' => __( 'The letters inside a filled button — white on almost every site.', 'diluxone-users' ),
		),
		'text'        => array(
			'name'   => __( 'Text', 'diluxone-users' ),
			'paints' => __( 'Headings, and every line meant to be read first. Nearly black rather than black.', 'diluxone-users' ),
		),
		'muted'       => array(
			'name'   => __( 'Quiet text', 'diluxone-users' ),
			'paints' => __( 'The sentence under a field, a date, the help beside a tick box.', 'diluxone-users' ),
		),
		'surface'     => array(
			'name'   => __( 'Surface', 'diluxone-users' ),
			'paints' => __( 'The ground every panel, card and box is drawn on.', 'diluxone-users' ),
		),
		'surface-alt' => array(
			'name'   => __( 'Second surface', 'diluxone-users' ),
			'paints' => __( 'What stands out from that ground without a line around it: the other row of a list.', 'diluxone-users' ),
		),
		'border'      => array(
			'name'   => __( 'Lines', 'diluxone-users' ),
			'paints' => __( 'Every line: the edge of a panel, of a field, the rule between two rows.', 'diluxone-users' ),
		),
	);
}

/**
 * The colour the plugin’s own stylesheet gives each part.
 *
 * It is written here as well as in assets/diluxone-users.css, and the copy is
 * deliberate: the sheet is the only place these can be DECLARED — every
 * component reads `var(--diluxone-users-surface)` and nothing else — and
 * nothing in the dashboard can ask a stylesheet what a property resolves to.
 * The accent is the exception and is not copied twice: it is a setting, and
 * the setting already knows its own default.
 *
 * @return array<string, string> Role => colour.
 */
function diluxone_users_color_own(): array {
	return array(
		'accent'      => diluxone_users_style_accent(),
		'accent-ink'  => '#ffffff',
		'text'        => '#16181d',
		'muted'       => '#626a7a',
		'surface'     => '#ffffff',
		'surface-alt' => '#f5f6f8',
		'border'      => '#e2e5ec',
	);
}

/**
 * The active theme's palette, as WordPress hands it over.
 *
 * Only the theme's own. The twelve colours WordPress ships with are not the
 * theme's answer to anything, and offering them would be offering a palette
 * nobody chose.
 *
 * @return array<string, array<string, string>> Keyed by slug.
 */
function diluxone_users_theme_palette(): array {
	$settings = wp_get_global_settings( array( 'color' ) );
	$palette  = array();

	foreach ( (array) ( $settings['palette']['theme'] ?? array() ) as $colour ) {
		$slug = sanitize_key( (string) ( $colour['slug'] ?? '' ) );
		$css  = diluxone_users_color_value( (string) ( $colour['color'] ?? '' ) );

		if ( '' === $slug || '' === $css ) {
			continue;
		}

		$palette[ $slug ] = array(
			'name'  => (string) ( $colour['name'] ?? $slug ),
			'color' => $css,
		);
	}

	/**
	 * Filters the palette the plugin borrows from.
	 *
	 * A theme that publishes no theme.json, or one that keeps its colours
	 * somewhere of its own, adds them here.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, string>> $palette Keyed by slug.
	 */
	$palette = (array) apply_filters( 'diluxone_users_theme_palette', $palette );

	/*
	 * Validated after the filter and not only before it. Every value in here
	 * ends up printed into a `:root{}` block, and a filter that returns a
	 * palette is the one caller that can hand over a value nothing checked.
	 */
	$clean = array();

	foreach ( $palette as $slug => $entry ) {
		$entry = (array) $entry;
		$color = is_string( $entry['color'] ?? null ) ? diluxone_users_color_value( $entry['color'] ) : '';

		// A colour that is not one is no entry: printed empty it was
		// `--diluxone-users-accent:;`, which took the plugin's own accent away
		// with it. The name is a name, not a colour.
		if ( '' === $color ) {
			continue;
		}

		$clean[ (string) $slug ] = array(
			'name'  => sanitize_text_field( is_scalar( $entry['name'] ?? null ) ? (string) $entry['name'] : (string) $slug ),
			'color' => $color,
		);
	}

	return $clean;
}

/**
 * A colour value that is safe to print into a stylesheet.
 *
 * Four shapes and nothing else: a hex, an rgb/rgba, an hsl/hsla, and a custom
 * property. The last one is the one that matters — it is how a theme says
 * "whatever this is right now" — and it is also the one that would carry
 * anything at all if it were not checked.
 */
function diluxone_users_color_value( string $value ): string {
	$value = trim( $value );

	if ( '' === $value ) {
		return '';
	}

	if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
		return $value;
	}

	if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9a-z%.,\s\/-]+\)$/i', $value ) ) {
		return $value;
	}

	if ( preg_match( '/^var\(\s*--[a-z0-9-]+\s*\)$/i', $value ) ) {
		return $value;
	}

	return '';
}

/**
 * Which colour of the palette plays which part, guessed.
 *
 * By the slug, and only where the slug says so. Themes that follow the names
 * WordPress suggests — base, contrast, primary, accent — are mapped without
 * anybody being asked. Themes that number their colours are not guessed at:
 * an accent chosen by counting would be wrong on half the sites and there
 * would be no way to tell which half.
 *
 * @param array<string, array<string, string>> $palette
 * @return array<string, string> Role => slug.
 */
function diluxone_users_color_guess( array $palette ): array {
	$by_role = array(
		'accent'      => array( 'primary', 'accent', 'accent-1', 'brand', 'link' ),
		'accent-ink'  => array( 'base', 'background', 'white' ),
		'text'        => array( 'contrast', 'foreground', 'text', 'body' ),
		'muted'       => array( 'contrast-2', 'contrast-3', 'muted', 'secondary' ),
		'surface'     => array( 'base', 'background', 'white' ),
		'surface-alt' => array( 'base-2', 'background-alt', 'surface', 'light' ),
		'border'      => array( 'border', 'contrast-3', 'base-3' ),
	);

	$map = array();

	foreach ( $by_role as $role => $slugs ) {
		foreach ( $slugs as $slug ) {
			if ( isset( $palette[ $slug ] ) ) {
				$map[ $role ] = $slug;
				break;
			}
		}
	}

	return $map;
}

/**
 * The mapping in force.
 *
 * What the palette's names say, guessed. Choosing it by hand is Users+ Pro's,
 * and arrives through the filter; a part mapped to a colour the palette does
 * not have is left to the plugin.
 *
 * @return array<string, string> Role => slug.
 */
function diluxone_users_color_map(): array {
	$palette = diluxone_users_theme_palette();

	/**
	 * Filters which colour of the theme's palette plays which part.
	 *
	 * Role (diluxone_users_color_roles()) => slug of the theme's palette, or
	 * '' to leave that part to the plugin's own colour.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string>                $map     The guess, from the palette's names.
	 * @param array<string, array<string, string>> $palette The theme's palette, keyed by slug.
	 */
	$map   = (array) apply_filters( 'diluxone_users_color_map', diluxone_users_color_guess( $palette ), $palette );
	$roles = diluxone_users_color_roles();
	$clean = array();

	foreach ( $map as $role => $slug ) {
		$role = sanitize_key( (string) $role );
		$slug = sanitize_key( (string) $slug );

		if ( isset( $roles[ $role ], $palette[ $slug ] ) ) {
			$clean[ $role ] = $slug;
		}
	}

	return $clean;
}

/**
 * Is the plugin taking its colours from the theme?
 *
 * Not, as the plugin has it: it paints itself with its own palette and the
 * accent chosen on Design › Your brand, light and dark. The theme's colours
 * are Users+ Pro's "From your theme", through
 * `diluxone_users_colors_from_theme`, and everything below that reads or
 * measures them is reached only through this answer.
 *
 * A theme with no palette to read, or one whose colours are not named in a
 * way that says which does what, still has colours: the ones it paints. What
 * the palette cannot answer is measured on the site (see theme-measure.php),
 * so the answer does not depend on the palette being there.
 */
function diluxone_users_colors_from_theme(): bool {
	/**
	 * Filters whether the plugin takes its colours from the theme.
	 *
	 * False, as the plugin has it: its own palette and accent.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $theme Whether the colours are the theme's.
	 */
	return (bool) apply_filters( 'diluxone_users_colors_from_theme', false );
}

/**
 * The parts the palette did not answer, which the visitor's browser measures
 * from what the theme paints. Empty unless the colours are the theme's.
 *
 * @return array<int, string> Roles.
 */
function diluxone_users_color_measured_roles(): array {
	if ( ! diluxone_users_colors_from_theme() ) {
		return array();
	}

	$palette = diluxone_users_theme_palette();
	$mapped  = array_filter(
		diluxone_users_color_map(),
		static fn( string $slug ): bool => isset( $palette[ $slug ] )
	);

	return array_values( array_diff( array_keys( diluxone_users_color_roles() ), array_keys( $mapped ) ) );
}

/**
 * The theme's colours, as the plugin's properties.
 *
 * A part nobody mapped is left alone rather than guessed at twice: the
 * plugin's own colour is a reasonable colour, and a wrong one taken from the
 * theme is worse than a neutral one that was never claimed to match.
 */
function diluxone_users_theme_colors_css(): string {
	if ( ! diluxone_users_colors_from_theme() ) {
		return '';
	}

	$palette = diluxone_users_theme_palette();
	$tokens  = '';

	foreach ( diluxone_users_color_map() as $role => $slug ) {
		if ( ! isset( $palette[ $slug ] ) ) {
			continue;
		}

		$tokens .= '--diluxone-users-' . $role . ':' . $palette[ $slug ]['color'] . ';';

		// The filled button takes the same colour as the accent unless the
		// site said otherwise, which is what the sheet does on its own.
		if ( 'accent' === $role ) {
			$tokens .= '--diluxone-users-accent-bg:' . $palette[ $slug ]['color'] . ';';
		}
	}

	return '' === $tokens ? '' : ':root{' . $tokens . '}';
}

/**
 * How the site says it is dark, read from what the admin typed.
 *
 * Two answers: `.is-dark`, a class, or `data-theme`, `data-theme="dark"`,
 * `data-theme=dark`, an attribute with or without the value it must have.
 * Nothing else, because the plugin writes it onto the page of its own as it
 * is: a class name, or an attribute name and a value, each from a short list
 * of characters. An attribute that runs code or paints (`on…`, `style`) is no
 * signal, nor is `class` (that is what the dot is for), nor a value with a
 * quote or a bracket in it.
 *
 * @param string $on   Where it goes: 'html' or 'body'.
 * @param string $text What was typed.
 * @return array{on: string, kind: string, name: string, value: ?string}|null Null when it is not one.
 */
function diluxone_users_dark_signal_parse( string $on, string $text ): ?array {
	$text = trim( $text );

	if ( ! in_array( $on, array( 'html', 'body' ), true ) || '' === $text ) {
		return null;
	}

	if ( preg_match( '/^\.([A-Za-z_-][A-Za-z0-9_-]*)$/', $text, $class ) ) {
		return array(
			'on'    => $on,
			'kind'  => 'class',
			'name'  => $class[1],
			'value' => null,
		);
	}

	if ( ! preg_match( '/^([A-Za-z_][A-Za-z0-9_.:-]*)(?:=(?:"([^"\'<>`]*)"|\'([^"\'<>`]*)\'|([^\s"\'<>`=]+)))?$/', $text, $attr ) ) {
		return null;
	}

	$name = strtolower( $attr[1] );

	if ( 'style' === $name || 'class' === $name || 0 === strpos( $name, 'on' ) ) {
		return null;
	}

	$value = null;

	if ( false !== strpos( $text, '=' ) ) {
		$value = implode( '', array_slice( $attr, 2 ) );
	}

	return array(
		'on'    => $on,
		'kind'  => 'attr',
		'name'  => $name,
		'value' => $value,
	);
}

/**
 * A signal as it is typed and stored: `.is-dark`, `data-theme`, `data-theme="dark"`.
 *
 * @param array{on: string, kind: string, name: string, value: ?string} $signal
 */
function diluxone_users_dark_signal_text( array $signal ): string {
	if ( 'class' === $signal['kind'] ) {
		return '.' . $signal['name'];
	}

	return null === $signal['value'] ? $signal['name'] : $signal['name'] . '="' . $signal['value'] . '"';
}

/**
 * A stored signal, read again: `html data-theme="dark"`, where it goes, a
 * space, and what it is. Null for nothing, or for something that no longer
 * parses (an old or hand-edited value), which leaves the device to decide.
 *
 * @param string $key 'diluxone_users_dark_signal' or 'diluxone_users_light_signal'.
 * @return array{on: string, kind: string, name: string, value: ?string}|null
 */
function diluxone_users_dark_signal_stored( string $key ): ?array {
	$stored = diluxone_users_option( $key );
	$parts  = explode( ' ', is_string( $stored ) ? trim( $stored ) : '', 2 );

	return 2 === count( $parts ) ? diluxone_users_dark_signal_parse( $parts[0], $parts[1] ) : null;
}

/**
 * How the plugin tells the site's dark scheme from its light one, in the
 * browser: the site's own switch, as the admin described it, or nothing,
 * while the visitor's device decides through `prefers-color-scheme`.
 *
 * Only while the colours are the theme's: with the plugin's own there is one
 * palette and no dark one to choose.
 *
 * @return array<string, array{on: string, kind: string, name: string, value: ?string}> 'dark', and 'light' when there is one; empty while the device decides.
 */
function diluxone_users_dark_signal(): array {
	if ( 'site' !== diluxone_users_option( 'diluxone_users_dark_follows' ) || ! diluxone_users_colors_from_theme() ) {
		return array();
	}

	$dark = diluxone_users_dark_signal_stored( 'diluxone_users_dark_signal' );

	if ( null === $dark ) {
		return array();
	}

	$light = diluxone_users_dark_signal_stored( 'diluxone_users_light_signal' );

	return null === $light ? array( 'dark' => $dark ) : array(
		'dark'  => $dark,
		'light' => $light,
	);
}
