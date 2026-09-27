<?php
/**
 * The plugin settings, with their default values in a single place.
 *
 * Everything that in other plugins is a constant or a number written into the
 * code lives here and is edited from the admin.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default values. The key is the option name, with its prefix.
 *
 * @return array<string, mixed>
 */
function diluxone_users_option_defaults(): array {
	return array(
		// ── Passwordless sign-in ──────────────────────────────────────
		// How people get into this site:
		// 'link'     the e-mail link only; the username-and-password form of
		// wp-login.php is closed.
		// 'password' username and password only, the usual WordPress one.
		// 'both'     both of them, one below the other.
		'diluxone_users_login_method'          => 'both',
		// Page holding the sign-in form (the [diluxone_users_login] shortcode,
		// or whichever one the site puts there). At 0, wp-login.php is used.
		'diluxone_users_login_page'            => 0,
		// Minutes the e-mail link is good for.
		'diluxone_users_login_expiry'          => 15,
		// Seconds between two requests for the same e-mail address.
		'diluxone_users_login_throttle'        => 60,
		// Create the account when the e-mail does not exist. Turned off, the
		// link only works for someone already registered.
		'diluxone_users_login_register'        => 0,
		// The site's own registration form, on its own page. One of the
		// doors into an account, beside the e-mail link and the social ones:
		// they are independent, and a site can open any of them together.
		'diluxone_users_register_form'         => 0,
		// Role of the accounts created that way.
		'diluxone_users_login_role'            => 'subscriber',
		'diluxone_users_login_subject'         => '',
		'diluxone_users_login_body'            => '',

		// ── Session length ────────────────────────────────────────────
		'diluxone_users_session_long_days'     => 30,  // With "remember me".
		'diluxone_users_session_short_days'    => 2,   // Without "remember me".

		// ── Social login ──────────────────────────────────────────────
		// If the e-mail the network returns already exists on the site, that
		// account belongs to the same person and they are linked. It is what
		// makes signing in with Google today and GitHub tomorrow one account.
		'diluxone_users_sso_link_by_email'     => 1,
		// Create a new account when the e-mail does not exist.
		// The page holding the [diluxone_users_register] shortcode.
		'diluxone_users_register_page'         => 0,

		// ── How the ways in are arranged on the screen ───────────────
		// Stacked one under the other, behind tabs, or counted: 'auto' is
		// stacked while there are two ways in and tabs from three, because
		// that is where the column outgrows the laptop it is read on.
		'diluxone_users_login_layout'          => 'auto',
		// The order the site dragged them into, as ids. It is the order of
		// the tabs and, stacked, the order down the page — which is what
		// makes it worth having in both arrangements. Empty means the order
		// each way in asked for when it registered.
		'diluxone_users_login_order'           => array(),
		// Which tab opens for somebody this site has never seen. After that
		// it is whichever one they used last, which the browser remembers.
		'diluxone_users_login_open'            => '',
		// What the registration form says, in the site's words.
		'diluxone_users_register_title'        => '',
		'diluxone_users_register_intro'        => '',
		'diluxone_users_register_done'         => '',

		// ── What the sign-in screen looks like ───────────────────────
		// The shape of the page the form sits on. 'plain' leaves it where the
		// theme put it, which is what a site with its own design wants;
		// the other three take over the page, which is what a site that has
		// not designed one wants and would otherwise install a second plugin
		// to get.
		'diluxone_users_login_template'        => 'plain',
		// Where the picture goes in the split layout, and which picture.
		'diluxone_users_login_side'            => 'left',
		'diluxone_users_login_image'           => 0,
		'diluxone_users_login_logo'            => 0,
		// What the split layout's panel says. Half a window of photograph is
		// half a window saying nothing, and every site that wanted a sentence
		// on it had to copy a page template to get one. Empty is the answer
		// that was there before: the picture on its own.
		// The title takes a line per line, because a heading on a panel like
		// this is written as two or three deliberate lines and not as a
		// sentence broken wherever the column happens to end.
		'diluxone_users_login_panel_title'     => '',
		'diluxone_users_login_panel_text'      => '',
		// One advantage per line.
		'diluxone_users_login_panel_points'    => '',
		'diluxone_users_login_panel_foot'      => '',
		// The mark on the panel, which is rarely the same file as the mark
		// above the form: this one sits on a block of colour and usually has
		// to be the light version of it.
		'diluxone_users_login_panel_logo'      => 0,

		// ── The black bar across the top ─────────────────────────────
		// 'wp' leaves it exactly as WordPress shows it. 'hide' takes it away
		// from the people chosen below — never from whoever can edit users,
		// who needs the way back into the dashboard.
		'diluxone_users_admin_bar'             => 'wp',
		'diluxone_users_admin_bar_scope'       => 'all',
		'diluxone_users_admin_bar_roles'       => array(),
		// Whoever can edit users keeps the toolbar whatever is chosen above.
		// An option and not a rule hidden in code: hiding the toolbar locks
		// nobody out — /wp-admin stays open — so there is no reason to fix
		// it, and every reason to show it and let the default be "yes".
		'diluxone_users_admin_bar_keep_admins' => 1,
		// And whether its user menu points at the account area on the site
		// instead of at /wp-admin/profile.php.
		'diluxone_users_bar_account'           => 0,

		/*
		 * What happens to wp-login.php and to WordPress's own registration.
		 * 'auto' — taken over only while "only a link" is the way in, which is
		 *          what the plugin always did and stays the default.
		 * 'mine' — always taken over: the site's pages are the doors.
		 * 'wp'   — left alone, and the admin says two doors will be open.
		 */
		'diluxone_users_wp_screens'            => 'auto',

		// ── wp-login.php, the screen WordPress brings ────────────────
		// Even a site that sends everybody to its own page still shows this
		// one: an administrator coming in through the emergency door, and
		// anybody finishing a password reset, both land here. WordPress's grey
		// box with its own logo on it is the kind of detail that makes a
		// person wonder whether the site is the one it says it is.
		'diluxone_users_wp_login_brand'        => 0,
		'diluxone_users_wp_login_logo'         => 0,
		'diluxone_users_wp_login_bg'           => '',
		// Where somebody who forgot their password ends up choosing a new one.
		// 'wp'   — WordPress's own screen, at wp-login.php.
		// 'site' — the site's own, on the sign-in page: the link WordPress
		// builds still goes to wp-login.php, and is sent on from
		// there, because WordPress offers no filter for that address.
		// 'link' — nowhere: "I forgot my password" leads to the sign-in page,
		// they get in with the e-mail link and the password is left
		// exactly as it was. Only worth it where the link is a way in.
		'diluxone_users_lost_password'         => 'wp',

		// ── What the sign-in screen says ──────────────────────────────
		// Empty means the plugin's own wording. A site that wants to greet
		// people in its own voice — or that has to print a line about its
		// terms, which is not optional for anybody creating accounts —
		// should not have to copy a template to do it.
		'diluxone_users_login_title'           => '',
		'diluxone_users_login_intro'           => '',
		'diluxone_users_login_legal'           => '',
		'diluxone_users_sent_title'            => '',
		'diluxone_users_sent_note'             => '',

		'diluxone_users_sso_register'          => 0,
		// Whether the buttons show on the sign-in form at all. Separate from
		// registering with them: a site can let the people who already linked
		// an account keep using it while it stops handing out new ones, and a
		// site can want the opposite.
		'diluxone_users_sso_login'             => 1,
		// Require the provider to say the e-mail is verified.
		'diluxone_users_sso_verified_only'     => 0,
		// Who may sign in with a social account: everybody, or only the
		// roles ticked. Said the other way round until now — a list of roles
		// that could NOT — which is the only question on these screens
		// answered by the negative, and the one place the reader had to work
		// out what an empty list meant. `all` / `some` plus the roles is the
		// shape the second step and the dashboard profile already use, so it
		// is the same control and the same answer everywhere.
		'diluxone_users_sso_scope'             => 'all',
		'diluxone_users_sso_roles'             => array(),

		/* How the network buttons look. */
		'diluxone_users_sso_button_skin'       => 'brand',
		'diluxone_users_sso_button_shape'      => 'rounded',
		'diluxone_users_sso_button_show'       => 'icon-text',
		// Empty means the default text, which also translates itself.
		'diluxone_users_sso_button_text'       => '',
		'diluxone_users_sso_button_columns'    => 2,

		// ── The person in the site's menu ─────────────────────────────
		// The menu location (of the active theme) that gets "Sign in" or the
		// signed-in person as its last item. Empty: none.
		'diluxone_users_menu_location'         => '',
		// How the signed-in person shows there: 'avatar-name', 'avatar' or 'name'.
		'diluxone_users_menu_style'            => 'avatar-name',

		// ── The account area ──────────────────────────────────────────
		// The page holding the [diluxone_users_account] shortcode. With that
		// declared in a single place, everyone who needs to send somebody to
		// "my account" — LifterLMS, bbPress, a certificate — points right.
		'diluxone_users_account_page'          => 0,
		// The account area's starting point. 'plain' is the area as a panel in
		// the page, the way a settings screen looks; 'cover' is the header as
		// a band the full width of the window with the person on it and the
		// menu in a bar of its own underneath, the way a profile looks. It is
		// a starting point and not a lid: every piece below can still be
		// changed afterwards.
		'diluxone_users_account_template'      => 'plain',
		// Where the navigation goes: on top, down the side, or nowhere at all
		// because the site places it with [diluxone_users_account_nav].
		'diluxone_users_account_layout'        => 'tabs',
		// And what the menu looks like, which is a different question from
		// where it goes and from which header the area has. It used to come
		// out of the template — the cover quietly turned the pills into
		// underlined tabs — and a look that changes as a side effect of a
		// choice about the header is a look nobody can predict.
		'diluxone_users_account_nav_style'     => 'pills',
		// And where along the strip it sits. Three answers and not a number:
		// a menu is at one end, in the middle, or at the other end, and
		// anything between those is a menu that looks misplaced.
		'diluxone_users_account_nav_align'     => 'start',
		// The front page with avatar, name and member-since date.
		'diluxone_users_account_header'        => 1,
		// And what that header is made of. They are separate options and not
		// one "style" because a site wanting the big cover without the join
		// date should not have to copy a template to get it.
		'diluxone_users_account_avatar'        => 1,
		'diluxone_users_account_since'         => 1,
		// The button on the far side of the header, to their own details.
		'diluxone_users_account_action'        => 0,
		// The colour the whole area sits on. Empty means the plugin says
		// nothing and the page's own ground shows through, which is what it
		// always did. It is asked because white cards on white are not cards:
		// a site whose page is white needs a shade behind the area for the
		// boxes in it to have an edge at all.
		'diluxone_users_account_ground'        => '',
		// The colour behind the cover. Empty means the accent colour, so a
		// site that only changes its accent gets a cover that matches without
		// setting a second colour that would then drift from the first.
		'diluxone_users_account_cover'         => '',
		// How wide the content runs: held to a reading column, or the whole
		// width the theme gives it.
		'diluxone_users_account_width'         => 'contained',
		// The configuration of each section: whether it is on, what it is
		// called, what order it goes in, and the site's own added sections. It
		// is a list because the saving treats it separately.
		'diluxone_users_account_sections'      => array(),
		// The summary cards the front page has been told NOT to show. Stored
		// the other way round from the screen on purpose: a card that appears
		// tomorrow because a plugin was installed shows up by itself, instead
		// of waiting for somebody to remember to tick it.
		'diluxone_users_home_cards_off'        => array(),

		// ── The public name ───────────────────────────────────────────
		// The e-mail is the identity and is not chosen; this is the short name
		// the person appears under and that goes in their profile URL.
		'diluxone_users_handle_enabled'        => 0,
		// Besides the e-mail, the sign-in link can be requested by typing the
		// public name. The link still goes to the account's e-mail address.
		'diluxone_users_handle_login'          => 0,
		'diluxone_users_handle_min'            => 3,
		'diluxone_users_handle_max'            => 30,
		// 'strict' = a-z 0-9 . _ - ; 'unicode' accepts accents and ñ.
		'diluxone_users_handle_charset'        => 'strict',
		// What to do with spaces: 'dash' turns them into hyphens — an address
		// cannot contain spaces — or 'reject' refuses them and says so.
		'diluxone_users_handle_spaces'         => 'dash',
		// Days to wait between one change and the next. 0 = no wait; a name
		// that changes every day identifies nobody.
		'diluxone_users_handle_cooldown'       => 30,
		'diluxone_users_handle_reserved'       => '',

		// ── The profile picture ───────────────────────────────────────
		// Let the person upload their own.
		'diluxone_users_avatar_upload'         => 1,
		// If they uploaded none, go and fetch it from Gravatar. Turned off, no
		// request is made to a third party with anybody's e-mail hash.
		'diluxone_users_avatar_gravatar'       => 1,
		// And if there is neither: the initials over the accent colour.
		'diluxone_users_avatar_initials'       => 1,
		'diluxone_users_avatar_max_kb'         => 2048,

		// ── Appearance ────────────────────────────────────────────────
		// The plugin stylesheet. Off, the site styles the diluxone-users-* classes.
		'diluxone_users_styles'                => 1,
		// Where the colours come from: 'own' the ones below, 'theme' the
		// palette the active theme publishes in its theme.json. The second is
		// what stops a site from having to write a stylesheet that repoints
		// every token by hand and then goes stale.
		'diluxone_users_colors'                => 'own',
		// Which colour of that palette plays which part. Empty means whatever
		// the slugs suggested; a part left empty here is left to the plugin.
		'diluxone_users_color_map'             => array(),
		// The two values that change everything else, because the rest of the
		// sheet derives from them. Empty = the ones the sheet ships with.
		'diluxone_users_style_accent'          => '',
		'diluxone_users_style_radius'          => '',
		// How thick a control's edge is, and how tall it is. They were tokens
		// in the sheet with no way to ask for them from here, which is how a
		// site ends up writing a rule for every input it owns.
		'diluxone_users_style_border'          => '',
		'diluxone_users_style_control'         => '',
		// What a button looks like: filled with the accent, an outline, or the
		// quiet one. It travels as properties and not as a class, so it is the
		// same answer on every screen that has a button on it.
		'diluxone_users_button_style'          => 'solid',
		// The icon inside the two doors — the link by e-mail and the passkey.
		'diluxone_users_button_icons'          => 0,
		// And the one on the screen that says the e-mail is on its way: on its
		// own, or in a circle of washed colour.
		'diluxone_users_sent_icon'             => 'plain',
		// A notice: a bar down its left, or a soft filled box.
		'diluxone_users_notice_style'          => 'bar',

		// ── The account area's cover ──────────────────────────────────
		// A colour, a picture, or a picture with the colour over it so the
		// name stays readable on top of whatever was uploaded.
		'diluxone_users_account_cover_kind'    => 'color',
		'diluxone_users_account_cover_image'   => 0,
		// What the menu does on a phone when it does not fit: scrolls
		// sideways, or wraps and shows every section at once.
		'diluxone_users_account_nav_small'     => 'scroll',
		// How the area's three rows line up with the rest of the site: the
		// width they are held to and the gutter inside them. Empty means the
		// plugin says nothing about it and whatever the theme does stands —
		// which is why they are empty and not zero. A zero would be an answer,
		// and an answer printed into the stylesheet beats a site that had
		// already lined these rows up itself.
		'diluxone_users_account_row_w'         => '',
		'diluxone_users_account_row_pad'       => '',
		'diluxone_users_account_body_pad'      => '',
		// The margin around the menu inside its strip, on the four sides, and
		// the gap between the strip and the content under it. Empty means the
		// plugin's own — 10, 10, 0, 0 and 28 — which are the numbers that
		// make every menu shape sit the same way in its strip.
		'diluxone_users_account_nav_top'       => '',
		'diluxone_users_account_nav_bottom'    => '',
		'diluxone_users_account_nav_left'      => '',
		'diluxone_users_account_nav_right'     => '',
		'diluxone_users_account_bar_gap'       => '',

		// ── E-mail notices ────────────────────────────────────────────
		// What the site does with each notice it can send: on by default
		// and the person can turn it off, off by default and they can turn
		// it on, always sent, or never. Keyed by the notice's key; a notice
		// not in the map is 'default_on', which is what it always did.
		'diluxone_users_notice_rules'          => array(),

		// ── Second factor ─────────────────────────────────────────────
		// 'off' is never asked; 'optional' only of whoever turned it on;
		// 'required' of everybody who can use it.
		'diluxone_users_2fa_mode'              => 'optional',
		// The methods this site offers. Empty is equivalent to off.
		'diluxone_users_2fa_methods'           => array( 'totp', 'email' ),
		// Roles it is asked of. Empty = everybody.
		'diluxone_users_2fa_scope'             => 'all',
		'diluxone_users_2fa_roles'             => array(),
		// What to do when somebody comes in by e-mail link:
		// 'auto'   ask only if the second step is NOT another e-mail. A code
		// to the same inbox that was just opened proves nothing
		// new; an authenticator app does.
		// 'always' always ask.
		// 'never'  never ask.
		'diluxone_users_2fa_link'              => 'auto',
		// Days a browser that already passed the challenge is remembered. 0 = never.
		'diluxone_users_2fa_remember_days'     => 30,

		// ── Passkeys ──────────────────────────────────────────────────
		// ── Privacy ─────────────────────────────────────────────────
		// What somebody can do with their own data without asking anyone.
		// Both come turned on: it is the right thing, and a site that would
		// rather handle those requests by hand turns them off.
		'diluxone_users_privacy_export'        => 1,
		'diluxone_users_privacy_delete'        => 1,

		// ── Behind a proxy ────────────────────────────────────────────
		// The ONE header the site's proxy writes the client IP in (a
		// $_SERVER key). Empty means X-Forwarded-For. It is only believed
		// when the connection itself came from a trusted proxy: a header
		// anybody can type is not an address.
		'diluxone_users_ip_header'             => '',
		// Proxies beyond the private network whose headers are believed —
		// a CDN's edge, a load balancer elsewhere — one address or CIDR per
		// line. Private and loopback ranges are always trusted.
		'diluxone_users_trusted_proxies'       => '',

		// ── Sessions ────────────────────────────────────────────────
		// Whether each person sees where their sessions are open and can close them.
		'diluxone_users_sessions_show'         => 1,

		'diluxone_users_passkey_enabled'       => 0,
		// 'device' only the key of the device in use; 'any' also the ones from
		// outside — a USB key, or the phone scanning a QR code.
		'diluxone_users_passkey_where'         => 'any',
		// Require verifying who they are on top: fingerprint, face or PIN.
		'diluxone_users_passkey_verify'        => 1,

		// What happens when somebody opens the WordPress dashboard profile:
		// 'allow' nothing, 'redirect' sends them to the site account area,
		// 'block' tells them no. It never reaches whoever administers.
		'diluxone_users_wp_profile'            => 'allow',
		'diluxone_users_wp_profile_scope'      => 'all',
		'diluxone_users_wp_profile_roles'      => array(),

		// ── The activity log ──────────────────────────────────────────
		// Which groups of events get a row in the plugin's own table. A fresh
		// install records the way in and the way out and nothing else: a plugin
		// that starts writing down every change a person makes has decided
		// something about somebody's disk, and about somebody's privacy, that
		// was not its to decide. The other groups are ticked by hand.
		'diluxone_users_log_levels'            => array( 'access' ),
		// Days a row is kept before the daily purge drops it. 0 keeps
		// everything for ever, which is the answer with no end to it.
		'diluxone_users_log_days'              => 90,

		// ── Deleting the plugin ───────────────────────────────────────
		// Whether removing the plugin also removes everything it wrote.
		// Off, because most of what it wrote is not the plugin's: it is
		// people's names, phone numbers and dates of birth, and their
		// passkeys and second factors. A plugin deleted by accident, or
		// deleted to be reinstalled, must not be the thing that loses
		// them. Whoever really wants it all gone ticks this first.
		'diluxone_users_uninstall_wipe'        => 0,
	);
}

