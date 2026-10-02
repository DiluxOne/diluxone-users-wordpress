<?php
/**
 * The notices this plugin sends, on its own account.
 *
 * The notifications screen has existed from the start, but until now it
 * arrived empty: whatever was offered there had to be registered by the site.
 * That is right for what belongs to the site — a broadcast, a course, a forum
 * — and wrong as a starting point: a plugin showing an empty section on a
 * clean install is asking the site to finish it.
 *
 * So the notices that belong to this plugin live here, because they are about
 * things this plugin knows and sends: who signed in to your account and what
 * changed in your security. None of them depends on another plugin, and the
 * site's own are added to these through the same filter as always.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own notices, the ones it really sends.
 *
 * They are not registered through the filter: they are the starting point,
 * and the filter is for what the site adds on top. A plugin that hooks its
 * own filter to bring its own makes its base look optional, and it takes only
 * somebody returning an empty array from elsewhere to be left with nothing.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_default_notifications(): array {
	$prefs = array();

	$prefs['diluxone_users_notify_login'] = array(
		'label'   => __( 'When somebody signs in to my account from a new device', 'diluxone-users' ),
		'help'    => __( 'The first time a browser or a phone gets in. From then on, that one is quiet.', 'diluxone-users' ),
		'default' => '1',
	);

	$prefs['diluxone_users_notify_security'] = array(
		'label'   => __( 'When something in my security changes', 'diluxone-users' ),
		'help'    => __( 'A passkey added or removed, two-step verification turned on or off, a social account linked or unlinked.', 'diluxone-users' ),
		'default' => '1',
	);

	return $prefs;
}

/**
 * Is a code by e-mail one of the second steps this site offers?
 *
 * Two settings have to agree: two-step verification has to be on at all, and
 * the e-mail has to be among its methods. Either alone sends no code.
 *
 * It sits beside the notices and not on the admin screen that first asked it,
 * because the account area asks it too: a person is only shown the code among
 * what reaches them if a code can reach them.
 */
function diluxone_users_notice_2fa_email(): bool {
	return 'off' !== (string) diluxone_users_option( 'diluxone_users_2fa_mode' )
		&& in_array( 'email', (array) diluxone_users_option( 'diluxone_users_2fa_methods' ), true );
}

/**
 * What this site sends whether anybody wants it or not.
 *
 * They are the two e-mails that are not news about the account but the way in
 * to it: somebody who presses "send me the link" and gets nothing has no way
 * in, and a second-step code that does not arrive locks out the person it was
 * meant to let through. Neither has a switch, and neither should.
 *
 * They are listed all the same, in the account area, ticked and greyed out.
 * Hiding them makes the section a half-answer to "what does this site send
 * me": the two e-mails a person actually receives most often would be the two
 * missing from the list. Showing them with the reason they cannot be turned
 * off answers the question and closes it.
 *
 * Each one is here only while the site really sends it: no e-mail link, no
 * row about the link.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_notification_musts(): array {
	$musts = array();

	if ( diluxone_users_login_has_link() ) {
		$musts['diluxone_users_notify_link'] = array(
			'label' => __( 'The link that signs me in', 'diluxone-users' ),
			'help'  => __( 'Sent the moment you ask to get in.', 'diluxone-users' ),
			'why'   => __( 'Nobody can turn it off: without it there is no way in.', 'diluxone-users' ),
		);
	}

	if ( diluxone_users_notice_2fa_email() ) {
		$musts['diluxone_users_notify_2fa'] = array(
			'label' => __( 'The code for the second step', 'diluxone-users' ),
			'help'  => __( 'Sent halfway through signing in, when this site asks you for a code.', 'diluxone-users' ),
			'why'   => __( 'Nobody can turn it off: a code that does not arrive leaves you out.', 'diluxone-users' ),
		);
	}

	return $musts;
}

/* ── Who decides ───────────────────────────────────────────────────── */

/**
 * The four things a site can decide about one notice, written as outcomes.
 *
 * Two of them leave a switch in the person's account area and only set which
 * way it starts; the other two take the switch away. "Always" exists for the
 * site that treats a security notice as part of the service, and "never" for
 * the one that has another system sending the same thing.
 *
 * @return array<string, string>
 */
function diluxone_users_notice_policies(): array {
	return array(
		'default_on'  => __( 'On by default — each person can turn it off', 'diluxone-users' ),
		'default_off' => __( 'Off by default — each person can turn it on', 'diluxone-users' ),
		'always'      => __( 'Always sent — no switch for anybody', 'diluxone-users' ),
		'never'       => __( 'Never sent', 'diluxone-users' ),
	);
}

