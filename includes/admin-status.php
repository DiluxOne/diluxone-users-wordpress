<?php
/**
 * The maintenance screen: what breaks today without saying so, and the
 * little that can be done about it by hand.
 *
 * Almost nothing this plugin does fails with a visible error. The account
 * page loses the shortcode and shows the raw text; the mail stops going out
 * and the sign-in links reach nobody; the site drops to HTTP and the passkeys
 * disappear with no explanation; somebody switches the permalinks to "plain"
 * and the account area starts returning 404. All of those are discovered when
 * somebody complains, not when they happen.
 *
 * Here they are all together, on a single tab that touches nothing: it looks
 * and reports. What gets fixed is fixed on the Tools tab beside it, and the
 * way back in when nothing else works is on the third one. Three tabs of one
 * screen and not three screens, because they are read in that order and by
 * the same person: something is wrong, what can be pressed, and what to do
 * when nothing can be pressed.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_USERS_STATUS = 'diluxone-users-status';

/** The screen's own tabs. The tools register theirs from their own file. */
function diluxone_users_status_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_STATUS,
		'status',
		array(
			'label'    => __( 'Status', 'diluxone-users' ),
			'position' => 0,
			'render'   => 'diluxone_users_screen_status_checks',
			// It reads and counts; there is nothing to save and so no form.
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_STATUS,
		'lockout',
		array(
			'label'    => __( 'If you get locked out', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_status_lockout',
			'form'     => false,
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_status_panels' );

/**
 * One check, with its verdict.
 *
 * The verdict is one of the four states every screen uses — active, pending,
 * off, unknown — so the row can go straight into the summary table. A pending
 * one always carries its reason: the pill is never shown bare.
 *
 * @param string $label  What was looked at.
 * @param string $state  One of active|pending|off|unknown.
 * @param string $detail What was found, in one line.
 * @param string $why    The reason, for a pending one.
 * @param string $url    Where to fix it, if anywhere.
 * @param string $change What the link says, when not "Change it".
 * @param string $word   What to call that state here, when the check's title
 *                       names a thing that has no switch on it — see
 *                       `diluxone_users_state_pill()`.
 * @return array{label: string, state: string, detail: string, why: string, url: string, change?: string, word?: string}
 */
function diluxone_users_check( string $label, string $state, string $detail = '', string $why = '', string $url = '', string $change = '', string $word = '' ): array {
	$row = array(
		'label'  => $label,
		'state'  => $state,
		'detail' => $detail,
		'why'    => $why,
		'url'    => $url,
	);

	// Same rule as the link below: left out rather than empty, so the table
	// falls back to the state's own word.
	if ( '' !== $word ) {
		$row['word'] = $word;
	}

	// Left out rather than empty: the table falls back to "Change it" only
	// when the key is missing, and an empty string would print as nothing.
	if ( '' !== $change ) {
		$row['change'] = $change;
	}

	return $row;
}

/**
 * Is the page holding the account area in working order?
 *
 * Three different things can be wrong and look identical from the outside:
 * that none has been chosen, that the chosen one no longer exists or is a
 * draft, or that it exists but is missing the shortcode. Each one is named
 * for what it is.
 *
 * @param string $option    The option storing the page ID.
 * @param string $shortcode The shortcode that page has to contain.
 * @param string $label     What that page is called in here.
 * @param string $screen    The plugin screen where the page is chosen.
 * @return array{label: string, state: string, detail: string, why: string, url: string, change?: string, word?: string}
 */
function diluxone_users_check_page( string $option, string $shortcode, string $label, string $screen ): array {
	$id     = (int) diluxone_users_option( $option );
	$choose = diluxone_users_admin_url( $screen );

	if ( $id <= 0 ) {
		return diluxone_users_check( $label, 'pending', '', __( 'No page chosen yet.', 'diluxone-users' ), $choose, __( 'Choose it →', 'diluxone-users' ) );
	}

	$page = get_post( $id );

	if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
		return diluxone_users_check( $label, 'off', __( 'The chosen page no longer exists.', 'diluxone-users' ), '', $choose, __( 'Choose another →', 'diluxone-users' ) );
	}

	if ( 'publish' !== $page->post_status ) {
		return diluxone_users_check(
			$label,
			'off',
			sprintf(
				/* translators: %s: title of the page */
				__( '“%s” is not published, so nobody can reach it.', 'diluxone-users' ),
				$page->post_title
			),
			'',
			(string) get_edit_post_link( $page, 'raw' ),
			__( 'Edit the page →', 'diluxone-users' )
		);
	}

	$draws = has_shortcode( (string) $page->post_content, $shortcode );

	/**
	 * Filters whether that page really draws this part of the plugin.
	 *
	 * The shortcode in the content is the ordinary way and the only one that
	 * can be checked from here. A site that draws it from a template — which
	 * is a supported way to use this plugin — answers true here and the check
	 * stops calling a working page broken.
	 *
	 * @param bool    $draws  Whether the shortcode was found in the content.
	 * @param string  $option The option holding the page ID.
	 * @param WP_Post $page   The page itself.
	 */
	$draws = (bool) apply_filters( 'diluxone_users_page_draws', $draws, $option, $page );

	if ( ! $draws ) {
		// Pending and not off: what can be said for certain is that the
		// shortcode is not in the content, and that is not the same as the
		// page being broken.
		return diluxone_users_check(
			$label,
			'pending',
			sprintf(
				/* translators: 1: title of the page, 2: the shortcode that was not found */
				__( '“%1$s” does not contain %2$s. If your theme draws it from a template, this is fine; if not, the page renders empty.', 'diluxone-users' ),
				$page->post_title,
				'[' . $shortcode . ']'
			),
			__( 'Shortcode not found.', 'diluxone-users' ),
			(string) get_edit_post_link( $page, 'raw' ),
			__( 'Edit the page →', 'diluxone-users' )
		);
	}

	return diluxone_users_check( $label, 'active', $page->post_title );
}

/**
 * Does mail go out?
 *
 * Only what the site has seen can be reported: the last message it tried to
 * send, and how that went. Before the first one there is nothing to go on,
 * and the honest answer is that nobody can tell — not "ready", which is a
 * guess dressed as a fact. The overview reuses this row, so the two screens
 * never disagree about the mail.
 *
 * @return array{label: string, state: string, detail: string, why: string, url: string, change?: string, word?: string}
 */
function diluxone_users_check_mail(): array {
	$mail  = diluxone_users_mail_status();
	$label = __( 'Outgoing mail', 'diluxone-users' );
	$test  = diluxone_users_admin_url( DILUXONE_USERS_STATUS, array( 'tab' => 'tools' ) );

	/*
	 * The words, and not the state's own: nobody turns outgoing mail off, so
	 * "Off" on this row reads as a setting somebody could go and put back.
	 * What it means is that the last message did not leave. The state, and so
	 * the colour, is the same one every other row speaks.
	 */
	if ( 'fail' === $mail['state'] ) {
		return diluxone_users_check(
			$label,
			'off',
			'' !== $mail['error']
				? wp_strip_all_tags( $mail['error'] )
				: __( 'The last message could not be sent.', 'diluxone-users' ),
			'',
			$test,
			__( 'Send a test →', 'diluxone-users' ),
			__( 'Failing', 'diluxone-users' )
		);
	}

	if ( 'ok' === $mail['state'] ) {
		return diluxone_users_check(
			$label,
			'active',
			sprintf(
				/* translators: %s: how long ago, e.g. "2 hours" */
				__( 'Last message sent %s ago.', 'diluxone-users' ),
				human_time_diff( $mail['time'] )
			),
			'',
			'',
			'',
			__( 'Going out', 'diluxone-users' )
		);
	}

	return diluxone_users_check(
		$label,
		'unknown',
		__( 'Nothing sent yet. A test is the only way to know.', 'diluxone-users' ),
		'',
		$test,
		__( 'Send a test →', 'diluxone-users' )
	);
}

/**
 * Every check, in order of how much it hurts.
 *
 * @return array<int, array{label: string, state: string, detail: string, why: string, url: string, change?: string, word?: string}>
 */
function diluxone_users_checks(): array {
	$checks = array();
	$tools  = diluxone_users_admin_url( DILUXONE_USERS_STATUS, array( 'tab' => 'tools' ) );

	$checks[] = diluxone_users_check_page( 'diluxone_users_account_page', 'diluxone_users_account', __( 'Account page', 'diluxone-users' ), 'diluxone-users-account' );
	$checks[] = diluxone_users_check_page( 'diluxone_users_login_page', 'diluxone_users_login', __( 'Sign-in page', 'diluxone-users' ), 'diluxone-users-login' );

	// Mail. Without it nobody comes in by link and nobody passes a second step.
	$checks[] = diluxone_users_check_mail();

	// HTTPS. Passkeys are WebAuthn, and WebAuthn does not exist outside HTTPS.
	//
	// wp_is_using_https() is what is looked at and not is_ssl(): is_ssl() says
	// whether *this* request arrived over TLS, which is false in WP-CLI and can
	// be false behind a proxy terminating TLS earlier. What decides whether the
	// browser speaks HTTPS — and therefore whether WebAuthn exists — is the
	// scheme of home_url().
	$passkeys = (int) diluxone_users_option( 'diluxone_users_passkey_enabled' );

	if ( wp_is_using_https() ) {
		$checks[] = diluxone_users_check( 'HTTPS', 'active', __( 'The site is served over HTTPS.', 'diluxone-users' ) );
	} elseif ( $passkeys ) {
		// Off and not pending: a door is advertised that nobody can open.
		$checks[] = diluxone_users_check(
			'HTTPS',
			'off',
			__( 'Passkeys are on, but WebAuthn does not work outside HTTPS: nobody can register or use one.', 'diluxone-users' )
		);
	} else {
		$checks[] = diluxone_users_check(
			'HTTPS',
			'pending',
			__( 'Passkeys will not work if you turn them on.', 'diluxone-users' ),
			__( 'The site is not served over HTTPS.', 'diluxone-users' )
		);
	}

	// Permalinks. The account area hangs off a rewrite rule.
	$permalinks = (string) get_option( 'permalink_structure' );

	$checks[] = '' === $permalinks
		? diluxone_users_check(
			__( 'Permalinks', 'diluxone-users' ),
			'off',
			__( 'Set to “Plain”. The account area needs pretty permalinks to route its sections.', 'diluxone-users' ),
			'',
			admin_url( 'options-permalink.php' )
		)
		: diluxone_users_check( __( 'Permalinks', 'diluxone-users' ), 'active', $permalinks );

	// The rewrite rules, which are rewritten when the version changes.
	$checks[] = diluxone_users_raw_get( 'diluxone_users_rewrite_version' ) === DILUXONE_USERS_VERSION
		? diluxone_users_check( __( 'Rewrite rules', 'diluxone-users' ), 'active', __( 'Up to date.', 'diluxone-users' ) )
		: diluxone_users_check(
			__( 'Rewrite rules', 'diluxone-users' ),
			'pending',
			__( 'Rebuild them from Tools if a section of the account area 404s.', 'diluxone-users' ),
			__( 'Not rewritten for this version yet.', 'diluxone-users' ),
			$tools,
			__( 'Rebuild them →', 'diluxone-users' )
		);

	// That there is at least one way in.
	$checks[] = diluxone_users_check_ways_in();

	// Social networks filled in but turned off, and the other way round.
	$social = diluxone_users_check_social();

	if ( null !== $social ) {
		$checks[] = $social;
	}

	return $checks;
}

/**
 * Is any door still open?
 *
 * The password can be turned off, no network configured and the e-mail link
 * left as the only way in. If the mail does not go out on top of that, nobody
 * comes in — not even the administrator — and the site is locked from the
 * inside.
 *
 * @return array{label: string, state: string, detail: string, why: string, url: string, change?: string, word?: string}
 */
function diluxone_users_check_ways_in(): array {
	$label  = __( 'Ways in', 'diluxone-users' );
	$access = diluxone_users_admin_url( 'diluxone-users-login' );
	$ways   = array();

	if ( diluxone_users_login_has_password() ) {
		$ways[] = __( 'password', 'diluxone-users' );
	}

	if ( diluxone_users_login_has_link() ) {
		$ways[] = __( 'e-mail link', 'diluxone-users' );
	}

	if ( array() !== diluxone_users_sso_available() ) {
		$ways[] = __( 'social login', 'diluxone-users' );
	}

	if ( array() === $ways ) {
		return diluxone_users_check( $label, 'off', __( 'No sign-in method is enabled at all.', 'diluxone-users' ), '', $access );
	}

	$email_only = array( __( 'e-mail link', 'diluxone-users' ) ) === $ways;

	if ( $email_only && ! diluxone_users_mail_works() ) {
		return diluxone_users_check(
			$label,
			'off',
			__( 'The e-mail link is the only way in, and mail is failing. Nobody can sign in.', 'diluxone-users' ),
			'',
			$access
		);
	}

	return diluxone_users_check( $label, 'active', implode( ', ', $ways ), '', $access, __( 'Access →', 'diluxone-users' ) );
}

/**
 * Providers with the credentials filled in but turned off.
 *
 * It is the dullest mistake and the most common: the ID and the secret are
 * filled in, it is saved, and the button does not appear because the provider
 * was never ticked.
 *
 * @return array{label: string, state: string, detail: string, why: string, url: string, change?: string, word?: string}|null
 */
function diluxone_users_check_social(): ?array {
	$ready   = array();
	$dormant = array();

	foreach ( diluxone_users_sso_providers() as $id => $provider ) {
		if ( ! diluxone_users_sso_configured( $id ) ) {
			continue;
		}

		$ready[] = $id;

		if ( ! diluxone_users_sso_ready( $id ) ) {
			$dormant[] = (string) ( $provider['name'] ?? $id );
		}
	}

	if ( array() === $ready ) {
		return null;
	}

	if ( array() === $dormant ) {
		return diluxone_users_check(
			__( 'Social login', 'diluxone-users' ),
			'active',
			sprintf(
				/* translators: %d: number of providers */
				_n( '%d provider configured and enabled.', '%d providers configured and enabled.', count( $ready ), 'diluxone-users' ),
				count( $ready )
			)
		);
	}

	return diluxone_users_check(
		__( 'Social login', 'diluxone-users' ),
		'pending',
		sprintf(
			/* translators: %s: comma-separated provider names */
			__( 'Credentials are saved but the provider is off, so no button shows: %s.', 'diluxone-users' ),
			implode( ', ', $dormant )
		),
		__( 'Provider off.', 'diluxone-users' ),
		diluxone_users_admin_url( 'diluxone-users-social' ),
		__( 'Turn it on →', 'diluxone-users' )
	);
}

/**
 * How many people use each thing.
 *
 * One query per number and against `usermeta`, which is indexed by
 * `meta_key`. On a site with twenty-five thousand accounts this has to cost
 * the same as on one with twenty.
 *
 * @return array<string, int>
 */
function diluxone_users_stats(): array {
	global $wpdb;

	// This site's people: whoever has a role here. On a network the meta is
	// the network's, and a site's numbers are about its own members.
	$members = $wpdb->get_blog_prefix() . 'capabilities';

	$count_meta = static function ( string $meta ) use ( $wpdb, $members ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this is the status screen: the number has to be the one from now, not the one from the cache.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT m.user_id) FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s WHERE m.meta_key = %s AND m.meta_value != ''", $members, $meta )
		);
	};

	// Social login is not one meta key but one per network, so it is counted
	// by prefix: what is being asked is how many people have any of them.
	$count_prefix = static function ( string $prefix ) use ( $wpdb, $members ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this is the status screen: the number has to be the one from now, not the one from the cache.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT m.user_id) FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s WHERE m.meta_key LIKE %s AND m.meta_value != ''", $members, $wpdb->esc_like( $prefix ) . '%' )
		);
	};

	return array(
		'users'    => (int) count_users()['total_users'],
		'totp'     => $count_meta( 'diluxone_users_totp' ),
		'passkeys' => $count_meta( 'diluxone_users_passkeys' ),
		'social'   => $count_prefix( 'diluxone_users_sso_' ),
		'handles'  => $count_meta( 'diluxone_users_handle' ),
	);
}

