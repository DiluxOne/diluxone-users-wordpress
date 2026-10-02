<?php
/**
 * A door to the hub, drawn by the plugin's shortcodes on any other site of a network.
 *
 * What it is given, in `$args`:
 *
 * - `door` 'login', 'register', 'account', or 'here': signing in on this
 *          site itself, on a domain of its own.
 * - `url`  Where the button goes: the hub's page, with the way back.
 * - `hub`  The name of the site the network's accounts live on.
 *
 * @var array{door: string, url: string, hub: string} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_words = array(
	'login'    => array(
		'title'  => __( 'Sign in', 'diluxone-users' ),
		/* translators: %s: the name of the site where the network's accounts live */
		'text'   => __( 'You sign in on %s, where your account is. You come back here afterwards.', 'diluxone-users' ),
		'button' => __( 'Sign in', 'diluxone-users' ),
	),
	'register' => array(
		'title'  => __( 'Create your account', 'diluxone-users' ),
		/* translators: %s: the name of the site where the network's accounts live */
		'text'   => __( 'Accounts are created on %s, for every site of the network. You come back here afterwards.', 'diluxone-users' ),
		'button' => __( 'Create your account', 'diluxone-users' ),
	),
	'here'     => array(
		'title'  => __( 'Sign in', 'diluxone-users' ),
		/* translators: %s: the name of the site where the network's accounts live */
		'text'   => __( 'This site is on a domain of its own, so you sign in here: signing in on %s does not carry over to it.', 'diluxone-users' ),
		'button' => __( 'Sign in', 'diluxone-users' ),
	),
	'account'  => array(
		'title'  => __( 'This is your account', 'diluxone-users' ),
		/* translators: %s: the name of the site where the network's accounts live */
		'text'   => __( 'Your account is kept on %s, for every site of the network.', 'diluxone-users' ),
		'button' => __( 'Open your account', 'diluxone-users' ),
	),
);
$diluxone_users_words = $diluxone_users_words[ $args['door'] ] ?? $diluxone_users_words['login'];
?>
<div class="diluxone-users-account diluxone-users-account--guest diluxone-users-hub-door" data-diluxone-users-door="<?php echo esc_attr( $args['door'] ); ?>">
	<h2><?php echo esc_html( $diluxone_users_words['title'] ); ?></h2>
	<p><?php echo esc_html( sprintf( $diluxone_users_words['text'], $args['hub'] ) ); ?></p>
	<p><a class="diluxone-users-button" href="<?php echo esc_url( $args['url'] ); ?>"><?php echo esc_html( $diluxone_users_words['button'] ); ?></a></p>
</div>