/**
 * Whether a setting applies to everybody or only to some roles.
 *
 * The answer used to be inferred from an empty list of ticked roles, which is
 * the kind of thing only the person who wrote it knows: a screen full of
 * unticked boxes reads as "nobody", and it meant "everybody".
 *
 * Sites configured before the choice existed have no answer stored, so it is
 * derived from what they ticked: roles ticked means they meant some.
 *
 * @param string $prefix Option prefix, e.g. 'diluxone_users_2fa'.
 * @return string 'all' or 'some'
 */
function diluxone_users_scope( string $prefix ): string {
	$stored = get_option( $prefix . '_scope', '' );

	if ( 'all' === $stored || 'some' === $stored ) {
		return $stored;
	}

	return array() === (array) diluxone_users_option( $prefix . '_roles' ) ? 'all' : 'some';
}

/**
 * Does this setting reach this person?
 *
 * @param int    $user_id Who is being checked.
 * @param string $prefix  Option prefix, e.g. 'diluxone_users_2fa'.
 */
function diluxone_users_scope_includes( int $user_id, string $prefix ): bool {
	if ( 'all' === diluxone_users_scope( $prefix ) ) {
		return true;
	}

	$roles = (array) diluxone_users_option( $prefix . '_roles' );

	if ( array() === $roles ) {
		// "Some" with nothing chosen reaches nobody. That is the literal
		// reading, and the screen says as much rather than quietly meaning
		// everybody like the old empty list did.
		return false;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return false;
	}

	// A super admin administers every site of the network, member or not, and
	// is in scope wherever administrators are.
	$has = (array) $user->roles;

	if ( is_multisite() && is_super_admin( $user_id ) ) {
		$has[] = 'administrator';
	}

	return array() !== array_intersect( $roles, $has );
}

