<?php
/**
 * A sign-in page of its own: the shape "A page of its own".
 *
 * Every other shape is drawn inside a page and inside the theme around it,
 * and a site that wanted the sign-in on a screen of its own wrote a template
 * to drop the header and the footer. This shape needs neither: the plugin
 * answers an address of the site's choosing (`/sign-in/` until somebody says
 * otherwise) and draws the whole document itself, with the site's name, its
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

/** The path the page is answered at, without slashes. */
function diluxone_users_login_slug(): string {
	$slug = sanitize_title( (string) diluxone_users_option( 'diluxone_users_login_slug' ) );

	return '' !== $slug ? $slug : DILUXONE_USERS_DOOR_SLUG;
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

	if ( '' === $view ) {
		return;
	}

	if ( 'moved' === $view || 'moved-register' === $view ) {
		$to = diluxone_users_door_url( 'moved-register' === $view ? 'register' : 'login' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nothing is done with it: the query goes along to the page it was meant for.
		$query = array_diff_key( (array) wp_unslash( $_GET ), array( DILUXONE_USERS_DOOR_VAR => true ) );

		if ( array() !== $query ) {
			$to = add_query_arg( array_map( 'rawurlencode_deep', $query ), $to );
		}

		wp_safe_redirect( $to, 301 );
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

	if ( 'login' !== $view && 'register' !== $view ) {
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
