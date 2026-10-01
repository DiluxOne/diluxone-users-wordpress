<?php
/**
 * The account area.
 *
 * Two shapes come out of here and they are the same markup with a different
 * class on the outside: 'plain' is a panel sitting in the page, the way a
 * settings screen looks, and 'cover' puts the person on a coloured band the
 * full width of the window with the menu in a bar of its own underneath, the
 * way a profile looks. Which pieces the header is made of — the picture, the
 * date they joined, the button — are asked separately, because a site that
 * wants the big cover without the join date should not have to copy this file
 * to get it.
 *
 * What it is given, in `$args`:
 *
 * - `current`
 * - `header`
 * - `avatar`
 * - `since`
 * - `action`
 * - `cover`
 * - `picture`
 * - `kind`
 * - `layout`
 * - `template`
 * - `width`
 * - `sections`
 * - `user`
 *
 * @var array{current: string, header: bool, avatar: bool, since: bool, action: bool, cover: string, picture: string, kind: string, layout: string, template: string, width: string, sections: array<string, array<string, mixed>>, user: WP_User} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

$diluxone_users_classes = array(
	'diluxone-users-account',
	'diluxone-users-account--' . $args['layout'],
	'diluxone-users-account--' . $args['template'],
	'diluxone-users-account--' . $args['width'],
	// What the menu does on a phone when it does not fit. It rides on the
	// area and not on the menu because the menu is also placed on its own
	// with the shortcode, where the site decides.
	'diluxone-users-account--nav-' . ( 'wrap' === (string) diluxone_users_option( 'diluxone_users_account_nav_small' ) ? 'wrap' : 'scroll' ),
);

// With no header the area opens with the menu, against whatever the site has
// above it. That is a different shape and it needs its own air, so it says so.
if ( ! $args['header'] ) {
	$diluxone_users_classes[] = 'diluxone-users-account--bare';
}

/*
 * What the cover is made of is a class of its own — `--cover-image`,
 * `--cover-dim` — and not something read off the presence of a picture. The
 * stylesheet needs to know which of the two a picture means, and a site
 * styling the header needs a name for it.
 */
if ( 'cover' === $args['template'] && 'color' !== $args['kind'] ) {
	$diluxone_users_classes[] = 'diluxone-users-account--cover-' . ( 'dim' === $args['kind'] ? 'dim' : 'image' );
}

$diluxone_users_style = '';

if ( 'cover' === $args['template'] ) {
	if ( '' !== $args['cover'] ) {
		$diluxone_users_style .= '--diluxone-users-cover:' . $args['cover'] . ';';
	}

	if ( '' !== $args['picture'] && 'color' !== $args['kind'] ) {
		$diluxone_users_style .= '--diluxone-users-cover-image:url(' . esc_url( $args['picture'] ) . ');';
	}
}
?>
<div class="<?php echo esc_attr( implode( ' ', $diluxone_users_classes ) ); ?>"
	<?php echo '' === $diluxone_users_style ? '' : 'style="' . esc_attr( $diluxone_users_style ) . '"'; ?>>

	<?php if ( $args['header'] ) : ?>
		<div class="diluxone-users-account__header">
			<div class="diluxone-users-account__header-inner">
				<?php if ( $args['avatar'] ) : ?>
					<span class="diluxone-users-account__avatar"><?php echo get_avatar( $args['user']->ID, 'cover' === $args['template'] ? 96 : 64 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress markup. ?></span>
				<?php endif; ?>

				<div class="diluxone-users-account__who">
					<h1 class="diluxone-users-account__name"><?php echo esc_html( diluxone_users_display_name( $args['user'] ) ); ?></h1>

					<?php if ( $args['since'] ) : ?>
						<p class="diluxone-users-account__since">
							<?php
							echo esc_html(
								sprintf(
								/* translators: %s: month and year they joined */
									__( 'Member since %s', 'diluxone-users' ),
									wp_date( 'F Y', (int) strtotime( $args['user']->user_registered ) )
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( $args['action'] && isset( $args['sections']['details'] ) ) : ?>
					<a class="diluxone-users-button diluxone-users-button--line diluxone-users-account__action" href="<?php echo esc_url( diluxone_users_account_url( 'details' ) ); ?>">
						<?php esc_html_e( 'Edit profile', 'diluxone-users' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php
	// With tabs, the bar is its own strip across the area so it can run the
	// full width under a cover; down the side it belongs inside the body, next
	// to what it is navigating.
	?>
	<?php if ( 'tabs' === $args['layout'] ) : ?>
		<div class="diluxone-users-account__bar">
			<?php echo diluxone_users_account_nav( $args['sections'], $args['current'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup, already escaped. ?>
		</div>
	<?php endif; ?>

	<div class="diluxone-users-account__body">
		<?php if ( 'side' === $args['layout'] ) : ?>
			<?php echo diluxone_users_account_nav( $args['sections'], $args['current'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup, already escaped. ?>
		<?php endif; ?>

		<div class="diluxone-users-account__section">
			<?php
			$diluxone_users_section = $args['sections'][ $args['current'] ];

			echo diluxone_users_account_heading_html( $diluxone_users_section, $args['current'], $args['user'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo diluxone_users_account_section_html( $diluxone_users_section, $args['user'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised inside.
			?>
		</div>
	</div>
</div>
