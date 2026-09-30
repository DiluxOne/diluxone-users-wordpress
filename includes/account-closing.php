<?php
/**
 * Deleting your account, from the account area.
 *
 * The request is WordPress's own erasure request: the person asks and
 * confirms by e-mail, and then every plugin's eraser removes what that plugin
 * keeps. WordPress waits for an administrator to run it from Tools → Erase
 * Personal Data; a request filed from the account area is run as soon as it
 * is confirmed, unless the site said it wants to carry them out itself (a
 * shop that checks for pending payments first, say). Erasing leaves an empty
 * account behind, and "delete my account" means the account too. So when a
 * request that was filed from the account area has been carried out, the
 * account is closed as well:
 *
 *   - Deleted, when nothing published on the site is theirs.
 *   - Anonymised, when something is: `wp_delete_user()` without somewhere to
 *     reassign it deletes their posts, and what somebody wrote in public is
 *     the site's too. The account stays as an empty shell — no name, no
 *     address, no password, no role, no sessions — so what it wrote keeps
 *     no name and anything tied to its id (a forum thread, a course's
 *     history, an order) does not break.
 *
 * On a network the account is the network's. It is deleted from the whole
 * network only when this is the one site it belongs to and nothing on any
 * site is its content; a person who is also a member of another site keeps
 * the account there, emptied and anonymised, because a site that was never
 * asked cannot lose a member without saying so.
 *
 * Requests an administrator files by hand from Tools are left as WordPress
 * leaves them: erasing data, not closing accounts. And an account that
 * administers the site is never closed this way, by the same rule that stops
 * it from asking.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The key a request filed from the account area carries in its data. */
const DILUXONE_USERS_CLOSE_KEY = 'diluxone_users_close';

/**
 * Does this person have anything published on the site?
 *
 * Anything they are the author of — posts, pages, any post type, media they
 * uploaded — except the bookkeeping WordPress keeps on their behalf. A plugin
 * that keeps something of theirs elsewhere (a course's progress, an order)
 * says so through the filter, and the account is anonymised instead.
 */
function diluxone_users_account_has_content( int $user_id ): bool {
	global $wpdb;

	$posts = 0;
	$sites = is_multisite() ? array_map( 'intval', array_keys( get_blogs_of_user( $user_id ) ) ) : array( get_current_blog_id() );

	// On every site the person belongs to: on a network, content on another
	// site is content all the same.
	foreach ( $sites as $site ) {
		$switch = is_multisite() && get_current_blog_id() !== $site;

		if ( $switch ) {
			switch_to_blog( $site );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count at the moment an account is closed; no API counts every post type at once.
		$posts += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type NOT IN ( 'revision', 'user_request', 'customize_changeset', 'oembed_cache', 'wp_global_styles' )",
				$user_id
			)
		);

		if ( $switch ) {
			restore_current_blog();
		}
	}

	/**
	 * Filters whether an account being closed has something that depends on it.
	 *
	 * Return true to have it anonymised rather than deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $has     Whether it has published posts of any type.
	 * @param int  $user_id The account being closed.
	 */
	return (bool) apply_filters( 'diluxone_users_account_has_content', $posts > 0, $user_id );
}

/**
 * Empties an account and leaves the shell.
 *
 * The username of an account made by this plugin is its e-mail address, so
 * the username is replaced too — WordPress's own update refuses to change it,
 * hence the direct write. The password is one nobody knows, the sessions are
 * closed, and the account keeps no role on any site.
 */
function diluxone_users_anonymize_account( int $user_id ): void {
	global $wpdb;

	$shell = 'deleted-' . $user_id;

	WP_Session_Tokens::get_instance( $user_id )->destroy_all();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_update_user() cannot change user_login; the cache is cleared below.
	$wpdb->update(
		$wpdb->users,
		array(
			'user_login'          => $shell,
			'user_email'          => $shell . '@deleted.invalid',
			'user_nicename'       => $shell,
			'display_name'        => __( 'Deleted account', 'diluxone-users' ),
			'user_url'            => '',
			'user_pass'           => wp_hash_password( wp_generate_password( 64, true, true ) ),
			'user_activation_key' => '',
		),
		array( 'ID' => $user_id ),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
		array( '%d' )
	);

	foreach ( array( 'first_name', 'last_name', 'description' ) as $key ) {
		delete_user_meta( $user_id, $key );
	}

	update_user_meta( $user_id, 'nickname', $shell );
	update_user_meta( $user_id, 'diluxone_users_closed', time() );

	clean_user_cache( $user_id );

	// No role anywhere: an empty account that could still do things is not
	// closed.
	$sites = is_multisite() ? array_map( 'intval', array_keys( get_blogs_of_user( $user_id ) ) ) : array( get_current_blog_id() );

	foreach ( $sites as $site ) {
		$switch = is_multisite() && get_current_blog_id() !== $site;

		if ( $switch ) {
			switch_to_blog( $site );
		}

		( new WP_User( $user_id ) )->set_role( '' );

		if ( $switch ) {
			restore_current_blog();
		}
	}
}

/**
 * Closes the account once its erasure request has been carried out.
 *
 * After WordPress's own confirmation e-mail (priority 10), which goes to the
 * address the account still had.
 *
 * @param int $request_id The erasure request that was completed.
 */
