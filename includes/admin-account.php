<?php
/**
 * The account-area screen: which tabs exist and how they are ordered.
 *
 * Everything seen in "my account" is administered from here: the ones the
 * plugin ships, the ones other plugins add (LifterLMS and company) and the
 * ones the site adds with its own text or another plugin's shortcode. They
 * are turned on, turned off, renamed and reordered without touching a line of
 * code.
 *
 * The ones from code can be turned off but not deleted: the code that draws
 * them is still there, and deleting them from the option would bring them
 * back on the next request.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves one section's configuration, merging it with what was already there.
 *
 * @param array<string, mixed> $config
 */
function diluxone_users_section_config_save( string $id, array $config ): void {
	$all = (array) diluxone_users_option( 'diluxone_users_account_sections' );

	$all[ $id ] = array_merge( (array) ( $all[ $id ] ?? array() ), $config );

	update_option( 'diluxone_users_account_sections', $all );
}

/** Removes one of the site's own sections. The ones from code are left alone. */
function diluxone_users_section_delete( string $id ): void {
	$all = (array) diluxone_users_option( 'diluxone_users_account_sections' );

	if ( empty( $all[ $id ]['custom'] ) ) {
		return;
	}

	unset( $all[ $id ] );

	update_option( 'diluxone_users_account_sections', $all );
}

/**
 * Reorders the sections with the list that arrived from the drag.
 *
 * Every position is rewritten at once, in gaps of 10, instead of touching
 * only the ones that moved: if two registered sections share a position —
 * which happens when a plugin does not declare one — rearranging just a pair
 * would move nothing and the drag would look broken.
 *
 * @param array<int, string> $ids
 */
function diluxone_users_section_reorder( array $ids ): void {
	$known = diluxone_users_sections( true );
	$order = 0;

	foreach ( $ids as $id ) {
		if ( ! isset( $known[ $id ] ) ) {
			continue;
		}

		++$order;
		diluxone_users_section_config_save( $id, array( 'position' => $order * 10 ) );
	}
}

/**
 * Saves a section from the detail form.
 *
 * It serves both purposes — creating and editing — because they are the same
 * one: a section is a name, an address and something to show. It returns the
 * identifier, or '' when it could not be done.
 *
 * @param array<string, mixed> $input
 */
function diluxone_users_section_save( array $input ): string {
	$id     = sanitize_key( (string) ( $input['id'] ?? '' ) );
	$exists = diluxone_users_sections( true );
	$fresh  = '' === $id || ! isset( $exists[ $id ] );
	$label  = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

	if ( '' === $label ) {
		return '';
	}

	$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
	$slug = '' === $slug ? sanitize_title( $label ) : $slug;

	if ( $fresh ) {
		$id = '' === $id ? $slug : $id;

			// One of the site's own sections cannot tread on one from code: there
			// would be two with the same address and either could win.
		if ( '' === $id || isset( $exists[ $id ] ) ) {
			return '';
		}
	}

	$config = array(
		'label'      => $label,
		'slug'       => $slug,
		'intro'      => sanitize_text_field( (string) ( $input['intro'] ?? '' ) ),
		'content'    => wp_kses_post( (string) ( $input['content'] ?? '' ) ),
		'placement'  => in_array( $input['placement'] ?? '', array( 'before', 'after', 'replace' ), true )
			? (string) $input['placement']
			: 'after',
		'roles'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['roles'] ?? array() ) ) ) ),
		// Kept even when it is shown to everybody: switching to "only some"
		// and back should not throw away what was ticked.
		'visibility' => 'some' === ( $input['visibility'] ?? '' ) ? 'some' : 'all',
	);

	if ( $fresh ) {
		$config['custom']   = true;
		$config['enabled']  = 1;
		$config['position'] = 900;
	}

	diluxone_users_section_config_save( $id, $config );

	/*
	 * The cards are not part of the section: they belong to the front page as
	 * a whole, and a card can come from a section that is not this one. So
	 * they are saved as their own option, and only from the form that draws
	 * them — otherwise editing any other section would wipe the list.
	 */
	if ( 'home' === $id && ! empty( $input['cards_shown'] ) ) {
		$shown = array_map( 'sanitize_key', (array) ( $input['cards'] ?? array() ) );
		$off   = array();

		foreach ( diluxone_users_summary_cards() as $card ) {
			if ( ! in_array( (string) $card['id'], $shown, true ) ) {
				$off[] = (string) $card['id'];
			}
		}

		update_option( 'diluxone_users_home_cards_off', $off );
	}

	return $id;
}

/**
 * Turning a section on, off, moving it or deleting it.
 *
 * It goes on admin_init and not inside the screen: by the time WordPress
 * calls the callback of an admin page it has already printed the headers, and
 * there a wp_safe_redirect() can do nothing but a "headers already sent"
 * notice in the log.
 */
function diluxone_users_account_actions(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'diluxone-users-account' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) || ! isset( $_GET['diluxone_users_action'], $_GET['section'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'diluxone_users_section_action' );

	$id     = sanitize_key( wp_unslash( $_GET['section'] ) );
	$action = sanitize_key( wp_unslash( $_GET['diluxone_users_action'] ) );

	// The tab travels with it: without it the redirect lands on the first tab
	// of the screen, which is the summary, and the section just turned on is
	// nowhere to be seen.
	$back = array(
		'tab'     => 'sections',
		'section' => $id,
	);

	if ( 'delete' === $action ) {
		diluxone_users_section_delete( $id );
		$back = array(
			'tab'                => 'sections',
			'diluxone_users_msg' => 'deleted',
		);
	} elseif ( 'on' === $action || 'off' === $action ) {
		diluxone_users_section_config_save( $id, array( 'enabled' => 'on' === $action ? 1 : 0 ) );
	}

	// It comes back to the same section: whoever turns one on and gets sent
	// back to the first of the list has to find it again every time.
	wp_safe_redirect( diluxone_users_admin_url( 'diluxone-users-account', $back ) );
	exit;
}
add_action( 'admin_init', 'diluxone_users_account_actions' );

/** The account-area screen. */
function diluxone_users_screen_account(): void {
	diluxone_users_account_notice();
	diluxone_users_screen_panels( 'diluxone-users-account', diluxone_users_screens()['diluxone-users-account'] );
}

/**
 * Its tabs, by the registry, so an add-on can add one of its own.
 *
 * The sections tab keeps its own forms — a list to reorder, a detail to edit
 * and the two switches that belong to one of the sections are not one
 * settings form — and posts them to admin_init as before; the registry only
 * draws the tab around it.
 *
 * There is no tab for what a person may do with their own data. Exporting and
 * deleting are not a subject of their own: they are one section of the account
 * area, the one called "Your data", and they are asked for beside it.
 */
