<?php
/**
 * Datos personales.
 *
 * What it is given, in `$args`: `user`, the account (unused here: each piece draws itself).
 *
 * The person's fields are one form with a box per block that has any (main,
 * then extra), each under its title and ending in a Save button. Any of those
 * buttons saves the whole form, so nothing typed in the other box is lost,
 * and what the save says is shown once, above the boxes.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_blocks = diluxone_users_field_blocks();
?>
<?php diluxone_users_avatar_form(); ?>
<?php diluxone_users_handle_form(); ?>
<?php
if ( array() === $diluxone_users_blocks ) {
	return;
}

$diluxone_users_all     = array_merge( ...array_values( array_column( $diluxone_users_blocks, 'fields' ) ) );
$diluxone_users_refused = diluxone_users_phone_refused( $diluxone_users_all );
$diluxone_users_state   = diluxone_users_state();
?>
<div class="diluxone-users diluxone-users-fields diluxone-users-fields--boxed">

	<?php if ( 'saved' === $diluxone_users_state ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--ok"><?php esc_html_e( 'Saved.', 'diluxone-users' ); ?></p>
	<?php elseif ( 'missing' === $diluxone_users_state ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'Some required fields are missing.', 'diluxone-users' ); ?></p>
	<?php elseif ( array() !== $diluxone_users_refused ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'Nothing was saved: a phone number is not the right length for its country.', 'diluxone-users' ); ?></p>
	<?php endif; ?>

	<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="diluxone_users_fields_save">
		<input type="hidden" name="diluxone_users_group" value="">
		<?php wp_nonce_field( 'diluxone_users_fields_save' ); ?>

		<?php foreach ( $diluxone_users_blocks as $diluxone_users_group => $diluxone_users_block ) : ?>
			<div class="diluxone-users-panel diluxone-users-fields__block diluxone-users-fields__block--<?php echo esc_attr( $diluxone_users_group ); ?>">
				<h3><?php echo esc_html( $diluxone_users_block['title'] ); ?></h3>

				<?php
				diluxone_users_template_part(
					'fields-list.php',
					array(
						'user_id' => get_current_user_id(),
						'fields'  => $diluxone_users_block['fields'],
						'refused' => $diluxone_users_refused,
					)
				);
				?>

				<p class="diluxone-users-panel__save"><button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Save', 'diluxone-users' ); ?></button></p>
			</div>
		<?php endforeach; ?>
	</form>
</div>
