<?php
/**
 * Security: how they get in, the passkeys, the second factor and where their
 * sessions are open.
 *
 * The order is not accidental. First how they get in today; then the
 * passkeys, which are the safest way and the one that asks for no extra step;
 * then the second factor, which is what is added to the ways in that do need
 * it; and last the authenticator app, which is a detail of that second factor
 * and makes no sense before turning it on.
 *
 * What it is given, in `$args`:
 *
 * - `user`
 *
 * @var array{user: WP_User} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_id      = (int) $args['user']->ID;
$diluxone_users_offers  = diluxone_users_2fa_offered( $diluxone_users_id );
$diluxone_users_on      = diluxone_users_2fa_on( $diluxone_users_id );
$diluxone_users_ready   = diluxone_users_2fa_available( $diluxone_users_id );
$diluxone_users_frescos = diluxone_users_backup_fresh( $diluxone_users_id );
$diluxone_users_piden   = diluxone_users_2fa_ways_asked( $diluxone_users_id );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks the message.
$diluxone_users_notice = isset( $_GET['diluxone-users'] ) ? sanitize_key( wp_unslash( $_GET['diluxone-users'] ) ) : '';

$diluxone_users_notices = array(
	'on'          => array( 'ok', __( 'Two-step verification is on.', 'diluxone-users' ) ),
	'off'         => array( 'ok', __( 'Two-step verification is off.', 'diluxone-users' ) ),
	'totp'        => array( 'ok', __( 'Your authenticator app is set up.', 'diluxone-users' ) ),
	'totpoff'     => array( 'ok', __( 'The authenticator app was removed.', 'diluxone-users' ) ),
	'backup'      => array( 'ok', __( 'New backup codes. The old ones no longer work.', 'diluxone-users' ) ),
	'badcode'     => array( 'error', __( 'That code is not right. Check the app and try again — they change every thirty seconds.', 'diluxone-users' ) ),
	'nomethod'    => array( 'error', __( 'First set up a way to receive the second step.', 'diluxone-users' ) ),
	'notoffered'  => array( 'error', __( 'Two-step verification is not available on this site right now.', 'diluxone-users' ) ),
	'required'    => array( 'error', __( 'This site requires two-step verification: it cannot be turned off.', 'diluxone-users' ) ),
	'reauth'      => array( 'error', __( 'That needs a current code — from your app, one of your backup codes, or one we email you — and it was missing or not right.', 'diluxone-users' ) ),
	'codesent'    => array( 'ok', __( 'We emailed you a code. Type it in below and try again.', 'diluxone-users' ) ),
	'passkeyoff'  => array( 'ok', __( 'The passkey was removed.', 'diluxone-users' ) ),
	'passkeyname' => array( 'ok', __( 'The passkey has a new name.', 'diluxone-users' ) ),
);

if ( diluxone_users_has_passkeys() ) {
	diluxone_users_passkeys_enqueue();
}
?>

<?php if ( isset( $diluxone_users_notices[ $diluxone_users_notice ] ) ) : ?>
	<p class="diluxone-users-notice diluxone-users-notice--<?php echo esc_attr( $diluxone_users_notices[ $diluxone_users_notice ][0] ); ?>"><?php echo esc_html( $diluxone_users_notices[ $diluxone_users_notice ][1] ); ?></p>
<?php endif; ?>

<?php diluxone_users_panel_open( __( 'How you get in', 'diluxone-users' ), true ); ?>
	<dl class="diluxone-users-data">
		<dt><?php esc_html_e( 'Email', 'diluxone-users' ); ?></dt>
		<dd><?php echo esc_html( $args['user']->user_email ); ?></dd>
		<dt><?php esc_html_e( 'Password', 'diluxone-users' ); ?></dt>
		<dd>
			<?php
			echo diluxone_users_login_has_password()
				? esc_html__( 'The one on your WordPress account.', 'diluxone-users' )
				: esc_html__( 'You do not have one. You get in with a link sent to your email, or with a social account.', 'diluxone-users' );
			?>
		</dd>
	</dl>
<?php diluxone_users_panel_close(); ?>

<?php
/*
 * The passkeys go before the second factor because they are the better
 * answer to the same problem, not an accessory to the previous answer:
 * whoever can use them needs nothing of what comes below.
 */
