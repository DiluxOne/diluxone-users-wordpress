<?php
/**
 * The words of every e-mail this plugin sends, and where a site rewrites them.
 *
 * Until now each message was written where it was sent: the sign-in link in
 * login.php, the second-step code in auth-email.php, the two notices in
 * notify.php. That is fine for sending and hopeless for changing — the one
 * message a site could rewrite was the sign-in link, it had a tab of its own,
 * and the other three could only be got at from code through a filter.
 *
 * So the texts live here, all four in the same shape, and the sending keeps
 * happening where it happened: each message is composed through
 * `diluxone_users_mail_compose()` and the filters that were already on the way
 * out are still applied afterwards, so nothing that hooked them breaks.
 *
 * Three rules make the thing worth having.
 *
 * The first is that the plugin's own text is the starting point, in the
 * language the site runs in. It is not copied into the database on install and
 * it is not a blank box waiting to be filled: it is what `__()` returns, so
 * changing the site's language changes every e-mail without anybody editing
 * anything, and an update of the plugin that improves a sentence improves it
 * on every site that did not rewrite it.
 *
 * The second is that a rewrite belongs to a language and not to the site. A
 * site running in two languages does not send the same body to both, and a
 * store keyed by nothing but the message would force it to. So the key is the
 * locale, and a language nobody touched keeps using what the plugin ships.
 *
 * The third is that saving the plugin's own text unchanged is not a rewrite.
 * Whoever opens a message, reads it and presses save has said nothing, and
 * recording it as a rewrite would quietly freeze that site on today's wording
 * for ever. What is compared is what is in the boxes against what the plugin
 * would have said; equal means there is nothing to store.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the rewritten texts are kept.
 *
 * Not one of `diluxone_users_option_defaults()`: the value is a map of
 * locales, and what saves those settings sanitises every key with
 * `sanitize_key()`, which lowercases — `es_AR` would come back out as `es_ar`
 * and never match the locale it was written for. It is read and written here,
 * with the sanitising a map of locales actually needs.
 */
const DILUXONE_USERS_MAIL_TEMPLATES = 'diluxone_users_mail_templates';

