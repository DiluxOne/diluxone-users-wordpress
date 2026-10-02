<?php
/**
 * What the way in says when something goes wrong, and where a site rewrites it.
 *
 * Every one of these sentences was written where it was printed: four in
 * templates/login.php, six in templates/register.php, two on the second-step
 * screen, one where a new password is chosen. Thirteen sentences that a person
 * only ever reads on the worst day they have with this site, and the only way
 * to change any of them was to copy the template into a theme — which is to
 * say: to stop receiving every other improvement to that screen for ever.
 *
 * The one that started this is the social one. "We could not finish signing
 * you in with that provider" is true and useless: the site knows whether it
 * runs one network or six, whether there is a help desk to write to, whether
 * the person should be trying the other button instead. The plugin cannot
 * know any of that, and the site cannot say it.
 *
 * So the sentences live here, all thirteen in the same shape, and they are
 * rewritten the same way the e-mails are — which is deliberate, and is why
 * this reads like mail-templates.php. Three rules, the same three:
 *
 * The plugin's own words are the starting point, in the language the site
 * runs in. Nothing is copied into the database on install, the boxes are never
 * blank, and a site that changes nothing goes on receiving better wording with
 * every update.
 *
 * A rewrite belongs to a language and not to a site. A site running in two
 * languages does not show the same sentence to both, so the key is the locale
 * and a language nobody touched keeps the plugin's own.
 *
 * Saving the plugin's own text unchanged is not a rewrite. Whoever opens the
 * tab, reads a sentence and presses save has said nothing, and recording it
 * would quietly freeze that site on today's wording.
 *
 * The locale helpers are the ones mail-templates.php already has. A locale key
 * is a locale key — the same regex, the same fallback, the same list of
 * languages a site can actually run in — and a second copy of it here would be
 * a second copy to keep in step the first time WordPress grows a locale shape
 * nobody expected.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the rewritten sentences are kept.
 *
 * Not one of `diluxone_users_option_defaults()`, for the reason the e-mails
 * are not either: the value is a map of locales, and what saves those settings
 * runs every key through `sanitize_key()`, which lowercases — `es_AR` would
 * come back out as `es_ar` and never match the locale it was written for.
 */
const DILUXONE_USERS_LOGIN_MESSAGES = 'diluxone_users_login_messages';

/**
 * The screens these sentences appear on, in the order somebody meets them.
 *
 * A group is not decoration: thirteen sentences in one column with nothing
 * separating them is a list to be read down, and what a person arrives with is
 * "the message about the network", which is a question about one screen.
 *
 * @return array<string, string>
 */
function diluxone_users_login_message_groups(): array {
	return array(
		'login'    => __( 'On the sign-in page', 'diluxone-users' ),
		'two_step' => __( 'On the second step', 'diluxone-users' ),
		'reset'    => __( 'Choosing a new password', 'diluxone-users' ),
		'register' => __( 'Creating an account', 'diluxone-users' ),
	);
}

