<?php
/**
 * A sign-in page of its own: the shape "A page of its own".
 *
 * Every other shape is drawn inside a page and inside the theme around it,
 * and a site that wanted the sign-in on a screen of its own wrote a template
 * to drop the header and the footer. This shape needs neither: the plugin
 * answers an address of the site's choosing (until somebody says otherwise,
 * the path of the page chosen as the sign-in page, or `/sign-in/`) and draws the whole document itself, with the site's name, its
 * mark and its colours, and nothing of the theme's but what wp_head() brings.
 *
 * It is the plugin's own route, the way the account area's sections and the
 * social sign-in's return are: a rewrite rule, a query var, and the rules
 * flushed once through diluxone_users_rewrite_stamp(). On a network it lives
 * on the hub, where everybody signs in; the other sites link to it.
 *
 * The form on it is the sign-in shortcode's, so the reset step and the second
 * step are drawn on the same document with nothing of their own.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The query var the door's addresses are answered by. */
const DILUXONE_USERS_DOOR_VAR = 'diluxone_users_door';

/** The slug until somebody chooses another. */
const DILUXONE_USERS_DOOR_SLUG = 'sign-in';

/** How many old addresses are kept answering. */
const DILUXONE_USERS_DOOR_OLD_MAX = 10;

/**
 * The views the query var may ask for. Compared with this list, never used
 * as a path: no template is ever chosen from the request.
 *
 * @return array<int, string>
 */
function diluxone_users_door_views(): array {
	return array( 'login', 'register', 'moved', 'moved-register' );
}

/** Is the sign-in page one of its own? */
function diluxone_users_door_on(): bool {
	return 'own' === diluxone_users_login_template();
}

/**
 * Is the page of its own the way in right now? Not in safe mode: it stands
 * aside like every other door, wp-login.php is the way in, and its rules stay
 * where they are, so leaving safe mode gives it back without a flush.
 */
function diluxone_users_door_open(): bool {
	return diluxone_users_door_on() && ! diluxone_users_safe_mode();
}

/**
 * The path the page is answered at, without slashes.
 *
 * With "Its address" empty, the address the site already had for signing in:
 * the path of the page chosen in Sign in › The page. `/sign-in/` only when no
 * page is chosen, the page is gone, or its path is nested.
 */
function diluxone_users_login_slug(): string {
	$slug = sanitize_title( (string) diluxone_users_option( 'diluxone_users_login_slug' ) );

	if ( '' !== $slug ) {
		return $slug;
	}

	$page = diluxone_users_login_slug_of_page();

	return '' !== $page ? $page : DILUXONE_USERS_DOOR_SLUG;
}

/**
 * The path of the page chosen as the sign-in page, without slashes, or ''
 * when none is chosen, it is gone, or its path is more than one segment: the
 * page of its own answers one segment only.
 */
function diluxone_users_login_slug_of_page(): string {
	$page = (int) diluxone_users_option( 'diluxone_users_login_page' );

	if ( $page <= 0 ) {
		return '';
	}

	$path = trim( (string) diluxone_users_on_hub( static fn() => get_page_uri( $page ) ), '/' );

	if ( '' === $path || false !== strpos( $path, '/' ) ) {
		return '';
	}

	return sanitize_title( $path );
}

/**
 * The addresses the page had before, newest first, the current one never
 * among them.
 *
 * @return array<int, string>
 */
function diluxone_users_login_slug_old(): array {
	$current = diluxone_users_login_slug();
	$old     = array();

	foreach ( (array) diluxone_users_option( 'diluxone_users_login_slug_old' ) as $slug ) {
		$slug = is_scalar( $slug ) ? sanitize_title( (string) $slug ) : '';

		if ( '' !== $slug && $slug !== $current && ! in_array( $slug, $old, true ) ) {
			$old[] = $slug;
		}
	}

	return array_slice( $old, 0, DILUXONE_USERS_DOOR_OLD_MAX );
}

