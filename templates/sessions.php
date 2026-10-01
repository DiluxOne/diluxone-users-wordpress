<?php
/**
 * The person's open sessions.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/sessions.php
 *
 * What it is given, in `$args`:
 *
 * - `sessions`
 * - `can_close_one` Whether a single one can be closed.
 * - `state`
 *
 * @var array{sessions: array<int, array<string, mixed>>, can_close_one: bool, state: string} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users diluxone-users-sessions">

	<?php if ( 'sessions' === $args['state'] ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--ok"><?php esc_html_e( 'Done.', 'diluxone-users' ); ?></p>
	<?php endif; ?>

	<?php if ( array() === $args['sessions'] ) : ?>
		<p class="diluxone-users-note"><?php esc_html_e( 'There are no open sessions.', 'diluxone-users' ); ?></p>
	<?php else : ?>

	<ul class="diluxone-users-sessions__list">
		<?php foreach ( $args['sessions'] as $diluxone_users_session ) : ?>
			<li class="diluxone-users-session<?php echo $diluxone_users_session['current'] ? ' diluxone-users-session--current' : ''; ?>">
				<div class="diluxone-users-session__what">
					<strong>
						<?php echo esc_html( $diluxone_users_session['browser'] ); ?>
						<?php if ( '' !== $diluxone_users_session['os'] ) : ?>
							· <?php echo esc_html( $diluxone_users_session['os'] ); ?>
						<?php endif; ?>
					</strong>
					<span>
						<?php echo esc_html( $diluxone_users_session['device'] ); ?>
						<?php if ( '' !== $diluxone_users_session['ip'] ) : ?>
							· <?php echo esc_html( $diluxone_users_session['ip'] ); ?>
						<?php endif; ?>
						<?php if ( $diluxone_users_session['started'] ) : ?>
							· 
							<?php
							printf(
									/* translators: %s: how long ago the session started */
								esc_html__( 'started %s ago', 'diluxone-users' ),
								esc_html( human_time_diff( $diluxone_users_session['started'] ) )
							);
							?>
						<?php endif; ?>
					</span>
				</div>

				<div class="diluxone-users-session__action">
					<?php if ( $diluxone_users_session['current'] ) : ?>
						<span class="diluxone-users-chip"><?php esc_html_e( 'This session', 'diluxone-users' ); ?></span>
					<?php elseif ( $args['can_close_one'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="diluxone_users_sessions">
							<input type="hidden" name="diluxone_users_session" value="<?php echo esc_attr( $diluxone_users_session['id'] ); ?>">
							<?php wp_nonce_field( 'diluxone_users_sessions' ); ?>
							<button type="submit" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Close', 'diluxone-users' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>

	<?php if ( count( $args['sessions'] ) > 1 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="diluxone-users-sessions__all">
			<input type="hidden" name="action" value="diluxone_users_sessions">
			<?php wp_nonce_field( 'diluxone_users_sessions' ); ?>
			<button type="submit" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Close the others', 'diluxone-users' ); ?></button>
		</form>
	<?php endif; ?>
</div>
