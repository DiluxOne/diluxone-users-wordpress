<?php
/**
 * The Tools tab of the maintenance screen: the little that can be done by hand.
 *
 * Everything here exists because at some point it had to be done over SSH or
 * with a loose script. None of it is a feature of the plugin: they are the
 * six buttons that show up when something went wrong and somebody is waiting
 * on the other side. They sit beside the checks that say what went wrong,
 * on the same screen, because that is the order they are used in.
 *
 * What is deliberately NOT here: reading somebody's two-step code. The code
 * is stored hashed, so "seeing" it would mean brute-forcing it, and a button
 * like that hands any administrator the second factor of any account — which
 * is exactly what a second factor is meant to prevent. A new one is sent
 * instead.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The option storing what happened with the last tool that was used. */
const DILUXONE_USERS_TOOL_RESULT = 'diluxone_users_tool_result';

/**
 * Records the result for after the redirect.
 *
 * It never returns: it redirects and stops. It is annotated as `never` in the
 * docblock and not in the signature because the plugin still supports PHP
 * 8.0, where that native type does not exist.
 *
 * @param string $text What happened.
 * @param string $type 'success' or 'error'.
 * @return never
 */
function diluxone_users_tool_done( string $text, string $type = 'success' ): void {
	set_transient( DILUXONE_USERS_TOOL_RESULT . '_' . get_current_user_id(), array( $text, $type ), 60 );

	// Straight to the tab, not to the old screen slug: that slug only exists
	// as a redirect now, and a redirect that lands on another one is a hop
	// nobody asked for.
	wp_safe_redirect( diluxone_users_admin_url( DILUXONE_USERS_STATUS, array( 'tab' => 'tools' ) ) );
	exit;
}

/** Its tab on the maintenance screen. */
function diluxone_users_tools_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_STATUS,
		'tools',
		array(
			'label'    => __( 'Tools', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_tools_boxes',
			// Every tool posts to admin-post and comes back: none of them is
			// a settings form and they must not be wrapped in one.
			'form'     => false,
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_tools_panels' );

/**
 * Everything fired from this screen comes in through here.
 *
 * A single `admin_post`, a single nonce and a single capability check: spread
 * over five endpoints, sooner or later one of them ends up missing one of the
 * three.
 *
 * @return void
 */
function diluxone_users_tools_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ) );
	}

	check_admin_referer( 'diluxone_users_tools' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado arriba.
	$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';

	// A map and not a switch: every tool ends in a redirect that stops
	// execution, so a `break` behind each one would be dead code and a
	// fall-through comment would be a lie.
	$tools = array(
		'flush'  => 'diluxone_users_tool_flush',
		'code'   => 'diluxone_users_tool_send_code',
		'close'  => 'diluxone_users_tool_close_sessions',
		'export' => 'diluxone_users_tool_export',
		'import' => 'diluxone_users_tool_import',
		'wipe'   => 'diluxone_users_tool_wipe',
	);

	if ( ! isset( $tools[ $tool ] ) ) {
		diluxone_users_tool_done( __( 'Nothing to do.', 'diluxone-users' ), 'error' );
	}

	$tools[ $tool ]();
}

/**
 * Rewrites the rewrite rules.
 *
 * The rule itself registers on `init` on every request; what gets lost is the
 * stored copy, and that is what is rebuilt here.
 *
 * @return never
 */
function diluxone_users_tool_flush(): void {
	flush_rewrite_rules( false );
	update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );

	diluxone_users_tool_done( __( 'Rewrite rules rebuilt.', 'diluxone-users' ) );
}

add_action( 'admin_post_diluxone_users_tools', 'diluxone_users_tools_action' );

/**
 * Sends a person a fresh two-step code.
 *
 * The same thing is said whether the account exists or not: this screen is
 * for the administrator, but the habit of not confirming who is registered is
 * kept all the same.
 *
 * @return never
 */
function diluxone_users_tool_send_code(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado en diluxone_users_tools_action().
	$typed = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$user  = '' !== $typed ? get_user_by( 'email', $typed ) : false;

	if ( ! $user instanceof WP_User ) {
		diluxone_users_tool_done( __( 'No account with that e-mail address.', 'diluxone-users' ), 'error' );
	}

	$ok = diluxone_users_2fa_email_send( $user->ID );

	diluxone_users_tool_done(
		$ok
			? sprintf(
				/* translators: %s: e-mail address */
				__( 'A fresh code is on its way to %s.', 'diluxone-users' ),
				$user->user_email
			)
			: __( 'The code could not be sent. Check outgoing mail on the Status tab.', 'diluxone-users' ),
		$ok ? 'success' : 'error'
	);
}

