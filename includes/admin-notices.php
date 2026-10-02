<?php
/**
 * Everything the plugin puts in somebody's inbox, in one place.
 *
 * It was spread out the way the look used to be: the sign-in e-mail lived on
 * the sign-in screen, the second-step code had no screen at all, and what a
 * person can ask not to be told was only visible from their own account area.
 * Three different places for one question — what does this site send, and who
 * decides.
 *
 * The answer has two halves. The site decides the rule for each notice; where
 * the rule leaves a switch, the person sets it from their own account area.
 * The first tab shows the whole picture, the second is where the rules are
 * written, and the third is the wording of every e-mail the plugin sends —
 * including the two nobody can refuse, which is where that tab started and why
 * it used to hold one of them.
 *
 * Its tabs are registered like every other screen's, so a feature that sends
 * its own mail brings its own tab with it.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_USERS_NOTICES = 'diluxone-users-notices';

/** The notifications screen. */
function diluxone_users_screen_notices(): void {
	diluxone_users_screen_panels( DILUXONE_USERS_NOTICES, diluxone_users_screens()[ DILUXONE_USERS_NOTICES ] );
}

/** Its tabs. */
function diluxone_users_notices_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_NOTICES,
		'summary',
		array(
			'label'    => __( 'Summary', 'diluxone-users' ),
			'position' => 0,
			'render'   => 'diluxone_users_screen_notices_summary',
			// Nothing to save: it is the picture, and each row says where to
			// go to change it.
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_NOTICES,
		'rules',
		array(
			'label'    => __( 'Notices', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_notices_rules',
			'save'     => 'diluxone_users_notices_rules_save',
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_NOTICES,
		'templates',
		array(
			'label'    => __( 'The e-mails', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_mail_templates',
			'save'     => 'diluxone_users_mail_templates_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_notices_panels' );

/* ── Summary ───────────────────────────────────────────────────────── */

/**
 * Who a notice belongs to, in words.
 *
 * On a site with three plugins adding notices, "where did this e-mail come
 * from" is the question actually asked, so every row answers it.
 */
function diluxone_users_notice_origin( string $key ): string {
	return isset( diluxone_users_default_notifications()[ $key ] )
		? __( 'comes from: this plugin', 'diluxone-users' )
		: __( 'comes from: something else on this site', 'diluxone-users' );
}

/**
 * Everything that leaves this site by e-mail, one row each.
 *
 * First the notices with a rule, then the ones nobody can refuse. The latter
 * go out because they were asked for — somebody who presses "send me the
 * link" and gets nothing has no way in — so their state is whether the
 * feature is in use, not whether anybody wants them. When the last e-mail
 * this site sent failed, none of them can be counted on, and the row says so
 * instead of showing a green pill over an empty inbox.
 */
function diluxone_users_screen_notices_summary(): void {
	diluxone_users_ui_aside_open();

	$policies = diluxone_users_notice_policies();
	$rules    = diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'rules' ) );
	$rows     = array();

	foreach ( diluxone_users_notification_prefs() as $key => $notice ) {
		$policy = diluxone_users_notice_policy( (string) $key );

		$rows[] = array(
			'label'  => (string) $notice['label'],
			'state'  => 'never' === $policy ? 'off' : 'active',
			'detail' => $policies[ $policy ] . ' · ' . diluxone_users_notice_origin( (string) $key ),
			'url'    => $rules,
		);
	}

	// Sent by the plugin, with no switch. A failed delivery is the one thing
	// that turns these from "it goes out" to "nobody can tell".
	$delivery = diluxone_users_mail_works();
	$status   = diluxone_users_admin_url( 'diluxone-users-status' );
	$why      = __( 'the last e-mail this site sent did not go out', 'diluxone-users' );

	$link_on = diluxone_users_login_has_link();
	$rows[]  = array(
		'label'  => __( 'The sign-in link', 'diluxone-users' ),
		'state'  => $link_on ? ( $delivery ? 'active' : 'unknown' ) : 'off',
		'why'    => $link_on && ! $delivery ? $why : '',
		'detail' => $link_on
			? __( 'Sent when somebody asks to get in. Nobody can turn it off: without it there is no way in.', 'diluxone-users' )
			: __( 'Not offered: people sign in to this site with a password only.', 'diluxone-users' ),
		'url'    => $link_on
			? ( $delivery ? diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'templates' ) ) : $status )
			: diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
	);

	$code_on = diluxone_users_notice_2fa_email();
	$rows[]  = array(
		'label'  => __( 'The second-step code', 'diluxone-users' ),
		'state'  => $code_on ? ( $delivery ? 'active' : 'unknown' ) : 'off',
		'why'    => $code_on && ! $delivery ? $why : '',
		'detail' => $code_on
			? __( 'Sent when somebody halfway through signing in is waiting for it. Nobody can turn it off: a code that does not arrive locks them out.', 'diluxone-users' )
			: __( 'Not in use: a code by e-mail is not one of the second steps this site offers.', 'diluxone-users' ),
		'url'    => $code_on
			? ( $delivery ? diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'templates' ) ) : $status )
			: diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => '2fa' ) ),
	);

	// WordPress sends this one, not the plugin: the plugin only opens the
	// door. Whether it is delivered is beyond what can be told from here.
	$data_on = diluxone_users_privacy_any();
	$rows[]  = array(
		'label'  => __( 'Their own data', 'diluxone-users' ),
		'state'  => $data_on ? 'unknown' : 'off',
		'detail' => $data_on
			? __( 'The confirmation WordPress sends when somebody asks to download their data or delete their account. It is WordPress that sends it, so this screen cannot tell whether it arrives.', 'diluxone-users' )
			: __( 'Not in use: neither downloading their data nor deleting their account is offered.', 'diluxone-users' ),
	);

	diluxone_users_summary_table( $rows );

	/*
	 * A row of buttons at the foot said where to go and not why, and it sat
	 * under a table long enough that nobody scrolled to it. Beside the table
	 * each way out carries its own line, which is the difference between a
	 * button and a reason to press it.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $delivery ): void {
			// Only while it matters: a site whose last e-mail went out has
			// nothing to be told here, and a green pill is not news.
			if ( ! $delivery ) {
				diluxone_users_ui_notice(
					__( 'The last e-mail this site sent did not go out, so none of the rules beside this can be counted on — whatever each row says about who wants what.', 'diluxone-users' ),
					'warning'
				);
			}

			diluxone_users_ui_links(
				__( 'Where each of these is changed', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'rules' ) ),
						'label' => __( 'The rules', 'diluxone-users' ),
						'help'  => __( 'Who decides each notice: the site on everybody’s behalf, or each person from their own account.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'templates' ) ),
						'label' => __( 'The e-mails', 'diluxone-users' ),
						'help'  => __( 'The subject and the words of every one of them, language by language.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-status', array( 'tab' => 'tools' ) ),
						'label' => __( 'Send myself a test', 'diluxone-users' ),
						'help'  => __( 'The only way to know this site can reach an inbox is to make it reach yours.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/* ── The rules ─────────────────────────────────────────────────────── */

