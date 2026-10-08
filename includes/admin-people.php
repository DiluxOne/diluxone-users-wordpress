<?php
/**
 * What the plugin knows about one person, where WordPress already keeps people.
 *
 * Everything here could have been a screen of its own, and that would have been
 * the wrong place: whoever needs to look at somebody's second step is already
 * in Users looking at that person. So it goes in the two places WordPress gives
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
 * Does the site offer any way in this column and this block are about?
 *
 * A second step, a social provider that works, or an add-on's way in. Until one of them
 * is turned on there is nothing of the plugin's to read on anybody, and
 * WordPress's Users list and profile stay exactly as WordPress draws them.
 */
function diluxone_users_access_features_on(): bool {
	if ( 'off' !== (string) diluxone_users_option( 'diluxone_users_2fa_mode' ) || array() !== diluxone_users_sso_available() ) {
		return true;
	}

	/**
	 * Filters whether the site offers a way in the Users list's Access column
	 * and a person's profile block are about, when the plugin's own are off.
	 *
	 * An add-on whose way in is turned on answers true (Users+ Pro's passkeys).
	 *
	 * @since 1.0.0
	 *
	 * @param bool $on Whether one is on.
	 */
	return (bool) apply_filters( 'diluxone_users_access_features_on', false );
}

/**
 * What this person has, in short.
 *
 * @return array{social: array<int, string>, handle: string, second_step: bool}
 */
function diluxone_users_person( int $user_id ): array {
	return array(
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
	if ( ! diluxone_users_access_features_on() ) {
		return $columns;
	}

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

	foreach ( $person['social'] as $id ) {
		$provider = diluxone_users_sso_providers()[ $id ] ?? null;
		$chips[]  = array( 'blank', (string) ( $provider['name'] ?? $id ) );
	}

	/**
	 * Filters the chips of one person in the Access column of Users.
	 *
	 * A chip is a pair: its colour (`on` or `blank`) and a few words. An
	 * add-on that keeps a way in on the account adds its own here, so the
	 * column answers "is there anything here" for everything the site has.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: string, 1: string}> $chips   The plugin's own.
	 * @param int                                     $user_id Whose row it is.
	 */
	$chips = array_values(
		array_filter(
			(array) apply_filters( 'diluxone_users_person_chips', $chips, $user_id ),
			static fn( $chip ): bool => is_array( $chip ) && isset( $chip[0], $chip[1] )
		)
	);

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
 * Read-only except for the things an administrator is ever asked to do for
 * somebody else: unlink a social account, turn off an authenticator app that
 * person no longer has, and whatever an add-on's way in adds to that list
 * (`diluxone_users_person_take_off`). Nothing here can add anything: only the
 * owner of an account can add a way into it.
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

	$person = diluxone_users_person( (int) $user->ID );

	// A way in this account still has — a network linked, or an add-on's (a
	// passkey) from before the site turned it off — keeps the block, so it
	// can be taken off; with none, the profile is WordPress's own.
	if ( ! diluxone_users_access_features_on() && array() === $person['social'] && array() === (array) apply_filters( 'diluxone_users_person_take_off', array(), $user ) ) {
		return;
	}

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
	 * Not one row of this table is a switch. It is facts about one account —
	 * a name they picked, a session, an app they set up, the networks on it —
	 * and "Off" beside any of them says an
	 * administrator turned something off for this person, which is not what
	 * any of them mean and not something this screen can do. So they keep the
	 * one state vocabulary and the colour that goes with it, and each says
	 * what its own absence is called.
	 *
	 * Each word carries the row it belongs to as its context, and that is not
	 * bookkeeping: "None" agrees with a masculine name, a feminine key and a
	 * masculine account, and three languages out of the eight would have had
	 * to pick one of the three and be wrong twice.
	 */
	$diluxone_users_rows = array(
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
			'detail' => $person['second_step']
				? esc_html__( 'Asked when they sign in.', 'diluxone-users' )
				: esc_html__( 'Not asked when they sign in.', 'diluxone-users' ),
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
	);

	/**
	 * Filters the facts about one account on its profile.
	 *
	 * The rows of a summary table (label, state, and optionally word,
	 * detail, url and change), in order. An add-on that keeps a way in on the
	 * account says what it has here, where it belongs among the rest.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, string>> $rows The plugin's own.
	 * @param WP_User                           $user Whose profile it is.
	 */
	diluxone_users_summary_table( (array) apply_filters( 'diluxone_users_person_rows', $diluxone_users_rows, $user ) );

	$diluxone_users_off = array();

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

	/**
	 * Filters what an administrator can take off this account.
	 *
	 * Tick boxes, as `diluxone_users_ui_choices()` draws them. An add-on adds
	 * one per thing of its own that can be taken off — with a name of its
	 * own — and does it on `diluxone_users_person_saved`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $choices The plugin's own.
	 * @param WP_User                          $user    Whose profile it is.
	 */
	$diluxone_users_off = (array) apply_filters( 'diluxone_users_person_take_off', $diluxone_users_off, $user );

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
	 * screen where somebody might reasonably look for "add a way in for this
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
						'help'  => __( 'Whether a second step is asked for after the door.', 'diluxone-users' ),
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

	$unlink = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_unlink'] ?? array() ) );

	foreach ( array_intersect( $unlink, array_keys( diluxone_users_sso_providers() ) ) as $id ) {
		delete_user_meta( $user_id, 'diluxone_users_sso_' . (string) $id );
	}

	/**
	 * Fires when an administrator saved somebody else's profile block.
	 *
	 * The profile's nonce and `edit_user` for this account are already
	 * checked. An add-on that put a tick box on `diluxone_users_person_take_off`
	 * reads it here, checks both again before it does, and acts on it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Whose profile it is.
	 */
	do_action( 'diluxone_users_person_saved', $user_id );
}
add_action( 'edit_user_profile_update', 'diluxone_users_profile_block_save' );
