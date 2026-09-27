<?php
/**
 * Plugin Name: DiluxOne Users+ — end-to-end support
 * Description: The three things a browser cannot do on its own: read the mail the site sent, answer as a social network, and put the site into a known state. Local environments only.
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 *
 * It is a mu-plugin and not a test file because what it does has to happen
 * inside the request the browser made: the mail is caught while WordPress is
 * sending it, and the provider answers while the plugin is asking it.
 *
 * Nothing here loads outside `wp_get_environment_type() === 'local'`, and
 * every route but the OAuth one asks for a token in a header. The OAuth route
 * is open because the browser follows a plain redirect into it and can carry
 * no header there — it signs nobody in, it only bounces back with a code.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

if ( 'local' !== wp_get_environment_type() ) {
	return;
}

const DILUXONE_E2E_NS      = 'diluxone-e2e/v1';
const DILUXONE_E2E_TOKEN   = 'diluxone-e2e';
const DILUXONE_E2E_MAIL    = 'diluxone_e2e_mail';
const DILUXONE_E2E_SSO     = 'diluxone_e2e_sso';
const DILUXONE_E2E_ID      = 'diluxone_e2e_identity';
const DILUXONE_E2E_MISSING = '__diluxone_e2e_missing__';

/** The fake provider's endpoints. Only the first one a browser ever sees. */
const DILUXONE_E2E_OAUTH_BASE = 'https://provider.e2e.test/';

/* ── The mail catcher ──────────────────────────────────────────────── */

/**
 * Keeps every message instead of sending it.
 *
 * wp-env has no mail server, so nothing was going out anyway; what changes is
 * that it is now readable. The list is capped because this option is written
 * on every send and a test run sends a lot.
 *
 * @param mixed                $pre  What an earlier filter decided.
 * @param array<string, mixed> $atts to / subject / message / headers.
 * @return bool
 */
function diluxone_e2e_catch_mail( $pre, array $atts ): bool {
	$mail = (array) get_option( DILUXONE_E2E_MAIL, array() );

	$mail[] = array(
		'to'      => is_array( $atts['to'] ?? '' ) ? implode( ', ', $atts['to'] ) : (string) ( $atts['to'] ?? '' ),
		'subject' => (string) ( $atts['subject'] ?? '' ),
		'body'    => (string) ( $atts['message'] ?? '' ),
		'sent'    => microtime( true ),
	);

	update_option( DILUXONE_E2E_MAIL, array_slice( $mail, -50 ), false );

	return true;
}
add_filter( 'pre_wp_mail', 'diluxone_e2e_catch_mail', 10, 2 );

/* ── The social network that answers from here ─────────────────────── */

/**
 * One more row in the provider table, when a test asked for it.
 *
 * It is behind an option so the other specs — and whoever is using this
 * environment by hand — do not find a network called "Mock" on their sign-in
 * page. The SSO spec turns it on and turns it off again.
 *
 * @param array<string, array<string, mixed>> $providers
 * @return array<string, array<string, mixed>>
 */
function diluxone_e2e_provider( array $providers ): array {
	if ( ! get_option( DILUXONE_E2E_SSO ) ) {
		return $providers;
	}

	$providers['mock'] = array(
		'name'      => 'Mock',
		'color'     => '#008671',
		// The only one the browser opens, so it is a real address on this
		// site. The other two are answered below without leaving the process.
		'authorize' => rest_url( DILUXONE_E2E_NS . '/oauth/authorize' ),
		'token'     => DILUXONE_E2E_OAUTH_BASE . 'token',
		'profile'   => DILUXONE_E2E_OAUTH_BASE . 'profile',
		'scope'     => 'openid email profile',
		'extra'     => array(),
		'pkce'      => false,
		'map'       => 'diluxone_users_sso_map_oidc',
		'console'   => DILUXONE_E2E_OAUTH_BASE,
		'guide'     => DILUXONE_E2E_OAUTH_BASE,
	);

	return $providers;
}
add_filter( 'diluxone_users_sso_providers', 'diluxone_e2e_provider' );

