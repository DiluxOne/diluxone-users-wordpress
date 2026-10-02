<?php
/**
 * The public-name field, for dropping inside another form.
 *
 * What it is given, in `$args`:
 *
 * - `can`
 * - `handle`
 * - `next`
 *
 * @var array{can: bool, handle: string, next: int} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_url_actual = diluxone_users_handle_base_url() . $args['handle'] . '/';
?>
<div class="diluxone-users-handle-field">
	<label for="diluxone-users-handle"><?php esc_html_e( 'Public name', 'diluxone-users' ); ?></label>

	<p class="diluxone-users-handle__what">
		<?php esc_html_e( 'It is your short name on the site: the one that goes in the address of your profile and the one other people use to find you. It is not how you sign in: that is always your email.', 'diluxone-users' ); ?>
	</p>

	<input type="text" id="diluxone-users-handle" name="diluxone_users_handle" value="<?php echo esc_attr( $args['handle'] ); ?>"
		minlength="<?php echo esc_attr( (string) diluxone_users_handle_limits()[0] ); ?>"
		maxlength="<?php echo esc_attr( (string) diluxone_users_handle_limits()[1] ); ?>"
		autocomplete="off" spellcheck="false"
		<?php disabled( ! $args['can'] ); ?>>

	<p class="diluxone-users-handle__preview" data-diluxone-users-handle-preview<?php echo '' === $args['handle'] ? ' hidden' : ''; ?>>
		<?php esc_html_e( 'Your profile:', 'diluxone-users' ); ?>
		<a href="<?php echo esc_url( $diluxone_users_url_actual ); ?>" target="_blank" rel="noopener" data-diluxone-users-handle-url><?php echo esc_html( $diluxone_users_url_actual ); ?></a>
	</p>

	<?php if ( $args['can'] ) : ?>
		<p class="diluxone-users-handle__state">
			<a href="<?php echo esc_url( $diluxone_users_url_actual ); ?>" target="_blank" rel="noopener" data-diluxone-users-handle-check><?php esc_html_e( 'Check if it is available', 'diluxone-users' ); ?></a>
			<span data-diluxone-users-handle-notice></span>
		</p>
	<?php endif; ?>

	<p class="diluxone-users-note">
		<?php if ( ! $args['can'] ) : ?>
			<?php
			echo esc_html(
				sprintf(
				/* translators: %s: date from which it can be changed */
					__( 'You changed it recently. You can change it again on %s.', 'diluxone-users' ),
					wp_date( 'j M Y', $args['next'] )
				)
			);
			?>
		<?php else : ?>
			<?php
			echo 'reject' === diluxone_users_option( 'diluxone_users_handle_spaces' )
				? esc_html__( 'No spaces: this goes in a web address. Letters, numbers, dots, dashes and underscores.', 'diluxone-users' )
				: esc_html__( 'Spaces turn into dashes, because a web address cannot have them. Everything else that does not fit in an address is dropped.', 'diluxone-users' );
			?>
		<?php endif; ?>
	</p>
</div>