/**
 * One rule per notice.
 *
 * The four choices are written as outcomes and not as settings — what
 * happens to the person, not what the option is called — because the
 * question being answered is "who decides", and "default_off" answers a
 * different one.
 */
function diluxone_users_screen_notices_rules(): void {
	diluxone_users_ui_aside_open();

	$prefs    = diluxone_users_notification_prefs();
	$policies = diluxone_users_notice_policies();

	diluxone_users_intro( __( 'The site decides the rule for each notice; where the rule leaves a switch, each person sets it from their own account area.', 'diluxone-users' ) );

	if ( array() === $prefs ) {
		echo '<p>' . esc_html__( 'Nothing is registered: beyond the sign-in e-mail, this site tells nobody anything.', 'diluxone-users' ) . '</p>';

		diluxone_users_ui_aside_close( 'diluxone_users_notices_rules_aside' );

		return;
	}
	?>
	<div class="diluxone-users-rules">
		<?php
		/*
		 * Each notice is one block and not a row of a two-column table: what it
		 * is called and what it is were in different cells, so the sentence
		 * explaining a notice sat beside the rule of the one above it. Name,
		 * rule and explanation now belong to the same piece, in that order.
		 */
		foreach ( $prefs as $key => $notice ) :
			$id     = 'diluxone_users_notice_rules_' . (string) $key;
			$policy = diluxone_users_notice_policy( (string) $key );

			diluxone_users_ui_select(
				array(
					'label'   => (string) $notice['label'],
					'id'      => $id,
					'name'    => 'diluxone_users_notice_rules[' . (string) $key . ']',
					'value'   => $policy,
					'options' => $policies,
					'help'    => trim(
						(string) ( $notice['help'] ?? '' )
						. ' <code>' . esc_html( (string) $key ) . '</code> · '
						. esc_html( diluxone_users_notice_origin( (string) $key ) )
					),
				)
			);
		endforeach;
		?>
	</div>
	<?php
	diluxone_users_ui_aside_close( 'diluxone_users_notices_rules_aside' );
}

