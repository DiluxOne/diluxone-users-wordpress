<?php
/**
 * The emergency switch: `define( 'DILUXONE_USERS_SAFE_MODE', true );`.
 *
 * Everything the plugin puts between a person and WordPress's own sign-in can
 * go wrong on a given site — a sign-in page that a theme breaks, a mail
 * server that stops sending the code, a provider that changes its API, a
 * redirect that loops behind a proxy — and the person locked out is usually
 * the administrator who would fix it. wp-config.php is the one thing they can
 * still reach.
 *
 * While the constant is true the plugin steps aside at every door it put up:
 * nothing redirects (wp-login.php is WordPress's again, on every site of a
 * network; the hub's doors, the forms posted to the hub, the dashboard profile
 * sent to the account area), nobody is asked for the second step, and the
 * passkey and social sign-in doors are shut. WordPress's own wp-login.php,
 * with a username and a password, works the way it does without the plugin.
 * Everything else — the account area, the fields, the settings — is left as
 * it was, and every dashboard page says the switch is on until it is taken
 * out again, because a site in safe mode is a site with its second step off.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the emergency switch on?
 *
 * The constant is the switch; the filter is the seam a test uses, and the way
 * a must-use plugin can turn it on for a site whose wp-config.php is not
 * writable.
 */
function diluxone_users_safe_mode(): bool {
	/**
	 * Filters whether the plugin is in safe mode.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $on What DILUXONE_USERS_SAFE_MODE says; false when it is not defined.
	 */
	return (bool) apply_filters( 'diluxone_users_safe_mode', defined( 'DILUXONE_USERS_SAFE_MODE' ) && DILUXONE_USERS_SAFE_MODE );
}

/**
 * Says so on the dashboard, the plugins list and the plugin's own screens, for
 * as long as it lasts.
 *
 * The places every other notice of the plugin speaks, and no more: the
 * WordPress admin is a workspace, and a warning on every screen of it is the
 * kind of noise people learn to stop reading.
 *
 * To whoever can manage the site, or the network in Network Admin: they are
 * the ones who put the constant there and the ones who have to take it out.
 * Not dismissible on purpose — a reminder that can be closed is a reminder
 * that is forgotten, and this one is about the second step being off.
 */
function diluxone_users_safe_mode_notice(): void {
	if ( ! diluxone_users_safe_mode() || ! current_user_can( is_network_admin() ? 'manage_network_options' : 'manage_options' ) || ! diluxone_users_notice_here() ) {
		return;
	}

	printf(
		'<div class="notice notice-error" data-diluxone-users-safe-mode><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'DiluxOne Users+ is in safe mode.', 'diluxone-users' ),
		sprintf(
			/* translators: %s: the line in wp-config.php that turns safe mode on */
			esc_html__( 'Nothing redirects, nobody is asked for the second step, and passkeys and social sign-in are off: WordPress’s own wp-login.php is the way in. Once you are back in and the cause is fixed, remove %s from wp-config.php.', 'diluxone-users' ),
			'<code>define( \'DILUXONE_USERS_SAFE_MODE\', true );</code>'
		)
	);
}
add_action( 'admin_notices', 'diluxone_users_safe_mode_notice' );
add_action( 'network_admin_notices', 'diluxone_users_safe_mode_notice' );
