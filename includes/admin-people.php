<?php
/**
 * What the plugin knows about one person, where WordPress already keeps people.
 *
 * Everything here could have been a screen of its own, and that would have been
 * the wrong place: whoever needs to look at somebody's passkeys is already in
 * Users looking at that person. So it goes in the two places WordPress gives
 * for exactly this — a column in the list, and a block on the profile — and
 * nothing has to be searched for twice.
 *
 * The list column reads user meta that WordPress has already loaded in one go
 * for the whole page, so a list of twenty costs no query per row.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * What this person has, in short.
 *
 * @return array{passkeys: int, totp: bool, social: array<int, string>, handle: string, second_step: bool}
 */
function diluxone_users_person( int $user_id ): array {
	return array(
		'passkeys'    => count( diluxone_users_passkeys_here( $user_id ) ),
		'totp'        => diluxone_users_totp_ready( $user_id ),
		'social'      => diluxone_users_sso_linked( $user_id ),
		'handle'      => diluxone_users_handle( $user_id ),
		'second_step' => diluxone_users_2fa_on( $user_id ),
	);
}

/**
 * The column header in Users.
 *
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function diluxone_users_users_column( array $columns ): array {
	$columns['diluxone_users'] = __( 'Access', 'diluxone-users' );

	return $columns;
}
add_filter( 'manage_users_columns', 'diluxone_users_users_column' );

/**
 * One row of that column.
 *
 * Chips and not sentences: this is a column read at a glance down a list, and
 * what it has to answer is "is there anything here", not the detail. The
 * detail is one click away on the profile.
 */
function diluxone_users_users_column_row( string $out, string $column, int $user_id ): string {
	if ( 'diluxone_users' !== $column ) {
		return $out;
	}

	$person = diluxone_users_person( $user_id );
	$chips  = array();

	if ( $person['second_step'] ) {
		$chips[] = array( 'on', __( '2FA', 'diluxone-users' ) );
	}

	if ( $person['totp'] ) {
		$chips[] = array( 'on', __( 'App', 'diluxone-users' ) );
	}

	if ( $person['passkeys'] > 0 ) {
		$chips[] = array(
			'on',
			sprintf(
				/* translators: %d: how many passkeys */
				_n( '%d passkey', '%d passkeys', $person['passkeys'], 'diluxone-users' ),
				$person['passkeys']
			),
		);
	}

	foreach ( $person['social'] as $id ) {
		$provider = diluxone_users_sso_providers()[ $id ] ?? null;
		$chips[]  = array( 'blank', (string) ( $provider['name'] ?? $id ) );
	}

	if ( array() === $chips ) {
		// The same pill as every other answer in this column, in its off
		// colour: a class of its own here was one grey this stylesheet never
		// defined, so the sentence came out at full strength beside the pills.
		return '<span class="diluxone-users-pill diluxone-users-pill--off">'
			. esc_html__( 'Only the e-mail link', 'diluxone-users' ) . '</span>';
	}

	$html = '';

	foreach ( $chips as $chip ) {
		$html .= sprintf(
			'<span class="diluxone-users-pill diluxone-users-pill--%1$s">%2$s</span> ',
			esc_attr( $chip[0] ),
			esc_html( $chip[1] )
		);
	}

	return $html;
}
add_filter( 'manage_users_custom_column', 'diluxone_users_users_column_row', 10, 3 );

/**
 * The block on somebody's profile.
 *
 * Read-only except for the three things an administrator is ever asked to do
 * for somebody else: take a lost passkey off, unlink a social account, and
 * turn off an authenticator app that person no longer has. Nothing here can
 * add anything: only the owner of an account can add a way into it.
 *
 * It is drawn in two halves and they are not the same kind of thing. What
 * this account has is a report and is read; what can be taken off it is a
 * decision and is ticked. As one two-column table they were the same five
 * rows — a title on the left, a sentence or a tick box on the right, no way
 * to tell which of them would do something when the profile was saved.
 *
 * The ground is opened by hand because this is WordPress's screen and not the
 * plugin's: it is what the design system is scoped to, and without it the
 * cards below would be drawn with none of it.
 */
