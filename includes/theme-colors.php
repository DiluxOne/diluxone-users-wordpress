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
 * something else in some — so it is guessed where the slug says so and asked
 * where it does not, on a screen that shows the palette it found.
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
 * The colour to paint in the square beside a part — always one, never none.
 *
 * Two different things used to leave that square empty, and an empty square
 * with a border on a settings screen is a tick box nobody has ticked: seven of
 * them in a column, and the question stopped being "which colour does what"
 * and became "what have I failed to switch on".
 *
 * The first was “leave it to the plugin”, which was painted `transparent`
 * when the honest answer is that the plugin has a colour for that part and
 * this is it. The second is the good case and the common one: a theme that
 * hands over `var(--ast-global-color-0)` instead of a hex is a theme the
 * plugin follows live, into its dark mode — and that value is exactly the
 * one the dashboard cannot resolve, because the theme’s stylesheet is not
 * loaded in here.
 *
 * So the variable is passed on with the plugin’s own colour behind it. Where
 * the property exists the fallback is never reached; where it does not — in
 * this screen — the square shows what the part would look like if the theme
 * said nothing, which is the truest thing that can be shown from here.
 *
 * @param string $role One of diluxone_users_color_roles().
 * @param string $slug The palette slug chosen for it, or '' for none.
 */
function diluxone_users_color_swatch( string $role, string $slug ): string {
	$own     = diluxone_users_color_own();
	$mine    = (string) ( $own[ $role ] ?? '#ffffff' );
	$palette = diluxone_users_theme_palette();

	if ( '' === $slug || ! isset( $palette[ $slug ] ) ) {
		return $mine;
	}

	$colour = $palette[ $slug ]['color'];

	// Only ever a `var(...)`, an rgb/hsl or a hex: diluxone_users_color_value()
	// refused everything else on the way in, so the closing bracket is there
	// to be found.
	return 0 === stripos( $colour, 'var(' )
		? substr( $colour, 0, -1 ) . ', ' . $mine . ')'
		: $colour;
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
 * The mapping in force: what the site chose, over what was guessed.
 *
 * @return array<string, string> Role => slug.
 */
function diluxone_users_color_map(): array {
	$palette = diluxone_users_theme_palette();
	$stored  = (array) diluxone_users_option( 'diluxone_users_color_map' );
	$map     = diluxone_users_color_guess( $palette );

	foreach ( $stored as $role => $slug ) {
		$role = sanitize_key( (string) $role );
		$slug = sanitize_key( (string) $slug );

		if ( ! isset( diluxone_users_color_roles()[ $role ] ) ) {
			continue;
		}

		// An empty answer is an answer: it means "leave this one to the
		// plugin", and it has to be able to beat the guess.
		$map[ $role ] = isset( $palette[ $slug ] ) ? $slug : '';
	}

	return array_filter( $map );
}

/**
 * Is the plugin taking its colours from the theme?
 *
 * A theme with no palette to read, or one whose colours are not named in a
 * way that says which does what, still has colours: the ones it paints. What
 * the palette cannot answer is measured on the site (see theme-measure.php),
 * so the answer no longer depends on the palette being there.
 */
function diluxone_users_colors_from_theme(): bool {
	return 'theme' === (string) diluxone_users_option( 'diluxone_users_colors' );
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
