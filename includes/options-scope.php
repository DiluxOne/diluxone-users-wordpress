<?php
/**
 * Where each stored setting belongs on a network.
 *
 * On a single site there is one answer: the site's own options table. On a
 * network every setting has to pick one of three, and the pick is not a
 * detail of storage but of meaning:
 *
 * - 'network': one value for the whole network. What decides who gets in and
 *   how safely — the second step, passkeys, how long a session lasts, which
 *   proxy to believe, the social credentials and the rules about linking an
 *   identity, the fields a person has, the log's retention. People are
 *   network-wide, and a person's second factor or the field holding their
 *   phone number cannot be a thing one site of the network has and the next
 *   one does not.
 * - 'hub': the site where people sign in, register and keep their account,
 *   and everything about how those screens look and what they say. The other
 *   sites send people there.
 * - 'site': what belongs to the site it runs on. The plugin's own
 *   bookkeeping — the version its address rules were built for, the last mail
 *   it sent — and what has to fit into that
 *   site's own theme and dashboard: the menu the account link goes in, the
 *   admin bar, the dashboard profile. Each site keeps its own.
 *
 * The map is written out key by key, with no rule working it out from a
 * prefix, because a prefix is a guess and a guess about where the social
 * credentials live is the wrong kind of guess. A setting added without a line
 * here fails the unit test that holds every default against this map.
 *
 * On a network — where the plugin only works activated for the whole network
 * — each scope is stored where it says: a network setting in the network's
 * own options, a hub setting in the hub's options table, a site setting in the
 * site's. On a single site everything stays in the site's table, as it always
 * did. The switch
 * is diluxone_users_scoped_storage_active(), and every read and write goes
 * through the helpers below, so nothing else has to know.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The scope of every setting the plugin stores.
 *
 * Every key of diluxone_users_option_defaults() is here, and so are the ones
 * stored without a default: the fields, the social credentials, the mail
 * templates, the sign-in messages and the site's bookkeeping markers.
 *
 * @return array<string, 'network'|'hub'|'site'>
 */