/**
 * Answers the two server-to-server calls of the round trip.
 *
 * @param mixed                $pre
 * @param array<string, mixed> $args
 * @return mixed
 */
function diluxone_e2e_http( $pre, array $args, string $url ) {
	if ( 0 !== strpos( $url, DILUXONE_E2E_OAUTH_BASE ) ) {
		return $pre;
	}

	$identity = (array) get_option( DILUXONE_E2E_ID, array() );

	if ( false !== strpos( $url, '/token' ) ) {
		$body = empty( $identity['no_token'] )
			? array( 'access_token' => 'e2e-token' )
			: array( 'error' => 'invalid_client' );
	} else {
		$body = array_diff_key( $identity, array_flip( array( 'no_token' ) ) );
	}

	return array(
		'headers'  => array(),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}
add_filter( 'pre_http_request', 'diluxone_e2e_http', 10, 3 );

/* ── A classic menu, on a site whose theme may have none ──────────── */

/**
 * A menu location of our own, and a shortcode that draws it.
 *
 * The plugin puts the person into the site's own menu through
 * `wp_nav_menu_objects`, which only a classic menu runs — and the theme wp-env
 * ships is a block theme with no locations at all. Registering one here and
 * drawing it from a shortcode on a page is the smallest honest stand-in for a
 * classic theme: the same function a theme's header.php calls, with the same
 * arguments, so the filter sees exactly what it would see there.
 */
const DILUXONE_E2E_MENU_LOCATION = 'diluxone-e2e';

add_action(
	'after_setup_theme',
	static function (): void {
		register_nav_menus( array( DILUXONE_E2E_MENU_LOCATION => 'E2E menu' ) );
	},
	20
);

add_shortcode(
	'diluxone_e2e_menu',
	static function (): string {
		return (string) wp_nav_menu(
			array(
				'theme_location'  => DILUXONE_E2E_MENU_LOCATION,
				'container'       => 'nav',
				'container_class' => 'diluxone-e2e-menu',
				'fallback_cb'     => false,
				'echo'            => false,
			)
		);
	}
);

/**
 * The menu, its one item, its location and the page that draws it — made once.
 *
 * Like the seeded pages, it is reused by name so a run leaves one menu behind
 * and not one per run.
 */
function diluxone_e2e_menu_seed(): WP_REST_Response {
	$menu = wp_get_nav_menu_object( 'E2E menu' );
	$id   = $menu instanceof WP_Term ? (int) $menu->term_id : (int) wp_create_nav_menu( 'E2E menu' );

	if ( array() === (array) wp_get_nav_menu_items( $id ) ) {
		wp_update_nav_menu_item(
			$id,
			0,
			array(
				'menu-item-title'  => 'E2E home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);
	}

	$locations                                = (array) get_theme_mod( 'nav_menu_locations', array() );
	$locations[ DILUXONE_E2E_MENU_LOCATION ] = $id;
	set_theme_mod( 'nav_menu_locations', $locations );

	$page = get_page_by_path( 'e2e-menu' );
	$page = $page instanceof WP_Post
		? (int) $page->ID
		: (int) wp_insert_post(
			array(
				'post_title'   => 'Menu',
				'post_name'    => 'e2e-menu',
				'post_content' => '[diluxone_e2e_menu]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

	return new WP_REST_Response(
		array(
			'menu'     => $id,
			'location' => DILUXONE_E2E_MENU_LOCATION,
			'url'      => (string) get_permalink( $page ),
		)
	);
}

/* ── The routes ────────────────────────────────────────────────────── */

/** Is this request allowed to drive the site? */
function diluxone_e2e_allowed( WP_REST_Request $request ): bool {
	return hash_equals( DILUXONE_E2E_TOKEN, (string) $request->get_header( 'x-diluxone-e2e' ) );
}

/** Registers every route. */
function diluxone_e2e_routes(): void {
	$guard = 'diluxone_e2e_allowed';

	register_rest_route(
		DILUXONE_E2E_NS,
		'/seed',
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => 'diluxone_e2e_seed',
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/options',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_options_read',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_options_write',
			),
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/mail',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_mail_read',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_mail_clear',
			),
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/user',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_user_read',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_user_write',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_user_delete',
			),
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/identity',
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => 'diluxone_e2e_identity_write',
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/menu',
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => 'diluxone_e2e_menu_seed',
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/expire',
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => 'diluxone_e2e_expire',
		)
	);

	// The one the browser walks into. It redirects and nothing else.
	register_rest_route(
		DILUXONE_E2E_NS,
		'/oauth/authorize',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'diluxone_e2e_authorize',
		)
	);
}
add_action( 'rest_api_init', 'diluxone_e2e_routes' );