function diluxone_users_close_account( $request_id ): void {
	global $wpdb;

	$user_id = diluxone_users_closing_request( (int) $request_id );
	$request = wp_get_user_request( (int) $request_id );
	$user    = get_userdata( $user_id );

	// The account the request was filed from, still with that address, and
	// not one that administers the site.
	if ( ! $request instanceof WP_User_Request || ! $user instanceof WP_User || strtolower( $user->user_email ) !== strtolower( (string) $request->email ) || ! diluxone_users_can_request_erase( $user_id ) || is_super_admin( $user_id ) ) {
		return;
	}

	if ( diluxone_users_account_has_content( $user_id ) || diluxone_users_account_elsewhere( $user_id ) ) {
		diluxone_users_anonymize_account( $user_id );

		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	// The request itself is a post of theirs, and deleting a user deletes
	// what they authored: it is the record that the request was carried
	// out, so it is left without an author instead of going with them.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API reassigns one post type's authorship; the cache is cleared below.
	$wpdb->update(
		$wpdb->posts,
		array( 'post_author' => 0 ),
		array(
			'post_author' => $user_id,
			'post_type'   => 'user_request',
		),
		array( '%d' ),
		array( '%d', '%s' )
	);
	clean_post_cache( (int) $request_id );

	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		// The sites it leaves are not an administrator's decision about it,
		// and nothing is written down as one (see membership.php).
		diluxone_users_membership_quietly( static fn() => wpmu_delete_user( $user_id ) );

		return;
	}

	wp_delete_user( $user_id );
}
add_action( 'wp_privacy_personal_data_erased', 'diluxone_users_close_account', 20 );

/** Is this person also a member of another site of the network? */
function diluxone_users_account_elsewhere( int $user_id ): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	return array() !== array_diff( array_map( 'intval', array_keys( get_blogs_of_user( $user_id ) ) ), array( get_current_blog_id() ) );
}

/**
 * Is this erasure request one the account area filed?
 *
 * @return int The account it closes, or 0.
 */
function diluxone_users_closing_request( int $request_id ): int {
	$request = wp_get_user_request( $request_id );

	if ( ! $request instanceof WP_User_Request || 'remove_personal_data' !== $request->action_name ) {
		return 0;
	}

	return (int) ( ( (array) $request->request_data )[ DILUXONE_USERS_CLOSE_KEY ] ?? 0 );
}

/**
 * Carries out a confirmed request from the account area, there and then.
 *
 * What an administrator's click on Tools → Erase Personal Data does, done
 * when the person confirms: every registered eraser, page after page until it
 * says it is done; the request marked completed; and the same action
 * WordPress fires at the end, which mails the person the confirmation and
 * closes the account (above). A site that wants to carry them out itself
 * says so on the account settings, and the request waits for it in Tools.
 *
 * After WordPress's own handling of the confirmation (priority 10 marks it
 * confirmed, 12 tells the administrator).
 *
 * @param int $request_id The request that was just confirmed.
 */
function diluxone_users_erase_on_confirm( $request_id ): void {
	$request_id = (int) $request_id;

	if ( diluxone_users_closing_request( $request_id ) <= 0 || 'admin' === diluxone_users_option( 'diluxone_users_privacy_delete_when' ) ) {
		return;
	}

	$request = wp_get_user_request( $request_id );
	$email   = $request instanceof WP_User_Request ? (string) $request->email : '';

	if ( '' === $email ) {
		return;
	}

	/** This filter is documented in wp-admin/includes/ajax-actions.php */
	$erasers = (array) apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own list of erasers, read as its Tools screen reads it.

	foreach ( $erasers as $eraser ) {
		if ( ! is_array( $eraser ) || ! isset( $eraser['callback'] ) || ! is_callable( $eraser['callback'] ) ) {
			continue;
		}

		// Page after page, as the Tools screen asks for them, with a ceiling:
		// an eraser that never says it is done does not hold the request.
		for ( $page = 1; $page <= 100; $page++ ) {
			$answer = call_user_func( $eraser['callback'], $email, $page );

			if ( ! is_array( $answer ) || ! empty( $answer['done'] ) ) {
				break;
			}
		}
	}

	wp_update_post(
		array(
			'ID'          => $request_id,
			'post_status' => 'request-completed',
		)
	);
	update_post_meta( $request_id, '_wp_user_request_completed_timestamp', time() );

	/** This action is documented in wp-admin/includes/privacy-tools.php */
	do_action( 'wp_privacy_personal_data_erased', $request_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own completion action, fired as its Tools screen fires it.
}
add_action( 'user_request_action_confirmed', 'diluxone_users_erase_on_confirm', 20 );

/**
 * What the confirmation page says when it was carried out on the spot.
 *
 * WordPress's own sentence says the administrator was told and will get to
 * it, which is not what happened.
 *
 * @param string $message    What WordPress would say.
 * @param int    $request_id The request that was confirmed.
 */
function diluxone_users_closed_message( $message, $request_id ): string {
	if ( diluxone_users_closing_request( (int) $request_id ) <= 0 || 'admin' === diluxone_users_option( 'diluxone_users_privacy_delete_when' ) ) {
		return (string) $message;
	}

	return '<p class="success">' . esc_html__( 'Done: your data has been erased and your account closed.', 'diluxone-users' ) . '</p>';
}
add_filter( 'user_request_action_confirmed_message', 'diluxone_users_closed_message', 10, 2 );

/**
 * A closed account does not sign in, whatever is left of it.
 *
 * The password is one nobody knows and the address is not one, so this is
 * the belt to those braces.
 *
 * @param WP_User|WP_Error|null $user
 * @return WP_User|WP_Error|null
 */
function diluxone_users_closed_refuses( $user ) {
	if ( $user instanceof WP_User && get_user_meta( $user->ID, 'diluxone_users_closed', true ) ) {
		return new WP_Error( 'diluxone_users_closed', __( 'This account was closed.', 'diluxone-users' ) );
	}

	return $user;
}
add_filter( 'authenticate', 'diluxone_users_closed_refuses', 99 );
