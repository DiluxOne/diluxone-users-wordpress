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
 * Does this install have passkeys at all?
 *
 * Passkeys is one feature in two files. Everything outside those two asks
 * this instead of calling into them, so an install without them gets a screen
 * with one row fewer rather than a fatal error. It is also the shape every
 * optional feature will take.
 */
function diluxone_users_has_passkeys(): bool {
	return function_exists( 'diluxone_users_passkeys_enabled' ) && diluxone_users_passkeys_enabled();
}

/**
 * The shape of the sign-in page.
 *
 * Four answers and only one of them leaves the page alone. A site with a
 * designed page wants 'plain' — the form where the theme put it. A site that
 * has not designed one wants the page taken over, which is what the other
 * three do, and is the reason a second plugin usually gets installed for it.
 */
function diluxone_users_login_template(): string {
	$template = (string) diluxone_users_option( 'diluxone_users_login_template' );

	// Checked against the shapes there are and not a list written here, or a
	// shape added by something else would never be allowed through — the
	// setting would save and then quietly not apply.
	$known = function_exists( 'diluxone_users_login_templates' )
		? array_keys( diluxone_users_login_templates() )
		: array( 'plain', 'card', 'split', 'backdrop' );

	return in_array( $template, $known, true ) && 'plain' !== $template ? $template : 'plain';
}

/**
 * A picture chosen in the admin, at a size worth serving.
 *
 * Full width because it is a background: an 'image' thumbnail stretched over
 * half a screen is the kind of detail that makes a good design look cheap.
 */
function diluxone_users_login_image( string $key ): string {
	$id = (int) diluxone_users_option( $key );

	if ( $id <= 0 ) {
		return '';
	}

	return (string) wp_get_attachment_image_url( $id, 'full' );
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
 * What the split layout's panel says, in pieces, or nothing.
 *
 * Half the window was a photograph and no more: a site that wanted a sentence
 * on it had to copy a page template, which is the sign of a missing seam
 * rather than of a site doing something unusual. Every piece is optional and
 * all of them empty means what it always meant — the picture on its own.
 *
 * The title arrives as lines because that is how a panel heading is written:
 * two or three deliberate lines, not a sentence broken wherever the column
 * happens to end. The points arrive the same way, one per line, for the same
 * reason a list is a list.
 *
 * @return array{title: array<int, string>, text: string, points: array<int, string>, foot: string, logo: string}
 */
function diluxone_users_login_panel(): array {
	return array(
		'title'  => diluxone_users_lines( (string) diluxone_users_option( 'diluxone_users_login_panel_title' ) ),
		'text'   => trim( (string) diluxone_users_option( 'diluxone_users_login_panel_text' ) ),
		'points' => diluxone_users_lines( (string) diluxone_users_option( 'diluxone_users_login_panel_points' ) ),
		'foot'   => trim( (string) diluxone_users_option( 'diluxone_users_login_panel_foot' ) ),
		'logo'   => diluxone_users_login_image( 'diluxone_users_login_panel_logo' ),
	);
}

/**
 * A textarea read as the lines somebody typed.
 *
 * Blank lines go: they are how a person separates while typing, not something
 * they meant to appear.
 *
 * @return array<int, string>
 */
function diluxone_users_lines( string $text ): array {
	$split = preg_split( '/\R/', $text );
	$lines = array_map( 'trim', false === $split ? array() : $split );

	return array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
}

/**
 * Is there anything to write on the panel at all?
 *
 * @param array{title: array<int, string>, text: string, points: array<int, string>, foot: string, logo: string} $panel
 */
function diluxone_users_login_panel_has_words( array $panel ): bool {
	return array() !== $panel['title'] || '' !== $panel['text'] || array() !== $panel['points'] || '' !== $panel['foot'] || '' !== $panel['logo'];
}

/**
 * The panel of the split layout, with whatever the site wrote on it.
 *
 * Three rows — the mark, what it says, the line at the foot — so that the
 * first and the last sit against the top and the bottom of the panel however
 * much there is in the middle. Any of them can be missing.
 *
 * @param array{title: array<int, string>, text: string, points: array<int, string>, foot: string, logo: string} $panel
 */
function diluxone_users_login_panel_words( array $panel ): void {
	echo '<div class="diluxone-users-login-frame__words">';

	if ( '' !== $panel['logo'] ) {
		printf(
			'<p class="diluxone-users-login-frame__mark"><img src="%1$s" alt="%2$s"></p>',
			esc_url( $panel['logo'] ),
			esc_attr( get_bloginfo( 'name' ) )
		);
	} else {
		// The row still has to be there, or "what it says" would be pushed to
		// the top of the panel by the space-between and the foot would be the
		// only thing holding the bottom.
		echo '<span aria-hidden="true"></span>';
	}

	echo '<div class="diluxone-users-login-frame__say">';

	if ( array() !== $panel['title'] ) {
		echo '<h2 class="diluxone-users-login-frame__title">';

		foreach ( $panel['title'] as $line ) {
			printf( '<span>%s</span>', esc_html( $line ) );
		}

		echo '</h2>';
	}

	if ( '' !== $panel['text'] ) {
		printf( '<p class="diluxone-users-login-frame__text">%s</p>', esc_html( $panel['text'] ) );
	}

	if ( array() !== $panel['points'] ) {
		echo '<ul class="diluxone-users-login-frame__points">';

		foreach ( $panel['points'] as $point ) {
			printf( '<li>%s</li>', esc_html( $point ) );
		}

		echo '</ul>';
	}

	echo '</div>';

	if ( '' !== $panel['foot'] ) {
		printf( '<p class="diluxone-users-login-frame__foot">%s</p>', esc_html( $panel['foot'] ) );
	} else {
		echo '<span aria-hidden="true"></span>';
	}

	echo '</div>';
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

	$image = diluxone_users_login_image( 'diluxone_users_login_image' );
	$side  = 'right' === (string) diluxone_users_option( 'diluxone_users_login_side' ) ? 'right' : 'left';

	// The picture travels as a custom property because it is a background in
	// every layout that has one, and a property can be repointed from a
	// stylesheet without touching any of this.
	$style = '' !== $image && in_array( $template, array( 'split', 'backdrop' ), true )
		? sprintf( ' style="--diluxone-users-login-image: url(%s)"', esc_url( $image ) )
		: '';

	/*
	 * The wrapper is not decoration: it is what makes the frame a grandchild.
	 * A block theme constrains its content with
	 * `:where(.is-layout-constrained) > :where(…)` — direct children only, and
	 * with !important on both the margin and the max-width, so nothing a child
	 * declares can break out of the column. The wrapper takes that treatment
	 * and the frame inside it is free, which is the same thing the account
	 * cover does one level down.
	 */
	echo '<div class="diluxone-users-login-page">';

	printf(
		'<div class="diluxone-users-login-frame diluxone-users-login-frame--%1$s diluxone-users-login-frame--%2$s"%3$s>',
		esc_attr( $template ),
		esc_attr( $side ),
		$style // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped just above.
	);

	if ( 'split' === $template ) {
		$panel = diluxone_users_login_panel();
		$words = diluxone_users_login_panel_has_words( $panel );

		/*
		 * With nothing written on it the panel is decoration and says so: it
		 * is hidden from screen readers and carries no alt text to invent.
		 * With words on it, it is content, and the two stop being the same
		 * element in every way except the class.
		 *
		 * A picture underneath words needs something between them or neither
		 * is readable — the same thing the backdrop layout does, for the same
		 * reason, and the same property so a site can change it once.
		 */
		printf(
			'<div class="diluxone-users-login-frame__picture%1$s%2$s"%3$s>',
			$words ? ' diluxone-users-login-frame__picture--words' : '',
			$words && '' !== $image ? ' diluxone-users-login-frame__picture--over' : '',
			$words ? '' : ' aria-hidden="true"'
		);

		if ( $words ) {
			diluxone_users_login_panel_words( $panel );
		}

		echo '</div>';
	}

	echo '<div class="diluxone-users-login-frame__box">';
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
		// The passkey: a key, because that is what everybody's operating
		// system draws on the prompt this button opens.
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
		$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup from the table above.
	);
}

