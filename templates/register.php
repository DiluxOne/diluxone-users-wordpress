<?php
/**
 * The registration form.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/register.php
 *
 * @var string                              $state     What happened.
 * @var array<int, array<string, mixed>>    $fields    What is asked for, besides the address.
 * @var bool                                $open      Whether the form takes accounts.
 * @var bool                                $by_link   Whether accounts are created by signing in with an e-mail link instead.
 * @var array<string, array<string, mixed>> $providers Networks, when they can create accounts.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users diluxone-users-login diluxone-users-register">

	<?php diluxone_users_login_logo(); ?>

	<?php if ( 'registered' === $state ) : ?>

		<p class="diluxone-users-login__icon"><?php echo diluxone_users_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?></p>
		<h2 class="diluxone-users-login__title">
			<?php echo esc_html( diluxone_users_text( 'diluxone_users_register_done', __( 'Your account is ready', 'diluxone-users' ) ) ); ?>
		</h2>
		<p><?php esc_html_e( 'We sent you a link to get in. There is no password to choose.', 'diluxone-users' ); ?></p>
		<p class="diluxone-users-note diluxone-users-note--icon">
			<?php echo diluxone_users_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?>
			<span><?php echo esc_html( diluxone_users_text( 'diluxone_users_sent_note', __( 'Did not arrive? Check your spam or promotions folder.', 'diluxone-users' ) ) ); ?></span>
		</p>

	<?php elseif ( ! $open && ! empty( $by_link ) ) : ?>

		<h2 class="diluxone-users-login__title"><?php esc_html_e( 'Sign in to create your account', 'diluxone-users' ); ?></h2>
		<p><?php esc_html_e( 'There is no form to fill in: sign in with your e-mail and the account is created the first time.', 'diluxone-users' ); ?></p>
		<p>
			<a class="diluxone-users-button diluxone-users-button--soft" href="<?php echo esc_url( diluxone_users_login_url() ); ?>">
				<?php esc_html_e( 'Sign in', 'diluxone-users' ); ?>
			</a>
		</p>

	<?php elseif ( ! $open ) : ?>

		<h2 class="diluxone-users-login__title"><?php esc_html_e( 'Registration is closed', 'diluxone-users' ); ?></h2>
		<p><?php esc_html_e( 'This site does not take new accounts right now. If you already have one, you can sign in.', 'diluxone-users' ); ?></p>
		<p>
			<a class="diluxone-users-button diluxone-users-button--soft" href="<?php echo esc_url( diluxone_users_login_url() ); ?>">
				<?php esc_html_e( 'Sign in', 'diluxone-users' ); ?>
			</a>
		</p>

	<?php else : ?>

		<?php
		$diluxone_users_heading = diluxone_users_text( 'diluxone_users_register_title', __( 'Create your account', 'diluxone-users' ) );
		$diluxone_users_intro   = diluxone_users_text( 'diluxone_users_register_intro' );
		?>

		<h2 class="diluxone-users-login__title"><?php echo esc_html( $diluxone_users_heading ); ?></h2>

		<?php if ( '' !== $diluxone_users_intro ) : ?>
			<p class="diluxone-users-login__intro"><?php echo esc_html( $diluxone_users_intro ); ?></p>
		<?php endif; ?>

		<?php
		// The same arrangement the sign-in page uses: this decides which
		// message, and includes/login-messages.php holds what it says. The one
		// with something after it is "taken" — the sentence tells somebody
		// their address is known, and the link is what they do about it, so it
		// travels with the message rather than underneath it.
		$diluxone_users_says = array(
			'taken'   => 'register_taken',
			'email'   => 'register_email',
			'missing' => 'register_missing',
			'slow'    => 'register_slow',
			'closed'  => 'register_closed',
			'error'   => 'register_error',
		);
		?>

		<?php if ( isset( $diluxone_users_says[ $state ] ) ) : ?>
			<?php
			diluxone_users_login_notice(
				$diluxone_users_says[ $state ],
				'taken' === $state
					? sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( diluxone_users_login_url() ),
						esc_html__( 'Sign in instead', 'diluxone-users' )
					)
					: ''
			);
			?>
		<?php endif; ?>

		<?php if ( array() !== $providers ) : ?>
			<?php echo diluxone_users_sso_buttons( $providers ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup, already escaped. ?>

			<p class="diluxone-users-divider"><span><?php esc_html_e( 'or with your email', 'diluxone-users' ); ?></span></p>
		<?php endif; ?>

		<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="diluxone_users_registro">
			<?php wp_nonce_field( 'diluxone_users_register', 'diluxone_users_register_nonce' ); ?>

			<?php
			// The same wrapper every other field form in the plugin uses. Left
			// to loose labels and inputs, the browser lays them out in a run-on
			// line — which is exactly what it did.
			?>
			<div class="diluxone-users-field diluxone-users-field--email">
				<label for="diluxone-users-email">
					<?php esc_html_e( 'Email address', 'diluxone-users' ); ?>
					<span class="diluxone-users-field__required" aria-hidden="true">*</span>
				</label>
				<input type="email" id="diluxone-users-email" name="diluxone_users_email" required autocomplete="email" placeholder="<?php echo esc_attr_x( 'you@example.com', 'placeholder for the e-mail field', 'diluxone-users' ); ?>">
			</div>

			<?php
			// Only what the fields screen marks as required. Everything else is
			// waiting in their account: a registration form that asks for
			// everything is a registration form nobody finishes.
			foreach ( $fields as $diluxone_users_field ) :
				?>
				<div class="diluxone-users-field diluxone-users-field--<?php echo esc_attr( $diluxone_users_field['type'] ); ?>">
					<?php if ( 'checkbox' !== $diluxone_users_field['type'] ) : ?>
						<label for="diluxone-users-<?php echo esc_attr( $diluxone_users_field['key'] ); ?>">
							<?php echo esc_html( $diluxone_users_field['label'] ); ?>
							<span class="diluxone-users-field__required" aria-hidden="true">*</span>
						</label>
					<?php endif; ?>

					<?php diluxone_users_field_input( $diluxone_users_field, '', 'diluxone-users-' . $diluxone_users_field['key'] ); ?>

					<?php if ( '' !== (string) $diluxone_users_field['help'] ) : ?>
						<p class="diluxone-users-field__help"><?php echo esc_html( (string) $diluxone_users_field['help'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Create my account', 'diluxone-users' ); ?></button>
		</form>

		<p class="diluxone-users-note diluxone-users-note--icon">
			<?php echo diluxone_users_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?>
			<span><?php esc_html_e( 'You get an email with a link. Click it and you are in: no password to choose or type.', 'diluxone-users' ); ?></span>
		</p>

		<p class="diluxone-users-note">
			<?php esc_html_e( 'Already have an account?', 'diluxone-users' ); ?>
			<a href="<?php echo esc_url( diluxone_users_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'diluxone-users' ); ?></a>
		</p>

		<?php
		// The same terms line as the sign-in screen, because it is the same
		// agreement: whichever door the account came through.
		$diluxone_users_legal = diluxone_users_text( 'diluxone_users_login_legal' );
		?>

		<?php if ( '' !== $diluxone_users_legal ) : ?>
			<p class="diluxone-users-login__legal"><?php echo wp_kses_post( $diluxone_users_legal ); ?></p>
		<?php endif; ?>

	<?php endif; ?>
</div>