function diluxone_users_option_scopes(): array {
	return array(
		// ── Network: who gets in, and how safely ─────────────────────
		'diluxone_users_2fa_link'               => 'network',
		'diluxone_users_2fa_methods'            => 'network',
		'diluxone_users_2fa_mode'               => 'network',
		'diluxone_users_2fa_remember_days'      => 'network',
		'diluxone_users_2fa_roles'              => 'network',
		'diluxone_users_2fa_scope'              => 'network',
		'diluxone_users_fields'                 => 'network',
		'diluxone_users_ip_header'              => 'network',
		'diluxone_users_log_days'               => 'network',
		'diluxone_users_membership'             => 'network',
		// The additions to sites still to be made, worked through by cron.
		// See includes/membership.php.
		'diluxone_users_membership_queue'       => 'network',
		'diluxone_users_log_levels'             => 'network',
		'diluxone_users_passkey_enabled'        => 'network',
		'diluxone_users_passkey_verify'         => 'network',
		'diluxone_users_passkey_where'          => 'network',
		'diluxone_users_session_long_days'      => 'network',
		'diluxone_users_session_short_days'     => 'network',
		'diluxone_users_sessions_show'          => 'network',
		'diluxone_users_sso'                    => 'network',
		'diluxone_users_sso_link_by_email'      => 'network',
		'diluxone_users_sso_login'              => 'network',
		'diluxone_users_sso_roles'              => 'network',
		'diluxone_users_sso_scope'              => 'network',
		'diluxone_users_sso_verified_only'      => 'network',
		'diluxone_users_trusted_proxies'        => 'network',
		// The wipe takes people's data, and people are the network's: it is
		// one decision for all of them, which today uninstall.php can only
		// piece together by waiting for every site to have ticked it.
		'diluxone_users_uninstall_wipe'         => 'network',
		// The network's own bookkeeping: which shape its settings were moved
		// to, and what the move found. See includes/migrate.php.
		'diluxone_users_network_version'        => 'network',
		'diluxone_users_network_migrating'      => 'network',
		'diluxone_users_network_conflicts'      => 'network',
		'diluxone_users_network_conflicts_seen' => 'network',
		// The log is one table for the whole network, so its shape and the
		// move of the sites' old tables into it are the network's to keep
		// track of. See includes/log.php and includes/migrate-log.php.
		'diluxone_users_log_schema'             => 'network',
		'diluxone_users_log_moved'              => 'network',
		'diluxone_users_log_moving'             => 'network',
		'diluxone_users_log_kept'               => 'network',

		// ── Hub: the screens people sign in, register and live on ───
		'diluxone_users_account_action'         => 'hub',
		'diluxone_users_account_avatar'         => 'hub',
		'diluxone_users_account_bar_gap'        => 'hub',
		'diluxone_users_account_body_pad'       => 'hub',
		'diluxone_users_account_cover'          => 'hub',
		'diluxone_users_account_cover_image'    => 'hub',
		'diluxone_users_account_cover_kind'     => 'hub',
		'diluxone_users_account_ground'         => 'hub',
		'diluxone_users_account_header'         => 'hub',
		'diluxone_users_account_layout'         => 'hub',
		'diluxone_users_account_nav_align'      => 'hub',
		'diluxone_users_account_nav_bottom'     => 'hub',
		'diluxone_users_account_nav_left'       => 'hub',
		'diluxone_users_account_nav_right'      => 'hub',
		'diluxone_users_account_nav_small'      => 'hub',
		'diluxone_users_account_nav_style'      => 'hub',
		'diluxone_users_account_nav_top'        => 'hub',
		'diluxone_users_account_page'           => 'hub',
		'diluxone_users_account_row_pad'        => 'hub',
		'diluxone_users_account_row_w'          => 'hub',
		'diluxone_users_account_sections'       => 'hub',
		'diluxone_users_account_since'          => 'hub',
		'diluxone_users_account_template'       => 'hub',
		'diluxone_users_account_width'          => 'hub',
		'diluxone_users_avatar_gravatar'        => 'hub',
		'diluxone_users_avatar_initials'        => 'hub',
		'diluxone_users_avatar_max_kb'          => 'hub',
		'diluxone_users_avatar_upload'          => 'hub',
		'diluxone_users_button_icons'           => 'hub',
		'diluxone_users_button_style'           => 'hub',
		'diluxone_users_color_map'              => 'hub',
		'diluxone_users_colors'                 => 'hub',
		'diluxone_users_handle_charset'         => 'hub',
		'diluxone_users_handle_cooldown'        => 'hub',
		'diluxone_users_handle_enabled'         => 'hub',
		'diluxone_users_handle_login'           => 'hub',
		'diluxone_users_handle_max'             => 'hub',
		'diluxone_users_handle_min'             => 'hub',
		'diluxone_users_handle_reserved'        => 'hub',
		'diluxone_users_handle_spaces'          => 'hub',
		'diluxone_users_home_cards_off'         => 'hub',
		'diluxone_users_login_expiry'           => 'hub',
		'diluxone_users_login_image'            => 'hub',
		'diluxone_users_login_intro'            => 'hub',
		'diluxone_users_login_layout'           => 'hub',
		'diluxone_users_login_legal'            => 'hub',
		'diluxone_users_login_logo'             => 'hub',
		'diluxone_users_login_messages'         => 'hub',
		'diluxone_users_login_method'           => 'hub',
		'diluxone_users_login_open'             => 'hub',
		'diluxone_users_login_order'            => 'hub',
		'diluxone_users_login_page'             => 'hub',
		'diluxone_users_login_panel_foot'       => 'hub',
		'diluxone_users_login_panel_logo'       => 'hub',
		'diluxone_users_login_panel_points'     => 'hub',
		'diluxone_users_login_panel_text'       => 'hub',
		'diluxone_users_login_panel_title'      => 'hub',
		'diluxone_users_login_register'         => 'hub',
		'diluxone_users_login_role'             => 'hub',
		'diluxone_users_login_side'             => 'hub',
		'diluxone_users_login_template'         => 'hub',
		'diluxone_users_login_throttle'         => 'hub',
		'diluxone_users_login_title'            => 'hub',
		'diluxone_users_lost_password'          => 'hub',
		'diluxone_users_mail_templates'         => 'hub',
		'diluxone_users_notice_rules'           => 'hub',
		'diluxone_users_notice_style'           => 'hub',
		'diluxone_users_privacy_delete'         => 'hub',
		'diluxone_users_privacy_delete_link'    => 'hub',
		'diluxone_users_privacy_delete_when'    => 'hub',
		'diluxone_users_privacy_export'         => 'hub',
		'diluxone_users_privacy_export_file'    => 'hub',
		'diluxone_users_privacy_export_link'    => 'hub',
		'diluxone_users_privacy_export_when'    => 'hub',
		'diluxone_users_register_done'          => 'hub',
		'diluxone_users_register_form'          => 'hub',
		'diluxone_users_register_intro'         => 'hub',
		'diluxone_users_register_page'          => 'hub',
		'diluxone_users_register_title'         => 'hub',
		// What the "check your e-mail" screen says: part of signing in.
		'diluxone_users_sent_icon'              => 'hub',
		'diluxone_users_sent_note'              => 'hub',
		'diluxone_users_sent_title'             => 'hub',
		'diluxone_users_sso_button_columns'     => 'hub',
		'diluxone_users_sso_button_shape'       => 'hub',
		'diluxone_users_sso_button_show'        => 'hub',
		'diluxone_users_sso_button_skin'        => 'hub',
		'diluxone_users_sso_button_text'        => 'hub',
		// A door into an account, like the registration form beside it; the
		// credentials behind the button are the network's.
		'diluxone_users_sso_register'           => 'hub',
		'diluxone_users_style_accent'           => 'hub',
		'diluxone_users_style_border'           => 'hub',
		'diluxone_users_style_control'          => 'hub',
		'diluxone_users_style_radius'           => 'hub',
		'diluxone_users_styles'                 => 'hub',
		'diluxone_users_wp_login_bg'            => 'hub',
		'diluxone_users_wp_login_brand'         => 'hub',
		'diluxone_users_wp_login_logo'          => 'hub',
		'diluxone_users_wp_screens'             => 'hub',

		// ── Site: what fits into each site's own theme ─────────────
		// Every site of a network has its own theme, its own menus and its own
		// dashboard: where the account link goes in a menu, and what the admin
		// bar and the dashboard profile show on this site, are this site's.
		'diluxone_users_admin_bar'              => 'site',
		'diluxone_users_admin_bar_keep_admins'  => 'site',
		'diluxone_users_admin_bar_roles'        => 'site',
		'diluxone_users_admin_bar_scope'        => 'site',
		'diluxone_users_bar_account'            => 'site',
		'diluxone_users_menu_location'          => 'site',
		'diluxone_users_menu_style'             => 'site',
		'diluxone_users_wp_profile'             => 'site',
		'diluxone_users_wp_profile_roles'       => 'site',
		'diluxone_users_wp_profile_scope'       => 'site',

		// ── Site: the plugin's bookkeeping about the site it runs on ─
		'diluxone_users_mail_last'              => 'site',
		'diluxone_users_rewrite_version'        => 'site',
	);
}

