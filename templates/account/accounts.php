<?php
/**
 * Cuentas vinculadas.
 *
 * Variables: $user.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>

<?php diluxone_users_panel_open( __( 'Networks you can sign in with', 'diluxone-users' ), true ); ?>
	<p><?php esc_html_e( 'Any of these opens this same account. Unlink the ones you do not use.', 'diluxone-users' ); ?></p>
	<?php echo do_shortcode( '[diluxone_users_accounts only="linked"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. ?>
<?php diluxone_users_panel_close(); ?>

<?php diluxone_users_panel_open( __( 'Networks you can link', 'diluxone-users' ) ); ?>
	<p><?php esc_html_e( 'Add one and from then on it opens this account too. Nothing gets duplicated: it is the same account with another way in.', 'diluxone-users' ); ?></p>
	<?php echo do_shortcode( '[diluxone_users_accounts only="available"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. ?>
<?php diluxone_users_panel_close(); ?>
