<?php
/**
 * The sign-in form.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/login.php
 *
 * What it is given, in `$args`:
 *
 * - `state`     What happened ('sent', 'expired', 'email', 'error', 'social', 'confirm', 'closed').
 * - `email`     What the person typed to ask for the link.
 * - `providers` Available networks, for a theme that draws its own.
 * - `minutes`   How long the link is good for.
 * - `title`     Whether to draw the "Sign in" heading.
 *
 * @var array{state: string, email: string, providers: array<string, array<string,mixed>>, minutes: int, title: bool} $args
 *
 * The shape of the page around this — the card, the split, the background —
 * is not in here: it wraps both steps of signing in, so it is printed by
 * diluxone_users_login_frame_open() and a theme replacing this file still
 * gets it.
 *
 * Neither are the ways in. This file used to stack them in an order written
 * by hand, which is why nobody could change it and why the page grew down the
 * screen without anybody deciding it would. Each one is registered now — see
 * includes/login-ways.php — and diluxone_users_ways_render() draws whatever
 * this site has, stacked or in tabs, in the site's own order. A theme with
 * its own copy of this file gets the same, including a way an add-on added.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users diluxone-users-login">

	<?php diluxone_users_login_logo(); ?>

	<?php if ( 'sent' === $args['state'] ) : ?>

		<p class="diluxone-users-login__icon <?php echo 'circle' === diluxone_users_option( 'diluxone_users_sent_icon' ) ? 'diluxone-users-login__icon--circle' : ''; ?>"><?php echo wp_kses( diluxone_users_icon( 'mail' ), diluxone_users_allowed_html() ); ?></p>
		<h2 class="diluxone-users-login__title"><?php echo esc_html( diluxone_users_text( 'diluxone_users_sent_title', __( 'Check your email', 'diluxone-users' ) ) ); ?></h2>
		<p><?php esc_html_e( 'We sent a sign-in link to', 'diluxone-users' ); ?></p>
		<p class="diluxone-users-login__email"><strong><?php echo esc_html( $args['email'] ); ?></strong></p>
		<p class="diluxone-users-note">
			<?php
			printf(
				/* translators: %d: how many minutes the link lasts */
				esc_html__( 'Click the link and you are in. It expires in %d minutes and works once.', 'diluxone-users' ),
				(int) $args['minutes']
			);
			?>
		</p>

		<?php
		/*
		 * The way out of this screen. Somebody who typed one letter wrong is
		 * looking at a page telling them to check an inbox that will never
		 * have anything in it, and without this the only way back is the
		 * browser's back button — or waiting for a link that is not coming.
		 */
		?>
		<p class="diluxone-users-login__again">
			<a class="diluxone-users-button diluxone-users-button--soft diluxone-users-button--wide" href="<?php echo esc_url( diluxone_users_login_url() ); ?>">
				<?php esc_html_e( 'Use a different address', 'diluxone-users' ); ?>
			</a>
		</p>

		<p class="diluxone-users-note diluxone-users-note--icon">
			<?php echo wp_kses( diluxone_users_icon( 'info' ), diluxone_users_allowed_html() ); ?>
			<span><?php echo esc_html( diluxone_users_text( 'diluxone_users_sent_note', __( 'Did not arrive? Check your spam or promotions folder.', 'diluxone-users' ) ) ); ?></span>
		</p>

	<?php else : ?>

		<?php
		// The heading shows when the shortcode was asked for one, and also
		// whenever the site wrote its own: somebody who takes the trouble to
		// name this screen means it to be read.
		$diluxone_users_heading = diluxone_users_text( 'diluxone_users_login_title' );
		$diluxone_users_intro   = diluxone_users_text( 'diluxone_users_login_intro' );
		?>

		<?php if ( $args['title'] || '' !== $diluxone_users_heading ) : ?>
			<h2 class="diluxone-users-login__title">
				<?php echo esc_html( '' !== $diluxone_users_heading ? $diluxone_users_heading : __( 'Sign in', 'diluxone-users' ) ); ?>
			</h2>
		<?php endif; ?>

		<?php if ( '' !== $diluxone_users_intro ) : ?>
			<p class="diluxone-users-login__intro"><?php echo esc_html( $diluxone_users_intro ); ?></p>
		<?php endif; ?>

		<?php
		/*
		 * What each of these says is not written here any more: the site can
		 * rewrite every one of them from the dashboard, in each of its
		 * languages, and a filter can have the last word. This decides WHICH
		 * message, which is the template's business; the wording is
		 * includes/login-messages.php.
		 */
		$diluxone_users_says = array(
			'changed' => 'login_changed',
			'expired' => 'login_expired',
			'email'   => 'login_email',
			'social'  => 'login_social',
			'error'   => 'login_error',
			'confirm' => 'login_confirm',
			'closed'  => 'login_closed',
		);
		?>

		<?php if ( isset( $diluxone_users_says[ $args['state'] ] ) ) : ?>
			<?php diluxone_users_login_notice( $diluxone_users_says[ $args['state'] ] ); ?>
		<?php endif; ?>

		<?php diluxone_users_ways_render(); ?>

		<?php
		/*
		 * The line about the terms goes last and inside the "else": on the
		 * screen that only says an e-mail was sent there is nothing left to
		 * accept. It allows links because that is what it is for — the terms
		 * and the privacy policy are pages, and a legal line that cannot link
		 * to them is not one.
		 */
		$diluxone_users_legal = diluxone_users_text( 'diluxone_users_login_legal' );
		?>

		<?php if ( '' !== $diluxone_users_legal ) : ?>
			<p class="diluxone-users-login__legal"><?php echo wp_kses_post( $diluxone_users_legal ); ?></p>
		<?php endif; ?>

	<?php endif; ?>
</div>
