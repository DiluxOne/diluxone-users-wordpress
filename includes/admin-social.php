<?php
/**
 * The social-login screen: the networks, the rules, and each one's detail.
 *
 * Twelve networks are a set to be looked over, not a list to be read down.
 * They were a grid of cards and became a strip of twelve rows, and that put
 * the whole set into one narrow column with half a window empty beside it:
 * finding one network meant reading the eleven above it. A card in a grid is
 * found by where it is; a row is found by reading.
 *
 * So the grid is back, and it is the grid this admin already has rather than
 * the one that used to be here: the same card the home screen is built out
 * of, taking all the width WordPress gives, because a set of twelve things is
 * what `diluxone_users_ui_wide_open()` is for. What the old grid had and this
 * one does not is twelve brand colours shouting at once — those stay where
 * they earn their keep, on the buttons people meet on the sign-in page, and
 * here a network is told apart by its logo.
 *
 * The rules and each provider's own screen are settings with something to
 * say beside them, so they are the other shape: a column of options at the
 * measure and a rail of explanation in the room it leaves over.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Screen social. */
function diluxone_users_screen_social(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$id        = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
	$providers = diluxone_users_sso_providers();

	if ( '' !== $id && isset( $providers[ $id ] ) ) {
		diluxone_users_screen_provider( $id, $providers[ $id ] );
		return;
	}

	diluxone_users_screen_panels( 'diluxone-users-social', __( 'Social login', 'diluxone-users' ) );
}

/**
 * Its tabs, by the registry, so an add-on bringing its own providers can add
 * a tab beside them.
 *
 * What the buttons look like is not here: it is on the Design screen with
 * everything else the plugin draws. This screen answers who can get in with a
 * network and how the accounts are joined up.
 *
 * The list of networks has nothing to save — every setting on it belongs to
 * one provider, on that provider's own screen — so it asks the screen for no
 * form. The rules do, and they let the screen carry the form, the nonce and
 * the button, like every other tab in the plugin.
 */