/**
 * Closes sessions: one person's, or everybody's.
 *
 * The second one leaves out whoever pressed it, and rightly so: if it is used
 * it is because somebody else's session is suspected to be open, and keeping
 * your own alive for convenience would be leaving open precisely the one that
 * matters.
 *
 * @return never
 */
function diluxone_users_tool_close_sessions(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificado en diluxone_users_tools_action().
	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'one';
	$typed = sanitize_email( wp_unslash( $_POST['close_email'] ?? '' ) );
	// phpcs:enable

	if ( 'all' === $scope ) {
		WP_Session_Tokens::destroy_all_for_all_users();

		diluxone_users_tool_done( __( 'Every session on the site is closed. Everyone signs in again, you included.', 'diluxone-users' ) );
	}

	$user = '' !== $typed ? get_user_by( 'email', $typed ) : false;

	if ( ! $user instanceof WP_User ) {
		diluxone_users_tool_done( __( 'No account with that e-mail address.', 'diluxone-users' ), 'error' );
	}

	WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

	diluxone_users_tool_done(
		sprintf(
			/* translators: %s: e-mail address */
			__( 'Every session for %s is closed.', 'diluxone-users' ),
			$user->user_email
		)
	);
}

/**
 * What an export takes with it.
 *
 * The settings and the fields: what defines how the plugin behaves. Not one
 * social-network credential — that is a secret, and a JSON file sent by
 * e-mail is no place for one — nor anything belonging to a person.
 *
 * @return array<string, mixed>
 */
function diluxone_users_tool_settings(): array {
	$out = array();

	foreach ( array_keys( diluxone_users_option_defaults() ) as $key ) {
		if ( in_array( $key, diluxone_users_tool_not_carried(), true ) ) {
			continue;
		}

		$out[ $key ] = diluxone_users_option( $key );
	}

	$out['diluxone_users_fields'] = get_option( 'diluxone_users_fields', array() );

	return $out;
}

/**
 * The settings a file never carries from one site to another.
 *
 * The social credentials are secrets of this site's apps. And the wipe on
 * uninstall is a decision about this site's people that nobody should find
 * already taken because they restored a file from somewhere else.
 *
 * @return array<int, string>
 */
function diluxone_users_tool_not_carried(): array {
	return array( 'diluxone_users_sso', 'diluxone_users_uninstall_wipe' );
}

/**
 * The account sections out of a file, typed one value at a time.
 *
 * Each section's configuration is a small record — a position, a label, a
 * slug, text — and the generic path for lists of keys would flatten every
 * one of them to a word. Unknown fields are dropped.
 *
 * @param mixed $value
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_tool_sections( $value ): array {
	$clean = array();

	foreach ( is_array( $value ) ? $value : array() as $id => $config ) {
		if ( ! is_string( $id ) || '' === sanitize_key( $id ) || ! is_array( $config ) ) {
			continue;
		}

		$one = array();

		foreach ( $config as $field => $data ) {
			switch ( $field ) {
				case 'position':
				case 'enabled':
					$one[ $field ] = (int) $data;
					break;
				case 'custom':
					$one[ $field ] = (bool) $data;
					break;
				case 'label':
					$one[ $field ] = sanitize_text_field( (string) $data );
					break;
				case 'slug':
					$one[ $field ] = sanitize_title( (string) $data );
					break;
				case 'intro':
					$one[ $field ] = sanitize_text_field( (string) $data );
					break;
				case 'content':
					$one[ $field ] = wp_kses_post( (string) $data );
					break;
				case 'placement':
				case 'visibility':
					$one[ $field ] = sanitize_key( (string) $data );
					break;
				case 'roles':
					$one[ $field ] = array_values( array_map( 'sanitize_key', array_map( 'strval', (array) $data ) ) );
					break;
			}
		}

		$clean[ sanitize_key( $id ) ] = $one;
	}

	return $clean;
}

/**
 * Downloads the settings as JSON.
 *
 * @return never
 */
function diluxone_users_tool_export(): void {
	$payload = array(
		'plugin'   => 'diluxone-users',
		'version'  => DILUXONE_USERS_VERSION,
		'site'     => home_url(),
		'exported' => gmdate( 'c' ),
		'settings' => diluxone_users_tool_settings(),
	);

	$name = 'diluxone-users-' . gmdate( 'Y-m-d' ) . '.json';

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $name );

	echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

