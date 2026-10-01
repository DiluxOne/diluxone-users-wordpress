<?php
/**
 * Choosing a new password.
 *
 * Reached from the link in the e-mail WordPress sends. By the time this
 * draws, the key has been checked and the person it belongs to is known: what
 * is left is to type the new password twice.
 *
 * What it is given, in `$args`:
 *
 * - `user`  Whose password is being changed.
 * - `state` What happened on the way here.
 *
 * @var array{user: WP_User, state: string} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users diluxone-users-login diluxone-users-login--reset">

	<?php diluxone_users_login_logo(); ?>

	<h2 class="diluxone-users-login__title"><?php esc_html_e( 'Choose a new password', 'diluxone-users' ); ?></h2>

	<p class="diluxone-users-login__intro">
		<?php
		printf(
			/* translators: %s: the account's e-mail address */
			esc_html__( 'For %s', 'diluxone-users' ),
			'<strong>' . esc_html( $args['user']->user_email ) . '</strong>'
		);
		?>
	</p>

	<?php if ( 'nomatch' === $args['state'] ) : ?>
		<?php diluxone_users_login_notice( 'reset_mismatch' ); ?>
	<?php endif; ?>

	<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="diluxone_users_reset">
		<?php wp_nonce_field( 'diluxone_users_reset', 'diluxone_users_reset_nonce' ); ?>

		<div class="diluxone-users-field">
			<label for="diluxone-users-pass"><?php esc_html_e( 'New password', 'diluxone-users' ); ?></label>
			<input type="password" id="diluxone-users-pass" name="diluxone_users_pass" required autocomplete="new-password" autofocus>
		</div>

		<div class="diluxone-users-field">
			<label for="diluxone-users-pass2"><?php esc_html_e( 'And again', 'diluxone-users' ); ?></label>
			<input type="password" id="diluxone-users-pass2" name="diluxone_users_pass2" required autocomplete="new-password">
		</div>

		<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Save it', 'diluxone-users' ); ?></button>
	</form>

	<?php if ( diluxone_users_login_has_link() ) : ?>
		<p class="diluxone-users-note diluxone-users-note--icon">
			<?php echo diluxone_users_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?>
			<span><?php esc_html_e( 'You do not have to do this: on this site a link by email gets you in without a password at all.', 'diluxone-users' ); ?></span>
		</p>
	<?php endif; ?>
</div>
