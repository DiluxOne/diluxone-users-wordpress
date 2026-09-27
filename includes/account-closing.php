<?php
/**
 * Deleting your account, from the account area.
 *
 * The request is WordPress's own erasure request: the person asks, confirms
 * by e-mail, and the site's administrator runs it from Tools → Erase Personal
 * Data, where every plugin's eraser removes what that plugin keeps. That
 * leaves an empty account behind, and "delete my account" means the account
 * too. So when a request that was filed from the account area has been
 * carried out, the account is closed as well:
 *
 *   - Deleted, when nothing published on the site is theirs.
 *   - Anonymised, when something is: `wp_delete_user()` without somewhere to
 *     reassign it deletes their posts, and what somebody wrote in public is
 *     the site's too. The account stays as an empty shell — no name, no
 *     address, no password, no role, no sessions — so what it wrote keeps
 *     no name and anything tied to its id (a forum thread, a course's
 *     history, an order) does not break.
 *
 * On a network the account is the network's and other sites may hold its
 * content, so it is always anonymised there.
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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count at the moment an account is closed; no API counts every post type at once.
	$posts = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type NOT IN ( 'revision', 'user_request', 'customize_changeset', 'oembed_cache', 'wp_global_styles' )",
			$user_id
		)
	);

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
	$request = wp_get_user_request( (int) $request_id );

	if ( ! $request instanceof WP_User_Request || 'remove_personal_data' !== $request->action_name ) {
		return;
	}

	$user_id = (int) ( ( (array) $request->request_data )[ DILUXONE_USERS_CLOSE_KEY ] ?? 0 );
	$user    = get_userdata( $user_id );

	// The account the request was filed from, still with that address, and
	// not one that administers the site.
	if ( ! $user instanceof WP_User || strtolower( $user->user_email ) !== strtolower( (string) $request->email ) || ! diluxone_users_can_request_erase( $user_id ) || is_super_admin( $user_id ) ) {
		return;
	}

	if ( is_multisite() || diluxone_users_account_has_content( $user_id ) ) {
		diluxone_users_anonymize_account( $user_id );

		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	wp_delete_user( $user_id );
}
add_action( 'wp_privacy_personal_data_erased', 'diluxone_users_close_account', 20 );

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
