<?php
/**
 * The account area.
 *
 * The same markup whatever the shape, with the shape as a class on the
 * outside: 'plain' is a panel sitting in the page, the way a settings screen
 * looks, and a shape an add-on brings is styled by its own stylesheet. Which
 * pieces the header is made of — the picture, the date they joined, the
 * button — are asked separately, because a site that wants a header without
 * the join date should not have to copy this file to get it.
 *
 * What it is given, in `$args`:
 *
 * - `current`
 * - `header`
 * - `avatar`
 * - `avatar_size`
 * - `since`
 * - `action`
 * - `layout`
 * - `template`
 * - `width`
 * - `classes`
 * - `style`
 * - `sections`
 * - `user`
 *
 * @var array{current: string, header: bool, avatar: bool, avatar_size: int, since: bool, action: bool, layout: string, template: string, width: string, classes: array<int, string>, style: string, sections: array<string, array<string, mixed>>, user: WP_User} $args
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="<?php echo esc_attr( implode( ' ', $args['classes'] ) ); ?>"
	<?php echo '' === $args['style'] ? '' : 'style="' . esc_attr( $args['style'] ) . '"'; ?>>

	<?php if ( $args['header'] ) : ?>
		<div class="diluxone-users-account__header">
			<div class="diluxone-users-account__header-inner">
				<?php if ( $args['avatar'] ) : ?>
					<span class="diluxone-users-account__avatar"><?php echo wp_kses( (string) get_avatar( $args['user']->ID, $args['avatar_size'] ), diluxone_users_avatar_tags(), diluxone_users_avatar_protocols() ); ?></span>
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
	// With tabs, the bar is its own strip across the area so it can run its
	// full width; down the side it belongs inside the body, next to what it
	// is navigating.
	?>
	<?php if ( 'tabs' === $args['layout'] ) : ?>
		<div class="diluxone-users-account__bar">
			<?php echo wp_kses( diluxone_users_account_nav( $args['sections'], $args['current'] ), diluxone_users_allowed_html() ); ?>
		</div>
	<?php endif; ?>

	<div class="diluxone-users-account__body">
		<?php if ( 'side' === $args['layout'] ) : ?>
			<?php echo wp_kses( diluxone_users_account_nav( $args['sections'], $args['current'] ), diluxone_users_allowed_html() ); ?>
		<?php endif; ?>

		<div class="diluxone-users-account__section">
			<?php
			$diluxone_users_section = $args['sections'][ $args['current'] ];

			echo wp_kses( diluxone_users_account_heading_html( $diluxone_users_section, $args['current'], $args['user'] ), diluxone_users_allowed_html() );
			diluxone_users_account_section( $diluxone_users_section, $args['user'] );
			?>
		</div>
	</div>
</div>