/**
 * One setting, with its default value.
 *
 * @param string $key      Option name, with prefix.
 * @param mixed  $fallback Value when there is neither option nor default.
 * @return mixed
 */
function diluxone_users_option( string $key, $fallback = null ) {
	$defaults = diluxone_users_option_defaults();
	$value    = get_option( $key, null );

	if ( null === $value ) {
		$value = $defaults[ $key ] ?? $fallback;
	}

	/**
	 * Filters one plugin setting.
	 *
	 * @param mixed  $value Resolved value.
	 * @param string $key   Option name.
	 */
	return apply_filters( 'diluxone_users_option', $value, $key );
}

/**
 * Is this setting being forced from code by the site?
 *
 * A site's own plugin can pin a value through the `diluxone_users_option`
 * filter — because there it is not an option but how things work. When that happens, the admin control saves and changes
 * nothing, which is exactly the kind of lie to avoid on a settings screen.
 * With this it can be shown next to the control.
 */
function diluxone_users_option_forced( string $key ): bool {
	$defaults = diluxone_users_option_defaults();
	$stored   = get_option( $key, null );
	$stored   = null === $stored ? ( $defaults[ $key ] ?? null ) : $stored;

	return diluxone_users_option( $key ) !== $stored;
}

/**
 * Who is pinning a setting from code.
 *
 * "Something on the site decided this" helps nobody: whoever reads that wants
 * to go and remove it, and does not know where. Here the file and the
 * function come out, which is what it takes to find it. Everything hooked to
 * the filter is listed because any of them could be the one that wins; which
 * one it is, opening the file will say.
 *
 * @return array<int, string>
 */