function diluxone_users_social_panels(): void {
	diluxone_users_register_panel(
		'diluxone-users-social',
		'providers',
		array(
			'label'    => __( 'Providers', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_social_providers',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-social',
		'general',
		array(
			'label'    => __( 'Rules', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_social_general',
			'save'     => 'diluxone_users_social_rules_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_social_panels' );

/** Saves the rules that hold for every network. */
function diluxone_users_social_rules_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_sso_link_by_email' => isset( $_POST['diluxone_users_sso_link_by_email'] ) ? 1 : 0,
			'diluxone_users_sso_verified_only' => isset( $_POST['diluxone_users_sso_verified_only'] ) ? 1 : 0,
		) + diluxone_users_scope_posted( 'diluxone_users_sso' )
	);
	// phpcs:enable
}

/**
 * How a network is doing, in the four words the rest of the plugin uses.
 *
 * A provider has four states of its own and the design system speaks four
 * others, and they are not the same four: "not tested" is neither on nor off,
 * it is halfway, and "no app created yet" is that same halfway one step
 * earlier. The translation happens once, here, so the card on the grid and the
 * box beside the provider's own settings say the same word about the same
 * network.
 */
function diluxone_users_sso_pill_state( string $state ): string {
	if ( 'enabled' === $state ) {
		return 'active';
	}

	return 'disabled' === $state ? 'off' : 'pending';
}

/**
 * And what that word means here, in one sentence.
 *
 * "Pending" covers two quite different situations — nothing created yet, and
 * created but never tested — and the difference is the whole of what somebody
 * has to do next, so the pill never travels without this line.
 */
function diluxone_users_sso_says( string $state ): string {
	if ( 'not-configured' === $state ) {
		return __( 'No app created for it yet: it has neither a client ID nor a secret.', 'diluxone-users' );
	}

	if ( 'not-tested' === $state ) {
		return __( 'It has its credentials and the live test has not been run. Until that round trip works the button cannot go up.', 'diluxone-users' );
	}

	if ( 'disabled' === $state ) {
		return __( 'Tested and working, and its button is not on the sign-in page.', 'diluxone-users' );
	}

	return __( 'Its button is on the sign-in page.', 'diluxone-users' );
}

/**
 * Where the button of a network is turned on or off, in one link.
 *
 * Both the grid and the provider's own screen offer it, and both come back
 * through `diluxone_users_screen_social()`, so the address is built once.
 */
function diluxone_users_sso_toggle_url( string $id, string $state ): string {
	return wp_nonce_url(
		diluxone_users_admin_url(
			'diluxone-users-social',
			array(
				'red'                   => $id,
				'diluxone_users_action' => 'enabled' === $state ? 'off' : 'on',
			)
		),
		'diluxone_users_social_toggle'
	);
}

/**
 * One network, as a card of the grid.
 *
 * It is the admin's own card and not a shape invented for this screen: the
 * logo goes where the overview puts its icon, the state pill where it puts the
 * number, and the sentence and the ways out where it puts its own. It used to
 * be that same card copied out by hand, because the piece took its icon as a
 * class name and its value as a line of text and a network has neither. Now
 * the piece takes both kinds, and this is the data.
 *
 * The card leads into the provider, and when there is a button to show or
 * hide it offers that as well: taking one network down is the thing somebody
 * does in a hurry, and a screen in the way of it is a screen too many.
 *
 * @param array<string, mixed> $provider The provider, for its name and its logo.
 */
function diluxone_users_sso_card( string $id, array $provider ): void {
	$state = diluxone_users_sso_state( $id );
	$logo  = diluxone_users_sso_icon( $id );
	$name  = (string) $provider['name'];

	/*
	 * The two ways out are not two of the same thing, and for a while they
	 * looked it: two links in a row with an arrow each, so a card offering
	 * "Settings" and "Take it off" offered them in one voice and the one that
	 * removes something read as invitingly as the one that sets it up.
	 *
	 * So each says what kind of action it is and the dashboard's own buttons
	 * draw it. What is principal depends on where the network has got to: on
	 * one with no app yet there is only one thing worth doing and it is
	 * creating it, and on a working one it is opening its settings. Putting a
	 * tested button up is a second, lighter action beside that, and taking one
	 * down is the one that costs something: it stays one press away, for the
	 * reason above, and it stops looking like the press the eye lands on.
	 */
	$links = array(
		array(
			'url'   => diluxone_users_admin_url( 'diluxone-users-social', array( 'provider' => $id ) ),
			'label' => 'not-configured' === $state
				? __( 'Get started', 'diluxone-users' )
				: __( 'Settings', 'diluxone-users' ),
			'tone'  => 'primary',
		),
	);

	if ( 'enabled' === $state || 'disabled' === $state ) {
		$links[] = array(
			'url'   => diluxone_users_sso_toggle_url( $id, $state ),
			'label' => 'enabled' === $state
				? __( 'Take it off', 'diluxone-users' )
				: __( 'Put it up', 'diluxone-users' ),
			'tone'  => 'enabled' === $state ? 'danger' : 'secondary',
		);
	}

	diluxone_users_ui_card(
		array(
			// The network's own logo when there is one; its initial is only
			// the fallback, and it is a letter rather than a drawing.
			'mark'   => '' !== $logo ? $logo : esc_html( mb_substr( $name, 0, 1 ) ),
			'title'  => $name,
			'state'  => diluxone_users_sso_pill_state( $state ),
			'detail' => diluxone_users_sso_says( $state ),
			'links'  => $links,
		)
	);
}

/**
 * Every network, as a grid.
 *
 * The grid is the one block on this screen with columns of its own, so it is
 * the one block that takes the window: twelve cards come out four and five
 * across instead of two, and nobody scrolls to find the eleventh. The prose
 * around it does not — an introduction read at 1600 pixels is not read — and
 * keeps the measure on its own, which is what `du-wide` wrapping the grid
 * alone rather than the whole screen buys.
 */
function diluxone_users_screen_social_providers(): void {
	diluxone_users_intro( __( 'One app per network: create it in the provider’s developer console, paste the client ID and the secret, and copy the redirect URL that each one shows. Then run the live test — a provider cannot be enabled until the round trip actually works.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'The networks', 'diluxone-users' ),
		__( 'Open any of them to create the app, paste what it gives back and test it.', 'diluxone-users' )
	);

	diluxone_users_ui_wide_open();
	diluxone_users_ui_cards_open();

	foreach ( diluxone_users_sso_providers() as $slug => $provider ) {
		diluxone_users_sso_card( (string) $slug, $provider );
	}

	diluxone_users_ui_cards_close();
	diluxone_users_ui_wide_close();

	diluxone_users_ui_section(
		__( 'Not included, on purpose', 'diluxone-users' ),
		__( 'Apple signs its client secret with a JWT that has to be regenerated every six months and answers by POST; Steam does not use OAuth 2 at all and never returns an email address. Both need their own flow, so they are not here yet: a button that does not work is worse than no button.', 'diluxone-users' )
	);
}

/**
 * What the buttons read back out of the form that draws them.
 *
 * @return array<string, mixed>
 */
function diluxone_users_sso_buttons_posted(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller verifies it.
	return array(
		'diluxone_users_sso_button_skin'    => sanitize_key( wp_unslash( $_POST['diluxone_users_sso_button_skin'] ?? 'brand' ) ),
		'diluxone_users_sso_button_shape'   => sanitize_key( wp_unslash( $_POST['diluxone_users_sso_button_shape'] ?? 'rounded' ) ),
		'diluxone_users_sso_button_show'    => sanitize_key( wp_unslash( $_POST['diluxone_users_sso_button_show'] ?? 'icon-text' ) ),
		'diluxone_users_sso_button_text'    => sanitize_text_field( wp_unslash( $_POST['diluxone_users_sso_button_text'] ?? '' ) ),
		'diluxone_users_sso_button_columns' => absint( wp_unslash( $_POST['diluxone_users_sso_button_columns'] ?? 2 ) ),
	);
	// phpcs:enable
}

/**
 * How the buttons look, on the Design screen.
 *
 * Only the questions. What they add up to is drawn in the column beside them
 * by diluxone_users_social_buttons_preview(), through the same stage every
 * other design tab uses — this was the last screen carrying two columns of its
 * own, which is why its preview sat a step further in than all the others and
 * did not look like them.
 *
 * Moving it there is not only tidier: the stage redraws on the server, so the
 * text on the buttons now follows what is typed as well as the finish does.
 * The choosers stay lists rather than cards for the same reason as before —
 * the answer to what a finish looks like is in the panel beside them, not in a
 * paragraph.
 *
 * The form and its button belong to the screen this lives on, which is why
 * neither is written here.
 */
function diluxone_users_screen_social_buttons(): void {
	diluxone_users_intro( __( 'How the buttons look on the sign-in page. It is the same markup and the same stylesheet the site uses, so the preview is the real thing.', 'diluxone-users' ) );

	$choosers = array(
		array(
			'id'      => 'diluxone_users_sso_button_skin',
			'label'   => __( 'Finish', 'diluxone-users' ),
			'options' => diluxone_users_sso_button_skins(),
			'help'    => __( 'Google and Microsoft always stay white with their own logo: their brand guidelines ask for it, and a four-colour logo on a coloured background does not read.', 'diluxone-users' ),
		),
		array(
			'id'      => 'diluxone_users_sso_button_shape',
			'label'   => __( 'Shape', 'diluxone-users' ),
			'options' => diluxone_users_sso_button_shapes(),
			'help'    => '',
		),
		array(
			'id'      => 'diluxone_users_sso_button_show',
			'label'   => __( 'What it shows', 'diluxone-users' ),
			'options' => diluxone_users_sso_button_contents(),
			'help'    => __( 'With logo only, the name is still there for screen readers.', 'diluxone-users' ),
		),
		array(
			'id'      => 'diluxone_users_sso_button_columns',
			'label'   => __( 'Layout', 'diluxone-users' ),
			'options' => diluxone_users_sso_button_columns(),
			'help'    => '',
		),
	);

	diluxone_users_ui_section( __( 'The finish', 'diluxone-users' ) );

	foreach ( $choosers as $chooser ) {
		diluxone_users_ui_select(
			array(
				'label'   => (string) $chooser['label'],
				'name'    => (string) $chooser['id'],
				'value'   => (string) diluxone_users_option( (string) $chooser['id'] ),
				'options' => (array) $chooser['options'],
				'help'    => (string) $chooser['help'],
			)
		);
	}

	diluxone_users_ui_section( __( 'What they say', 'diluxone-users' ) );

	diluxone_users_ui_words(
		'diluxone_users_sso_button_text',
		__( 'Text', 'diluxone-users' ),
		/* translators: %s: name of the social network */
		__( 'Continue with %s', 'diluxone-users' ),
		sprintf(
			/* translators: %s: the %s placeholder, literal */
			esc_html__( 'Where %s goes, the name of the network goes. Leave it empty to use the default, which is already translated.', 'diluxone-users' ),
			'<code>%s</code>'
		)
	);
}

/**
 * The buttons themselves, in the preview column.
 *
 * Four networks and not the whole catalogue: the question is what the finish
 * looks like, not how long the list is. They are drawn by the same function
 * the sign-in page calls, with the links taken out — so what is in the frame
 * is the markup and the stylesheet the site ships, sitting on the site's own
 * surface, which is the only ground they are ever seen on.
 *
 * That ground is where the old "on white / on dark" pair of buttons went. It
 * was asking the button screen a question that belongs to the colours: what
 * the buttons sit on is the surface the site chose, and the frame now shows
 * that instead of offering two grounds and being right about neither.
 */
function diluxone_users_social_buttons_preview(): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup, already escaped.
	echo diluxone_users_sso_buttons( array_slice( diluxone_users_sso_providers(), 0, 4, true ), false );
}

/**
 * The settings that hold for every network.
 *
 * Two questions with two tick boxes, and around them four things that are not
 * questions at all: whether an account gets created, what one ends up looking
 * like, the reassurance that nobody is locked out by any of this, and where
 * the rest of it is decided. Above and below the options those read as
 * interruptions — one of them was a four-column table drawn to hold a single
 * pill — and beside them they read as what they are. So the tab is the
 * plugin's other shape: the options at the measure, and the telling in the
 * rail.
 */
function diluxone_users_screen_social_general(): void {
	/*
	 * Decided on the registration screen and shown here as state. It used to
	 * be a tick box in both places for the same option, and a person could
	 * change it here and find it "changed by itself" over there.
	 */
	$creates   = (bool) diluxone_users_option( 'diluxone_users_sso_register' );
	$role      = (string) diluxone_users_option( 'diluxone_users_login_role' );
	$role_name = translate_user_role( wp_roles()->get_names()[ $role ] ?? $role );

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'These apply to every provider. They decide what happens when someone comes back from a social network.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'Recognising somebody who is already here', 'diluxone-users' ),
		__( 'Turned off, someone whose email is already registered simply cannot get in with a social network. Only turn it off if you do not trust the provider to verify its own users’ email addresses.', 'diluxone-users' )
	);

	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_sso_link_by_email',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_sso_link_by_email' ),
				'title'   => __( 'If the email already exists, link the network to that account', 'diluxone-users' ),
				'help'    => __( 'This is what makes signing in with Google today and with GitHub tomorrow land on the same account instead of creating two. Each network the person uses gets added to their account, and any of them works from then on.', 'diluxone-users' ),
			),
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_sso_verified_only',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_sso_verified_only' ),
				'title'   => __( 'Refuse the sign-in when the provider does not say the email is verified', 'diluxone-users' ),
				'help'    => __( 'Stricter, and a few providers never send that flag: with this on, those stop working.', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_section(
		__( 'Who can sign in with a network', 'diluxone-users' ),
		__( 'Everybody, or only the roles you pick. Whoever is left out signs in with the email link as always, so nobody is shut out of the site — and it is the account with the most power that is worth thinking about: an administrator who signs in with Google depends on that Google account never being taken over.', 'diluxone-users' )
	);

	/*
	 * The same control as the second step and the dashboard profile, and it
	 * says the same thing the same way round.
	 *
	 * This question was the odd one out: a heading reading "Roles that cannot
	 * use it" over a permanent column of every role on the site, each with an
	 * empty box. Nothing said what no ticks meant, the list was as long as the
	 * site had roles whether or not anybody was going to narrow it, and it was
	 * the one rule on these screens expressed by the negative — so a reader
	 * arriving from the second-step tab had to turn it round in their head.
	 */
	diluxone_users_ui_field_open( __( 'Who it reaches', 'diluxone-users' ) );

	diluxone_users_scope_control(
		'diluxone_users_sso',
		__( 'The roles ticked can use the buttons. Everybody else signs in with the email link.', 'diluxone-users' )
	);

	diluxone_users_ui_field_close();

	diluxone_users_ui_aside_close(
		static function () use ( $creates, $role_name ): void {
			diluxone_users_ui_note(
				__( 'Creating accounts', 'diluxone-users' ),
				$creates
					? __( 'Signing in with a social account creates the account when there is none.', 'diluxone-users' )
					: __( 'A social account only signs in people who already have one here.', 'diluxone-users' ),
				$creates ? 'active' : 'off'
			);

			diluxone_users_ui_note(
				__( 'How an account ends up', 'diluxone-users' ),
				array(
					sprintf(
						/* translators: 1: name of the role accounts are created with, 2: name of the screen where it is chosen */
						esc_html__( 'There is nothing to choose here, and that is on purpose: a new account gets the email address as its username, no password at all —not even one nobody knows how to use— and the role %1$s, which is set once for the whole site on the %2$s screen. A role per provider would be a quiet way of handing out privileges.', 'diluxone-users' ),
						esc_html( $role_name ),
						'«' . esc_html__( 'Registration', 'diluxone-users' ) . '»'
					),
					esc_html__( 'The name comes from the provider, and it only fills in what the person has not written themselves.', 'diluxone-users' ),
					esc_html__( 'Each linked network is stored on the person, so anybody can add a second and a third from their profile and unlink them again, and from then on any of them opens the same account.', 'diluxone-users' ),
				)
			);

			diluxone_users_ui_notice( esc_html__( 'Nobody is locked out by the list of roles: the email link is always there.', 'diluxone-users' ) );

			diluxone_users_ui_links(
				__( 'Where the rest of this lives', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'register' ) ),
						'label' => __( 'Whether an account gets created', 'diluxone-users' ),
						'help'  => __( 'Registration answers it once, for the email form and for the networks alike.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => 'social' ) ),
						'label' => __( 'What the buttons look like', 'diluxone-users' ),
						'help'  => __( 'Their finish, their shape and what they say, with the rest of what the plugin draws.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-social' ),
						'label' => __( 'The networks themselves', 'diluxone-users' ),
						'help'  => __( 'Each one’s app, its credentials and its live test.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * A provider's detail, with tabs of its own.
 *
 * Whichever tab is open, the same three things are true of the network and
 * were being said at the top of the screen before anybody got to the tab: how
 * it is doing, what that means, and what is in the way. Read there they are
 * news interrupting the instructions; read in the rail they are the context
 * the instructions are being followed in. So the screen is a column and a
 * rail, and what is in the rail depends on the tab — the technical detail of
 * the app belongs beside the steps for creating it and nowhere else.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_screen_provider( string $id, array $provider ): void {
	$tabs = array(
		'start'    => __( 'Getting started', 'diluxone-users' ),
		'settings' => __( 'Settings', 'diluxone-users' ),
		'usage'    => __( 'Usage', 'diluxone-users' ),
	);

	$current = diluxone_users_tab( $tabs );

	if ( current_user_can( 'manage_options' ) && isset( $_POST['diluxone_users_provider_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_provider_nonce'] ) ), 'diluxone_users_provider' ) ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$typed = sanitize_text_field( wp_unslash( $_POST['diluxone_users_client_secret'] ?? '' ) );

		diluxone_users_sso_save_credentials(
			$id,
			array(
				'active' => isset( $_POST['diluxone_users_active'] ) ? 1 : 0,
				'id'     => sanitize_text_field( wp_unslash( $_POST['diluxone_users_client_id'] ?? '' ) ),
				// An empty box means "leave it as it is", because an empty box
				// is what the form shows: the stored secret is never printed
				// back into the page. Saving any other setting on this screen
				// would otherwise wipe the credential.
				'secret' => '' === $typed ? diluxone_users_sso_credentials( $id )['secret'] : $typed,
			)
		);
		// phpcs:enable

		diluxone_users_notice( __( 'Provider saved.', 'diluxone-users' ) );
	}

	$state = diluxone_users_sso_state( $id );

	diluxone_users_screen_open( (string) $provider['name'], 'diluxone-users-social', $tabs, $current, array( 'provider' => $id ) );

	diluxone_users_ui_aside_open();
	?>
	<p><a href="<?php echo esc_url( diluxone_users_admin_url( 'diluxone-users-social' ) ); ?>">&larr; <?php esc_html_e( 'Back to all providers', 'diluxone-users' ); ?></a></p>
	<?php

	diluxone_users_screen_provider_actions( $id, $state );

	if ( 'settings' === $current ) {
		diluxone_users_screen_provider_settings( $id, $state );
	} elseif ( 'usage' === $current ) {
		diluxone_users_screen_provider_usage( $id, $provider );
	} else {
		diluxone_users_screen_provider_start( $id, $provider );
	}

	diluxone_users_ui_aside_close(
		static function () use ( $id, $provider, $state, $current ): void {
			diluxone_users_provider_rail( $id, $provider, $state, $current );
		}
	);

	diluxone_users_screen_close();
}

/**
 * What the app gave back, and whether its button is up.
 *
 * The tick box stays live while the provider has not been tested, rather than
 * going grey: what is ticked today is what applies the day the test passes,
 * and `diluxone_users_sso_save_credentials()` is the one that refuses to turn
 * on something untested — here it is only said out loud.
 */
function diluxone_users_screen_provider_settings( string $id, string $state ): void {
	$credentials = diluxone_users_sso_credentials( $id );
	?>
	<form method="post" id="diluxone-users-provider-form">
		<?php
		wp_nonce_field( 'diluxone_users_provider', 'diluxone_users_provider_nonce' );
		diluxone_users_ui_save( 'diluxone-users-provider-form' );

		diluxone_users_ui_section(
			__( 'What the app gave you', 'diluxone-users' ),
			__( 'Both come from the provider’s console, and changing either of them asks for the live test again.', 'diluxone-users' )
		);

		diluxone_users_ui_text(
			array(
				'label' => __( 'Client ID', 'diluxone-users' ),
				'name'  => 'diluxone_users_client_id',
				'value' => (string) $credentials['id'],
				'code'  => true,
			)
		);

		/*
		 * The secret goes in and does not come back out. It is stored in the
		 * clear — WordPress has nowhere else to put it — and that is the part
		 * there is no choice about; printing it into `value=""` on every load
		 * of this screen is the part there was. A saved one shows as a row of
		 * dots that is not the secret, and leaving the box alone keeps it.
		 */
		diluxone_users_ui_text(
			array(
				'label'       => __( 'Secret', 'diluxone-users' ),
				'name'        => 'diluxone_users_client_secret',
				'value'       => '',
				'type'        => 'password',
				'code'        => true,
				'placeholder' => '' === (string) $credentials['secret']
					? ''
					: '••••••••••••••••',
				'help'        => '' === (string) $credentials['secret']
					? ''
					: __( 'A secret is saved. Leave this empty to keep it, or paste a new one to replace it.', 'diluxone-users' ),
			)
		);

		diluxone_users_ui_section( __( 'The button on the sign-in page', 'diluxone-users' ) );

		if ( 'enabled' !== $state && 'disabled' !== $state ) {
			diluxone_users_not_now( __( 'This provider has not passed the live test, so its button cannot go up yet. What is ticked here applies the day it does.', 'diluxone-users' ) );
		}

		diluxone_users_ui_choices(
			array(
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_active',
					'value'   => '1',
					'checked' => (bool) $credentials['active'],
					'title'   => __( 'Show the button', 'diluxone-users' ),
					'help'    => __( 'It goes with the email form, wherever the sign-in page is.', 'diluxone-users' ),
				),
			)
		);
		?>
	</form>
	<?php
}

/**
 * Where the buttons come out, and the direct link for anywhere else.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_screen_provider_usage( string $id, array $provider ): void {
	diluxone_users_intro( __( 'The buttons are drawn by the sign-in shortcode, together with the email form. There is nothing else to place.', 'diluxone-users' ) );

	diluxone_users_ui_section( __( 'The shortcodes that draw them', 'diluxone-users' ) );

	diluxone_users_ui_note(
		__( 'The sign-in page', 'diluxone-users' ),
		'<code>[diluxone_users_login]</code> — ' . esc_html__( 'The email sign-in form and the social buttons.', 'diluxone-users' )
	);

	diluxone_users_ui_note(
		__( 'The account area', 'diluxone-users' ),
		'<code>[diluxone_users_accounts]</code> — ' . esc_html__( 'Linked providers, to link or unlink.', 'diluxone-users' )
	);

	diluxone_users_ui_note(
		sprintf(
			/* translators: %s: provider name */
			__( 'A direct link to sign in with %s, if you want it somewhere else', 'diluxone-users' ),
			(string) $provider['name']
		),
		'<code>' . esc_html( diluxone_users_sso_login_url( $id ) ) . '</code>'
	);
}

