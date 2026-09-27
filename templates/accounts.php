<?php
/**
 * The social networks linked to the account.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/accounts.php
 *
 * @var array<string, array<string, mixed>> $providers Available networks.
 * @var array<int, string>                  $linked    IDs already linked.
 * @var string                              $state
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users diluxone-users-accounts">

	<?php if ( 'linked' === $state ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--ok"><?php esc_html_e( 'Account linked.', 'diluxone-users' ); ?></p>
	<?php elseif ( 'taken' === $state ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php esc_html_e( 'That social account already opens another account on this site, so it was not linked to this one.', 'diluxone-users' ); ?></p>
	<?php endif; ?>

	<?php if ( array() === $providers ) : ?>
		<p class="diluxone-users-note">
			<?php
			if ( 'linked' === ( $only ?? '' ) ) {
				esc_html_e( 'None yet. Link one below and it opens this same account.', 'diluxone-users' );
			} elseif ( 'available' === ( $only ?? '' ) ) {
				esc_html_e( 'You already have them all linked.', 'diluxone-users' );
			} else {
				esc_html_e( 'No provider has been set up yet.', 'diluxone-users' );
			}
			?>
		</p>
	<?php else : ?>

	<ul class="diluxone-users-linked">
		<?php
		foreach ( $providers as $diluxone_users_id => $diluxone_users_provider ) :
			$diluxone_users_is_linked = in_array( $diluxone_users_id, $linked, true );
			?>
			<?php
			/*
			 * A logo with a colour of its own — Google's, Microsoft's — is not
			 * painted over: it is left on a light background, which is what
			 * their guidelines ask for and the only place it reads.
			 */
			?>
			<li class="diluxone-users-linked__item <?php echo $diluxone_users_is_linked ? 'is-linked' : ''; ?> <?php echo diluxone_users_sso_icon_is_colored( $diluxone_users_id ) ? 'has-color' : ''; ?>" style="--diluxone-users-brand: <?php echo esc_attr( (string) sanitize_hex_color( (string) $diluxone_users_provider['color'] ) ); ?>">
				<span class="diluxone-users-linked__logo"><?php echo diluxone_users_sso_icon( $diluxone_users_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG, built from its own paths. ?></span>

				<span class="diluxone-users-linked__who">
					<strong><?php echo esc_html( $diluxone_users_provider['name'] ); ?></strong>
					<span>
						<?php
						echo $diluxone_users_is_linked
							? esc_html__( 'Linked to your account', 'diluxone-users' )
							: esc_html__( 'Not linked', 'diluxone-users' );
						?>
					</span>
				</span>

				<?php if ( $diluxone_users_is_linked ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="diluxone_users_sso_unlink">
						<input type="hidden" name="diluxone_users_provider" value="<?php echo esc_attr( $diluxone_users_id ); ?>">
						<?php wp_nonce_field( 'diluxone_users_sso_unlink' ); ?>
						<button type="submit" class="diluxone-users-button diluxone-users-button--soft"><?php esc_html_e( 'Unlink', 'diluxone-users' ); ?></button>
					</form>
				<?php else : ?>
					<a class="diluxone-users-button diluxone-users-button--soft" href="<?php echo esc_url( diluxone_users_sso_link_url( $diluxone_users_id ) ); ?>">
						<?php esc_html_e( 'Link', 'diluxone-users' ); ?>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
</div>