function diluxone_users_option_forced_by(): array {
	global $wp_filter;

	if ( ! isset( $wp_filter['diluxone_users_option'] ) ) {
		return array();
	}

	$who = array();

	foreach ( $wp_filter['diluxone_users_option']->callbacks as $hooked ) {
		foreach ( $hooked as $hook ) {
			$fn = $hook['function'];

			if ( ! is_string( $fn ) || ! function_exists( $fn ) ) {
				continue;
			}

			try {
				$file = (string) ( new ReflectionFunction( $fn ) )->getFileName();
			} catch ( ReflectionException $e ) {
				continue;
			}

			$who[] = sprintf(
				'%s() — %s',
				$fn,
				diluxone_users_file_label( $file )
			);
		}
	}

	return $who;
}

/** The link lifetime in minutes, clamped to something reasonable. */
function diluxone_users_login_expiry(): int {
	return max( 1, min( 1440, (int) diluxone_users_option( 'diluxone_users_login_expiry' ) ) );
}

/**
 * The URL of the site's sign-in screen.
 *
 * With no page configured it falls back to wp-login.php, which is where
 * WordPress expects to send somebody who is not signed in: a plugin cannot
 * leave a site with no door.
 */
function diluxone_users_login_url(): string {
	$id  = (int) diluxone_users_option( 'diluxone_users_login_page' );
	$url = $id > 0 ? (string) get_permalink( $id ) : '';

	if ( '' === $url ) {
		$url = wp_login_url();
	}

	/**
	 * Filters the URL of the sign-in screen.
	 *
	 * @param string $url
	 */
	return apply_filters( 'diluxone_users_login_url', $url );
}