/**
 * Every message, what it is called, and what can be dropped into it.
 *
 * `shipped` is a callable and not a pair of strings because it has to be able
 * to run under a language that is not the one loaded: the screen shows the
 * plugin's own words for whichever language is being written, which means
 * calling `__()` after switching. A closure is called then; a string was
 * translated when the array was built.
 *
 * `required` is the placeholder a message cannot be sent without. A sign-in
 * e-mail with no link is not a shorter e-mail, it is a dead end, so a rewrite
 * that lost it is refused when it is saved and ignored when it is sent.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_mail_templates(): array {
	$site = __( 'the name of this site', 'diluxone-users' );
	$who  = __( 'the name of the person it goes to, or what comes before the at sign when they have not written one', 'diluxone-users' );
	$when = __( 'the date and time it happened, in the site’s own format', 'diluxone-users' );
	$area = __( 'the address of their account area, on the security section', 'diluxone-users' );

	$templates = array();

	$templates['login_link'] = array(
		'label'    => __( 'The sign-in link', 'diluxone-users' ),
		'help'     => __( 'Sent the moment somebody asks to get in. It is the way into the site, so it goes out whatever anybody chose.', 'diluxone-users' ),
		'vars'     => array(
			'{link}'    => __( 'the address that signs them in — the e-mail is useless without it', 'diluxone-users' ),
			'{minutes}' => __( 'how many minutes the link is good for', 'diluxone-users' ),
			'{site}'    => $site,
			'{name}'    => $who,
		),
		'required' => array( '{link}' ),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Your sign-in link for %s', 'diluxone-users' ),
					'{site}'
				),
				'body'    => __(
					"Click here to sign in:\n\n{link}\n\nThe link expires in {minutes} minutes and works once.\n\nIf you did not ask for it, ignore this message: nobody can get into your account without it.",
					'diluxone-users'
				),
			);
		},
	);

	$templates['second_step'] = array(
		'label'    => __( 'The second-step code', 'diluxone-users' ),
		'help'     => __( 'Sent halfway through signing in, to somebody who is waiting for it. It has no switch either: a code that does not arrive locks them out.', 'diluxone-users' ),
		'vars'     => array(
			'{code}'    => __( 'the six digits they have to type — the e-mail is useless without them', 'diluxone-users' ),
			'{minutes}' => __( 'how many minutes the code lasts', 'diluxone-users' ),
			'{site}'    => $site,
			'{name}'    => $who,
		),
		'required' => array( '{code}' ),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Your code for %s', 'diluxone-users' ),
					'{site}'
				),
				'body'    => sprintf(
					/* translators: 1: the code, 2: how many minutes it lasts */
					__( "Your sign-in code is:\n\n%1\$s\n\nIt is good for %2\$d minutes. If you did not ask for it, ignore this message: without the code nobody gets in.", 'diluxone-users' ),
					'{code}',
					(int) ( DILUXONE_USERS_2FA_EMAIL_TTL / MINUTE_IN_SECONDS )
				),
			);
		},
	);

	$templates['new_device'] = array(
		'label'    => __( 'A sign-in from a new device', 'diluxone-users' ),
		'help'     => __( 'Sent the first time a browser or a phone gets into somebody’s account. Whether it goes out at all is decided on the Notices tab.', 'diluxone-users' ),
		'vars'     => array(
			'{device}'  => __( 'the device, the browser and the system, in one line', 'diluxone-users' ),
			'{via}'     => __( 'how they got in: a link, a password, a social account, a passkey', 'diluxone-users' ),
			'{when}'    => $when,
			'{account}' => $area,
			'{site}'    => $site,
			'{name}'    => $who,
		),
		'required' => array(),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'New sign-in to your account on %s', 'diluxone-users' ),
					'{site}'
				),
				'body'    => sprintf(
					/* translators: 1: device and browser, 2: date and time, 3: how they signed in, 4: address of the account area */
					__( "Somebody just signed in to your account.\n\n%1\$s\n%2\$s\nWay in: %3\$s\n\nIf it was you, there is nothing to do. If it was not, close that session and review your security here:\n%4\$s", 'diluxone-users' ),
					'{device}',
					'{when}',
					'{via}',
					'{account}'
				),
			);
		},
	);

	$templates['security_changed'] = array(
		'label'    => __( 'A change in their security', 'diluxone-users' ),
		'help'     => __( 'Sent when a passkey, the second step or a linked social account changes. Whether it goes out at all is decided on the Notices tab.', 'diluxone-users' ),
		'vars'     => array(
			'{event}'   => __( 'what changed, in one line', 'diluxone-users' ),
			'{when}'    => $when,
			'{account}' => $area,
			'{site}'    => $site,
			'{name}'    => $who,
		),
		'required' => array(),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Your security on %s changed', 'diluxone-users' ),
					'{site}'
				),
				'body'    => sprintf(
					/* translators: 1: what changed, 2: date and time, 3: address of the account area */
					__( "This changed in your account:\n\n%1\$s\n%2\$s\n\nIf it was you, there is nothing to do. If it was not, review your security here:\n%3\$s", 'diluxone-users' ),
					'{event}',
					'{when}',
					'{account}'
				),
			);
		},
	);

	/**
	 * Filters the e-mails a site can rewrite.
	 *
	 * An add-on that sends its own mail registers it here and gets the screen,
	 * the per-language rewriting and the way back to its own text for nothing.
	 * Each entry: label, help, vars (placeholder => what it is), required (the
	 * placeholders it cannot be sent without) and shipped (a callable giving
	 * back subject and body, called under the language being written).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $templates
	 */
	return (array) apply_filters( 'diluxone_users_mail_templates', $templates );
}

/**
 * A locale as it may be used for a key.
 *
 * WordPress locales are `es_AR`, `pt_BR`, `de_DE_formal` or a bare `ca`, so
 * `sanitize_key()` is the wrong tool — it lowercases, and the key would stop
 * matching the locale it was written for. Anything that is not shaped like a
 * locale is answered with the one the plugin is written in, which is the only
 * language guaranteed to have text.
 */
function diluxone_users_mail_locale_key( string $locale ): string {
	return 1 === preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,16})*$/', $locale ) ? $locale : 'en_US';
}

/**
 * The language a message is being written in right now.
 *
 * `determine_locale()` and not `get_locale()`: it is the same thing on the
 * front end, where these are sent, and on an admin screen it is the language
 * that person has the dashboard in — which is the language the `__()` calls
 * around it are answering in too. The rewrite and the plugin's own text have
 * to come from the same language or a site ends up with a Spanish subject and
 * an English body.
 */
function diluxone_users_mail_locale(): string {
	return diluxone_users_mail_locale_key( determine_locale() );
}

/**
 * What the plugin itself would say, in one language.
 *
 * The switch is what makes the screen honest: an administrator writing the
 * Portuguese version has to be shown the Portuguese the plugin ships, not the
 * Spanish their own dashboard is in. WordPress reloads the text domains when
 * the locale is switched, so the closure below answers in the language asked
 * for.
 *
 * @return array{subject: string, body: string}
 */