/**
 * What sits beside the rules.
 *
 * A named function and not a closure because the early return above needs the
 * same rail: a site with no notice registered still has an e-mail going out
 * and still has somewhere to change its words, and a screen that says
 * “nothing is registered” and then ends is a dead end.
 */
function diluxone_users_notices_rules_aside(): void {
	diluxone_users_ui_note(
		__( 'Who the switch belongs to', 'diluxone-users' ),
		array(
			esc_html__( 'Two of the four answers settle it for everybody, and the other two leave the person a switch and only decide which way it starts. Nobody is ever subscribed to something they turned off.', 'diluxone-users' ),
			esc_html__( 'The two e-mails nobody can refuse — the sign-in link and the second-step code — have no rule at all: they go out because somebody asked for them a second ago.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_links(
		__( 'The other half of this', 'diluxone-users' ),
		array(
			array(
				'url'   => diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'templates' ) ),
				'label' => __( 'The e-mails', 'diluxone-users' ),
				'help'  => __( 'A rule decides whether one goes out; this is what it says when it does.', 'diluxone-users' ),
			),
			array(
				'url'   => diluxone_users_admin_url( 'diluxone-users-account' ),
				'label' => __( 'Account area', 'diluxone-users' ),
				'help'  => __( 'Where a person meets the switches these rules leave them.', 'diluxone-users' ),
			),
		)
	);
}

/**
 * Writes the rules down.
 *
 * What arrives is merged over what was stored and not put in its place: the
 * form only carries the notices registered right now, and a rule written for
 * an add-on's notice should survive the add-on being switched off for a week.
 * A word that is not one of the four policies is dropped, not stored.
 */
function diluxone_users_notices_rules_save(): void {
	check_admin_referer( 'diluxone_users_panel_' . DILUXONE_USERS_NOTICES, 'diluxone_users_panel_nonce' );
	diluxone_users_panel_allowed();

	$policies = diluxone_users_notice_policies();
	$rules    = diluxone_users_notice_rules();
	$notices  = diluxone_users_notification_prefs();

	foreach ( (array) map_deep( wp_unslash( $_POST['diluxone_users_notice_rules'] ?? array() ), 'sanitize_key' ) as $key => $policy ) {
		$key = sanitize_key( (string) $key );

		// A rule for a notice the screen drew, and one of the answers it
		// offers. A key nobody registered was kept as a rule for nothing.
		if ( ! isset( $notices[ $key ] ) || ! is_string( $policy ) || ! isset( $policies[ $policy ] ) ) {
			continue;
		}

		$rules[ $key ] = $policy;
	}

	diluxone_users_save_options( array( 'diluxone_users_notice_rules' => $rules ) );
}

/* ── The templates ─────────────────────────────────────────────────── */

/**
 * The language this screen is writing for.
 *
 * It rides in the address rather than in the form, because switching language
 * is not a save: what is on screen belongs to the language it was drawn for,
 * and carrying it into another one would write the Spanish body into the
 * Portuguese slot. The form posts back to the same address, so the language
 * survives the save that follows; the hidden field is there for the case where
 * it does not — a form posted from somewhere else, a redirect that dropped the
 * query — and it is read second so the address always wins.
 *
 * A language this site cannot run in is answered with the one it does, because
 * a text written for a language nobody can switch to would never be sent.
 */
function diluxone_users_mail_screen_locale(): string {
	// phpcs:disable WordPress.Security.NonceVerification -- only which language: a locale picked from the site's installed languages, used to choose which texts are drawn or written; this function saves nothing.
	$asked = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';

	if ( '' === $asked && isset( $_POST['diluxone_users_mail_locale'] ) ) {
		$asked = sanitize_text_field( wp_unslash( $_POST['diluxone_users_mail_locale'] ) );
	}
	// phpcs:enable

	$site   = diluxone_users_mail_locale_key( get_locale() );
	$locale = diluxone_users_mail_locale_key( '' === $asked ? $site : $asked );

	return in_array( $locale, diluxone_users_mail_languages(), true ) ? $locale : $site;
}

