<?php
/**
 * Security: how they get in, the second factor and where their sessions are
 * open.
 *
 * The order is not accidental. First how they get in today; then whatever an
 * add-on brings in front of the second step (`diluxone_users_account_security`)
 * — a way in that already proves two things is the better answer to the same
 * problem, so it goes before the step it makes unnecessary; then the second
 * factor, which is what is added to the ways in that do need it; and last
 * whatever an add-on adds to that second factor
 * (`diluxone_users_account_security_2fa`), which makes no sense before
 * turning it on.
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
	'on'         => array( 'ok', __( 'Two-step verification is on.', 'diluxone-users' ) ),
	'off'        => array( 'ok', __( 'Two-step verification is off.', 'diluxone-users' ) ),
	'backup'     => array( 'ok', __( 'New backup codes. The old ones no longer work.', 'diluxone-users' ) ),
	'nomethod'   => array( 'error', __( 'First set up a way to receive the second step.', 'diluxone-users' ) ),
	'notoffered' => array( 'error', __( 'Two-step verification is not available on this site right now.', 'diluxone-users' ) ),
	'required'   => array( 'error', __( 'This site requires two-step verification: it cannot be turned off.', 'diluxone-users' ) ),
	'reauth'     => array( 'error', __( 'That needs a current code — one of your backup codes, or one we email you — and it was missing or not right.', 'diluxone-users' ) ),
	'codesent'   => array( 'ok', __( 'We emailed you a code. Type it in below and try again.', 'diluxone-users' ) ),
);

/**
 * Filters the notices the Security section can show after an action.
 *
 * The key is the `diluxone-users=` word the action redirects back with; the
 * value is the kind (`ok` or `error`) and the sentence. An add-on whose panel
 * on this section has an action of its own adds its words here.
 *
 * @since 1.0.0
 *
 * @param array<string, array{0: string, 1: string}> $notices The plugin's own.
 * @param WP_User                                    $user    Whose account it is.
 */
$diluxone_users_notices = (array) apply_filters( 'diluxone_users_security_notices', $diluxone_users_notices, $args['user'] );
?>

<?php if ( isset( $diluxone_users_notices[ $diluxone_users_notice ][0], $diluxone_users_notices[ $diluxone_users_notice ][1] ) ) : ?>
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
/**
 * Fires between how somebody gets in and the second step.
 *
 * Where an add-on draws a panel of its own on the account's Security
 * section, with `diluxone_users_panel_open()` and `diluxone_users_panel_close()`
 * like the plugin's. A way in that already proves two things belongs here:
 * it is the better answer to the problem the second step solves, not an
 * accessory to it. Its forms post to an action of its own, or to
 * `diluxone_users_security` with a word of its own
 * (`diluxone_users_security_action`).
 *
 * @since 1.0.0
 *
 * @param WP_User $user Whose account it is.
 */
do_action( 'diluxone_users_account_security', $args['user'] );
?>

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

				<?php
				// A method the site offers that does not arrive by e-mail, and
				// that this person has not set up yet: the way to be asked on
				// the link too.
				foreach ( diluxone_users_2fa_methods() as $diluxone_users_method_id => $diluxone_users_method ) {
					if ( 'email' !== ( $diluxone_users_method['channel'] ?? 'email' ) && ! isset( $diluxone_users_ready[ $diluxone_users_method_id ] ) ) {
						esc_html_e( 'Set up a way of getting the code that is not an email, below, and it starts being asked with the link too.', 'diluxone-users' );
						break;
					}
				}
				?>
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
				<p class="diluxone-users-note"><?php esc_html_e( 'Any code you can get for the second step: one of your backup codes, or the one we email you.', 'diluxone-users' ); ?></p>
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

	<?php
	/**
	 * Fires after the second step's panel, while the site offers the second
	 * step to this person.
	 *
	 * Where an add-on draws what it adds to the second step, in a panel of
	 * its own: Users+ Pro's authenticator app. Its forms post to
	 * `diluxone_users_security` with a word of its own
	 * (`diluxone_users_security_action`), and its notices are added through
	 * `diluxone_users_security_notices`.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_User $user Whose account it is.
	 */
	do_action( 'diluxone_users_account_security_2fa', $args['user'] );
	?>

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