function diluxone_users_account_panels(): void {
	diluxone_users_register_panel(
		'diluxone-users-account',
		'summary',
		array(
			'label'    => __( 'Summary', 'diluxone-users' ),
			'position' => 0,
			'render'   => 'diluxone_users_screen_account_summary',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-account',
		'page',
		array(
			'label'    => __( 'The page', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_account_page',
			'save'     => 'diluxone_users_account_page_save',
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-account',
		'sections',
		array(
			'label'    => __( 'Sections', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_account_sections',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-account',
		'handle',
		array(
			'label'    => __( 'Public name', 'diluxone-users' ),
			'position' => 30,
			'render'   => 'diluxone_users_screen_account_handle',
			'save'     => 'diluxone_users_account_handle_save',
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-account',
		'menu',
		array(
			'label'    => __( 'In the site menu', 'diluxone-users' ),
			'position' => 35,
			'render'   => 'diluxone_users_screen_account_menu',
			'save'     => 'diluxone_users_account_menu_save',
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-account',
		'dashboard',
		array(
			'label'    => __( 'The WordPress dashboard', 'diluxone-users' ),
			'position' => 40,
			'render'   => 'diluxone_users_screen_account_dashboard',
			'save'     => 'diluxone_users_account_dashboard_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_account_panels' );

/** The page that is "my account". */
function diluxone_users_account_page_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_account_page' => absint( wp_unslash( $_POST['diluxone_users_account_page'] ?? 0 ) ),
		)
	);
	// phpcs:enable

	// The page changed: the /account/<section>/ rules have to be rebuilt.
	delete_option( 'diluxone_users_rewrite_version' );
}

/** Which menu gets the person, and how the person looks in it. */
function diluxone_users_account_menu_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$location = sanitize_key( wp_unslash( $_POST['diluxone_users_menu_location'] ?? '' ) );
	$style    = sanitize_key( wp_unslash( $_POST['diluxone_users_menu_style'] ?? 'avatar-name' ) );
	// phpcs:enable

	diluxone_users_save_options(
		array(
			'diluxone_users_menu_location' => isset( diluxone_users_menu_locations()[ $location ] ) ? $location : '',
			'diluxone_users_menu_style'    => isset( diluxone_users_menu_styles()[ $style ] ) ? $style : 'avatar-name',
		)
	);
}

/** The person in the site's menu: where, and how. */
function diluxone_users_screen_account_menu(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'A door in the site’s own menu: “Sign in” for visitors, and for whoever is signed in their photo or their name, with their account, the dashboard if they can use it, and a way out underneath. The theme draws it like the rest of the menu.', 'diluxone-users' ) );

	diluxone_users_ui_select(
		array(
			'label'   => __( 'Which menu', 'diluxone-users' ),
			'name'    => 'diluxone_users_menu_location',
			'value'   => (string) diluxone_users_option( 'diluxone_users_menu_location' ),
			'options' => diluxone_users_menu_locations(),
			'help'    => __( 'It goes at the end of the menu assigned to that place, wherever the theme shows it — the mobile menu included when it is the same menu.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_select(
		array(
			'label'   => __( 'How the person looks', 'diluxone-users' ),
			'name'    => 'diluxone_users_menu_style',
			'value'   => (string) diluxone_users_option( 'diluxone_users_menu_style' ),
			'options' => diluxone_users_menu_styles(),
		)
	);

	$menu = diluxone_users_menu_id();
	diluxone_users_ui_aside_close(
		static function () use ( $menu ): void {
			$term = $menu > 0 ? get_term( $menu, 'nav_menu' ) : null;
			diluxone_users_ui_note(
				__( 'The person in the menu', 'diluxone-users' ),
				$term instanceof WP_Term
					? esc_html( $term->name )
					: esc_html__( 'Not shown in any menu.', 'diluxone-users' ),
				$term instanceof WP_Term ? 'active' : 'pending',
				$term instanceof WP_Term || '' === (string) diluxone_users_option( 'diluxone_users_menu_location' )
					? ''
					: __( 'That place has no menu assigned in Appearance → Menus.', 'diluxone-users' )
			);
		}
	);
}

/** The two things WordPress shows a signed-in person that the site may not want. */
function diluxone_users_account_dashboard_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$bar = sanitize_key( wp_unslash( $_POST['diluxone_users_admin_bar'] ?? 'wp' ) );

	diluxone_users_save_options(
		array(
			'diluxone_users_wp_profile'            => sanitize_key( wp_unslash( $_POST['diluxone_users_wp_profile'] ?? 'allow' ) ),
			// One radio with three answers on the screen, two options
			// underneath: whether it is hidden, and from whom.
			'diluxone_users_admin_bar'             => 'wp' === $bar ? 'wp' : 'hide',
			'diluxone_users_admin_bar_scope'       => 'hide-some' === $bar ? 'some' : 'all',
			'diluxone_users_admin_bar_roles'       => array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_admin_bar_roles'] ?? array() ) ),
			'diluxone_users_admin_bar_keep_admins' => isset( $_POST['diluxone_users_admin_bar_keep_admins'] ) ? 1 : 0,
			'diluxone_users_bar_account'           => isset( $_POST['diluxone_users_bar_account'] ) ? 1 : 0,
		) + diluxone_users_scope_posted( 'diluxone_users_wp_profile' )
	);
	// phpcs:enable
}

/** What each person can do with their own data. */
function diluxone_users_account_privacy_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- diluxone_users_account_post() verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_privacy_export' => isset( $_POST['diluxone_users_privacy_export'] ) ? 1 : 0,
			'diluxone_users_privacy_delete' => isset( $_POST['diluxone_users_privacy_delete'] ) ? 1 : 0,
		)
	);
	// phpcs:enable
}

/**
 * Saves whatever was submitted from the screen.
 *
 * It goes on admin_init, like the one-click actions, so it can redirect: by
 * the time WordPress calls a page callback it has already printed the
 * headers. And redirecting is needed — it is not only hygiene — because after
 * creating a section it has to be opened, and because reloading must not
 * submit the form again.
 */
function diluxone_users_account_post(): void {
	// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its own.
	if ( 'diluxone-users-account' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$open_box = sanitize_key( wp_unslash( $_GET['section'] ?? '' ) );

	if ( isset( $_POST['diluxone_users_order_form_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_order_form_nonce'] ) ), 'diluxone_users_order_form' ) ) {
		diluxone_users_section_reorder( array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_order_form'] ?? array() ) ) );

		diluxone_users_account_back( 'order', $open_box );
	}

	/*
	 * The two switches of the "Your data" section. They are saved here and not
	 * by the panel registry because the sections tab registers no form of its
	 * own — it has three, and one of them wraps a rich-text editor.
	 */
	if ( isset( $_POST['diluxone_users_privacy_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_privacy_nonce'] ) ), 'diluxone_users_privacy' ) ) {
		diluxone_users_account_privacy_save();

		diluxone_users_account_back( 'privacy', 'privacy' );
	}

	if ( isset( $_POST['diluxone_users_section_form_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_section_form_nonce'] ) ), 'diluxone_users_section_form' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- diluxone_users_section_save() sanitises it field by field.
		$saved = diluxone_users_section_save( (array) wp_unslash( $_POST['diluxone_users_section_form'] ?? array() ) );

		diluxone_users_account_back(
			'' === $saved ? 'error' : 'saved',
			'' === $saved ? $open_box : $saved
		);
	}

	// phpcs:enable
}
add_action( 'admin_init', 'diluxone_users_account_post' );