function diluxone_users_profile_block( WP_User $user ): void {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}

	$person   = diluxone_users_person( (int) $user->ID );
	$passkeys = diluxone_users_passkeys_here( (int) $user->ID );
	$sessions = diluxone_users_sessions( (int) $user->ID );
	$last     = $sessions[0] ?? null;
	$networks = array();

	foreach ( $person['social'] as $id ) {
		$networks[ $id ] = (string) ( diluxone_users_sso_providers()[ $id ]['name'] ?? $id );
	}
	?>
	<h2><?php echo esc_html( diluxone_users_plugin_name() ); ?></h2>

	<?php
	diluxone_users_ui_ground_open();
	diluxone_users_ui_aside_open();

	/*
	 * Not one row of this table is a switch. It is five facts about one
	 * account — a name they picked, a session, an app they set up, the keys
	 * and the networks on it — and "Off" beside any of them says an
	 * administrator turned something off for this person, which is not what
	 * any of them mean and not something this screen can do. So they keep the
	 * one state vocabulary and the colour that goes with it, and each says
	 * what its own absence is called.
	 *
	 * Each word carries the row it belongs to as its context, and that is not
	 * bookkeeping: "None" agrees with a masculine name, a feminine passkey and
	 * a masculine account, and three languages out of the eight would have had
	 * to pick one of the three and be wrong twice.
	 */
	diluxone_users_summary_table(
		array(
			array(
				'label'  => __( 'Public name', 'diluxone-users' ),
				'state'  => '' !== $person['handle'] ? 'active' : 'off',
				'word'   => '' !== $person['handle']
					? _x( 'Chosen', 'the public name of one account', 'diluxone-users' )
					: _x( 'None', 'the public name of one account', 'diluxone-users' ),
				'detail' => '' !== $person['handle']
					? '<code>' . esc_html( $person['handle'] ) . '</code>'
					: esc_html__( 'None chosen. The account is known by its e-mail address.', 'diluxone-users' ),
			),
			array(
				'label'  => __( 'Last seen', 'diluxone-users' ),
				// Read from the sessions that are open, so with none open there
				// is nothing to read: the site cannot tell, which is the one
				// state that means exactly that. It is not a thing switched off.
				'state'  => null !== $last ? 'active' : 'unknown',
				'detail' => null !== $last
					? sprintf(
						/* translators: 1: how long ago, 2: device and browser, 3: IP address */
						esc_html__( '%1$s ago, from %2$s (%3$s)', 'diluxone-users' ),
						esc_html( human_time_diff( (int) $last['started'] ) ),
						esc_html( trim( $last['device'] . ' · ' . $last['browser'] ) ),
						esc_html( $last['ip'] )
					)
					: esc_html__( 'No open session.', 'diluxone-users' ),
				'url'    => diluxone_users_admin_url(
					DILUXONE_USERS_REPORTS,
					array(
						'tab' => 'sessions',
						's'   => $user->user_email,
					)
				),
				'change' => __( 'Every session →', 'diluxone-users' ),
			),
			array(
				'label'  => __( 'Two-step verification', 'diluxone-users' ),
				'state'  => $person['second_step'] ? 'active' : 'off',
				'detail' => $person['totp']
					? esc_html__( 'An authenticator app is set up.', 'diluxone-users' )
					: esc_html__( 'No authenticator app.', 'diluxone-users' ),
			),
			array(
				'label'  => __( 'Passkeys', 'diluxone-users' ),
				'state'  => $person['passkeys'] > 0 ? 'active' : 'off',
				'word'   => $person['passkeys'] > 0
					? _x( 'In use', 'the passkeys on one account', 'diluxone-users' )
					: _x( 'None', 'the passkeys on one account', 'diluxone-users' ),
				'detail' => $person['passkeys'] > 0
					? esc_html(
						sprintf(
							/* translators: %d: how many passkeys */
							_n( '%d passkey on this account.', '%d passkeys on this account.', $person['passkeys'], 'diluxone-users' ),
							$person['passkeys']
						)
					)
					: esc_html__( 'None.', 'diluxone-users' ),
			),
			array(
				'label'  => __( 'Linked accounts', 'diluxone-users' ),
				'state'  => array() !== $networks ? 'active' : 'off',
				'word'   => array() !== $networks
					? _x( 'Linked', 'the social networks on one account', 'diluxone-users' )
					: _x( 'None', 'the social networks on one account', 'diluxone-users' ),
				'detail' => array() !== $networks
					? esc_html( implode( ', ', $networks ) )
					: esc_html__( 'None.', 'diluxone-users' ),
			),
		)
	);

	$diluxone_users_off = array();

	if ( $person['totp'] ) {
		$diluxone_users_off[] = array(
			'type'  => 'checkbox',
			'name'  => 'diluxone_users_forget_totp',
			'value' => '1',
			'title' => __( 'Remove the authenticator app', 'diluxone-users' ),
			'help'  => __( 'For somebody who lost the phone it lived on. They set a new one up from their own account.', 'diluxone-users' ),
		);
	}

	foreach ( $passkeys as $diluxone_users_key ) {
		$diluxone_users_off[] = array(
			'type'  => 'checkbox',
			'name'  => 'diluxone_users_forget_passkey[]',
			'value' => (string) $diluxone_users_key['id'],
			'title' => sprintf(
				/* translators: 1: the name given to the passkey, 2: date it was added */
				__( 'Remove “%1$s”, added on %2$s', 'diluxone-users' ),
				(string) ( $diluxone_users_key['label'] ?? __( 'Passkey', 'diluxone-users' ) ),
				date_i18n( (string) get_option( 'date_format' ), (int) ( $diluxone_users_key['created'] ?? 0 ) )
			),
			'help'  => __( 'The device keeps its half and it stops opening this account. They can add it again.', 'diluxone-users' ),
		);
	}

	foreach ( $networks as $diluxone_users_id => $diluxone_users_name ) {
		$diluxone_users_off[] = array(
			'type'  => 'checkbox',
			'name'  => 'diluxone_users_unlink[]',
			'value' => (string) $diluxone_users_id,
			'title' => sprintf(
				/* translators: %s: name of the social network */
				__( 'Unlink %s', 'diluxone-users' ),
				$diluxone_users_name
			),
			'help'  => __( 'Unlinking does not delete anything: that network simply stops opening this account.', 'diluxone-users' ),
		);
	}

	/*
	 * Drawn only when there is something to draw. A heading over an empty
	 * group is the box with nothing in it that the rest of this admin
	 * spent a week getting rid of.
	 */
	if ( array() !== $diluxone_users_off ) {
		diluxone_users_ui_section(
			__( 'Take a way in off this account', 'diluxone-users' ),
			__( 'Ticked here, it goes when the profile is saved.', 'diluxone-users' )
		);

		diluxone_users_ui_choices( $diluxone_users_off );
	}

	/*
	 * What this block cannot do belongs beside it and not inside the group
	 * that does the taking off: on an account with nothing to take off that
	 * group is not drawn at all, and the sentence went with it — so the one
	 * screen where somebody might reasonably look for "add a passkey for this
	 * person" was also the one that never said why there is no such thing.
	 *
	 * The ways out are the plugin's own screens, which is where the rules
	 * this account is living under are written. They are not on WordPress's
	 * own profile screen and nothing here suggests they are.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $user ): void {
			diluxone_users_ui_note(
				__( 'What this block does', 'diluxone-users' ),
				__( 'It reads what this account has, and it can take a way in off it. It cannot add one: only the owner of an account can do that, from their own account area.', 'diluxone-users' )
			);

			diluxone_users_ui_links(
				__( 'The rules this account lives under', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-login' ),
						'label' => __( 'Access', 'diluxone-users' ),
						'help'  => __( 'Which ways in the site offers at all. Nothing above can give this account one the site does not have.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-security' ),
						'label' => __( 'Security', 'diluxone-users' ),
						'help'  => __( 'Whether a second step is asked for after the door, and what a passkey has to prove.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url(
							DILUXONE_USERS_REPORTS,
							array(
								'tab' => 'sessions',
								's'   => $user->user_email,
							)
						),
						'label' => __( 'Every session this account has open', 'diluxone-users' ),
						'help'  => __( 'Where it signed in from, on what, and the button that closes them all.', 'diluxone-users' ),
					),
				)
			);
		}
	);

	diluxone_users_ui_ground_close();
}
add_action( 'edit_user_profile', 'diluxone_users_profile_block' );

/**
 * Applies what was ticked on that block.
 *
 * Nothing here is destructive beyond the way in it takes away, and every one
 * of them is something the person can add again from their own account.
 */
function diluxone_users_profile_block_save( int $user_id ): void {
	// WordPress checks the profile form's nonce before it fires this hook; it
	// is checked here again, so the save does not depend on how it was reached.
	check_admin_referer( 'update-user_' . $user_id );

	// `edit_user` and not `edit_users`: the first is asked about this account
	// and goes through `map_meta_cap()`, which is where a network, a role
	// another plugin invented, and `DISALLOW_FILE_EDIT`-style constants get
	// their say. The second is a blanket yes that says nothing about whose
	// second factor is about to be removed.
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	if ( isset( $_POST['diluxone_users_forget_totp'] ) ) {
		diluxone_users_totp_forget( $user_id );
	}

	$forget = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['diluxone_users_forget_passkey'] ?? array() ) );
	$unlink = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_unlink'] ?? array() ) );

	foreach ( $forget as $id ) {
		diluxone_users_passkey_forget( $user_id, (string) $id );
	}

	foreach ( array_intersect( $unlink, array_keys( diluxone_users_sso_providers() ) ) as $id ) {
		delete_user_meta( $user_id, 'diluxone_users_sso_' . (string) $id );
	}
}
add_action( 'edit_user_profile_update', 'diluxone_users_profile_block_save' );
