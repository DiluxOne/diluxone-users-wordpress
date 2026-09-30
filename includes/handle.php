<?php
/**
 * The public name.
 *
 * They are three different things WordPress mixes together and that are worth
 * keeping apart:
 *
 *   - `user_login` is the e-mail, it is the identity, and WordPress never
 *     lets it be changed. It is neither chosen nor shown.
 *   - `user_nicename` is what goes in the profile URL.
 *   - `display_name` is the name a person appears under.
 *
 * The public name here is the second one, and it is stored as `nickname`
 * along the way. That way somebody can choose how they are seen and at which
 * address they are found, without any of it touching how they get in: the
 * link always goes to the account's e-mail.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Names that cannot be asked for: they would be mistaken for parts of the site.
 *
 * @return array<int, string>
 */
function diluxone_users_handle_reserved(): array {
	$base = array(
		'admin', 'administrator', 'administrador', 'root', 'sistema', 'system',
		'soporte', 'support', 'ayuda', 'help', 'api', 'wp-admin', 'wp-login',
		'login', 'logout', 'registro', 'register', 'cuenta', 'account', 'perfil',
		'profile', 'usuario', 'user', 'usuarios', 'users', 'null', 'undefined',
	);

	$extra = preg_split( '/[\s,]+/', (string) diluxone_users_option( 'diluxone_users_handle_reserved' ), -1, PREG_SPLIT_NO_EMPTY );

	return array_values( array_unique( array_map( 'sanitize_title', array_merge( $base, is_array( $extra ) ? $extra : array() ) ) ) );
}

/**
 * What a name looks like after being put through the rules.
 *
 * It is the same function WordPress builds a `user_nicename` with, so what it
 * returns is exactly what will end up in the address. It is used to show it
 * before saving: nobody should have to guess what their typing turns into.
 */
function diluxone_users_handle_clean( string $handle ): string {
	return 'unicode' === diluxone_users_option( 'diluxone_users_handle_charset' )
		? sanitize_title( $handle, '', 'save' )
		: sanitize_title( remove_accents( $handle ) );
}

/** Somebody's public name. Empty when they never chose one. */
function diluxone_users_handle( int $user_id ): string {
	return (string) get_user_meta( $user_id, 'diluxone_users_handle', true );
}

/**
 * The public name as the site shows it: the one they chose, or else the one
 * WordPress gave the account (its nicename), which is what the profile
 * address already uses. Empty only for an account that has neither.
 */
function diluxone_users_public_handle( int $user_id ): string {
	$chosen = diluxone_users_handle( $user_id );
	if ( '' !== $chosen ) {
		return $chosen;
	}
	$user = get_userdata( $user_id );

	return $user instanceof WP_User ? (string) $user->user_nicename : '';
}

/** When they last changed it. 0 when never. */
function diluxone_users_handle_changed( int $user_id ): int {
	return (int) get_user_meta( $user_id, 'diluxone_users_handle_changed', true );
}

/** Can they change it today, or are they still waiting? */
function diluxone_users_handle_can_change( int $user_id ): bool {
	$days = (int) diluxone_users_option( 'diluxone_users_handle_cooldown' );
	$last = diluxone_users_handle_changed( $user_id );

	return $days <= 0 || 0 === $last || ( time() - $last ) >= $days * DAY_IN_SECONDS;
}

/** When they will be able to change it. 0 when they already can. */
function diluxone_users_handle_next_change( int $user_id ): int {
	if ( diluxone_users_handle_can_change( $user_id ) ) {
		return 0;
	}

	return diluxone_users_handle_changed( $user_id ) + (int) diluxone_users_option( 'diluxone_users_handle_cooldown' ) * DAY_IN_SECONDS;
}

/**
 * Checks a public name.
 *
 * Returns the sanitised name, or a WP_Error with the reason. The reasons are
 * told one at a time and in words: "that will not do" tells nobody what to fix.
 *
 * @return string|WP_Error
 */
