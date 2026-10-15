<?php
/**
 * The second-factor screen.
 *
 * Deliberately its own file, next to auth.php: the second
 * factor is one feature and it lives in one place, so it can be moved, turned
 * off or replaced without editing a screen that belongs to something else.
 *
 * A second factor is NOT the e-mail sign-in link. The link is how somebody
 * gets in with no password at all; the second factor is what is asked *after*
 * they got in, on top of whatever they used. The plugin knows the difference
 * — see diluxone_users_2fa_worth_it_on_link().
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Its tab on the security screen. */
function diluxone_users_2fa_panel(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_SECURITY,
		'2fa',
		array(
			'label'    => __( 'Two-step verification', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_login_2fa',
			'save'     => 'diluxone_users_2fa_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_2fa_panel' );

/**
 * What is said when the step is on and nothing can carry the code.
 *
 * Written once and read twice: the group of tick boxes carries it on screen,
 * and the save prints it when the form arrived anyway. Two copies of a
 * sentence are two sentences by the second time somebody edits one of them.
 */
function diluxone_users_2fa_needs_a_method(): string {
	return __( 'Choose at least one way of sending the code. With the second step on and none of these ticked, nobody can finish signing in.', 'diluxone-users' );
}

/**
 * What is said while the second step has nowhere to be answered.
 *
 * Written once and read twice, like the sentence above: the screen carries
 * it under the three answers, and the save prints it when "required" arrived
 * anyway.
 */
function diluxone_users_2fa_needs_a_surface(): string {
	return __( 'There is no sign-in page, and this site says wp-login.php does not draw the second step, so there is nowhere to type the code: it is not asked of anybody and cannot be required. Choose a sign-in page and it can.', 'diluxone-users' );
}

/**
 * Every method the screen offers, the ones turned off included.
 *
 * The registry, diluxone_users_2fa_methods(), only answers with the methods
 * the site turned on: the ones turned off do not exist for anybody signing
 * in. The screen and its save are the exception — the box of a method that is
 * off has to exist to turn it on, and a save that only kept the ones already
 * on could never turn one back on.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_2fa_methods_offered(): array {
	return (array) apply_filters(
		'diluxone_users_2fa_methods',
		array(
			'email' => array(
				'label' => __( 'A code by email', 'diluxone-users' ),
				'help'  => __( 'Six digits to the address on the account. Nothing to install, which is why it is the one people actually turn on.', 'diluxone-users' ),
			),
		)
	);
}

/**
 * Saves it.
 *
 * @return bool False when the second step is on with no way of sending the
 *              code, in which case nothing was written.
 */
function diluxone_users_2fa_save(): bool {
	check_admin_referer( 'diluxone_users_panel_' . DILUXONE_USERS_SECURITY, 'diluxone_users_panel_nonce' );
	diluxone_users_panel_allowed();

	/*
	 * Only what the form asked. Nothing chosen under "when it is asked for"
	 * — a fresh install, saved before anybody picked — is not a choice of
	 * "optional", and the two groups under it wait for that answer: while
	 * it is off or unset they are drawn disabled, and a disabled control
	 * sends nothing. A key that did not arrive keeps what it had. The tick
	 * boxes leave a line saying they were drawn (diluxone_users_ui_choice()),
	 * disabled with them, which is how "none ticked" is told from "not
	 * asked".
	 */
	$drawn = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_panel_drawn'] ?? array() ) );

	$chose_mode    = isset( $_POST['diluxone_users_2fa_mode'] );
	$asked_methods = isset( $_POST['diluxone_users_2fa_methods'] ) || in_array( 'diluxone_users_2fa_methods', $drawn, true );
	$asked_link    = isset( $_POST['diluxone_users_2fa_link'] );

	$mode    = $chose_mode ? sanitize_key( wp_unslash( $_POST['diluxone_users_2fa_mode'] ) ) : (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
	$methods = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_2fa_methods'] ?? array() ) );
	$link    = sanitize_key( wp_unslash( $_POST['diluxone_users_2fa_link'] ?? 'auto' ) );

	// Each answer is one of the answers the screen offers. Anything else read
	// back later as something nobody chose: a mode of `bogus` behaved as
	// optional while the screen showed no mode at all, and a method nothing
	// registered was a method the site said it had.
	$mode    = in_array( $mode, array( 'off', 'optional', 'required' ), true ) ? $mode : 'optional';
	$link    = in_array( $link, array( 'auto', 'always', 'never' ), true ) ? $link : 'auto';
	$methods = array_values( array_intersect( $methods, array_keys( diluxone_users_2fa_methods_offered() ) ) );
	// Kept, and kept out of the question below: a method stored by something
	// that is not running now is not one this screen drew, so it is not one
	// it can take away — and it sends no code today, so it answers nothing.
	$kept = diluxone_users_unknown_entries( (array) diluxone_users_raw_get( 'diluxone_users_2fa_methods', array() ), array_keys( diluxone_users_2fa_methods_offered() ) );

	/*
	 * Off is the one mode where none of them is an answer: nothing is asked,
	 * so nothing has to carry a code. In the other two the step is on, and a
	 * step on with no way of sending the code is a site whose people get as
	 * far as the second screen and no further. It saved exactly that, without
	 * a word, until this stood in the way.
	 *
	 * Before anything is written, not after: a half-saved state here is a
	 * screen showing what was refused as though it had been kept. A form
	 * that did not draw the boxes — one sent with the script off, from a tab
	 * drawn while the step was off — is judged on the methods stored: the
	 * step cannot be turned on over an empty list either way.
	 */
	$carrying = $asked_methods
		? $methods
		: array_values( array_intersect( array_map( 'strval', (array) diluxone_users_option( 'diluxone_users_2fa_methods' ) ), array_keys( diluxone_users_2fa_methods_offered() ) ) );

	if ( 'off' !== $mode && ! diluxone_users_ui_needs_one( $carrying, diluxone_users_2fa_needs_a_method() ) ) {
		return false;
	}

	// Required, with nowhere to answer it, is everybody it applies to
	// locked out at the second screen. The browser never offered it; a form
	// sent by hand is refused here, before anything is written.
	if ( 'required' === $mode && '' === diluxone_users_2fa_surface() ) {
		diluxone_users_notice( diluxone_users_2fa_needs_a_surface(), 'error' );

		return false;
	}

	$saved = array();

	if ( $chose_mode ) {
		$saved['diluxone_users_2fa_mode'] = $mode;
	}

	if ( $asked_methods ) {
		$saved['diluxone_users_2fa_methods'] = array_values( array_unique( array_merge( $methods, $kept ) ) );
	}

	if ( $asked_link ) {
		$saved['diluxone_users_2fa_link'] = $link;
	}

	diluxone_users_save_options( $saved );

	return true;
}

/**
 * What the three settings above add up to for somebody arriving by link.
 *
 * The answer depends on the mode, on the rule for the link and on whether any
 * method that is turned on arrives somewhere other than the inbox — three
 * controls on one screen, and until this said so out loud the only way to
 * know was to work it out. It is the same rule the sign-in runs, read from
 * the saved settings instead of from a person.
 *
 * @return array{state: string, line: string} A state for the pill, and the sentence beside it.
 */
function diluxone_users_2fa_link_today(): array {
	$mode = (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
	$link = (string) diluxone_users_option( 'diluxone_users_2fa_link' );

	// Nobody decided whether there is a second step: there is none, as
	// WordPress has it, and that is not a thing somebody turned off.
	if ( ! diluxone_users_option_is_set( 'diluxone_users_2fa_mode' ) ) {
		return array(
			'state' => 'unset',
			'line'  => __( 'Nothing is asked: there is no second step on this site, as WordPress has it, until one is chosen.', 'diluxone-users' ),
		);
	}

	if ( 'off' === $mode ) {
		return array(
			'state' => 'off',
			'line'  => __( 'Nothing is asked: the second step is off for the whole site, whatever is chosen here.', 'diluxone-users' ),
		);
	}

	if ( 'always' === $link ) {
		return array(
			'state' => 'active',
			'line'  => __( 'The second step is asked of somebody who came in by link, whichever one they have.', 'diluxone-users' ),
		);
	}

	if ( 'never' === $link ) {
		return array(
			'state' => 'off',
			'line'  => __( 'Nothing is asked of somebody who came in by link: following it is the whole of it.', 'diluxone-users' ),
		);
	}

	foreach ( diluxone_users_2fa_methods() as $method ) {
		if ( 'email' !== ( $method['channel'] ?? 'email' ) ) {
			return array(
				'state' => 'active',
				'line'  => __( 'The second step is asked: a method that is on does not arrive by e-mail, so it proves something the link did not.', 'diluxone-users' ),
			);
		}
	}

	return array(
		'state' => 'off',
		'line'  => __( 'Nothing is asked: every method that is on arrives by e-mail, at the inbox the person just opened.', 'diluxone-users' ),
	);
}

/**
 * The same answer as a word, for the pill that carries it.
 *
 * "Active" and "Off" are the words of a switch, and this is not one: nothing
 * here turns the sign-in link on or off — that is on Access — and a pill
 * saying Off beside a line about the link is read as the link being off.
 * It was read that way, on this very screen. What is on or off is whether the
 * second step gets asked of somebody who arrived by one, so that is what the
 * word says, and the state underneath it stays the plugin's own.
 */
function diluxone_users_2fa_link_word( string $state ): string {
	// Not configured is a word of its own, and the pill's.
	if ( 'unset' === $state ) {
		return '';
	}

	return 'active' === $state
		? __( 'Asked', 'diluxone-users' )
		: __( 'Not asked', 'diluxone-users' );
}

/**
 * The second factor: who is asked for it, with what, and when not.
 *
 * The methods are listed from the registry and not by hand: if an add-on adds
 * one, it shows up here on its own.
 */
function diluxone_users_screen_login_2fa(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'One more thing after the password or the link: a code that only that person has. Whoever gets hold of an email still does not get in.', 'diluxone-users' ) );

	$all     = diluxone_users_2fa_methods_offered();
	$enabled = (array) diluxone_users_option( 'diluxone_users_2fa_methods' );
	$mode    = (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
	$decided = diluxone_users_option_is_set( 'diluxone_users_2fa_mode' );
	$today   = diluxone_users_2fa_link_today();
	$nowhere = '' === diluxone_users_2fa_surface();

	diluxone_users_ui_section( __( 'When it is asked for', 'diluxone-users' ) );

	// An answer is drawn chosen once somebody chose it: on a fresh install
	// none is, and the groups below wait for one.
	$modes = array(
		array(
			'name'    => 'diluxone_users_2fa_mode',
			'value'   => 'optional',
			'checked' => $decided && 'optional' === $mode,
			'title'   => __( 'Optional: whoever wants it turns it on from their account', 'diluxone-users' ),
			'help'    => __( 'Nobody is made to do anything, and whoever turned it on is asked from then on.', 'diluxone-users' ),
		),
		array(
			'name'     => 'diluxone_users_2fa_mode',
			'value'    => 'required',
			'checked'  => $decided && 'required' === $mode,
			'disabled' => $nowhere && 'required' !== $mode,
			'state'    => $nowhere ? 'pending' : '',
			'note'     => $nowhere ? __( 'needs a sign-in page', 'diluxone-users' ) : '',
			'title'    => __( 'Required: everybody it applies to has to set it up', 'diluxone-users' ),
			'help'     => __( 'They are walked through it the next time they sign in, and there is no way past.', 'diluxone-users' ),
		),
		array(
			'name'    => 'diluxone_users_2fa_mode',
			'value'   => 'off',
			'checked' => $decided && 'off' === $mode,
			'title'   => __( 'Off: it is not offered at all', 'diluxone-users' ),
			'help'    => __( 'Nobody is asked, not even whoever had it set up. What they set up is kept and works again the day this comes back on.', 'diluxone-users' ),
		),
	);

	// After the three, the one Users+ Pro brings, as the radio it would be:
	// switched off, and gone once Pro answers false to it.
	diluxone_users_ui_choices(
		diluxone_users_ui_pro_choices(
			$modes,
			array(
				array(
					'where'  => 'security/2fa/roles',
					'screen' => 'security-2fa',
					'title'  => __( 'Only some roles', 'diluxone-users' ),
					'text'   => __( 'Users+ Pro asks the second step of the roles you choose — the ones that can change things — and not of everybody with an account.', 'diluxone-users' ),
				),
			)
		)
	);

	/*
	 * Off — or nothing chosen yet — is the one answer that makes every
	 * question below it moot, so the groups below wait for it: drawn
	 * disabled with nothing ticked, under one line saying so, until it is
	 * turned on above (diluxone_users_ui_choices(), $waits). Nowhere to
	 * answer the step is said the same way as before, under the answer that
	 * caused it, while that answer is the saved one.
	 */
	$waits = array(
		'why'     => $decided
			? __( 'The second step is off, so nothing below is asked of anybody. Turn it on above and this applies.', 'diluxone-users' )
			: __( 'No second step is chosen yet: nothing is asked of anybody, as WordPress has it. Choose one above and this applies.', 'diluxone-users' ),
		'name'    => 'diluxone_users_2fa_mode',
		'is'      => array( 'optional', 'required' ),
		'waiting' => ! $decided || 'off' === $mode,
	);

	if ( $decided && 'off' !== $mode && $nowhere ) {
		diluxone_users_not_now(
			diluxone_users_2fa_needs_a_surface(),
			diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'page' ) ),
			__( 'The sign-in page →', 'diluxone-users' )
		);
	}

	diluxone_users_2fa_screen_place( 'who' );

	diluxone_users_ui_section( __( 'With what', 'diluxone-users' ) );

	$cards = array();

	foreach ( $all as $key => $method ) {
		$cards[] = array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_2fa_methods[]',
			'value'   => (string) $key,
			'checked' => in_array( (string) $key, $enabled, true ),
			'title'   => (string) $method['label'],
			'help'    => (string) ( $method['help'] ?? '' ),
		);
	}

	// Kept by the save, so shown: ticked, switched off, after the real ones.
	foreach ( diluxone_users_unknown_entries( (array) diluxone_users_raw_get( 'diluxone_users_2fa_methods', array() ), array_keys( $all ) ) as $key ) {
		$cards[] = diluxone_users_ui_kept( 'diluxone_users_2fa_methods[]', $key, __( 'no code from it, and the code by email in its place when nothing else is ticked', 'diluxone-users' ), 'checkbox' );
	}

	/*
	 * Asked of the browser only while the step is on, and "on" is whichever
	 * of the three above is picked at the moment of the press — not the one
	 * that was saved. Turning it off and untying both methods in one go is a
	 * form with nothing wrong in it, and the browser used to refuse it.
	 */
	diluxone_users_ui_choices(
		// After the real methods, the one Users+ Pro brings, as the box it
		// would be: switched off, and gone once Pro answers false to it.
		diluxone_users_ui_pro_choices(
			$cards,
			array(
				array(
					'type'   => 'checkbox',
					'where'  => 'security/2fa/app',
					'screen' => 'security-2fa',
					'title'  => __( 'An authenticator app', 'diluxone-users' ),
					'text'   => __( 'Users+ Pro adds the six digits that change every thirty seconds, from Google Authenticator, 1Password or Aegis: the code never travels, so it is asked on the e-mail link too.', 'diluxone-users' ),
				),
			)
		),
		diluxone_users_2fa_needs_a_method(),
		array(
			'name' => 'diluxone_users_2fa_mode',
			'is'   => array( 'optional', 'required' ),
		),
		$waits
	);

	diluxone_users_2fa_screen_place( 'methods' );

	diluxone_users_ui_section(
		__( 'Asking it of somebody who came in by link', 'diluxone-users' ),
		__( 'Whether there is a sign-in link at all is decided on Access. This is only what happens after one is followed.', 'diluxone-users' )
	);

	// Worth saying only once the group is asked at all: under the line that
	// says it waits, a second reason is noise.
	if ( ! $waits['waiting'] && ! diluxone_users_login_has_link() ) {
		diluxone_users_not_now(
			__( 'The e-mail link is not one of the ways in on this site, so nobody follows one. What is chosen here applies the day it is.', 'diluxone-users' ),
			diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
			__( 'Ways in →', 'diluxone-users' )
		);
	}

	$rules = array(
		array(
			'name'    => 'diluxone_users_2fa_link',
			'value'   => 'auto',
			'checked' => 'auto' === (string) diluxone_users_option( 'diluxone_users_2fa_link' ),
			'title'   => __( 'Work it out: ask, unless the second step is another email', 'diluxone-users' ),
			'help'    => __( 'A code sent to the inbox the person has just opened to follow the link proves nothing the link did not prove already. An app does, so on an app it asks.', 'diluxone-users' ),
		),
		array(
			'name'    => 'diluxone_users_2fa_link',
			'value'   => 'always',
			'checked' => 'always' === (string) diluxone_users_option( 'diluxone_users_2fa_link' ),
			'title'   => __( 'Always ask', 'diluxone-users' ),
			'help'    => __( 'The second step on every link, including the errand of a code going back to the same inbox.', 'diluxone-users' ),
		),
		array(
			'name'    => 'diluxone_users_2fa_link',
			'value'   => 'never',
			'checked' => 'never' === (string) diluxone_users_option( 'diluxone_users_2fa_link' ),
			'title'   => __( 'Never ask', 'diluxone-users' ),
			'help'    => __( 'Following the link is the whole of it. The quickest way in, and the weakest of the three.', 'diluxone-users' ),
		),
	);

	// After the three, the one Users+ Pro brings, as the box it would be:
	// switched off, and gone once Pro answers false to it.
	diluxone_users_ui_choices(
		diluxone_users_ui_pro_choices(
			$rules,
			array(
				array(
					'type'   => 'checkbox',
					'where'  => 'security/2fa/remember',
					'screen' => 'security-2fa',
					'title'  => __( 'Remember a browser', 'diluxone-users' ),
					'text'   => __( 'Users+ Pro lets people skip the second step for a few days on a browser that already passed it, with a signed cookie that lets nobody in on its own.', 'diluxone-users' ),
				),
			)
		),
		'',
		array(),
		$waits
	);

	diluxone_users_2fa_screen_place( 'end' );

	/*
	 * What the three answers above come to is worth saying and is not a
	 * fourth question, and in the column of settings that is what it looked
	 * like: a row with a pill in it, between two groups that are asking for
	 * something. Beside them it is what it is — the screen reading itself
	 * back.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $today ): void {
			diluxone_users_ui_aside_state(
				'<strong>' . esc_html__( 'The second step, for somebody who came in by link.', 'diluxone-users' ) . '</strong> ' . $today['line'],
				$today['state'],
				'',
				diluxone_users_2fa_link_word( $today['state'] )
			);
		}
	);
}

/**
 * A place on the tab where an add-on draws settings of its own.
 *
 * `who` under the three answers (who it is asked of), `methods` under the
 * methods, `end` after the e-mail link's rule. What it draws is saved with
 * the tab: it listens on `diluxone_users_panel_saved` for this screen and the
 * `2fa` tab.
 */
function diluxone_users_2fa_screen_place( string $place ): void {
	/**
	 * Fires at a place of the Two-step verification tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $place 'who', 'methods' or 'end'.
	 */
	do_action( 'diluxone_users_2fa_screen', $place );
}

/**
 * Who the second step reaches, in words.
 *
 * Everybody with an account, as far as the plugin goes. An add-on that
 * narrows it says the result in its own line through `diluxone_users_2fa_who`,
 * and an empty answer is a site that reaches nobody — one that thinks it asks
 * for a code and does not — which the summary says out loud.
 */
function diluxone_users_2fa_who(): string {
	/**
	 * Filters who the second step reaches, in a line of the summary.
	 *
	 * @since 1.0.0
	 *
	 * @param string $who A list of roles or "everybody with an account"; '' for nobody.
	 */
	return (string) apply_filters( 'diluxone_users_2fa_who', __( 'everybody with an account', 'diluxone-users' ) );
}

/**
 * The second step, as three lines of the security summary.
 *
 * Three and not one because they are three answers a person comes looking
 * for separately: whether it is asked at all and of whom, what carries the
 * code, and what happens to somebody who arrived by link — the last being the
 * one nobody can work out from the settings, which is why the tab already
 * says it out loud beside them.
 *
 * @param array<int, array<string, string>> $rows
 * @return array<int, array<string, string>>
 */
function diluxone_users_2fa_summary_rows( array $rows ): array {
	$mode    = (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
	$on      = 'off' !== $mode;
	$who     = diluxone_users_2fa_who();
	$none    = '' === $who;
	$who     = $none ? __( 'nobody: none of the roles it is meant for is ticked', 'diluxone-users' ) : $who;
	$nowhere = '' === diluxone_users_2fa_surface();
	$today   = diluxone_users_2fa_link_today();
	$tab     = diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => '2fa' ) );

	if ( 'required' === $mode ) {
		/* translators: %s: who it applies to — a list of roles, or "everybody with an account" */
		$detail = sprintf( esc_html__( 'Required of %s: they are walked through it the next time they sign in, and there is no way past.', 'diluxone-users' ), esc_html( $who ) );
	} elseif ( 'optional' === $mode ) {
		/* translators: %s: who it applies to — a list of roles, or "everybody with an account" */
		$detail = sprintf( esc_html__( 'Offered to %s, and asked of whoever turned it on from their own account.', 'diluxone-users' ), esc_html( $who ) );
	} else {
		$detail = esc_html__( 'Nobody is asked for a second step, not even whoever has one set up. What they set up is kept.', 'diluxone-users' );
	}

	// Both rows hang off the mode: with none chosen there is no second
	// step, as WordPress has it, and nothing carries a code for it.
	$rows[] = diluxone_users_summary_row(
		'diluxone_users_2fa_mode',
		array(
			'label'  => __( 'Two-step verification', 'diluxone-users' ),
			// On, and reaching nobody, is the one answer that is neither: the
			// site is asking for a code from a list of roles it never filled in.
			'state'  => $on ? ( $none || $nowhere ? 'pending' : 'active' ) : 'off',
			'why'    => $on && $nowhere ? __( 'there is nowhere to type the code', 'diluxone-users' ) : ( $on && $none ? __( 'it reaches nobody as it stands', 'diluxone-users' ) : '' ),
			'detail' => $on && $nowhere ? esc_html( diluxone_users_2fa_needs_a_surface() ) : $detail,
			'url'    => $tab,
		),
		esc_html__( 'No second step: WordPress asks for none.', 'diluxone-users' )
	);

	// From the registry and not from a list written here: what is on is what
	// the sign-in will actually offer, an add-on's method included.
	$methods = array_map(
		static fn( array $method ): string => (string) $method['label'],
		diluxone_users_2fa_methods()
	);

	$rows[] = diluxone_users_summary_row(
		'diluxone_users_2fa_mode',
		array(
			'label'  => __( 'How the code arrives', 'diluxone-users' ),
			'state'  => array() === $methods ? 'off' : ( $on ? 'active' : 'off' ),
			'why'    => array() !== $methods && ! $on ? __( 'while the second step is off', 'diluxone-users' ) : '',
			'detail' => array() === $methods
				? esc_html__( 'Nothing carries it: no method is ticked, so nobody could finish signing in.', 'diluxone-users' )
				: esc_html( implode( ', ', $methods ) ),
			'url'    => $tab,
		),
		esc_html__( 'Nothing is sent: there is no second step.', 'diluxone-users' )
	);

	$rows[] = array(
		// Not "Somebody arriving by e-mail link", which is what it said: the
		// pill beside it answers whether the second step is asked of that
		// somebody, and a title naming the arrival made the pill look like an
		// answer about the link itself.
		'label'  => __( 'The second step, on a link sign-in', 'diluxone-users' ),
		'state'  => $today['state'],
		'detail' => esc_html( $today['line'] ),
		'url'    => $tab,
	);

	return $rows;
}
add_filter( 'diluxone_users_security_summary', 'diluxone_users_2fa_summary_rows', 10 );