/**
 * The scope of one setting: 'network', 'hub' or 'site'.
 *
 * A key the map does not know is 'site', which is where every setting lived
 * before scopes existed: an unknown key is never quietly shared with the rest
 * of a network.
 *
 * @param string $key Option name, with its prefix.
 */
function diluxone_users_option_scope( string $key ): string {
	$scope = diluxone_users_option_scopes()[ $key ] ?? 'site';

	/**
	 * Filters where one setting is stored on a network.
	 *
	 * An add-on that stores settings of its own through the plugin's helpers
	 * gives them a scope here. Anything other than 'network', 'hub' or 'site'
	 * is read as 'site'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope 'network', 'hub' or 'site'.
	 * @param string $key   Option name.
	 */
	$scope = apply_filters( 'diluxone_users_option_scope', $scope, $key );

	return in_array( $scope, array( 'network', 'hub', 'site' ), true ) ? $scope : 'site';
}

/**
 * Are settings stored where their scope says, or all on the site?
 *
 * Where their scope says on any network: the plugin only works on one when it
 * is on for the whole network (see network-gate.php). On a single site the
 * network, the hub and the site are the same place, and every setting lives in
 * the site's own table.
 */
function diluxone_users_scoped_storage_active(): bool {
	return is_multisite();
}