function diluxone_users_handle_validate( string $handle, int $user_id ) {
	$handle = trim( $handle );
	$min    = max( 1, (int) diluxone_users_option( 'diluxone_users_handle_min' ) );
	$max    = max( $min, (int) diluxone_users_option( 'diluxone_users_handle_max' ) );

	$clean = diluxone_users_handle_clean( $handle );

	if ( '' === $clean ) {
		return new WP_Error( 'diluxone_users_handle_empty', __( 'You have to write something.', 'diluxone-users' ) );
	}

	// Spaces cannot stay: an address does not have them. Either they are
	// turned into hyphens without a word — which is what nearly everybody
	// expects — or it says so, so nobody ends up with a name they did not type.
	if ( 'reject' === diluxone_users_option( 'diluxone_users_handle_spaces' ) && preg_match( '/\s/', $handle ) ) {
		return new WP_Error( 'diluxone_users_handle_spaces', __( 'It cannot have spaces: this goes in a web address.', 'diluxone-users' ) );
	}

	if ( is_email( $handle ) ) {
		return new WP_Error( 'diluxone_users_handle_email', __( 'It cannot be an email address: that is how you sign in, not how people see you.', 'diluxone-users' ) );
	}

	if ( mb_strlen( $clean ) < $min ) {
		return new WP_Error(
			'diluxone_users_handle_short',
			sprintf(
					/* translators: %d: minimum number of characters */
				__( 'It is too short: at least %d characters.', 'diluxone-users' ),
				$min
			)
		);
	}

	if ( mb_strlen( $clean ) > $max ) {
		return new WP_Error(
			'diluxone_users_handle_long',
			sprintf(
					/* translators: %d: maximum number of characters */
				__( 'It is too long: at most %d characters.', 'diluxone-users' ),
				$max
			)
		);
	}

	if ( in_array( $clean, diluxone_users_handle_reserved(), true ) ) {
		return new WP_Error( 'diluxone_users_handle_reserved', __( 'That one is taken by the site itself. Pick another.', 'diluxone-users' ) );
	}

	if ( diluxone_users_handle_taken( $clean, $user_id ) ) {
		return new WP_Error( 'diluxone_users_handle_taken', __( 'Somebody already has that one.', 'diluxone-users' ) );
	}

	return $clean;
}

/**
 * Does somebody already have it?
 *
 * The lookup is against `user_nicename` and also against `user_login`. The
 * second one looks superfluous and is not: accounts made elsewhere — by the
 * site before this plugin, or by another plugin — can have a user_login that
 * is a person's name, and if signing in by typing the public name is also
 * allowed, two different people answering to the same text makes the sign-in
 * link go to the wrong account.
 */
function diluxone_users_handle_taken( string $handle, int $user_id ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no API for searching against two columns of wp_users, and a cached answer here would say a name is free when it no longer is.
	$found = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->users} WHERE ( user_nicename = %s OR user_login = %s ) AND ID <> %d LIMIT 1",
			$handle,
			$handle,
			$user_id
		)
	);

	return null !== $found;
}

/**
 * Saves the public name.
 *
 * @return true|WP_Error
 */
function diluxone_users_handle_save( int $user_id, string $handle ) {
	if ( diluxone_users_handle( $user_id ) === $handle ) {
		return true;
	}

	if ( ! diluxone_users_handle_can_change( $user_id ) ) {
		return new WP_Error(
			'diluxone_users_handle_cooldown',
			sprintf(
					/* translators: %s: date from which it can be changed */
				__( 'You can change it again on %s.', 'diluxone-users' ),
				wp_date( 'j M Y', diluxone_users_handle_next_change( $user_id ) )
			)
		);
	}

	$clean = diluxone_users_handle_validate( $handle, $user_id );

	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	$updated = wp_update_user(
		array(
			'ID'            => $user_id,
			'user_nicename' => $clean,
			'nickname'      => $clean,
		)
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	update_user_meta( $user_id, 'diluxone_users_handle', $clean );
	update_user_meta( $user_id, 'diluxone_users_handle_changed', time() );

	return true;
}

/** Who answers to this public name. 0 when nobody. */
function diluxone_users_handle_user( string $handle ): int {
	global $wpdb;

	$clean = sanitize_title( remove_accents( $handle ) );

	if ( '' === $clean ) {
		return 0;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same again: two columns, and it is the query that decides who a sign-in link goes to.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s OR user_login = %s LIMIT 1",
			$clean,
			$clean
		)
	);
}

/* ── The form ──────────────────────────────────────────────────────── */

/**
 * The field alone, with no form.
 *
 * A site that already has a personal-details form does not want another form
 * beside it with a button of its own: it wants the field inside its form and
 * a single "Save". That is this.
 */
function diluxone_users_handle_field( ?int $user_id = null ): string {
	$user_id = $user_id ?? get_current_user_id();

	if ( $user_id <= 0 || ! diluxone_users_option( 'diluxone_users_handle_enabled' ) ) {
		return '';
	}

	diluxone_users_handle_enqueue();

	return diluxone_users_render(
		'account/handle-field',
		array(
			'handle' => diluxone_users_public_handle( $user_id ),
			'can'    => diluxone_users_handle_can_change( $user_id ),
			'next'   => diluxone_users_handle_next_change( $user_id ),
		)
	);
}

/** The public-name field. Shortcode: [diluxone_users_handle] */
function diluxone_users_shortcode_handle(): string {
	if ( ! is_user_logged_in() || ! diluxone_users_option( 'diluxone_users_handle_enabled' ) ) {
		return '';
	}

	$user = wp_get_current_user();

	diluxone_users_handle_enqueue();

	return diluxone_users_render(
		'account/handle',
		array(
			'user'   => $user,
			'handle' => '' !== diluxone_users_handle( $user->ID ) ? diluxone_users_handle( $user->ID ) : $user->user_nicename,
			'can'    => diluxone_users_handle_can_change( $user->ID ),
			'next'   => diluxone_users_handle_next_change( $user->ID ),
			'error'  => diluxone_users_flash_take( $user->ID, 'handle' ),
		)
	);
}
add_shortcode( 'diluxone_users_handle', 'diluxone_users_shortcode_handle' );

