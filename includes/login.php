<?php
/**
 * Single-use link sign-in ("magic link").
 *
 * Signing in and registering can be the same step: the person types their
 * e-mail, receives a link and gets in with it. If the account did not exist,
 * it is created right there.
 *
 * How it works:
 *   1. A random token is generated.
 *   2. Only its hash is stored in the database, never the token: if somebody
 *      walks off with the database, they cannot get in with what is there.
 *   3. On opening the link it is validated, the session starts and the token
 *      is deleted — it works exactly once.
 *
 * Decisions that matter:
 *   - The answer is identical whether the account exists or not. Saying "we
 *     could not find that e-mail" turns the form into a detector of who is
 *     registered.
 *   - Constant-time comparison, so the token does not leak through how long
 *     it takes to fail.
 *   - A per-e-mail request limit, so it cannot be used as a battering ram or
 *     as a machine for mailing third parties.
 *
 * This lives alongside WordPress's ordinary registration: it is one more
 * door, not a replacement. The "e-mail only" setting is what closes the others.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shape of the sign-in page.
 *
 * Two answers of the plugin's own: 'plain' leaves the page alone — the form
 * where the theme put it, for a site with a designed page — and 'card' takes
 * the page over with the form in a box in the middle, for a site that has not
 * designed one. An add-on adds shapes of its own through
 * `diluxone_users_login_templates`.
 */
function diluxone_users_login_template(): string {
	$template = (string) diluxone_users_option( 'diluxone_users_login_template' );

	// Checked against the shapes there are and not a list written here, or a
	// shape added by something else would never be allowed through — the
	// setting would save and then quietly not apply.
	$known = function_exists( 'diluxone_users_login_templates' )
		? array_keys( diluxone_users_login_templates() )
		: array( 'plain', 'card' );

	if ( in_array( $template, $known, true ) ) {
		return $template;
	}

	// A shape something offered and nothing draws today — an add-on that is
	// not running — took the page over: the card is the shape of the plugin's
	// own that still does, rather than dropping the form into a page that was
	// never designed to hold it. Nothing stored is the page as it is.
	return '' === $template ? 'plain' : 'card';
}

/**
 * A picture chosen in the admin, at a size worth serving.
 *
 * Full width because it is a background: an 'image' thumbnail stretched over
 * half a screen is the kind of detail that makes a good design look cheap.
 */
function diluxone_users_login_image( string $key ): string {
	return diluxone_users_hub_image_url( (int) diluxone_users_option( $key ), 'full' );
}

/** Does the site want an icon inside the two doors? */
function diluxone_users_button_icons(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_button_icons' );
}

/**
 * The icon inside a button, or nothing.
 *
 * It is here and not in the template because both templates want it — the
 * sign-in form and the registration form draw the same two doors — and a
 * decision written twice is one that will be changed once.
 */
function diluxone_users_button_icon( string $name ): string {
	return diluxone_users_button_icons() ? diluxone_users_icon( $name, 20 ) : '';
}

/**
 * The frame around the sign-in form, opened.
 *
 * It lives here and not in the template because both steps of signing in go
 * inside it — the form, and the screen asking for the second-step code — and
 * a frame written twice is a frame that will be changed once. A theme that
 * replaces login.php still gets the frame around whatever it draws, which is
 * what a theme replacing the form actually wants.
 *
 * Prints nothing at all for the template that leaves the page alone.
 */