/**
 * The site of a network where people sign in and keep their account.
 *
 * The network's main site until a network can choose one.
 */
function diluxone_users_hub_site_id(): int {
	return (int) get_main_site_id();
}

/** Is the current site the hub, or the only site there is? */
function diluxone_users_on_the_hub(): bool {
	return ! diluxone_users_scoped_storage_active() || get_current_blog_id() === diluxone_users_hub_site_id();
}

/**
 * Runs a callback on the hub, switching to it only if this is not it.
 *
 * @param callable $callback What to do there.
 * @return mixed What the callback returned.
 */
function diluxone_users_on_hub( callable $callback ) {
	if ( diluxone_users_on_the_hub() ) {
		return $callback();
	}

	switch_to_blog( diluxone_users_hub_site_id() );
	$result = $callback();
	restore_current_blog();

	return $result;
}

/**
 * The hub's copy of one setting, read once per request from any other site.
 *
 * Reading the hub from another site is a switch of site and a query, and the
 * account area asks for forty of these on one page. So what was read is kept
 * for the rest of the request, and forgotten the moment the plugin writes it —
 * or anything writes it on the hub, see diluxone_users_hub_memo_forget().
 *
 * @param string $key    Option name.
 * @param bool   $forget Drop what is kept instead of reading it.
 * @return mixed The stored value, or null when the hub has none.
 */
function diluxone_users_hub_memo( string $key, bool $forget = false ) {
	static $memo = array();

	$hub = diluxone_users_hub_site_id();

	if ( $forget ) {
		unset( $memo[ $hub ][ $key ] );

		return null;
	}

	if ( ! isset( $memo[ $hub ] ) || ! array_key_exists( $key, $memo[ $hub ] ) ) {
		$memo[ $hub ][ $key ] = get_blog_option( $hub, $key, null );
	}

	return $memo[ $hub ][ $key ];
}

/**
 * Forgets the hub's copy of a setting written on the hub by anybody.
 *
 * The plugin's own writes forget it themselves; this is for the ones that go
 * past the helpers — a test, WP-CLI, another plugin — so that nothing in the
 * same request goes on reading what was there before.
 *
 * @param string $option Option name.
 */
function diluxone_users_hub_memo_forget( string $option ): void {
	if ( 0 === strpos( $option, 'diluxone_users_' ) && get_current_blog_id() === diluxone_users_hub_site_id() ) {
		diluxone_users_hub_memo( $option, true );
	}
}
add_action( 'added_option', 'diluxone_users_hub_memo_forget' );
add_action( 'updated_option', 'diluxone_users_hub_memo_forget' );
add_action( 'deleted_option', 'diluxone_users_hub_memo_forget' );

/**
 * One stored setting, as stored: no default filled in and no filter run.
 *
 * What diluxone_users_option() builds on, and what the few places that need
 * to tell "never written" from "written with the default" read directly.
 *
 * @param string $key      Option name, with its prefix.
 * @param mixed  $fallback Value when it was never written.
 * @return mixed
 */
function diluxone_users_raw_get( string $key, $fallback = false ) {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return get_option( $key, $fallback );
	}

	switch ( diluxone_users_option_scope( $key ) ) {
		case 'network':
			return get_site_option( $key, $fallback );
		case 'hub':
			if ( diluxone_users_on_the_hub() ) {
				return get_option( $key, $fallback );
			}

			$value = diluxone_users_hub_memo( $key );

			return null === $value ? $fallback : $value;
		default:
			return get_option( $key, $fallback );
	}
}

/**
 * Stores one setting where its scope says.
 *
 * @param string    $key      Option name, with its prefix.
 * @param mixed     $value    The value, already sanitised.
 * @param bool|null $autoload As update_option(). A network setting has none:
 *                            the network's options are loaded their own way.
 */
function diluxone_users_update_option( string $key, $value, ?bool $autoload = null ): bool {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return update_option( $key, $value, $autoload );
	}

	switch ( diluxone_users_option_scope( $key ) ) {
		case 'network':
			return update_site_option( $key, $value );
		case 'hub':
			diluxone_users_hub_memo( $key, true );

			return (bool) diluxone_users_on_hub( static fn() => update_option( $key, $value, $autoload ) );
		default:
			return update_option( $key, $value, $autoload );
	}
}

