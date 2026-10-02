<?php
/**
 * The passkeys screen.
 *
 * Its own file, next to auth-passkeys.php, and the pair is the whole feature:
 * the two of them together can be moved somewhere else and nothing in the
 * plugin notices — no screen loses a tab it had written down, because no
 * screen had it written down.
 *
 * Whether passkeys are offered at all is NOT decided here. That box lives on
 * Access › Ways in, beside the e-mail link and the password, because what it
 * answers is "what can somebody sign in with" and that question is asked once,
 * in one place. What is left here is how they behave once they are offered —
 * the same split as everywhere else: turning a thing on lives with the other
 * things that can be turned on, setting it up lives with the thing itself.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Its tab on the security screen. */
function diluxone_users_passkeys_panel(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_SECURITY,
		'passkeys',
		array(
			'label'    => __( 'Passkeys', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_login_passkeys',
			'save'     => 'diluxone_users_passkeys_settings_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_passkeys_panel' );

/**
 * Saves the settings.
 *
 * Not `diluxone_users_passkeys_save()`: that name was already taken by
 * auth-passkeys.php, which saves a person's passkey. Two different things,
 * and PHP noticed before anybody else did.
 *
 * `diluxone_users_passkey_enabled` is not written here on a site and must not
 * be: it is written by the screen that shows it, Access › Ways in. Saving an
 * option from a form that does not carry it is how a setting gets turned off
 * by somebody who only came to change something else. In Network Admin there
 * is no Access screen — whether passkeys are offered is the network's, like
 * the rest of them — so there it is this form that carries it.
 */
function diluxone_users_passkeys_settings_save(): void {
	check_admin_referer( 'diluxone_users_panel_' . DILUXONE_USERS_SECURITY, 'diluxone_users_panel_nonce' );
	diluxone_users_panel_allowed();

	$saved = array(
		'diluxone_users_passkey_where'  => sanitize_key( wp_unslash( $_POST['diluxone_users_passkey_where'] ?? 'any' ) ),
		'diluxone_users_passkey_verify' => isset( $_POST['diluxone_users_passkey_verify'] ) ? 1 : 0,
	);

	if ( 'network' === diluxone_users_admin_context() ) {
		$saved['diluxone_users_passkey_enabled'] = isset( $_POST['diluxone_users_passkey_enabled'] ) ? 1 : 0;
	}

	diluxone_users_save_options( $saved );
}

/** Passkeys: whether they are offered, which ones are accepted and what is required. */
function diluxone_users_screen_login_passkeys(): void {
	diluxone_users_ui_aside_open();

	$on    = (bool) diluxone_users_option( 'diluxone_users_passkey_enabled' );
	$where = (string) diluxone_users_option( 'diluxone_users_passkey_where' );
	$ways  = diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) );

	diluxone_users_intro( __( 'A passkey is a private key that lives on the person’s device or keychain and never leaves it. There is nothing on this side worth stealing, nothing to reuse on another site, and it cannot be phished: the browser refuses to sign for a domain that is not the one it was made for.', 'diluxone-users' ) );

	// In Network Admin there is no Access screen to switch them on from: on a
	// network, whether passkeys are offered is decided here, for every site.
	$network = 'network' === diluxone_users_admin_context();

	if ( $network ) {
		diluxone_users_ui_section( __( 'Offered on every site', 'diluxone-users' ) );

		diluxone_users_ui_choices(
			array(
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_passkey_enabled',
					'value'   => '1',
					'checked' => $on,
					'title'   => __( 'People can add a passkey and sign in with it', 'diluxone-users' ),
					'help'    => __( 'A key that stays on the person’s device or keychain: nothing to type, nothing to phish, and it counts as both steps at once.', 'diluxone-users' ),
				),
			)
		);
	}

	diluxone_users_ui_section( __( 'Which passkeys are accepted', 'diluxone-users' ) );

	// Dimmed rather than hidden or disabled: what is chosen today is what
	// applies the day somebody turns passkeys on, and a control that is not
	// submitted is a control that saves nothing.
	if ( ! $on && ! $network ) {
		diluxone_users_not_now(
			__( 'Passkeys are not one of the ways in yet, so nothing below is in use. What is chosen here applies the day they are.', 'diluxone-users' ),
			$ways,
			__( 'Turn them on → Access › Ways in', 'diluxone-users' )
		);
	}

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_passkey_where',
				'value'   => 'any',
				'checked' => 'device' !== $where,
				'title'   => __( 'Any: the device being used, a USB key, or another phone', 'diluxone-users' ),
				'help'    => __( 'Somebody at a laptop can register using their phone, and a hardware key works. It is what most people expect.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_passkey_where',
				'value'   => 'device',
				'checked' => 'device' === $where,
				'title'   => __( 'Only the device being used', 'diluxone-users' ),
				'help'    => __( 'Everything stays on the machine in front of them. Some organisations require it; everybody else finds it annoying.', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_section( __( 'Proof that it is them holding the device', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_passkey_verify',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_passkey_verify' ),
				'title'   => __( 'Require the fingerprint, the face or the PIN', 'diluxone-users' ),
				'help'    => __( 'This is what makes a passkey count as two things at once: something they have and something they are. Unticked, whoever is holding an unlocked device gets in, and the passkey is worth one factor.', 'diluxone-users' ),
			),
		)
	);

	/*
	 * Everything this tab says and does not ask goes beside it. The state is
	 * read from another screen, the domain caveat is a fact about the site
	 * rather than a setting, and the way to the switch is a way out of here:
	 * across the top of the settings all three read as things to deal with
	 * before starting, and they were also a strip of text squeezed into a
	 * middle column with the rest of the window empty to the right of it.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $on, $ways, $network ): void {
			diluxone_users_ui_note(
				$network ? __( 'Offered on every site', 'diluxone-users' ) : __( 'Offered on this site', 'diluxone-users' ),
				$on
					? sprintf(
						/* translators: %s: the domain the passkeys end up tied to */
						esc_html__( 'People can add passkeys and sign in with them. They are tied to %s: if the site moves to another domain they stop working and have to be added again, which is exactly what makes them unphishable.', 'diluxone-users' ),
						'<code>' . esc_html( diluxone_users_passkey_rp_id() ) . '</code>'
					)
					: esc_html__( 'Nobody can add one or sign in with one, whatever is chosen beside this.', 'diluxone-users' ),
				$on ? 'active' : 'off'
			);

			// The one thing here that stops everything on this tab from ever
			// happening, so it keeps the shape every screen uses to say so.
			if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
				diluxone_users_ui_notice(
					esc_html__( 'This site is not on HTTPS. Browsers refuse passkeys until it is, whatever is chosen here.', 'diluxone-users' ),
					'warning'
				);
			}

			// In Network Admin it is decided on this very tab.
			if ( $network ) {
				return;
			}

			diluxone_users_ui_links(
				__( 'Decided elsewhere', 'diluxone-users' ),
				array(
					array(
						'url'   => $ways,
						'label' => __( 'Access › Ways in', 'diluxone-users' ),
						'help'  => __( 'Whether passkeys are offered at all is a question about doors, and it is answered there, beside the password and the e-mail link.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * How many accounts on this site have a passkey.
 *
 * Accounts and not keys: one indexed query over the meta key that holds a
 * person's list, which is a number that is exactly right. Counting the keys
 * themselves would mean opening every list — or trusting the lookup index,
 * which does not cover the ones registered before it existed — and a summary
 * that rounds is a summary nobody trusts twice.
 *
 * Not cached, like everything else on a summary: the screen is open because
 * somebody wants to know how the site stands now.
 */
function diluxone_users_passkeys_count(): int {
	global $wpdb;

	// Of this site's people (whoever has a role here), as every number on
	// these screens: on a network the meta is the network's.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- user meta has no API that counts, and the number has to be the one from now.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT m.user_id) FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s WHERE m.meta_key = %s AND m.meta_value != ''",
			$wpdb->get_blog_prefix() . 'capabilities',
			'diluxone_users_passkeys'
		)
	);
}

/**
 * Passkeys, as four lines of the security summary.
 *
 * Whether they are offered is decided on Access and the first line says so by
 * pointing there: a summary that sends somebody to the tab beside it for a
 * switch that is not on it is a summary that wastes a trip.
 *
 * The two lines in the middle describe settings that are chosen here and only
 * bite while passkeys are offered, so with them off the line keeps saying
 * what is chosen — it is what applies the day they come on — and the pill
 * says it is not in force.
 *
 * @param array<int, array<string, string>> $rows
 * @return array<int, array<string, string>>
 */
function diluxone_users_passkeys_summary_rows( array $rows ): array {
	$on    = (bool) diluxone_users_option( 'diluxone_users_passkey_enabled' );
	$where = (string) diluxone_users_option( 'diluxone_users_passkey_where' );
	$count = diluxone_users_passkeys_count();
	$tab   = diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => 'passkeys' ) );
	$ways  = diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) );

	// Browsers refuse the whole thing without HTTPS, so a site that has
	// switched them on and is not on it has them on in name only.
	$https = is_ssl() || 'local' === wp_get_environment_type();

	$rows[] = array(
		'label'  => __( 'Passkeys', 'diluxone-users' ),
		'state'  => $on ? ( $https ? 'active' : 'pending' ) : 'off',
		'why'    => $on && ! $https ? __( 'browsers refuse them until this site is on HTTPS', 'diluxone-users' ) : '',
		'detail' => $on
			? sprintf(
				/* translators: %s: the domain the passkeys are tied to */
				esc_html__( 'Offered, and tied to %s: a passkey made here works here and nowhere else.', 'diluxone-users' ),
				'<code>' . esc_html( diluxone_users_passkey_rp_id() ) . '</code>'
			)
			: esc_html__( 'Nobody can add one or sign in with one.', 'diluxone-users' ),
		'url'    => $ways,
		'change' => __( 'Access › Ways in →', 'diluxone-users' ),
	);

	// In Network Admin they are switched on on their own tab.
	if ( 'network' === diluxone_users_admin_context() ) {
		$rows[ array_key_last( $rows ) ]['url'] = $tab;
		unset( $rows[ array_key_last( $rows ) ]['change'] );
	}

	$rows[] = array(
		'label'  => __( 'Which passkeys are accepted', 'diluxone-users' ),
		'state'  => $on ? 'active' : 'off',
		'why'    => $on ? '' : __( 'while passkeys are not offered', 'diluxone-users' ),
		'detail' => 'device' === $where
			? esc_html__( 'Only the device in front of them: no phone, no hardware key.', 'diluxone-users' )
			: esc_html__( 'Any: the device being used, a USB key, or another phone.', 'diluxone-users' ),
		'url'    => $tab,
	);

	$verify = (bool) diluxone_users_option( 'diluxone_users_passkey_verify' );

	$rows[] = array(
		'label'  => __( 'The fingerprint, the face or the PIN', 'diluxone-users' ),
		'state'  => $on && $verify ? 'active' : 'off',
		'why'    => $on || ! $verify ? '' : __( 'while passkeys are not offered', 'diluxone-users' ),
		'detail' => $verify
			? esc_html__( 'Required, which is what makes a passkey count as two things at once.', 'diluxone-users' )
			: esc_html__( 'Not required: whoever holds an unlocked device gets in, and the passkey is worth one factor.', 'diluxone-users' ),
		'url'    => $tab,
	);

	$rows[] = array(
		'label'  => __( 'Registered on this site', 'diluxone-users' ),
		// A key that was registered and cannot be used is not an active
		// anything, however many of them there are: with passkeys off the
		// count is history, and the line below says so.
		'state'  => $on ? ( $count > 0 ? 'active' : 'pending' ) : 'off',
		'why'    => 0 === $count && $on ? __( 'nobody has added one yet', 'diluxone-users' ) : '',
		'detail' => sprintf(
			/* translators: %s: how many accounts have at least one passkey */
			esc_html( _n( '%s account has one.', '%s accounts have one.', $count, 'diluxone-users' ) ),
			esc_html( number_format_i18n( $count ) )
		) . ( $count > 0 && ! $on ? ' ' . esc_html__( 'None of them opens anything while passkeys are off.', 'diluxone-users' ) : '' ),
		'url'    => diluxone_users_admin_url( 'diluxone-users', array( 'tab' => 'usage' ) ),
		'change' => __( 'Overview › Usage →', 'diluxone-users' ),
	);

	return $rows;
}
add_filter( 'diluxone_users_security_summary', 'diluxone_users_passkeys_summary_rows', 20 );