/** Back to the screen, on the right section, with the notice in place. */
function diluxone_users_account_back( string $msg, string $section = '' ): void {
	// Everything that comes through here was posted from the sections tab, so
	// that is where it goes back to: the tab is not in the POST and without it
	// the screen opens on its first one.
	$args = array(
		'tab'                => 'sections',
		'diluxone_users_msg' => $msg,
	);

	if ( '' !== $section ) {
		$args['section'] = $section;
	}

	wp_safe_redirect( diluxone_users_admin_url( 'diluxone-users-account', $args ) );
	exit;
}

/** The notice about what just happened. */
function diluxone_users_account_notice(): void {
	$notices = array(
		'order'   => array( 'success', __( 'New order saved.', 'diluxone-users' ) ),
		'saved'   => array( 'success', __( 'Section saved.', 'diluxone-users' ) ),
		'deleted' => array( 'success', __( 'Section removed.', 'diluxone-users' ) ),
		'privacy' => array( 'success', __( 'Saved.', 'diluxone-users' ) ),
		'error'   => array( 'error', __( 'That section needs a name, and an address that is not taken.', 'diluxone-users' ) ),
	);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks the message.
	$msg = isset( $_GET['diluxone_users_msg'] ) ? sanitize_key( wp_unslash( $_GET['diluxone_users_msg'] ) ) : '';

	if ( isset( $notices[ $msg ] ) ) {
		diluxone_users_notice( $notices[ $msg ][1], $notices[ $msg ][0] );
	}
}

/**
 * The sections: the list on the left and the detail of one on the right.
 *
 * It is a two-panel screen and not a table because they are two different
 * things: the order, which is taken in at a glance and dragged, and what a
 * section is, which is eight fields and an editor. Putting the eight fields
 * inside a row warped the table and hid the order.
 *
 * Two panels are columns of their own, so the block says so and takes the
 * width the window gives instead of the reading measure: held to the measure,
 * the list of sections and the editor of one shared a thousand pixels with
 * the rest of the screen empty beside them, which is the shape this was
 * drawn to avoid. What is inside the editor keeps the measure — a name is
 * still typed into a box the width of a name.
 *
 * The warning about there being no account page yet goes in the rail, where
 * the same warning goes on every other tab of this screen. Above the panels
 * it was a strip of amber pushing the thing it is about off the first
 * screenful, which is the arrangement that reads as news rather than as
 * context.
 */
function diluxone_users_screen_account_sections(): void {
	$sections = diluxone_users_sections( true );
	$page     = diluxone_users_account_page_id();
	$actual   = diluxone_users_screen_account_current( $sections );

	diluxone_users_ui_aside_open();
	diluxone_users_ui_wide_open();
	?>

	<div class="diluxone-users-endpoints">
		<div class="diluxone-users-endpoints__head">
			<h2><?php esc_html_e( 'The sections of “my account”', 'diluxone-users' ); ?></h2>
			<a class="button button-primary" href="
			<?php
			echo esc_url(
				diluxone_users_admin_url(
					'diluxone-users-account',
					array(
						'tab'     => 'sections',
						'section' => 'diluxone-users-new',
					)
				)
			);
			?>
			"><?php esc_html_e( 'Add section', 'diluxone-users' ); ?></a>
		</div>

		<div class="diluxone-users-endpoints__body">
			<form class="diluxone-users-endpoints__order" method="post">
				<?php wp_nonce_field( 'diluxone_users_order_form', 'diluxone_users_order_form_nonce' ); ?>

				<ul class="diluxone-users-endpoints__list" data-diluxone-users-sortable>
					<?php foreach ( $sections as $id => $section ) : ?>
						<li class="diluxone-users-endpoint <?php echo $id === $actual ? 'is-current' : ''; ?> <?php echo $section['enabled'] && diluxone_users_section_available( $section ) ? '' : 'is-off'; ?>">
							<input type="hidden" name="diluxone_users_order_form[]" value="<?php echo esc_attr( $id ); ?>">
							<a class="diluxone-users-endpoint__name" href="
						<?php
						echo esc_url(
							diluxone_users_admin_url(
								'diluxone-users-account',
								array(
									'tab'     => 'sections',
									'section' => $id,
								)
							)
						);
						?>
						"><?php echo esc_html( $section['label'] ); ?></a>
							<span class="diluxone-users-endpoint__grip" aria-hidden="true"></span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php
				/*
				 * Without JavaScript there is no dragging, so the button stays: a
				 * screen that can only be used by dragging cannot be used with
				 * the keyboard.
				 */
				?>
				<p class="diluxone-users-endpoints__save-order">
					<button type="submit" class="button"><?php esc_html_e( 'Save the order', 'diluxone-users' ); ?></button>
				</p>
			</form>

			<div class="diluxone-users-endpoints__detail">
				<?php
				/*
				 * The two switches of "Your data" are asked for above the
				 * section itself, because they are what is in it and what
				 * decides whether it is shown at all. Above and not below: a
				 * section that says it is on but is not showing says so at the
				 * top of the detail, and the answer has to be within reach of
				 * the question.
				 */
				if ( 'privacy' === $actual ) {
					diluxone_users_screen_account_privacy();
				}

				diluxone_users_screen_account_section( $actual, $sections, $page );
				?>
			</div>
		</div>
	</div>
	<?php
	diluxone_users_ui_wide_close();

	diluxone_users_ui_aside_close(
		static function () use ( $page ): void {
			if ( $page > 0 ) {
				return;
			}

			diluxone_users_ui_notice(
				'<strong>' . esc_html__( 'There is no account page yet', 'diluxone-users' ) . '</strong> '
				. sprintf(
					/* translators: %s: link to the tab where the account page is chosen, labelled with that tab's name. */
					esc_html__( 'Pick the page that has the [diluxone_users_account] shortcode, on %s. Until then the links beside this go nowhere.', 'diluxone-users' ),
					'<a href="' . esc_url( diluxone_users_admin_url( 'diluxone-users-account', array( 'tab' => 'page' ) ) ) . '">'
					. esc_html__( 'The page', 'diluxone-users' ) . '</a>'
				),
				'warning'
			);
		}
	);
}

/**
 * Which section is being looked at.
 *
 * With nothing asked for, the first one: a two-panel screen with a blank
 * right-hand side looks broken.
 *
 * @param array<string, array<string, mixed>> $sections
 */