/**
 * The pages the plugin needs, made once and reused.
 *
 * Reused by slug so a run does not leave a new "Sign in" page behind every
 * time. Nothing here writes a plugin setting: which page is the sign-in page
 * is a setting, and settings are set — and put back — by the spec that wants
 * them changed.
 *
 * The slug says `e2e-`, the title does not, and the difference matters in one
 * place: `listing-screenshots.spec.ts` photographs these pages for the
 * wordpress.org listing, and the theme prints the title. A shop window with
 * "E2E Sign in" in the heading and "E2E Account" in the menu is a shop window
 * that says the pictures were taken in a test harness. The slug is what the
 * suite identifies them by and it is not on screen.
 */
function diluxone_e2e_seed( WP_REST_Request $request ): WP_REST_Response {
	$pages = array(
		'login'    => array( 'Sign in', '[diluxone_users_login]' ),
		'register' => array( 'Create your account', '[diluxone_users_register]' ),
		'account'  => array( 'Your account', '[diluxone_users_account]' ),
	);

	$out = array();

	foreach ( $pages as $key => $page ) {
		$slug     = 'e2e-' . $key;
		$existing = get_page_by_path( $slug );

		if ( $existing instanceof WP_Post ) {
			$id = (int) $existing->ID;

			// The shortcode may have been edited by hand in this environment,
			// and the title may be the one an older run of this file wrote.
			if ( $page[1] !== $existing->post_content || $page[0] !== $existing->post_title ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_title'   => $page[0],
						'post_content' => $page[1],
					)
				);
			}
		} else {
			$id = (int) wp_insert_post(
				array(
					'post_title'   => $page[0],
					'post_name'    => $slug,
					'post_content' => $page[1],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
		}

		$out[ $key ] = array(
			'id'  => $id,
			'url' => (string) get_permalink( $id ),
		);
	}

	diluxone_e2e_rebuild_rules();

	return new WP_REST_Response(
		array(
			'pages' => $out,
			'home'  => home_url( '/' ),
		)
	);
}

/**
 * Only the plugin's own settings can be driven from here.
 *
 * Plus three of WordPress's own, and each one is here for a named reason
 * rather than because it was convenient. `users_can_register` is a setting
 * this plugin writes, so a spec has to be able to put it back. `WPLANG`,
 * `blogname` and `blogdescription` are what the listing screenshots need: the
 * pictures on an English listing have to be in English, on a site with a name
 * rather than on "Vistalba Club". All four go through the same set-and-restore
 * contract as everything else, so a run leaves the site as it found it.
 */
function diluxone_e2e_option_allowed( string $key ): bool {
	return 0 === strpos( $key, 'diluxone_users_' )
		|| 0 === strpos( $key, 'diluxone_e2e_' )
		|| in_array( $key, array( 'users_can_register', 'WPLANG', 'blogname', 'blogdescription' ), true );
}

/**
 * Reads options, with the absent ones marked as absent.
 *
 * A setting that was never written is not the same as one written empty:
 * restoring the first means deleting it, so that the plugin's own default
 * takes over again.
 */