/**
 * Every sentence the way in can show, what it is called, and how it reads.
 *
 * `shipped` is a callable and not a string for the same reason the e-mails'
 * is: it has to be able to run under a language that is not the one loaded,
 * because the screen shows the plugin's own words for whichever language is
 * being written. A closure is called after the switch; a string was translated
 * when the array was built.
 *
 * `tone` is which of the two notices it is — the red one or the green one —
 * and it belongs to the message and not to the template, so an add-on that
 * registers a sentence gets the right colour without printing any markup.
 *
 * `when` is the sentence in the present tense that says when a person sees
 * this. It is what makes the tab usable: a list of thirteen messages with no
 * word about when each one appears is thirteen guesses.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_login_messages(): array {
	$messages = array();

	$messages['login_changed'] = array(
		'group'   => 'login',
		'tone'    => 'ok',
		'label'   => __( 'The password was changed', 'diluxone-users' ),
		'when'    => __( 'Shown to somebody who has just chosen a new password and is back at the form to use it.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Your password is changed. You can sign in with it now.', 'diluxone-users' );
		},
	);

	$messages['login_expired'] = array(
		'group'   => 'login',
		'tone'    => 'error',
		'label'   => __( 'The sign-in link no longer works', 'diluxone-users' ),
		'when'    => __( 'Shown when somebody clicks a link that has run out of time or was already used once.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'That link expired or was already used. Ask for a new one.', 'diluxone-users' );
		},
	);

	$messages['login_confirm'] = array(
		'group'   => 'login',
		'tone'    => 'ok',
		'label'   => __( 'Signing in to confirm a request', 'diluxone-users' ),
		'when'    => __( 'Shown to somebody who opened the link that confirms a copy of their data or the deletion of their account, and has to sign in first.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Sign in to confirm your request. It has to be the account that asked for it.', 'diluxone-users' );
		},
	);

	$messages['login_closed'] = array(
		'group'   => 'login',
		'tone'    => 'ok',
		'label'   => __( 'The account was deleted', 'diluxone-users' ),
		'when'    => __( 'Shown to somebody who has just confirmed, from their account, that it should be deleted.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Your account was deleted and your data erased.', 'diluxone-users' );
		},
	);

	$messages['login_email'] = array(
		'group'   => 'login',
		'tone'    => 'error',
		'label'   => __( 'The address is not an address', 'diluxone-users' ),
		'when'    => __( 'Shown when what was typed into the e-mail box cannot be an e-mail address.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'That email address does not look valid.', 'diluxone-users' );
		},
	);

	$messages['login_social'] = array(
		'group'   => 'login',
		'tone'    => 'error',
		'label'   => __( 'Signing in with a network did not finish', 'diluxone-users' ),
		'when'    => __( 'Shown whenever a trip out to a social network comes back without a session: the person said no over there, the network would not vouch for the address, this site does not take that account, or the trip was tampered with. One sentence covers all of them on purpose — telling a stranger which of those it was is telling them what to try next.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'We could not finish signing you in with that provider. Try again or use your email.', 'diluxone-users' );
		},
	);

	$messages['login_error'] = array(
		'group'   => 'login',
		'tone'    => 'error',
		'label'   => __( 'Something else went wrong', 'diluxone-users' ),
		'when'    => __( 'The last resort of the sign-in page, when nothing more precise can honestly be said.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Something went wrong. Try again.', 'diluxone-users' );
		},
	);

	$messages['two_step_wrong'] = array(
		'group'   => 'two_step',
		'tone'    => 'error',
		'label'   => __( 'The code is not right', 'diluxone-users' ),
		'when'    => __( 'Shown when the six digits do not match, or matched a code that has since expired.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'That code is not right, or it expired. Try the next one.', 'diluxone-users' );
		},
	);

	$messages['two_step_locked'] = array(
		'group'   => 'two_step',
		'tone'    => 'error',
		'label'   => __( 'Too many wrong codes', 'diluxone-users' ),
		'when'    => __( 'Shown once an account has answered the second step wrongly too many times. The wait grows with every further try and the account opens again on its own.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Too many wrong codes. Wait a few minutes and try again.', 'diluxone-users' );
		},
	);

	$messages['two_step_sent'] = array(
		'group'   => 'two_step',
		'tone'    => 'ok',
		'label'   => __( 'A code is on its way', 'diluxone-users' ),
		'when'    => __( 'Shown after somebody asks for the code to be sent to their inbox instead.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Sent. Check your email.', 'diluxone-users' );
		},
	);

	$messages['reset_mismatch'] = array(
		'group'   => 'reset',
		'tone'    => 'error',
		'label'   => __( 'The two passwords differ', 'diluxone-users' ),
		'when'    => __( 'Shown on the screen where a new password is chosen, when the two boxes do not say the same thing.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'The two passwords are not the same. Try again.', 'diluxone-users' );
		},
	);

	$messages['register_taken'] = array(
		'group'   => 'register',
		'tone'    => 'error',
		'label'   => __( 'That address already has an account', 'diluxone-users' ),
		'when'    => __( 'Shown when somebody tries to register an address the site already knows. The way out — the link to the sign-in page — is added after whatever this says.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'There is already an account with that address.', 'diluxone-users' );
		},
	);

	$messages['register_email'] = array(
		'group'   => 'register',
		'tone'    => 'error',
		'label'   => __( 'The address is not an address', 'diluxone-users' ),
		'when'    => __( 'The same as on the sign-in page, on the registration form. It is a separate sentence because the two screens are allowed to speak differently.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'That email address does not look valid.', 'diluxone-users' );
		},
	);

	$messages['register_missing'] = array(
		'group'   => 'register',
		'tone'    => 'error',
		'label'   => __( 'Something required was left blank', 'diluxone-users' ),
		'when'    => __( 'Shown when one of the fields this site marks as required came back empty.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Some required fields are missing.', 'diluxone-users' );
		},
	);

	$messages['register_slow'] = array(
		'group'   => 'register',
		'tone'    => 'error',
		'label'   => __( 'Too many accounts, too fast', 'diluxone-users' ),
		'when'    => __( 'Shown when the same machine has just asked for several accounts in a row. It is the throttle talking, and it says so gently: whoever reads it is far more often impatient than malicious.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'One moment: wait a few seconds and try again.', 'diluxone-users' );
		},
	);

	$messages['register_closed'] = array(
		'group'   => 'register',
		'tone'    => 'error',
		'label'   => __( 'Registration is shut', 'diluxone-users' ),
		'when'    => __( 'Shown when a form was sent to a site that has since stopped taking new accounts.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'This site does not take new accounts right now.', 'diluxone-users' );
		},
	);

	$messages['register_error'] = array(
		'group'   => 'register',
		'tone'    => 'error',
		'label'   => __( 'Something else went wrong', 'diluxone-users' ),
		'when'    => __( 'The last resort of the registration form, when nothing more precise can honestly be said.', 'diluxone-users' ),
		'shipped' => static function (): string {
			return __( 'Something went wrong. Try again.', 'diluxone-users' );
		},
	);

	/**
	 * Filters the sentences the way in can show, and that a site can rewrite.
	 *
	 * An add-on that puts its own message on the sign-in page registers it
	 * here and gets the screen, the per-language rewriting and the way back to
	 * its own wording for nothing. Each entry: group (one of
	 * `diluxone_users_login_message_groups()`), tone (`error` or `ok`), label,
	 * when, and shipped — a callable giving back the sentence, called under
	 * the language being written.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $messages
	 */
	return (array) apply_filters( 'diluxone_users_login_messages', $messages );
}