/**
 * Which language is being written, and the way to another one.
 *
 * Only on a site that has more than one: a picker with a single entry is
 * furniture, and the sentence under it would be answering a question nobody
 * asked.
 */
function diluxone_users_mail_languages_note( string $current ): void {
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
						DILUXONE_USERS_NOTICES,
						array(
							'tab'  => 'templates',
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
			esc_html__( 'Each language keeps its own wording, and one nobody has touched sends the text the plugin ships. Moving to another one here does not save what is on screen.', 'diluxone-users' ),
			implode( ' · ', $links ),
		)
	);
}

/**
 * One e-mail, folded away until somebody wants it.
 *
 * Four e-mails with a subject, a body and an explanation each is four screens
 * of scrolling to reach the one being looked for, so each of them is a
 * `<details>` and they all start shut. That fold, the line saying whose words
 * are going out, and the way back to the plugin's own are
 * `diluxone_users_ui_rewritable()`: the sign-in page's sentences are the same
 * question asked about shorter texts, and one of them had to stop having its
 * own opinion about it.
 *
 * The boxes are never blank: they open with the text that is really going out,
 * which is the rewrite where there is one and the plugin's own where there is
 * not. A blank box asks the reader to invent an e-mail; a full one asks them
 * to change a word, which is what they came for.
 *
 * @param array<string, mixed> $template
 */
function diluxone_users_mail_template_fields( string $key, array $template, string $locale ): void {
	/** @var array<string, string> $vars */
	$vars = (array) $template['vars'];
	/** @var array<int, string> $required */
	$required = (array) $template['required'];

	$shipped   = diluxone_users_mail_shipped( $key, $locale );
	$rewritten = diluxone_users_mail_rewritten( $key, $locale );
	$is_own    = '' !== $rewritten['subject'] || '' !== $rewritten['body'];

	$subject = '' !== $rewritten['subject'] ? $rewritten['subject'] : $shipped['subject'];
	$body    = '' !== $rewritten['body'] ? $rewritten['body'] : $shipped['body'];

	$says = array();

	foreach ( $vars as $placeholder => $what ) {
		$says[] = '<code>' . esc_html( $placeholder ) . '</code> — ' . esc_html( $what );
	}

	diluxone_users_ui_rewritable(
		array(
			'label'    => (string) $template['label'],
			'help'     => (string) $template['help'],
			'name'     => $key,
			'language' => $is_own ? diluxone_users_mail_language_name( $locale ) : '',
			'revert'   => 'diluxone_users_mail[' . $key . '][shipped]',
		),
		static function () use ( $key, $subject, $body, $required, $says ): void {
			diluxone_users_ui_text(
				array(
					'label' => __( 'Subject', 'diluxone-users' ),
					'id'    => 'diluxone_users_mail_' . $key . '_subject',
					'name'  => 'diluxone_users_mail[' . $key . '][subject]',
					'value' => $subject,
				)
			);

			diluxone_users_ui_textarea(
				array(
					'label' => __( 'Message', 'diluxone-users' ),
					'id'    => 'diluxone_users_mail_' . $key . '_body',
					'name'  => 'diluxone_users_mail[' . $key . '][body]',
					'value' => $body,
					'rows'  => 10,
					'code'  => true,
					'help'  => esc_html__( 'It goes out as plain text: what is typed here is what is read, line breaks included. Empty the box and the plugin’s own words come back.', 'diluxone-users' )
					. (
						array() === $required
							? ''
							: ' ' . sprintf(
								/* translators: %s: one or more placeholders, e.g. {link} */
								esc_html__( '%s has to stay in whatever you write: without it the message is a dead end, so a save that lost it is refused.', 'diluxone-users' ),
								'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $required ) ) . '</code>'
							)
					),
				)
			);

			diluxone_users_ui_note(
				__( 'What you can drop into it', 'diluxone-users' ),
				array_merge(
					$says,
					array( esc_html__( 'Anything the plugin does not know about is left exactly as it is typed, and one it knows but has nothing to put in comes out as nothing at all.', 'diluxone-users' ) )
				)
			);
		}
	);
}

/**
 * The wording of every e-mail the plugin sends.
 *
 * One tab and not one per message, because "where do I change what this site
 * e-mails people" is one question. A message an add-on registers arrives here
 * with the rest and gets the same rewriting for nothing.
 */
