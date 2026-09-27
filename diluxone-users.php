<?php
/**
 * Plugin Name:       DiluxOne Users+ – Accounts & Login
 * Plugin URI:        https://github.com/soydiloreto/diluxone-users-wordpress
 * Description:       Custom user fields, a front-end account area, passwordless sign-in, social login, two-step verification, passkeys and session control.
 * Version:           1.0.0
 * Author:            Pablo Ariel Di Loreto
 * Author URI:        https://diluxone.com/plugins-wordpress
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diluxone-users
 * Requires at least: 6.2
 * Requires PHP:      8.0
 *
 * @package DiluxOneUsers
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 *
 * Who a person is, how they get in and how long their session lasts is the
 * same problem on every site, and until now it was solved again on each one.
 * Here it lives once.
 *
 * What it covers: the details you ask a person for, the account area on the
 * front end, how they sign in — e-mail link, password, social accounts,
 * passkeys —, the second factor, their sessions and their data.
 *
 * What it does not cover yet: paid subscriptions. That drags in a payment
 * gateway, recurring billing, retries and invoicing, and it will arrive as a
 * separate add-on (`diluxone-users-subscriptions`) so that a free site does
 * not carry billing code it never runs. The `diluxone_users_` prefix is
 * already the family's, so metadata keys will not move when it lands.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

define( 'DILUXONE_USERS_VERSION', '1.0.0' );
define( 'DILUXONE_USERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DILUXONE_USERS_URL', plugin_dir_url( __FILE__ ) );
define( 'DILUXONE_USERS_FILE', __FILE__ );

/**
 * Every file in includes/ stands on its own and only registers hooks. They are
 * loaded in alphabetical order on purpose: if one of them needed another to
 * boot, that would be coupling to resolve with a hook, not with load order.
 *
 * Translations are loaded by WordPress itself, just in time, from the language
 * packs translate.wordpress.org publishes into wp-content/languages/plugins/.
 * The plugin calls no load_plugin_textdomain() and ships no .mo of its own.
 */
foreach ( (array) glob( DILUXONE_USERS_DIR . 'includes/*.php' ) as $diluxone_users_file ) {
	require_once (string) $diluxone_users_file;
}

/**
 * On activation: what each site needs — its starter fields, its registration
 * switch as the site had it, and the activity log's table.
 *
 * It lives in includes/multisite.php, because on a network it runs once per
 * site and again when a new site is born, where this hook does not run.
 */
register_activation_hook( __FILE__, 'diluxone_users_activate' );
