<?php
/**
 * WordPress's own user meta keys: never the plugin's to write or to delete.
 *
 * Read in two places that have to agree. A field may not be named after one
 * of them (fields.php), because a field's key is the meta key its answers are
 * written to; and uninstall.php never deletes one, whatever a stored field
 * says, because deleting a field's answers is deleting that key for every
 * person on the site — a field keyed `description` would take every
 * biography with it. uninstall.php loads this file on its own, since the
 * plugin is not loaded when it is deleted, so it declares one function and
 * nothing else.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'diluxone_users_core_user_meta' ) ) {
	/**
	 * The keys, without the table prefix some of them carry (`wp_capabilities`,
	 * `wp_2_user_level`): anything under the prefix is WordPress's too, and is
	 * checked by prefix where it matters.
	 *
	 * First and last name are on the list: the plugin adopts them as fields,
	 * but they are WordPress's, and outlive it.
	 *
	 * @return array<int, string>
	 */
	function diluxone_users_core_user_meta(): array {
		return array(
			'first_name',
			'last_name',
			'nickname',
			'description',
			'rich_editing',
			'syntax_highlighting',
			'comment_shortcuts',
			'admin_color',
			'use_ssl',
			'show_admin_bar_front',
			'locale',
			'session_tokens',
			'primary_blog',
			'source_domain',
			'capabilities',
			'user_level',
			'dismissed_wp_pointers',
			'show_welcome_panel',
			'default_password_nag',
		);
	}
}
