<?php
/**
 * Datos personales.
 *
 * Variables: $user.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<?php echo do_shortcode( '[diluxone_users_avatar]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. ?>
<?php echo do_shortcode( '[diluxone_users_handle]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. ?>
<?php
echo do_shortcode( '[diluxone_users_fields]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. 
