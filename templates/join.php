<?php
/**
 * A site of the network somebody is not a member of: the invitation, or the welcome.
 *
 * Drawn by the `[diluxone_users_join]` shortcode and at the top of the page the
 * hub sends somebody back to, for a signed-in person who is not a member of
 * this site. A way to join on one's own is an add-on's, which draws its own
 * box (`diluxone_users_join_box`).
 *
 * What it is given, in `$args`:
 *
 * - `state`  'invite' (only administrators add people) or 'joined'.
 * - `site`   The name of this site.
 * - `hub`    The name of the site the network's accounts live on.
 * - `notice` Drawn at the top of the page rather than where a shortcode is.
 *
 * @var array{state: string, site: string, hub: string, notice: bool} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_words = array(
	'invite' => array(
		'title' => __( 'This site is by invitation', 'diluxone-users' ),
		/* translators: 1: the name of this site, 2: the name of the site where the network's accounts live */
		'text'  => __( 'You are signed in with your account from %2$s, and you are not a member of %1$s. Only its administrators add people to it.', 'diluxone-users' ),
	),
	'joined' => array(
		'title' => __( 'Welcome', 'diluxone-users' ),
		/* translators: 1: the name of this site, 2: the name of the site where the network's accounts live */
		'text'  => __( 'You are a member of %1$s now, with your account from %2$s.', 'diluxone-users' ),
	),
);
$diluxone_users_words = $diluxone_users_words[ $args['state'] ] ?? $diluxone_users_words['invite'];
?>
<div class="diluxone-users-account diluxone-users-account--guest diluxone-users-join<?php echo $args['notice'] ? ' diluxone-users-join--notice' : ''; ?>" data-diluxone-users-join="<?php echo esc_attr( $args['state'] ); ?>"<?php echo $args['notice'] ? ' role="status"' : ''; ?>>
	<h2><?php echo esc_html( $diluxone_users_words['title'] ); ?></h2>
	<p><?php echo esc_html( sprintf( $diluxone_users_words['text'], $args['site'], $args['hub'] ) ); ?></p>
</div>