/**
 * Moves the page to another address, keeping the one it leaves.
 *
 * The address it had goes to the front of the old ones, which go on sending
 * people to the new one: a sign-in or reset link already sent by e-mail still
 * works. At most DILUXONE_USERS_DOOR_OLD_MAX of them, the newest kept, and
 * never the address the page has now — going back to an old address takes it
 * off the list.
 *
 * Saving the address it answers while "Its address" is empty, which follows
 * the chosen sign-in page, stores it: from then on it stays where it is when
 * that page is renamed or another is chosen.
 *
 * @param string $slug The new path, already sanitised.
 */
function diluxone_users_login_slug_move( string $slug ): void {
	$was = diluxone_users_login_slug();

	if ( $slug === $was ) {
		if ( '' === sanitize_title( (string) diluxone_users_option( 'diluxone_users_login_slug' ) ) ) {
			diluxone_users_update_option( 'diluxone_users_login_slug', $slug );
		}

		return;
	}

	$old = array_values( array_diff( array_merge( array( $was ), diluxone_users_login_slug_old() ), array( $slug ) ) );

	diluxone_users_update_option( 'diluxone_users_login_slug_old', array_slice( $old, 0, DILUXONE_USERS_DOOR_OLD_MAX ) );
	diluxone_users_update_option( 'diluxone_users_login_slug', $slug );
}

/**
 * An old address a post takes later is the post's.
 *
 * The old ones only redirect, and a page published at one of them is the
 * site saying it wants that address back: it is taken off the list, and the
 * next request's rules no longer cover it.
 */
function diluxone_users_login_slug_old_forget(): void {
	if ( ! diluxone_users_on_the_hub() ) {
		return;
	}

	$old  = diluxone_users_login_slug_old();
	$kept = array_values( array_filter( $old, static fn( string $slug ): bool => '' === diluxone_users_login_slug_taken( $slug ) ) );

	if ( $kept !== $old ) {
		diluxone_users_update_option( 'diluxone_users_login_slug_old', $kept );
	}
}
add_action( 'save_post', 'diluxone_users_login_slug_old_forget' );

/**
 * The address of the page, on the hub.
 *
 * With pretty permalinks `/<slug>/` (and `/<slug>/register/`); with plain
 * ones the query var itself, which the same code answers.
 *
 * @param string $view 'login' or 'register'.
 */
function diluxone_users_door_url( string $view = 'login' ): string {
	$view = 'register' === $view ? 'register' : 'login';
	$slug = diluxone_users_login_slug();

	return (string) diluxone_users_on_hub(
		static function () use ( $view, $slug ): string {
			if ( '' === (string) get_option( 'permalink_structure' ) ) {
				return add_query_arg( DILUXONE_USERS_DOOR_VAR, $view, home_url( '/' ) );
			}

			return home_url( user_trailingslashit( 'register' === $view ? $slug . '/register' : $slug ) );
		}
	);
}

/**
 * The first segments WordPress, the server or the site already answer, which
 * the page cannot take without taking them away.
 *
 * WordPress's own directories and routes, the REST API's prefix, the
 * category and tag bases, every registered type's and taxonomy's address,
 * the account page's, and on a subdirectory network the paths of its sites
 * and the main site's `blog`.
 *
 * @return array<int, string>
 */
function diluxone_users_login_slug_reserved(): array {
	$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-json', 'feed', 'comments', 'page', 'search', 'author', 'embed', 'category', 'tag' );

	$reserved[] = rest_get_url_prefix();
	$reserved[] = (string) get_option( 'category_base' );
	$reserved[] = (string) get_option( 'tag_base' );

	foreach ( array_merge( array_values( get_post_types( array(), 'objects' ) ), array_values( get_taxonomies( array(), 'objects' ) ) ) as $object ) {
		if ( is_array( $object->rewrite ) && isset( $object->rewrite['slug'] ) ) {
			$reserved[] = (string) $object->rewrite['slug'];
		}

		if ( $object instanceof WP_Post_Type && is_string( $object->has_archive ) ) {
			$reserved[] = $object->has_archive;
		}
	}

	$account = diluxone_users_page_here( 'diluxone_users_account_page' );

	if ( $account > 0 ) {
		$reserved[] = (string) get_page_uri( $account );
	}

	if ( is_multisite() && ! is_subdomain_install() ) {
		$reserved[] = 'blog';

		foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
			$reserved[] = (string) $site->path;
		}
	}

	$first = static function ( string $path ): string {
		$path = explode( '/', trim( $path, '/' ) );

		return strtolower( $path[0] );
	};

	return array_values( array_unique( array_filter( array_map( $first, $reserved ) ) ) );
}

