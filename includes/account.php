<?php
/**
 * The account area: the section registry and the URLs.
 *
 * "My account" belongs neither to this site nor to this design: it belongs to
 * any WordPress with people in it. That is why it lives here and not in the
 * site plugin, and why the sections are a registry and not a `switch` —
 * LifterLMS adds its own, the site adds its own, and neither has to edit this
 * file.
 *
 * What decides what is seen and in what order is the sum of three things:
 *   1. what each party registers with diluxone_users_register_section(),
 *   2. what whoever administers turned on, turned off, renamed or reordered,
 *   3. the site's own sections, added from the admin.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The rewrite endpoint: /account/<section>/ */
const DILUXONE_USERS_ACCOUNT_VAR = 'diluxone_users_section';

/**
 * Registers one section of the account area.
 *
 * @param string               $id   Identifier. It is what goes in the URL.
 * @param array<string, mixed> $args label, render, position, capability, source.
 */
function diluxone_users_register_section( string $id, array $args ): void {
	global $diluxone_users_sections;

	$diluxone_users_sections = is_array( $diluxone_users_sections ) ? $diluxone_users_sections : array();

	$diluxone_users_sections[ $id ] = diluxone_users_section_shape( $id, $args );
}

/**
 * One section with every key filled in: what was given, and the rest by default.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function diluxone_users_section_shape( string $id, array $args ): array {
	return wp_parse_args(
		$args,
		array(
			'label'      => $id,
			// Function that draws the section. It receives the WP_User.
			'render'     => '',
			// Lower goes first. Leaves gaps of 10 so things can be slotted in.
			'position'   => 50,
			// Empty: anybody with a session sees it.
			'capability' => '',
			// Where it came from, so the admin screen can say so.
			'source'     => __( 'System', 'diluxone-users' ),
			// The site's own can be deleted; the ones from code cannot.
			'custom'     => false,
			// Content written from the admin. In a section of the site's own it is
			// all there is; in a system one it adds to what the code draws.
			'content'    => '',
			// Where that content goes relative to what the code draws:
			// 'before', 'after' or 'replace'. With no code of its own it makes no difference.
			'placement'  => 'after',
			// Roles that see it. Empty: anybody with a session sees it.
			'roles'      => array(),
			// Optional: a function saying whether the section makes sense today.
			// "Linked accounts" with no network turned on has nothing to show,
			// and an empty section is worse than a section that is not there.
			'available'  => '',
			// Why it is not being shown, in plain words, for the admin.
			'why'        => '',
			// Optional: a function returning a card for the account front page.
			// That way each section builds the summary out of what it knows,
			// instead of a front page that has to know about all of them.
			'summary'    => '',
			// What goes in the URL. The identifier by default, but it is changed
			// from the admin: the identifier belongs to the code and is in
			// English; the address is read by people and is in the site language.
			'slug'       => $id,
		)
	);
}

/**
 * What whoever administers saved for one section.
 *
 * @return array<string, mixed>
 */
function diluxone_users_section_config( string $id ): array {
	$all = (array) diluxone_users_option( 'diluxone_users_account_sections' );

	return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
}

