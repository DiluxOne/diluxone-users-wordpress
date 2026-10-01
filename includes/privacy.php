<?php
/**
 * Everything else the plugin knows about a person, under WordPress's own rules.
 *
 * `log-privacy.php` answers for the activity log. This file answers for the
 * rest, and the rest is most of it: the answers to the fields the site asks
 * for, the public name, the avatar, the social accounts that are linked, the
 * passkeys, whether there is a second step and which browsers have been seen.
 *
 * It matters because of what the two tools in Tools → Export/Erase Personal
 * Data look like when this is missing. They report success. A site owner
 * answers a legal request saying the data is gone, and the person's document
 * number, phone number, linked Google account and passkeys are all still in
 * `wp_usermeta` — invisible, because core's own exporter walks the user row
 * and not the meta a plugin invented. The readme promised the opposite, which
 * made the gap a claim rather than an omission.
 *
 * What is exported is what the person is entitled to see. What is NOT
 * exported is anything that is a credential rather than a fact about them:
 * the TOTP secret, the backup-code hashes and a passkey's public key say
 * nothing about a person and would be a copy of the keys to the account,
 * travelling by e-mail in a zip. The erasers remove them all the same.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the account's own data to what WordPress exports.
 *
 * @param array<string, array<string, mixed>> $exporters
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_privacy_exporter( array $exporters ): array {
	$exporters['diluxone-users-account'] = array(
		'exporter_friendly_name' => __( 'Account details and sign-in methods', 'diluxone-users' ),
		'callback'               => 'diluxone_users_privacy_export',
	);

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'diluxone_users_privacy_exporter' );

/**
 * Everything at once, in one page.
 *
 * Unlike the log, this does not grow: it is one row per field and one per
 * passkey, for one person. There is nothing here that a site with a hundred
 * thousand of anything could turn into a timeout.
 *
 * @return array{data: array<int, array<string, mixed>>, done: bool}
 */
