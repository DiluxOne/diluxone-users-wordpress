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
 * The settings follow the people. Who gets in and how safely, and the fields
 * a person has, are one set for the whole network, set in Network Admin: the
 * person is the same person on every site and the session they open reaches
 * all of them. The screens people sign in and keep their account on, and how
 * they look, are the main site's. What fits into each site's own theme — its
 * menus, its admin bar — stays with each site. Where each setting lives is
 * options-scope.php; the screens that set them, admin-network.php.
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
 * Signing in, as the network's membership policy has it.
 *
 * Under "every site" it is the safety net: the person becomes a member of the
 * site they are on and of the hub, whatever the new-account and new-site
 * additions missed. Under "click" and "invite" it only makes sure of the hub,
 * where the account lives; any other site is joined with a press of "Join
 * this site", or by an administrator. The rest of the rules — no super admin,
 * nobody an administrator took off a site, only live sites, each site's own
 * role — are diluxone_users_membership_add()'s, which on a single site adds
 * nobody: there, everybody with an account is a member already.
 */
function diluxone_users_join_site( int $user_id ): void {
	$sites = array( diluxone_users_hub_site_id() );

	if ( 'all' === diluxone_users_membership() ) {
		array_unshift( $sites, get_current_blog_id() );
	}

	foreach ( array_unique( $sites ) as $site ) {
		diluxone_users_membership_add( $user_id, (int) $site, 'sign-in' );
	}
}

/* ── The life of a site ────────────────────────────────────────────── */

/**
 * Everything one site needs from the plugin, done on that site.
 *
 * One list, run in three moments: activation on a single site, activation for
 * the whole network (once per site), and the birth of a site on the network.
 *
 * The activity log's table is made here only on a single site. On a network
 * it is the network's, one for every site, made once by the network's
 * activation below: a site has nothing of its own to create.
 */
function diluxone_users_site_setup(): void {
	diluxone_users_seed_fields();
	diluxone_users_seed_registration();

	if ( ! is_multisite() ) {
		diluxone_users_log_install();
	}
}

/**
 * Activation.
 *
 * On a network it is always for the whole network (the plugin says
 * `Network: true`, and WordPress activates it network-wide even when asked
 * from a site). Every site gets its setup now and not "the first time
 * somebody opens its dashboard": a site whose visitors sign in before its
 * administrator ever looks would be reading settings nobody wrote. The log's
 * table, the network's, is made once, first.
 *
 * @param bool $network_wide Whether it was activated for the whole network.
 */
function diluxone_users_activate( $network_wide = false ): void {
	if ( ! is_multisite() ) {
		diluxone_users_site_setup();

		return;
	}

	diluxone_users_log_install();

	foreach ( get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => (int) get_current_network_id(),
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
 * `switch_to_blog()` points the options at the new site, which is where its
 * own settings belong. Its log is the network's table, already there.
 *
 * @param WP_Site $site The new site.
 */
function diluxone_users_site_born( $site ): void {
	if ( ! $site instanceof WP_Site || ! diluxone_users_network_activated() ) {
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
		if ( null === diluxone_users_raw_get( $door, null ) ) {
			diluxone_users_update_option( $door, (int) diluxone_users_site_takes_accounts() );
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
	if ( false === diluxone_users_raw_get( 'diluxone_users_fields', false ) ) {
		diluxone_users_update_option( 'diluxone_users_fields', diluxone_users_default_fields() );

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
	$fields  = (array) diluxone_users_raw_get( 'diluxone_users_fields', array() );
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

	diluxone_users_update_option( 'diluxone_users_fields', array_merge( $missing, $fields ) );
}
