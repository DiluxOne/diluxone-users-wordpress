<?php
/**
 * What somebody with no session sees on the account page.
 *
 * What it is given, in `$args`:
 *
 * - `url`
 *
 * @var array{url: string} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="diluxone-users-account diluxone-users-account--guest">
	<h2><?php esc_html_e( 'This is your account', 'diluxone-users' ); ?></h2>
	<p><?php esc_html_e( 'Sign in to see it.', 'diluxone-users' ); ?></p>
	<p><a class="diluxone-users-button" href="<?php echo esc_url( $args['url'] ); ?>"><?php esc_html_e( 'Sign in', 'diluxone-users' ); ?></a></p>
</div>