?>
<?php if ( diluxone_users_has_passkeys() ) : ?>
	<?php diluxone_users_panel_open( __( 'Passkeys', 'diluxone-users' ) ); ?>
		<p><?php esc_html_e( 'The way in with no password and nothing to type: the fingerprint, the face or the PIN of your own device. The key never leaves it, there is nothing on our side worth stealing, and it cannot be used on a fake site pretending to be this one.', 'diluxone-users' ); ?></p>

		<p class="diluxone-users-note"><?php esc_html_e( 'When you get in with a passkey we do not ask for a second step: the passkey already is two of them in one — the device you have, and the fingerprint, face or PIN that unlocks it.', 'diluxone-users' ); ?></p>

		<p class="diluxone-users-notice" data-diluxone-users-passkey-notice hidden></p>

		<?php $diluxone_users_keys = diluxone_users_passkeys_here( $diluxone_users_id ); ?>

		<?php if ( array() !== $diluxone_users_keys ) : ?>
			<ul class="diluxone-users-keys">
				<?php foreach ( $diluxone_users_keys as $diluxone_users_n => $diluxone_users_key ) : ?>
					<li>
						<?php
						/*
						 * The name is read, not edited: a row with a text
						 * field always open looks like a half-filled form.
						 * It opens when asked for, and inside it lives the
						 * removal too, which is what is best not kept one
						 * click away.
						 */
						?>
						<details class="diluxone-users-key">
							<summary class="diluxone-users-key__row">
								<span class="diluxone-users-key__logo" aria-hidden="true">
									<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2a8 8 0 0 0-7.7 10.2L2 16.5V22h5.5v-2.5H10V17h2.5l1.3-1.3A8 8 0 1 0 14 2m3 6.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3"/></svg>
								</span>

								<span class="diluxone-users-key__who">
									<strong><?php echo esc_html( (string) $diluxone_users_key['label'] ); ?></strong>
									<span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: date it was added */
												__( 'Added on %s', 'diluxone-users' ),
												wp_date( 'j M Y', (int) $diluxone_users_key['created'] )
											)
										);

										if ( (int) $diluxone_users_key['used'] > 0 ) {
											echo ' · ' . esc_html(
												sprintf(
													/* translators: %s: how long ago it was used */
													__( 'used %s ago', 'diluxone-users' ),
													human_time_diff( (int) $diluxone_users_key['used'] )
												)
											);
										}
										?>
									</span>
								</span>

								<span class="diluxone-users-key__arrow" aria-hidden="true"></span>
							</summary>

							<form class="diluxone-users-key__edit" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="diluxone_users_passkey">
								<input type="hidden" name="diluxone_users_passkey" value="<?php echo esc_attr( (string) $diluxone_users_key['id'] ); ?>">
								<?php wp_nonce_field( 'diluxone_users_passkey' ); ?>

								<label for="diluxone-users-key-<?php echo esc_attr( (string) $diluxone_users_n ); ?>"><?php esc_html_e( 'Name of this passkey', 'diluxone-users' ); ?></label>
								<input type="text" id="diluxone-users-key-<?php echo esc_attr( (string) $diluxone_users_n ); ?>" name="diluxone_users_passkey_label" value="<?php echo esc_attr( (string) $diluxone_users_key['label'] ); ?>" maxlength="60">

								<span class="diluxone-users-key__actions">
									<button type="submit" name="diluxone_users_passkey_do" value="rename" class="diluxone-users-button"><?php esc_html_e( 'Save name', 'diluxone-users' ); ?></button>
									<button type="submit" name="diluxone_users_passkey_do" value="delete" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Remove this passkey', 'diluxone-users' ); ?></button>
								</span>
							</form>
						</details>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php
		/*
		 * The name is asked for when registering and not afterwards: with two
		 * or three keys, "Passkey, Passkey, Passkey" tells nobody which one to
		 * remove when they lose the phone.
		 */
		?>
		<p class="diluxone-users-key-add">
			<span class="diluxone-users-key__field">
				<label for="diluxone-users-key-new"><?php esc_html_e( 'Name it, so you recognise it later', 'diluxone-users' ); ?></label>
				<input type="text" id="diluxone-users-key-new" data-diluxone-users-passkey-label maxlength="60" placeholder="<?php echo esc_attr( diluxone_users_passkey_label() ); ?>">
			</span>
			<button type="button" class="diluxone-users-button" data-diluxone-users-passkey="register"><?php esc_html_e( 'Add a passkey', 'diluxone-users' ); ?></button>
		</p>
	<?php diluxone_users_panel_close(); ?>
