<?php
/**
 * The site's own face on wp-login.php.
 *
 * Even a site that sends everybody to its own sign-in page still shows this
 * screen to somebody. An administrator coming in through the emergency door
 * sees it, and so does anybody finishing a password reset — that link goes
 * to wp-login.php and nowhere else, because WordPress builds it. A grey box
 * with WordPress's logo on it, on a site that looks nothing like that, is the
 * kind of detail that makes a person wonder whether they are where they think
 * they are.
 *
 * It is off until somebody turns it on: a plugin that repaints a screen it
 * was not asked about is a plugin that gets blamed for the repaint.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Is the site's face being put on WordPress's own screen? */
function diluxone_users_wp_login_branded(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_wp_login_brand' );
}

/**
 * The colour behind it.
 *
 * Empty means the accent colour, and it stays empty rather than being copied:
 * a site that later changes its accent gets this screen following along
 * instead of a second colour, set once, drifting away from the first.
 */
function diluxone_users_wp_login_bg(): string {
	$own = (string) diluxone_users_option( 'diluxone_users_wp_login_bg' );
	$hex = '' === $own ? diluxone_users_style_accent() : $own;

	// Both branches are sanitised on the way out and not only the explicit
	// one. What comes back from the accent is sanitised where it is saved,
	// which is a different file and a promise this one cannot check — and
	// what this returns is printed into a stylesheet.
	return (string) sanitize_hex_color( $hex );
}

/**
 * The style for WordPress's sign-in screen.
 *
 * Added to WordPress's own login stylesheet rather than printed as a <style>
 * block. The hook that prints one runs before that stylesheet is linked, so
 * an inline rule of equal weight lost to it every time — the background took
 * and the logo did not, which is the confusing half-result. Attached to the
 * handle, it comes after by definition.
 */
function diluxone_users_wp_login_styles(): void {
	if ( ! diluxone_users_wp_login_branded() ) {
		return;
	}

	$background = diluxone_users_wp_login_bg();
	$url        = diluxone_users_hub_image_url( (int) diluxone_users_option( 'diluxone_users_wp_login_logo' ), 'medium' );
	$stored     = (string) diluxone_users_option( 'diluxone_users_style_radius' );

	// A number and then "px", never the text as it was typed. It is saved
	// with `sanitize_text_field`, so `}` and `;` survive it — and this goes
	// straight into a stylesheet. A value with a brace in it closed the rule
	// early and took wp-login.php with it, which is the one screen that has
	// to work when everything else does not.
	$radius = ( '' === $stored ? 4 : (int) $stored ) . 'px';

	/*
	 * The heading is a link with WordPress's logo as its background image and
	 * the site name pushed out of sight. WordPress styles it through an id —
	 * `#login h1 a` — so a rule made of classes never reached it however late
	 * it was loaded; this one matches that weight and comes after. With a picture it stays that way and
	 * only the picture changes; without one the text comes back, because a
	 * site with no logo uploaded should read as its name rather than as an
	 * empty box with somebody else's mark in it.
	 */
	$mark = '' !== $url
		? sprintf(
			// Centred by hand: the link WordPress draws is as wide as its
			// picture, so a wider one sat against the left edge.
			'background-image:url(%s);background-size:contain;background-position:center;background-repeat:no-repeat;display:block;width:100%%;max-width:320px;height:80px;margin:0 auto;',
			esc_url( $url )
		)
		: 'background-image:none;width:auto;height:auto;text-indent:0;font-size:22px;font-weight:700;line-height:1.3;color:#fff;';

	$css = sprintf(
		'body.login{background:%1$s}
		#login h1 a,.login h1 a{%2$s}
		.login form,.login .notice,.login #login_error,.login .message{border-radius:%3$s;border-left-width:1px}
		.login #nav a,.login #backtoblog a,.login .privacy-policy-link{color:#fff;opacity:.85}
		.login #nav a:hover,.login #backtoblog a:hover,.login .privacy-policy-link:hover{color:#fff;opacity:1}
		.login .button-primary{border-radius:%3$s;background:%1$s;border-color:%1$s;text-shadow:none;box-shadow:none}
		.login .button-primary:hover,.login .button-primary:focus{background:%1$s;border-color:%1$s;filter:brightness(0.93)}',
		$background,
		$mark,
		$radius
	);

	wp_add_inline_style( 'login', $css );
}
add_action( 'login_enqueue_scripts', 'diluxone_users_wp_login_styles', 20 );

/** The logo links to the site, not to wordpress.org. */
function diluxone_users_wp_login_url( string $url ): string {
	return diluxone_users_wp_login_branded() ? home_url( '/' ) : $url;
}
add_filter( 'login_headerurl', 'diluxone_users_wp_login_url' );

/** And it is called by the site's name. */
function diluxone_users_wp_login_text( string $text ): string {
	return diluxone_users_wp_login_branded() ? (string) get_bloginfo( 'name' ) : $text;
}
add_filter( 'login_headertext', 'diluxone_users_wp_login_text' );

/**
 * Where "I forgot my password" leads.
 *
 * On a site where an e-mail link signs people in, the reset screen asks for
 * the same address the sign-in form asks for, and sends a second e-mail to do
 * what the first one already does. Pointing that link at the sign-in page is
 * one round trip fewer for the person who forgot something they never had.
 *
 * Only when there is a link to point at and a page to point it to. A site
 * with passwords and no e-mail link keeps WordPress's reset, which is the
 * only way back in it has.
 */
function diluxone_users_lost_password_url( string $url ): string {
	// Only for the answer that says nobody resets anything here: the other two
	// send people to a form that asks for a reset, which is where the link
	// already goes.
	if ( 'link' !== (string) diluxone_users_option( 'diluxone_users_lost_password' ) || diluxone_users_safe_mode() ) {
		return $url;
	}

	if ( ! diluxone_users_login_has_link() ) {
		return $url;
	}

	// The page itself, and not diluxone_users_login_url(), which falls back to
	// wp-login.php when none is chosen — sending "I forgot my password" to
	// wp-login.php without its action is sending it nowhere.
	$page = diluxone_users_login_page_live() ? diluxone_users_page_url( 'diluxone_users_login_page' ) : '';

	return '' !== $page ? $page : $url;
}
add_filter( 'lostpassword_url', 'diluxone_users_lost_password_url' );