/**
 * Deletes one setting from where its scope says it lives.
 *
 * @param string $key Option name, with its prefix.
 */
function diluxone_users_delete_option( string $key ): bool {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return delete_option( $key );
	}

	switch ( diluxone_users_option_scope( $key ) ) {
		case 'network':
			return delete_site_option( $key );
		case 'hub':
			diluxone_users_hub_memo( $key, true );

			return (bool) diluxone_users_on_hub( static fn() => delete_option( $key ) );
		default:
			return delete_option( $key );
	}
}

/* ── Who writes what, from where ──────────────────────────────────── */

/**
 * Where the admin is being looked at from: single, network, hub or site.
 *
 * Asked of the request and not of the screen: an admin-post or AJAX request
 * made from a network screen is not in Network Admin as WordPress sees it,
 * which is why a save that belongs to the network is only ever drawn — and
 * only ever accepted — on the network's own screens.
 */
function diluxone_users_admin_context(): string {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return 'single';
	}

	if ( is_network_admin() ) {
		return 'network';
	}

	return diluxone_users_on_the_hub() ? 'hub' : 'site';
}

/**
 * Whether something of this scope is set from where the admin is looked at.
 *
 * @param string $scope   'network', 'hub' or 'site'.
 * @param string $context One of diluxone_users_admin_context()'s answers; the
 *                        current one when left out.
 */
function diluxone_users_admin_owns( string $scope, string $context = '' ): bool {
	$context = '' === $context ? diluxone_users_admin_context() : $context;

	switch ( $context ) {
		case 'single':
			return true;
		case 'network':
			return 'network' === $scope;
		case 'hub':
			return 'hub' === $scope || 'site' === $scope;
		default:
			return 'site' === $scope;
	}
}

/**
 * Whether one stored setting may be written from here.
 *
 * The one gate every settings screen goes through on its way to the database
 * (diluxone_users_save_options() asks it), so a site administrator who sends
 * the second-step mode by hand to their own site's screen writes nothing: the
 * form never had it, and the save does not take it.
 *
 * @param string $key Option name, with its prefix.
 */
function diluxone_users_option_editable_here( string $key ): bool {
	return diluxone_users_admin_owns( diluxone_users_option_scope( $key ) );
}

/* ── What a hub setting points at ──────────────────────────────────── */

/**
 * The page chosen for one of the plugin's roles, if it is a page of this site.
 *
 * The sign-in, registration and account pages are hub settings, and what they
 * hold is a number — the id of a page on the hub. On any other site of the
 * network the same number is some other page, or none, so here it is 0: this
 * site has no page of that role, and nothing on it is drawn or routed as if it
 * had.
 *
 * @param string $option The option holding the page, e.g. 'diluxone_users_login_page'.
 */
function diluxone_users_page_here( string $option ): int {
	return diluxone_users_on_the_hub() ? (int) diluxone_users_option( $option ) : 0;
}

/**
 * The address of the page chosen for one of the plugin's roles, wherever it is.
 *
 * Read on the hub, where the page is: from any other site of the network this
 * is the hub's page, which is where the people of the network sign in.
 *
 * @param string $option The option holding the page.
 * @return string The address, or '' when none is chosen.
 */
function diluxone_users_page_url( string $option ): string {
	$page = (int) diluxone_users_option( $option );

	return $page > 0 ? (string) diluxone_users_on_hub( static fn() => get_permalink( $page ) ) : '';
}

/**
 * A picture chosen in the admin, from the media library it was chosen in.
 *
 * The pictures on the sign-in and account screens are hub settings, so the
 * attachment is one of the hub's: its address is asked of the hub.
 *
 * @param int    $id   Attachment id, as the setting holds it.
 * @param string $size A registered image size.
 */
function diluxone_users_hub_image_url( int $id, string $size ): string {
	return $id > 0 ? (string) diluxone_users_on_hub( static fn() => wp_get_attachment_image_url( $id, $size ) ) : '';
}