/**
 * Why this address cannot be the page's, or '' when it can.
 *
 * One segment, not empty, nothing WordPress or the site already answers, and
 * no post's but the page chosen as the sign-in page, which it takes the place
 * of: a page shadowed by the sign-in, or the sign-in shadowing wp-admin, is a
 * site that lost something without anybody deciding it.
 *
 * @param string $raw What was typed, unslashed.
 */
function diluxone_users_login_slug_problem( string $raw ): string {
	$raw  = trim( $raw, " \t\n\r\0\x0B/" );
	$slug = sanitize_title( $raw );

	if ( '' === $slug ) {
		return __( 'The sign-in page needs an address: letters, numbers and dashes.', 'diluxone-users' );
	}

	if ( false !== strpos( $raw, '/' ) ) {
		return sprintf(
			/* translators: %s: what was typed */
			__( 'The address “%s” has to be one word, with no slashes: letters, numbers and dashes.', 'diluxone-users' ),
			$raw
		);
	}

	$reserved = diluxone_users_login_slug_reserved();

	if ( in_array( $slug, $reserved, true ) || in_array( strtolower( $raw ), $reserved, true ) ) {
		return sprintf(
			/* translators: %s: the address, as /sign-in/ */
			__( 'The address %s is one WordPress or the site already answers. Choose another.', 'diluxone-users' ),
			'/' . $slug . '/'
		);
	}

	$taken = diluxone_users_login_slug_taken( $slug );

	if ( '' !== $taken ) {
		return sprintf(
			/* translators: 1: the address, as /about/, 2: what has it, as: the page “About us” */
			__( 'The address %1$s is already %2$s. Choose another, or give that one another address first.', 'diluxone-users' ),
			'/' . $slug . '/',
			$taken
		);
	}

	return '';
}

/**
 * What already has this path on this site, as a sentence's end — `the page
 * “About us”` — or '' when nothing does.
 *
 * Any public type, in any status but the bin: a draft published tomorrow
 * would otherwise lose its address to the sign-in page without a word. Never
 * the page chosen as the sign-in page: it already sends people to the page of
 * its own, which takes its place at its address, so the save, the Status
 * screen and the old addresses all leave it be.
 *
 * @param string $slug The path, without slashes.
 */
function diluxone_users_login_slug_taken( string $slug ): string {
	if ( '' === $slug ) {
		return '';
	}

	$post = get_page_by_path( $slug, OBJECT, array_values( get_post_types( array( 'public' => true ) ) ) );

	if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
		return '';
	}

	if ( (int) $post->ID === (int) diluxone_users_option( 'diluxone_users_login_page' ) ) {
		return '';
	}

	$type = get_post_type_object( $post->post_type );
	$name = $type instanceof WP_Post_Type ? strtolower( (string) $type->labels->singular_name ) : $post->post_type;

	return sprintf(
		/* translators: 1: a kind of content, "page" or "post", 2: its title */
		__( 'the %1$s “%2$s”', 'diluxone-users' ),
		$name,
		'' !== $post->post_title ? $post->post_title : $post->post_name
	);
}

/**
 * The address of the site's own sign-in screen, or '' when it has none and
 * wp-login.php does the job.
 *
 * The page of its own first: while that shape is chosen it is the door,
 * whatever page is still chosen in Sign in › The page. Then that page, when a
 * stranger can open it (diluxone_users_login_page_live()).
 */
function diluxone_users_login_door_url(): string {
	if ( diluxone_users_door_open() ) {
		return diluxone_users_door_url();
	}

	return diluxone_users_login_page_live() ? diluxone_users_page_url( 'diluxone_users_login_page' ) : '';
}

