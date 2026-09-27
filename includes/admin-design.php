<?php
/**
 * Everything the plugin draws, in one place, with what it draws beside it.
 *
 * The look used to live wherever the setting it belonged to lived: the shape
 * of the sign-in page on the sign-in screen, the account template on the
 * account screen, the buttons on the social screen, the colours on whichever
 * of them you opened first. Seven places for one question, and no way to see
 * the result as a whole — which is how a site ends up with a blue sign-in
 * page and a green account area.
 *
 * Its tabs are registered, not listed, so a tab exists because something
 * registered it. That is what lets a feature live in its own file and take
 * its tab with it when it goes.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_USERS_DESIGN = 'diluxone-users-design';

/** The design screen. */
function diluxone_users_screen_design(): void {
	diluxone_users_screen_panels( DILUXONE_USERS_DESIGN, diluxone_users_screens()[ DILUXONE_USERS_DESIGN ] );
}

/* ── Your brand ────────────────────────────────────────────────────── */


/** Saves it. */
function diluxone_users_design_brand_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.

	/*
	 * One question on the screen, two settings underneath it. Where the look
	 * comes from is asked once because that is how it is understood, and kept
	 * as the two answers that are actually read — whether the stylesheet is
	 * loaded, and whose colours are used. Storing the question as well would
	 * be a third answer able to disagree with the two.
	 */
	$look = sanitize_key( wp_unslash( $_POST['diluxone_users_look'] ?? '' ) );

	diluxone_users_save_options(
		array(
			'diluxone_users_styles'        => 'site' === $look ? 0 : 1,
			'diluxone_users_colors'        => 'theme' === $look ? 'theme' : 'own',
			'diluxone_users_color_map'     => (array) map_deep( wp_unslash( $_POST['diluxone_users_color_map'] ?? array() ), 'sanitize_key' ),
			'diluxone_users_style_accent'  => sanitize_hex_color( wp_unslash( $_POST['diluxone_users_style_accent'] ?? '' ) ) ?? '',
			'diluxone_users_style_radius'  => sanitize_text_field( wp_unslash( $_POST['diluxone_users_style_radius'] ?? '' ) ),
			'diluxone_users_style_control' => sanitize_text_field( wp_unslash( $_POST['diluxone_users_style_control'] ?? '' ) ),
			'diluxone_users_style_border'  => sanitize_text_field( wp_unslash( $_POST['diluxone_users_style_border'] ?? '' ) ),
			'diluxone_users_button_style'  => sanitize_key( wp_unslash( $_POST['diluxone_users_button_style'] ?? 'solid' ) ),
			'diluxone_users_button_icons'  => isset( $_POST['diluxone_users_button_icons'] ) ? 1 : 0,
			'diluxone_users_notice_style'  => 'soft' === sanitize_key( wp_unslash( $_POST['diluxone_users_notice_style'] ?? '' ) ) ? 'soft' : 'bar',
			// The mark shown above the sign-in form, picked on this tab.
			'diluxone_users_login_logo'    => absint( wp_unslash( $_POST['diluxone_users_login_logo'] ?? 0 ) ),
		)
	);
	// phpcs:enable
}

/* ── Sign in ───────────────────────────────────────────────────────── */


/** The shape of the sign-in page and the words on it. */
function diluxone_users_design_login(): void {
	diluxone_users_screen_login_shape();
	diluxone_users_screen_login_words();
}

