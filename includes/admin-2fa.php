<?php
/**
 * The second-factor screen.
 *
 * Deliberately its own file, next to auth.php and auth-totp.php: the second
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
 * Saves it.
 *
 * @return bool False when the second step is on with no way of sending the
 *              code, in which case nothing was written.
 */
function diluxone_users_2fa_save(): bool {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$mode    = sanitize_key( wp_unslash( $_POST['diluxone_users_2fa_mode'] ?? 'optional' ) );
	$methods = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_2fa_methods'] ?? array() ) );
	$link    = sanitize_key( wp_unslash( $_POST['diluxone_users_2fa_link'] ?? 'auto' ) );
	$days    = absint( wp_unslash( $_POST['diluxone_users_2fa_remember_days'] ?? 30 ) );
	// phpcs:enable

	/*
	 * Off is the one mode where none of them is an answer: nothing is asked,
	 * so nothing has to carry a code. In the other two the step is on, and a
	 * step on with no way of sending the code is a site whose people get as
	 * far as the second screen and no further. It saved exactly that, without
	 * a word, until this stood in the way.
	 *
	 * Before anything is written, not after: a half-saved state here is a
	 * screen showing what was refused as though it had been kept.
	 */
	if ( 'off' !== $mode && ! diluxone_users_ui_needs_one( $methods, diluxone_users_2fa_needs_a_method() ) ) {
		return false;
	}

	diluxone_users_save_options(
		array_merge(
			diluxone_users_scope_posted( 'diluxone_users_2fa' ),
			array(
				'diluxone_users_2fa_mode'          => $mode,
				'diluxone_users_2fa_methods'       => $methods,
				'diluxone_users_2fa_link'          => $link,
				'diluxone_users_2fa_remember_days' => $days,
			)
		)
	);

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

	// All of them are asked for, not only the ones turned on: the checkbox of a
	// method that is off has to exist in order to turn it on.
	$all     = (array) apply_filters(
		'diluxone_users_2fa_methods',
		array(
			'email' => array(
				'label' => __( 'A code by email', 'diluxone-users' ),
				'help'  => __( 'Six digits to the address on the account. Nothing to install, which is why it is the one people actually turn on.', 'diluxone-users' ),
			),
			'totp'  => array(
				'label' => __( 'An authenticator app', 'diluxone-users' ),
				'help'  => __( 'Six digits that change every thirty seconds, from Google Authenticator, 1Password or Aegis. The stronger one: the code never travels.', 'diluxone-users' ),
			),
		)
	);
	$enabled = (array) diluxone_users_option( 'diluxone_users_2fa_methods' );
	$mode    = (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
	$today   = diluxone_users_2fa_link_today();

	diluxone_users_ui_section( __( 'When it is asked for', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_2fa_mode',
				'value'   => 'optional',
				'checked' => 'optional' === $mode,
				'title'   => __( 'Optional: whoever wants it turns it on from their account', 'diluxone-users' ),
				'help'    => __( 'Nobody is made to do anything, and whoever turned it on is asked from then on.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_2fa_mode',
				'value'   => 'required',
				'checked' => 'required' === $mode,
				'title'   => __( 'Required: everybody it applies to has to set it up', 'diluxone-users' ),
				'help'    => __( 'They are walked through it the next time they sign in, and there is no way past.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_2fa_mode',
				'value'   => 'off',
				'checked' => 'off' === $mode,
				'title'   => __( 'Off: it is not offered at all', 'diluxone-users' ),
				'help'    => __( 'Nobody is asked, not even whoever had it set up. What they set up is kept and works again the day this comes back on.', 'diluxone-users' ),
			),
		)
	);

	/*
	 * Off is the one answer that makes every question below it moot, and the
	 * rest of the screen is kept on show so it can be prepared before it is
	 * turned on. Said once, right under the answer that caused it, while that
	 * answer is the saved one.
	 */
	if ( 'off' === $mode ) {
		diluxone_users_not_now( __( 'The second step is off, so nothing below is asked of anybody. What is chosen applies the day it is turned on.', 'diluxone-users' ) );
	}

	diluxone_users_ui_field_open( __( 'To whom', 'diluxone-users' ) );
	diluxone_users_scope_control( 'diluxone_users_2fa' );
	diluxone_users_ui_field_close( __( 'Asking only the roles that can change things is the usual middle ground: the second step where the friction is worth it.', 'diluxone-users' ) );

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

	/*
	 * Asked of the browser only while the step is on, and "on" is whichever
	 * of the three above is picked at the moment of the press — not the one
	 * that was saved. Turning it off and untying both methods in one go is a
	 * form with nothing wrong in it, and the browser used to refuse it.
	 */
	diluxone_users_ui_choices(
		$cards,
		diluxone_users_2fa_needs_a_method(),
		array(
			'name' => 'diluxone_users_2fa_mode',
			'is'   => array( 'optional', 'required' ),
		)
	);

	diluxone_users_ui_section(
		__( 'Asking it of somebody who came in by link', 'diluxone-users' ),
		__( 'Whether there is a sign-in link at all is decided on Access. This is only what happens after one is followed.', 'diluxone-users' )
	);

	if ( ! diluxone_users_login_has_link() ) {
		diluxone_users_not_now(
			__( 'The e-mail link is not one of the ways in on this site, so nobody follows one. What is chosen here applies the day it is.', 'diluxone-users' ),
			diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
			__( 'Ways in →', 'diluxone-users' )
		);
	}

	diluxone_users_ui_choices(
		array(
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
		)
	);

	diluxone_users_ui_section(
		__( 'Remembering a browser', 'diluxone-users' ),
		__( 'A signed cookie, no more: it does not let anybody in, it only saves repeating the step on a browser that already passed it.', 'diluxone-users' )
	);

	diluxone_users_ui_number(
		array(
			'label'  => __( 'For how long', 'diluxone-users' ),
			'name'   => 'diluxone_users_2fa_remember_days',
			'value'  => (string) diluxone_users_option( 'diluxone_users_2fa_remember_days' ),
			'suffix' => __( 'days', 'diluxone-users' ),
			'min'    => 0,
			'help'   => __( '0 to ask every time.', 'diluxone-users' ),
		)
	);

	/*
	 * What the three answers above come to is worth saying and is not a
	 * fourth question, and in the column of settings that is what it looked
	 * like: a row with a pill in it, between two groups that are asking for
	 * something. Beside them it is what it is — the screen reading itself
	 * back — and the same goes for the two screens the code and the link
	 * come from.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $today ): void {
			diluxone_users_ui_note(
				__( 'The second step, for somebody who came in by link', 'diluxone-users' ),
				$today['line'],
				$today['state'],
				'',
				diluxone_users_2fa_link_word( $today['state'] )
			);

			diluxone_users_ui_links(
				__( 'What this leans on', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-notices', array( 'tab' => 'templates' ) ),
						'label' => __( 'E-mail notices › The e-mails', 'diluxone-users' ),
						'help'  => __( 'The subject and the words of the message that carries the six digits are written there, not here.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
						'label' => __( 'Access › Ways in', 'diluxone-users' ),
						'help'  => __( 'Whether there is a sign-in link at all — the arrival the rule above is about — is decided there.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Who the second step reaches, in words.
 *
 * The control is two settings — all, or a list of roles — and the summary has
 * to say the result in a line. "Some roles" with nothing ticked reaches
 * nobody, and it says so: that is a site that thinks it asks for a code and
 * does not.
 */
function diluxone_users_2fa_who(): string {
	if ( 'all' === diluxone_users_scope( 'diluxone_users_2fa' ) ) {
		return __( 'everybody with an account', 'diluxone-users' );
	}

	$names = wp_roles()->get_names();
	$roles = array();

	foreach ( (array) diluxone_users_option( 'diluxone_users_2fa_roles' ) as $role ) {
		$role    = (string) $role;
		$roles[] = translate_user_role( (string) ( $names[ $role ] ?? $role ) );
	}

	return array() === $roles
		? __( 'nobody: it is set to some roles and none of them is ticked', 'diluxone-users' )
		: implode( ', ', $roles );
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
	$mode  = (string) diluxone_users_option( 'diluxone_users_2fa_mode' );
	$on    = 'off' !== $mode;
	$who   = diluxone_users_2fa_who();
	$none  = 'some' === diluxone_users_scope( 'diluxone_users_2fa' ) && array() === (array) diluxone_users_option( 'diluxone_users_2fa_roles' );
	$today = diluxone_users_2fa_link_today();
	$tab   = diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => '2fa' ) );

	if ( 'required' === $mode ) {
		/* translators: %s: who it applies to — a list of roles, or "everybody with an account" */
		$detail = sprintf( esc_html__( 'Required of %s: they are walked through it the next time they sign in, and there is no way past.', 'diluxone-users' ), esc_html( $who ) );
	} elseif ( 'optional' === $mode ) {
		/* translators: %s: who it applies to — a list of roles, or "everybody with an account" */
		$detail = sprintf( esc_html__( 'Offered to %s, and asked of whoever turned it on from their own account.', 'diluxone-users' ), esc_html( $who ) );
	} else {
		$detail = esc_html__( 'Nobody is asked for a second step, not even whoever has one set up. What they set up is kept.', 'diluxone-users' );
	}

	$rows[] = array(
		'label'  => __( 'Two-step verification', 'diluxone-users' ),
		// On, and reaching nobody, is the one answer that is neither: the
		// site is asking for a code from a list of roles it never filled in.
		'state'  => $on ? ( $none ? 'pending' : 'active' ) : 'off',
		'why'    => $on && $none ? __( 'it reaches nobody as it stands', 'diluxone-users' ) : '',
		'detail' => $detail,
		'url'    => $tab,
	);

	// From the registry and not from a list written here: what is on is what
	// the sign-in will actually offer, an add-on's method included.
	$methods = array_map(
		static fn( array $method ): string => (string) $method['label'],
		diluxone_users_2fa_methods()
	);

	$rows[] = array(
		'label'  => __( 'How the code arrives', 'diluxone-users' ),
		'state'  => array() === $methods ? 'off' : ( $on ? 'active' : 'off' ),
		'why'    => array() !== $methods && ! $on ? __( 'while the second step is off', 'diluxone-users' ) : '',
		'detail' => array() === $methods
			? esc_html__( 'Nothing carries it: no method is ticked, so nobody could finish signing in.', 'diluxone-users' )
			: esc_html( implode( ', ', $methods ) ),
		'url'    => $tab,
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
