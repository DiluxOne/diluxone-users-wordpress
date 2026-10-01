<?php
/**
 * Datos personales.
 *
 * What it is given, in `$args`: `user`, the account (unused here: each piece draws itself).
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<?php echo do_shortcode( '[diluxone_users_avatar]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. ?>
<?php echo do_shortcode( '[diluxone_users_handle]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. ?>
<?php
echo do_shortcode( '[diluxone_users_fields]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own shortcode, which escapes its output. 