/** Saves it. */
function diluxone_users_design_login_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_login_template'     => sanitize_key( wp_unslash( $_POST['diluxone_users_login_template'] ?? 'plain' ) ),
			'diluxone_users_login_side'         => 'right' === sanitize_key( wp_unslash( $_POST['diluxone_users_login_side'] ?? 'left' ) ) ? 'right' : 'left',
			'diluxone_users_login_image'        => absint( wp_unslash( $_POST['diluxone_users_login_image'] ?? 0 ) ),
			// The panel's words. The three that come in lines keep their
			// newlines — that is what makes them lines — so they are cleaned
			// as areas and not as fields.
			'diluxone_users_login_panel_logo'   => absint( wp_unslash( $_POST['diluxone_users_login_panel_logo'] ?? 0 ) ),
			'diluxone_users_login_panel_title'  => sanitize_textarea_field( wp_unslash( $_POST['diluxone_users_login_panel_title'] ?? '' ) ),
			'diluxone_users_login_panel_text'   => sanitize_textarea_field( wp_unslash( $_POST['diluxone_users_login_panel_text'] ?? '' ) ),
			'diluxone_users_login_panel_points' => sanitize_textarea_field( wp_unslash( $_POST['diluxone_users_login_panel_points'] ?? '' ) ),
			'diluxone_users_login_panel_foot'   => sanitize_text_field( wp_unslash( $_POST['diluxone_users_login_panel_foot'] ?? '' ) ),
			'diluxone_users_login_title'        => sanitize_text_field( wp_unslash( $_POST['diluxone_users_login_title'] ?? '' ) ),
			'diluxone_users_login_intro'        => sanitize_text_field( wp_unslash( $_POST['diluxone_users_login_intro'] ?? '' ) ),
			// Links allowed, and only links: the terms and the privacy policy
			// are pages, and a legal line that cannot point at them is not one.
			'diluxone_users_login_legal'        => wp_kses_post( wp_unslash( $_POST['diluxone_users_login_legal'] ?? '' ) ),
			'diluxone_users_sent_title'         => sanitize_text_field( wp_unslash( $_POST['diluxone_users_sent_title'] ?? '' ) ),
			'diluxone_users_sent_note'          => sanitize_text_field( wp_unslash( $_POST['diluxone_users_sent_note'] ?? '' ) ),
			// Beside the two lines it belongs with, because the screen that
			// draws it is this one. It was read by the brand tab's save, which
			// is a tab that never draws it — so every save of Your brand posted
			// no answer, the save read the absence as “plain”, and a site that
			// had chosen the circle lost it to a screen about colours.
			'diluxone_users_sent_icon'          => 'circle' === sanitize_key( wp_unslash( $_POST['diluxone_users_sent_icon'] ?? '' ) ) ? 'circle' : 'plain',
		)
	);
	// phpcs:enable
}

/* ── The account area ──────────────────────────────────────────────── */


/** Saves it. */
function diluxone_users_design_account_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_account_template'    => 'cover' === sanitize_key( wp_unslash( $_POST['diluxone_users_account_template'] ?? '' ) ) ? 'cover' : 'plain',
			'diluxone_users_account_layout'      => sanitize_key( wp_unslash( $_POST['diluxone_users_account_layout'] ?? 'tabs' ) ),
			'diluxone_users_account_nav_style'   => sanitize_key( wp_unslash( $_POST['diluxone_users_account_nav_style'] ?? 'pills' ) ),
			'diluxone_users_account_nav_align'   => sanitize_key( wp_unslash( $_POST['diluxone_users_account_nav_align'] ?? 'start' ) ),
			'diluxone_users_account_width'       => 'full' === sanitize_key( wp_unslash( $_POST['diluxone_users_account_width'] ?? '' ) ) ? 'full' : 'contained',
			'diluxone_users_account_header'      => isset( $_POST['diluxone_users_account_header'] ) ? 1 : 0,
			'diluxone_users_account_avatar'      => isset( $_POST['diluxone_users_account_avatar'] ) ? 1 : 0,
			'diluxone_users_account_since'       => isset( $_POST['diluxone_users_account_since'] ) ? 1 : 0,
			'diluxone_users_account_action'      => isset( $_POST['diluxone_users_account_action'] ) ? 1 : 0,
			// The tick box is what decides, not the picker: a disabled picker
			// is not posted at all, and with the script off it is posted with
			// a colour nobody chose.
			'diluxone_users_account_cover'       => isset( $_POST['diluxone_users_account_cover_own'] )
				? ( sanitize_hex_color( wp_unslash( $_POST['diluxone_users_account_cover'] ?? '' ) ) ?? '' )
				: '',
			// Same tick-box-decides shape as the cover colour above, and for
			// the same reason: a colour picker has no way of saying "none".
			'diluxone_users_account_ground'      => isset( $_POST['diluxone_users_account_ground_own'] )
				? ( sanitize_hex_color( wp_unslash( $_POST['diluxone_users_account_ground'] ?? '' ) ) ?? '' )
				: '',
			'diluxone_users_account_cover_kind'  => sanitize_key( wp_unslash( $_POST['diluxone_users_account_cover_kind'] ?? 'color' ) ),
			'diluxone_users_account_cover_image' => absint( wp_unslash( $_POST['diluxone_users_account_cover_image'] ?? 0 ) ),
			'diluxone_users_account_nav_small'   => 'wrap' === sanitize_key( wp_unslash( $_POST['diluxone_users_account_nav_small'] ?? '' ) ) ? 'wrap' : 'scroll',
			'diluxone_users_account_row_w'       => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_row_w'] ?? '' ) ),
			'diluxone_users_account_row_pad'     => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_row_pad'] ?? '' ) ),
			'diluxone_users_account_body_pad'    => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_body_pad'] ?? '' ) ),
			'diluxone_users_account_nav_top'     => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_nav_top'] ?? '' ) ),
			'diluxone_users_account_nav_bottom'  => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_nav_bottom'] ?? '' ) ),
			'diluxone_users_account_nav_left'    => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_nav_left'] ?? '' ) ),
			'diluxone_users_account_nav_right'   => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_nav_right'] ?? '' ) ),
			'diluxone_users_account_bar_gap'     => sanitize_text_field( wp_unslash( $_POST['diluxone_users_account_bar_gap'] ?? '' ) ),
		)
	);
	// phpcs:enable
}