function diluxone_users_screen_mail_templates(): void {
	diluxone_users_ui_aside_open();

	$locale    = diluxone_users_mail_screen_locale();
	$templates = diluxone_users_mail_templates();

	diluxone_users_intro( __( 'Every e-mail the plugin sends, in the words it sends them. Each one starts as the text the plugin ships in this language, so a site that changes nothing still reads correctly — and an update that improves a sentence improves it here too. Rewrite one and that is what goes out, until you put it back.', 'diluxone-users' ) );

	printf(
		'<input type="hidden" name="diluxone_users_mail_locale" value="%s">',
		esc_attr( $locale )
	);

	foreach ( $templates as $key => $template ) {
		diluxone_users_mail_template_fields( (string) $key, $template, $locale );
	}

	/*
	 * Which language is being written is not one of the e-mails: it is the
	 * frame all of them are in, and above the list it was a box somebody had
	 * to get past before reaching the first message. Beside them it stays in
	 * view while one is open, which is when switching language is actually
	 * wanted.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $locale ): void {
			diluxone_users_mail_languages_note( $locale );

			diluxone_users_ui_links(
				__( 'Before and after the words', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'rules' ) ),
						'label' => __( 'The rules', 'diluxone-users' ),
						'help'  => __( 'Whether each of these goes out at all, and who gets to say so.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-status', array( 'tab' => 'tools' ) ),
						'label' => __( 'Send myself a test', 'diluxone-users' ),
						'help'  => __( 'Words nobody receives are still words nobody receives. This is how to find out.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Writes what was typed, for the language it was typed in.
 *
 * Two things are decided here rather than stored. The first is that the
 * plugin's own text saved unchanged is not a rewrite: whoever opens a message,
 * reads it and presses save has said nothing, and recording it would freeze
 * that site on today's wording for good. The second is that a message which
 * lost what it cannot be sent without is left exactly as it was — the save
 * says which one and why, and nothing is written for it, because half a
 * sign-in e-mail is worse than the one that was working a second ago.
 */
function diluxone_users_mail_templates_save(): void {
	check_admin_referer( 'diluxone_users_panel_' . DILUXONE_USERS_NOTICES, 'diluxone_users_panel_nonce' );
	diluxone_users_panel_allowed();

	$locale = diluxone_users_mail_screen_locale();

	$sent = (array) map_deep( wp_unslash( $_POST['diluxone_users_mail'] ?? array() ), 'sanitize_textarea_field' );

	foreach ( diluxone_users_mail_templates() as $key => $template ) {
		$key = (string) $key;

		if ( ! isset( $sent[ $key ] ) || ! is_array( $sent[ $key ] ) ) {
			continue;
		}

		$one = $sent[ $key ];

		if ( ! empty( $one['shipped'] ) ) {
			diluxone_users_mail_rewrite( $key, $locale, '', '' );
			continue;
		}

		$subject = trim( sanitize_text_field( (string) ( is_scalar( $one['subject'] ?? null ) ? $one['subject'] : '' ) ) );
		$body    = trim( sanitize_textarea_field( (string) ( is_scalar( $one['body'] ?? null ) ? $one['body'] : '' ) ) );

		/*
		 * A text area sends the line breaks the way the browser writes them,
		 * which on most of them is a carriage return and a line feed. The
		 * plugin's own text has line feeds alone, so without this the very
		 * first save of a message nobody changed comes out different from what
		 * it was compared against — and the site would be recorded as having
		 * rewritten an e-mail it only read.
		 */
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );

		/** @var array<int, string> $required */
		$required = (array) $template['required'];

		/*
		 * An empty box is not a broken e-mail, it is the way back: whoever
		 * clears the message is asking for the plugin's own again, which is
		 * what the empty value means everywhere else the plugin lets a site
		 * write its own words. Only a text that says something and has lost
		 * what it cannot be sent without is refused.
		 */
		if ( '' !== $body && ! diluxone_users_mail_keeps( $body, $required ) ) {
			diluxone_users_notice(
				sprintf(
					/* translators: 1: the name of one of the e-mails, 2: one or more placeholders, e.g. {link} */
					__( '“%1$s” was left as it was: it cannot go out without %2$s.', 'diluxone-users' ),
					(string) $template['label'],
					implode( ', ', $required )
				),
				'error'
			);

			continue;
		}

		$shipped = diluxone_users_mail_shipped( $key, $locale );

		diluxone_users_mail_rewrite(
			$key,
			$locale,
			$subject === trim( $shipped['subject'] ) ? '' : $subject,
			$body === trim( $shipped['body'] ) ? '' : $body
		);
	}
}