/**
 * Saves the settings arriving from an admin screen.
 *
 * @param array<string, mixed> $input
 */
function diluxone_users_save_options( array $input ): void {
	$defaults = diluxone_users_option_defaults();

	foreach ( $input as $key => $value ) {
		if ( ! array_key_exists( $key, $defaults ) ) {
			continue;
		}

		$default = $defaults[ $key ];

		if ( is_int( $default ) ) {
			update_option( $key, (int) $value );
			continue;
		}

		// A list of keys — the blocked roles, the second-factor methods. It is
		// stored sanitised element by element and with no stray indexes.
		if ( is_array( $default ) ) {
			$clean = array();

			foreach ( (array) $value as $one_key => $one ) {
				// A map keeps its keys; a list does not have any worth
				// keeping. Both arrive here and both have to come out
				// sanitised, which is why the key is looked at rather than
				// assumed.
				if ( is_string( $one_key ) ) {
					$clean[ sanitize_key( $one_key ) ] = sanitize_key( (string) $one );
					continue;
				}

				$clean[] = sanitize_key( (string) $one );
			}

			update_option( $key, array() === $clean || isset( $clean[0] ) ? array_values( array_unique( $clean ) ) : $clean );
			continue;
		}

		update_option(
			$key,
			diluxone_users_option_allows_markup( $key )
				? wp_kses_post( (string) $value )
				: sanitize_textarea_field( (string) $value )
		);
	}
}