function diluxone_users_login_frame_open(): void {
	$template = diluxone_users_login_template();

	if ( 'plain' === $template ) {
		return;
	}

	$shape      = (array) ( diluxone_users_login_templates()[ $template ] ?? array() );
	$classes    = array( 'diluxone-users-login-frame', 'diluxone-users-login-frame--' . $template );
	$properties = array();

	// What a shape adds to the frame: classes of its own and the custom
	// properties its stylesheet reads. Asked of the shape, so a shape an
	// add-on brings draws its frame the way the plugin's own would.
	if ( isset( $shape['classes'] ) && is_callable( $shape['classes'] ) ) {
		$classes = array_merge( $classes, array_map( 'strval', (array) call_user_func( $shape['classes'] ) ) );
	}

	if ( isset( $shape['properties'] ) && is_callable( $shape['properties'] ) ) {
		$properties = (array) call_user_func( $shape['properties'] );
	}

	/*
	 * The wrapper is not decoration: it is what makes the frame a grandchild.
	 * A block theme constrains its content with
	 * `:where(.is-layout-constrained) > :where(…)` — direct children only, and
	 * with !important on both the margin and the max-width, so nothing a child
	 * declares can break out of the column. The wrapper takes that treatment
	 * and the frame inside it is free.
	 */
	echo '<div class="diluxone-users-login-page">';

	$style = diluxone_users_css_properties( $properties );

	printf(
		'<div class="%1$s"%2$s>',
		esc_attr( implode( ' ', array_unique( array_filter( array_map( 'sanitize_html_class', $classes ) ) ) ) ),
		'' === $style ? '' : ' style="' . esc_attr( $style ) . '"'
	);

	// What the shape draws beside the form, before its box: a picture, a
	// panel with words on it. Printed through the same allow-list as any
	// markup the plugin builds.
	if ( isset( $shape['inside'] ) && is_callable( $shape['inside'] ) ) {
		ob_start();
		call_user_func( $shape['inside'] );
		echo wp_kses( (string) ob_get_clean(), diluxone_users_allowed_html() );
	}

	echo '<div class="diluxone-users-login-frame__box">';
}

/**
 * Custom properties, as the declarations of a style attribute.
 *
 * Only properties — a name that starts with two dashes — and only values that
 * cannot close the declaration, the rule or the attribute they are printed
 * in: a value is a value, never a way out of it.
 *
 * @param array<mixed, mixed> $properties Name => value.
 */
function diluxone_users_css_properties( array $properties ): string {
	$style = '';

	foreach ( $properties as $name => $value ) {
		$name  = (string) $name;
		$value = trim( is_scalar( $value ) ? (string) $value : '' );

		if ( ! preg_match( '/^--[a-z0-9-]+$/i', $name ) || '' === $value || preg_match( '/[;{}<>"\\\\]/', $value ) ) {
			continue;
		}

		$style .= $name . ':' . $value . ';';
	}

	return $style;
}

/** And closed. */
function diluxone_users_login_frame_close(): void {
	if ( 'plain' === diluxone_users_login_template() ) {
		return;
	}

	echo '</div></div></div>';
}

/**
 * A small drawing, for the two places on this screen that read better with one.
 *
 * Inline and not a font or a sprite: two shapes do not justify a request, and
 * inline is the only kind that follows the surrounding colour. They carry
 * their size as attributes so they stay the right size with the plugin's
 * stylesheet turned off, and they are hidden from screen readers — each one
 * sits next to a sentence that already says the same thing.
 *
 * @param string $name mail | info | key | network | user
 */
