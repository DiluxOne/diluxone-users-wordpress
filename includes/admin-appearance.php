<?php
/**
 * How everything the plugin draws looks.
 *
 * These two tabs live inside the Account area screen. What they change reaches
 * further than that — the sign-in form, the fields, the sessions list — but the
 * account area is where nearly all of it is seen, and it is where somebody
 * changing a colour wants to be looking while they change it. A setting nobody
 * can find is worse than one filed slightly too far down.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;


/**
 * What the account area looks like with the values on this screen.
 *
 * It is not a drawing of the account area: it is the account area. The same
 * shortcode the page runs is run here, so what is on this screen is what the
 * site serves — including a template the theme has replaced, which is the
 * thing a drawing could never show. A site that has put its own account.php
 * in its theme sees its own account.php here, and does not spend an evening
 * wondering why the site and this screen disagree.
 *
 * It renders into the preview's own document, which is where the colour and
 * the corners arrive as custom properties — the same way they arrive on the
 * front end.
 */
function diluxone_users_style_preview(): void {
	// The shortcode, not a copy of it. It renders for whoever is looking —
	// the sections they can see, their own name and picture — because an
	// account area shown with somebody else's data would be a different kind
	// of lie.
	echo do_shortcode( '[diluxone_users_account]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the shortcode escapes its own output.
}

/**
 * The two shapes the account area comes in.
 *
 * A template is a starting point and not a lid: pressing one fills in the
 * pieces underneath, and from there every one of them is yours to change.
 * Which of the two was chosen IS stored, unlike the values it wrote — the
 * shape is not the sum of its pieces, it is the difference between a panel in
 * the page and a band across the window.
 *
 * The shape of a row is documented rather than declared: it goes through a
 * filter, and what comes back from a filter is whatever somebody put there.
 * Every reader treats a missing key as absent.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_account_templates(): array {
	$templates = array(
		'plain' => array(
			'label'     => __( 'Simple', 'diluxone-users' ),
			'help'      => __( 'A panel in the page, the way a settings screen looks. It sits quietly inside whatever the theme already draws.', 'diluxone-users' ),
			'layout'    => 'tabs',
			'nav_style' => 'pills',
			'header'    => 1,
			'avatar'    => 1,
			'since'     => 1,
			'action'    => 0,
			'width'     => 'contained',
		),
		'cover' => array(
			'label'     => __( 'With a cover', 'diluxone-users' ),
			'help'      => __( 'The person on a coloured band the full width of the window, with the menu in a bar of its own underneath. The way a profile looks.', 'diluxone-users' ),
			'layout'    => 'tabs',
			// Underlined and not filled, because a filled block directly under
			// a filled band is two blocks of colour fighting. It is filled in
			// here as a starting point, where it can be seen and changed —
			// not applied behind the back of the control that shows it.
			'nav_style' => 'underline',
			'header'    => 1,
			'avatar'    => 1,
			'since'     => 1,
			'action'    => 1,
			'width'     => 'contained',
		),
	);

	/**
	 * Filters the shapes the account area comes in.
	 *
	 * The id is printed as a class on the area — `diluxone-users-account--<id>`
	 * — and the rest of the row is what pressing it fills in. Anything that
	 * is not one of the plugin's own needs its own CSS.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $templates
	 */
	return (array) apply_filters( 'diluxone_users_account_templates', $templates );
}