/**
 * Every section, sorted and carrying what whoever administers decided.
 *
 * @param bool $all true to include the ones turned off (the admin needs it).
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_sections( bool $all = false ): array {
	global $diluxone_users_sections;

	// The registry is filled on a hook so that whoever adds a section does not
	// depend on the order the plugins loaded in.
	do_action( 'diluxone_users_register_sections' );

	$sections = is_array( $diluxone_users_sections ) ? $diluxone_users_sections : array();

	// The site's own sections are in no code: they live in the option, and
	// are read from it on every call. Kept in the registry, one deleted
	// earlier in the request would still be listed, and its address taken.
	foreach ( (array) diluxone_users_option( 'diluxone_users_account_sections' ) as $id => $config ) {
		if ( ! isset( $sections[ $id ] ) && ! empty( $config['custom'] ) ) {
			$sections[ (string) $id ] = diluxone_users_section_shape(
				(string) $id,
				array(
					'label'   => (string) ( $config['label'] ?? $id ),
					'source'  => __( 'Yours', 'diluxone-users' ),
					'custom'  => true,
					'content' => (string) ( $config['content'] ?? '' ),
				)
			);
		}
	}

	foreach ( $sections as $id => $section ) {
		$config = diluxone_users_section_config( $id );

		if ( isset( $config['label'] ) && '' !== $config['label'] ) {
			$sections[ $id ]['label'] = (string) $config['label'];
		}

		if ( isset( $config['position'] ) && '' !== $config['position'] ) {
			$sections[ $id ]['position'] = (int) $config['position'];
		}

		if ( isset( $config['content'] ) ) {
			$sections[ $id ]['content'] = (string) $config['content'];
		}

		if ( isset( $config['placement'] ) && in_array( $config['placement'], array( 'before', 'after', 'replace' ), true ) ) {
			$sections[ $id ]['placement'] = (string) $config['placement'];
		}

		if ( isset( $config['roles'] ) ) {
			$sections[ $id ]['roles'] = array_values( array_filter( array_map( 'sanitize_key', (array) $config['roles'] ) ) );
		}

		if ( isset( $config['intro'] ) ) {
			$sections[ $id ]['intro'] = sanitize_text_field( (string) $config['intro'] );
		}

		if ( isset( $config['visibility'] ) ) {
			$sections[ $id ]['visibility'] = 'some' === $config['visibility'] ? 'some' : 'all';
		}

		if ( isset( $config['slug'] ) && '' !== $config['slug'] ) {
			$sections[ $id ]['slug'] = sanitize_title( (string) $config['slug'] );
		}

		$sections[ $id ]['enabled'] = ! isset( $config['enabled'] ) || (bool) $config['enabled'];
	}

	if ( ! $all ) {
		$sections = array_filter(
			$sections,
			static fn( array $s ): bool => $s['enabled']
				&& diluxone_users_section_available( $s )
				&& ( '' === $s['capability'] || current_user_can( $s['capability'] ) )
				&& diluxone_users_section_role_ok( $s )
		);
	}

	uasort( $sections, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

	/**
	 * Filters the account-area sections, already sorted.
	 *
	 * @param array<string, array<string, mixed>> $sections
	 * @param bool                                $all
	 */
	return apply_filters( 'diluxone_users_sections', $sections, $all );
}

/**
 * Does this section have anything to show today?
 *
 * It is the rule that makes what is turned off in the dashboard disappear
 * from the front end without anyone having to remember to turn the section
 * off too: if the site has no social network enabled, "Linked accounts" does
 * not exist.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_section_available( array $section ): bool {
	$check = $section['available'] ?? '';

	return '' === $check || ! is_callable( $check ) || (bool) call_user_func( $check );
}

/**
 * Everybody, or only some roles?
 *
 * Sections saved before the question was asked out loud have no answer stored,
 * so it is read back out of what they do have: roles ticked meant "only
 * those", none ticked meant "everybody". The same rule the rest of the plugin
 * follows for settings that gained the question later.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_section_visibility( array $section ): string {
	$stored = (string) ( $section['visibility'] ?? '' );

	if ( 'all' === $stored || 'some' === $stored ) {
		return $stored;
	}

	return array() === (array) ( $section['roles'] ?? array() ) ? 'all' : 'some';
}

/**
 * Is the role of whoever is looking enough to see this section?
 *
 * "Everybody" means anybody with a session: that is right for almost
 * everything in an account — your details, your security — and it makes
 * choosing roles a decision rather than a setup chore.
 *
 * "Only some roles" with nothing ticked reaches nobody. That is the literal
 * reading of what the screen says, and it is what the rest of the plugin does
 * with the same answer.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_section_role_ok( array $section ): bool {
	if ( 'all' === diluxone_users_section_visibility( $section ) ) {
		return true;
	}

	$roles = (array) ( $section['roles'] ?? array() );

	if ( array() === $roles ) {
		return false;
	}

	$user = wp_get_current_user();

	return $user instanceof WP_User && array() !== array_intersect( $roles, (array) $user->roles );
}

/**
 * Does the code draw this section, or is it only what was written in the admin?
 *
 * Whether it makes sense to ask where the site's own content goes depends on
 * that: if there is nothing from the code, there is no "before" or "after" of
 * anything.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_section_has_code( array $section ): bool {
	return is_callable( $section['render'] ?? '' );
}

/**
 * Draws what goes inside a section.
 *
 * Two things can live together: what the code knows how to draw — the
 * details, the security, another plugin's courses — and what was written from
 * the admin. The second used to overwrite the first, which is the one
 * combination nobody asks for: whoever writes a paragraph above their details
 * does not want to lose the details. Now it is a choice, and replacing is
 * still there for whoever wants it.
 *
 * The code draws itself, where it is called. What the admin wrote is filtered
 * on the way in, as a post would be, and its shortcodes are expanded and the
 * result filtered again on the way out, right where it is printed.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_account_section( array $section, WP_User $user ): void {
	$code  = diluxone_users_section_has_code( $section );
	$own   = '' !== (string) $section['content'];
	$first = $own && $code && in_array( (string) $section['placement'], array( 'before', 'replace' ), true );

	if ( $first ) {
		diluxone_users_account_section_own( $section );
	}

	if ( $code && ! ( $own && 'replace' === (string) $section['placement'] ) ) {
		call_user_func( $section['render'], $user );
	}

	if ( $own && ! $first ) {
		diluxone_users_account_section_own( $section );
	}
}

/**
 * What the admin wrote for a section, with its shortcodes.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_account_section_own( array $section ): void {
	echo wp_kses( do_shortcode( wp_kses_post( (string) $section['content'] ) ), diluxone_users_allowed_html(), diluxone_users_avatar_protocols() );
}

/** The first section shown when arriving without asking for one. */
function diluxone_users_default_section(): string {
	$sections = diluxone_users_sections();

	return (string) ( array_key_first( $sections ) ?? '' );
}