function diluxone_users_mail_shipped( string $key, string $locale = '' ): array {
	$templates = diluxone_users_mail_templates();

	if ( ! isset( $templates[ $key ] ) || ! is_callable( $templates[ $key ]['shipped'] ) ) {
		return array(
			'subject' => '',
			'body'    => '',
		);
	}

	$locale   = '' === $locale ? diluxone_users_mail_locale() : diluxone_users_mail_locale_key( $locale );
	$switched = determine_locale() !== $locale && switch_to_locale( $locale );

	$text = (array) call_user_func( $templates[ $key ]['shipped'] );

	if ( $switched ) {
		restore_previous_locale();
	}

	return array(
		'subject' => (string) ( $text['subject'] ?? '' ),
		'body'    => (string) ( $text['body'] ?? '' ),
	);
}

/**
 * Whatever is in the option, cut down to the shape it is supposed to have.
 *
 * Read defensively for the same reason the notice rules are: a value edited by
 * hand, restored from a half-finished export or written by an older version
 * must not reach the sending as an array where a string was expected. A row
 * that is not shaped like a rewrite is dropped rather than repaired, and the
 * message it belonged to goes back to the plugin's own text — which is always
 * a working e-mail.
 *
 * @param mixed $stored
 * @return array<string, array<string, array<string, string>>>
 */
function diluxone_users_mail_clean( $stored ): array {
	$store = array();

	foreach ( is_array( $stored ) ? $stored : array() as $locale => $messages ) {
		if ( ! is_string( $locale ) || ! is_array( $messages ) ) {
			continue;
		}

		foreach ( $messages as $key => $one ) {
			if ( ! is_string( $key ) || ! is_array( $one ) ) {
				continue;
			}

			$subject = isset( $one['subject'] ) && is_string( $one['subject'] ) ? $one['subject'] : '';
			$body    = isset( $one['body'] ) && is_string( $one['body'] ) ? $one['body'] : '';

			if ( '' === $subject && '' === $body ) {
				continue;
			}

			$store[ diluxone_users_mail_locale_key( $locale ) ][ $key ] = array(
				'subject' => $subject,
				'body'    => $body,
			);
		}
	}

	return $store;
}

/**
 * Everything that was rewritten, by language and then by message.
 *
 * @return array<string, array<string, array<string, string>>>
 */
function diluxone_users_mail_store(): array {
	$stored = diluxone_users_raw_get( DILUXONE_USERS_MAIL_TEMPLATES, array() );

	return is_array( $stored ) ? diluxone_users_mail_clean( $stored ) : array();
}

/**
 * What one message was rewritten to, in one language, or empty strings.
 *
 * @return array{subject: string, body: string}
 */
function diluxone_users_mail_rewritten( string $key, string $locale = '' ): array {
	$locale = '' === $locale ? diluxone_users_mail_locale() : diluxone_users_mail_locale_key( $locale );
	$one    = diluxone_users_mail_store()[ $locale ][ $key ] ?? array();

	return array(
		'subject' => $one['subject'] ?? '',
		'body'    => $one['body'] ?? '',
	);
}

/**
 * Writes one message's rewrite, or takes it away.
 *
 * Empty subject and empty body mean there is no rewrite, and the entry is
 * removed rather than stored as two empty strings: an empty entry and no
 * entry have to behave the same, and the only way to be sure of that is for
 * one of them never to exist. The language goes with it when it holds nothing
 * more, so the option does not grow a row per language somebody once opened.
 */