function diluxone_users_screen_account_current( array $sections ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks what to draw.
	$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

	if ( 'diluxone-users-new' === $requested || isset( $sections[ $requested ] ) ) {
		return $requested;
	}

	return (string) ( array_key_first( $sections ) ?? 'diluxone-users-new' );
}

/**
 * The detail of one section.
 *
 * @param array<string, array<string, mixed>> $sections
 */
function diluxone_users_screen_account_section( string $id, array $sections, int $page ): void {
	$fresh   = ! isset( $sections[ $id ] );
	$section = $fresh
		? array(
			'label'     => '',
			'slug'      => '',
			'content'   => '',
			'placement' => 'after',
			'roles'     => array(),
			'source'    => '',
			'custom'    => true,
			'enabled'   => true,
			'render'    => '',
			'available' => '',
			'why'       => '',
		)
		: $sections[ $id ];

	$with_code = ! $fresh && diluxone_users_section_has_code( $section );
	?>
	<form method="post" class="diluxone-users-endpoint-form">
		<?php wp_nonce_field( 'diluxone_users_section_form', 'diluxone_users_section_form_nonce' ); ?>
		<input type="hidden" name="diluxone_users_section_form[id]" value="<?php echo esc_attr( $fresh ? '' : $id ); ?>">

		<div class="diluxone-users-endpoint-form__head">
			<h3><?php echo esc_html( $fresh ? __( 'New section', 'diluxone-users' ) : $section['label'] ); ?></h3>

			<?php if ( ! $fresh ) : ?>
				<a class="diluxone-users-toggle <?php echo $section['enabled'] ? 'is-on' : ''; ?>"
					href="
					<?php
					echo esc_url(
						wp_nonce_url(
							diluxone_users_admin_url(
								'diluxone-users-account',
								array(
									'section' => $id,
									'diluxone_users_action' => $section['enabled'] ? 'off' : 'on',
								)
							),
							'diluxone_users_section_action'
						)
					);
					?>
							">
					<span class="diluxone-users-toggle__knob" aria-hidden="true"></span>
					<?php echo $section['enabled'] ? esc_html__( 'Showing', 'diluxone-users' ) : esc_html__( 'Hidden', 'diluxone-users' ); ?>
				</a>

				<?php if ( ! empty( $section['custom'] ) ) : ?>
					<a class="button diluxone-users-danger"
						href="
						<?php
						echo esc_url(
							wp_nonce_url(
								diluxone_users_admin_url(
									'diluxone-users-account',
									array(
										'section' => $id,
										'diluxone_users_action' => 'delete',
									)
								),
								'diluxone_users_section_action'
							)
						);
						?>
								"
						data-diluxone-users-confirm="<?php esc_attr_e( 'Delete this section?', 'diluxone-users' ); ?>">
						<?php esc_html_e( 'Remove', 'diluxone-users' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
		if ( ! $fresh && $section['enabled'] && ! diluxone_users_section_available( $section ) ) {
			diluxone_users_ui_notice(
				'<strong>' . esc_html__( 'It is on, but it is not showing', 'diluxone-users' ) . '</strong> '
				. esc_html( (string) $section['why'] ),
				'warning'
			);
		}

		diluxone_users_ui_text(
			array(
				'label'    => __( 'Name', 'diluxone-users' ),
				'id'       => 'diluxone-users-section-label',
				'name'     => 'diluxone_users_section_form[label]',
				'value'    => (string) $section['label'],
				'required' => true,
				'help'     => __( 'What people read in the menu, and the title of the section.', 'diluxone-users' ),
			)
		);

		diluxone_users_ui_text(
			array(
				'label'       => __( 'Address', 'diluxone-users' ),
				'id'          => 'diluxone-users-section-slug',
				'name'        => 'diluxone_users_section_form[slug]',
				'value'       => (string) ( $section['slug'] ?? '' ),
				'placeholder' => __( 'made from the name', 'diluxone-users' ),
				'code'        => true,
				'help'        => ! $fresh && $page > 0
					? sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( diluxone_users_account_url( $id ) ),
						esc_html( str_replace( home_url(), '', diluxone_users_account_url( $id ) ) )
					)
					: '',
			)
		);

		if ( 'home' === $id ) {
			/*
			 * What is stored is the list of cards turned OFF, so a card that
			 * turns up tomorrow because a plugin was installed appears by
			 * itself. The screen asks the question the other way round
			 * because "show this" is what somebody is actually deciding.
			 */
			$off   = diluxone_users_summaries_hidden();
			$all   = diluxone_users_summary_cards();
			$cards = array();

			foreach ( $all as $card ) {
				$cards[] = array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_section_form[cards][]',
					'value'   => (string) $card['id'],
					'checked' => ! in_array( (string) $card['id'], $off, true ),
					'title'   => (string) $card['label'],
					'help'    => '' === (string) $card['value'] ? __( 'Nothing to show right now.', 'diluxone-users' ) : '',
				);
			}

			diluxone_users_ui_field_open( __( 'The cards it shows', 'diluxone-users' ) );

			/*
			 * A marker, because a form with every box unticked sends no
			 * "cards" at all — and without something saying the list was on
			 * screen, "show none" would be indistinguishable from "this form
			 * never had it".
			 */
			echo '<input type="hidden" name="diluxone_users_section_form[cards_shown]" value="1">';

			diluxone_users_ui_choices( $cards );

			diluxone_users_ui_field_close(
				array() === $all
					? __( 'No section offers a card yet, so the front page is empty. Sections bring their own, and so does anything else installed on the site.', 'diluxone-users' )
					: __( 'The front page is a summary: each section offers one card and the site can add its own. Untick one and it stops being shown — the section itself is untouched, and a card that has nothing to say today is left out on its own anyway.', 'diluxone-users' )
			);
		}

		diluxone_users_ui_text(
			array(
				'label' => __( 'The line under the title', 'diluxone-users' ),
				'id'    => 'diluxone-users-section-intro',
				'name'  => 'diluxone_users_section_form[intro]',
				'value' => (string) ( $section['intro'] ?? '' ),
				'help'  => __( 'Optional. One sentence saying what this section is for, under its title.', 'diluxone-users' ),
			)
		);

		diluxone_users_ui_field_open( __( 'Who sees it', 'diluxone-users' ) );
		diluxone_users_roles_picker(
			'diluxone_users_section_form[visibility]',
			'diluxone_users_section_form[roles][]',
			diluxone_users_section_visibility( $section ),
			(array) ( $section['roles'] ?? array() ),
			__( 'Everybody with an account, or only the roles ticked. Nobody who is not signed in reaches the account area at all.', 'diluxone-users' )
		);
		diluxone_users_ui_field_close();

		if ( $with_code ) {
			$where = array(
				'after'   => __( 'After what the plugin shows', 'diluxone-users' ),
				'before'  => __( 'Before what the plugin shows', 'diluxone-users' ),
				'replace' => __( 'Instead of it — your content replaces the section', 'diluxone-users' ),
			);

			diluxone_users_ui_select(
				array(
					'label'   => __( 'Where your content goes', 'diluxone-users' ),
					'id'      => 'diluxone-users-section-placement',
					'name'    => 'diluxone_users_section_form[placement]',
					'value'   => (string) $section['placement'],
					'options' => $where,
					'help'    => __( 'This section is drawn by code. What you write below is added to it — unless you say it replaces it.', 'diluxone-users' ),
				)
			);
		} else {
			echo '<input type="hidden" name="diluxone_users_section_form[placement]" value="replace">';
		}

		diluxone_users_ui_field_open( __( 'Your content', 'diluxone-users' ), 'diluxone-users-section-content' );
		wp_editor(
			(string) $section['content'],
			'diluxone-users-section-content',
			array(
				'textarea_name' => 'diluxone_users_section_form[content]',
				'textarea_rows' => 10,
				'media_buttons' => true,
			)
		);
		diluxone_users_ui_field_close(
			$with_code
				? __( 'Text, HTML, or the shortcode of another plugin. Leave it empty and the section stays as the plugin draws it.', 'diluxone-users' )
				: __( 'Text, HTML, or the shortcode of another plugin — a course plugin, a membership, a support desk. This is the whole section.', 'diluxone-users' )
		);

		if ( ! $fresh && '' !== (string) $section['source'] ) {
			diluxone_users_ui_note(
				__( 'Comes from', 'diluxone-users' ),
				esc_html( (string) $section['source'] ) . ' <code>' . esc_html( $id ) . '</code>'
				. ( empty( $section['custom'] )
					? '<br>' . esc_html__( 'It comes from code, so it cannot be deleted —the code that draws it is still there and it would come back— but it can be hidden.', 'diluxone-users' )
					: '' )
			);
		}

		submit_button( $fresh ? __( 'Add section', 'diluxone-users' ) : __( 'Save section', 'diluxone-users' ) );
		?>
	</form>
	<?php
}

