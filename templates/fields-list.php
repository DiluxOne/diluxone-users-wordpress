<?php
/**
 * The person's fields, one after another, with nothing around them.
 *
 * Drawn by `templates/fields.php` and by the boxes of the account area's
 * "Your details" tab, so both draw a field the same way.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/fields-list.php
 *
 * What it is given, in `$args`:
 *
 * - `user_id`
 * - `fields`
 * - `refused` the message under each phone the form came back for, by key
 *
 * @var array{user_id: int, fields: array<int, array<string, mixed>>, refused: array<string, string>} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<?php foreach ( $args['fields'] as $diluxone_users_field ) : ?>
	<div class="diluxone-users-field diluxone-users-field--<?php echo esc_attr( $diluxone_users_field['type'] ); ?>">
		<?php if ( 'checkbox' !== $diluxone_users_field['type'] ) : ?>
			<label for="<?php echo esc_attr( $diluxone_users_field['key'] ); ?>">
				<?php echo esc_html( $diluxone_users_field['label'] ); ?>
				<?php if ( $diluxone_users_field['required'] ) : ?>
					<span class="diluxone-users-field__required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
		<?php endif; ?>

		<?php diluxone_users_field_input( $diluxone_users_field, diluxone_users_value( $args['user_id'], $diluxone_users_field['key'] ), '', (int) $args['user_id'], (string) ( $args['refused'][ $diluxone_users_field['key'] ] ?? '' ) ); ?>

		<?php if ( '' !== $diluxone_users_field['help'] ) : ?>
			<p class="diluxone-users-field__help"><?php echo esc_html( $diluxone_users_field['help'] ); ?></p>
		<?php endif; ?>

		<?php $diluxone_users_nota = diluxone_users_field_edit_note( $diluxone_users_field, $args['user_id'] ); ?>
		<?php if ( '' !== $diluxone_users_nota ) : ?>
			<p class="diluxone-users-field__help diluxone-users-field__limit"><?php echo esc_html( $diluxone_users_nota ); ?></p>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
