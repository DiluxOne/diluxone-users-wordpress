<?php
/**
 * The sign-in page of its own: the whole document.
 *
 * Drawn in place of the theme's template when the shape of the sign-in page
 * is "A page of its own" (see includes/login-door.php). Nothing of the
 * theme's header or footer: the site's mark — or its name — and the form,
 * on the plugin's colours. wp_head() and wp_footer() stay, because the
 * stylesheet, the scripts of the ways in and whatever the site hangs there
 * arrive through them.
 *
 * Overridable from the theme at:
 *   wp-content/themes/<your-theme>/diluxone-users/login-page.php
 *
 * The form is built first, before wp_head(), so the stylesheet it asks for
 * is enqueued in time to be printed in the head.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_form = diluxone_users_door_form();
// The mark the form draws above itself; without it, the site's name stands in.
$diluxone_users_mark = (int) diluxone_users_option( 'diluxone_users_login_logo' ) > 0 && (bool) diluxone_users_option( 'diluxone_users_login_logo_show' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'diluxone-users-door' ); ?>>
<?php wp_body_open(); ?>
<main class="diluxone-users-door__main">
	<?php if ( ! $diluxone_users_mark ) : ?>
		<p class="diluxone-users-door__site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></p>
	<?php endif; ?>
	<?php echo wp_kses( $diluxone_users_form, diluxone_users_allowed_html() ); ?>
	<?php if ( $diluxone_users_mark ) : ?>
		<p class="diluxone-users-door__back"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php
			/* translators: %s: the site's name */
			echo esc_html( sprintf( __( '← Back to %s', 'diluxone-users' ), get_bloginfo( 'name' ) ) );
			?>
		</a></p>
	<?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