/** The state of the account area. Nothing is edited here. */
function diluxone_users_screen_account_summary(): void {
	$page     = diluxone_users_account_page_id();
	$sections = diluxone_users_sections( true );
	$on       = array_filter( $sections, static fn( array $s ): bool => ! empty( $s['enabled'] ) );
	$profile  = (string) diluxone_users_option( 'diluxone_users_wp_profile' );
	$bar      = (string) diluxone_users_option( 'diluxone_users_admin_bar' );
	$tab      = static fn( string $id ): string => diluxone_users_admin_url( 'diluxone-users-account', array( 'tab' => $id ) );

	$profiles = array(
		'allow'    => __( 'Left as WordPress ships it.', 'diluxone-users' ),
		'redirect' => __( 'Sends people to their account on the site.', 'diluxone-users' ),
		'block'    => __( 'Closed: details are edited on the site only.', 'diluxone-users' ),
	);

	diluxone_users_intro( __( 'What the account area is and where it lives, read from the settings the other tabs write. Nothing is edited here.', 'diluxone-users' ) );

	diluxone_users_summary_table(
		array(
			array(
				'label'  => __( 'The page', 'diluxone-users' ),
				// Pending and not off, which is the word the status screen has
				// always used for a page nobody has chosen yet: off says
				// somebody turned it off, and there is no switch to turn.
				'state'  => $page > 0 ? 'active' : 'pending',
				'why'    => $page > 0 ? '' : __( 'No page chosen yet.', 'diluxone-users' ),
				'detail' => $page > 0
					? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $page ) ), esc_html( (string) get_the_title( $page ) ) )
					: esc_html__( 'This site has no account area.', 'diluxone-users' ),
				'url'    => $tab( 'page' ),
			),
			array(
				'label'  => __( 'Sections', 'diluxone-users' ),
				'state'  => array() !== $on ? 'active' : 'off',
				'detail' => esc_html(
					sprintf(
					/* translators: 1: sections shown, 2: sections there are */
						__( '%1$d of %2$d shown.', 'diluxone-users' ),
						count( $on ),
						count( $sections )
					)
				),
				'url'    => $tab( 'sections' ),
			),
			array(
				'label'  => __( 'Public name', 'diluxone-users' ),
				'state'  => diluxone_users_option( 'diluxone_users_handle_enabled' ) ? 'active' : 'off',
				'detail' => diluxone_users_option( 'diluxone_users_handle_enabled' )
					? esc_html__( 'People choose the short name they appear under.', 'diluxone-users' )
					: esc_html__( 'The name comes from their first and last name.', 'diluxone-users' ),
				'url'    => $tab( 'handle' ),
			),

			/*
			 * These two rows are named after WordPress's own things — the
			 * profile screen and the toolbar — and both used to be measured
			 * the other way round: the pill said Active when the setting was
			 * "keep people out of the profile", so a site with the profile
			 * wide open read "The dashboard profile — Off". Every row in this
			 * table reports on the thing in its first column, so these two do
			 * too, and the word says which way, because neither of them is a
			 * switch of this plugin's.
			 */
			array(
				'label'  => __( 'The dashboard profile', 'diluxone-users' ),
				'state'  => 'allow' === $profile ? 'active' : 'off',
				'word'   => 'allow' === $profile ? __( 'Reachable', 'diluxone-users' ) : __( 'Kept out', 'diluxone-users' ),
				'detail' => esc_html( $profiles[ $profile ] ?? $profiles['allow'] ),
				'url'    => $tab( 'dashboard' ),
			),
			array(
				'label'  => __( 'The WordPress toolbar', 'diluxone-users' ),
				'state'  => 'hide' === $bar ? 'off' : 'active',
				'word'   => 'hide' === $bar
					? _x( 'Hidden', 'the WordPress toolbar, on the summary of the account area', 'diluxone-users' )
					: _x( 'Shown', 'the WordPress toolbar, on the summary of the account area', 'diluxone-users' ),
				'detail' => 'hide' === $bar
					? ( 'some' === (string) diluxone_users_option( 'diluxone_users_admin_bar_scope' ) ? esc_html__( 'Hidden on the site for some roles.', 'diluxone-users' ) : esc_html__( 'Hidden on the site for everybody.', 'diluxone-users' ) )
					: esc_html__( 'Shown, as WordPress does.', 'diluxone-users' ),
				'url'    => $tab( 'dashboard' ),
			),
			array(
				'label'  => __( 'Their data', 'diluxone-users' ),
				'state'  => ( diluxone_users_option( 'diluxone_users_privacy_export' ) || diluxone_users_option( 'diluxone_users_privacy_delete' ) ) ? 'active' : 'off',
				'detail' => esc_html(
					implode(
						' · ',
						array_filter(
							array(
								diluxone_users_option( 'diluxone_users_privacy_export' ) ? __( 'can ask for a copy', 'diluxone-users' ) : '',
								diluxone_users_option( 'diluxone_users_privacy_delete' ) ? __( 'can ask for their data to be erased', 'diluxone-users' ) : '',
							)
						)
					)
				),
				'url'    => diluxone_users_admin_url(
					'diluxone-users-account',
					array(
						'tab'     => 'sections',
						'section' => 'privacy',
					)
				),
			),
		)
	);
}