/** Saves the public name from the front end. */
function diluxone_users_handle_submit(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( diluxone_users_login_url() );
		exit;
	}

	check_admin_referer( 'diluxone_users_handle' );

	$target = diluxone_users_account_url( 'details' );
	$result = diluxone_users_handle_save( get_current_user_id(), sanitize_text_field( wp_unslash( $_POST['diluxone_users_handle'] ?? '' ) ) );

	if ( is_wp_error( $result ) ) {
		diluxone_users_flash_set( get_current_user_id(), 'handle', $result->get_error_message() );
		wp_safe_redirect( $target );
		exit;
	}

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'saved', $target ) );
	exit;
}
add_action( 'admin_post_diluxone_users_handle', 'diluxone_users_handle_submit' );

/**
 * Is this name free?
 *
 * The same validation that runs on save answers here — there are not two sets
 * of rules — so what is read here is exactly what will happen afterwards. It
 * only answers to somebody with a session: otherwise this would be a
 * convenient way of finding out which names exist on the site.
 */
function diluxone_users_handle_check(): void {
	check_ajax_referer( 'diluxone_users_handle_check', 'nonce' );

	$user_id = get_current_user_id();

	if ( $user_id <= 0 ) {
		wp_send_json_error();
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
	$clean = diluxone_users_handle_validate( sanitize_text_field( wp_unslash( $_POST['handle'] ?? '' ) ), $user_id );

	if ( is_wp_error( $clean ) ) {
		wp_send_json_success(
			array(
				'free'   => false,
				'reason' => $clean->get_error_message(),
			)
		);
	}

	wp_send_json_success(
		array(
			'free'   => true,
			'url'    => diluxone_users_handle_base_url() . $clean . '/',
			'reason' => diluxone_users_handle( $user_id ) === $clean
				? __( 'This is the one you have now.', 'diluxone-users' )
				: __( 'Nobody is using it: it is yours when you save.', 'diluxone-users' ),
		)
	);
}
add_action( 'wp_ajax_diluxone_users_handle_check', 'diluxone_users_handle_check' );

/**
 * Does the sign-in box take a public name today?
 *
 * Its switch on the Ways in tab, and public names being on at all, which is
 * the account screen's switch. With names off nobody has chosen one and none
 * is shown anywhere, so a box that still resolved them would answer to the
 * login name WordPress made up — a name the site stopped showing.
 */
function diluxone_users_handle_login_on(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_handle_login' ) && (bool) diluxone_users_option( 'diluxone_users_handle_enabled' );
}

/**
 * Signing in by typing the public name.
 *
 * The sign-in form asks for an e-mail; if what arrived is not one and this is
 * turned on, who it belongs to is looked up and it goes on with THEIR e-mail.
 * The link never goes to an address the person typed at that moment: it goes
 * to the account's, which is what stops this from opening a new door.
 */
function diluxone_users_handle_login_email( string $typed ): string {
	if ( is_email( $typed ) || ! diluxone_users_handle_login_on() ) {
		return $typed;
	}

	$user_id = diluxone_users_handle_user( $typed );

	if ( $user_id <= 0 ) {
		return $typed;
	}

	$user = get_userdata( $user_id );

	return $user instanceof WP_User ? $user->user_email : $typed;
}

/**
 * The script that shows what the typing turns into.
 *
 * It validates nothing — the server does that — it only avoids the surprise
 * of typing "Pablo Di Loreto" and finding out afterwards that it came out as
 * "pablo-di-loreto".
 */
function diluxone_users_handle_enqueue(): void {
	if ( wp_script_is( 'diluxone-users-handle', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_script( 'diluxone-users-handle', DILUXONE_USERS_URL . 'assets/diluxone-users-handle.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-handle.js' ), true );

	wp_localize_script(
		'diluxone-users-handle',
		'diluxOneUsersHandle',
		array(
			'base'     => diluxone_users_handle_base_url(),
			'unicode'  => (bool) ( 'unicode' === diluxone_users_option( 'diluxone_users_handle_charset' ) ),
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'diluxone_users_handle_check' ),
			'checking' => __( 'Checking…', 'diluxone-users' ),
			'error'    => __( 'We could not check it right now.', 'diluxone-users' ),
		)
	);
}

/**
 * The address where somebody's public profile is seen.
 *
 * With bbPress installed it is their forum profile, which is the one people
 * share; otherwise the author page WordPress brings.
 */
function diluxone_users_handle_base_url(): string {
	$base = function_exists( 'bbp_get_user_profile_url' )
		? (string) bbp_get_user_profile_url( get_current_user_id() )
		: (string) get_author_posts_url( get_current_user_id() );

	// The last segment is taken off, which is precisely the name.
	$base = untrailingslashit( $base );

	return trailingslashit( substr( $base, 0, (int) strrpos( $base, '/' ) ) );
}