/** The section open right now. */
function diluxone_users_current_section(): string {
	$sections = diluxone_users_sections();

	$asked = (string) get_query_var( DILUXONE_USERS_ACCOUNT_VAR, '' );

	if ( '' === $asked ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks what to draw.
		$asked = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_title( wp_unslash( $_GET['section'] ) ) : '';
	}

	if ( '' === $asked ) {
		return diluxone_users_default_section();
	}

	// The lookup is by address, which is what the browser typed. The
	// identifier works just as well: it is the default address.
	foreach ( $sections as $id => $section ) {
		if ( $section['slug'] === $asked || $id === $asked ) {
			return (string) $id;
		}
	}

	return diluxone_users_default_section();
}

/** The account page, or 0 when none has been chosen yet. */
function diluxone_users_account_page_id(): int {
	return (int) diluxone_users_option( 'diluxone_users_account_page' );
}

/**
 * The URL of one section.
 *
 * With permalinks on it comes out as /account/security/; without them, the
 * usual parameter. There is nothing to configure: it looks at what is there.
 */
function diluxone_users_account_url( string $section = '' ): string {
	$base = diluxone_users_page_url( 'diluxone_users_account_page' );

	if ( '' === $base ) {
		return home_url( '/' );
	}

	if ( '' === $section || $section === diluxone_users_default_section() ) {
		return $base;
	}

	$sections = diluxone_users_sections( true );
	$slug     = isset( $sections[ $section ] ) ? (string) $sections[ $section ]['slug'] : $section;

	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		return add_query_arg( 'section', $slug, $base );
	}

	return trailingslashit( $base ) . $slug . '/';
}

/**
 * The rule that makes /account/<section>/ possible.
 *
 * A rule of our own and not add_rewrite_endpoint(): an endpoint puts its name
 * in the URL — it would come out as /account/diluxone_users_section/security/
 * — and with one per section the number of them would have to be declared up
 * front, which is exactly what this registry avoids.
 */