/**
 * What the plugin itself would say, in one language.
 *
 * The switch is what makes the tab honest: somebody writing the Portuguese
 * version has to be shown the Portuguese the plugin ships, not the Spanish
 * their own dashboard is in.
 */
function diluxone_users_login_message_shipped( string $key, string $locale = '' ): string {
	$messages = diluxone_users_login_messages();

	if ( ! isset( $messages[ $key ] ) || ! is_callable( $messages[ $key ]['shipped'] ) ) {
		return '';
	}

	$locale   = '' === $locale ? diluxone_users_mail_locale() : diluxone_users_mail_locale_key( $locale );
	$switched = determine_locale() !== $locale && switch_to_locale( $locale );

	$text = (string) call_user_func( $messages[ $key ]['shipped'] );

	if ( $switched ) {
		restore_previous_locale();
	}

	return $text;
}

/**
 * Whatever is in the option, cut down to the shape it is supposed to have.
 *
 * Read defensively: a value edited by hand, restored from a half-finished
 * export or written by an older version must not reach the sign-in page as an
 * array where a sentence was expected. A row that is not shaped like a
 * rewrite is dropped rather than repaired, and the message it belonged to goes
 * back to the plugin's own words — which always read correctly.
 *
 * @param mixed $stored
 * @return array<string, array<string, string>>
 */
function diluxone_users_login_messages_clean( $stored ): array {
	$store = array();

	foreach ( is_array( $stored ) ? $stored : array() as $locale => $written ) {
		if ( ! is_string( $locale ) || ! is_array( $written ) ) {
			continue;
		}

		foreach ( $written as $key => $text ) {
			if ( ! is_string( $key ) || ! is_string( $text ) || '' === $text ) {
				continue;
			}

			$store[ diluxone_users_mail_locale_key( $locale ) ][ $key ] = $text;
		}
	}

	return $store;
}

/**
 * Everything that was rewritten, by language and then by message.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_login_messages_store(): array {
	return diluxone_users_login_messages_clean( diluxone_users_raw_get( DILUXONE_USERS_LOGIN_MESSAGES, array() ) );
}

/**
 * Writes one sentence's rewrite, or takes it away.
 *
 * An empty text means there is no rewrite, and the entry is removed rather
 * than stored as an empty string: an empty entry and no entry have to behave
 * the same, and the only way to be sure of that is for one of them never to
 * exist. The language goes with it when it holds nothing more, so the option
 * does not grow a row per language somebody once opened.
 */
function diluxone_users_login_message_rewrite( string $key, string $locale, string $text ): void {
	$locale = diluxone_users_mail_locale_key( $locale );
	$store  = diluxone_users_login_messages_store();

	unset( $store[ $locale ][ $key ] );

	if ( '' !== $text ) {
		$store[ $locale ][ $key ] = $text;
	}

	if ( empty( $store[ $locale ] ) ) {
		unset( $store[ $locale ] );
	}

	diluxone_users_update_option( DILUXONE_USERS_LOGIN_MESSAGES, $store );
}