/**
 * The rules the site wrote down, key => policy, and nothing else.
 *
 * Whatever is stored is read defensively: a rule with a word that is not one
 * of the four is dropped rather than guessed at, and a value that is not a
 * map at all counts as no rules. A broken option must not silence a security
 * notice.
 *
 * @return array<string, string>
 */
function diluxone_users_notice_rules(): array {
	$stored   = diluxone_users_option( 'diluxone_users_notice_rules' );
	$policies = diluxone_users_notice_policies();
	$rules    = array();

	foreach ( is_array( $stored ) ? $stored : array() as $key => $policy ) {
		if ( is_string( $key ) && is_string( $policy ) && isset( $policies[ $policy ] ) ) {
			$rules[ $key ] = $policy;
		}
	}

	return $rules;
}

/**
 * The site's policy for one notice.
 *
 * A notice with no rule written down behaves as it always did: whatever it
 * registered as its default decides, and the plugin's own register as on. So
 * a site that never opens the screen sees nothing change, and an add-on that
 * ships a notice off by default is still off by default.
 */
function diluxone_users_notice_policy( string $key ): string {
	$rules = diluxone_users_notice_rules();

	if ( isset( $rules[ $key ] ) ) {
		return $rules[ $key ];
	}

	$prefs = diluxone_users_notification_prefs();

	return isset( $prefs[ $key ] ) && '' === (string) ( $prefs[ $key ]['default'] ?? '' ) ? 'default_off' : 'default_on';
}

/** Does this policy leave the choice to the person? */
function diluxone_users_notice_is_choice( string $policy ): bool {
	return 'default_on' === $policy || 'default_off' === $policy;
}

/**
 * Does this person want this notice?
 *
 * The site speaks first: a notice it never sends is not sent, and one it
 * always sends goes out whatever the person chose. In between, the person's
 * own switch counts, and with nothing chosen yet the policy says which way
 * the switch starts.
 */
function diluxone_users_wants( int $user_id, string $key ): bool {
	$prefs = diluxone_users_notification_prefs();

	if ( ! isset( $prefs[ $key ] ) ) {
		return false;
	}

	$policy = diluxone_users_notice_policy( $key );

	if ( 'never' === $policy ) {
		return false;
	}

	if ( 'always' === $policy ) {
		return true;
	}

	$saved = get_user_meta( $user_id, $key, true );

	return '' === (string) $saved ? 'default_on' === $policy : (bool) $saved;
}

/**
 * Sends a notice, if the person wants it.
 *
 * It returns false when they do not want it either: the caller does not have
 * to ask twice or know how the preference is stored.
 */
function diluxone_users_notify( int $user_id, string $key, string $subject, string $body ): bool {
	if ( ! diluxone_users_wants( $user_id, $key ) ) {
		return false;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return false;
	}

	/**
	 * Filters a notice before sending it.
	 *
	 * @param array{subject: string, body: string} $mail
	 * @param int                                  $user_id
	 * @param string                               $key
	 */
	$mail = (array) apply_filters(
		'diluxone_users_notification',
		array(
			'subject' => $subject,
			'body'    => $body,
		),
		$user_id,
		$key
	);

	return wp_mail( $user->user_email, (string) $mail['subject'], (string) $mail['body'] );
}

/** The site name, without the HTML entities WordPress stores. */
function diluxone_users_site_name(): string {
	return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
}

/* ── Signing in from a new device ──────────────────────────────────── */

/**
 * How a device is recognised.
 *
 * It is the user agent, hashed. It is not a security measure — the user agent
 * lies if somebody wants it to — and it does not have to be: it is there so
 * as not to notify fifty times from the same browser. The IP is deliberately
 * left out: it changes on its own, and with it every sign-in from the same
 * phone would be "new".
 */
function diluxone_users_device_id(): string {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	return substr( hash( 'sha256', $ua ), 0, 16 );
}

/**
 * Notifies when somebody signs in from a device that had not been seen.
 *
 * It hangs off `diluxone_users_logged_in`, which every way in the plugin
 * handles goes through: the link, the password, a social network and a
 * passkey. Once per device.
 */