function diluxone_e2e_options_read( WP_REST_Request $request ): WP_REST_Response {
	$keys = array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'keys' ) ) ) );
	$out  = array();

	foreach ( $keys as $key ) {
		if ( ! diluxone_e2e_option_allowed( $key ) ) {
			continue;
		}

		$value       = get_option( $key, DILUXONE_E2E_MISSING );
		$out[ $key ] = DILUXONE_E2E_MISSING === $value ? null : $value;
	}

	return new WP_REST_Response( $out );
}

/**
 * Writes options and answers with what was there before.
 *
 * That answer is the whole point: a spec keeps it and puts it back, so the
 * environment is left the way it was found.
 */
function diluxone_e2e_options_write( WP_REST_Request $request ): WP_REST_Response {
	$set      = (array) $request->get_param( 'set' );
	$previous = array();

	foreach ( $set as $key => $value ) {
		$key = (string) $key;

		if ( ! diluxone_e2e_option_allowed( $key ) ) {
			continue;
		}

		$was              = get_option( $key, DILUXONE_E2E_MISSING );
		$previous[ $key ] = DILUXONE_E2E_MISSING === $was ? null : $was;

		if ( null === $value ) {
			delete_option( $key );
			continue;
		}

		update_option( $key, $value );
	}

	if ( $request->get_param( 'flush' ) ) {
		diluxone_e2e_rebuild_rules();
	}

	if ( $request->get_param( 'forget_transients' ) ) {
		diluxone_e2e_forget_transients();
	}

	return new WP_REST_Response( array( 'previous' => $previous ) );
}

/**
 * Asks the plugin to rebuild /sso/<network>/ and /account/<section>/.
 *
 * Not `flush_rewrite_rules()` here, which is what the first attempt did and
 * why every section URL came back "Page not found": the account rule is built
 * on `init` out of the page the settings name, so a flush in the same request
 * that changed that setting saves the rules for the OLD page. The plugin has
 * its own answer for exactly this — a version stamp it checks on `wp_loaded`
 * — and taking the stamp away is asking it to rebuild them on the next
 * request, with the settings as they are by then.
 */
function diluxone_e2e_rebuild_rules(): void {
	delete_option( 'diluxone_users_rewrite_version' );
}

/** Throttles, OAuth states and the rest of the plugin's short-lived rows. */
function diluxone_e2e_forget_transients(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_diluxone\_users\_%' OR option_name LIKE '\_transient\_timeout\_diluxone\_users\_%' OR option_name LIKE '\_site\_transient\_diluxone\_users\_%' OR option_name LIKE '\_site\_transient\_timeout\_diluxone\_users\_%'" );

	// The counts per machine are the network's, and on a network they live
	// in the network's own table.
	if ( is_multisite() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\_site\_transient\_diluxone\_users\_%' OR meta_key LIKE '\_site\_transient\_timeout\_diluxone\_users\_%'" );
	}

	wp_cache_flush();
}

/**
 * The messages the site sent, newest last, optionally to one address only.
 */
function diluxone_e2e_mail_read( WP_REST_Request $request ): WP_REST_Response {
	$mail = (array) get_option( DILUXONE_E2E_MAIL, array() );
	$to   = strtolower( trim( (string) $request->get_param( 'to' ) ) );

	if ( '' !== $to ) {
		$mail = array_values(
			array_filter(
				$mail,
				static fn( array $one ): bool => false !== strpos( strtolower( (string) $one['to'] ), $to )
			)
		);
	}

	return new WP_REST_Response( $mail );
}

/** Empties the mailbox. */
function diluxone_e2e_mail_clear(): WP_REST_Response {
	update_option( DILUXONE_E2E_MAIL, array(), false );

	return new WP_REST_Response( array( 'cleared' => true ) );
}

/**
 * What the site knows about somebody, as far as a test needs to check it.
 */