/**
 * The sentence to show, in the language the page is being drawn in.
 *
 * The rewrite wins over the plugin's own, and the filter wins over both — it
 * is the seam for the site that needs the message to depend on something no
 * settings screen can hold: which network was being tried, whether the person
 * arrived from the app, what the hour is. A key nobody registered answers with
 * an empty string, and an empty string draws no notice at all, so a template
 * asking for a message that has been filtered away does not print an empty
 * red box.
 */
function diluxone_users_login_message( string $key ): string {
	$locale    = diluxone_users_mail_locale();
	$rewritten = diluxone_users_login_messages_store()[ $locale ][ $key ] ?? '';
	$text      = '' !== $rewritten ? $rewritten : diluxone_users_login_message_shipped( $key, $locale );

	/**
	 * Filters one sentence of the way in, after the site's own rewriting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text   What is about to be shown.
	 * @param string $key    Which message, as `diluxone_users_login_messages()` names it.
	 * @param string $locale The language it is being drawn in.
	 */
	return (string) apply_filters( 'diluxone_users_login_message', $text, $key, $locale );
}

/**
 * One of those sentences, drawn as the notice it is.
 *
 * The templates call this instead of writing the paragraph themselves, which
 * is what makes the rewriting reach all of them at once. The tone comes from
 * the message and not from the template for the same reason: the colour is
 * part of what the sentence is, and a template deciding it is a template that
 * can disagree with the tab.
 *
 * The `data-diluxone-users-message` attribute is the one thing here that is
 * not for a reader. It is how a test says "the message about the network"
 * without saying any of its words, on a site in any of the eight languages
 * this plugin ships.
 *
 * @param string $key   Which message.
 * @param string $extra Markup that belongs inside the same notice, after the
 *                      sentence — the way out, where there is one. It is the
 *                      caller's own markup and is filtered, not escaped.
 */
function diluxone_users_login_notice( string $key, string $extra = '' ): void {
	$text = diluxone_users_login_message( $key );

	if ( '' === $text ) {
		return;
	}

	$messages = diluxone_users_login_messages();
	$tone     = 'ok' === ( $messages[ $key ]['tone'] ?? 'error' ) ? 'ok' : 'error';

	printf(
		'<p class="diluxone-users-notice diluxone-users-notice--%1$s" data-diluxone-users-message="%2$s">%3$s%4$s</p>',
		esc_attr( $tone ),
		esc_attr( $key ),
		esc_html( $text ),
		'' === $extra ? '' : ' ' . wp_kses_post( $extra )
	);
}

/* ── The screen ────────────────────────────────────────────────────── */

/**
 * The tab, on the Access screen and not on the social one.
 *
 * It started as the message about the networks, which would have put it beside
 * them. It did not stay there: the other twelve sentences are in exactly the
 * same position — written into a template, unreachable without a theme — and
 * two mechanisms for one problem is the thing this plugin keeps refusing to
 * grow. So it is one tab, holding every sentence the way in can say, on the
 * screen the way in already lives on. The rail says where the networks
 * themselves are configured, for whoever arrived looking for them.
 */
