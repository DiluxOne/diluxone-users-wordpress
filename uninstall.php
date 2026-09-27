<?php
/**
 * What is left behind when the plugin is deleted.
 *
 * By default: everything. The settings, the activity log and — above all —
 * what is in people's profiles. Most of what this plugin wrote is not the
 * plugin's: it is somebody's name, their phone number, their date of birth,
 * the passkey they sign in with and the second factor standing between their
 * account and whoever wants it. A plugin deleted by accident, or deleted in
 * order to be installed again, must not be the thing that loses them.
 *
 * So the wiping is a decision somebody makes beforehand, on
 * DiluxOne Users+ → Maintenance → Tools, and it is off until they do. Once
 * ticked, this file runs on delete — not on deactivate — and takes it all:
 * the table, the settings, and every meta key the plugin ever wrote,
 * including the answers to the fields the site invented.
 *
 * On a network it runs once per site, because that is where the data is: the
 * settings are per site, the log table is per site, and only the user meta is
 * shared. That last part is a decision about the whole network's people, and
 * a site administrator can tick the box for their own site only: the people's
 * data goes only when every site that used the plugin ticked it. Otherwise
 * each site that asked loses its own settings and log, and the profiles stay.
 *
 * @package DiluxOneUsers
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * The option keys that are not simply `diluxone_users_*`.
 *
 * `users_can_register` is WordPress's own and is deliberately not touched: the
 * site's answer to "can anybody sign up" outlives this plugin, and putting it
 * back to some remembered value would be this plugin deciding something after
 * it is gone.
 *
 * @return array<int, string>
 */
function diluxone_users_uninstall_options(): array {
	return array(
		'diluxone_users_fields',
		'diluxone_users_sso',
		'diluxone_users_mail_templates',
		'diluxone_users_login_messages',
		'diluxone_users_account_sections',
		'diluxone_users_home_cards_off',
		'diluxone_users_log_schema',
		'diluxone_users_rewrite_version',
		'diluxone_users_mail_last',
	);
}

/**
 * Everything one site wrote: its table, its settings, its transients.
 *
 * The options are deleted by pattern and not from a list of a hundred and
 * twenty names, because a list is a thing that goes out of date silently —
 * the setting added next year is the one that stays behind for ever.
 */
function diluxone_users_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'diluxone_users_log_purge' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dropping our own table is the one thing there is no API for.
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'diluxone_users_log`' );

	foreach ( diluxone_users_uninstall_options() as $option ) {
		delete_option( $option );
	}

	// Options, then transients, then the timeout rows the transients leave —
	// the site's own and, on a single site, the "site" ones, which live in the
	// same table there.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting by prefix, which no option API expresses.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'diluxone_users_' ) . '%',
			$wpdb->esc_like( '_transient_diluxone_users_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_diluxone_users_' ) . '%',
			$wpdb->esc_like( '_site_transient_diluxone_users_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_diluxone_users_' ) . '%'
		)
	);
}

/**
 * The network's own transients: the counts per machine, kept for the whole
 * network. Only on a network, where they live in their own table.
 */
function diluxone_users_uninstall_network(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting by prefix, which no option API expresses.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( '_site_transient_diluxone_users_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_diluxone_users_' ) . '%'
		)
	);
}

/**
 * The profile photos people uploaded, from the media library that holds each.
 *
 * They are photographs of people, and the meta that pointed at them is about
 * to go: left behind they would be pictures of somebody that nothing links
 * to any more. Only an attachment the person authored is deleted, on the site
 * the photo was uploaded to.
 */
function diluxone_users_uninstall_photos(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off read of every photo, on uninstall.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.user_id, a.meta_value AS photo, s.meta_value AS site
			   FROM {$wpdb->usermeta} a
			   LEFT JOIN {$wpdb->usermeta} s ON s.user_id = a.user_id AND s.meta_key = %s
			  WHERE a.meta_key = %s",
			'diluxone_users_avatar_site',
			'diluxone_users_avatar'
		)
	);

	foreach ( (array) $rows as $row ) {
		$site   = (int) $row->site;
		$switch = is_multisite() && $site > 0 && get_current_blog_id() !== $site;

		if ( $switch ) {
			switch_to_blog( $site );
		}

		if ( (int) get_post_field( 'post_author', (int) $row->photo ) === (int) $row->user_id ) {
			wp_delete_attachment( (int) $row->photo, true );
		}

		if ( $switch ) {
			restore_current_blog();
		}
	}
}

/**
 * Everything the plugin wrote about people.
 *
 * Two shapes, and the second one is the reason this is not one query. The
 * plugin's own keys all start with the prefix; the answers to the site's own
 * fields are stored under whatever key the site chose, which can be anything
 * — so those keys are read out of the field definition before it is deleted,
 * and `first_name` and `last_name` are skipped, because those are WordPress's
 * and were only ever borrowed.
 *
 * @param array<int, string> $field_keys The site's own field keys.
 */
function diluxone_users_uninstall_people( array $field_keys ): void {
	global $wpdb;

	diluxone_users_uninstall_photos();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting by prefix, which delete_metadata() cannot express.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( 'diluxone_users_' ) . '%',
			$wpdb->esc_like( '_diluxone_users_' ) . '%'
		)
	);

	foreach ( array_unique( $field_keys ) as $key ) {
		if ( '' === $key || in_array( $key, array( 'first_name', 'last_name' ), true ) ) {
			continue;
		}

		delete_metadata( 'user', 0, $key, '', true );
	}
}

/**
 * The field keys one site invented, read before its settings are deleted.
 *
 * @return array<int, string>
 */
function diluxone_users_uninstall_field_keys(): array {
	$fields = get_option( 'diluxone_users_fields' );
	$keys   = array();

	foreach ( is_array( $fields ) ? $fields : array() as $field ) {
		if ( is_array( $field ) && isset( $field['key'] ) && is_string( $field['key'] ) ) {
			$keys[] = $field['key'];
		}
	}

	return $keys;
}

/* ── The run ───────────────────────────────────────────────────────── */

if ( is_multisite() ) {
	$diluxone_users_keys  = array();
	$diluxone_users_any   = false;
	$diluxone_users_all   = true;
	$diluxone_users_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $diluxone_users_sites as $diluxone_users_site ) {
		switch_to_blog( (int) $diluxone_users_site );

		// Asked before the settings go, and only asked: the meta itself is
		// shared across the network and is deleted once, at the end — if at
		// all. A site that used the plugin (it has its fields) and did not
		// tick the box keeps its people's data, which is everybody's.
		if ( get_option( 'diluxone_users_uninstall_wipe' ) ) {
			$diluxone_users_any  = true;
			$diluxone_users_keys = array_merge( $diluxone_users_keys, diluxone_users_uninstall_field_keys() );

			diluxone_users_uninstall_site();
		} elseif ( false !== get_option( 'diluxone_users_fields', false ) ) {
			$diluxone_users_all = false;
		}

		restore_current_blog();
	}

	// A flag and not "did we collect any keys": a site that ticked the box and
	// had no fields of its own collects none, and its people's passkeys and
	// second factors would have stayed behind for ever.
	if ( $diluxone_users_any && $diluxone_users_all ) {
		diluxone_users_uninstall_people( $diluxone_users_keys );
		diluxone_users_uninstall_network();
	}

	return;
}

if ( ! get_option( 'diluxone_users_uninstall_wipe' ) ) {
	return;
}

$diluxone_users_keys = diluxone_users_uninstall_field_keys();

diluxone_users_uninstall_site();
diluxone_users_uninstall_people( $diluxone_users_keys );