function diluxone_users_account_rule(): void {
	// Only where the page is: on another site of a network the same id is some
	// other page, and a rule pointing at it would route that one instead.
	$page = diluxone_users_page_here( 'diluxone_users_account_page' );

	if ( $page <= 0 ) {
		return;
	}

	$uri = get_page_uri( $page );

	if ( ! is_string( $uri ) || '' === $uri ) {
		return;
	}

	add_rewrite_rule(
		'^' . preg_quote( $uri, '/' ) . '/([^/]+)/?$',
		'index.php?page_id=' . $page . '&' . DILUXONE_USERS_ACCOUNT_VAR . '=$matches[1]',
		'top'
	);
}
add_action( 'init', 'diluxone_users_account_rule' );

/**
 * Without this WordPress throws away the value the rule captured.
 *
 * @param array<int, string> $vars
 * @return array<int, string>
 */
function diluxone_users_account_query_var( array $vars ): array {
	$vars[] = DILUXONE_USERS_ACCOUNT_VAR;

	return $vars;
}
add_filter( 'query_vars', 'diluxone_users_account_query_var' );

/**
 * The rewrite rules are saved once, not on every request.
 *
 * They are regenerated when the account page changes or when the plugin
 * changes version, the two moments at which the endpoint may have gone stale.
 */
function diluxone_users_account_flush_rules(): void {
	if ( diluxone_users_raw_get( 'diluxone_users_rewrite_version' ) === DILUXONE_USERS_VERSION ) {
		return;
	}

	flush_rewrite_rules( false );
	diluxone_users_update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );
}
add_action( 'wp_loaded', 'diluxone_users_account_flush_rules' );

/**
 * The heading of the section being viewed.
 *
 * The account area draws it and not each template, so that there are not two
 * ways of writing the same thing: a section bringing its own <h2> ends up in
 * a different typeface and size from the ones next to it, and that has
 * happened. The text is the section label — the same one read in the menu,
 * and the one changed from the admin — and whatever wants to say something
 * else says it through the filter.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_account_heading( array $section, string $id, WP_User $user ): string {
	/**
	 * Filters the heading of an account-area section.
	 *
	 * Returning '' removes it.
	 *
	 * @param string  $heading Heading text.
	 * @param string  $id      Section identifier.
	 * @param WP_User $user    Who is looking at it.
	 */
	return (string) apply_filters( 'diluxone_users_account_heading', (string) $section['label'], $id, $user );
}

/**
 * The heading, ready to print. Empty when the section carries none.
 *
 * @param array<string, mixed> $section
 */
function diluxone_users_account_heading_html( array $section, string $id, WP_User $user ): string {
	$heading = diluxone_users_account_heading( $section, $id, $user );
	$intro   = trim( (string) ( $section['intro'] ?? '' ) );

	$html = '' === $heading
		? ''
		: sprintf( '<h2 class="diluxone-users-account__title">%s</h2>', esc_html( $heading ) );

	// The line under the title. A section that explains what it is for in one
	// sentence is worth more than one that does not, and asking a site to copy
	// a template to write that sentence is how templates get copied.
	if ( '' !== $intro ) {
		$html .= sprintf( '<p class="diluxone-users-account__intro">%s</p>', esc_html( $intro ) );
	}

	return $html;
}

/* ── Drawing ───────────────────────────────────────────────────────── */

/** Somebody's visible name: the one they wrote, or whatever there is. */
function diluxone_users_display_name( WP_User $user ): string {
	$full = trim( $user->first_name . ' ' . $user->last_name );

	return '' !== $full ? $full : $user->display_name;
}

/** The first name, for greeting them. */
function diluxone_users_first_name( WP_User $user ): string {
	return '' !== $user->first_name ? $user->first_name : diluxone_users_display_name( $user );
}

/** The initials, for the letter avatar. */
function diluxone_users_initials( WP_User $user ): string {
	$parts = preg_split( '/\s+/', diluxone_users_display_name( $user ), -1, PREG_SPLIT_NO_EMPTY );
	$parts = is_array( $parts ) ? $parts : array();

	$first = isset( $parts[0] ) ? mb_substr( $parts[0], 0, 1 ) : '';
	$last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';

	return mb_strtoupper( $first . $last );
}

/**
 * The navigation, separate from the area: the site may want it elsewhere.
 *
 * @param array<string, array<string, mixed>>|null $sections
 */
