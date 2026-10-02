<?php
/**
 * Cuentas vinculadas.
 *
 * What it is given, in `$args`: `user`, the account (unused here: each piece draws itself).
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>

<?php diluxone_users_panel_open( __( 'Networks you can sign in with', 'diluxone-users' ), true ); ?>
	<p><?php esc_html_e( 'Any of these opens this same account. Unlink the ones you do not use.', 'diluxone-users' ); ?></p>
	<?php diluxone_users_accounts_list( array( 'only' => 'linked' ) ); ?>
<?php diluxone_users_panel_close(); ?>

<?php diluxone_users_panel_open( __( 'Networks you can link', 'diluxone-users' ) ); ?>
	<p><?php esc_html_e( 'Add one and from then on it opens this account too. Nothing gets duplicated: it is the same account with another way in.', 'diluxone-users' ); ?></p>
	<?php diluxone_users_accounts_list( array( 'only' => 'available' ) ); ?>
<?php diluxone_users_panel_close(); ?>
