<?php
/**
 * The public name.
 *
 * @var bool   $can
 * @var string $error
 * @var int    $next
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users-panel diluxone-users-handle">
	<h3><?php esc_html_e( 'Your public name', 'diluxone-users' ); ?></h3>
	<p><?php esc_html_e( 'This is how people see you, and what goes in the address of your profile. Your email is how you get in, and nobody sees it.', 'diluxone-users' ); ?></p>

	<?php if ( '' !== $error ) : ?>
		<p class="diluxone-users-notice diluxone-users-notice--error"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="diluxone-users-form">
		<input type="hidden" name="action" value="diluxone_users_handle">
		<?php wp_nonce_field( 'diluxone_users_handle' ); ?>

		<?php echo diluxone_users_handle_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plantilla, ya escapada. ?>

		<?php
		// When the name cannot change yet, the field itself says until when:
		// the panel only offers the button while there is something to save.
		if ( $can ) :
			?>
			<button type="submit" class="diluxone-users-button"><?php esc_html_e( 'Save', 'diluxone-users' ); ?></button>
		<?php endif; ?>
	</form>
</div>