function diluxone_users_account_nav( ?array $sections = null, string $current = '' ): string {
	$sections = null === $sections ? diluxone_users_sections() : $sections;
	$current  = '' === $current ? diluxone_users_current_section() : $current;

	if ( array() === $sections ) {
		return '';
	}

	return diluxone_users_render(
		'account-nav',
		array(
			'sections' => $sections,
			'current'  => $current,
			'style'    => diluxone_users_account_nav_style(),
			'align'    => diluxone_users_account_nav_align(),
			// The menu can be placed on its own with the shortcode, far from
			// the area it navigates, so it carries its own direction rather
			// than waiting to be told by a parent that may not be there.
			'column'   => 'side' === diluxone_users_account_layout(),
		)
	);
}

/**
 * The shape of the account area: a panel in the page, or a cover.
 *
 * Anything that is not one of the two answers is the plain one. A template
 * name arriving from a filter that no longer exists should come out as the
 * area that works everywhere, not as a class nothing has styles for.
 */
function diluxone_users_account_template(): string {
	$template = (string) diluxone_users_option( 'diluxone_users_account_template' );

	// Against the shapes there are, not a list written here: a shape added by
	// something else has to be allowed through, and anything unknown falls
	// back to the one that works everywhere.
	$known = function_exists( 'diluxone_users_account_templates' )
		? array_keys( diluxone_users_account_templates() )
		: array( 'plain', 'cover' );

	return in_array( $template, $known, true ) ? $template : 'plain';
}

/**
 * Where the menu can go.
 *
 * @return array<string, string> Layout => what it is called.
 */
function diluxone_users_account_layouts(): array {
	return array(
		'tabs' => __( 'Tabs across the top', 'diluxone-users' ),
		'side' => __( 'A menu down the side', 'diluxone-users' ),
		'none' => __( 'No menu — the site places it with [diluxone_users_account_nav]', 'diluxone-users' ),
	);
}

/**
 * Where the menu goes, read against the places there are.
 *
 * Anything else — an option written by hand, a settings file — was drawn as
 * no menu at all, since neither the tabs nor the side matched: an account
 * area nobody could move around in. It is the tabs instead.
 */
function diluxone_users_account_layout(): string {
	$layout = (string) diluxone_users_option( 'diluxone_users_account_layout' );

	return isset( diluxone_users_account_layouts()[ $layout ] ) ? $layout : 'tabs';
}

/**
 * What the menu looks like: pills, underlined tabs, or plain text.
 *
 * Down the side an underline would be a line across the whole menu under each
 * item, so the stylesheet turns it into a bar down the left — the same idea,
 * rotated. The answer stored is the same either way: the site chose a look,
 * not a set of borders.
 */
function diluxone_users_account_nav_style(): string {
	$style = (string) diluxone_users_option( 'diluxone_users_account_nav_style' );

	$known = diluxone_users_account_nav_styles();

	return isset( $known[ $style ] ) ? $style : 'pills';
}

/**
 * The looks the menu comes in, and what each one is for.
 *
 * Three and not thirty: they are the three ways a menu of sections is drawn
 * on the web, and a fourth would be a variation on one of them. An add-on
 * with a fourth adds it here and writes the rules for its own class.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_account_nav_styles(): array {
	$styles = array(
		'pills'     => array(
			'label' => __( 'Filled', 'diluxone-users' ),
			'help'  => __( 'The open section is a filled block in your colour. The clearest of the three, and the one that reads as a menu rather than as a row of links.', 'diluxone-users' ),
		),
		'underline' => array(
			'label' => __( 'Underlined', 'diluxone-users' ),
			'help'  => __( 'A line under the open section. Down the side it becomes a bar down the left, because a line under a stacked item runs across the whole menu.', 'diluxone-users' ),
		),
		'plain'     => array(
			'label' => __( 'Plain text', 'diluxone-users' ),
			'help'  => __( 'Nothing but the words, with the open one in your colour. For a site whose own design already says where you are.', 'diluxone-users' ),
		),
	);

	/**
	 * Filters the looks the account menu comes in.
	 *
	 * The stylesheet styles `.diluxone-users-account__nav--<slug>`, so a look
	 * added here is a look somebody has written the rules for.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, string>> $styles Keyed by slug.
	 */
	return (array) apply_filters( 'diluxone_users_account_nav_styles', $styles );
}