/**
 * The rules that answer the page's addresses, on the hub only.
 *
 * At the top, so a page given the same path later does not take the door
 * away without a word: the Status screen says so instead.
 */
function diluxone_users_door_rules(): void {
	if ( ! diluxone_users_on_the_hub() || ! diluxone_users_door_on() ) {
		return;
	}

	$slug = preg_quote( diluxone_users_login_slug(), '/' );

	add_rewrite_rule( '^' . $slug . '/?$', 'index.php?' . DILUXONE_USERS_DOOR_VAR . '=login', 'top' );
	add_rewrite_rule( '^' . $slug . '/register/?$', 'index.php?' . DILUXONE_USERS_DOOR_VAR . '=register', 'top' );

	foreach ( diluxone_users_login_slug_old() as $old ) {
		$old = preg_quote( $old, '/' );

		add_rewrite_rule( '^' . $old . '/?$', 'index.php?' . DILUXONE_USERS_DOOR_VAR . '=moved', 'top' );
		add_rewrite_rule( '^' . $old . '/register/?$', 'index.php?' . DILUXONE_USERS_DOOR_VAR . '=moved-register', 'top' );
	}
}
add_action( 'init', 'diluxone_users_door_rules' );

/**
 * Without this WordPress throws away the value the rule captured.
 *
 * @param array<int, string> $vars
 * @return array<int, string>
 */
function diluxone_users_door_query_var( array $vars ): array {
	$vars[] = DILUXONE_USERS_DOOR_VAR;

	return $vars;
}
add_filter( 'query_vars', 'diluxone_users_door_query_var' );

/**
 * What this request asked the door for: one of diluxone_users_door_views(),
 * or '' when it is not the door, or the door is not on here.
 */
function diluxone_users_door_view(): string {
	if ( ! diluxone_users_on_the_hub() || ! diluxone_users_door_on() ) {
		return '';
	}

	$view = (string) get_query_var( DILUXONE_USERS_DOOR_VAR, '' );

	return in_array( $view, diluxone_users_door_views(), true ) ? $view : '';
}

/**
 * The part of the rewrite stamp the door brings: its address and the old
 * ones, so a changed slug flushes the rules once.
 */
function diluxone_users_door_stamp(): string {
	if ( ! diluxone_users_on_the_hub() || ! diluxone_users_door_on() ) {
		return '';
	}

	return 'door:' . implode( '+', array_merge( array( diluxone_users_login_slug() ), diluxone_users_login_slug_old() ) );
}

/**
 * What the request carried, to go along to the page it was meant for: each
 * name a key and each value plain text, read one by one. A value that is not
 * a string (an array) stays behind.
 *
 * @param string[] $leave Names left out.
 * @return array<string, string>
 */
function diluxone_users_door_query( array $leave = array() ): array {
	$query = array();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nothing is done with it: the query goes along to the page it was meant for.
	foreach ( $_GET as $name => $value ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name || in_array( $name, $leave, true ) || ! is_string( $value ) ) {
			continue;
		}

		$query[ $name ] = sanitize_text_field( wp_unslash( $value ) );
	}

	return $query;
}

/**
 * Before anything is drawn: where the request goes, and what the page says
 * about itself.
 *
 * An old address goes to the new one, with what it carried — a link already
 * sent by e-mail still works. Somebody already signed in has nothing to do
 * here and goes to their account. Everybody else gets the page: kept out of
 * every cache, out of the search engines, and answered 200.
 */
