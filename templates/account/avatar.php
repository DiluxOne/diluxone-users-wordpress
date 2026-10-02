<?php
/**
 * The profile picture.
 *
 * What it is given, in `$args`:
 *
 * - `error`
 * - `has`
 * - `user`
 *
 * @var array{error: string, has: bool, user: WP_User} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<?php diluxone_users_panel_open( __( 'Your photo', 'diluxone-users' ), true, 'diluxone-users-avatar' ); ?>

	<?php if ( '' !== $args['error'] ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php echo esc_html( $args['error'] ); ?></p>
	<?php endif; ?>

	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="diluxone-users-avatar__form">
		<input type="hidden" name="action" value="diluxone_users_avatar">
		<?php wp_nonce_field( 'diluxone_users_avatar' ); ?>

		<span class="diluxone-users-avatar__current"><?php echo wp_kses( (string) get_avatar( $args['user']->ID, 88 ), diluxone_users_avatar_tags(), diluxone_users_avatar_protocols() ); ?></span>

		<div class="diluxone-users-avatar__actions">
			<label class="diluxone-users-button diluxone-users-button--soft" for="diluxone-users-avatar-file">
				<?php esc_html_e( 'Choose a photo', 'diluxone-users' ); ?>
				<input type="file" id="diluxone-users-avatar-file" name="diluxone_users_avatar_file" accept="image/jpeg,image/png,image/gif,image/webp">
			</label>

			<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Save', 'diluxone-users' ); ?></button>

			<?php if ( $args['has'] ) : ?>
				<button type="submit" name="diluxone_users_avatar_remove" value="1" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Remove it', 'diluxone-users' ); ?></button>
			<?php endif; ?>
		</div>

		<p class="diluxone-users-note">
			<?php
			echo esc_html(
				sprintf(
				/* translators: %s: maximum size, already formatted */
					__( 'JPG, PNG, GIF or WebP, up to %s.', 'diluxone-users' ),
					size_format( max( 1, (int) diluxone_users_option( 'diluxone_users_avatar_max_kb' ) ) * KB_IN_BYTES )
				)
			);
			?>
		</p>
	</form>
<?php diluxone_users_panel_close(); ?>