/**
 * Where the menu sits along its strip.
 *
 * It is named after the edges and not after the sides — start and end rather
 * than left and right — because in a language written right to left the menu
 * at "the start" is on the right, and a site should not have to know that.
 */
function diluxone_users_account_nav_align(): string {
	$align = (string) diluxone_users_option( 'diluxone_users_account_nav_align' );

	return in_array( $align, array( 'center', 'end' ), true ) ? $align : 'start';
}

/** How wide the content runs. */
function diluxone_users_account_width(): string {
	$width = (string) diluxone_users_option( 'diluxone_users_account_width' );

	return 'full' === $width ? 'full' : 'contained';
}

/**
 * The colour behind the cover.
 *
 * Empty means the accent colour, and it is left empty rather than copied into
 * the option: a site that later changes its accent gets a cover that follows
 * instead of a second colour, set once, drifting from the first.
 */
function diluxone_users_account_cover(): string {
	$cover = (string) diluxone_users_option( 'diluxone_users_account_cover' );

	return '' === $cover ? '' : (string) sanitize_hex_color( $cover );
}

/**
 * What the cover is made of: a colour, a picture, or a picture under it.
 *
 * Three and not a tick box, because "a picture" and "a picture you can read a
 * name on top of" are different answers and a site should not have to find
 * that out by uploading a bright one. The third lays the colour over the
 * picture, so the header keeps the contrast the white text needs whatever was
 * uploaded.
 *
 * Asking for a picture and not choosing one falls back to the colour: a band
 * that came out empty because a step was missed is worse than a band.
 */
function diluxone_users_account_cover_kind(): string {
	$kind = (string) diluxone_users_option( 'diluxone_users_account_cover_kind' );

	if ( ! in_array( $kind, array( 'image', 'dim' ), true ) ) {
		return 'color';
	}

	return '' === diluxone_users_account_cover_image() ? 'color' : $kind;
}

/** The picture behind the cover, at full size: it is a band across the window. */
function diluxone_users_account_cover_image(): string {
	return diluxone_users_hub_image_url( (int) diluxone_users_option( 'diluxone_users_account_cover_image' ), 'full' );
}

/** Draws the whole account area. */
function diluxone_users_account_area(): void {
	if ( ! is_user_logged_in() ) {
		diluxone_users_template_part( 'account-guest', array( 'url' => diluxone_users_login_url() ) );

		return;
	}

	$sections = diluxone_users_sections();

	if ( array() === $sections ) {
		return;
	}

	diluxone_users_enqueue_styles();

	$current = diluxone_users_current_section();

	diluxone_users_template_part(
		'account',
		array(
			'user'     => wp_get_current_user(),
			'sections' => $sections,
			'current'  => $current,
			'layout'   => diluxone_users_account_layout(),
			'header'   => (bool) diluxone_users_option( 'diluxone_users_account_header' ),
			'template' => diluxone_users_account_template(),
			'avatar'   => (bool) diluxone_users_option( 'diluxone_users_account_avatar' ),
			'since'    => (bool) diluxone_users_option( 'diluxone_users_account_since' ),
			'action'   => (bool) diluxone_users_option( 'diluxone_users_account_action' ),
			'cover'    => diluxone_users_account_cover(),
			'picture'  => diluxone_users_account_cover_image(),
			'kind'     => diluxone_users_account_cover_kind(),
			'width'    => diluxone_users_account_width(),
		)
	);
}

/** The whole account area. Shortcode: [diluxone_users_account] */
function diluxone_users_shortcode_account(): string {
	ob_start();
	diluxone_users_account_area();

	return (string) ob_get_clean();
}

add_shortcode( 'diluxone_users_account', 'diluxone_users_shortcode_account' );

/** The navigation alone. Shortcode: [diluxone_users_account_nav] */
function diluxone_users_shortcode_account_nav(): string {
	return is_user_logged_in() ? diluxone_users_account_nav() : '';
}
add_shortcode( 'diluxone_users_account_nav', 'diluxone_users_shortcode_account_nav' );