/**
 * "Getting started": what to create, where, and which URL to paste.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_screen_provider_start( string $id, array $provider ): void {
	$guide = diluxone_users_sso_guide( $id );
	$name  = (string) $provider['name'];

	diluxone_users_intro(
		sprintf(
			/* translators: %s: provider name */
			__( 'To let people sign in with their %s account you have to create an app there. Below is the whole thing, click by click.', 'diluxone-users' ),
			$name
		)
	);

	diluxone_users_ui_section(
		__( 'The URL they are going to ask you for', 'diluxone-users' ),
		__( 'Keep it at hand: one of the steps below asks for it, and it has to be pasted exactly as it is.', 'diluxone-users' )
	);

	diluxone_users_ui_text(
		array(
			'label'    => __( 'Redirect URL', 'diluxone-users' ),
			'name'     => 'diluxone_users_redirect_uri',
			'value'    => diluxone_users_sso_redirect_uri( $id ),
			'code'     => true,
			'readonly' => true,
			'help'     => esc_html__( 'Depending on the provider it is called redirect URI, callback URL, return URL or authorized redirect URL.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_section(
		sprintf(
			/* translators: %s: provider name */
			__( 'Step by step in %s', 'diluxone-users' ),
			$name
		)
	);
	?>
	<p class="diluxone-users-admin__actions">
		<a class="button" href="<?php echo esc_url( (string) $provider['console'] ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'Open the console', 'diluxone-users' ); ?>
			<span class="dashicons dashicons-external" aria-hidden="true"></span>
		</a>
		<span class="description"><?php echo esc_html( (string) $provider['console'] ); ?></span>
	</p>

	<?php
	diluxone_users_ui_steps( (array) $guide['steps'] );

	diluxone_users_ui_section(
		__( 'And then, here', 'diluxone-users' ),
		__( 'Paste the client ID and the secret in Settings, run the live test, and turn the button on.', 'diluxone-users' )
	);

	$settings = diluxone_users_admin_url(
		'diluxone-users-social',
		array(
			'provider' => $id,
			'tab'      => 'settings',
		)
	);
	?>
	<p class="diluxone-users-admin__actions">
		<a class="button button-primary" href="<?php echo esc_url( $settings ); ?>">
			<?php esc_html_e( 'I already created the app', 'diluxone-users' ); ?>
		</a>
	</p>
	<?php
}

/**
 * The button that opens the live test.
 *
 * It is a real link, with a `target`: if the admin JavaScript did not load,
 * the test still opens in a tab. The window size is set by the script reading
 * `data-diluxone-users-popup`; an `onclick` in the markup would be no use,
 * because `wp_kses_post()` strips event handlers and the button was left
 * doing nothing.
 */
function diluxone_users_sso_test_button( string $id, string $state ): void {
	?>
	<a class="button <?php echo 'not-tested' === $state ? 'button-primary' : 'button-secondary'; ?>"
		href="<?php echo esc_url( diluxone_users_sso_test_url( $id ) ); ?>"
		target="diluxone-users-test"
		data-diluxone-users-popup="600x740">
		<?php
		echo 'not-tested' === $state
			? esc_html__( 'Run the live test', 'diluxone-users' )
			: esc_html__( 'Test it again', 'diluxone-users' );
		?>
	</a>
	<?php
}

/**
 * What there is to do about this provider, right now.
 *
 * The test is the real round trip against the provider, in a separate window:
 * it is the only way of knowing that the ID, the secret and the callback URL
 * are right before the first person who cannot get in finds out. That is why
 * a provider cannot be turned on without having been tested.
 *
 * These two are the only things on this screen that act, so they stay in the
 * column that is being read and do not go in the rail: the rail tells, it does
 * not ask. A provider with no app yet has neither to offer — there is nothing
 * to test and nothing to put up — and the rail says what to do instead.
 */
function diluxone_users_screen_provider_actions( string $id, string $state ): void {
	if ( 'not-configured' === $state ) {
		return;
	}
	?>
	<p class="diluxone-users-admin__actions">
		<?php diluxone_users_sso_test_button( $id, $state ); ?>

		<?php if ( 'not-tested' !== $state ) : ?>
			<a class="button <?php echo 'enabled' === $state ? '' : 'button-primary'; ?>"
				href="<?php echo esc_url( diluxone_users_sso_toggle_url( $id, $state ) ); ?>">
				<?php echo 'enabled' === $state ? esc_html__( 'Take the button off', 'diluxone-users' ) : esc_html__( 'Put the button up', 'diluxone-users' ); ?>
			</a>
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Everything about this provider that is not something to do.
 *
 * Where it stands, what is in the way of it, what the app it needs actually
 * asks for, and the way to the two screens that decide the rest. It used to be
 * a summary table of one row plus a warning above the tab and four boxes under
 * it, which is the same content arranged so that the instructions were read
 * last.
 *
 * The technical detail only comes out on "Getting started": beside the steps
 * for creating the app it answers the question those steps raise, and beside
 * the credentials or the shortcodes it is trivia.
 *
 * @param array<string, mixed> $provider
 */
function diluxone_users_provider_rail( string $id, array $provider, string $state, string $tab ): void {
	$name = (string) $provider['name'];

	diluxone_users_ui_note( $name, diluxone_users_sso_says( $state ), diluxone_users_sso_pill_state( $state ) );

	if ( 'not-configured' === $state ) {
		diluxone_users_ui_notice( esc_html__( 'Create the app, load the redirect URL and paste the client ID and the secret in Settings.', 'diluxone-users' ) );
	}

	if ( 'not-tested' === $state ) {
		diluxone_users_ui_notice(
			sprintf(
				/* translators: %s: provider name */
				esc_html__( 'A window opens, %s asks you to authorise, and it comes back here. Nobody is signed in and nothing is saved to your account — it only checks that the round trip works. Until it does, the button cannot be enabled.', 'diluxone-users' ),
				esc_html( $name )
			),
			'warning'
		);
	}

	if ( 'start' === $tab ) {
		$guide = diluxone_users_sso_guide( $id );

		if ( '' !== $guide['gotcha'] ) {
			diluxone_users_ui_notice(
				'<strong>' . esc_html__( 'Watch out:', 'diluxone-users' ) . '</strong> ' . esc_html( $guide['gotcha'] ),
				'warning'
			);
		}

		$asks = array(
			sprintf(
				/* translators: %s: the permissions the app asks for, as code */
				esc_html__( 'Permissions requested: %s', 'diluxone-users' ),
				'<code>' . esc_html( (string) $provider['scope'] ) . '</code>'
			),
			sprintf(
				/* translators: %s: the authorization URL, as code */
				esc_html__( 'People are sent to authorise at %s', 'diluxone-users' ),
				'<code>' . esc_html( (string) $provider['authorize'] ) . '</code>'
			),
		);

		if ( ! empty( $provider['pkce'] ) ) {
			$asks[] = esc_html__( 'It requires PKCE. The plugin does that part; there is nothing to configure.', 'diluxone-users' );
		}

		diluxone_users_ui_note( __( 'What this provider asks for', 'diluxone-users' ), $asks );
	}

	$links = array(
		array(
			'url'   => diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => 'social' ) ),
			'label' => __( 'What its button looks like', 'diluxone-users' ),
			'help'  => __( 'One finish for all the networks at once, with the rest of what the plugin draws.', 'diluxone-users' ),
		),
		array(
			'url'   => diluxone_users_admin_url( 'diluxone-users-social', array( 'tab' => 'general' ) ),
			'label' => __( 'The rules for every network', 'diluxone-users' ),
			'help'  => __( 'Who gets recognised, who is refused, and which roles may not use a network at all.', 'diluxone-users' ),
		),
	);

	if ( 'start' === $tab ) {
		array_unshift(
			$links,
			array(
				'url'      => (string) $provider['guide'],
				'label'    => sprintf(
					/* translators: %s: provider name */
					__( '%s’s own documentation', 'diluxone-users' ),
					$name
				),
				'help'     => __( 'For the day their console moves a button and the steps here no longer match.', 'diluxone-users' ),
				// Somebody halfway through the steps on this screen should
				// still have them when they come back from reading those.
				'external' => true,
			)
		);
	}

	diluxone_users_ui_links( __( 'Where the rest of this lives', 'diluxone-users' ), $links );
}

/**
 * Turning a provider's button on or off, from the row on the list as well as
 * from the provider's own screen: both point here.
 *
 * On `admin_init`, before the dashboard prints a byte: it ends in a redirect,
 * and a redirect from inside the page is a header sent after the page began —
 * the change was written and the screen came back empty.
 */
function diluxone_users_social_toggle(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only which page this is; the nonce is checked below.
	if ( 'diluxone-users-social' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) || ! isset( $_GET['diluxone_users_action'], $_GET['red'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'diluxone_users_social_toggle' );

	$network = sanitize_key( wp_unslash( $_GET['red'] ) );

	if ( isset( diluxone_users_sso_providers()[ $network ] ) && diluxone_users_sso_tested( $network ) ) {
		$all = (array) get_option( 'diluxone_users_sso', array() );

		$all[ $network ]['active'] = 'on' === sanitize_key( wp_unslash( $_GET['diluxone_users_action'] ) ) ? 1 : 0;

		update_option( 'diluxone_users_sso', $all, false );
	}

	wp_safe_redirect( diluxone_users_admin_url( 'diluxone-users-social' ) );
	exit;
}
add_action( 'admin_init', 'diluxone_users_social_toggle' );