function diluxone_users_privacy_export( string $email, int $page = 1 ): array {
	$user = get_user_by( 'email', $email );

	if ( ! $user instanceof WP_User ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$user_id = (int) $user->ID;
	$items   = array();
	$data    = diluxone_users_privacy_details( $user_id );

	if ( array() !== $data ) {
		$items[] = array(
			'group_id'    => 'diluxone-users-details',
			'group_label' => __( 'Your details', 'diluxone-users' ),
			'item_id'     => 'diluxone-users-details',
			'data'        => $data,
		);
	}

	$access = diluxone_users_privacy_access( $user_id );

	if ( array() !== $access ) {
		$items[] = array(
			'group_id'    => 'diluxone-users-access',
			'group_label' => __( 'How you sign in', 'diluxone-users' ),
			'item_id'     => 'diluxone-users-access',
			'data'        => $access,
		);
	}

	foreach ( diluxone_users_passkeys( $user_id ) as $index => $key ) {
		$items[] = array(
			'group_id'    => 'diluxone-users-passkeys',
			'group_label' => __( 'Passkeys', 'diluxone-users' ),
			'item_id'     => 'diluxone-users-passkey-' . $index,
			'data'        => array(
				array(
					'name'  => __( 'Name', 'diluxone-users' ),
					'value' => (string) ( $key['label'] ?? '' ),
				),
				array(
					'name'  => __( 'Added', 'diluxone-users' ),
					'value' => diluxone_users_privacy_when( (int) ( $key['created'] ?? 0 ) ),
				),
				array(
					'name'  => __( 'Last used', 'diluxone-users' ),
					'value' => diluxone_users_privacy_when( (int) ( $key['used'] ?? 0 ) ),
				),
			),
		);
	}

	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * A timestamp as somebody reads one, or a dash.
 *
 * The site's own format and the site's own zone: this is read by a person and
 * not by a machine, and a Unix number in an export is an answer that needs
 * answering again.
 */
function diluxone_users_privacy_when( int $stamp ): string {
	if ( $stamp <= 0 ) {
		return '—';
	}

	return (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $stamp );
}

/**
 * The answers to the fields the site asks for, plus the name and the picture.
 *
 * `first_name` and `last_name` are left out on purpose: they are WordPress's
 * own and core's exporter already carries them. Two copies of the same answer
 * in one export is a report that looks wrong even when it is right.
 *
 * @return array<int, array<string, string>>
 */
function diluxone_users_privacy_details( int $user_id ): array {
	$rows = array();

	foreach ( diluxone_users_fields( '', false ) as $field ) {
		$key = (string) $field['key'];

		if ( diluxone_users_field_is_native( $key ) ) {
			continue;
		}

		$value = diluxone_users_value( $user_id, $key );

		if ( '' === $value ) {
			continue;
		}

		$rows[] = array(
			'name'  => (string) $field['label'],
			'value' => $value,
		);
	}

	$handle = (string) get_user_meta( $user_id, 'diluxone_users_handle', true );

	if ( '' !== $handle ) {
		$rows[] = array(
			'name'  => __( 'Public name', 'diluxone-users' ),
			'value' => $handle,
		);
	}

	$avatar = diluxone_users_avatar_id( $user_id ) > 0
		? (string) diluxone_users_avatar_on_its_site(
			$user_id,
			static fn(): string => (string) wp_get_attachment_url( diluxone_users_avatar_id( $user_id ) )
		)
		: '';

	if ( '' !== $avatar ) {
		$rows[] = array(
			'name'  => __( 'Profile picture', 'diluxone-users' ),
			'value' => $avatar,
		);
	}

	foreach ( diluxone_users_notification_choices() as $key => $pref ) {
		$stored = get_user_meta( $user_id, (string) $key, true );

		if ( '' === $stored ) {
			continue;
		}

		$rows[] = array(
			'name'  => (string) $pref['label'],
			'value' => '1' === (string) $stored ? __( 'Yes', 'diluxone-users' ) : __( 'No', 'diluxone-users' ),
		);
	}

	return $rows;
}

/**
 * The ways in that are set up, named and never quoted.
 *
 * The social account rows say which network, not the identifier it gave: that
 * number is the provider's and means nothing outside it. What the person
 * wants to know is which networks can open their account.
 *
 * @return array<int, array<string, string>>
 */
function diluxone_users_privacy_access( int $user_id ): array {
	$rows      = array();
	$providers = diluxone_users_sso_providers();

	foreach ( diluxone_users_sso_linked( $user_id ) as $id ) {
		$rows[] = array(
			'name'  => __( 'Linked account', 'diluxone-users' ),
			'value' => (string) ( $providers[ $id ]['name'] ?? $id ),
		);
	}

	$rows[] = array(
		'name'  => __( 'Two-step verification', 'diluxone-users' ),
		'value' => get_user_meta( $user_id, 'diluxone_users_2fa_on', true )
			? __( 'On', 'diluxone-users' )
			: __( 'Off', 'diluxone-users' ),
	);

	if ( get_user_meta( $user_id, 'diluxone_users_totp', true ) ) {
		$rows[] = array(
			'name'  => __( 'Authenticator app', 'diluxone-users' ),
			'value' => __( 'Set up', 'diluxone-users' ),
		);
	}

	// On a network: the sites an administrator took them off, which the
	// plugin keeps so that nothing adds them back on its own.
	$removed = array();

	foreach ( diluxone_users_membership_removed( $user_id ) as $site_id ) {
		$site      = is_multisite() ? get_site( $site_id ) : null;
		$removed[] = $site instanceof WP_Site ? (string) $site->blogname : '#' . $site_id;
	}

	if ( array() !== $removed ) {
		$rows[] = array(
			'name'  => __( 'Sites an administrator removed you from', 'diluxone-users' ),
			'value' => implode( ', ', $removed ),
		);
	}

	$devices = diluxone_users_meta_list( $user_id, 'diluxone_users_devices' );
	$devices = array_filter( array_map( 'strval', $devices ) );

	if ( array() !== $devices ) {
		$rows[] = array(
			'name'  => __( 'Devices recognised', 'diluxone-users' ),
			// The count and not the list. Each entry is a hash of a browser
			// string, kept so that "a new device signed in" is only said
			// once; handing somebody twenty hashes tells them nothing and
			// tells whoever else opens the zip a little too much.
			'value' => (string) count( $devices ),
		);
	}

	return $rows;
}

/**
 * Adds the account's own data to what WordPress erases.
 *
 * @param array<string, array<string, mixed>> $erasers
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_privacy_eraser( array $erasers ): array {
	$erasers['diluxone-users-account'] = array(
		'eraser_friendly_name' => __( 'Account details and sign-in methods', 'diluxone-users' ),
		'callback'             => 'diluxone_users_privacy_erase',
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'diluxone_users_privacy_eraser' );

/**
 * Removes it, credentials included.
 *
 * The second factor and the passkeys go with the rest. It reads like the one
 * thing worth keeping — it protects the account — but an erasure request is
 * somebody saying the account should not hold anything of theirs, and a TOTP
 * secret derived from nothing but them is theirs. The account is left with
 * whatever WordPress leaves it with.
 *
 * The avatar attachment is deleted rather than unlinked: it is a photograph
 * of a person sitting in the media library, and a row pointing at it is not
 * what makes it personal data.
 *
 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
 */
function diluxone_users_privacy_erase( string $email, int $page = 1 ): array {
	$user = get_user_by( 'email', $email );

	if ( ! $user instanceof WP_User ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$user_id = (int) $user->ID;
	$removed = false;

	// On the site that holds it: see diluxone_users_avatar_site().
	if ( diluxone_users_avatar_id( $user_id ) > 0 ) {
		diluxone_users_avatar_delete( $user_id );
		$removed = true;
	}

	foreach ( diluxone_users_privacy_keys( $user_id ) as $key ) {
		if ( '' === (string) get_user_meta( $user_id, $key, true ) ) {
			continue;
		}

		delete_user_meta( $user_id, $key );
		$removed = true;
	}

	// Through the saver and not by hand: it is what keeps the credential-id
	// index in step with the list, and an index row left behind is a row that
	// still says whose passkey a credential is.
	diluxone_users_passkeys_save( $user_id, array() );

	// The sites an administrator removed them from stay: it is the one thing
	// that keeps the plugin from adding them back, and the account may outlive
	// the erasure. The export lists them; the site owner is told why they stay.
	$kept = array() !== diluxone_users_membership_removed( $user_id );

	return array(
		'items_removed'  => $removed,
		'items_retained' => $kept,
		'messages'       => $kept ? array( __( 'The list of sites an administrator removed this person from was kept: it is what keeps them from being added back to those sites.', 'diluxone-users' ) ) : array(),
		'done'           => true,
	);
}

/**
 * Every meta key this plugin writes about one person.
 *
 * Built rather than listed where it can be — the site's own fields, the
 * providers, the notification switches — because a list is what goes out of
 * date the day a field is added, silently, in the one function whose job is
 * to leave nothing behind.
 *
 * @return array<int, string>
 */
function diluxone_users_privacy_keys( int $user_id ): array {
	$keys = array(
		'diluxone_users_handle',
		'diluxone_users_handle_changed',
		'diluxone_users_avatar',
		'diluxone_users_avatar_site',
		'diluxone_users_devices',
		'diluxone_users_2fa_on',
		'diluxone_users_2fa_epoch',
		'diluxone_users_2fa_email',
		'diluxone_users_2fa_pending',
		'diluxone_users_2fa_fails',
		'diluxone_users_2fa_lock',
		'diluxone_users_2fa_lock_at',
		'diluxone_users_totp',
		'diluxone_users_totp_pending',
		'diluxone_users_totp_step',
		'diluxone_users_backup_codes',
		'_diluxone_users_link_hash',
		'_diluxone_users_link_expires',
	);

	foreach ( diluxone_users_fields( '', false ) as $field ) {
		if ( ! diluxone_users_field_is_native( (string) $field['key'] ) ) {
			$keys[] = (string) $field['key'];
			$keys[] = 'diluxone_users_edits_' . (string) $field['key'];
		}
	}

	foreach ( array_keys( diluxone_users_sso_providers() ) as $id ) {
		$keys[] = 'diluxone_users_sso_' . $id;
	}

	foreach ( array_keys( diluxone_users_notification_prefs() ) as $key ) {
		$keys[] = (string) $key;
	}

	return array_values( array_unique( $keys ) );
}

/* ── The suggested text for the site's own policy ──────────────────── */

/**
 * What this plugin suggests a site says in its privacy policy.
 *
 * WordPress collects these suggestions on the Privacy Settings screen and
 * hands them to whoever writes the page. It is not the policy and it does not
 * become one by being here — but a plugin that stores addresses and says
 * nothing leaves the person writing that page to find out by reading the
 * database.
 */
function diluxone_users_privacy_policy(): void {
	$text = '<p>' . __( 'This site uses DiluxOne Users+ to hold accounts and sign people in.', 'diluxone-users' ) . '</p>'
		. '<p><strong>' . __( 'What is stored in your profile', 'diluxone-users' ) . '</strong> — '
		. __( 'the answers to the fields this site asks for, your public name, your profile picture, which social accounts you have linked, your passkeys, whether two-step verification is on, and a hash of each browser you have signed in from so that a new one can be announced once.', 'diluxone-users' ) . '</p>'
		. '<p><strong>' . __( 'What is stored in the activity log', 'diluxone-users' ) . '</strong> — '
		. __( 'for each recorded event: the date, the account, your IP address and your browser’s user-agent string. When a sign-in is refused, what was typed in the username box is kept with it, because that is how an attempt to guess an account is recognised. Which events are recorded, and for how many days, are settings of this site.', 'diluxone-users' ) . '</p>'
		. '<p><strong>' . __( 'Cookies', 'diluxone-users' ) . '</strong> — '
		. __( 'while you sign in, short-lived cookies hold the progress of the attempt: a social sign-in, a password reset, the address you asked a link for, the request you are signing in to confirm. If you ask not to be asked for the second step again on a browser, a cookie remembers that browser for the number of days this site sets. And a cookie remembers which way of signing in you used last, so the sign-in page opens on it.', 'diluxone-users' ) . '</p>'
		. '<p><strong>' . __( 'Deleting your account', 'diluxone-users' ) . '</strong> — '
		. __( 'you can ask for it from your account; once you confirm it by e-mail and the site carries it out, your data is erased and the account deleted, or, if you published something here or belong to another site of the network, left with no name, e-mail or password so what you wrote stays without your name.', 'diluxone-users' ) . '</p>'
		. '<p><strong>' . __( 'Where it goes', 'diluxone-users' ) . '</strong> — '
		. __( 'the plugin sends nothing anywhere on its own. If this site offers social sign-in, the provider you choose receives what it needs to identify you, and only when you use it; the account id it answers with is kept in your profile so it can recognise you next time. If your profile has no picture of its own and this site shows Gravatar pictures, your browser asks Gravatar (gravatar.com) for one using a hash of your e-mail address.', 'diluxone-users' ) . '</p>';

	wp_add_privacy_policy_content( diluxone_users_plugin_name(), wp_kses_post( wpautop( $text ) ) );
}
add_action( 'admin_init', 'diluxone_users_privacy_policy' );
