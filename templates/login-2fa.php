<?php
/**
 * The second step of signing in.
 *
 * @var string                              $key
 * @var string                              $method
 * @var array<string, array<string, mixed>> $methods
 * @var string                              $state
 * @var int                                 $user_id
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_actual = $methods[ $method ] ?? array(
	'label' => '',
	'help'  => '',
);
?>
<div class="diluxone-users diluxone-users-login diluxone-users-login--2fa">
	<?php diluxone_users_login_logo(); ?>
	<h2 class="diluxone-users-login__title"><?php esc_html_e( 'One more step', 'diluxone-users' ); ?></h2>
	<p><?php echo esc_html( (string) $diluxone_users_actual['help'] ); ?></p>

	<?php if ( 'locked' === $state ) : ?>
		<?php diluxone_users_login_notice( 'two_step_locked' ); ?>
	<?php elseif ( 'code' === $state ) : ?>
		<?php diluxone_users_login_notice( 'two_step_wrong' ); ?>
	<?php elseif ( 'sent' === $state ) : ?>
		<?php diluxone_users_login_notice( 'two_step_sent' ); ?>
	<?php endif; ?>

	<form class="diluxone-users-form" method="post" action="">
		<input type="hidden" name="diluxone_users_2fa_user" value="<?php echo esc_attr( (string) $user_id ); ?>">
		<input type="hidden" name="diluxone_users_2fa_key" value="<?php echo esc_attr( $key ); ?>">
		<input type="hidden" name="diluxone_users_2fa_method" value="<?php echo esc_attr( $method ); ?>">

		<label for="diluxone-users-2fa-code"><?php esc_html_e( 'The code', 'diluxone-users' ); ?></label>
		<input type="text" id="diluxone-users-2fa-code" name="diluxone_users_2fa_code" inputmode="numeric" autocomplete="one-time-code"
			maxlength="20" autofocus required>

		<?php if ( (int) diluxone_users_option( 'diluxone_users_2fa_remember_days' ) > 0 ) : ?>
			<label class="diluxone-users-check">
				<input type="checkbox" name="diluxone_users_2fa_trust" value="1">
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: days */
							__( 'Do not ask again on this browser for %d days', 'diluxone-users' ),
							(int) diluxone_users_option( 'diluxone_users_2fa_remember_days' )
						)
					);
					?>
				</span>
			</label>
		<?php endif; ?>

		<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Confirm', 'diluxone-users' ); ?></button>

		<?php
		/*
		 * `formnovalidate` because this button is in the same form as the code
		 * box, and that box is `required`: without it the browser answers the
		 * press with "fill in this field" and never sends anything — which is
		 * to say the button did not work at the one moment it exists for, when
		 * the code never arrived and the box is therefore empty. Asking for
		 * another code is not the same submission as answering with one, and
		 * the handler reads `resend` before it looks at the code.
		 */
		?>
		<?php if ( isset( $methods[ $method ]['send'] ) ) : ?>
			<button type="submit" name="diluxone_users_2fa_resend" value="1" formnovalidate class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Send it again', 'diluxone-users' ); ?></button>
		<?php endif; ?>
	</form>

	<?php if ( count( $methods ) > 1 ) : ?>
		<p class="diluxone-users-note">
			<?php esc_html_e( 'Or use:', 'diluxone-users' ); ?>
			<?php foreach ( $methods as $diluxone_users_id => $diluxone_users_m ) : ?>
				<?php if ( $diluxone_users_id !== $method ) : ?>
					<a href="<?php echo esc_url( diluxone_users_2fa_url( $user_id, $key, (string) $diluxone_users_id ) ); ?>"><?php echo esc_html( $diluxone_users_m['label'] ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<p class="diluxone-users-note"><?php esc_html_e( 'Lost the phone and the email? Use one of your backup codes: they go in the same box.', 'diluxone-users' ); ?></p>
</div>
