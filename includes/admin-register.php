<?php
/**
 * Who gets an account, and how. A tab of the Access screen.
 *
 * It was a screen of its own for a while, on the argument that opening
 * registration changes nothing about signing in. True and beside the point:
 * they are asked at the same moment and they share half their settings. So
 * it is the third tab of the door, after where people sign in and with what.
 *
 * The question is asked in two steps, because that is how it is thought
 * about. First: does this site let people in at all, or does an administrator
 * make every account? Then, and only then: through which doors — the e-mail
 * link can create the account, a social account can, the site's own form can,
 * WordPress's own form can, and none of them excludes another. Four tick
 * boxes on their own could not say the first thing: a site with all four shut
 * and a site that has decided nobody registers look identical, and the second
 * is a decision while the first is a state somebody arrived at.
 *
 * There is no switch behind that first answer. It is read off the doors —
 * open is "at least one of them is" — because a switch of its own would be a
 * second thing to say the same thing, and the day the two disagree the site
 * behaves like one of them and shows the other.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Its tab, on the Access screen. */
function diluxone_users_register_panels(): void {
	diluxone_users_register_panel(
		'diluxone-users-login',
		'register',
		array(
			'label'    => __( 'Registration', 'diluxone-users' ),
			'position' => 30,
			'render'   => 'diluxone_users_screen_register',
			'save'     => 'diluxone_users_screen_register_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_register_panels' );

/**
 * The doors into an account that are open right now, named.
 *
 * WordPress's own form is read through `get_option()` and not copied into an
 * option of the plugin's: it is WordPress's switch, and while the plugin has
 * it locked shut that read already answers 0. Empty means nobody creates an
 * account by themselves, which is the whole of the first question this tab
 * asks and the one fact the Access summary needs.
 *
 * @return array<int, string>
 */
function diluxone_users_register_doors_open(): array {
	$open = array();

	if ( diluxone_users_option( 'diluxone_users_login_register' ) ) {
		$open[] = __( 'the e-mail link', 'diluxone-users' );
	}

	if ( diluxone_users_option( 'diluxone_users_sso_register' ) ) {
		$open[] = __( 'a social account', 'diluxone-users' );
	}

	if ( diluxone_users_option( 'diluxone_users_register_form' ) ) {
		$open[] = __( 'the site’s own form', 'diluxone-users' );
	}

	if ( get_option( 'users_can_register' ) ) {
		$open[] = __( 'WordPress’s own form', 'diluxone-users' );
	}

	return $open;
}

/**
 * What an open registration with every door shut is told.
 *
 * One sentence in one place, said twice: beside the group while the form is
 * being filled in, and by the save if the form is sent anyway.
 */
function diluxone_users_register_needs_one(): string {
	return __( 'With registration open, at least one of these has to be ticked: a door nobody can walk through is a closed door. Tick one, or say that nobody registers themselves.', 'diluxone-users' );
}

/**
 * The roles a self-made account may be given.
 *
 * WordPress calls a role editable when the person looking at the screen is
 * allowed to hand it out, and `wp_dropdown_roles()` draws all of them — which
 * for an administrator means every role there is, Administrator included. On
 * a screen about people who create their own accounts that is the wrong list:
 * whatever is chosen here is what a stranger becomes by filling in a form, so
 * a role that can edit the site is a role that hands the site away.
 *
 * So the ones that can edit anything are left out. What is already saved stays
 * on the list even if it is one of them — a site that set it before this, or
 * through the filter below, sees its own setting rather than a drop-down that
 * quietly says something else.
 *
 * @return array<string, string> Role slug to its translated name.
 */
function diluxone_users_register_roles(): array {
	$roles = array();
	$now   = (string) diluxone_users_option( 'diluxone_users_login_role' );

	foreach ( get_editable_roles() as $role => $details ) {
		$caps = (array) ( $details['capabilities'] ?? array() );

		/*
		 * `edit_posts` is the line. Everything above it — publishing, pages,
		 * other people's posts, plugins, users — is held by a role that has
		 * it, and the roles that do are exactly the ones a site would regret
		 * giving away: contributor upwards. A role a plugin invented is read
		 * by the same rule rather than by its name.
		 */
		$edits = ! empty( $caps['edit_posts'] ) || ! empty( $caps['manage_options'] ) || ! empty( $caps['edit_users'] );

		if ( $edits && $role !== $now ) {
			continue;
		}

		$roles[ $role ] = translate_user_role( (string) $details['name'] );
	}

	/**
	 * Filters the roles offered for accounts people create themselves.
	 *
	 * A site that means it — an intranet where everybody writes — says so
	 * here. It is deliberately not a setting: the screen is where the safe
	 * answer lives, and the unsafe one is worth a line of code.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $roles Role slug to its translated name.
	 */
	return (array) apply_filters( 'diluxone_users_register_roles', $roles );
}

/**
 * Saves the answer, the doors, the page and the role.
 *
 * @return bool False when registration is open with no door ticked, in which
 *              case nothing was written.
 */
function diluxone_users_screen_register_save(): bool {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$open  = 'open' === sanitize_key( wp_unslash( $_POST['diluxone_users_register_open'] ?? '' ) );
	$doors = array(
		'diluxone_users_login_register' => isset( $_POST['diluxone_users_login_register'] ) ? 1 : 0,
		'diluxone_users_sso_register'   => isset( $_POST['diluxone_users_sso_register'] ) ? 1 : 0,
		'diluxone_users_register_form'  => isset( $_POST['diluxone_users_register_form'] ) ? 1 : 0,
	);
	$wp    = isset( $_POST['diluxone_users_wp_register'] ) ? 1 : 0;

	/*
	 * Open with nothing ticked is refused before anything is written. After
	 * would be worse than useless: the screen would come back showing what
	 * was rejected as though it had been kept.
	 */
	if ( $open && ! diluxone_users_ui_needs_one( array_merge( array_values( $doors ), array( $wp ) ), diluxone_users_register_needs_one() ) ) {
		return false;
	}

	/*
	 * A role the screen never offered is not a role this setting takes. It
	 * falls back to what is saved, and to subscriber if that is gone too,
	 * rather than to whatever arrived: what is posted here decides what a
	 * stranger becomes.
	 */
	$offered = diluxone_users_register_roles();
	$asked   = sanitize_key( wp_unslash( $_POST['diluxone_users_login_role'] ?? '' ) );
	$saved   = (string) diluxone_users_option( 'diluxone_users_login_role' );
	$role    = isset( $offered[ $asked ] ) ? $asked : $saved;
	$role    = isset( $offered[ $role ] ) ? $role : 'subscriber';

	diluxone_users_save_options(
		array_merge(
			// Nobody registering themselves is every door shut, and it is
			// written as such: a door left open under an answer that says it
			// is not is how the site and the screen come to disagree.
			$open ? $doors : array_fill_keys( array_keys( $doors ), 0 ),
			array(
				'diluxone_users_register_page' => absint( wp_unslash( $_POST['diluxone_users_register_page'] ?? 0 ) ),
				// Only a role the screen was willing to offer. A drop-down is
				// four keystrokes away in the inspector, and this is the one
				// setting where that would hand the site to whoever asked.
				'diluxone_users_login_role'    => $role,
			)
		)
	);

	/*
	 * WordPress's own form is WordPress's own switch — the same one as
	 * Settings → General → "Anyone can register" — so it is written there and
	 * not copied. Closing registration closes it too, lock or no lock: an
	 * answer of "nobody registers themselves" that leaves the other screen
	 * offering the form is two screens contradicting each other, and the lock
	 * is a rule that lasts as long as its reason does, not a decision. While
	 * it is locked the box is not posted, so an open site writes nothing
	 * there and the lock keeps saying no on its own.
	 */
	if ( ! $open ) {
		update_option( 'users_can_register', 0 );
	} elseif ( ! diluxone_users_wp_registration_locked() ) {
		update_option( 'users_can_register', $wp );
	}
	// phpcs:enable

	// The page changed, and with it the /register/ rules.
	delete_option( 'diluxone_users_rewrite_version' );

	return true;
}

/**
 * The four doors, as cards.
 *
 * @param array<int, string> $social The social providers working right now.
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_register_doors( array $social ): array {
	$form   = (bool) diluxone_users_option( 'diluxone_users_register_form' );
	$page   = (int) diluxone_users_option( 'diluxone_users_register_page' );
	$locked = diluxone_users_wp_registration_locked();

	return array(
		array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_login_register',
			'value'   => '1',
			'checked' => (bool) diluxone_users_option( 'diluxone_users_login_register' ),
			'title'   => __( 'Signing in with the e-mail link creates the account', 'diluxone-users' ),
			'help'    => __( 'Somebody types an address the site has never seen, gets a link, and the account exists by the time they are in. Nothing to fill in.', 'diluxone-users' ),
		),
		array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_sso_register',
			'value'   => '1',
			'checked' => (bool) diluxone_users_option( 'diluxone_users_sso_register' ),
			'title'   => __( 'Signing in with a social account creates the account', 'diluxone-users' ),
			'help'    => __( 'The same thing through Google or Microsoft. Off, a social account only lets in somebody who already has one here.', 'diluxone-users' ),
			'state'   => array() === $social ? 'pending' : '',
			'note'    => array() === $social ? __( 'no provider is working yet', 'diluxone-users' ) : '',
		),
		array(
			'type'     => 'checkbox',
			'name'     => 'diluxone_users_register_form',
			'value'    => '1',
			'checked'  => $form,
			'title'    => __( 'A registration form of the site’s own', 'diluxone-users' ),
			'help'     => __( 'For a site that asks for more than an address before letting anybody in. It asks for the required fields, then sends the same link.', 'diluxone-users' ),
			'state'    => $form && $page <= 0 ? 'pending' : '',
			'note'     => $form && $page <= 0 ? __( 'it has no page yet', 'diluxone-users' ) : '',
			// The page it needs lives inside the box that turns it on, and
			// appears with it.
			'children' => static function () use ( $page ): void {
				diluxone_users_ui_field_open( __( 'The page holding it', 'diluxone-users' ), 'diluxone_users_register_page' );

				/** @var array<string, mixed> $diluxone_users_dropdown */
				$diluxone_users_dropdown = array(
					'name'              => 'diluxone_users_register_page',
					'id'                => 'diluxone_users_register_page',
					'selected'          => $page,
					'show_option_none'  => __( '— Choose one —', 'diluxone-users' ),
					'option_none_value' => 0,
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own and prints it.
				wp_dropdown_pages( $diluxone_users_dropdown );
				diluxone_users_create_page_link( 'diluxone_users_register_page' );

				if ( $page <= 0 ) {
					diluxone_users_not_now( __( 'Without a page nobody can reach the form, so this door is not open yet.', 'diluxone-users' ) );
				}

				diluxone_users_ui_field_close( __( 'The form appears on this page, below whatever the page already says. What the form says is on Design → Registration.', 'diluxone-users' ) );
			},
		),
		array(
			'type'     => 'checkbox',
			'name'     => 'diluxone_users_wp_register',
			'value'    => '1',
			'checked'  => (bool) get_option( 'users_can_register' ),
			'disabled' => $locked,
			'title'    => __( 'WordPress’s own form (wp-login.php?action=register)', 'diluxone-users' ),
			'help'     => __( 'The same switch as Settings → General → “Anyone can register”: changing it here or there changes both.', 'diluxone-users' ),
			'state'    => $locked ? 'off' : '',
			'note'     => $locked ? __( 'locked: the e-mail link is the only way in, and that form hands out passwords', 'diluxone-users' ) : '',
		),
	);
}