/**
 * Is this setting one of the few that are allowed to carry markup?
 *
 * Almost none are, and `sanitize_textarea_field()` is the right answer for
 * almost all of them. The exception is the line about the terms: the terms and
 * the privacy policy are pages, and a legal line that cannot point at them is
 * not a legal line. Its own screen already says so and already runs it through
 * `wp_kses_post()` — and then this function used to strip the link straight
 * afterwards, so the setting saved, looked right on the screen it was typed
 * on, and came out on the page as plain text. One sanitiser undoing another is
 * the kind of thing that only shows up from the front end, which is where the
 * test that found it looks.
 *
 * @param string $key Option name, with its prefix.
 */
function diluxone_users_option_allows_markup( string $key ): bool {
	/**
	 * Filters the settings whose value may hold HTML.
	 *
	 * Whatever is on this list is stored through `wp_kses_post()`, which is
	 * the same set of tags a post is allowed. Anything not on it is stored as
	 * plain text.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $keys
	 */
	$keys = (array) apply_filters(
		'diluxone_users_options_with_markup',
		array( 'diluxone_users_login_legal' )
	);

	return in_array( $key, $keys, true );
}

/**
 * A user meta that holds a list, always as an array.
 *
 * `(array) get_user_meta( $id, $key, true )` looks like the same thing and is
 * not: with no row the meta comes back as '' and `(array) ''` is `array( '' )`,
 * a list with one empty entry. Read as sessions, that was a session started on
 * 1 January 1970 — "57 years ago" on the profile of somebody who never signed in.
 *
 * @return array<mixed>
 */