<?php endif; ?>

<?php if ( $diluxone_users_offers ) : ?>

	<?php if ( array() !== $diluxone_users_frescos ) : ?>
		<?php diluxone_users_panel_open( __( 'Write these down now', 'diluxone-users' ), true, 'diluxone-users-backup' ); ?>
			<p><?php esc_html_e( 'Each one gets you in once, if you lose the phone or the email. They are shown only this time: we keep them scrambled, so nobody —including us— can read them back.', 'diluxone-users' ); ?></p>
			<ul class="diluxone-users-backup__list">
				<?php foreach ( $diluxone_users_frescos as $diluxone_users_code ) : ?>
					<li><code><?php echo esc_html( $diluxone_users_code ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php diluxone_users_panel_close(); ?>
	<?php endif; ?>

	<?php diluxone_users_panel_open( __( 'Two-step verification', 'diluxone-users' ) ); ?>
		<p><?php esc_html_e( 'One more thing when you sign in: a code that only you have. If somebody gets hold of your email, they still do not get in.', 'diluxone-users' ); ?></p>

		<?php
		// What is said here has to be what is going to happen, and that changes
		// with how each person gets in: the e-mail-link exception belongs to the
		// e-mail link alone. With a social network turned on the second step is
		// asked for, and saying otherwise is worse than saying nothing.
		if ( $diluxone_users_on && ! diluxone_users_2fa_worth_it_on_link( $diluxone_users_id ) && diluxone_users_login_has_link() ) :
			?>
			<p class="diluxone-users-notice diluxone-users-notice--info">
				<?php if ( array() === $diluxone_users_piden ) : ?>
					<?php esc_html_e( 'Right now it is not being asked anywhere: the only way in you have is the link we email you, and the second step would be another code to that same inbox — it would not prove anything new.', 'diluxone-users' ); ?>
				<?php else : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: list of ways in, already comma-separated */
							__( 'It is not asked when you get in with the link we email you: that link already proves you have the inbox, and the code would go to the same place. It is asked when you get in with %s.', 'diluxone-users' ),
							wp_sprintf( '%l', $diluxone_users_piden )
						)
					);
					?>
				<?php endif; ?>

				<?php if ( ! diluxone_users_totp_ready( $diluxone_users_id ) ) : ?>
					<?php esc_html_e( 'Set up the authenticator app below and it starts being asked with the link too.', 'diluxone-users' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<p>
			<span class="diluxone-users-chip">
			<?php
				echo $diluxone_users_on
					? esc_html__( 'On', 'diluxone-users' )
					: esc_html__( 'Off', 'diluxone-users' );
			?>
			</span>
			<?php if ( 'required' === (string) diluxone_users_option( 'diluxone_users_2fa_mode' ) ) : ?>
				<span class="diluxone-users-note"><?php esc_html_e( 'This site requires it.', 'diluxone-users' ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( array() !== $diluxone_users_ready ) : ?>
			<p class="diluxone-users-note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: list of available methods */
						__( 'Ready to use: %s.', 'diluxone-users' ),
						implode( ', ', wp_list_pluck( $diluxone_users_ready, 'label' ) )
					)
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="diluxone_users_security">
			<?php wp_nonce_field( 'diluxone_users_security' ); ?>

			<?php if ( $diluxone_users_on ) : ?>
				<?php
				/*
				 * Turning it off or replacing the codes asks for a current
				 * code first: a session alone must not be able to undo the
				 * second step. Whoever has no app asks for one by e-mail.
				 */
				?>
				<label for="diluxone-users-reauth-code"><?php esc_html_e( 'A current code, to confirm it is you', 'diluxone-users' ); ?></label>
				<input type="text" id="diluxone-users-reauth-code" name="diluxone_users_code" inputmode="numeric" autocomplete="one-time-code" maxlength="20">
				<p class="diluxone-users-note"><?php esc_html_e( 'From your app, one of your backup codes, or the one we email you.', 'diluxone-users' ); ?></p>
				<?php if ( isset( $diluxone_users_ready['email'] ) ) : ?>
					<button type="submit" name="diluxone_users_security" value="code" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Email me a code', 'diluxone-users' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! $diluxone_users_on ) : ?>
				<button type="submit" name="diluxone_users_security" value="on" class="diluxone-users-button"><?php esc_html_e( 'Turn it on', 'diluxone-users' ); ?></button>
			<?php elseif ( diluxone_users_2fa_can_turn_off( $diluxone_users_id ) ) : ?>
				<button type="submit" name="diluxone_users_security" value="off" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Turn it off', 'diluxone-users' ); ?></button>
			<?php endif; ?>

			<?php if ( $diluxone_users_on ) : ?>
				<button type="submit" name="diluxone_users_security" value="backup" class="diluxone-users-button diluxone-users-button--soft">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: codes they have left */
							__( 'New backup codes (%d left)', 'diluxone-users' ),
							diluxone_users_backup_left( $diluxone_users_id )
						)
					);
					?>
				</button>
			<?php endif; ?>
		</form>
	<?php diluxone_users_panel_close(); ?>

	<?php if ( isset( diluxone_users_2fa_methods()['totp'] ) ) : ?>
		<?php diluxone_users_panel_open( __( 'Authenticator app', 'diluxone-users' ) ); ?>

			<?php if ( diluxone_users_totp_ready( $diluxone_users_id ) ) : ?>
				<p><?php esc_html_e( 'Set up. When you sign in, we ask for the six-digit code from the app.', 'diluxone-users' ); ?></p>
				<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="diluxone_users_security">
					<?php wp_nonce_field( 'diluxone_users_security' ); ?>
					<label for="diluxone-users-totp-off-code"><?php esc_html_e( 'The code from the app, to confirm it is you', 'diluxone-users' ); ?></label>
					<input type="text" id="diluxone-users-totp-off-code" name="diluxone_users_code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required>
					<button type="submit" name="diluxone_users_security" value="totp_off" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Remove it', 'diluxone-users' ); ?></button>
				</form>
				<?php
			else :
				$diluxone_users_secret = diluxone_users_totp_pending( $diluxone_users_id );
				$diluxone_users_uri    = diluxone_users_totp_uri( $diluxone_users_id, $diluxone_users_secret );
				?>
				<p><?php esc_html_e( 'Scan this with Google Authenticator, 1Password, Aegis or whichever app you use, and then write down the code it shows to confirm it.', 'diluxone-users' ); ?></p>

				<div class="diluxone-users-totp">
					<div class="diluxone-users-totp__qr"><?php echo wp_kses( diluxone_users_qr_svg( $diluxone_users_uri, 190 ), diluxone_users_allowed_html() ); ?></div>

					<div class="diluxone-users-totp__manual">
						<p class="diluxone-users-note"><?php esc_html_e( 'Cannot scan it? Type this key into the app:', 'diluxone-users' ); ?></p>
						<p><code class="diluxone-users-totp__key"><?php echo esc_html( diluxone_users_totp_readable( $diluxone_users_secret ) ); ?></code></p>

						<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="diluxone_users_security">
							<input type="hidden" name="diluxone_users_security" value="totp">
							<?php wp_nonce_field( 'diluxone_users_security' ); ?>

							<label for="diluxone-users-totp-code"><?php esc_html_e( 'The code from the app', 'diluxone-users' ); ?></label>
							<input type="text" id="diluxone-users-totp-code" name="diluxone_users_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required>

							<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Confirm', 'diluxone-users' ); ?></button>
						</form>
					</div>
				</div>
			<?php endif; ?>
		<?php diluxone_users_panel_close(); ?>
	<?php endif; ?>

<?php endif; ?>

<?php
/*
 * It goes in a panel like everything else on this screen: a loose heading
 * inherits the theme's h3 size and ends up a different weight from the ones
 * above. Here every block is the same box.
 */
?>
<?php if ( diluxone_users_option( 'diluxone_users_sessions_show' ) ) : ?>
	<?php diluxone_users_panel_open( __( 'Where you are signed in', 'diluxone-users' ) ); ?>
		<?php diluxone_users_sessions_list(); ?>
	<?php diluxone_users_panel_close(); ?>
<?php endif; ?>