/**
 * What to paste into a support ticket.
 *
 * @return array<string, string>
 */
function diluxone_users_environment(): array {
	global $wp_version;

	return array(
		__( 'Plugin version', 'diluxone-users' )         => DILUXONE_USERS_VERSION,
		__( 'WordPress', 'diluxone-users' )              => (string) $wp_version,
		'PHP'                                            => PHP_VERSION,
		__( 'Site language', 'diluxone-users' )          => (string) get_locale(),
		__( 'Multisite', 'diluxone-users' )              => is_multisite() ? __( 'yes', 'diluxone-users' ) : __( 'no', 'diluxone-users' ),
		__( 'Object cache', 'diluxone-users' )           => wp_using_ext_object_cache() ? __( 'external', 'diluxone-users' ) : __( 'none', 'diluxone-users' ),
		__( 'Dashboard profile rule', 'diluxone-users' ) => (string) diluxone_users_option( 'diluxone_users_wp_profile' ),
	);
}

/** The maintenance screen: its tabs come from the registry. */
function diluxone_users_screen_status(): void {
	diluxone_users_screen_panels( DILUXONE_USERS_STATUS, diluxone_users_screens()[ DILUXONE_USERS_STATUS ] );
}

/** The Status tab: the checks, the numbers, the environment. */
function diluxone_users_screen_status_checks(): void {
	diluxone_users_intro( __( 'Nothing here changes anything: it looks and it counts. What needs fixing gets fixed on the Tools tab.', 'diluxone-users' ) );

	$checks = diluxone_users_checks();

	// Only what is off is counted. A pending row says what it waits on and
	// the site works meanwhile; an unknown one is a question, not a fault.
	$bad = count( array_filter( $checks, static fn( array $check ): bool => 'off' === $check['state'] ) );

	if ( 0 === $bad ) {
		diluxone_users_notice( __( 'Everything checks out.', 'diluxone-users' ) );
	} else {
		diluxone_users_notice(
			sprintf(
				/* translators: %d: number of failing checks */
				_n( '%d thing needs attention.', '%d things need attention.', $bad, 'diluxone-users' ),
				$bad
			),
			'error'
		);
	}

	diluxone_users_summary_table( $checks );

	$stats = diluxone_users_stats();

	diluxone_users_ui_section( __( 'Usage', 'diluxone-users' ) );
	echo '<table class="widefat striped diluxone-users-summary"><tbody>';

	$rows = array(
		__( 'Accounts', 'diluxone-users' )           => $stats['users'],
		__( 'With an authenticator app', 'diluxone-users' ) => $stats['totp'],
		__( 'With a passkey', 'diluxone-users' )     => $stats['passkeys'],
		__( 'With a social account linked', 'diluxone-users' ) => $stats['social'],
		__( 'With a public name', 'diluxone-users' ) => $stats['handles'],
	);

	foreach ( $rows as $label => $value ) {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( (string) $label ),
			esc_html( number_format_i18n( $value ) )
		);
	}

	echo '</tbody></table>';

	diluxone_users_ui_section(
		__( 'Environment', 'diluxone-users' ),
		__( 'Copy this into a support message and half the back-and-forth disappears.', 'diluxone-users' )
	);

	echo '<table class="widefat striped diluxone-users-summary"><tbody>';

	foreach ( diluxone_users_environment() as $label => $value ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><code>%2$s</code></td></tr>',
			esc_html( (string) $label ),
			esc_html( $value )
		);
	}

	echo '</tbody></table>';

	/*
	 * The templates a theme can replace. It used to sit among the colour
	 * settings, where it was the only row that changed nothing: it is
	 * documentation for whoever writes the theme, and this is the screen that
	 * gets pasted into a support message.
	 */
	diluxone_users_ui_section(
		__( 'Templates a theme can replace', 'diluxone-users' ),
		__( 'Copy any file from the plugin’s templates/ folder into the folder below and edit it there. The plugin will use yours.', 'diluxone-users' )
	);

	printf( '<p><code>%s</code></p>', esc_html( 'wp-content/themes/' . get_stylesheet() . '/diluxone-users/' ) );

	echo '<p class="description">';

	$diluxone_users_files = array( 'account.php', 'account-nav.php', 'account/*.php', 'login.php', 'login-2fa.php', 'fields.php', 'accounts.php', 'sessions.php' );

	echo wp_kses_post( '<code>' . implode( '</code> · <code>', $diluxone_users_files ) . '</code>' );

	echo '</p>';
}

