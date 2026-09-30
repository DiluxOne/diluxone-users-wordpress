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
 * - 'site': the plugin's own bookkeeping about the site it runs on — the
 *   version its address rules were built for, the shape of its log table, the
 *   last mail it sent. Nobody configures these; each site keeps its own.
 *
 * The map is written out key by key, with no rule working it out from a
 * prefix, because a prefix is a guess and a guess about where the social
 * credentials live is the wrong kind of guess. A setting added without a line
 * here fails the unit test that holds every default against this map.
 *
 * Today nothing is routed yet: every scope is read and written on the current
 * site, exactly as before this file existed. The helpers below are where the
 * routing goes, so that turning it on is one switch
 * (diluxone_users_scoped_storage_active()) and not a hundred call sites.
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
		'diluxone_users_2fa_link'              => 'network',
		'diluxone_users_2fa_methods'           => 'network',
		'diluxone_users_2fa_mode'              => 'network',
		'diluxone_users_2fa_remember_days'     => 'network',
		'diluxone_users_2fa_roles'             => 'network',
		'diluxone_users_2fa_scope'             => 'network',
		'diluxone_users_fields'                => 'network',
		'diluxone_users_ip_header'             => 'network',
		'diluxone_users_log_days'              => 'network',
		'diluxone_users_log_levels'            => 'network',
		'diluxone_users_passkey_enabled'       => 'network',
		'diluxone_users_passkey_verify'        => 'network',
		'diluxone_users_passkey_where'         => 'network',
		'diluxone_users_session_long_days'     => 'network',
		'diluxone_users_session_short_days'    => 'network',
		'diluxone_users_sessions_show'         => 'network',
		'diluxone_users_sso'                   => 'network',
		'diluxone_users_sso_link_by_email'     => 'network',
		'diluxone_users_sso_login'             => 'network',
		'diluxone_users_sso_roles'             => 'network',
		'diluxone_users_sso_scope'             => 'network',
		'diluxone_users_sso_verified_only'     => 'network',
		'diluxone_users_trusted_proxies'       => 'network',
		// The wipe takes people's data, and people are the network's: it is
		// one decision for all of them, which today uninstall.php can only
		// piece together by waiting for every site to have ticked it.
		'diluxone_users_uninstall_wipe'        => 'network',

		// ── Hub: the screens people sign in, register and live on ───
		'diluxone_users_account_action'        => 'hub',
		'diluxone_users_account_avatar'        => 'hub',
		'diluxone_users_account_bar_gap'       => 'hub',
		'diluxone_users_account_body_pad'      => 'hub',
		'diluxone_users_account_cover'         => 'hub',
		'diluxone_users_account_cover_image'   => 'hub',
		'diluxone_users_account_cover_kind'    => 'hub',
		'diluxone_users_account_ground'        => 'hub',
		'diluxone_users_account_header'        => 'hub',
		'diluxone_users_account_layout'        => 'hub',
		'diluxone_users_account_nav_align'     => 'hub',
		'diluxone_users_account_nav_bottom'    => 'hub',
		'diluxone_users_account_nav_left'      => 'hub',
		'diluxone_users_account_nav_right'     => 'hub',
		'diluxone_users_account_nav_small'     => 'hub',
		'diluxone_users_account_nav_style'     => 'hub',
		'diluxone_users_account_nav_top'       => 'hub',
		'diluxone_users_account_page'          => 'hub',
		'diluxone_users_account_row_pad'       => 'hub',
		'diluxone_users_account_row_w'         => 'hub',
		'diluxone_users_account_sections'      => 'hub',
		'diluxone_users_account_since'         => 'hub',
		'diluxone_users_account_template'      => 'hub',
		'diluxone_users_account_width'         => 'hub',
		'diluxone_users_admin_bar'             => 'hub',
		'diluxone_users_admin_bar_keep_admins' => 'hub',
		'diluxone_users_admin_bar_roles'       => 'hub',
		'diluxone_users_admin_bar_scope'       => 'hub',
		'diluxone_users_avatar_gravatar'       => 'hub',
		'diluxone_users_avatar_initials'       => 'hub',
		'diluxone_users_avatar_max_kb'         => 'hub',
		'diluxone_users_avatar_upload'         => 'hub',
		'diluxone_users_bar_account'           => 'hub',
		'diluxone_users_button_icons'          => 'hub',
		'diluxone_users_button_style'          => 'hub',
		'diluxone_users_color_map'             => 'hub',
		'diluxone_users_colors'                => 'hub',
		'diluxone_users_handle_charset'        => 'hub',
		'diluxone_users_handle_cooldown'       => 'hub',
		'diluxone_users_handle_enabled'        => 'hub',
		'diluxone_users_handle_login'          => 'hub',
		'diluxone_users_handle_max'            => 'hub',
		'diluxone_users_handle_min'            => 'hub',
		'diluxone_users_handle_reserved'       => 'hub',
		'diluxone_users_handle_spaces'         => 'hub',
		'diluxone_users_home_cards_off'        => 'hub',
		'diluxone_users_login_expiry'          => 'hub',
		'diluxone_users_login_image'           => 'hub',
		'diluxone_users_login_intro'           => 'hub',
		'diluxone_users_login_layout'          => 'hub',
		'diluxone_users_login_legal'           => 'hub',
		'diluxone_users_login_logo'            => 'hub',
		'diluxone_users_login_messages'        => 'hub',
		'diluxone_users_login_method'          => 'hub',
		'diluxone_users_login_open'            => 'hub',
		'diluxone_users_login_order'           => 'hub',
		'diluxone_users_login_page'            => 'hub',
		'diluxone_users_login_panel_foot'      => 'hub',
		'diluxone_users_login_panel_logo'      => 'hub',
		'diluxone_users_login_panel_points'    => 'hub',
		'diluxone_users_login_panel_text'      => 'hub',
		'diluxone_users_login_panel_title'     => 'hub',
		'diluxone_users_login_register'        => 'hub',
		'diluxone_users_login_role'            => 'hub',
		'diluxone_users_login_side'            => 'hub',
		'diluxone_users_login_template'        => 'hub',
		'diluxone_users_login_throttle'        => 'hub',
		'diluxone_users_login_title'           => 'hub',
		'diluxone_users_lost_password'         => 'hub',
		'diluxone_users_mail_templates'        => 'hub',
		'diluxone_users_menu_location'         => 'hub',
		'diluxone_users_menu_style'            => 'hub',
		'diluxone_users_notice_rules'          => 'hub',
		'diluxone_users_notice_style'          => 'hub',
		'diluxone_users_privacy_delete'        => 'hub',
		'diluxone_users_privacy_delete_link'   => 'hub',
		'diluxone_users_privacy_delete_when'   => 'hub',
		'diluxone_users_privacy_export'        => 'hub',
		'diluxone_users_privacy_export_file'   => 'hub',
		'diluxone_users_privacy_export_link'   => 'hub',
		'diluxone_users_privacy_export_when'   => 'hub',
		'diluxone_users_register_done'         => 'hub',
		'diluxone_users_register_form'         => 'hub',
		'diluxone_users_register_intro'        => 'hub',
		'diluxone_users_register_page'         => 'hub',
		'diluxone_users_register_title'        => 'hub',
		// What the "check your e-mail" screen says: part of signing in.
		'diluxone_users_sent_icon'             => 'hub',
		'diluxone_users_sent_note'             => 'hub',
		'diluxone_users_sent_title'            => 'hub',
		'diluxone_users_sso_button_columns'    => 'hub',
		'diluxone_users_sso_button_shape'      => 'hub',
		'diluxone_users_sso_button_show'       => 'hub',
		'diluxone_users_sso_button_skin'       => 'hub',
		'diluxone_users_sso_button_text'       => 'hub',
		// A door into an account, like the registration form beside it; the
		// credentials behind the button are the network's.
		'diluxone_users_sso_register'          => 'hub',
		'diluxone_users_style_accent'          => 'hub',
		'diluxone_users_style_border'          => 'hub',
		'diluxone_users_style_control'         => 'hub',
		'diluxone_users_style_radius'          => 'hub',
		'diluxone_users_styles'                => 'hub',
		'diluxone_users_wp_login_bg'           => 'hub',
		'diluxone_users_wp_login_brand'        => 'hub',
		'diluxone_users_wp_login_logo'         => 'hub',
		'diluxone_users_wp_profile'            => 'hub',
		'diluxone_users_wp_profile_roles'      => 'hub',
		'diluxone_users_wp_profile_scope'      => 'hub',
		'diluxone_users_wp_screens'            => 'hub',

		// ── Site: the plugin's bookkeeping about the site it runs on ─
		'diluxone_users_log_schema'            => 'site',
		'diluxone_users_mail_last'             => 'site',
		'diluxone_users_rewrite_version'       => 'site',
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
 * Are settings stored where their scope says, or all on the current site?
 *
 * Off, and that is deliberate: this is the first half of the change. Every
 * read and write already goes through the helpers below, and each helper
 * already knows where its key belongs, but until the network has a screen for
 * its own settings and a hub to send people to, moving the values would be
 * moving them somewhere nobody can edit them. Turning this on is the second
 * half, and it needs nothing else changed.
 *
 * Off on a single site for ever: there, the network, the hub and the site are
 * the same place.
 *
 * The filter is internal, for the tests of the routing itself, and not part
 * of the public API.
 */
function diluxone_users_scoped_storage_active(): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	/**
	 * Internal. Routes the settings by scope on a network.
	 *
	 * @ignore
	 *
	 * @param bool $active
	 */
	return (bool) apply_filters( 'diluxone_users_scoped_storage', false );
}

/**
 * The site of a network where people sign in and keep their account.
 *
 * The network's main site until a network can choose one.
 */
function diluxone_users_hub_site_id(): int {
	return (int) get_main_site_id();
}

/**
 * Runs a callback on the hub, switching to it only if this is not it.
 *
 * @param callable $callback What to do there.
 * @return mixed What the callback returned.
 */
function diluxone_users_on_hub( callable $callback ) {
	$hub = diluxone_users_hub_site_id();

	if ( get_current_blog_id() === $hub ) {
		return $callback();
	}

	switch_to_blog( $hub );
	$result = $callback();
	restore_current_blog();

	return $result;
}

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
			return diluxone_users_on_hub( static fn() => get_option( $key, $fallback ) );
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
			return (bool) diluxone_users_on_hub( static fn() => delete_option( $key ) );
		default:
			return delete_option( $key );
	}
}