/* ── Social buttons ────────────────────────────────────────────────── */


/** Saves them. */
function diluxone_users_design_social_save(): void {
	diluxone_users_save_options( diluxone_users_sso_buttons_posted() );
}

/* ── Profile photo ─────────────────────────────────────────────────── */


/** Saves it. */
function diluxone_users_design_photo_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_avatar_upload'   => isset( $_POST['diluxone_users_avatar_upload'] ) ? 1 : 0,
			'diluxone_users_avatar_gravatar' => isset( $_POST['diluxone_users_avatar_gravatar'] ) ? 1 : 0,
			'diluxone_users_avatar_initials' => isset( $_POST['diluxone_users_avatar_initials'] ) ? 1 : 0,
			'diluxone_users_avatar_max_kb'   => absint( wp_unslash( $_POST['diluxone_users_avatar_max_kb'] ?? 2048 ) ),
		)
	);
	// phpcs:enable
}

/* ── WordPress's own screen ────────────────────────────────────────── */


/** Saves it. */
function diluxone_users_design_wp_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_wp_login_brand' => isset( $_POST['diluxone_users_wp_login_brand'] ) ? 1 : 0,
			'diluxone_users_wp_login_logo'  => absint( wp_unslash( $_POST['diluxone_users_wp_login_logo'] ?? 0 ) ),
			'diluxone_users_wp_login_bg'    => sanitize_hex_color( wp_unslash( $_POST['diluxone_users_wp_login_bg'] ?? '' ) ) ?? '',
		)
	);
	// phpcs:enable
}

/**
 * Its tabs, registered when the menu is being built.
 *
 * All of them in one place and on the hook, so an add-on adding a seventh is
 * doing exactly what the plugin does for its own six.
 */