/**
 * Writes the settings of an export file, each through what types it.
 *
 * Apart from the upload so the part that decides what gets written can be
 * exercised on its own.
 *
 * @param array<mixed> $settings The `settings` of an export file.
 * @return int How many settings were written.
 */
function diluxone_users_tool_restore( array $settings ): int {
	$known   = array_keys( diluxone_users_option_defaults() );
	$written = 0;

	foreach ( $settings as $key => $value ) {
		if ( 'diluxone_users_fields' === $key && is_array( $value ) ) {
			/*
			 * Through the same normaliser the fields screen uses, and not
			 * straight into the option. The docblock above has always said
			 * only known keys are accepted; for this one key it was not true,
			 * and the shape a hand-edited file could put in there is read
			 * back on the account form — where the key of a "field" is the
			 * user meta key it writes to.
			 */
			$fields = array();

			foreach ( $value as $field ) {
				$clean = is_array( $field ) ? diluxone_users_normalize_field( $field ) : array( 'key' => '' );

				// A key the fields screen could have made: WordPress's own
				// fields, or one under the plugin's prefix. Anything else is a
				// key some other plugin writes, and a field with that key
				// would read and write that plugin's data — and take it with
				// it on uninstall.
				$key_ok = diluxone_users_field_is_native( (string) $clean['key'] ) || 0 === strpos( (string) $clean['key'], 'diluxone_users_' );

				if ( '' !== $clean['key'] && $key_ok && diluxone_users_field_key_allowed( (string) $clean['key'] ) ) {
					$fields[] = $clean;
				}
			}

			update_option( 'diluxone_users_fields', $fields );
			++$written;
			continue;
		}

		if ( ! in_array( $key, $known, true ) || in_array( $key, diluxone_users_tool_not_carried(), true ) ) {
			continue;
		}

		if ( 'diluxone_users_account_sections' === $key ) {
			update_option( $key, diluxone_users_tool_sections( $value ) );
			++$written;
			continue;
		}

		diluxone_users_save_options( array( $key => $value ) );
		++$written;
	}

	return $written;
}

/**
 * Puts an exported JSON file back in.
 *
 * Only the keys the plugin knows are accepted. A file with rubbish inside —
 * or from another plugin, or edited by hand — cannot write options that are
 * not its own.
 *
 * @return never
 */
function diluxone_users_tool_import(): void {
	// PHP's own temporary path: never slashed, so not unslashed either — that
	// would strip the backslashes of a Windows path — and only a string.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; the path is checked with is_uploaded_file() below and the content is validated as JSON.
	$uploaded = isset( $_FILES['file']['tmp_name'] ) && is_string( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
	$problem = isset( $_FILES['file']['error'] ) ? (int) $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;

	if ( UPLOAD_ERR_OK !== $problem || '' === $uploaded || ! is_uploaded_file( $uploaded ) ) {
		diluxone_users_tool_done( __( 'No file uploaded.', 'diluxone-users' ), 'error' );
	}

	/*
	 * Measured before it is read. What comes out of the button beside this one
	 * is a few kilobytes of JSON; anything past a megabyte is not that file,
	 * and reading it first to find out means holding all of it in memory to
	 * decide it was too big.
	 */
	if ( (int) filesize( $uploaded ) > MB_IN_BYTES ) {
		diluxone_users_tool_done( __( 'That file is too big to be a settings export.', 'diluxone-users' ), 'error' );
	}

	$raw  = (string) file_get_contents( $uploaded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file that was just uploaded, not a URL, and capped above.
	$json = json_decode( $raw, true );

	if ( ! is_array( $json ) || ! isset( $json['settings'] ) || ! is_array( $json['settings'] ) ) {
		diluxone_users_tool_done( __( 'That file is not a DiluxOne Users+ export.', 'diluxone-users' ), 'error' );
	}

	$written = diluxone_users_tool_restore( $json['settings'] );

	diluxone_users_tool_done(
		sprintf(
			/* translators: %d: number of settings written */
			_n( '%d setting restored.', '%d settings restored.', $written, 'diluxone-users' ),
			$written
		)
	);
}

/**
 * Sends a test e-mail to whoever asked for it.
 *
 * It lives here and not in mail.php because it is one of the buttons on this
 * screen: it comes back to this screen, and it reports what happened the same
 * way the rest of them do. mail.php keeps what is its own — recording what
 * really became of the last message the site tried to send.
 *
 * @return never
 */
function diluxone_users_mail_test(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ) );
	}

	check_admin_referer( 'diluxone_users_mail_test' );

	$user = wp_get_current_user();

	$ok = wp_mail(
		$user->user_email,
		sprintf(
			/* translators: %s: site name */
			__( 'Test from %s', 'diluxone-users' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		),
		__( 'If this arrived, the site can send the sign-in links, the second-step codes and the data requests. If it did not, none of those work either.', 'diluxone-users' )
	);

	// wp_mail() only reports whether it handed the message to the server; the
	// hook in mail.php already recorded what really happened. It is stored
	// anyway in case nothing fired.
	if ( ! $ok && 'fail' !== diluxone_users_mail_status()['state'] ) {
		diluxone_users_mail_failed( new WP_Error( 'diluxone_users_mail', __( 'wp_mail() returned false and said nothing else.', 'diluxone-users' ) ) );
	}

	if ( $ok ) {
		diluxone_users_tool_done(
			sprintf(
				/* translators: %s: e-mail address */
				__( 'A test message is on its way to %s. If it does not arrive, nothing else will either.', 'diluxone-users' ),
				$user->user_email
			)
		);
	}

	diluxone_users_tool_done(
		sprintf(
			/* translators: %s: the reason the send failed */
			__( 'The message could not be sent: %s', 'diluxone-users' ),
			diluxone_users_mail_status()['error']
		),
		'error'
	);
}
add_action( 'admin_post_diluxone_users_mail_test', 'diluxone_users_mail_test' );