function diluxone_users_icon( string $name, int $size = 0 ): string {
	$paths = array(
		'mail'    => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"></rect><path d="m3 6 9 6.5L21 6"></path>',
		'info'    => '<circle cx="12" cy="12" r="9"></circle><path d="M12 16v-5M12 8h.01"></path>',
		// A key, for a way in that is a key: that is what everybody's
		// operating system draws on the prompt such a button opens.
		'key'     => '<circle cx="8" cy="12" r="4"></circle><path d="M12 12h9M18 12v4M15.5 12v3"></path>',

		/*
		 * The two that name a way in on a tab. They are drawn here, in the
		 * same hand as the other three, and that is the rule rather than an
		 * accident: a tab strip is a row of equals, so the tab for "a social
		 * account" cannot be Google's mark — a site with five networks would
		 * have five logos to fit on one tab, and whichever one it picked would
		 * be telling people the other four are not there.
		 */
		'network' => '<circle cx="12" cy="5" r="2.4"></circle><circle cx="5" cy="18" r="2.4"></circle><circle cx="19" cy="18" r="2.4"></circle><path d="M10.6 6.9 6.4 15.6M13.4 6.9l4.2 8.7M7.4 18h9.2"></path>',
		'user'    => '<circle cx="12" cy="8" r="3.4"></circle><path d="M5.5 19.5a6.5 6.5 0 0 1 13 0"></path>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	// The envelope is the picture on the screen that says the mail is on its
	// way, so it is drawn big; inside a button it is asked for at 20.
	$size = $size > 0 ? $size : ( 'mail' === $name ? 40 : 20 );

	return sprintf(
		'<svg class="diluxone-users-icon diluxone-users-icon--%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
		esc_attr( $name ),
		$size,
		$paths[ $name ]
	);
}

/** The site’s mark above the form, when there is one and the site shows it there. */
function diluxone_users_login_logo(): void {
	if ( ! diluxone_users_option( 'diluxone_users_login_logo_show' ) ) {
		return;
	}

	$logo = diluxone_users_login_image( 'diluxone_users_login_logo' );

	if ( '' === $logo ) {
		return;
	}

	printf(
		'<p class="diluxone-users-login__logo"><img src="%1$s" alt="%2$s"></p>',
		esc_url( $logo ),
		esc_attr( get_bloginfo( 'name' ) )
	);
}

/**
 * What a screen says, in the site's words or in the plugin's.
 *
 * Every visible sentence the plugin writes has a default that works out of
 * the box and an option that is empty until somebody fills it in. Empty is
 * not "say nothing": it is "say what the plugin says", which is the same rule
 * every other setting here follows — a site that changes nothing gets a
 * screen that reads correctly, and a site that has its own voice does not
 * have to copy a template to use it.
 *
 * @param string $key      Option name.
 * @param string $fallback  What the plugin says when nothing was written.
 */
function diluxone_users_text( string $key, string $fallback = '' ): string {
	$own = trim( (string) diluxone_users_option( $key ) );

	return '' !== $own ? $own : $fallback;
}

const DILUXONE_USERS_META_HASH    = '_diluxone_users_link_hash';
const DILUXONE_USERS_META_EXPIRES = '_diluxone_users_link_expires';

/** Where the link lands: the `redirect_to` the sign-in page was opened with, kept on the server. */
const DILUXONE_USERS_META_RETURN = '_diluxone_users_link_return';

/**
 * Generates a token, stores its hash and returns the token in the clear.
 *
 * What is persisted is the hash: the token in the clear exists only in the e-mail.
 */
function diluxone_users_token_create( int $user_id ): string {
	$token = wp_generate_password( 40, false, false );

	update_user_meta( $user_id, DILUXONE_USERS_META_HASH, wp_hash( $token ) );
	update_user_meta( $user_id, DILUXONE_USERS_META_EXPIRES, time() + ( diluxone_users_login_expiry() * MINUTE_IN_SECONDS ) );

	return $token;
}

/**
 * Is this token valid for this user?
 *
 * It is compared with hash_equals so that the response time does not depend
 * on how many characters matched.
 */
function diluxone_users_token_valid( int $user_id, string $token ): bool {
	$hash    = (string) get_user_meta( $user_id, DILUXONE_USERS_META_HASH, true );
	$expires = (int) get_user_meta( $user_id, DILUXONE_USERS_META_EXPIRES, true );

	if ( '' === $hash || $expires <= 0 || time() > $expires ) {
		return false;
	}

	return hash_equals( $hash, wp_hash( $token ) );
}

/**
 * Burns the token: a link works exactly once.
 *
 * The delete is the check. Two requests carrying the same link can both find
 * it valid; only one of them deletes a row, and only that one gets in.
 */
function diluxone_users_token_burn( int $user_id ): bool {
	$burned = delete_user_meta( $user_id, DILUXONE_USERS_META_HASH );
	delete_user_meta( $user_id, DILUXONE_USERS_META_EXPIRES );

	return $burned;
}

/**
 * The address the sign-in page was opened for: its `redirect_to`, when it
 * stays on the site (or the network, through `allowed_redirect_hosts`), and
 * `''` otherwise.
 */
function diluxone_users_login_asked_redirect(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only display read of the page's own address: it only fills a form field, and the address is cleaned and kept to the site's hosts.
	$url = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

	return '' !== $url ? (string) wp_validate_redirect( $url, '' ) : '';
}

/** URL of the sign-in link. */
function diluxone_users_login_link( int $user_id, string $token ): string {
	return add_query_arg(
		array(
			'diluxone_users_login' => $user_id,
			'diluxone_users_token' => $token,
		),
		home_url( '/' )
	);
}

/**
 * Finds the account for the e-mail. It never creates one.
 *
 * Asking for a link proves nothing — anybody can type somebody else's
 * address — so an address nobody has yet gets a pending sign-up instead
 * (diluxone_users_signup_create()), and the account is created when the link
 * is opened, which is what proves the address is theirs.
 *
 * @return int User ID, or 0 when there is no account with that address.
 */
function diluxone_users_user_for( string $email ): int {
	$user = get_user_by( 'email', $email );

	// On a network, existing is not being a member of this site. They are not
	// made one here either: diluxone_users_complete_login() adds them once
	// they have come in.
	return $user ? (int) $user->ID : 0;
}

/** Prefix of the site transient that holds a pending sign-up. */
const DILUXONE_USERS_SIGNUP_PREFIX = 'diluxone_users_signup_';

/**
 * Remembers a sign-up for an address nobody has yet, until its link is opened.
 *
 * Kept on the network, like the account it becomes, under a random key: what
 * is stored is the address, the token's hash and when it runs out — the same
 * as a sign-in link. The token in the clear exists only in the e-mail, and
 * the address never travels in the link.
 *
 * @return array{id: string, token: string}
 */
function diluxone_users_signup_create( string $email ): array {
	$id     = strtolower( wp_generate_password( 32, false, false ) );
	$token  = wp_generate_password( 40, false, false );
	$expiry = diluxone_users_login_expiry() * MINUTE_IN_SECONDS;

	set_site_transient(
		DILUXONE_USERS_SIGNUP_PREFIX . $id,
		array(
			'email'   => $email,
			'hash'    => wp_hash( $token ),
			'expires' => time() + $expiry,
		),
		$expiry
	);

	return array(
		'id'    => $id,
		'token' => $token,
	);
}

/**
 * Spends a pending sign-up and returns its address, or empty when it is no good.
 *
 * Like a sign-in link, the delete is the check: two requests carrying the
 * same link can both find it valid; only one of them deletes it.
 */
function diluxone_users_signup_take( string $id, string $token ): string {
	if ( '' === $id || '' === $token ) {
		return '';
	}

	$signup = get_site_transient( DILUXONE_USERS_SIGNUP_PREFIX . $id );

	if ( ! is_array( $signup ) || ! isset( $signup['email'], $signup['hash'], $signup['expires'] ) ) {
		return '';
	}

	if ( time() > (int) $signup['expires'] || ! hash_equals( (string) $signup['hash'], wp_hash( $token ) ) ) {
		return '';
	}

	if ( ! delete_site_transient( DILUXONE_USERS_SIGNUP_PREFIX . $id ) ) {
		return '';
	}

	return (string) $signup['email'];
}

/** URL of the link that finishes a pending sign-up. */
function diluxone_users_signup_link( string $id, string $token ): string {
	return add_query_arg(
		array(
			'diluxone_users_signup' => $id,
			'diluxone_users_token'  => $token,
		),
		home_url( '/' )
	);
}

/**
 * Does signing in with the e-mail link create the account today?
 *
 * The switch on the Registration screen, and the link itself being a way in:
 * on a site that signs in with a password only, no link is mailed, so a door
 * that creates the account by mailing one is not open, whatever it says. Read
 * here, once, by everything that asks whether that door is open — the
 * sign-in, the Registration screen, the summary and the network.
 */
function diluxone_users_link_registers(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_login_register' ) && diluxone_users_login_has_link();
}

/**
 * How accounts come to exist, read off the doors that make them.
 *
 * It used to be an option of its own with three exclusive answers, and the
 * third question — "and if I want both?" — had no answer. The doors are
 * independent now: the e-mail link can create the account, the site's own
 * form can, either, neither. This is the same fact read back as one word,
 * for everything that wants a word: 'login', 'form', 'both' or 'closed'.
 */
function diluxone_users_register_mode(): string {
	if ( ! diluxone_users_network_takes_accounts() ) {
		return 'closed';
	}

	$link = diluxone_users_link_registers();
	$form = (bool) diluxone_users_option( 'diluxone_users_register_form' );

	if ( $link && $form ) {
		return 'both';
	}

	if ( $form ) {
		return 'form';
	}

	return $link ? 'login' : 'closed';
}

/**
 * Creates the account, whatever door it came through.
 *
 * Whoever calls this has already decided that this person may have one: it
 * asks nothing and checks nothing except that the address is free.
 *
 * @return int User ID, or 0 if WordPress refused.
 */
function diluxone_users_create_account( string $email ): int {
	// Except one thing, which no site setting overrides: on a network, the
	// network decides whether accounts may be created at all.
	if ( ! diluxone_users_network_takes_accounts() ) {
		return 0;
	}

	// The display name and the one in the profile URL are NOT allowed to be
	// derived from user_login, because user_login is the e-mail: WordPress
	// would build a display_name of "somebody@gmail.com" that later shows up in
	// the forums, and a user_nicename of "somebodygmail-com" that reads plainly
	// in the profile URL. The account is identified by the e-mail; publishing
	// it is another matter.
	$visible = diluxone_users_name_from_email( $email );

	// The profile address must not answer to another account's: not its
	// nicename, which WordPress already checks, and not its login either,
	// which WordPress does not, and which an account made before the plugin
	// can have as a plain name.
	$base     = sanitize_title( $visible );
	$nicename = $base;

	$n = 2;

	while ( '' !== $nicename && diluxone_users_handle_taken( $nicename, 0 ) ) {
		$nicename = $base . '-' . $n;
		++$n;
	}

	// The password is generated at random and nobody knows it, not even the
	// person registering: WordPress needs the field, the site does not use it.
	$id = wp_insert_user(
		array(
			'user_login'    => $email,
			'user_email'    => $email,
			'user_pass'     => wp_generate_password( 64, true, true ),
			'display_name'  => $visible,
			'user_nicename' => $nicename,
			'role'          => diluxone_users_register_role(),
		)
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	diluxone_users_join_site( (int) $id );

	return (int) $id;
}

/**
 * The role an account gets when it creates itself.
 *
 * The setting is read through a check and not as it is, because the screen
 * that saves it offers every role there is, administrator included, and a
 * setting that can hand the site to whoever types an e-mail is not a setting
 * anybody meant. A role that fails the check falls back to subscriber, the
 * one WordPress means for exactly this.
 */
function diluxone_users_register_role(): string {
	$role = sanitize_key( (string) diluxone_users_option( 'diluxone_users_login_role' ) );

	return diluxone_users_role_self_serve( $role ) ? $role : 'subscriber';
}

/**
 * May people give themselves this role?
 *
 * It is decided by what the role can do, not by its name, so a custom role
 * gets the same treatment as a built-in one: anything that reaches other
 * people's accounts, other people's content or the site's setup is out. That
 * leaves subscriber, contributor and author of the built-in ones — and any
 * role a site adds that keeps to its own account.
 */
function diluxone_users_role_self_serve( string $role ): bool {
	$object = get_role( $role );

	if ( ! $object instanceof WP_Role ) {
		return false;
	}

	/**
	 * Filters the capabilities that keep a role out of self-registration.
	 *
	 * @param array<int, string> $caps
	 */
	$forbidden = (array) apply_filters(
		'diluxone_users_role_forbidden_caps',
		array(
			// Other people's accounts.
			'manage_options',
			'edit_users',
			'list_users',
			'promote_users',
			'create_users',
			'delete_users',
			'remove_users',
			// Other people's content, and content that goes out unfiltered.
			'edit_others_posts',
			'delete_others_posts',
			'edit_others_pages',
			'publish_pages',
			'unfiltered_html',
			'moderate_comments',
			'manage_categories',
			// The site itself.
			'edit_theme_options',
			'switch_themes',
			'edit_themes',
			'install_themes',
			'activate_plugins',
			'edit_plugins',
			'install_plugins',
			'update_core',
			'edit_files',
			'import',
			'export',
		)
	);

	foreach ( $forbidden as $cap ) {
		if ( $object->has_cap( (string) $cap ) ) {
			return false;
		}
	}

	return true;
}

/** How people get in: 'link', 'password' or 'both'. */
function diluxone_users_login_method(): string {
	$method = (string) diluxone_users_option( 'diluxone_users_login_method' );

	return in_array( $method, array( 'link', 'password', 'both' ), true ) ? $method : 'password';
}

/** Is the e-mail link offered? */
function diluxone_users_login_has_link(): bool {
	return 'password' !== diluxone_users_login_method();
}

/** Is the username-and-password form offered? */
function diluxone_users_login_has_password(): bool {
	return 'link' !== diluxone_users_login_method();
}

/** Is the e-mail link the only door? */
function diluxone_users_login_only_link(): bool {
	return 'link' === diluxone_users_login_method();
}

/**
 * A presentable name out of an e-mail address.
 *
 * It is what is shown until the person writes their own. It keeps what comes
 * before the at sign — the domain tells nobody anything — and strips the
 * separators so that "juan.perez88" reads as "juan perez88".
 */
function diluxone_users_name_from_email( string $email ): string {
	$local = (string) strstr( $email, '@', true );
	$local = '' === $local ? $email : $local;
	$local = trim( (string) preg_replace( '/[._\-]+/', ' ', $local ) );

	return '' === $local ? __( 'Someone', 'diluxone-users' ) : $local;
}

/** Sends the e-mail with the link. */
function diluxone_users_login_send( int $user_id, string $email, string $token ): bool {
	return diluxone_users_login_mail( $email, diluxone_users_login_link( $user_id, $token ) );
}

/** Sends the e-mail with the link that finishes a pending sign-up. */
function diluxone_users_signup_send( string $email, string $id, string $token ): bool {
	return diluxone_users_login_mail( $email, diluxone_users_signup_link( $id, $token ) );
}

/** Mails a link that signs somebody in, whether their account exists yet or not. */
function diluxone_users_login_mail( string $email, string $url ): bool {
	/*
	 * The link is not written anywhere on the way out, and that includes the
	 * error log on a site that is not production. It is a credential: whoever
	 * reads `debug.log` inside the next fifteen minutes signs in as that
	 * person, with no password and no second step. A development site with
	 * real people's addresses imported into it is the normal case, not the
	 * strange one. Whoever needs to see the message on a machine with no mail
	 * server has `diluxone_users_login_email` right below, or Mailpit.
	 */

	/**
	 * Filters the e-mail before sending it.
	 *
	 * @param array{subject: string, body: string} $message
	 * @param string                               $email
	 * @param string                               $url
	 */
	$message = apply_filters( 'diluxone_users_login_email', diluxone_users_mail_login_link( $email, $url ), $email, $url );

	return wp_mail( $email, $message['subject'], $message['body'] );
}

/**
 * Remembers, in this browser only, what the person typed to ask for a link.
 *
 * The "check your e-mail" screen shows it back so they can spot a typo. It is
 * what they typed, and it lives for as long as a link does.
 */
function diluxone_users_sent_to( string $typed ): void {
	diluxone_users_cookie_set( 'diluxone_users_sent', $typed, time() + 60 * diluxone_users_login_expiry() );
}

/** What this browser typed to ask for the last link, or empty. */
function diluxone_users_sent_address(): string {
	return isset( $_COOKIE['diluxone_users_sent'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['diluxone_users_sent'] ) ) : '';
}

/**
 * An address with most of it hidden: `ana@example.com` reads `a•••@e•••.com`.
 *
 * Enough for the person it belongs to to recognise it and spot a typo, and
 * not enough for somebody looking over their shoulder to write it down. The
 * first letter of the name, the first letter of the domain and what follows
 * its last dot; anything that is not an address is hidden whole.
 */
function diluxone_users_mask_email( string $email ): string {
	$at = strrpos( $email, '@' );

	if ( false === $at || 0 === $at || strlen( $email ) - 1 === $at ) {
		return '' === $email ? '' : '•••';
	}

	$local  = substr( $email, 0, $at );
	$domain = substr( $email, $at + 1 );
	$dot    = strrpos( $domain, '.' );
	$tail   = false === $dot || 0 === $dot ? '' : substr( $domain, $dot );

	return substr( $local, 0, 1 ) . '•••@' . substr( $domain, 0, 1 ) . '•••' . $tail;
}

/**
 * How many sign-in links one machine may ask for in an hour.
 *
 * Generous on purpose: a family, an office or a school share an address, and
 * so do the people on one conference wifi. Thirty is more than any of them
 * reach in an hour and far below what is worth a script's while.
 */
function diluxone_users_login_burst(): int {
	/**
	 * Filters how many sign-in links one address may ask for per hour.
	 *
	 * @since 1.0.0
	 *
	 * @param int $burst
	 */
	return max( 1, (int) apply_filters( 'diluxone_users_login_burst', 30 ) );
}

/**
 * Handles the "send me the link" form.
 *
 * It always answers the same thing, whatever happened. It creates no account:
 * for an address nobody has, where the link creates accounts, it mails the
 * link of a pending sign-up, and the account is created when that link is
 * opened.
 */
function diluxone_users_login_request(): void {
	$redirect = diluxone_users_login_url();

	if ( ! isset( $_POST['diluxone_users_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_nonce'] ) ), 'diluxone_users_login' ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', $redirect ) );
		exit;
	}

	// A site that signs in with a password only does not mail links, and a
	// request made by hand to this address gets what the missing form would
	// have given it: nothing.
	if ( ! diluxone_users_login_has_link() ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', $redirect ) );
		exit;
	}

	// An e-mail address and nothing else: WordPress signs in by its username,
	// which here is the address, and a second name to type would only be a
	// second way to land on somebody else's account.
	$typed = sanitize_text_field( wp_unslash( $_POST['diluxone_users_email'] ?? '' ) );
	$email = sanitize_email( $typed );

	// Everything that follows ends on the same screen, account or no account.
	// Nor does the address travel in the URL, where access logs, caches and
	// analytics would keep it: it goes in a short cookie only this browser
	// reads.
	$done = add_query_arg( 'diluxone-users', 'sent', $redirect );

	if ( '' === $email || ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'email', $redirect ) );
		exit;
	}

	diluxone_users_sent_to( $email );

	// The inbox, not its spelling: `Ana@…` and `ana@…` are one inbox to wait for.
	$throttle = 'diluxone_users_throttle_' . md5( strtolower( $email ) );

	/*
	 * The machine first, and the address after.
	 *
	 * The per-address wait below is the one a person meets: it stops the same
	 * inbox being written to twice in a minute. It was also the only one, and
	 * as a defence it was the wrong shape — a script holding a list of five
	 * hundred members' addresses gets a fresh key on every line of it, and the
	 * site mails five hundred sign-in links a minute, signed by its own
	 * domain, until its sending reputation is gone. What a script cannot get a
	 * fresh one of is the machine it is typing from.
	 *
	 * It sits before the address check so that a run of different addresses
	 * from one place is counted as the one thing it is.
	 */
	if ( ! diluxone_users_ip_burst( 'link', diluxone_users_login_burst() ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	// Per network, like the count above: one inbox is one inbox on every site.
	if ( get_site_transient( $throttle ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	set_site_transient( $throttle, 1, max( 1, (int) diluxone_users_option( 'diluxone_users_login_throttle' ) ) );

	// An address nobody has seen before is a sign-up about to be mailed, an
	// account once its link is opened, and accounts are counted per machine, on the same count the
	// registration form uses: a script typing a new address each time gets
	// a new throttle key each time, and only the count per machine stops it
	// — from filling the users table, and from mailing a link to every
	// address it types with the site as the sender. The answer is the same
	// as always: the form never says whether anything happened.
	if ( ! get_user_by( 'email', $email ) && diluxone_users_option( 'diluxone_users_login_register' ) && ! diluxone_users_register_allowed() ) {
		wp_safe_redirect( $done );
		exit;
	}

	$user_id = diluxone_users_user_for( $email );

	if ( $user_id > 0 ) {
		$token = diluxone_users_token_create( $user_id );

		// Where the sign-in page was opened for goes with the token, on the
		// server: the link itself never says where it lands, so nobody can
		// mail somebody a link that signs them in and sends them elsewhere.
		$back = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? (string) wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), '' ) : '';

		if ( '' !== $back ) {
			update_user_meta( $user_id, DILUXONE_USERS_META_RETURN, $back );
		} else {
			delete_user_meta( $user_id, DILUXONE_USERS_META_RETURN );
		}

		diluxone_users_login_send( $user_id, $email, $token );
	} elseif ( diluxone_users_link_registers() && diluxone_users_network_takes_accounts() ) {
		// No account is created here: the link mailed is a pending sign-up,
		// and the account is created when it is opened
		// (diluxone_users_signup_consume()), since opening it is what proves
		// the address belongs to whoever typed it.
		$signup = diluxone_users_signup_create( $email );
		diluxone_users_signup_send( $email, $signup['id'], $signup['token'] );
	}

	wp_safe_redirect( $done );
	exit;
}
add_action( 'admin_post_nopriv_diluxone_users_link_request', 'diluxone_users_login_request' );
add_action( 'admin_post_diluxone_users_link_request', 'diluxone_users_login_request' );

/**
 * Consumes the link: validates, signs in and burns the token.
 */
function diluxone_users_login_consume(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token IS the credential.
	if ( ! isset( $_GET['diluxone_users_login'], $_GET['diluxone_users_token'] ) ) {
		return;
	}

	$user_id = absint( wp_unslash( $_GET['diluxone_users_login'] ) );
	$token   = sanitize_text_field( wp_unslash( $_GET['diluxone_users_token'] ) );
	// phpcs:enable

	// A link mailed while the e-mail link was a way in is not one once the
	// site turned it off: what an administrator turns off disappears, links
	// already in somebody's inbox included. It is not spent here — whoever
	// opens a link does not get to spend somebody else's — so it works again
	// if the link is turned back on before it runs out.
	if ( ! diluxone_users_login_has_link() || $user_id <= 0 || '' === $token || ! diluxone_users_token_valid( $user_id, $token ) || ! diluxone_users_token_burn( $user_id ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'expired', diluxone_users_login_url() ) );
		exit;
	}

	/**
	 * Where the person goes after coming in through the link.
	 *
	 * This is the point where a site sends them to fill in their profile the
	 * first time.
	 *
	 * @param string $redirect
	 * @param int    $user_id
	 */
	$back = (string) wp_validate_redirect( (string) get_user_meta( $user_id, DILUXONE_USERS_META_RETURN, true ), '' );

	delete_user_meta( $user_id, DILUXONE_USERS_META_RETURN );

	// The address asked for is where the link lands by default, so the
	// filter still has its say: on a network the hub sends the person back to
	// the site they came from and makes them a member there.
	$redirect = (string) apply_filters( 'diluxone_users_login_redirect', '' !== $back ? $back : home_url( '/' ), $user_id );

	// The session is not opened here: diluxone_users_complete_login() opens it,
	// and also decides whether a second factor has to be asked for first. Every
	// door ends in the same function on purpose.
	diluxone_users_complete_login( $user_id, 'link', true, $redirect );
}
add_action( 'init', 'diluxone_users_login_consume' );