function diluxone_e2e_user_read( WP_REST_Request $request ): WP_REST_Response {
	$user = get_user_by( 'email', (string) $request->get_param( 'email' ) );

	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( array( 'exists' => false ) );
	}

	return new WP_REST_Response(
		array(
			'exists'   => true,
			'id'       => (int) $user->ID,
			'email'    => $user->user_email,
			'login'    => $user->user_login,
			'name'     => $user->display_name,
			'roles'    => array_values( (array) $user->roles ),
			'meta'     => array(
				'first_name'          => (string) get_user_meta( $user->ID, 'first_name', true ),
				'last_name'           => (string) get_user_meta( $user->ID, 'last_name', true ),
				'diluxone_users_2fa_on' => (string) get_user_meta( $user->ID, 'diluxone_users_2fa_on', true ),
				'diluxone_users_totp' => (string) get_user_meta( $user->ID, 'diluxone_users_totp', true ),
				'diluxone_users_sso_mock' => (string) get_user_meta( $user->ID, 'diluxone_users_sso_mock', true ),
			),
			'fields'   => diluxone_e2e_user_fields( (int) $user->ID, (string) $request->get_param( 'fields' ) ),
			'sessions' => count( (array) get_user_meta( $user->ID, 'session_tokens', true ) ),
			// On a network the account belongs to the network and the
			// membership to each site: `roles` above is this site's, and this
			// is whether there is one at all — plus every site that has them.
			'member'   => is_multisite() ? is_user_member_of_blog( (int) $user->ID ) : true,
			'sites'    => is_multisite() ? array_map( 'intval', array_keys( get_blogs_of_user( (int) $user->ID ) ) ) : array( 1 ),
		)
	);
}

/**
 * A handful of user meta, asked for by name.
 *
 * @param string $keys Comma-separated meta keys.
 * @return array<string, string>
 */
function diluxone_e2e_user_fields( int $user_id, string $keys ): array {
	$out = array();

	foreach ( array_filter( array_map( 'trim', explode( ',', $keys ) ) ) as $key ) {
		$out[ $key ] = (string) get_user_meta( $user_id, $key, true );
	}

	return $out;
}

/**
 * Makes somebody, or changes what they are.
 *
 * Every account it makes carries an address inside the e2e domain, which is
 * what the cleanup at the end of the run looks for.
 */
function diluxone_e2e_user_write( WP_REST_Request $request ): WP_REST_Response {
	$email = sanitize_email( (string) $request->get_param( 'email' ) );
	$role  = sanitize_key( (string) ( $request->get_param( 'role' ) ?: 'subscriber' ) );
	$pass  = (string) ( $request->get_param( 'password' ) ?: wp_generate_password( 20 ) );

	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_REST_Response( array( 'error' => 'bad-email' ), 400 );
	}

	$user = get_user_by( 'email', $email );

	if ( $user instanceof WP_User ) {
		$id = (int) $user->ID;
		wp_set_password( $pass, $id );
		$user->set_role( $role );
	} else {
		$id = (int) wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => (string) ( $request->get_param( 'name' ) ?: $email ),
				'role'         => $role,
			)
		);
	}

	foreach ( (array) $request->get_param( 'meta' ) as $key => $value ) {
		if ( null === $value ) {
			delete_user_meta( $id, (string) $key );
			continue;
		}

		update_user_meta( $id, (string) $key, $value );
	}

	return new WP_REST_Response(
		array(
			'id'       => $id,
			'email'    => $email,
			'password' => $pass,
			'totp'     => (string) get_user_meta( $id, 'diluxone_users_totp', true ),
		)
	);
}

/**
 * Removes one account, or every account the suite ever made.
 *
 * On a network `wp_delete_user()` only takes somebody off the current site —
 * the account itself stays, and so would every account the network suite
 * made. `wpmu_delete_user()` is the one that deletes it, and the domain sweep
 * looks at the whole network rather than at the members of this one site.
 */