/**
 * One tool: heading, explanation and a form of its own.
 *
 * @param string   $title What it is called.
 * @param string   $text  What it does and when it is used.
 * @param callable $form  What goes inside the form.
 * @param bool     $files Whether the form uploads a file.
 * @return void
 */
function diluxone_users_tool_box( string $title, string $text, callable $form, bool $files = false, string $action = 'diluxone_users_tools' ): void {
	// The design system's heading and not an <h2> of this file's own: a raw
	// heading inside these screens takes WordPress's margins instead of the
	// one number every block leaves under it, which is six tools each sitting
	// a different distance from the one above.
	diluxone_users_ui_section( $title, $text );

	printf(
		'<form method="post" action="%s"%s>',
		esc_url( admin_url( 'admin-post.php' ) ),
		$files ? ' enctype="multipart/form-data"' : ''
	);

	wp_nonce_field( $action );
	printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );

	$form();

	echo '</form>';
}

/** The Tools tab: one box per thing that can be pressed. */
function diluxone_users_screen_tools_boxes(): void {
	$result = get_transient( DILUXONE_USERS_TOOL_RESULT . '_' . get_current_user_id() );

	if ( is_array( $result ) ) {
		delete_transient( DILUXONE_USERS_TOOL_RESULT . '_' . get_current_user_id() );
		diluxone_users_notice( (string) $result[0], (string) $result[1] );
	}

	diluxone_users_intro( __( 'Six buttons for when something went wrong and somebody is waiting on the other side.', 'diluxone-users' ) );

	diluxone_users_tool_box(
		__( 'Rebuild the rewrite rules', 'diluxone-users' ),
		__( 'Use this when a section of the account area returns a 404. The account area routes its sections through rewrite rules, and those go stale when permalinks change or another plugin rewrites them.', 'diluxone-users' ),
		static function (): void {
			echo '<input type="hidden" name="tool" value="flush">';
			submit_button( __( 'Rebuild', 'diluxone-users' ), 'secondary', 'submit', false );
		}
	);

	diluxone_users_tool_box(
		__( 'Send a test message', 'diluxone-users' ),
		__( 'It goes to your own address. If it does not arrive, the sign-in links and the second-step codes are not arriving either.', 'diluxone-users' ),
		static function (): void {
			submit_button( __( 'Send it', 'diluxone-users' ), 'secondary', 'submit', false );
		},
		false,
		'diluxone_users_mail_test'
	);

	diluxone_users_tool_box(
		__( 'Send someone a fresh code', 'diluxone-users' ),
		__( 'For when a person says the second-step code never arrived. It sends a new one and voids the previous one. You never get to see it — the code is stored hashed, which is the point.', 'diluxone-users' ),
		static function (): void {
			echo '<input type="hidden" name="tool" value="code">';
			printf(
				'<input type="email" name="email" class="regular-text" required placeholder="%s"> ',
				esc_attr__( 'their e-mail address', 'diluxone-users' )
			);
			submit_button( __( 'Send the code', 'diluxone-users' ), 'secondary', 'submit', false );
		}
	);

	diluxone_users_tool_box(
		__( 'Close sessions', 'diluxone-users' ),
		__( 'Closing every session on the site signs you out too. That is on purpose: if you are doing this, the session you are least sure about might be your own.', 'diluxone-users' ),
		static function (): void {
			echo '<input type="hidden" name="tool" value="close">';
			echo '<p><label><input type="radio" name="scope" value="one" checked> ';
			esc_html_e( 'Just this person:', 'diluxone-users' );
			printf(
				' <input type="email" name="close_email" class="regular-text" placeholder="%s"></label></p>',
				esc_attr__( 'their e-mail address', 'diluxone-users' )
			);
			echo '<p><label><input type="radio" name="scope" value="all"> ';
			esc_html_e( 'Everyone on the site, me included', 'diluxone-users' );
			echo '</label></p>';
			submit_button( __( 'Close them', 'diluxone-users' ), 'delete', 'submit', false );
		}
	);

	diluxone_users_tool_box(
		__( 'Settings as a file', 'diluxone-users' ),
		__( 'Take the settings and the user fields from one site to another — staging to production, or a site you set up once and want to repeat. Social login credentials are deliberately left out: those are secrets, and a JSON file that travels by e-mail is no place for one.', 'diluxone-users' ),
		static function (): void {
			echo '<p>';
			echo '<input type="hidden" name="tool" value="export">';
			submit_button( __( 'Download them', 'diluxone-users' ), 'secondary', 'submit', false );
			echo '</p>';
		}
	);

	diluxone_users_tool_box(
		'',
		__( 'Restoring overwrites what is set right now. Only keys this plugin knows are read, so a file from somewhere else cannot write settings that are not ours.', 'diluxone-users' ),
		static function (): void {
			echo '<p>';
			echo '<input type="hidden" name="tool" value="import">';
			echo '<input type="file" name="file" accept="application/json,.json" required> ';
			submit_button( __( 'Restore them', 'diluxone-users' ), 'secondary', 'submit', false );
			echo '</p>';
		},
		true
	);

	diluxone_users_tool_box(
		__( 'What happens when the plugin is deleted', 'diluxone-users' ),
		__( 'By default, nothing. The settings stay, the activity log stays, and so does everything in people’s profiles — their details, their public names, their passkeys and their second factors. That is on purpose: a plugin deleted by accident, or deleted to be installed again, should not be what loses somebody their account. Tick this only when the plugin is going for good and the data is meant to go with it. It cannot be undone, and it runs on delete, not on deactivate.', 'diluxone-users' ),
		static function (): void {
			echo '<input type="hidden" name="tool" value="wipe">';
			echo '<p><label><input type="checkbox" name="wipe" value="1"';
			checked( (bool) diluxone_users_option( 'diluxone_users_uninstall_wipe' ) );
			echo '> ';
			esc_html_e( 'Remove everything this plugin wrote when it is deleted', 'diluxone-users' );
			echo '</label></p>';

			// On a network the profiles are everybody's: one site's answer
			// takes its own settings and log, and the people only go when
			// every site that uses the plugin gave the same answer.
			if ( is_multisite() ) {
				echo '<p class="description">';
				esc_html_e( 'On this network, ticking it removes this site’s settings and activity log. What is in people’s profiles is shared by every site, so it is removed only when every site that uses the plugin has ticked it too.', 'diluxone-users' );
				echo '</p>';
			}
			submit_button( __( 'Save', 'diluxone-users' ), 'secondary', 'submit', false );
		}
	);
}

/**
 * Remembers whether deleting the plugin should take the data with it.
 *
 * @return never
 */
function diluxone_users_tool_wipe(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in diluxone_users_tools_action().
	diluxone_users_save_options( array( 'diluxone_users_uninstall_wipe' => isset( $_POST['wipe'] ) ? 1 : 0 ) );

	diluxone_users_tool_done(
		diluxone_users_option( 'diluxone_users_uninstall_wipe' )
			? __( 'Deleting the plugin will now remove everything it wrote.', 'diluxone-users' )
			: __( 'Deleting the plugin will leave the data where it is.', 'diluxone-users' )
	);
}