/**
 * Consumes the link of a pending sign-up: validates it, spends it, creates the
 * account and signs it in.
 *
 * The doors are asked again, not remembered from when the link was mailed:
 * an administrator who stops the link creating accounts, or a network that
 * stops taking them, closes the links already in somebody's inbox too.
 */
function diluxone_users_signup_consume(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token IS the credential.
	if ( ! isset( $_GET['diluxone_users_signup'], $_GET['diluxone_users_token'] ) || ! is_string( $_GET['diluxone_users_signup'] ) || ! is_string( $_GET['diluxone_users_token'] ) ) {
		return;
	}

	$id    = sanitize_key( wp_unslash( $_GET['diluxone_users_signup'] ) );
	$token = sanitize_text_field( wp_unslash( $_GET['diluxone_users_token'] ) );
	// phpcs:enable

	$expired = add_query_arg( 'diluxone-users', 'expired', diluxone_users_login_url() );

	if ( ! diluxone_users_link_registers() || ! diluxone_users_network_takes_accounts() ) {
		wp_safe_redirect( $expired );
		exit;
	}

	$email = diluxone_users_signup_take( $id, $token );

	if ( '' === $email ) {
		wp_safe_redirect( $expired );
		exit;
	}

	// Somebody may have registered the address in the meantime, through any
	// door: then it is theirs, and the link they just proved signs them in.
	$user_id = diluxone_users_user_for( $email );

	if ( $user_id <= 0 ) {
		$user_id = diluxone_users_create_account( $email );
	}

	if ( $user_id <= 0 ) {
		wp_safe_redirect( $expired );
		exit;
	}

	/** This filter is documented in diluxone_users_login_consume(). */
	$redirect = (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user_id );

	diluxone_users_complete_login( $user_id, 'link', true, $redirect );
}
add_action( 'init', 'diluxone_users_signup_consume' );