function diluxone_users_door_serve(): void {
	$view = diluxone_users_door_view();

	if ( '' === $view || ! diluxone_users_door_open() ) {
		return;
	}

	if ( 'moved' === $view || 'moved-register' === $view ) {
		$to = diluxone_users_door_url( 'moved-register' === $view ? 'register' : 'login' );

		$query = diluxone_users_door_query( array( DILUXONE_USERS_DOOR_VAR ) );

		if ( array() !== $query ) {
			$to = add_query_arg( array_map( 'rawurlencode', $query ), $to );
		}

		// A 302 nobody keeps, not a 301: a browser keeps a bare 301 for good,
		// and a slug set back to an old one would then send it in a loop.
		diluxone_users_no_cache();
		wp_safe_redirect( $to, 302 );
		exit;
	}

	if ( is_user_logged_in() ) {
		$account = diluxone_users_page_url( 'diluxone_users_account_page' );

		wp_safe_redirect( '' !== $account ? $account : home_url( '/' ) );
		exit;
	}

	diluxone_users_no_cache();

	global $wp_query;

	if ( $wp_query instanceof WP_Query ) {
		$wp_query->is_404 = false;
	}

	if ( ! headers_sent() ) {
		status_header( 200 );
	}

	// The form is built before the head, while the stylesheet is not even
	// registered: it is asked for right after it is.
	add_action( 'wp_enqueue_scripts', 'diluxone_users_enqueue_styles', 6 );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_filter( 'redirect_canonical', '__return_false' );
}
add_action( 'template_redirect', 'diluxone_users_door_serve', 2 );

/**
 * A sign-in or registration page still chosen in Sign in › The page is not
 * a second door while this shape is on: it sends people to the page of its
 * own. A 302, so choosing another shape gives the page back at once.
 */
function diluxone_users_door_pages(): void {
	if ( ! diluxone_users_door_open() || ! is_page() ) {
		return;
	}

	$id = (int) get_queried_object_id();

	$roles = array(
		'diluxone_users_login_page'    => 'login',
		'diluxone_users_register_page' => 'register',
	);

	foreach ( $roles as $option => $view ) {
		$page = diluxone_users_page_here( $option );

		if ( $page > 0 && $page === $id ) {
			$query = diluxone_users_door_query();
			$to    = diluxone_users_door_url( $view );

			wp_safe_redirect( array() === $query ? $to : add_query_arg( array_map( 'rawurlencode', $query ), $to ), 302 );
			exit;
		}
	}
}
add_action( 'template_redirect', 'diluxone_users_door_pages', 2 );

/**
 * Is this request the page of its own, drawn? Its sign-in or its
 * registration, with the shape on and nobody signed in, which is when the
 * template below answers it.
 */
function diluxone_users_door_drawn(): bool {
	$view = diluxone_users_door_view();

	return ( 'login' === $view || 'register' === $view ) && diluxone_users_door_open() && ! is_user_logged_in();
}

/**
 * The document the plugin draws in place of the theme's template.
 *
 * Late, so a block theme's template, chosen before this filter, is replaced
 * too. A theme can still bring its own copy of the file
 * (`diluxone-users/login-page.php`), the way it can for every template.
 *
 * @param mixed $template The template WordPress chose.
 */
function diluxone_users_door_template( $template ): string {
	$view = diluxone_users_door_view();

	if ( ( 'login' !== $view && 'register' !== $view ) || ! diluxone_users_door_open() ) {
		return (string) $template;
	}

	$own = diluxone_users_template( 'login-page.php' );

	return '' !== $own ? $own : (string) $template;
}
add_filter( 'template_include', 'diluxone_users_door_template', 99 );

/**
 * The page's own title, in the browser's tab.
 *
 * @param mixed $title What WordPress would say.
 */
function diluxone_users_door_title( $title ): string {
	$view = diluxone_users_door_view();

	if ( 'register' === $view ) {
		return __( 'Create your account', 'diluxone-users' ) . ' – ' . get_bloginfo( 'name' );
	}

	if ( 'login' === $view ) {
		return diluxone_users_text( 'diluxone_users_login_title', __( 'Sign in', 'diluxone-users' ) ) . ' – ' . get_bloginfo( 'name' );
	}

	return (string) $title;
}
add_filter( 'pre_get_document_title', 'diluxone_users_door_title' );

/**
 * What the page holds: the form for the view asked for, built before the
 * document starts, so the stylesheet it asks for is enqueued in time for
 * wp_head().
 */
function diluxone_users_door_form(): string {
	if ( 'register' === diluxone_users_door_view() ) {
		return diluxone_users_shortcode_register();
	}

	return diluxone_users_shortcode_login( array( 'title' => 'yes' ) );
}