function diluxone_users_login_messages_panel(): void {
	diluxone_users_register_panel(
		'diluxone-users-login',
		'messages',
		array(
			// "What it says" read as a question on the tab strip and said
			// nothing about which of the six tabs held the wording. These are
			// the notices the sign-in screens show, so that is the name.
			'label'    => __( 'Messages', 'diluxone-users' ),
			'position' => 40,
			'render'   => 'diluxone_users_screen_login_messages',
			'save'     => 'diluxone_users_login_messages_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_login_messages_panel' );

/**
 * Which language is being written.
 *
 * The address first, so a link into the tab always wins; the form second, so a
 * save comes back to the language it was typed in. A language this site cannot
 * run in is answered with the one it does, because a sentence written for a
 * language nobody can switch to would never be shown.
 */
function diluxone_users_login_messages_locale(): string {
	// phpcs:disable WordPress.Security.NonceVerification -- only which language: a locale picked from the site's installed languages, used to choose which texts are drawn or written; this function saves nothing.
	$asked = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';

	if ( '' === $asked && isset( $_POST['diluxone_users_message_locale'] ) ) {
		$asked = sanitize_text_field( wp_unslash( $_POST['diluxone_users_message_locale'] ) );
	}
	// phpcs:enable

	$site   = diluxone_users_mail_locale_key( get_locale() );
	$locale = diluxone_users_mail_locale_key( '' === $asked ? $site : $asked );

	return in_array( $locale, diluxone_users_mail_languages(), true ) ? $locale : $site;
}

/**
 * How many of these this site has written itself, in one language.
 */
function diluxone_users_login_messages_own( string $locale ): int {
	$written = diluxone_users_login_messages_store()[ $locale ] ?? array();

	return count( array_intersect_key( $written, diluxone_users_login_messages() ) );
}

/**
 * One sentence, folded away until somebody wants it.
 *
 * Thirteen open boxes is a screen nobody reads to the end, so each of them is
 * folded away by `diluxone_users_ui_rewritable()` — literally the same piece
 * the e-mails use, which is also what keeps the two screens saying "this site
 * rewrote it" in the same words. The box is never blank: it opens with what is
 * really being shown, which is the rewrite where there is one and the plugin's
 * own where there is not.
 *
 * @param array<string, mixed> $message
 */
function diluxone_users_login_message_fields( string $key, array $message, string $locale ): void {
	$shipped   = diluxone_users_login_message_shipped( $key, $locale );
	$rewritten = diluxone_users_login_messages_store()[ $locale ][ $key ] ?? '';
	$is_own    = '' !== $rewritten;

	diluxone_users_ui_rewritable(
		array(
			'label'    => (string) $message['label'],
			'help'     => (string) $message['when'],
			'name'     => $key,
			'language' => $is_own ? diluxone_users_mail_language_name( $locale ) : '',
			'revert'   => 'diluxone_users_message_shipped[' . $key . ']',
		),
		static function () use ( $key, $rewritten, $shipped ): void {
			diluxone_users_ui_textarea(
				array(
					'label' => __( 'The sentence', 'diluxone-users' ),
					'id'    => 'diluxone_users_message_' . $key,
					'name'  => 'diluxone_users_message[' . $key . ']',
					'value' => '' !== $rewritten ? $rewritten : $shipped,
					'rows'  => 2,
					'help'  => __( 'It is read by somebody who is stuck, so it is worth saying what to do next and not only what happened. Empty the box and the plugin’s own words come back.', 'diluxone-users' ),
				)
			);
		}
	);
}

/** The tab itself. */
function diluxone_users_screen_login_messages(): void {
	diluxone_users_ui_aside_open();

	$locale   = diluxone_users_login_messages_locale();
	$messages = diluxone_users_login_messages();

	diluxone_users_intro( __( 'The sentences a person reads when the way in does not work. Each one starts as the text the plugin ships in this language, so a site that changes nothing still reads correctly — and an update that improves a sentence improves it here too. Rewrite one and that is what is shown, until you put it back.', 'diluxone-users' ) );

	printf(
		'<input type="hidden" name="diluxone_users_message_locale" value="%s">',
		esc_attr( $locale )
	);

	foreach ( diluxone_users_login_message_groups() as $group => $title ) {
		$mine = array_filter(
			$messages,
			static fn( array $one ): bool => $group === ( $one['group'] ?? '' )
		);

		if ( array() === $mine ) {
			continue;
		}

		diluxone_users_ui_section( $title );

		foreach ( $mine as $key => $message ) {
			diluxone_users_login_message_fields( (string) $key, $message, $locale );
		}
	}

	// Anything an add-on registered under a group of its own: it would
	// otherwise be written, saved and never drawn, which is worse than an
	// ungrouped box.
	$groups = diluxone_users_login_message_groups();
	$loose  = array_filter(
		$messages,
		static fn( array $one ): bool => ! isset( $groups[ (string) ( $one['group'] ?? '' ) ] )
	);

	if ( array() !== $loose ) {
		diluxone_users_ui_section( __( 'Added by something else on this site', 'diluxone-users' ) );

		foreach ( $loose as $key => $message ) {
			diluxone_users_login_message_fields( (string) $key, $message, $locale );
		}
	}

	diluxone_users_ui_aside_close(
		static function () use ( $locale, $messages ): void {
			$own = diluxone_users_login_messages_own( $locale );

			diluxone_users_ui_aside_state(
				0 === $own
					? sprintf(
						/* translators: %s: the name of a language, in <code> */
						__( 'Every message is the plugin’s own, in %s.', 'diluxone-users' ),
						'<code>' . esc_html( diluxone_users_mail_language_name( $locale ) ) . '</code>'
					)
					: sprintf(
						/* translators: 1: how many messages this site wrote, 2: how many there are, 3: the name of a language, in <code> */
						_n(
							'This site writes %1$d of the %2$d messages itself, in %3$s.',
							'This site writes %1$d of the %2$d messages themselves, in %3$s.',
							$own,
							'diluxone-users'
						),
						$own,
						count( $messages ),
						'<code>' . esc_html( diluxone_users_mail_language_name( $locale ) ) . '</code>'
					),
				'active'
			);

			diluxone_users_login_messages_languages( $locale );

			diluxone_users_ui_note(
				__( 'What this tab decides', 'diluxone-users' ),
				array(
					__( 'The words, and nothing else. Whether a message ever appears is decided by the setting it reports on — which networks are on, whether registration is open, how long a link lasts.', 'diluxone-users' ),
					__( 'A message that has to depend on something no settings screen can hold is a filter in code: <code>diluxone_users_login_message</code> runs after everything written here.', 'diluxone-users' ),
				)
			);

			diluxone_users_ui_links(
				__( 'What these messages are about', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-social' ),
						'label' => __( 'Social login', 'diluxone-users' ),
						'help'  => __( 'The networks themselves: which ones are on, and why one of them turns somebody away.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
						'label' => __( 'Ways in', 'diluxone-users' ),
						'help'  => __( 'Which doors are open at all, and how long a sign-in link is good for.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'templates' ) ),
						'label' => __( 'The e-mails', 'diluxone-users' ),
						'help'  => __( 'The other half of what this site says to somebody signing in, rewritten the same way.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Which language is being written, and the way to another one.
 *
 * Only on a site that has more than one: a picker with a single entry is
 * furniture.
 */
function diluxone_users_login_messages_languages( string $current ): void {
	$languages = diluxone_users_mail_languages();

	if ( count( $languages ) < 2 ) {
		return;
	}

	$links = array();

	foreach ( $languages as $locale ) {
		$name = diluxone_users_mail_language_name( $locale );

		$links[] = $locale === $current
			? '<strong>' . esc_html( $name ) . '</strong>'
			: sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					diluxone_users_admin_url(
						'diluxone-users-login',
						array(
							'tab'  => 'messages',
							'lang' => $locale,
						)
					)
				),
				esc_html( $name )
			);
	}

	diluxone_users_ui_note(
		__( 'The language you are writing', 'diluxone-users' ),
		array(
			esc_html__( 'Each language keeps its own wording, and one nobody has touched shows the text the plugin ships. Moving to another one here does not save what is on screen.', 'diluxone-users' ),
			implode( ' · ', $links ),
		)
	);
}

/**
 * Writes what was typed, for the language it was typed in.
 *
 * The plugin's own sentence saved unchanged is not a rewrite, for the reason
 * the e-mails give: whoever opens a message, reads it and presses save has
 * said nothing, and recording it would freeze that site on today's wording.
 * What is compared is what is in the box against what the plugin would have
 * said in that language; equal means there is nothing to store.
 */
function diluxone_users_login_messages_save(): void {
	check_admin_referer( 'diluxone_users_panel_diluxone-users-login', 'diluxone_users_panel_nonce' );
	diluxone_users_panel_allowed();

	$locale = diluxone_users_login_messages_locale();

	/** @var array<string, mixed> $sent */
	$sent = (array) map_deep( wp_unslash( $_POST['diluxone_users_message'] ?? array() ), 'sanitize_textarea_field' );
	/** @var array<string, mixed> $back */
	$back = (array) map_deep( wp_unslash( $_POST['diluxone_users_message_shipped'] ?? array() ), 'sanitize_key' );

	foreach ( array_keys( diluxone_users_login_messages() ) as $key ) {
		$key = (string) $key;

		if ( ! empty( $back[ $key ] ) ) {
			diluxone_users_login_message_rewrite( $key, $locale, '' );
			continue;
		}

		if ( ! isset( $sent[ $key ] ) || ! is_scalar( $sent[ $key ] ) ) {
			continue;
		}

		/*
		 * A text area sends the line breaks the way the browser writes them,
		 * which on most of them is a carriage return and a line feed. The
		 * plugin's own text has line feeds alone, so without this a message
		 * nobody changed would come out different from what it is compared
		 * against — and the site would be recorded as having rewritten a
		 * sentence it only read.
		 */
		$text = trim( sanitize_textarea_field( (string) $sent[ $key ] ) );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		diluxone_users_login_message_rewrite(
			$key,
			$locale,
			$text === trim( diluxone_users_login_message_shipped( $key, $locale ) ) ? '' : $text
		);
	}
}
