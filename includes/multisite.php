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

/* ── Activation ────────────────────────────────────────────────────── */

/**
 * Activation.
 *
 * It decides nothing for the site, and writes nothing. No setting, no table,
 * no event: every setting reads its default until an administrator saves it,
 * and every default leaves the site as WordPress has it. The hook stays so
 * that WordPress has one to call and the tests have one to prove.
 *
 * @param bool $network_wide Whether it was activated for the whole network.
 */
function diluxone_users_activate( $network_wide = false ): void {
}

/**
 * WordPress's own fields, on a site that already had the list assembled.
 *
 * They are added at the beginning and only the missing ones. A site that was
 * already drawing the name on its own is going to see two: that is correct
 * and is fixed by turning its own off, not by hiding the one WordPress
 * already had.
 */
function diluxone_users_seed_native_fields(): void {
	$stored = diluxone_users_raw_get( 'diluxone_users_fields', null );

	// A list never saved is the starter list, read as a default: nothing to add.
	if ( null === $stored ) {
		return;
	}

	$fields  = (array) $stored;
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
add_action( 'admin_init', 'diluxone_users_seed_native_fields' );
