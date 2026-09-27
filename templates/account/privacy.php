<?php
/**
 * Privacy: downloading your data or asking for the account to be deleted.
 *
 * @var bool                $can_erase
 * @var array<int, WP_Post> $erasures
 * @var array<int, WP_Post> $exports
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks the message.
$diluxone_users_notice     = isset( $_GET['diluxone-users'] ) ? sanitize_key( wp_unslash( $_GET['diluxone-users'] ) ) : '';
$diluxone_users_estados    = diluxone_users_data_states();
$diluxone_users_mail_ready = diluxone_users_data_mail_ready();

/** Draws a table of requests. */
$diluxone_users_table = static function ( array $requests ) use ( $diluxone_users_estados ): void {
	if ( array() === $requests ) {
		return;
	}
	?>
	<table class="diluxone-users-table diluxone-users-requests">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Asked on', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Status', 'diluxone-users' ); ?></th>
				<th class="diluxone-users-requests__action"></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $requests as $diluxone_users_request ) :
				[ $diluxone_users_tono, $diluxone_users_text ] = $diluxone_users_estados[ $diluxone_users_request->post_status ] ?? array( 'off', $diluxone_users_request->post_status );
				$diluxone_users_file                           = diluxone_users_data_file( $diluxone_users_request );
				?>
				<tr>
					<td><?php echo esc_html( (string) wp_date( 'j M Y, H:i', (int) get_post_timestamp( $diluxone_users_request ) ) ); ?></td>
					<td><span class="diluxone-users-pill diluxone-users-pill--<?php echo esc_attr( $diluxone_users_tono ); ?>"><?php echo esc_html( $diluxone_users_text ); ?></span></td>
					<td class="diluxone-users-requests__action">
						<?php if ( '' !== $diluxone_users_file ) : ?>
							<a class="diluxone-users-button" href="<?php echo esc_url( $diluxone_users_file ); ?>" download><?php esc_html_e( 'Download', 'diluxone-users' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<p><?php esc_html_e( 'Everything this site knows about you is yours: you can take it with you, and you can ask us to erase it.', 'diluxone-users' ); ?></p>

<?php if ( 'requested' === $diluxone_users_notice ) : ?>
	<p class="diluxone-users-notice diluxone-users-notice--ok"><?php esc_html_e( 'We sent you an email to confirm it. Nothing happens until you click that link.', 'diluxone-users' ); ?></p>
<?php elseif ( 'admin' === $diluxone_users_notice ) : ?>
	<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'An account with admin permissions cannot ask for its own deletion.', 'diluxone-users' ); ?></p>
<?php elseif ( 'error' === $diluxone_users_notice ) : ?>
	<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'We could not create the request. There may already be one waiting.', 'diluxone-users' ); ?></p>
<?php endif; ?>

<?php if ( ! $diluxone_users_mail_ready && current_user_can( 'manage_options' ) ) : ?>
	<p class="diluxone-users-notice diluxone-users-notice--error">
		<?php esc_html_e( 'Heads up, this only shows to administrators: the site has no outgoing mail set up, so the confirmation email never arrives and every request stays waiting forever.', 'diluxone-users' ); ?>
	</p>
<?php endif; ?>

<?php if ( diluxone_users_option( 'diluxone_users_privacy_export' ) ) : ?>
	<?php diluxone_users_panel_open( __( 'Download your data', 'diluxone-users' ), true ); ?>
	<p><?php esc_html_e( 'A file with everything this site keeps about you: your details, and what the site and its plugins record about you. You get an email to confirm; once you do, we prepare it and it shows up here to download.', 'diluxone-users' ); ?></p>

	<?php $diluxone_users_table( $exports ); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="diluxone_users_data_request">
		<input type="hidden" name="diluxone_users_request" value="export">
		<?php wp_nonce_field( 'diluxone_users_data_request' ); ?>
		<button type="submit" class="diluxone-users-button">
			<?php
			echo array() === $exports
				? esc_html__( 'Ask for my data', 'diluxone-users' )
				: esc_html__( 'Ask for it again, up to date', 'diluxone-users' );
			?>
		</button>
	</form>
	<?php diluxone_users_panel_close(); ?>
<?php endif; ?>

<?php if ( diluxone_users_option( 'diluxone_users_privacy_delete' ) ) : ?>
	<?php
	/*
	 * The first box opens. If the one above is not there, this becomes the
	 * first and this is the one that opens.
	 */
	?>
	<?php diluxone_users_panel_open( __( 'Erase your data', 'diluxone-users' ), ! diluxone_users_option( 'diluxone_users_privacy_export' ), 'diluxone-users-panel--danger' ); ?>

	<?php if ( ! $can_erase ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--info">
			<?php esc_html_e( 'This account administers the site, so it cannot ask for its data to be erased: the site would be left with nobody in charge. Another administrator has to lower its role first, and then it can ask.', 'diluxone-users' ); ?>
		</p>
	<?php else : ?>
		<p><strong><?php esc_html_e( 'This cannot be undone.', 'diluxone-users' ); ?></strong></p>
		<ul class="diluxone-users-list-plain">
			<li><?php esc_html_e( 'What this site keeps about you is erased: your details, and what the site and its plugins record about you.', 'diluxone-users' ); ?></li>
			<li><?php esc_html_e( 'What you wrote in public stays, with no name on it.', 'diluxone-users' ); ?></li>
			<li><?php esc_html_e( 'Once the site has done it, nothing can be recovered — not by you and not by us.', 'diluxone-users' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'If you want a copy of anything, download your data first.', 'diluxone-users' ); ?></p>
		<p class="diluxone-users-note"><?php esc_html_e( 'Asking is not deleting: we send you an email and nothing happens until you click the link in it. That is what stops somebody who borrowed your screen for a minute.', 'diluxone-users' ); ?></p>

		<?php $diluxone_users_table( $erasures ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-diluxone-users-confirm="<?php esc_attr_e( 'Ask to erase your data? You still have to confirm it by email, and after that there is no going back.', 'diluxone-users' ); ?>">
			<input type="hidden" name="action" value="diluxone_users_data_request">
			<input type="hidden" name="diluxone_users_request" value="erase">
			<?php wp_nonce_field( 'diluxone_users_data_request' ); ?>
			<button type="submit" class="diluxone-users-button diluxone-users-button--danger"><?php esc_html_e( 'Ask to erase my data', 'diluxone-users' ); ?></button>
		</form>
	<?php endif; ?>
	<?php diluxone_users_panel_close(); ?>
<?php endif; ?>
