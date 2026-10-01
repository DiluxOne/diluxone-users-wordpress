<?php
/**
 * The account-area navigation.
 *
 * The look and the direction are classes on the menu itself and not on
 * whatever contains it: the menu can be placed anywhere on the site with
 * [diluxone_users_account_nav], and a menu that only looks right inside the
 * account area is not one that can be placed anywhere.
 *
 * What it is given, in `$args`:
 *
 * - `current`
 * - `sections`
 * - `style`    'pills', 'underline' or 'plain'.
 * - `align`    'start', 'center' or 'end'.
 * - `column`   Down the side rather than across.
 *
 * @var array{current: string, sections: array<string, array<string, mixed>>, style: string, align: string, column: bool} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_nav = array(
	'diluxone-users-account__nav',
	'diluxone-users-account__nav--' . $args['style'],
	'diluxone-users-account__nav--' . ( $args['column'] ? 'column' : 'row' ),
	'diluxone-users-account__nav--' . $args['align'],
);
?>
<nav class="<?php echo esc_attr( implode( ' ', $diluxone_users_nav ) ); ?>" aria-label="<?php esc_attr_e( 'Account sections', 'diluxone-users' ); ?>">
	<?php foreach ( $args['sections'] as $diluxone_users_id => $diluxone_users_section ) : ?>
		<a class="diluxone-users-account__tab <?php echo $diluxone_users_id === $args['current'] ? 'is-current' : ''; ?>"
			href="<?php echo esc_url( diluxone_users_account_url( $diluxone_users_id ) ); ?>"
			<?php echo $diluxone_users_id === $args['current'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $diluxone_users_section['label'] ); ?></a>
	<?php endforeach; ?>
</nav>