function diluxone_users_notify_new_device( int $user_id, string $via ): void {
	$id    = diluxone_users_device_id();
	$known = diluxone_users_meta_list( $user_id, 'diluxone_users_devices' );
	$known = array_filter( array_map( 'strval', $known ) );

	if ( in_array( $id, $known, true ) ) {
		return;
	}

	// It is recorded before sending: if the e-mail fails, the notice does not
	// end up repeating on every sign-in from the same browser.
	$known[] = $id;
	update_user_meta( $user_id, 'diluxone_users_devices', array_slice( $known, -20 ) );

	// The first time a device is seen is, for nearly everybody, the time the
	// account was created. Telling somebody they signed in themselves, while
	// they are signing in, tells them nothing.
	if ( 1 === count( $known ) ) {
		return;
	}

	$agent = diluxone_users_user_agent( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );

	$device = trim( sprintf( '%s · %s %s', $agent['device'], $agent['browser'], $agent['os'] ) );

	diluxone_users_in_their_language(
		$user_id,
		static function ( string $locale ) use ( $user_id, $device, $via ): void {
			// The wording is not written here any more: it is one of the four
			// templates a site can rewrite per language, and this only says
			// what goes in the gaps.
			$mail = diluxone_users_mail_compose(
				'new_device',
				array(
					'{device}'  => $device,
					'{via}'     => diluxone_users_via_label( $via ),
					'{when}'    => (string) wp_date( 'j M Y, H:i' ),
					'{account}' => diluxone_users_account_url( 'security' ),
					'{site}'    => diluxone_users_site_name(),
					'{name}'    => diluxone_users_mail_person( '', $user_id ),
				),
				$locale
			);

			diluxone_users_notify( $user_id, 'diluxone_users_notify_login', $mail['subject'], $mail['body'] );
		}
	);
}
add_action( 'diluxone_users_logged_in', 'diluxone_users_notify_new_device', 10, 2 );

/** How they signed in, in plain words. */
function diluxone_users_via_label( string $via ): string {
	$labels = array(
		'link'     => __( 'a link sent to your email', 'diluxone-users' ),
		'password' => __( 'your password', 'diluxone-users' ),
		'sso'      => __( 'a social account', 'diluxone-users' ),
		'passkey'  => __( 'a passkey', 'diluxone-users' ),
	);

	return $labels[ $via ] ?? $via;
}

/* ── Changes to security ───────────────────────────────────────────── */

/**
 * Notifies that something in the security changed.
 *
 * The parts of the plugin that change something call it: the passkeys, the
 * second factor and the linked networks. The value of the notice is precisely
 * that it arrives when it was NOT you: if somebody got in and added a
 * passkey, that is the moment to find out.
 *
 * What happened is handed over as a function that says it, not as a sentence
 * already said: the sentence has to be written in the language of the person
 * it goes to, and that is only known here.
 *
 * @param int                $user_id Whose security changed.
 * @param callable(): string $event   Says what happened, in the language in force when it is called.
 */
function diluxone_users_notify_security( int $user_id, callable $event ): void {
	diluxone_users_in_their_language(
		$user_id,
		static function ( string $locale ) use ( $user_id, $event ): void {
			$mail = diluxone_users_mail_compose(
				'security_changed',
				array(
					'{event}'   => (string) $event(),
					'{when}'    => (string) wp_date( 'j M Y, H:i' ),
					'{account}' => diluxone_users_account_url( 'security' ),
					'{site}'    => diluxone_users_site_name(),
					'{name}'    => diluxone_users_mail_person( '', $user_id ),
				),
				$locale
			);

			diluxone_users_notify( $user_id, 'diluxone_users_notify_security', $mail['subject'], $mail['body'] );
		}
	);
}

/**
 * Writes a notice in the language of the person it goes to.
 *
 * A notice is not always sent from that person's own request: an
 * administrator removing an authenticator app, or somebody else's browser
 * adding a passkey to a stolen session, would otherwise write it in their
 * own language. So the language is switched to the recipient's for as long as
 * the notice is being written — the plugin's words, the site's rewrite for
 * that language, the date — and put back after.
 *
 * @param int                    $user_id Who it goes to.
 * @param callable(string): void $write   Writes and sends it; given the recipient's locale.
 */
function diluxone_users_in_their_language( int $user_id, callable $write ): void {
	$switched = switch_to_user_locale( $user_id );

	try {
		$write( get_user_locale( $user_id ) );
	} finally {
		if ( $switched ) {
			restore_previous_locale();
		}
	}
}