/**
 * The way back in when there is no way back in.
 *
 * On a site without passwords and without outgoing mail, an expired session
 * leaves the administrator outside with the rest. Two doors survive that:
 * the command line, which prints a link without needing the mail, and the
 * native form, which never went away for administrators.
 */
function diluxone_users_screen_status_lockout(): void {
	$screens = diluxone_users_screens();

	diluxone_users_intro( __( 'On a site without passwords and without outgoing mail, an expired session leaves you outside. There are two ways back that do not depend on either.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'From the server', 'diluxone-users' ),
		__( 'That prints a single-use link and does not need the mail to work. It is WP-CLI, so it needs a shell on the server.', 'diluxone-users' )
	);

	printf( '<p><code>%s</code></p>', esc_html( 'wp diluxone-users login ' . wp_get_current_user()->user_email ) );

	diluxone_users_ui_section(
		__( 'From the browser', 'diluxone-users' ),
		__( 'That address shows the WordPress form with a username and a password, whatever the site is set to. It is no secret: the password is still what keeps the door shut, so it opens only for an account that has one.', 'diluxone-users' )
	);

	printf( '<p><code>%s</code></p>', esc_html( wp_login_url() . '?diluxone-users-admin=1' ) );

	diluxone_users_panel_actions(
		array(
			$screens['diluxone-users-login'] => diluxone_users_admin_url( 'diluxone-users-login' ),
			__( 'Tools', 'diluxone-users' )  => diluxone_users_admin_url( DILUXONE_USERS_STATUS, array( 'tab' => 'tools' ) ),
		)
	);
}
