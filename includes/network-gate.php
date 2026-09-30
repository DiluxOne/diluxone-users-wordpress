<?php
/**
 * On a network the plugin is on for the whole network, or it does nothing.
 *
 * Who a person is and how they get in is one decision for every site of a
 * network, because the person and their session are the network's. A plugin
 * that could also be switched on for one site alone had to keep a second way
 * of working — each site with its own settings, its own log, its own say about
 * deleting everybody's data — that no network wanted and every change had to
 * carry. So the header says `Network: true`, WordPress offers only "Network
 * Activate", and activating it from a site activates it for the network.
 *
 * What is left is a plugin found on for one site and not for the network: an
 * activation from before this rule, or a copy dropped in by hand. On such a
 * site it loads nothing — no screen, no shortcode, no sign-in — and tells
 * whoever administers the network, and only them, to activate it for the whole
 * network. A single site is not affected by any of this.
 *
 * This file is read first, by the main file, before anything else is loaded.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Is the plugin on for every site of this network? False on a single site. */
function diluxone_users_network_activated(): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	$network = (array) get_site_option( 'active_sitewide_plugins', array() );

	return isset( $network[ plugin_basename( DILUXONE_USERS_FILE ) ] );
}

/**
 * Does the plugin work here?
 *
 * On a single site, always. On a network, only when it is on for the whole
 * network.
 */
function diluxone_users_awake(): bool {
	return ! is_multisite() || diluxone_users_network_activated();
}

/** Everything the plugin is, loaded: every file in includes/, in order. */
function diluxone_users_load(): void {
	foreach ( (array) glob( DILUXONE_USERS_DIR . 'includes/*.php' ) as $file ) {
		require_once (string) $file;
	}
}

/**
 * Activation, whichever way it arrives.
 *
 * On a network WordPress runs this before it writes down that the plugin is
 * on for the network, so the plugin may not be loaded yet: it is loaded here,
 * and set up.
 *
 * @param bool $network_wide Whether it was activated for the whole network.
 */
function diluxone_users_activate_now( $network_wide = false ): void {
	diluxone_users_load();
	diluxone_users_activate( is_multisite() || (bool) $network_wide );
}

/** On a site of a network where it is not on for the network: only the notice. */
function diluxone_users_sleep(): void {
	add_action( 'network_admin_notices', 'diluxone_users_asleep_notice' );
	add_action( 'admin_notices', 'diluxone_users_asleep_notice' );
}

/**
 * Tells whoever administers the network to activate it for the whole network.
 *
 * In Network Admin, when the main site is one it was left on for, and on the
 * dashboard of every site it was left on for: WordPress only loads a plugin
 * on the sites it is on for, so those are the places it can speak. Only
 * whoever can manage the network's plugins sees it, because only they can do
 * what it asks.
 */
function diluxone_users_asleep_notice(): void {
	if ( ! current_user_can( 'manage_network_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning" data-diluxone-users-asleep><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
		esc_html__( 'DiluxOne Users+ only works on a network when it is activated for the whole network. It is on for this site alone, so it does nothing here: no screens, no sign-in, no account area.', 'diluxone-users' ),
		esc_url( network_admin_url( 'plugins.php' ) ),
		esc_html__( 'Activate DiluxOne Users+ for the whole network', 'diluxone-users' )
	);
}
