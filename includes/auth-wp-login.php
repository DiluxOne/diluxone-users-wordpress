<?php
/**
 * The second step on wp-login.php.
 *
 * The plugin's own sign-in page draws the second step when somebody is
 * halfway in. A site with no such page — the plugin installed and nothing
 * chosen yet, or a page that was deleted — still has people signing in, on
 * wp-login.php, and the challenge used to send them back there with nothing
 * to draw it: WordPress showed its password form again, the code that had
 * just arrived by e-mail had nowhere to go, and somebody who did the
 * responsible thing and turned the second step on was locked out of their
 * own site.
 *
 * So wp-login.php draws it, with WordPress's own markup — the site's face
 * on it when Design › WordPress's screens says so, like every other screen of
 * wp-login.php — and the same handler (`diluxone_users_2fa_handle()`, on
 * `init`) checks what is typed. It is an action of wp-login.php's own,
 * `?action=diluxone_users_2fa`: WordPress keeps an unknown action only when
 * something listens on `login_form_{action}`, which is exactly this.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The wp-login.php action that draws the second step when there is no sign-in page. */
const DILUXONE_USERS_2FA_ACTION = 'diluxone_users_2fa';

/**
 * The screen: the code box, the methods, and the way out with a backup code.
 *
 * With no attempt behind the address — it expired, it was spent, it was
 * typed by hand — there is nothing to finish, and the person is sent to the
 * start with a sentence saying so.
 */
function diluxone_users_2fa_wp_login(): void {
	$challenge = diluxone_users_login_challenge();

	if ( array() === $challenge ) {
		wp_safe_redirect( diluxone_users_2fa_restart_url() );
		exit;
	}

	$user_id = (int) $challenge['user_id'];
	$key     = (string) $challenge['key'];
	$method  = (string) $challenge['method'];
	$methods = (array) $challenge['methods'];
	$actual  = (array) ( $methods[ $method ] ?? array() );
	$days    = (int) diluxone_users_option( 'diluxone_users_2fa_remember_days' );

	// The three states the page shows, in the words the site may have
	// rewritten on Access › Messages: one list of sentences for both screens.
	$says   = array(
		'locked' => 'two_step_locked',
		'code'   => 'two_step_wrong',
		'sent'   => 'two_step_sent',
	);
	$state  = diluxone_users_state();
	$errors = new WP_Error();

	if ( isset( $says[ $state ] ) ) {
		$text = diluxone_users_login_message( $says[ $state ] );

		if ( '' !== $text ) {
			$errors->add( 'diluxone_users_' . $state, esc_html( $text ), 'sent' === $state ? 'message' : null );
		}
	}

	login_header( __( 'One more step', 'diluxone-users' ), '', $errors );
	?>
	<div class="diluxone-users-login--2fa">
		<form name="diluxone-users-2fa" id="loginform" method="post" action="<?php echo esc_url( diluxone_users_2fa_url( $user_id, $key, $method ) ); ?>">
			<p><?php echo esc_html( (string) ( $actual['help'] ?? '' ) ); ?></p>

			<input type="hidden" name="diluxone_users_2fa_user" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<input type="hidden" name="diluxone_users_2fa_key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="diluxone_users_2fa_method" value="<?php echo esc_attr( $method ); ?>">

			<p>
				<label for="diluxone-users-2fa-code"><?php esc_html_e( 'The code', 'diluxone-users' ); ?></label>
				<input type="text" id="diluxone-users-2fa-code" name="diluxone_users_2fa_code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="20" size="20" required>
			</p>

			<?php if ( $days > 0 ) : ?>
				<p class="forgetmenot">
					<input type="checkbox" id="diluxone-users-2fa-trust" name="diluxone_users_2fa_trust" value="1">
					<label for="diluxone-users-2fa-trust">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: days */
								__( 'Do not ask again on this browser for %d days', 'diluxone-users' ),
								$days
							)
						);
						?>
					</label>
				</p>
			<?php endif; ?>

			<p class="submit">
				<input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Confirm', 'diluxone-users' ); ?>">
				<?php
				// `formnovalidate`, for the reason the page's template gives:
				// the box is required, and asking for another code is the
				// press for when it is empty.
				?>
				<?php if ( isset( $actual['send'] ) ) : ?>
					<button type="submit" name="diluxone_users_2fa_resend" value="1" formnovalidate class="button button-large"><?php esc_html_e( 'Send it again', 'diluxone-users' ); ?></button>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( count( $methods ) > 1 ) : ?>
			<p id="nav">
				<?php esc_html_e( 'Or use:', 'diluxone-users' ); ?>
				<?php foreach ( $methods as $id => $other ) : ?>
					<?php if ( $id !== $method ) : ?>
						<a href="<?php echo esc_url( diluxone_users_2fa_url( $user_id, $key, (string) $id ) ); ?>"><?php echo esc_html( (string) $other['label'] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<p id="backtoblog"><?php esc_html_e( 'Lost the phone and the email? Use one of your backup codes: they go in the same box.', 'diluxone-users' ); ?></p>
	</div>
	<?php
	login_footer( 'diluxone-users-2fa-code' );
	exit;
}
add_action( 'login_form_' . DILUXONE_USERS_2FA_ACTION, 'diluxone_users_2fa_wp_login' );

/**
 * Says why somebody is back at wp-login.php's form.
 *
 * Two ways of arriving here with something gone: the second step's attempt
 * ran out — ten minutes, or five wrong codes — or, on a site with no sign-in
 * page, the e-mail link had already been used or had expired. WordPress's
 * form has nothing to say about either, and a form that silently reappears
 * reads as the code or the link having been taken.
 *
 * @param WP_Error $errors What WordPress is about to show.
 * @return WP_Error
 */
function diluxone_users_wp_login_states( $errors ) {
	if ( ! $errors instanceof WP_Error ) {
		return $errors;
	}

	$state = diluxone_users_state();

	if ( 'retry' === $state ) {
		$errors->add( 'diluxone_users_retry', esc_html__( 'That attempt ran out of time or of tries. Sign in again and a new code is sent.', 'diluxone-users' ) );
	} elseif ( 'expired' === $state ) {
		$text = diluxone_users_login_message( 'login_expired' );

		if ( '' !== $text ) {
			$errors->add( 'diluxone_users_expired', esc_html( $text ) );
		}
	}

	return $errors;
}
add_filter( 'wp_login_errors', 'diluxone_users_wp_login_states' );