/** The page that is "my account" — or none, which is a state and says so. */
function diluxone_users_screen_account_page(): void {
	$page = (int) diluxone_users_account_page_id();

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Which page is “my account”. Declaring it here is what lets everything else on the site — a course, a forum, a certificate — send people to the right place.', 'diluxone-users' ) );

	diluxone_users_ui_field_open( __( 'The account page', 'diluxone-users' ), 'diluxone_users_account_page' );

	/** @var array<string, mixed> $dropdown */
	$dropdown = array(
		'name'              => 'diluxone_users_account_page',
		'id'                => 'diluxone_users_account_page',
		'selected'          => $page,
		'show_option_none'  => __( '— None: this site has no account area —', 'diluxone-users' ),
		'option_none_value' => 0,
	);

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own and prints it.
	wp_dropdown_pages( $dropdown );
	diluxone_users_create_page_link( 'diluxone_users_account_page' );

	$help = esc_html__( 'The account area appears on this page, below whatever the page already says.', 'diluxone-users' );

	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		$help .= ' ' . esc_html__( 'With plain permalinks the sections go as ?section=…; turn on pretty permalinks in Settings → Permalinks and they become /page/section/ on their own.', 'diluxone-users' );
	}

	diluxone_users_ui_field_close( $help );

	/*
	 * Beside the question and not on top of it. What this tab is worth knowing
	 * — whether the site has an account area at all, and what behaves
	 * differently while it has not — was printed above the only control on the
	 * screen, where it reads as news to be got past before answering. It is not
	 * news: it is the state of the thing being configured, and beside the
	 * control it is context.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $page ): void {
			diluxone_users_ui_note(
				__( 'The account area', 'diluxone-users' ),
				$page > 0
					? sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( (string) get_permalink( $page ) ),
						esc_html( (string) get_the_title( $page ) )
					)
					: esc_html__( 'This site has not got one.', 'diluxone-users' ),
				// The same fact as the summary row and the status check, so
				// the same answer: a page nobody chose is pending, not off.
				$page > 0 ? 'active' : 'pending',
				$page > 0 ? '' : __( 'No page chosen yet.', 'diluxone-users' )
			);

			if ( $page <= 0 ) {
				diluxone_users_ui_notice(
					array(
						'<strong>' . esc_html__( 'What that means', 'diluxone-users' ) . '</strong>',
						esc_html__( 'Links to “my account” from the rest of the site go to the front page.', 'diluxone-users' ),
						esc_html__( 'The toolbar’s user menu is not redirected, and “send people to their account” on the dashboard tab has nowhere to send them.', 'diluxone-users' ),
						esc_html__( 'The shortcode still works wherever somebody puts it.', 'diluxone-users' ),
					),
					'warning'
				);
			}

			diluxone_users_ui_links(
				__( 'What goes on it', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-account', array( 'tab' => 'sections' ) ),
						'label' => __( 'The sections it is made of', 'diluxone-users' ),
						'help'  => __( 'What people find inside, and the order they find it in.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => 'account' ) ),
						'label' => __( 'What it looks like', 'diluxone-users' ),
						'help'  => __( 'Its shape, its cover and its menu, with the page itself beside the settings.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * The two things WordPress shows a signed-in person that the site may not want.
 *
 * The roles that keep the toolbar hang off the one answer that needs them —
 * "only for some roles" — instead of sitting in a row underneath it. Three
 * rows down, a list of roles is a list of roles; inside the option it belongs
 * to, it is the rest of the sentence.
 */