function diluxone_users_meta_list( int $user_id, string $key ): array {
	$value = get_user_meta( $user_id, $key, true );

	return is_array( $value ) ? $value : array();
}

/**
 * Leaves a message for this person's next page, once.
 *
 * What went wrong in a form that redirects has to reach the page it lands
 * on, and the address is the wrong carrier: anybody can build a link whose
 * query string makes the site say anything, in its own voice. This keeps the
 * text on the server, under the person it is for, for a minute.
 */
function diluxone_users_flash_set( int $user_id, string $key, string $message ): void {
	set_transient( 'diluxone_users_flash_' . sanitize_key( $key ) . '_' . $user_id, $message, MINUTE_IN_SECONDS );
}

/** Takes the message left for this person, if any. Once read, it is gone. */
function diluxone_users_flash_take( int $user_id, string $key ): string {
	$name    = 'diluxone_users_flash_' . sanitize_key( $key ) . '_' . $user_id;
	$message = get_transient( $name );

	if ( false === $message ) {
		return '';
	}

	delete_transient( $name );

	return is_string( $message ) ? $message : '';
}

/**
 * A file named the way a person looks for it: the plugin folder and the file
 * (`my-plugin/functions.php`), never the server's absolute path, which says
 * nothing useful on a screen and something it should not say.
 */
function diluxone_users_file_label( string $file ): string {
	$relative = plugin_basename( $file );

	return 0 === strpos( wp_normalize_path( $relative ), '/' )
		? basename( dirname( $relative ) ) . '/' . basename( $relative )
		: $relative;
}