/** The site's mark above the form, when there is one. */
function diluxone_users_login_logo(): void {
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

const DILUXONE_USERS_META_HASH    = '_diluxone_users_acceso_hash';
const DILUXONE_USERS_META_EXPIRES = '_diluxone_users_acceso_vence';

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
 * Finds the account for the e-mail, or creates it if the site allows it.
 *
 * This is where "registration" and "sign-in" stop being two things: the
 * account is created in the same step, with no separate form and no password
 * to choose.
 *
 * @return int User ID, or 0 when it does not exist and cannot be created.
 */
function diluxone_users_user_for( string $email ): int {
	$user = get_user_by( 'email', $email );

	if ( $user ) {
		// On a network, existing is not being a member of this site: somebody
		// coming from the site next door gets in, but with no role here they
		// can do nothing.
		diluxone_users_join_site( (int) $user->ID );

		return (int) $user->ID;
	}

	// Signing in only creates an account when that is how this site registers
	// people. With a form of its own, or with registration closed, an address
	// nobody has seen before is simply an address nobody has seen before.
	if ( ! diluxone_users_option( 'diluxone_users_login_register' ) ) {
		return 0;
	}

	return diluxone_users_create_account( $email );
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
	$link = (bool) diluxone_users_option( 'diluxone_users_login_register' );
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
	// The display name and the one in the profile URL are NOT allowed to be
	// derived from user_login, because user_login is the e-mail: WordPress
	// would build a display_name of "somebody@gmail.com" that later shows up in
	// the forums, and a user_nicename of "somebodygmail-com" that reads plainly
	// in the profile URL. The account is identified by the e-mail; publishing
	// it is another matter.
	$visible = diluxone_users_name_from_email( $email );

	// The password is generated at random and nobody knows it, not even the
	// person registering: WordPress needs the field, the site does not use it.
	$id = wp_insert_user(
		array(
			'user_login'    => $email,
			'user_email'    => $email,
			'user_pass'     => wp_generate_password( 64, true, true ),
			'display_name'  => $visible,
			// wp_insert_user() adds a suffix if it is already taken.
			'user_nicename' => sanitize_title( $visible ),
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

	return in_array( $method, array( 'link', 'password', 'both' ), true ) ? $method : 'both';
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

/** The e-mail subject, with the site's own as a fallback. */
function diluxone_users_login_subject(): string {
	$subject = trim( (string) diluxone_users_option( 'diluxone_users_login_subject' ) );

	if ( '' === $subject ) {
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your sign-in link for %s', 'diluxone-users' ), get_bloginfo( 'name' ) );
	}

	return $subject;
}

/**
 * The e-mail body. `{link}` and `{minutes}` are replaced.
 */
function diluxone_users_login_body( string $url ): string {
	$body = trim( (string) diluxone_users_option( 'diluxone_users_login_body' ) );

	if ( '' === $body ) {
		$body = __(
			"Click here to sign in:\n\n{link}\n\nThe link expires in {minutes} minutes and works once.\n\nIf you did not ask for it, ignore this message: nobody can get into your account without it.",
			'diluxone-users'
		);
	}

	return strtr(
		$body,
		array(
			'{link}'    => $url,
			'{minutes}' => (string) diluxone_users_login_expiry(),
		)
	);
}

/** Sends the e-mail with the link. */
function diluxone_users_login_send( int $user_id, string $email, string $token ): bool {
	$url = diluxone_users_login_link( $user_id, $token );

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
	$message = apply_filters(
		'diluxone_users_login_email',
		array(
			'subject' => diluxone_users_login_subject(),
			'body'    => diluxone_users_login_body( $url ),
		),
		$email,
		$url
	);

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
 * It always answers the same thing, whatever happened.
 */
function diluxone_users_login_request(): void {
	$redirect = diluxone_users_login_url();

	if ( ! isset( $_POST['diluxone_users_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_nonce'] ) ), 'diluxone_users_login' ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', $redirect ) );
		exit;
	}

	// What was typed can be an e-mail or, if the site allows it, somebody's
	// public name. In the second case it goes on with that account's e-mail:
	// the link never goes out to an address typed on the spot.
	$typed  = sanitize_text_field( wp_unslash( $_POST['diluxone_users_email'] ?? '' ) );
	$handle = ! is_email( $typed ) && diluxone_users_option( 'diluxone_users_handle_login' );
	$email  = sanitize_email( diluxone_users_handle_login_email( $typed ) );

	// Everything that follows ends on the same screen, account or no account,
	// and that screen shows what the person typed — never the address a
	// public name resolved to, which would hand anyone who knows a name the
	// e-mail behind it. Nor does the address travel in the URL, where access
	// logs, caches and analytics would keep it: it goes in a short cookie
	// only this browser reads.
	$done = add_query_arg( 'diluxone-users', 'sent', $redirect );

	if ( '' === $email || ! is_email( $email ) ) {
		// A name nobody has answers as a name somebody has: telling them
		// apart would say which names exist.
		if ( $handle && '' !== $typed ) {
			diluxone_users_sent_to( $typed );
			wp_safe_redirect( $done );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'diluxone-users', 'email', $redirect ) );
		exit;
	}

	diluxone_users_sent_to( $typed );

	$throttle = 'diluxone_users_throttle_' . md5( $email );

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

	if ( get_transient( $throttle ) ) {
		wp_safe_redirect( $done );
		exit;
	}

	set_transient( $throttle, 1, max( 1, (int) diluxone_users_option( 'diluxone_users_login_throttle' ) ) );

	// An address nobody has seen before is an account about to be created,
	// and accounts are counted per machine, on the same count the
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
		diluxone_users_login_send( $user_id, $email, diluxone_users_token_create( $user_id ) );
	}

	wp_safe_redirect( $done );
	exit;
}
add_action( 'admin_post_nopriv_diluxone_users_acceso', 'diluxone_users_login_request' );
add_action( 'admin_post_diluxone_users_acceso', 'diluxone_users_login_request' );

/**
 * Consumes the link: validates, signs in and burns the token.
 */
function diluxone_users_login_consume(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token IS the credential.
	if ( ! isset( $_GET['diluxone_users_login'], $_GET['diluxone_users_token'] ) ) {
		return;
	}

	$user_id = absint( $_GET['diluxone_users_login'] );
	$token   = sanitize_text_field( wp_unslash( $_GET['diluxone_users_token'] ) );
	// phpcs:enable

	if ( $user_id <= 0 || '' === $token || ! diluxone_users_token_valid( $user_id, $token ) || ! diluxone_users_token_burn( $user_id ) ) {
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
	$redirect = (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user_id );

	// The session is not opened here: diluxone_users_complete_login() opens it,
	// and also decides whether a second factor has to be asked for first. Every
	// door ends in the same function on purpose.
	diluxone_users_complete_login( $user_id, 'link', true, $redirect );
}
add_action( 'init', 'diluxone_users_login_consume' );