/** Who gets an account, and what that account is. */
function diluxone_users_screen_register(): void {
	diluxone_users_ui_aside_open();

	$social = diluxone_users_sso_working_names();
	$open   = array() !== diluxone_users_register_doors_open();

	diluxone_users_intro( __( 'Who can create an account on this site, and what that account is once it exists. First whether anybody can at all; then, if they can, through which doors — they are independent, and a site can open as many as it likes.', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_register_open',
				'value'   => 'closed',
				'checked' => ! $open,
				'title'   => __( 'Nobody can register on their own', 'diluxone-users' ),
				'help'    => __( 'Accounts are made by whoever administers the site, under Users. Every door below is shut, WordPress’s own form on Settings → General included.', 'diluxone-users' ),
			),
			array(
				'name'     => 'diluxone_users_register_open',
				'value'    => 'open',
				'checked'  => $open,
				'title'    => __( 'Registration is open', 'diluxone-users' ),
				'help'     => __( 'Through the doors ticked below and no others, and what they get is the role underneath them.', 'diluxone-users' ),
				'children' => static function () use ( $social ): void {
					/*
					 * Armed only while open is the answer on screen, which is
					 * said to the browser rather than decided here: on a site
					 * where nobody registers every door is unticked because
					 * that is what that means, and a rule read from the saved
					 * answer would refuse a form in which nothing was wrong.
					 * The save says the same sentence on the other side.
					 */
					diluxone_users_ui_choices(
						diluxone_users_register_doors( $social ),
						diluxone_users_register_needs_one(),
						array(
							'name' => 'diluxone_users_register_open',
							'is'   => array( 'open' ),
						)
					);

					$diluxone_users_roles = diluxone_users_register_roles();

					diluxone_users_ui_select(
						array(
							'label'   => __( 'Role of new accounts', 'diluxone-users' ),
							'name'    => 'diluxone_users_login_role',
							'value'   => (string) diluxone_users_option( 'diluxone_users_login_role' ),
							'options' => $diluxone_users_roles,
							'help'    => __( 'The same one whichever door they came through: a role per door is a quiet way of handing out privileges. Roles that can edit the site are not offered: this is what anybody who registers themselves becomes, and that is not a decision to make from a drop-down.', 'diluxone-users' ),
						)
					);
				},
			),
		)
	);

	$diluxone_users_asked = diluxone_users_register_fields();

	/*
	 * What the form ends up asking for is a consequence of this tab and not a
	 * question it puts: the fields are decided two screens away. Under the
	 * cards it was a box somebody had to read past to reach the save button;
	 * beside them it is the answer to “and what do they have to fill in,
	 * then”, which is the next thing anybody wonders here.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $diluxone_users_asked ): void {
			// Said from the registration side: there the doors are the subject
			// and the fields are what follows from them. The sentence itself
			// lives with the fields screen, so the two cannot drift apart.
			diluxone_users_nobody_registers_notice( 'register' );

			diluxone_users_ui_note(
				__( 'What the form asks for', 'diluxone-users' ),
				array(
					array() === $diluxone_users_asked
						? esc_html__( 'Only the e-mail address: no field is marked as required.', 'diluxone-users' )
						: esc_html(
							sprintf(
								/* translators: %s: the required fields, separated by dots */
								__( 'The address, plus: %s', 'diluxone-users' ),
								implode( ' · ', wp_list_pluck( $diluxone_users_asked, 'label' ) )
							)
						),
					esc_html__( 'Only the required ones, on purpose: a form that asks for everything is a form nobody finishes. The rest waits in their account. The e-mail is the username and never changes; there is no password unless the person sets one.', 'diluxone-users' ),
				)
			);

			diluxone_users_ui_links(
				__( 'The rest of the form', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-fields' ),
						'label' => __( 'User fields', 'diluxone-users' ),
						'help'  => __( 'Which ones exist, and which of them a form will not go through without.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-design', array( 'tab' => 'register' ) ),
						'label' => __( 'What the form says', 'diluxone-users' ),
						'help'  => __( 'Its heading, its sentences and the terms line, with a preview beside them.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/** The form as the site serves it. */
function diluxone_users_register_preview(): void {
	// The same frame the sign-in page wears: it is the same page with other
	// fields in it, and previewing it bare would preview something else.
	diluxone_users_login_frame_open();

	echo diluxone_users_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes its own output.
		'register.php',
		array(
			'state'     => '',
			'email'     => '',
			'fields'    => diluxone_users_register_fields(),
			'open'      => 'closed' !== diluxone_users_register_mode(),
			'providers' => diluxone_users_sso_for_login(),
		)
	);

	diluxone_users_login_frame_close();
}