function diluxone_users_screen_account_dashboard(): void {
	$page    = (int) diluxone_users_account_page_id();
	$profile = (string) diluxone_users_option( 'diluxone_users_wp_profile' );
	$bar     = 'hide' === (string) diluxone_users_option( 'diluxone_users_admin_bar' )
		? ( 'some' === (string) diluxone_users_option( 'diluxone_users_admin_bar_scope' ) ? 'hide-some' : 'hide-all' )
		: 'wp';

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Two things WordPress shows to anybody signed in — its own profile screen and its toolbar — that a site with an account area of its own may not want.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'The dashboard profile (wp-admin/profile.php)', 'diluxone-users' ),
		__( 'It does not know about the required fields or the edit limits set up here. Two screens for the same data is how somebody ends up editing their name in one and their phone in the other, under different rules.', 'diluxone-users' )
	);

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_wp_profile',
				'value'   => 'allow',
				'checked' => 'allow' === $profile,
				'title'   => __( 'Leave it as WordPress ships it', 'diluxone-users' ),
				'help'    => __( 'Anybody who can reach the dashboard edits their details there as well as on the site.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_wp_profile',
				'value'   => 'redirect',
				'checked' => 'redirect' === $profile,
				'title'   => __( 'Send people to their account on the site instead', 'diluxone-users' ),
				'help'    => __( 'Opening it lands them on the account page. One screen for their details, and it is the one this plugin knows the rules of.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_wp_profile',
				'value'   => 'block',
				'checked' => 'block' === $profile,
				'title'   => __( 'Close it — their details are edited on the site only', 'diluxone-users' ),
				'help'    => __( 'The screen answers that it is not available. For a site where the dashboard is not part of what people were given.', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_field_open( __( 'For whom', 'diluxone-users' ) );
	diluxone_users_scope_control(
		'diluxone_users_wp_profile',
		'',
		__( 'Whoever can edit users is never reached: they are the person who has to be able to fix what broke, and the dashboard profile is where it gets fixed.', 'diluxone-users' )
	);
	diluxone_users_ui_field_close();

	diluxone_users_ui_section(
		__( 'The WordPress toolbar', 'diluxone-users' ),
		__( 'The black strip across the top of the site. Hiding it locks nobody out — /wp-admin stays open — which is why this is a choice and not a rule.', 'diluxone-users' )
	);

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_admin_bar',
				'value'   => 'wp',
				'checked' => 'wp' === $bar,
				'title'   => __( 'Show it to everybody, as WordPress does', 'diluxone-users' ),
				'help'    => __( 'Whoever is signed in sees the strip on the site, with the way back to the dashboard in it.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_admin_bar',
				'value'   => 'hide-all',
				'checked' => 'hide-all' === $bar,
				'title'   => __( 'Hide it on the site for everybody', 'diluxone-users' ),
				'help'    => __( 'The site looks the same signed in as signed out. The dashboard keeps its own toolbar.', 'diluxone-users' ),
			),
			array(
				'name'     => 'diluxone_users_admin_bar',
				'value'    => 'hide-some',
				'checked'  => 'hide-some' === $bar,
				'title'    => __( 'Hide it on the site only for some roles', 'diluxone-users' ),
				'help'     => __( 'The usual answer for a site with members: the strip goes for them and stays for whoever runs the place.', 'diluxone-users' ),
				// The roles live inside the answer that needs them, and appear
				// with it.
				'children' => static function (): void {
					$fixed = diluxone_users_option( 'diluxone_users_admin_bar_keep_admins' ) ? diluxone_users_roles_that_edit_users() : array();
					$roles = (array) diluxone_users_option( 'diluxone_users_admin_bar_roles' );

					diluxone_users_ui_field_open( __( 'The roles it is hidden from', 'diluxone-users' ) );

					// The same wrapper the other role pickers use: inside it a
					// role is a line in a list, and not a card of its own. A
					// dozen cards for a dozen roles is a screen that scrolls.
					echo '<div class="diluxone-users-scope__roles">';

					foreach ( wp_roles()->get_names() as $diluxone_users_role => $diluxone_users_label ) :
						if ( in_array( (string) $diluxone_users_role, $fixed, true ) ) :
							?>
							<label class="diluxone-users-roles__item diluxone-users-roles__item--fixed">
								<input type="checkbox" disabled>
								<?php echo esc_html( translate_user_role( $diluxone_users_label ) ); ?>
								<span class="description"><?php esc_html_e( '— always keeps it', 'diluxone-users' ); ?></span>
							</label>
							<?php
						else :
							?>
							<label class="diluxone-users-roles__item">
								<input type="checkbox" name="diluxone_users_admin_bar_roles[]" value="<?php echo esc_attr( (string) $diluxone_users_role ); ?>" <?php checked( in_array( (string) $diluxone_users_role, $roles, true ) ); ?>>
								<?php echo esc_html( translate_user_role( $diluxone_users_label ) ); ?>
							</label>
							<?php
						endif;
					endforeach;

					echo '</div>';

					diluxone_users_ui_field_close( __( 'Nothing ticked and the strip stays for everybody, which is the same as the first answer.', 'diluxone-users' ) );
				},
			),
		)
	);

	/*
	 * The next two are not a fourth and a fifth answer to the question above,
	 * so they are not in that group: one is the exception to whatever was
	 * answered, and the other is about where the toolbar points while it is
	 * there at all.
	 */
	diluxone_users_ui_field_open( __( 'The exception', 'diluxone-users' ) );
	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_admin_bar_keep_admins',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_admin_bar_keep_admins' ),
				'title'   => __( 'Whoever can edit users always keeps it, whatever is chosen above', 'diluxone-users' ),
				'help'    => __( 'It is on because the person who administers the site is the one who most needs the way back.', 'diluxone-users' ),
			),
		)
	);
	diluxone_users_ui_field_close();

	diluxone_users_ui_field_open( __( 'Its user menu', 'diluxone-users' ) );
	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_bar_account',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_bar_account' ),
				'title'   => __( 'While the toolbar is shown, its user menu points at the account area', 'diluxone-users' ),
				'help'    => __( 'Their name, their picture and “Edit profile” lead to the account page instead of the dashboard.', 'diluxone-users' ),
			),
		)
	);

	/*
	 * Where anybody sent away from the dashboard ends up is the one fact both
	 * halves of this tab depend on, and it is not answered here — it is
	 * answered on the tab before. Beside the answers it is the standing state
	 * of the site; in the middle of them it was a paragraph interrupting a
	 * group of options to talk about another screen.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $page ): void {
			diluxone_users_ui_note(
				__( 'Where they are sent', 'diluxone-users' ),
				$page > 0
					? sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( (string) get_permalink( $page ) ),
						esc_html( (string) get_the_title( $page ) )
					)
					: esc_html__( 'Nowhere yet: this site has no account area, so sending people to theirs does nothing until it has one.', 'diluxone-users' ),
				$page > 0 ? 'active' : 'pending'
			);

			diluxone_users_ui_links(
				__( 'The other half of this', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-account', array( 'tab' => 'page' ) ),
						'label' => __( 'The page that is “my account”', 'diluxone-users' ),
						'help'  => __( 'Declared once, and every redirect on this tab reads it.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => 'account' ) ),
						'label' => __( 'What they find when they get there', 'diluxone-users' ),
						'help'  => __( 'The account area is the screen replacing the dashboard profile, so it is worth a look.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/** The public name and its rules. */
function diluxone_users_account_handle_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_handle_enabled'  => isset( $_POST['diluxone_users_handle_enabled'] ) ? 1 : 0,
			'diluxone_users_handle_min'      => absint( wp_unslash( $_POST['diluxone_users_handle_min'] ?? 3 ) ),
			'diluxone_users_handle_max'      => absint( wp_unslash( $_POST['diluxone_users_handle_max'] ?? 30 ) ),
			'diluxone_users_handle_charset'  => sanitize_key( wp_unslash( $_POST['diluxone_users_handle_charset'] ?? 'strict' ) ),
			'diluxone_users_handle_spaces'   => sanitize_key( wp_unslash( $_POST['diluxone_users_handle_spaces'] ?? 'dash' ) ),
			'diluxone_users_handle_cooldown' => absint( wp_unslash( $_POST['diluxone_users_handle_cooldown'] ?? 30 ) ),
			'diluxone_users_handle_reserved' => sanitize_textarea_field( wp_unslash( $_POST['diluxone_users_handle_reserved'] ?? '' ) ),
		)
	);
	// phpcs:enable
}

/**
 * The public name: whether it is offered, under which rules, and whether it
 * also works for requesting the sign-in link.
 */
