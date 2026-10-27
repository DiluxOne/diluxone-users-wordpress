<?php
/**
 * The person's fields, for editing.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/fields.php
 *
 * What it is given, in `$args`:
 *
 * - `user_id`
 * - `fields`
 * - `group`
 * - `title`
 * - `state`
 * - `redirect` (optional) where a save that leaves nothing required missing
 *   goes on to: the step that asks for the missing fields after a sign-in
 * - `skip` (optional) the address of a "Skip for now" link under the form
 *
 * @var array{user_id: int, fields: array<int, array<string, mixed>>, group: string, title: string, state: string, redirect?: string, skip?: string} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

if ( array() === $args['fields'] ) {
	return;
}

$diluxone_users_redirect = (string) ( $args['redirect'] ?? '' );
$diluxone_users_skip     = (string) ( $args['skip'] ?? '' );
// The phone the form came back for, and why: said under the number.
$diluxone_users_refused = diluxone_users_phone_refused( $args['fields'] );
?>
<div class="diluxone-users diluxone-users-fields">

	<?php if ( '' !== $args['title'] ) : ?>
		<h2 class="diluxone-users-fields__title"><?php echo esc_html( $args['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( 'saved' === $args['state'] ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--ok"><?php esc_html_e( 'Saved.', 'diluxone-users' ); ?></p>
	<?php elseif ( 'missing' === $args['state'] ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'Some required fields are missing.', 'diluxone-users' ); ?></p>
	<?php elseif ( array() !== $diluxone_users_refused ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'Nothing was saved: a phone number is not the right length for its country.', 'diluxone-users' ); ?></p>
	<?php endif; ?>

	<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="diluxone_users_fields_save">
		<input type="hidden" name="diluxone_users_group" value="<?php echo esc_attr( $args['group'] ); ?>">
		<?php wp_nonce_field( 'diluxone_users_fields_save' ); ?>
		<?php if ( '' !== $diluxone_users_redirect ) : ?>
			<input type="hidden" name="diluxone_users_redirect" value="<?php echo esc_url( $diluxone_users_redirect ); ?>">
		<?php endif; ?>

		<?php
		diluxone_users_template_part(
			'fields-list.php',
			array(
				'user_id' => (int) $args['user_id'],
				'fields'  => $args['fields'],
				'refused' => $diluxone_users_refused,
			)
		);
		?>

		<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Save', 'diluxone-users' ); ?></button>

		<?php if ( '' !== $diluxone_users_skip ) : ?>
			<a class="diluxone-users-fields__skip" href="<?php echo esc_url( $diluxone_users_skip ); ?>"><?php esc_html_e( 'Skip for now', 'diluxone-users' ); ?></a>
		<?php endif; ?>
	</form>
</div>
