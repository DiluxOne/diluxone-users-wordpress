<?php
/**
 * What changes when this runs on a network of sites.
 *
 * On a WordPress multisite the accounts belong to the network and the
 * permissions belong to each site: somebody can exist and not be a member
 * here. A users plugin that ignores that creates accounts that sign in and
 * can do nothing, or leaves out people who already exist on the site next
 * door.
 *
 * The settings, on the other hand, stay per site on purpose: each site of a
 * network usually has its own account page, its own fields and its own
 * design. A network setting would force every site to ask for the same thing.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Does this site let people create their own accounts, as WordPress sees it?
 *
 * It is where the plugin's own switch starts from until the site answers the
 * question itself on the plugin's screen: activating a plugin is not deciding
 * that strangers may create accounts. On a single site that is "Anyone can
 * register"; on a network it is the network's own setting.
 */
function diluxone_users_site_takes_accounts(): bool {
	return is_multisite() ? diluxone_users_network_takes_accounts() : (bool) get_option( 'users_can_register' );
}

/**
 * May accounts be created on this network at all?
 *
 * On a network an account belongs to the whole network, and whether people
 * may create one is the network's decision — Network Admin → Settings →
 * "Allow new registrations" — not any one site's. A site administrator can
 * close their own site's doors, never open the network's.
 */
function diluxone_users_network_takes_accounts(): bool {
	if ( ! is_multisite() ) {
		return true;
	}

	return in_array( (string) get_site_option( 'registration', 'none' ), array( 'user', 'all' ), true );
}

/**
 * Adds somebody to this site if they are not a member yet.
 *
 * The same role as for a new account is used: if the site decided whoever
 * registers is a subscriber, whoever arrives from another site of the network
 * is one too.
 */
function diluxone_users_join_site( int $user_id ): void {
	if ( ! is_multisite() || $user_id <= 0 ) {
		return;
	}

	// A super admin already reaches every site, and a role here would only
	// be a Subscriber badge on the most powerful account of the network.
	if ( is_user_member_of_blog( $user_id, get_current_blog_id() ) || is_super_admin( $user_id ) ) {
		return;
	}

	// Existing on the network is not enough to become a member here: the site
	// has to take new people through some door of its own — the ones that
	// would have created the account had it not existed yet.
	$social = diluxone_users_option( 'diluxone_users_sso_register' ) && array() !== diluxone_users_sso_for_login();

	if ( ! diluxone_users_option( 'diluxone_users_login_register' ) && ! diluxone_users_option( 'diluxone_users_register_form' ) && ! $social ) {
		return;
	}

	add_user_to_blog( get_current_blog_id(), $user_id, diluxone_users_register_role() );
}

/* ── The life of a site ────────────────────────────────────────────── */

/**
 * Everything one site needs from the plugin, done on that site.
 *
 * One list, run in three moments: activation on a single site, activation for
 * the whole network (once per site), and the birth of a site on a network
 * where the plugin is already active everywhere.
 */
function diluxone_users_site_setup(): void {
	diluxone_users_seed_fields();
	diluxone_users_seed_registration();
	diluxone_users_log_install();
}

/**
 * Activation.
 *
 * Network-wide, every site gets its setup now and not "the first time
 * somebody opens its dashboard": a site whose visitors sign in before its
 * administrator ever looks would be writing to a table that does not exist.
 *
 * @param bool $network_wide Whether it was activated for the whole network.
 */
function diluxone_users_activate( $network_wide = false ): void {
	if ( ! is_multisite() || ! $network_wide ) {
		diluxone_users_site_setup();

		return;
	}

	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $site ) {
		switch_to_blog( (int) $site );
		diluxone_users_site_setup();
		restore_current_blog();
	}
}

/**
 * A site born on a network where the plugin is active everywhere.
 *
 * After WordPress's own setup of the site (priority 10), and inside it:
 * `switch_to_blog()` points `$wpdb->prefix` and the options at the new site,
 * which is where its table and its settings belong.
 *
 * @param WP_Site $site The new site.
 */
function diluxone_users_site_born( $site ): void {
	$network = (array) get_site_option( 'active_sitewide_plugins', array() );

	if ( ! $site instanceof WP_Site || ! isset( $network[ plugin_basename( DILUXONE_USERS_FILE ) ] ) ) {
		return;
	}

	switch_to_blog( (int) $site->blog_id );
	diluxone_users_site_setup();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'diluxone_users_site_born', 11 );

/**
 * Whether people may create their own accounts, as the site had it.
 *
 * Written once, when the plugin arrives, from the site's own answer: turning
 * a plugin on is not deciding that strangers may create accounts, by e-mail
 * link or by social sign-in. From then on they are the plugin's switches, on
 * its Registration screen.
 */
function diluxone_users_seed_registration(): void {
	foreach ( array( 'diluxone_users_login_register', 'diluxone_users_sso_register' ) as $door ) {
		if ( null === get_option( $door, null ) ) {
			add_option( $door, (int) diluxone_users_site_takes_accounts() );
		}
	}
}

/**
 * The starter fields.
 *
 * Seeded by diluxone_users_site_setup(), and again on the dashboard as a
 * safety net, only if the option does not exist: a deliberately empty list is
 * respected.
 */
function diluxone_users_seed_fields(): void {
	if ( false === get_option( 'diluxone_users_fields', false ) ) {
		update_option( 'diluxone_users_fields', diluxone_users_default_fields() );

		return;
	}

	diluxone_users_seed_native_fields();
}
add_action( 'admin_init', 'diluxone_users_seed_fields' );

/**
 * WordPress's own fields, on a site that already had the list assembled.
 *
 * They are added at the beginning and only the missing ones. A site that was
 * already drawing the name on its own is going to see two: that is correct
 * and is fixed by turning its own off, not by hiding the one WordPress
 * already had.
 */
function diluxone_users_seed_native_fields(): void {
	$fields  = (array) get_option( 'diluxone_users_fields', array() );
	$keys    = array_column( $fields, 'key' );
	$missing = array();

	foreach ( diluxone_users_default_fields() as $field ) {
		if ( diluxone_users_field_is_native( $field['key'] ) && ! in_array( $field['key'], $keys, true ) ) {
			$missing[] = $field;
		}
	}

	if ( array() === $missing ) {
		return;
	}

	update_option( 'diluxone_users_fields', array_merge( $missing, $fields ) );
}