/** The shape of the account area, and the pieces that shape is made of. */
function diluxone_users_screen_appearance_template(): void {
	$template = diluxone_users_account_template();

	diluxone_users_intro( __( 'What the account area is shaped like, and what it is made of. The shape is a starting point: press one and the pieces underneath fill in, and from there each of them is yours.', 'diluxone-users' ) );

	diluxone_users_ui_section( __( 'The shape', 'diluxone-users' ) );

	/*
	 * A grid of cards with a drawing in each one is columns of its own, so it
	 * says so rather than being read as a paragraph: held to the reading
	 * measure it dropped to two cards a row with the room beside them empty.
	 * The options further down this tab are read in lines and keep the measure,
	 * which is the difference between the two.
	 */
	diluxone_users_ui_wide_open();
	?>
	<div class="diluxone-users-templates">
		<?php foreach ( diluxone_users_account_templates() as $id => $preset ) : ?>
			<label class="diluxone-users-templates__one <?php echo $id === $template ? 'is-chosen' : ''; ?>">
				<input type="radio" name="diluxone_users_account_template" value="<?php echo esc_attr( $id ); ?>" <?php checked( $id, $template ); ?>
					data-diluxone-users-template
					data-diluxone-users-template-pieces="<?php echo esc_attr( (string) wp_json_encode( $preset ) ); ?>">
				<span class="diluxone-users-templates__art diluxone-users-templates__art--<?php echo esc_attr( $id ); ?>" aria-hidden="true"></span>
				<span class="diluxone-users-templates__name"><?php echo esc_html( $preset['label'] ); ?></span>
				<span class="diluxone-users-templates__help"><?php echo esc_html( $preset['help'] ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>
	<?php
	diluxone_users_ui_wide_close();

	diluxone_users_ui_section( __( 'The header', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'type'     => 'checkbox',
				'name'     => 'diluxone_users_account_header',
				'value'    => '1',
				'piece'    => 'header',
				'checked'  => (bool) diluxone_users_option( 'diluxone_users_account_header' ),
				'title'    => __( 'Show it', 'diluxone-users' ),
				'help'     => __( 'The name is always there: a header without it is a coloured band with a picture on it.', 'diluxone-users' ),

				/*
				 * The three pieces exist because the header does. Hanging off
				 * it they come and go with it, instead of staying on screen
				 * as three questions about something that is not there.
				 */
				'children' => static function (): void {
					diluxone_users_ui_choices(
						array(
							array(
								'type'    => 'checkbox',
								'name'    => 'diluxone_users_account_avatar',
								'value'   => '1',
								'piece'   => 'avatar',
								'checked' => (bool) diluxone_users_option( 'diluxone_users_account_avatar' ),
								'title'   => __( 'Their picture', 'diluxone-users' ),
							),
							array(
								'type'    => 'checkbox',
								'name'    => 'diluxone_users_account_since',
								'value'   => '1',
								'piece'   => 'since',
								'checked' => (bool) diluxone_users_option( 'diluxone_users_account_since' ),
								'title'   => __( 'The month they joined', 'diluxone-users' ),
							),
							array(
								'type'    => 'checkbox',
								'name'    => 'diluxone_users_account_action',
								'value'   => '1',
								'piece'   => 'action',
								'checked' => (bool) diluxone_users_option( 'diluxone_users_account_action' ),
								'title'   => __( 'A button to their details', 'diluxone-users' ),
							),
						)
					);
				},
			),
		)
	);

	diluxone_users_ui_section( __( 'The cover', 'diluxone-users' ) );

	/*
	 * Three answers and not a tick box: "a picture" and "a picture you can
	 * read a name on top of" are different, and a site should not have to
	 * find that out by uploading a bright one.
	 */
	$covers = array(
		'color' => array(
			__( 'A colour', 'diluxone-users' ),
			__( 'A flat band. The steadiest, and the one that never fights the name on top of it.', 'diluxone-users' ),
		),
		'image' => array(
			__( 'A picture', 'diluxone-users' ),
			__( 'The photo across the band. Choose a dark, quiet one: the name goes on top in white.', 'diluxone-users' ),
		),
		'dim'   => array(
			__( 'A picture under the colour', 'diluxone-users' ),
			__( 'The photo with your colour laid over it. The name stays readable whatever was uploaded, which is why it is here.', 'diluxone-users' ),
		),
	);

	$kinds = array();

	foreach ( $covers as $key => $one ) {
		$kinds[] = array(
			'name'    => 'diluxone_users_account_cover_kind',
			'value'   => $key,
			'piece'   => 'cover_kind',
			'checked' => (string) diluxone_users_option( 'diluxone_users_account_cover_kind' ) === $key,
			'title'   => $one[0],
			'help'    => $one[1],
		);
	}

	diluxone_users_ui_field_open( __( 'What the cover is', 'diluxone-users' ) );
	diluxone_users_ui_choices( $kinds );
	diluxone_users_ui_field_close();

	diluxone_users_ui_field_open( __( 'The cover picture', 'diluxone-users' ) );
	diluxone_users_image_field(
		'diluxone_users_account_cover_image',
		__( 'It runs the whole width of the window, so a wide one. With none chosen the band falls back to the colour, rather than coming out empty.', 'diluxone-users' )
	);
	diluxone_users_ui_field_close();

	diluxone_users_ui_color(
		array(
			'label'    => __( 'The cover colour', 'diluxone-users' ),
			'name'     => 'diluxone_users_account_cover',
			'value'    => diluxone_users_account_cover(),
			'fallback' => diluxone_users_style_accent(),
			'help'     => __( 'Unticked it follows the accent colour, which is what it does out of the box: a site that changes its accent gets a cover that follows instead of a second colour, set once, drifting from the first.', 'diluxone-users' )
				. ' '
				. __( 'It is also what goes over the picture in the third answer above, so the two never drift apart.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_section( __( 'The menu', 'diluxone-users' ) );

	diluxone_users_forced_notice( 'diluxone_users_account_layout' );

	$layouts = array(
		'tabs' => __( 'Tabs across the top', 'diluxone-users' ),
		'side' => __( 'A menu down the side', 'diluxone-users' ),
		'none' => __( 'No menu — the site places it with [diluxone_users_account_nav]', 'diluxone-users' ),
	);

	$where = array();

	foreach ( $layouts as $key => $label ) {
		$where[] = array(
			'name'    => 'diluxone_users_account_layout',
			'value'   => $key,
			'piece'   => 'layout',
			'checked' => (string) diluxone_users_option( 'diluxone_users_account_layout' ) === $key,
			'title'   => $label,
		);
	}

	diluxone_users_ui_field_open( __( 'Where it goes', 'diluxone-users' ) );
	diluxone_users_ui_choices( $where );
	diluxone_users_ui_field_close();

	/*
	 * Its own question, and not a consequence of the template. Until now the
	 * cover turned the menu into underlined tabs on its way past, so the same
	 * three sections changed shape because of a decision about the header.
	 */
	$looks = array();

	foreach ( diluxone_users_account_nav_styles() as $key => $style ) {
		$looks[] = array(
			'name'    => 'diluxone_users_account_nav_style',
			'value'   => $key,
			'piece'   => 'nav_style',
			'checked' => diluxone_users_account_nav_style() === $key,
			'title'   => (string) $style['label'],
			'help'    => (string) $style['help'],
		);
	}

	diluxone_users_ui_field_open( __( 'What the menu looks like', 'diluxone-users' ) );
	diluxone_users_ui_choices( $looks );
	diluxone_users_ui_field_close();

	diluxone_users_ui_inline_choices(
		__( 'The menu on a phone', 'diluxone-users' ),
		array(
			array(
				'name'    => 'diluxone_users_account_nav_small',
				'value'   => 'scroll',
				'checked' => 'wrap' !== (string) diluxone_users_option( 'diluxone_users_account_nav_small' ),
				'title'   => __( 'One line that scrolls sideways', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_account_nav_small',
				'value'   => 'wrap',
				'checked' => 'wrap' === (string) diluxone_users_option( 'diluxone_users_account_nav_small' ),
				'title'   => __( 'A grid with every section visible', 'diluxone-users' ),
			),
		),
		__( 'With three sections the scroller is tidier. With eight it hides half of them, and somebody has to drag sideways to find out they exist.', 'diluxone-users' )
	);

	/*
	 * Named after the edges and not after the sides: in a language written
	 * right to left, "the start" is the right-hand one, and the site should
	 * not have to know that to answer.
	 */
	$aligns = array(
		'start'  => __( 'At the start', 'diluxone-users' ),
		'center' => __( 'In the middle', 'diluxone-users' ),
		'end'    => __( 'At the end', 'diluxone-users' ),
	);

	$sits = array();

	foreach ( $aligns as $key => $label ) {
		$sits[] = array(
			'name'    => 'diluxone_users_account_nav_align',
			'value'   => $key,
			'piece'   => 'nav_align',
			'checked' => diluxone_users_account_nav_align() === $key,
			'title'   => $label,
		);
	}

	diluxone_users_ui_field_open( __( 'Where the menu sits', 'diluxone-users' ) );
	diluxone_users_ui_choices( $sits );
	diluxone_users_ui_field_close( __( 'Across the top, where the row begins, sits in the middle or ends. Down the side it is where the words in each line begin, which is the same question asked of a column.', 'diluxone-users' ) );

	$sides = array(
		'nav_top'    => __( 'Top', 'diluxone-users' ),
		'nav_bottom' => __( 'Bottom', 'diluxone-users' ),
		'nav_left'   => __( 'Left', 'diluxone-users' ),
		'nav_right'  => __( 'Right', 'diluxone-users' ),
	);

	// Four numbers that are one answer, so they go in a row: stacked, the
	// second reads as a new subject.
	diluxone_users_ui_field_open( __( 'The menu’s margin', 'diluxone-users' ) );
	diluxone_users_ui_fields_open();

	foreach ( $sides as $side => $label ) {
		diluxone_users_ui_number(
			array(
				'label' => $label,
				'name'  => 'diluxone_users_account_' . $side,
				'value' => (string) diluxone_users_option( 'diluxone_users_account_' . $side ),
				'min'   => 0,
				'max'   => 120,
			)
		);
	}

	diluxone_users_ui_fields_close();
	diluxone_users_ui_field_close(
		__( 'Pixels around the menu, inside its strip. Empty for the plugin’s own — 10, 10, 0 and 0. Top and bottom equal is what keeps the menu in the middle of its strip whichever shape it has.', 'diluxone-users' )
		. ' '
		. __( 'Left and right move the menu without moving the strip, so a background or a rule on it still runs the whole width.', 'diluxone-users' )
	);

	diluxone_users_default_button( '#diluxone_users_account_nav_top,#diluxone_users_account_nav_bottom,#diluxone_users_account_nav_left,#diluxone_users_account_nav_right' );

	diluxone_users_ui_number(
		array(
			'label' => __( 'Under the strip', 'diluxone-users' ),
			'name'  => 'diluxone_users_account_bar_gap',
			'value' => (string) diluxone_users_option( 'diluxone_users_account_bar_gap' ),
			'min'   => 0,
			'max'   => 160,
			'help'  => __( 'The gap between the strip and the content below it. Pixels, empty for 28. Only the menu across the top has a strip: down the side it is a column inside the content.', 'diluxone-users' ),
		)
	);

	diluxone_users_default_button( '#diluxone_users_account_bar_gap' );

	diluxone_users_ui_section( __( 'The page it sits on', 'diluxone-users' ) );

	/*
	 * Empty and not a colour, for the reason the numbers below it are empty:
	 * with no answer the plugin prints no rule, and the page's own ground is
	 * whatever the theme makes it. A "white" default would be an answer, and
	 * an answer that loads after the site's stylesheet wins over it.
	 */
	diluxone_users_ui_color(
		array(
			'label'    => __( 'Behind the whole area', 'diluxone-users' ),
			'name'     => 'diluxone_users_account_ground',
			'value'    => (string) diluxone_users_option( 'diluxone_users_account_ground' ),
			'fallback' => '#f5f6f8',
			'help'     => __( 'The shade the area sits on. Unticked it sits on whatever the page is, which is what it always did — and on a white page white boxes have no edge, so this is how they get one.', 'diluxone-users' ),
		)
	);

	// The header, the menu and the content are three rows lining up to one
	// width, so the three numbers that say how go in one row.
	diluxone_users_ui_field_open( __( 'Lining up with the site', 'diluxone-users' ) );
	diluxone_users_ui_fields_open();

	diluxone_users_ui_number(
		array(
			'label' => __( 'Width', 'diluxone-users' ),
			'name'  => 'diluxone_users_account_row_w',
			'value' => (string) diluxone_users_option( 'diluxone_users_account_row_w' ),
			'min'   => 0,
			'max'   => 2400,
		)
	);

	diluxone_users_ui_number(
		array(
			'label' => __( 'Side gutter', 'diluxone-users' ),
			'name'  => 'diluxone_users_account_row_pad',
			'value' => (string) diluxone_users_option( 'diluxone_users_account_row_pad' ),
			'min'   => 0,
			'max'   => 200,
		)
	);

	diluxone_users_ui_number(
		array(
			'label' => __( 'Above and below', 'diluxone-users' ),
			'name'  => 'diluxone_users_account_body_pad',
			'value' => (string) diluxone_users_option( 'diluxone_users_account_body_pad' ),
			'min'   => 0,
			'max'   => 200,
		)
	);

	diluxone_users_ui_fields_close();
	diluxone_users_ui_field_close(
		__( 'The header, the menu and the content are three rows, and this is the width they line up to and the air inside them. Pixels, and empty means the plugin says nothing about it: whatever your theme already does with the page stands.', 'diluxone-users' )
		. ' '
		. __( 'Empty and not zero, and the difference matters: a zero is an answer, and an answer printed into the stylesheet would overrule a theme that had already lined these rows up itself.', 'diluxone-users' )
	);

	diluxone_users_default_button( '#diluxone_users_account_row_w,#diluxone_users_account_row_pad,#diluxone_users_account_body_pad' );

	$widths = array(
		'contained' => __( 'Held to a reading column', 'diluxone-users' ),
		'full'      => __( 'As wide as the theme allows', 'diluxone-users' ),
	);

	$held = array();

	foreach ( $widths as $key => $label ) {
		$held[] = array(
			'name'    => 'diluxone_users_account_width',
			'value'   => $key,
			'piece'   => 'width',
			'checked' => diluxone_users_account_width() === $key,
			'title'   => $label,
		);
	}

	diluxone_users_ui_field_open( __( 'The content', 'diluxone-users' ) );
	diluxone_users_ui_choices( $held );
	diluxone_users_ui_field_close( __( 'Forms are hard to read at full width; a list of courses is not. This holds the content only — a cover always runs edge to edge.', 'diluxone-users' ) );
}

/**
 * The one answer this screen asks for, read back out of the two it is kept in.
 *
 * Where the look comes from is one question to the person answering it and two
 * settings to the plugin: whether the stylesheet is loaded at all, and whether
 * the colours are the theme’s or this screen’s. Both of those are read
 * elsewhere — by the enqueue, by the token printer — and both have to stay
 * where they are read.
 *
 * So the question is a view of them and not a third setting beside them. A
 * stored “which of the three” would be a second source of truth for something
 * already written down twice, and the day an add-on switches the stylesheet off
 * by itself the stored answer starts lying about a screen it no longer
 * describes.
 */
function diluxone_users_brand_look(): string {
	if ( ! diluxone_users_option( 'diluxone_users_styles' ) ) {
		return 'site';
	}

	return diluxone_users_colors_from_theme() ? 'theme' : 'own';
}

/**
 * Which colour of the theme’s palette plays which part of the plugin.
 *
 * Folded away, and that is the whole of what it needed. It is not a question
 * this screen is asking: a theme that names its colours the way WordPress
 * suggests — Astra does — arrives here already mapped, and seven dropdowns
 * drawn open above the settings read as seven things left undone. The case it
 * is really for is narrow and it is worth saying out loud: a theme that
 * numbers its colours cannot be guessed at, and this is where it gets told.
 *
 * Each row is a square of the colour, the answer, and a sentence naming the
 * part and saying where in the plugin it turns up. The square used to be
 * `transparent` whenever a part was left to the plugin, which on a settings
 * screen is not “nothing chosen” — it is an unticked box, seven of them in a
 * column, and the whole row of them was read as work rather than as a palette.
 *
 * @param array<string, array<string, string>> $palette
 */
function diluxone_users_brand_colour_map( array $palette ): void {
	diluxone_users_ui_fold(
		array(
			'label' => __( 'Fine tuning: which colour does what', 'diluxone-users' ),
			'help'  => __( 'Already filled in from the names your theme gives its colours. Worth opening only if the plugin came out in the wrong ones — a theme that numbers its colours instead of naming them cannot be guessed at.', 'diluxone-users' ),
			'data'  => array( 'brand-map' => 'theme-colours' ),
		),
		static function () use ( $palette ): void {
			$map = diluxone_users_color_map();

			foreach ( diluxone_users_color_roles() as $role => $part ) {
				$now = (string) ( $map[ $role ] ?? '' );
				$id  = 'diluxone_users_color_map_' . $role;
				?>
				<p class="diluxone-users-palette__row">
					<span class="diluxone-users-palette__chip" style="background: <?php echo esc_attr( diluxone_users_color_swatch( $role, $now ) ); ?>" aria-hidden="true"></span>

					<select id="<?php echo esc_attr( $id ); ?>" name="diluxone_users_color_map[<?php echo esc_attr( $role ); ?>]">
						<option value=""><?php esc_html_e( '— the plugin’s own', 'diluxone-users' ); ?></option>
						<?php foreach ( $palette as $slug => $colour ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $now, $slug ); ?>>
								<?php echo esc_html( $colour['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label class="description" for="<?php echo esc_attr( $id ); ?>">
						<strong><?php echo esc_html( $part['name'] ); ?></strong>
						<?php echo esc_html( $part['paints'] ); ?>
					</label>
				</p>
				<?php
			}
		}
	);
}

/**
 * The accent, and the corners, for a site that is choosing them here.
 *
 * The picker is switched off while the colours are the theme’s, because there
 * it is the answer to a question nobody is asking any more — the palette
 * answers it. Switched off it is also not posted, and a save that reads an
 * absence as “no colour” is a save that throws away a colour somebody chose
 * the moment they try the theme’s palette for an afternoon. Hence the keeper
 * in front of it: while the picker is off, what it is holding goes with the
 * form anyway. In front, and not behind, so that the picker wins the moment
 * the browser switches it back on.
 */
function diluxone_users_brand_own_colour(): void {
	$from_theme = diluxone_users_colors_from_theme();

	diluxone_users_ui_field_open( __( 'Accent colour', 'diluxone-users' ), 'diluxone_users_style_accent' );

	if ( $from_theme ) {
		printf(
			'<input type="hidden" name="diluxone_users_style_accent" value="%s">',
			esc_attr( (string) diluxone_users_option( 'diluxone_users_style_accent' ) )
		);
	}

	printf(
		'<input type="color" id="diluxone_users_style_accent" name="diluxone_users_style_accent" value="%1$s"%2$s>',
		esc_attr( diluxone_users_style_accent() ),
		disabled( $from_theme, true, false )
	);

	diluxone_users_ui_field_close( __( 'A filled button, a link, the section of the menu somebody is reading, and the initials drawn for anybody with no photo. Everything else — the grounds, the lines, the quiet text — the plugin builds around it.', 'diluxone-users' ) );

	diluxone_users_ui_number(
		array(
			'label'  => __( 'Corners', 'diluxone-users' ),
			'name'   => 'diluxone_users_style_radius',
			'value'  => (string) diluxone_users_option( 'diluxone_users_style_radius' ),
			'suffix' => __( 'pixels — empty for the default', 'diluxone-users' ),
			'min'    => 0,
			'max'    => 40,
			'help'   => __( 'How round every panel, field and button is. 0 for a design with straight edges, 12 for the plugin’s own, more for a site whose own cards are round.', 'diluxone-users' ),
		)
	);

	diluxone_users_default_button( '#diluxone_users_style_radius' );
}

/**
 * The brand: where everything the plugin draws gets its look from.
 *
 * One question, and everything else hangs off the answer. It used to be six
 * blocks in a column — a row of ready-made looks, a tick box for the
 * stylesheet, the list of properties it reads, where the colours come from,
 * which colour does what, and the accent — and they were not six parts of one
 * question. They were three questions asked of three different people, in a
 * pile: what the site should look like, which is the owner’s; how it is
 * wired, which is the developer’s; and which colour plays which part, which is
 * neither of theirs until something has gone wrong.
 *
 * The ready-made looks were the worst of it, because they hid the shape.
 * “Leave it to the theme”, “Rounded”, “Square” and “As it comes” were not four
 * versions of one answer: the first is a source of colour, the middle two are
 * a number of pixels, and the last is not doing anything. A person pressing
 * them could not tell what had changed, and nothing on the screen said.
 *
 * So: one question with three answers, and each answer carries what it and
 * only it needs. The panel beside this one is the real account area, so the
 * answer can be judged by looking at it rather than by reading about it.
 */
function diluxone_users_screen_design_brand(): void {
	$palette = diluxone_users_theme_palette();
	$look    = diluxone_users_brand_look();

	diluxone_users_intro( __( 'One question, and everything else on this tab hangs off the answer. What it decides reaches past this screen: the sign-in page, the account area, the fields and the buttons all read the same handful of CSS properties.', 'diluxone-users' ) );

	diluxone_users_ui_section( __( 'Where the plugin’s look comes from', 'diluxone-users' ) );

	$theme = array(
		'name'    => 'diluxone_users_look',
		'value'   => 'theme',
		'id'      => 'diluxone_users_look_theme',
		'checked' => 'theme' === $look,

		'title'   => sprintf(
			/* translators: %s: the active theme's name. */
			__( 'From your theme — %s', 'diluxone-users' ),
			(string) wp_get_theme()->get( 'Name' )
		),
		'help'    => array() === $palette
			? __( 'Your theme publishes no palette, so the plugin takes the colours your theme paints: the colour of its links and buttons, of its text, its page ground and the edge of its fields, measured on the site itself. There is nothing else to choose.', 'diluxone-users' )
			: __( 'The plugin paints itself with the palette your theme publishes, and goes on following it — into the theme’s own dark mode as well, where it hands over a variable rather than a colour. Any part the palette does not name is taken from what your theme paints on the site. There is nothing else to choose.', 'diluxone-users' ),
	);

	// Offered only where there is a palette to map. With none, the answer above
	// is switched off, and a fold hanging under a switched-off answer is a
	// setting for something that cannot happen.
	if ( array() !== $palette ) {
		$theme['children'] = static function () use ( $palette ): void {
			diluxone_users_brand_colour_map( $palette );
		};
	}

	diluxone_users_ui_choices(
		array(
			$theme,
			array(
				'name'     => 'diluxone_users_look',
				'value'    => 'own',
				'id'       => 'diluxone_users_look_own',
				'checked'  => 'own' === $look,
				'title'    => __( 'I choose it here', 'diluxone-users' ),
				'help'     => __( 'One colour and one corner, and the plugin builds the rest around them. The panel beside this one is the account area itself, so what is chosen can be looked at rather than imagined.', 'diluxone-users' ),
				// What turns the picker back on the moment this answer is
				// given, rather than at the next save: the server draws it
				// switched off because the saved answer is the theme’s
				// palette, and that stops being true as soon as this is
				// pressed.
				'data'     => array( 'toggle' => '#diluxone_users_style_accent' ),
				'children' => 'diluxone_users_brand_own_colour',
			),
			array(
				'name'     => 'diluxone_users_look',
				'value'    => 'site',
				'id'       => 'diluxone_users_look_site',
				'checked'  => 'site' === $look,
				'title'    => __( 'The site writes it', 'diluxone-users' ),
				'help'     => __( 'The plugin’s stylesheet is not loaded and the plugin paints nothing: its panels, fields and buttons come out bare and the site’s own stylesheet dresses them. The most work of the three, and the only way to match a design exactly.', 'diluxone-users' ),
				'children' => static function (): void {
					diluxone_users_ui_note(
						__( 'The properties it reads', 'diluxone-users' ),
						array(
							'<code>--diluxone-users-accent</code> <code>--diluxone-users-accent-ink</code> <code>--diluxone-users-surface</code> <code>--diluxone-users-surface-alt</code> <code>--diluxone-users-border</code> <code>--diluxone-users-text</code> <code>--diluxone-users-muted</code> <code>--diluxone-users-radius</code> <code>--diluxone-users-control-h</code>',
							__( 'Pointing these at the site’s own tokens, from the site’s own stylesheet, is almost always enough on its own — and unlike a sheet written by hand it survives the plugin growing a component next month. That is worth trying before this answer.', 'diluxone-users' ),
						)
					);
				},
			),
		)
	);

	/*
	 * Said once, under the question, because it is true of all three answers
	 * and belongs to none of them. Inside one it would read as a property of
	 * that answer; at the top it would read as a fourth thing to decide.
	 */
	diluxone_users_ui_note(
		__( 'Dark mode', 'diluxone-users' ),
		__( 'There is none here, on purpose. Dark mode belongs to the site: a light site seen from a dark system used to end up with a light page and black panels. Where the site has one, its own tokens change and these follow.', 'diluxone-users' )
	);

	diluxone_users_ui_section( __( 'Your mark', 'diluxone-users' ) );

	diluxone_users_image_field(
		'diluxone_users_login_logo',
		__( 'Shown above the sign-in form. Somebody who arrived from an e-mail link should be able to tell whose site this is before typing their address into it.', 'diluxone-users' )
	);

	diluxone_users_ui_section(
		__( 'Shapes and edges', 'diluxone-users' ),
		__( 'What the plugin’s own stylesheet draws, whatever the colours in it came from.', 'diluxone-users' )
	);

	/*
	 * The shape of a button is one answer for the whole plugin and not one per
	 * screen: a site whose sign-in button is an outline and whose account
	 * button is filled has not chosen two looks, it has missed one.
	 */
	$buttons = array(
		'solid'   => array(
			__( 'Filled', 'diluxone-users' ),
			__( 'The accent colour, with the text on top of it.', 'diluxone-users' ),
		),
		'outline' => array(
			__( 'Outline', 'diluxone-users' ),
			__( 'A border and the text in the accent, on the page’s own ground. For a design where a block of colour would be too loud.', 'diluxone-users' ),
		),
		'soft'    => array(
			__( 'Soft', 'diluxone-users' ),
			__( 'The accent washed down behind the text. Quieter than filled and steadier than an outline.', 'diluxone-users' ),
		),
	);

	$shapes = array();

	foreach ( $buttons as $key => $one ) {
		$shapes[] = array(
			'name'    => 'diluxone_users_button_style',
			'value'   => $key,
			'checked' => (string) diluxone_users_option( 'diluxone_users_button_style' ) === $key,
			'title'   => $one[0],
			'help'    => $one[1],
		);
	}

	diluxone_users_ui_field_open( __( 'Buttons', 'diluxone-users' ) );
	diluxone_users_ui_choices( $shapes );
	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_button_icons',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_button_icons' ),
				'title'   => __( 'An icon inside the two doors: an envelope on the link, a key on the passkey', 'diluxone-users' ),
			),
		)
	);
	diluxone_users_ui_field_close();

	// Two numbers that are one answer about the same control, so they go in a
	// row rather than one under the other.
	diluxone_users_ui_field_open( __( 'Controls', 'diluxone-users' ) );
	diluxone_users_ui_fields_open();

	diluxone_users_ui_number(
		array(
			'label' => __( 'Height', 'diluxone-users' ),
			'name'  => 'diluxone_users_style_control',
			'value' => (string) diluxone_users_option( 'diluxone_users_style_control' ),
			'min'   => 28,
			'max'   => 80,
		)
	);

	diluxone_users_ui_number(
		array(
			'label' => __( 'Edge', 'diluxone-users' ),
			'name'  => 'diluxone_users_style_border',
			'value' => (string) diluxone_users_option( 'diluxone_users_style_border' ),
			'min'   => 0,
			'max'   => 4,
			'step'  => 0.5,
		)
	);

	diluxone_users_ui_fields_close();
	diluxone_users_ui_field_close( __( 'Pixels, both, and empty for the plugin’s own — 46 and 1. The edge takes halves: 1.5 is what a site with a heavier hand actually uses, and 2 is a different design.', 'diluxone-users' ) );

	diluxone_users_ui_inline_choices(
		__( 'Notices', 'diluxone-users' ),
		array(
			array(
				'name'    => 'diluxone_users_notice_style',
				'value'   => 'bar',
				'checked' => 'soft' !== (string) diluxone_users_option( 'diluxone_users_notice_style' ),
				'title'   => __( 'A bar down the left', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_notice_style',
				'value'   => 'soft',
				'checked' => 'soft' === (string) diluxone_users_option( 'diluxone_users_notice_style' ),
				'title'   => __( 'A soft box in the colour of what it says', 'diluxone-users' ),
			),
		),
		__( 'The four the plugin writes: the link expired, that address is not valid, a network failed, something went wrong. The colour is the notice’s own either way — green when it went well, red when it did not.', 'diluxone-users' )
	);
}

/** The three layers of the profile picture. */
function diluxone_users_screen_appearance_photo(): void {
	$gravatar = (bool) diluxone_users_option( 'diluxone_users_avatar_gravatar' );

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Three layers, in this order: the photo the person uploaded, then Gravatar, then their initials drawn on the accent colour. Turn off the ones you do not want.', 'diluxone-users' ) );

	diluxone_users_ui_field_open( __( 'Where it comes from', 'diluxone-users' ) );
	diluxone_users_ui_choices(
		array(
			array(
				'type'     => 'checkbox',
				'name'     => 'diluxone_users_avatar_upload',
				'value'    => '1',
				'checked'  => (bool) diluxone_users_option( 'diluxone_users_avatar_upload' ),
				'title'    => __( 'Let people upload their own', 'diluxone-users' ),
				// How big a photo may be is a question only while there are
				// photos, so it lives inside the box that allows them.
				'children' => static function (): void {
					diluxone_users_ui_number(
						array(
							'label'  => __( 'Largest photo accepted', 'diluxone-users' ),
							'name'   => 'diluxone_users_avatar_max_kb',
							'value'  => (string) diluxone_users_option( 'diluxone_users_avatar_max_kb' ),
							'suffix' => __( 'KB', 'diluxone-users' ),
							'min'    => 64,
						)
					);
				},
			),
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_avatar_gravatar',
				'value'   => '1',
				'checked' => $gravatar,
				'title'   => __( 'Fall back to Gravatar when there is none', 'diluxone-users' ),
			),
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_avatar_initials',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_avatar_initials' ),
				'title'   => __( 'Otherwise, draw their initials', 'diluxone-users' ),
			),
		)
	);
	diluxone_users_ui_field_close();

	/*
	 * Where a visitor's e-mail address goes is not a note under a tick box. It
	 * is the consequence of the middle layer, it is true whether the box is on
	 * or off, and it is the one thing on this tab somebody may have to answer
	 * for. Beside the settings it stays readable with the state it describes
	 * next to it.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $gravatar ): void {
			diluxone_users_ui_note(
				__( 'Gravatar', 'diluxone-users' ),
				$gravatar
					? esc_html__( 'A hash of every visitor’s e-mail address is sent to a third party to ask whether it has a picture for them.', 'diluxone-users' )
					: esc_html__( 'Nothing leaves the site: no address is sent anywhere to ask whether there is a picture for it.', 'diluxone-users' ),
				$gravatar ? 'active' : 'off'
			);

			diluxone_users_ui_links(
				__( 'Where the picture turns up', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => 'account' ) ),
						'label' => __( 'The account header', 'diluxone-users' ),
						'help'  => __( 'Whether the header shows it at all, and how big the band behind it is.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => 'brand' ) ),
						'label' => __( 'The accent colour', 'diluxone-users' ),
						'help'  => __( 'What the initials are drawn on, for whoever has no photo of their own.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}
