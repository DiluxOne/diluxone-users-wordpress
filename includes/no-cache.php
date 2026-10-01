<?php
/**
 * The pages a cache must never keep.
 *
 * The sign-in page carries a nonce and, halfway through a sign-in, the
 * second step with the attempt's key in it; the registration form carries a
 * nonce; the account area is one person's data. A page cache — the host's,
 * a caching plugin's, a CDN in front — that keeps any of them hands the next
 * visitor an expired form, somebody else's challenge, or somebody else's
 * account. So those pages say so twice, in the two languages caches speak:
 * the `Cache-Control: no-store` headers WordPress itself sends from
 * wp-login.php (nocache_headers()), and the DONOTCACHEPAGE constant the page
 * caching plugins read.
 *
 * When. On `template_redirect`, which is the earliest moment the front end
 * knows which page it is drawing and the latest at which a header can still
 * be sent: the page the query resolved to is one of the plugin's chosen pages
 * (its sub-addresses, /my-account/details/, resolve to the same page), or its
 * content carries one of the shortcodes. That is the reliable part. A
 * shortcode drawn from somewhere else — a widget, a template part, a block
 * theme's template — is only known when it renders, so rendering one says it
 * too (`pre_do_shortcode_tag`): DONOTCACHEPAGE always arrives in time for a
 * cache that decides when the page is finished, and the headers whenever
 * nothing has been sent yet, which in a block theme is the case, since its
 * template is assembled before the first byte goes out. And wp-login.php's own
 * second step adds the constant to the headers WordPress already sends there.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shortcodes that draw a form, a challenge or somebody's account.
 *
 * @return array<int, string>
 */
function diluxone_users_private_shortcodes(): array {
	return array(
		'diluxone_users_login',
		'diluxone_users_register',
		'diluxone_users_account',
		'diluxone_users_account_nav',
		'diluxone_users_fields',
		'diluxone_users_sessions',
		'diluxone_users_accounts',
		'diluxone_users_handle',
		'diluxone_users_avatar',
		'diluxone_users_notifications',
		'diluxone_users_join',
	);
}

/**
 * Is the page about to be drawn one a cache must not keep?
 *
 * One of the plugin's chosen pages on this site, or a page whose content
 * carries one of the shortcodes above.
 */
function diluxone_users_no_cache_wanted(): bool {
	if ( ! is_singular() ) {
		return false;
	}

	$id = (int) get_queried_object_id();

	foreach ( array_keys( diluxone_users_page_roles() ) as $option ) {
		if ( $id > 0 && diluxone_users_page_here( $option ) === $id ) {
			return true;
		}
	}

	$post = get_post( $id );

	if ( $post instanceof WP_Post ) {
		foreach ( diluxone_users_private_shortcodes() as $shortcode ) {
			if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
				return true;
			}
		}
	}

	/**
	 * Filters whether the page about to be drawn is kept out of every cache.
	 *
	 * For a site that draws the plugin's shortcodes from a template on pages
	 * whose content does not carry them, and wants the headers sent before
	 * the page starts rather than the constant set as it renders.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $wanted Whether it is one of the plugin's pages.
	 */
	return (bool) apply_filters( 'diluxone_users_no_cache', false );
}

/** Says it, in both languages: the constant, and the headers while they can still go. */
function diluxone_users_no_cache(): void {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the name every page-caching plugin reads.
	}

	if ( ! headers_sent() ) {
		nocache_headers();
	}
}

/** The plugin's pages, before anything is drawn. */
function diluxone_users_no_cache_pages(): void {
	if ( diluxone_users_no_cache_wanted() ) {
		diluxone_users_no_cache();
	}
}
add_action( 'template_redirect', 'diluxone_users_no_cache_pages', 0 );

/**
 * One of the shortcodes drawn from somewhere the page's content did not say.
 *
 * @param false|string $output What to draw instead; passed through untouched.
 * @param string       $tag    The shortcode.
 * @return false|string
 */
function diluxone_users_no_cache_shortcode( $output, $tag ) {
	if ( in_array( (string) $tag, diluxone_users_private_shortcodes(), true ) ) {
		diluxone_users_no_cache();
	}

	return $output;
}
add_filter( 'pre_do_shortcode_tag', 'diluxone_users_no_cache_shortcode', 5, 2 );