function diluxone_users_design_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'brand',
		array(
			'label'    => __( 'Your brand', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_design_brand',
			'preview'  => 'diluxone_users_style_preview',
			'note'     => __( 'Your account area, as the site serves it. It is the real thing and not a drawing, so a template your theme has replaced shows up here as it does on the site.', 'diluxone-users' ),
			'save'     => 'diluxone_users_design_brand_save',
		)
	);
	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'login',
		array(
			// The tabs are named after the screens whose look they hold, so
			// "the sign-in design" and "the sign-in settings" are obviously
			// the same thing seen twice.
			'label'    => __( 'The sign-in page', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_design_login',
			'preview'  => 'diluxone_users_login_preview',
			'note'     => __( 'The sign-in page, at the width shown. The theme’s own fonts are not in here: what this shows is the plugin’s part of the page.', 'diluxone-users' ),
			'save'     => 'diluxone_users_design_login_save',
		)
	);
	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'register',
		array(
			'label'    => __( 'Registration', 'diluxone-users' ),
			'position' => 25,
			'render'   => 'diluxone_users_design_register',
			'preview'  => 'diluxone_users_register_preview',
			'note'     => __( 'The registration form, in the frame the sign-in tab chose for both.', 'diluxone-users' ),
			'save'     => 'diluxone_users_design_register_save',
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'account',
		array(
			'label'    => __( 'Account area', 'diluxone-users' ),
			'position' => 30,
			'render'   => 'diluxone_users_screen_appearance_template',
			'preview'  => 'diluxone_users_style_preview',
			'note'     => __( 'The account area as you would meet it, with your own name and picture and the sections your account can see.', 'diluxone-users' ),
			'save'     => 'diluxone_users_design_account_save',
		)
	);
	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'social',
		array(
			'label'    => __( 'Social login', 'diluxone-users' ),
			'position' => 40,
			'render'   => 'diluxone_users_screen_social_buttons',
			'preview'  => 'diluxone_users_social_buttons_preview',
			'note'     => __( 'The buttons as the sign-in page draws them, on the site’s own surface. Four networks are enough to see a finish; the sign-in page shows the ones that are switched on.', 'diluxone-users' ),
			'save'     => 'diluxone_users_design_social_save',
		)
	);
	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'photo',
		array(
			'label'    => __( 'Profile photo', 'diluxone-users' ),
			'position' => 50,
			'render'   => 'diluxone_users_screen_appearance_photo',
			'save'     => 'diluxone_users_design_photo_save',
		)
	);
	diluxone_users_register_panel(
		DILUXONE_USERS_DESIGN,
		'wp',
		array(
			'label'       => 'wp-login.php',
			'position'    => 60,
			'render'      => 'diluxone_users_screen_login_wp',
			// The page itself and not a rendering of it. It is fetched by the
			// browser, so what is in the frame is what is saved — and the stage
			// puts a button under it that asks for the same page drawn with
			// what is on this screen instead.
			'preview_src' => wp_login_url() . '?diluxone-users-admin=1',
			'note'        => __( 'wp-login.php itself, as it is saved right now — not a drawing of it. Press the button above to see it with the choices on this screen.', 'diluxone-users' ),
			'save'        => 'diluxone_users_design_wp_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_design_panels' );

/**
 * What the registration form says.
 *
 * Its shape is not here: the sign-in form and this one are the same page with
 * different fields, and choosing the frame twice would be choosing it twice
 * — the tab before this one sets it for both. What is its own is what it
 * says.
 */
function diluxone_users_design_register(): void {
	diluxone_users_intro( __( 'The words on the registration form. Its shape — the frame, the picture, your mark — comes from the Sign in tab: the two forms are the same page, so the frame is chosen once.', 'diluxone-users' ) );

	/*
	 * Before the boxes and not after them: what is written here is kept and
	 * waits, and that is worth knowing while writing it rather than once it
	 * has been written.
	 */
	if ( ! diluxone_users_option( 'diluxone_users_register_form' ) ) {
		diluxone_users_ui_notice(
			__( 'This site does not use this form: accounts are made another way. What is written here waits for the day that changes.', 'diluxone-users' ),
			'warning'
		);
	}

	diluxone_users_ui_words(
		'diluxone_users_register_title',
		__( 'The heading', 'diluxone-users' ),
		__( 'Create your account', 'diluxone-users' )
	);

	diluxone_users_ui_words(
		'diluxone_users_register_intro',
		__( 'The line under it', 'diluxone-users' ),
		'',
		__( 'Nothing by default. Somewhere to say what an account is for on this site.', 'diluxone-users' )
	);

	diluxone_users_ui_words(
		'diluxone_users_register_done',
		__( 'Once it is done', 'diluxone-users' ),
		__( 'Your account is ready', 'diluxone-users' )
	);
}

/** Saves them. */
function diluxone_users_design_register_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_register_title' => sanitize_text_field( wp_unslash( $_POST['diluxone_users_register_title'] ?? '' ) ),
			'diluxone_users_register_intro' => sanitize_text_field( wp_unslash( $_POST['diluxone_users_register_intro'] ?? '' ) ),
			'diluxone_users_register_done'  => sanitize_text_field( wp_unslash( $_POST['diluxone_users_register_done'] ?? '' ) ),
		)
	);
	// phpcs:enable
}