function diluxone_users_screen_account_handle(): void {
	$offered = (bool) diluxone_users_option( 'diluxone_users_handle_enabled' );

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'The email is the identity and nobody chooses it. This is the short name people see, the one that goes in the address of their profile.', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_handle_enabled',
				'value'   => '1',
				'checked' => $offered,
				'title'   => __( 'Let people choose their public name', 'diluxone-users' ),
				'help'    => __( 'Turned off, the name comes from what they wrote as their first and last name, and the profile address is made from that.', 'diluxone-users' ),
			),
		)
	);

	/*
	 * The rules below are one subject and they were five rows of a table: a
	 * shortest and a longest in one row, two sets of two words in the next
	 * two, a paragraph with nothing to decide in the fourth. Under a heading
	 * of their own they read as what they are — what a name may be — and the
	 * paragraph stops pretending to be a setting.
	 */
	diluxone_users_ui_section( __( 'What a name may be', 'diluxone-users' ) );

	diluxone_users_ui_range(
		array(
			'label'   => __( 'Length', 'diluxone-users' ),
			'from'    => array(
				'name'  => 'diluxone_users_handle_min',
				'value' => (string) diluxone_users_option( 'diluxone_users_handle_min' ),
				'min'   => 1,
			),
			'to'      => array(
				'name'  => 'diluxone_users_handle_max',
				'value' => (string) diluxone_users_option( 'diluxone_users_handle_max' ),
				'min'   => 1,
			),
			'between' => __( 'to', 'diluxone-users' ),
			'suffix'  => __( 'characters', 'diluxone-users' ),
		)
	);

	$charset = (string) diluxone_users_option( 'diluxone_users_handle_charset' );

	diluxone_users_ui_inline_choices(
		__( 'Letters', 'diluxone-users' ),
		array(
			array(
				'name'    => 'diluxone_users_handle_charset',
				'value'   => 'strict',
				'checked' => 'unicode' !== $charset,
				'title'   => __( 'Plain: a–z, digits, dot, dash and underscore', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_handle_charset',
				'value'   => 'unicode',
				'checked' => 'unicode' === $charset,
				'title'   => __( 'Also accents and ñ', 'diluxone-users' ),
			),
		),
		__( 'Whatever is typed is turned into the same thing WordPress would put in a URL, so what passes here is exactly what ends up in the address. Anything that does not fit —punctuation, symbols, emoji— is dropped, and the person sees what it turned into before saving.', 'diluxone-users' )
	);

	$spaces = (string) diluxone_users_option( 'diluxone_users_handle_spaces' );

	diluxone_users_ui_inline_choices(
		__( 'Spaces', 'diluxone-users' ),
		array(
			array(
				'name'    => 'diluxone_users_handle_spaces',
				'value'   => 'dash',
				'checked' => 'reject' !== $spaces,
				'title'   => __( 'Turn them into dashes: “Ana Gómez” becomes ana-gomez', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_handle_spaces',
				'value'   => 'reject',
				'checked' => 'reject' === $spaces,
				'title'   => __( 'Refuse them and say so', 'diluxone-users' ),
			),
		),
		__( 'A web address cannot have spaces, so one of the two has to happen. The first is what almost everybody expects; the second is for a site that would rather nobody ends up with a name they did not type.', 'diluxone-users' )
	);

	diluxone_users_ui_number(
		array(
			'label'  => __( 'How often it can change', 'diluxone-users' ),
			'name'   => 'diluxone_users_handle_cooldown',
			'value'  => (string) diluxone_users_option( 'diluxone_users_handle_cooldown' ),
			'suffix' => __( 'days between one change and the next', 'diluxone-users' ),
			'min'    => 0,
			'help'   => __( '0 means whenever they like. A name that changes every day does not identify anybody, and the old address stops working each time.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_textarea(
		array(
			'label' => __( 'Names nobody can take', 'diluxone-users' ),
			'name'  => 'diluxone_users_handle_reserved',
			'value' => (string) diluxone_users_option( 'diluxone_users_handle_reserved' ),
			'rows'  => 3,
			'code'  => true,
			'help'  => __( 'One per line, or separated by commas. The obvious ones —admin, support, api, login— are already blocked; these are yours to add.', 'diluxone-users' ),
		)
	);

	/*
	 * What is true whatever is answered above goes beside the answers and not
	 * under the last of them. There is nothing to decide about taken names —
	 * the site always checks both lists — and a paragraph with nothing to
	 * decide, printed at the foot of a column of settings, reads like a
	 * setting somebody forgot to draw a control for.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $offered ): void {
			if ( ! $offered ) {
				diluxone_users_ui_notice(
					__( 'Nobody is choosing a name while this is turned off. What is set here is kept, and it applies the day it is turned on.', 'diluxone-users' )
				);
			}

			diluxone_users_ui_note(
				__( 'Taken names', 'diluxone-users' ),
				sprintf(
					/* translators: 1: user_nicename, 2: user_login */
					esc_html__( 'Always checked, and against two things: the public names already in use (%1$s) and the usernames that came with the accounts (%2$s). The second one matters because a site that lets people sign in by public name would otherwise have two people answering to the same text, and the link would go to the wrong account.', 'diluxone-users' ),
					'<code>user_nicename</code>',
					'<code>user_login</code>'
				)
			);

			diluxone_users_ui_links(
				__( 'Where the name is used', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
						'label' => __( 'Signing in with it', 'diluxone-users' ),
						'help'  => __( 'Whether the sign-in box takes a public name as well as an e-mail address.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-fields' ),
						'label' => __( 'Their first and last name', 'diluxone-users' ),
						'help'  => __( 'Where the public name comes from while nobody is choosing one.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * What each person can do with their own data, without asking anyone.
 *
 * Drawn beside the section it governs and not on a tab of its own. Exporting
 * and deleting are not a subject: they are one section of the account area,
 * the one called "Your data", and these two switches are what is inside it.
 * They also decide whether it appears at all — with both off there is nothing
 * in it and it is left out — which is a fact that belongs next to the section
 * rather than three tabs away from it.
 *
 * Both come turned on because that is what is right. Turning them off is not
 * hiding the obligation: it is saying those requests are handled by hand, and
 * in that case the section disappears instead of offering buttons that lead
 * nowhere.
 *
 * Its own form and its own nonce, saved by diluxone_users_account_post(): the
 * sections tab registers no form of its own — it already has one for the order
 * and one for the detail — and a form inside a form is thrown away.
 */
function diluxone_users_screen_account_privacy(): void {
	diluxone_users_ui_section(
		__( 'What people can do with their data', 'diluxone-users' ),
		__( 'This is what the section holds. With neither of them on there is nothing in it, and it is not shown.', 'diluxone-users' )
	);
	?>
	<form method="post" class="diluxone-users-endpoint-form">
		<?php
		wp_nonce_field( 'diluxone_users_privacy', 'diluxone_users_privacy_nonce' );

		diluxone_users_ui_choices(
			array(
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_privacy_export',
					'value'   => '1',
					'checked' => (bool) diluxone_users_option( 'diluxone_users_privacy_export' ),
					'title'   => __( 'They can ask for a copy of everything and download it', 'diluxone-users' ),
					'help'    => __( 'The export WordPress already knows how to make: it asks the person to confirm by email and leaves the file ready.', 'diluxone-users' ),
				),
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_privacy_delete',
					'value'   => '1',
					'checked' => (bool) diluxone_users_option( 'diluxone_users_privacy_delete' ),
					'title'   => __( 'They can ask for their data to be erased', 'diluxone-users' ),
					'help'    => __( 'Confirmed by email too, and never for an account that administers the site: it would leave the site with nobody in charge.', 'diluxone-users' ),
				),
			)
		);

		submit_button( __( 'Save what they can do', 'diluxone-users' ), 'primary', 'diluxone_users_privacy_submit' );
		?>
	</form>
	<?php
}
