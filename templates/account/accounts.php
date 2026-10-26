<?php
/**
 * Cuentas vinculadas.
 *
 * What it is given, in `$args`: `user`, the account.
 *
 * @var array{user: WP_User} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

// With every network on offer already linked, the second box opens on its own
// note, so its heading never stands with nothing visible under it.
$diluxone_users_all_linked = array() === array_diff_key(
	diluxone_users_sso_available(),
	array_flip( diluxone_users_sso_linked( $args['user']->ID ) )
);
?>

<?php diluxone_users_panel_open( __( 'Networks you can sign in with', 'diluxone-users' ), true ); ?>
	<p><?php esc_html_e( 'Any of these opens this same account. Unlink the ones you do not use.', 'diluxone-users' ); ?></p>
	<?php diluxone_users_accounts_list( array( 'only' => 'linked' ) ); ?>
<?php diluxone_users_panel_close(); ?>

<?php diluxone_users_panel_open( __( 'Networks you can link', 'diluxone-users' ), $diluxone_users_all_linked ); ?>
	<?php if ( ! $diluxone_users_all_linked ) : ?>
		<p><?php esc_html_e( 'Add one and from then on it opens this account too. Nothing gets duplicated: it is the same account with another way in.', 'diluxone-users' ); ?></p>
	<?php endif; ?>
	<?php diluxone_users_accounts_list( array( 'only' => 'available' ) ); ?>
<?php diluxone_users_panel_close(); ?>