function diluxone_users_mail_rewrite( string $key, string $locale, string $subject, string $body ): void {
	$locale = diluxone_users_mail_locale_key( $locale );
	$store  = diluxone_users_mail_store();

	unset( $store[ $locale ][ $key ] );

	if ( '' !== $subject || '' !== $body ) {
		$store[ $locale ][ $key ] = array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	if ( empty( $store[ $locale ] ) ) {
		unset( $store[ $locale ] );
	}

	diluxone_users_update_option( DILUXONE_USERS_MAIL_TEMPLATES, $store );
}

/**
 * Does this text still carry everything the message cannot be sent without?
 *
 * @param array<int, string> $required
 */
function diluxone_users_mail_keeps( string $text, array $required ): bool {
	foreach ( $required as $placeholder ) {
		if ( false === strpos( $text, $placeholder ) ) {
			return false;
		}
	}

	return true;
}

/**
 * The text of one message, ready to send.
 *
 * The rewrite wins over the plugin's own, one half at a time: a site that
 * changed the subject and left the body alone keeps getting the plugin's body,
 * improvements included.
 *
 * What happens when a placeholder is missing is worth saying out loud, because
 * it is the question anybody rewriting one of these asks. A placeholder the
 * message declares and the caller did not fill in comes out as nothing — it is
 * a value that is not there, not a word to print. A placeholder nobody
 * declared is left exactly as it was typed, because it may belong to whatever
 * filters the message after this. And a rewrite that dropped a placeholder the
 * message cannot do without is not used at all: the plugin's own text goes out
 * instead, so a sign-in e-mail never leaves without its link.
 *
 * @param string                $key
 * @param array<string, string> $values Placeholder => what goes in its place.
 * @param string                $locale The language to write in, or '' for the one in use.
 * @return array{subject: string, body: string}
 */
function diluxone_users_mail_compose( string $key, array $values, string $locale = '' ): array {
	$templates = diluxone_users_mail_templates();
	$shipped   = diluxone_users_mail_shipped( $key, $locale );

	if ( ! isset( $templates[ $key ] ) ) {
		return $shipped;
	}

	/** @var array<string, string> $vars */
	$vars = (array) $templates[ $key ]['vars'];
	/** @var array<int, string> $required */
	$required = (array) $templates[ $key ]['required'];

	$rewritten = diluxone_users_mail_rewritten( $key, $locale );

	$subject = '' !== $rewritten['subject'] ? $rewritten['subject'] : $shipped['subject'];
	$body    = '' !== $rewritten['body'] ? $rewritten['body'] : $shipped['body'];

	if ( ! diluxone_users_mail_keeps( $body, $required ) ) {
		$body = $shipped['body'];
	}

	$fill = array();

	foreach ( array_keys( $vars ) as $placeholder ) {
		$fill[ $placeholder ] = (string) ( $values[ $placeholder ] ?? '' );
	}

	return array(
		'subject' => trim( strtr( $subject, $fill ) ),
		'body'    => trim( strtr( $body, $fill ) ),
	);
}

/**
 * What to call somebody in an e-mail.
 *
 * Their display name, and their address without the domain when there is no
 * account yet — which is the case the sign-in link has to handle, because the
 * account is created by following it.
 */
function diluxone_users_mail_person( string $email, int $user_id = 0 ): string {
	$user = $user_id > 0 ? get_userdata( $user_id ) : get_user_by( 'email', $email );

	if ( $user instanceof WP_User && '' !== $user->display_name ) {
		return $user->display_name;
	}

	return '' === $email ? '' : diluxone_users_name_from_email( $email );
}

/* ── The sign-in link ──────────────────────────────────────────────── */

/**
 * The sign-in link's message, as this site wrote it or as the plugin ships it.
 *
 * @return array{subject: string, body: string}
 */
function diluxone_users_mail_login_link( string $email, string $url ): array {
	return diluxone_users_mail_compose(
		'login_link',
		array(
			'{link}'    => $url,
			'{minutes}' => (string) diluxone_users_login_expiry(),
			'{site}'    => diluxone_users_site_name(),
			'{name}'    => diluxone_users_mail_person( $email ),
		)
	);
}

/**
 * The second-step code, the same way.
 *
 * @param array<string, string> $mail
 * @return array<string, string>
 */
function diluxone_users_mail_second_step( array $mail, int $user_id, string $code ): array {
	$user = get_userdata( $user_id );

	return diluxone_users_mail_compose(
		'second_step',
		array(
			'{code}'    => $code,
			'{minutes}' => (string) ( DILUXONE_USERS_2FA_EMAIL_TTL / MINUTE_IN_SECONDS ),
			'{site}'    => diluxone_users_site_name(),
			'{name}'    => diluxone_users_mail_person( $user instanceof WP_User ? $user->user_email : '', $user_id ),
		)
	);
}
add_filter( 'diluxone_users_2fa_email', 'diluxone_users_mail_second_step', 5, 3 );

/* ── The screen ────────────────────────────────────────────────────── */

/**
 * The languages a rewrite can be written for.
 *
 * What WordPress has installed, plus the language the site is set to and the
 * one the plugin is written in, which is always an answer even on a site with
 * no translations at all. Only these: a text written for a language the site
 * can never run in would never be sent.
 *
 * @return array<int, string>
 */
function diluxone_users_mail_languages(): array {
	$locales = array_merge( array( 'en_US', get_locale() ), get_available_languages() );
	$locales = array_values( array_unique( array_map( 'diluxone_users_mail_locale_key', $locales ) ) );

	sort( $locales );

	return $locales;
}

/**
 * A language named in its own language.
 *
 * PHP's intl names a locale the way a person who reads it would recognise it,
 * which is the whole job of a language picker. Without the extension the code
 * itself is the honest answer — it is what WordPress calls it — rather than a
 * table of names in this file going stale.
 */
function diluxone_users_mail_language_name( string $locale ): string {
	if ( function_exists( 'locale_get_display_name' ) ) {
		$name = (string) locale_get_display_name( $locale, $locale );

		if ( '' !== $name && $name !== $locale ) {
			return $name;
		}
	}

	return $locale;
}