function diluxone_e2e_user_delete( WP_REST_Request $request ): WP_REST_Response {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}

	$delete = static function ( int $id ): void {
		if ( is_multisite() ) {
			wpmu_delete_user( $id );

			return;
		}

		wp_delete_user( $id );
	};

	$email  = (string) $request->get_param( 'email' );
	$domain = (string) ( $request->get_param( 'domain' ) ?: '' );
	$gone   = 0;

	if ( '' !== $email ) {
		$user = get_user_by( 'email', $email );

		if ( $user instanceof WP_User ) {
			$delete( (int) $user->ID );
			++$gone;
		}
	}

	if ( '' !== $domain ) {
		$everyone = array(
			'fields'  => array( 'ID', 'user_email' ),
			'blog_id' => is_multisite() ? 0 : get_current_blog_id(),
		);

		foreach ( get_users( $everyone ) as $one ) {
			if ( str_ends_with( strtolower( (string) $one->user_email ), strtolower( $domain ) ) ) {
				$delete( (int) $one->ID );
				++$gone;
			}
		}
	}

	return new WP_REST_Response( array( 'deleted' => $gone ) );
}

/** What the fake network will say about whoever comes back through it. */
function diluxone_e2e_identity_write( WP_REST_Request $request ): WP_REST_Response {
	$identity = (array) $request->get_param( 'identity' );

	update_option( DILUXONE_E2E_ID, $identity, false );

	return new WP_REST_Response( array( 'identity' => $identity ) );
}

/**
 * Moves a deadline into the past.
 *
 * `what` is 'link' for the sign-in link and '2fa' for a half-finished second
 * step. It is how expiry gets tested without a test that sleeps for fifteen
 * minutes.
 */
function diluxone_e2e_expire( WP_REST_Request $request ): WP_REST_Response {
	$user = get_user_by( 'email', (string) $request->get_param( 'email' ) );

	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( array( 'error' => 'no-user' ), 404 );
	}

	$what = (string) ( $request->get_param( 'what' ) ?: 'link' );

	if ( 'link' === $what ) {
		update_user_meta( (int) $user->ID, '_diluxone_users_acceso_vence', time() - 60 );
	}

	if ( '2fa' === $what ) {
		$pending = (array) get_user_meta( (int) $user->ID, 'diluxone_users_2fa_pending', true );

		if ( array() !== $pending ) {
			$pending['expires'] = time() - 60;
			update_user_meta( (int) $user->ID, 'diluxone_users_2fa_pending', $pending );
		}
	}

	// The resend throttle counts from when the last code went out; pushing
	// that into the past is how "sixty seconds later" happens in a second.
	if ( 'resend' === $what ) {
		$pending = (array) get_user_meta( (int) $user->ID, 'diluxone_users_2fa_pending', true );

		if ( array() !== $pending ) {
			$pending['sent'] = time() - 3600;
			update_user_meta( (int) $user->ID, 'diluxone_users_2fa_pending', $pending );
		}
	}

	return new WP_REST_Response( array( 'expired' => $what ) );
}

/**
 * The provider's own screen, which here is a redirect and nothing else.
 *
 * A real one would ask the person to approve; approving is what this stands
 * in for. It hands back the `state` it was given — that is what the plugin
 * checks — plus a code the token endpoint above accepts.
 */
function diluxone_e2e_authorize( WP_REST_Request $request ) {
	$redirect = (string) $request->get_param( 'redirect_uri' );
	$state    = (string) $request->get_param( 'state' );

	if ( '' === $redirect ) {
		return new WP_REST_Response( array( 'error' => 'no-redirect-uri' ), 400 );
	}

	$identity = (array) get_option( DILUXONE_E2E_ID, array() );

	// A person who presses "cancel" on the provider's screen: the answer is an
	// error where the code would have been, and the plugin has to survive it.
	$args = empty( $identity['deny'] )
		? array(
			'code'  => 'e2e-code',
			'state' => $state,
		)
		: array(
			'error'             => 'access_denied',
			'error_description' => 'The e2e provider was told to deny this.',
			'state'             => $state,
		);

	// Not wp_safe_redirect(): the callback is this same site, but the check
	// would still have to know that, and a plain redirect is what a provider
	// actually sends.
	wp_redirect( add_query_arg( $args, $redirect ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}
