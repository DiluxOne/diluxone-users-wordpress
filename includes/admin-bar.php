<?php
/**
 * The black bar across the top of the site.
 *
 * WordPress shows it to everybody who is signed in, and it is the last thing
 * on a site that still says "this is a WordPress install" to a person who
 * only came here to read, watch a course or write in a forum. Its user menu
 * also points at /wp-admin/profile.php — a screen that has nothing to do with
 * the account area the site built, and that does not know about the required
 * fields or the edit limits set up in this plugin.
 *
 * Both are left alone until somebody says otherwise, and neither ever reaches
 * whoever can edit users: that is the person who has to be able to get back
 * into the dashboard and fix what broke.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Does the bar go away for whoever is looking?
 *
 * Whoever can edit users keeps it, whatever is chosen. Taking the way into
 * the dashboard off the screen of the person who administers the site is the
 * kind of setting that gets turned on once and puzzled over for an hour.
 */
function diluxone_users_admin_bar_hidden(): bool {
	if ( 'hide' !== (string) diluxone_users_option( 'diluxone_users_admin_bar' ) ) {
		return false;
	}

	// An option, shown on the screen, and on by default: hiding the toolbar
	// locks nobody out — /wp-admin stays open — so there is no reason to fix
	// it in code, and every reason to let it be seen and turned off.
	if ( diluxone_users_option( 'diluxone_users_admin_bar_keep_admins' ) && current_user_can( 'edit_users' ) ) {
		return false;
	}

	return diluxone_users_scope_includes( get_current_user_id(), 'diluxone_users_admin_bar' );
}

/** @param bool $show */
function diluxone_users_admin_bar_show( $show ): bool {
	return diluxone_users_admin_bar_hidden() ? false : (bool) $show;
}
add_filter( 'show_admin_bar', 'diluxone_users_admin_bar_show' );

/**
 * "Edit profile" points at the account area instead of the dashboard.
 *
 * Through `edit_profile_url` and not by rewriting the bar's nodes. The nodes
 * are not all there when `admin_bar_menu` runs — at any priority, "my-account"
 * and "edit-profile" were still missing while "user-info" was already in —
 * and every one of them builds its address from this filter anyway. One
 * filter, every link, and no race with whoever adds what.
 *
 * Only on the front of the site: an administrator inside the dashboard who
 * clicks "Edit profile" means the dashboard's profile screen, and sending
 * them to the site would be answering a question they did not ask.
 *
 * @param string $url
 */
function diluxone_users_edit_profile_url( $url ): string {
	if ( is_admin() || ! diluxone_users_option( 'diluxone_users_bar_account' ) ) {
		return (string) $url;
	}

	// An account address with no account page is the home page: no profile.
	return '' === diluxone_users_page_url( 'diluxone_users_account_page' ) ? (string) $url : diluxone_users_account_url();
}
add_filter( 'edit_profile_url', 'diluxone_users_edit_profile_url' );
