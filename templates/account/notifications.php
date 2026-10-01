<?php
/**
 * Notificaciones.
 *
 * Two lists in one, and on purpose. What a person can decide comes first,
 * with its switch; underneath, what this site sends them whatever they say —
 * the sign-in link, the second-step code — ticked, greyed out, and each one
 * saying why it cannot be turned off.
 *
 * Leaving those two out made the section a half-answer: the e-mails somebody
 * actually receives most often would have been the two missing from the list
 * of what this site e-mails them. They are shown the way any site worth
 * copying shows them — visible, and plainly not up for discussion.
 *
 * What it is given, in `$args`:
 *
 * - `prefs` Notices with a switch.
 * - `musts` Notices without one.
 * - `user`
 *
 * @var array{prefs: array<string, array<string, string>>, musts: array<string, array<string, string>>, user: WP_User} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

// The two lists are drawn by one loop: they differ in whether the box can be
// touched, and nowhere else. A person reading down the list is reading one
// list — everything this site sends them.
$diluxone_users_rows = array();

foreach ( $args['prefs'] as $diluxone_users_key => $diluxone_users_pref ) {
	$diluxone_users_saved = get_user_meta( $args['user']->ID, $diluxone_users_key, true );

	$diluxone_users_rows[] = array(
		'key'    => (string) $diluxone_users_key,
		'label'  => (string) $diluxone_users_pref['label'],
		'help'   => (string) ( $diluxone_users_pref['help'] ?? '' ),
		'on'     => '' === (string) $diluxone_users_saved ? ! empty( $diluxone_users_pref['default'] ) : (bool) $diluxone_users_saved,
		'locked' => false,
	);
}

foreach ( $args['musts'] as $diluxone_users_key => $diluxone_users_must ) {
	$diluxone_users_rows[] = array(
		'key'    => (string) $diluxone_users_key,
		'label'  => (string) $diluxone_users_must['label'],
		// The reason goes with the notice and not in a line at the foot: it is
		// the answer to the question the greyed-out box just raised.
		'help'   => trim( (string) ( $diluxone_users_must['help'] ?? '' ) . ' ' . (string) ( $diluxone_users_must['why'] ?? '' ) ),
		'on'     => true,
		'locked' => true,
	);
}

$diluxone_users_choose = array() !== $args['prefs'];
?>

<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks which message to show. ?>
<?php if ( isset( $_GET['diluxone-users'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['diluxone-users'] ) ) ) : ?>
	<p class="diluxone-users-notice diluxone-users-notice--ok"><?php esc_html_e( 'Saved.', 'diluxone-users' ); ?></p>
<?php endif; ?>

<?php if ( array() === $diluxone_users_rows ) : ?>
	<p><?php esc_html_e( 'This site does not email you about anything.', 'diluxone-users' ); ?></p>
<?php else : ?>
	<?php diluxone_users_panel_open( __( 'What we email you about', 'diluxone-users' ), true ); ?>

	<?php if ( ! $diluxone_users_choose ) : ?>
		<p><?php esc_html_e( 'There is nothing to choose here: this site decides what it sends. This is what reaches you.', 'diluxone-users' ); ?></p>
	<?php endif; ?>

	<?php if ( $diluxone_users_choose ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="diluxone-users-form">
		<input type="hidden" name="action" value="diluxone_users_notifications">
		<?php wp_nonce_field( 'diluxone_users_notifications' ); ?>
	<?php endif; ?>

		<?php foreach ( $diluxone_users_rows as $diluxone_users_row ) : ?>
			<label class="diluxone-users-check">
				<?php
				/*
				 * A box that is off and cannot be turned on would be a promise
				 * broken twice, so a locked one is always ticked: it is ticked
				 * because the e-mail really does go out.
				 */
				?>
				<input type="checkbox" name="<?php echo esc_attr( $diluxone_users_row['key'] ); ?>" value="1" <?php checked( $diluxone_users_row['on'] ); ?> <?php disabled( $diluxone_users_row['locked'] ); ?>>
				<span>
					<?php echo esc_html( $diluxone_users_row['label'] ); ?>
					<?php if ( $diluxone_users_row['locked'] ) : ?>
						<span class="diluxone-users-pill"><?php esc_html_e( 'Always sent', 'diluxone-users' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $diluxone_users_row['help'] ) : ?>
						<small><?php echo esc_html( $diluxone_users_row['help'] ); ?></small>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>

	<?php if ( $diluxone_users_choose ) : ?>
		<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Save', 'diluxone-users' ); ?></button>
	</form>
	<?php endif; ?>

	<?php diluxone_users_panel_close(); ?>
<?php endif; ?>
