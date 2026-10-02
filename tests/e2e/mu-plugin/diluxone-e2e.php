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

/** The e-mail domain of every account the suite makes. */
const DILUXONE_E2E_DOMAIN = '@e2e.test';

/** The user meta that marks an account the suite made, whatever its address became. */
const DILUXONE_E2E_MADE = 'diluxone_e2e_made';

/** The fake provider's endpoints. Only the first one a browser ever sees. */
const DILUXONE_E2E_OAUTH_BASE = 'https://provider.e2e.test/';

/* ── The suite's accounts ──────────────────────────────────────────── */

/**
 * Marks every account made with the suite's domain, the moment it is made.
 *
 * The teardown deletes the suite's accounts by their domain, and an account
 * can lose it during a test: closing one that has published something leaves
 * a shell named `deleted-<id>` with an address of its own and no role on any
 * site, and a person can change their address on the account area. The mark
 * stays with the account through both, so the teardown still finds it.
 */
function diluxone_e2e_mark( int $user_id ): void {
	$user = get_userdata( $user_id );

	if ( $user instanceof WP_User && str_ends_with( strtolower( (string) $user->user_email ), DILUXONE_E2E_DOMAIN ) ) {
		update_user_meta( $user_id, DILUXONE_E2E_MADE, 1 );
	}
}
add_action( 'user_register', 'diluxone_e2e_mark' );

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

/**
 * The menu page, gone: a published page is in every theme's list of pages,
 * and a page called "Menu" there is a test run showing through the site.
 */
function diluxone_e2e_menu_forget(): WP_REST_Response {
	$page = get_page_by_path( 'e2e-menu' );

	if ( $page instanceof WP_Post ) {
		wp_delete_post( (int) $page->ID, true );
	}

	return new WP_REST_Response( array( 'deleted' => $page instanceof WP_Post ) );
}

/**
 * A page of a spec's own, with whatever it is given to draw: `e2e-` and a
 * name, made once and rewritten when the content changes, so a run leaves one
 * page per name behind at most — and the spec deletes it when it is done.
 *
 * It is how a shortcode a site puts on a page of its own (the pieces of the
 * account, one by one) is tried where a site would put it.
 */
function diluxone_e2e_page( WP_REST_Request $request ): WP_REST_Response {
	$slug    = 'e2e-' . sanitize_title( (string) $request->get_param( 'slug' ) );
	$content = (string) $request->get_param( 'content' );
	$page    = get_page_by_path( $slug );

	if ( $page instanceof WP_Post ) {
		$id = (int) $page->ID;

		if ( $content !== $page->post_content ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => $content,
				)
			);
		}
	} else {
		$id = (int) wp_insert_post(
			array(
				'post_title'   => $slug,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	}

	return new WP_REST_Response(
		array(
			'id'  => $id,
			'url' => (string) get_permalink( $id ),
		)
	);
}

/**
 * That page, gone for good — or, by `id`, a page a spec had the plugin make
 * (its "Create the page" button), which has a title of the plugin's and no
 * `e2e-` in its address.
 */
function diluxone_e2e_page_forget( WP_REST_Request $request ): WP_REST_Response {
	$id   = (int) $request->get_param( 'id' );
	$page = $id > 0 ? get_post( $id ) : get_page_by_path( 'e2e-' . sanitize_title( (string) $request->get_param( 'slug' ) ) );
	$page = $page instanceof WP_Post && 'page' === $page->post_type ? $page : null;

	if ( $page instanceof WP_Post ) {
		wp_delete_post( (int) $page->ID, true );
	}

	return new WP_REST_Response( array( 'deleted' => $page instanceof WP_Post ) );
}

/* ── What the browser cannot see: hooks, constants, switches ────────── */

/**
 * Counts every `wp_login`, by the e-mail of whoever signed in.
 *
 * Another plugin of the site listens on it like this; the count is how a spec
 * asks "did the plugin's own door say it, and only once". Read and reset
 * through the options route, as `diluxone_e2e_wp_login`.
 *
 * @param string  $login The login name.
 * @param WP_User $user  The account.
 */
function diluxone_e2e_hear_wp_login( $login, $user ): void {
	if ( ! $user instanceof WP_User ) {
		return;
	}

	$heard = (array) get_option( 'diluxone_e2e_wp_login', array() );
	$email = strtolower( (string) $user->user_email );

	$heard[ $email ] = (int) ( $heard[ $email ] ?? 0 ) + 1;

	update_option( 'diluxone_e2e_wp_login', $heard, false );
}
add_action( 'wp_login', 'diluxone_e2e_hear_wp_login', 100, 2 );

/**
 * Two switches a site turns on from code, flipped from a spec.
 *
 * `diluxone_e2e_safe_mode` is the emergency switch the way a must-use plugin
 * turns it on — DILUXONE_USERS_SAFE_MODE cannot be defined and undefined
 * between two tests — and `diluxone_e2e_no_wp_login_2fa` is a site saying its
 * wp-login.php cannot draw the second step.
 */
function diluxone_e2e_switches(): void {
	if ( get_option( 'diluxone_e2e_safe_mode' ) ) {
		add_filter( 'diluxone_users_safe_mode', '__return_true' );
	}

	if ( get_option( 'diluxone_e2e_no_wp_login_2fa' ) ) {
		add_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );
	}
}
add_action( 'plugins_loaded', 'diluxone_e2e_switches' );

/**
 * Says in a header whether the page defined DONOTCACHEPAGE before it was drawn.
 *
 * The constant is read by page caches at the end of the request, where no
 * browser can see it; at `template_redirect` priority 99 — after the
 * plugin's own at 0 — a header can still go.
 */
function diluxone_e2e_donotcache_header(): void {
	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && ! headers_sent() ) {
		header( 'X-Diluxone-E2E-Donotcachepage: 1' );
	}
}
add_action( 'template_redirect', 'diluxone_e2e_donotcache_header', 99 );

/**
 * Registers somebody the way another plugin's sign-up does: register_new_user()
 * with nothing of this plugin's in the request.
 */
function diluxone_e2e_register( WP_REST_Request $request ): WP_REST_Response {
	$user = register_new_user( sanitize_user( (string) $request->get_param( 'login' ) ), sanitize_email( (string) $request->get_param( 'email' ) ) );

	return new WP_REST_Response(
		is_wp_error( $user )
			? array( 'errors' => $user->get_error_codes() )
			: array( 'id' => (int) $user )
	);
}

/* ── The activity log ──────────────────────────────────────────────── */

/** The columns of a row of the plugin's activity log, in the table's order. */
const DILUXONE_E2E_LOG_COLUMNS = array( 'id', 'site_id', 'user_id', 'event', 'happened', 'ip', 'agent', 'detail' );

/**
 * This site's rows of the activity log, every column, oldest first.
 *
 * For the spec that empties the log from its screen: the log it empties is
 * the developer's, not the run's, so the spec keeps what was there and puts it
 * back with the route below when it ends.
 */
function diluxone_e2e_log_read(): WP_REST_Response {
	global $wpdb;

	$table = diluxone_users_log_table();

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return new WP_REST_Response( array() );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE site_id = %d ORDER BY id", get_current_blog_id() ), ARRAY_A );

	return new WP_REST_Response( is_array( $rows ) ? $rows : array() );
}

/**
 * Puts rows read by diluxone_e2e_log_read() back, under their own ids.
 *
 * A row whose id is still there is left as it is, so putting back what was
 * never taken away changes nothing.
 */
function diluxone_e2e_log_restore( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$table = diluxone_users_log_table();
	$put   = 0;

	foreach ( (array) $request->get_param( 'rows' ) as $row ) {
		$row = is_array( $row ) ? $row : array();

		if ( array_diff( DILUXONE_E2E_LOG_COLUMNS, array_keys( $row ) ) ) {
			continue;
		}

		$put += (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own.
				"INSERT IGNORE INTO {$table} (id, site_id, user_id, event, happened, ip, agent, detail) VALUES (%d, %d, %d, %s, %s, %s, %s, %s)",
				(int) $row['id'],
				(int) $row['site_id'],
				(int) $row['user_id'],
				(string) $row['event'],
				(string) $row['happened'],
				(string) $row['ip'],
				(string) $row['agent'],
				(string) $row['detail']
			)
		);
	}

	return new WP_REST_Response( array( 'restored' => $put ) );
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
			array(
				'methods'             => 'POST',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_menu_seed',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_menu_forget',
			),
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/page',
		array(
			array(
				'methods'             => 'POST',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_page',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_page_forget',
			),
		)
	);

	register_rest_route(
		DILUXONE_E2E_NS,
		'/register',
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => 'diluxone_e2e_register',
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

	register_rest_route(
		DILUXONE_E2E_NS,
		'/log',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_log_read',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $guard,
				'callback'            => 'diluxone_e2e_log_restore',
			),
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
 * Whether an option is one of the plugin's, stored where the plugin stores it.
 *
 * The plugin's settings go through its own helpers, which know the scope of
 * each one on a network; reading them straight from this site's options table
 * would be reading the wrong place the day they are routed. Everything else
 * this route may touch is WordPress's or this file's, and per site.
 */
function diluxone_e2e_option_is_plugins( string $key ): bool {
	return 0 === strpos( $key, 'diluxone_users_' ) && function_exists( 'diluxone_users_raw_get' );
}

/** One option, or the marker for "never written". */
function diluxone_e2e_option_get( string $key ) {
	return diluxone_e2e_option_is_plugins( $key )
		? diluxone_users_raw_get( $key, DILUXONE_E2E_MISSING )
		: get_option( $key, DILUXONE_E2E_MISSING );
}

/** Writes one option. */
function diluxone_e2e_option_set( string $key, $value ): void {
	if ( diluxone_e2e_option_is_plugins( $key ) ) {
		diluxone_users_update_option( $key, $value );
		return;
	}

	update_option( $key, $value );
}

/** Deletes one option. */
function diluxone_e2e_option_delete( string $key ): void {
	if ( diluxone_e2e_option_is_plugins( $key ) ) {
		diluxone_users_delete_option( $key );
		return;
	}

	delete_option( $key );
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

		$value       = diluxone_e2e_option_get( $key );
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

		$was              = diluxone_e2e_option_get( $key );
		$previous[ $key ] = DILUXONE_E2E_MISSING === $was ? null : $was;

		if ( null === $value ) {
			diluxone_e2e_option_delete( $key );
			continue;
		}

		diluxone_e2e_option_set( $key, $value );
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
	diluxone_e2e_option_delete( 'diluxone_users_rewrite_version' );
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

	// Not `(array)` on the meta: with every session closed WordPress deletes
	// the row, get_user_meta() answers '', and `(array) ''` is one element —
	// an account with no session left would count one.
	$tokens = get_user_meta( $user->ID, 'session_tokens', true );

	return new WP_REST_Response(
		array(
			'exists'   => true,
			'id'       => (int) $user->ID,
			'email'    => $user->user_email,
			'login'    => $user->user_login,
			'nicename' => $user->user_nicename,
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
			'sessions' => is_array( $tokens ) ? count( $tokens ) : 0,

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

	// An account that is not the suite's — the people the pictures are taken
	// with — keeps the password its owner gave it when asked to.
	$keep = (bool) $request->get_param( 'keep_password' );

	if ( $user instanceof WP_User ) {
		$id = (int) $user->ID;

		if ( $keep ) {
			$pass = '';
		} else {
			wp_set_password( $pass, $id );
		}

		$user->set_role( $role );
	} else {
		$made = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => (string) ( $request->get_param( 'name' ) ?: $email ),
				'role'         => $role,
			)
		);

		// A refusal is not account 0: `(int)` of an error is 1, and the meta
		// below would have been written onto the first account of the site.
		if ( is_wp_error( $made ) ) {
			return new WP_REST_Response( array( 'error' => $made->get_error_code() ), 400 );
		}

		$id = (int) $made;
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
		// What the account wrote goes with it, on every site. WordPress only
		// deletes posts on the sites somebody is still a member of, and a
		// closed account kept for its content is a member of none.
		foreach ( is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() ) as $site ) {
			$switch = is_multisite() && get_current_blog_id() !== (int) $site;

			if ( $switch ) {
				switch_to_blog( (int) $site );
			}

			$posts = get_posts(
				array(
					'author'      => $id,
					'post_type'   => 'any',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);

			foreach ( $posts as $post ) {
				wp_delete_post( (int) $post, true );
			}

			if ( $switch ) {
				restore_current_blog();
			}
		}

		// And what the activity log wrote about it: the log is the site's,
		// and a run that signed a hundred people in and out leaves no trace of
		// them there either.
		if ( function_exists( 'diluxone_users_log_table' ) ) {
			global $wpdb;

			$table = diluxone_users_log_table();

			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$wpdb->delete( $table, array( 'user_id' => $id ), array( '%d' ) );
			}
		}

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
		// Every account of the installation, members of no site included: a
		// closed account kept for its content has no role anywhere, and a
		// query by site would not see it.
		$everyone = array(
			'fields'  => array( 'ID', 'user_email' ),
			'blog_id' => 0,
		);

		foreach ( get_users( $everyone ) as $one ) {
			$ours = str_ends_with( strtolower( (string) $one->user_email ), strtolower( $domain ) )
				|| ( DILUXONE_E2E_DOMAIN === strtolower( $domain ) && get_user_meta( (int) $one->ID, DILUXONE_E2E_MADE, true ) );

			if ( $ours ) {
				$delete( (int) $one->ID );
				++$gone;
			}
		}

		// The privacy requests made for the domain's addresses, on every
		// site: a request is a post of its own, titled with the address, and
		// outlives the account it was for.
		foreach ( is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() ) as $site ) {
			$switch = is_multisite() && get_current_blog_id() !== (int) $site;

			if ( $switch ) {
				switch_to_blog( (int) $site );
			}

			$requests = get_posts(
				array(
					'post_type'   => 'user_request',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);

			foreach ( $requests as $request ) {
				if ( str_ends_with( strtolower( (string) get_the_title( (int) $request ) ), strtolower( $domain ) ) ) {
					wp_delete_post( (int) $request, true );
				}
			}

			if ( $switch ) {
				restore_current_blog();
			}
		}

		// The attempts that named an address of the domain and matched no
		// account — a wrong password for somebody who does not exist — are
		// written with nobody's id: they go by what they tried.
		if ( function_exists( 'diluxone_users_log_table' ) ) {
			global $wpdb;

			$table = diluxone_users_log_table();

			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = 0 AND detail LIKE %s", '%' . $wpdb->esc_like( strtolower( $domain ) ) . '%' ) );
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
		update_user_meta( (int) $user->ID, '_diluxone_users_link_expires', time() - 60 );
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

/* ── admin-access: a setting pinned from code, and a picture in the library ─── */

/**
 * Pins plugin settings from code the way a site's own code would, while
 * `diluxone_e2e_admin_access_force` holds them (key => value). A named
 * function on purpose: the forced-setting notice lists the functions hooked
 * to the filter, and a closure has no name to list.
 *
 * @param mixed  $value Resolved value.
 * @param string $key   Option name.
 * @return mixed
 */
function diluxone_e2e_admin_access_forced( $value, $key ) {
	$forced = get_option( 'diluxone_e2e_admin_access_force', array() );

	return is_array( $forced ) && array_key_exists( (string) $key, $forced ) ? $forced[ (string) $key ] : $value;
}
add_filter( 'diluxone_users_option', 'diluxone_e2e_admin_access_forced', 10, 2 );

/**
 * A one-pixel picture in the media library, for the media picker; DELETE
 * with `?id=` takes it out again.
 */
function diluxone_e2e_admin_access_attachment( WP_REST_Request $request ): WP_REST_Response {
	if ( 'DELETE' === $request->get_method() ) {
		wp_delete_attachment( (int) $request->get_param( 'id' ), true );

		return new WP_REST_Response( array( 'deleted' => (int) $request->get_param( 'id' ) ) );
	}

	$upload = wp_upload_bits(
		'e2e-picture-' . wp_generate_password( 6, false ) . '.png',
		null,
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed one-pixel PNG.
		(string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' )
	);

	if ( ! empty( $upload['error'] ) ) {
		return new WP_REST_Response( array( 'error' => $upload['error'] ), 500 );
	}

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'e2e-picture',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	return new WP_REST_Response(
		array(
			'id'  => $id,
			'url' => wp_get_attachment_url( $id ),
		)
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'diluxone-e2e/v1',
			'/admin-access/attachment',
			array(
				'methods'             => array( 'POST', 'DELETE' ),
				'callback'            => 'diluxone_e2e_admin_access_attachment',
				'permission_callback' => 'diluxone_e2e_allowed',
			)
		);
	}
);

/* ── signin: switches and a meta door for the ways-in specs ─────────── */

/**
 * Two more switches a site turns on from code, flipped from a spec.
 *
 * `diluxone_e2e_login_burst` lowers the per-machine ceiling on sign-in links
 * and social trips (the `diluxone_users_login_burst` filter) so the burst is
 * reached in three requests instead of thirty-one. `diluxone_e2e_refuse_accounts`
 * makes WordPress refuse every new account, the way a site whose own rules
 * turn a registration down does.
 */
function diluxone_e2e_signin_switches(): void {
	$burst = (int) get_option( 'diluxone_e2e_login_burst', 0 );

	if ( $burst > 0 ) {
		add_filter( 'diluxone_users_login_burst', static fn(): int => $burst );
	}

	if ( get_option( 'diluxone_e2e_refuse_accounts' ) ) {
		add_filter( 'pre_user_login', '__return_empty_string' );
	}
}
add_action( 'plugins_loaded', 'diluxone_e2e_signin_switches' );

/**
 * Reads or writes a person's meta as it is stored, without touching anything
 * else of the account (the `/user` write sets the password again).
 *
 * GET `?email=&keys=a,b` answers each key's value as stored (arrays as
 * arrays). POST `{email, set: {key: value|null}, past: [key | "key.field"]}`
 * writes values (null deletes) and moves each named deadline sixty seconds
 * into the past: a timestamp meta, or one field of an array meta.
 */
function diluxone_e2e_signin_meta( WP_REST_Request $request ): WP_REST_Response {
	$user = get_user_by( 'email', (string) $request->get_param( 'email' ) );

	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( array( 'error' => 'no-user' ), 404 );
	}

	$id = (int) $user->ID;

	if ( 'GET' === $request->get_method() ) {
		$out = array();

		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'keys' ) ) ) ) as $key ) {
			$out[ $key ] = get_user_meta( $id, $key, true );
		}

		return new WP_REST_Response( $out );
	}

	foreach ( (array) $request->get_param( 'set' ) as $key => $value ) {
		if ( null === $value ) {
			delete_user_meta( $id, (string) $key );
			continue;
		}

		update_user_meta( $id, (string) $key, $value );
	}

	foreach ( (array) $request->get_param( 'past' ) as $name ) {
		[ $key, $field ] = array_pad( explode( '.', (string) $name, 2 ), 2, '' );

		if ( '' === $field ) {
			update_user_meta( $id, $key, time() - 60 );
			continue;
		}

		$stored = get_user_meta( $id, $key, true );

		if ( is_array( $stored ) ) {
			$stored[ $field ] = time() - 60;
			update_user_meta( $id, $key, $stored );
		}
	}

	return new WP_REST_Response( array( 'id' => $id ) );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			DILUXONE_E2E_NS,
			'/signin/meta',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => 'diluxone_e2e_allowed',
				'callback'            => 'diluxone_e2e_signin_meta',
			)
		);
	}
);

/* ── my-account: a session store the account cannot address one by one ─── */

/**
 * With `diluxone_e2e_session_manager` on, WordPress keeps sessions through a
 * class of its own instead of WP_User_Meta_Session_Tokens: the same storage,
 * another name. That is what a site with sessions in Redis looks like to the
 * plugin, which then cannot close one session by its id and must offer only
 * "close the others". An option of this file's, set and put back by the spec.
 */
class Diluxone_E2E_Session_Tokens extends WP_User_Meta_Session_Tokens {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the stand-in lives with the switch that uses it.

function diluxone_e2e_my_account_session_manager( string $manager ): string {
	return get_option( 'diluxone_e2e_session_manager' ) ? 'Diluxone_E2E_Session_Tokens' : $manager;
}
add_filter( 'session_token_manager', 'diluxone_e2e_my_account_session_manager' );

/* ── refusals: a nonce for whoever the cookie says, and meta as it is stored ─── */

/**
 * A nonce made for the person whose session cookie came with the request.
 *
 * The refusal specs post a form as somebody without the right to send it, and
 * the only honest version of that carries a nonce that is valid for THAT
 * person: then what stops the request is the capability, not the nonce. A REST
 * call without the REST nonce runs as nobody, so the session is read from the
 * logged-in cookie here and made current before the nonce is made. Without a
 * cookie it is a nonce for nobody, which is what a stranger's page carries.
 */
function diluxone_e2e_refusals_nonce( WP_REST_Request $request ): WP_REST_Response {
	$user = (int) wp_validate_auth_cookie( '', 'logged_in' );

	wp_set_current_user( $user );

	return new WP_REST_Response(
		array(
			'user'  => $user,
			'nonce' => wp_create_nonce( (string) $request->get_param( 'action' ) ),
		)
	);
}

/**
 * User meta as it is stored, arrays included.
 *
 * The /user route reads meta as strings, which is right for the fields and
 * wrong for a list of passkeys: an array read as a string is "Array" whatever
 * is in it, and a refusal that compares "Array" with "Array" proves nothing.
 */
function diluxone_e2e_refusals_meta( WP_REST_Request $request ): WP_REST_Response {
	$user = get_user_by( 'email', (string) $request->get_param( 'email' ) );
	$out  = array();

	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( array( 'exists' => false ) );
	}

	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'keys' ) ) ) ) as $key ) {
		$out[ $key ] = metadata_exists( 'user', (int) $user->ID, $key ) ? get_user_meta( (int) $user->ID, $key, true ) : null;
	}

	return new WP_REST_Response(
		array(
			'exists' => true,
			'meta'   => $out,
		)
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			DILUXONE_E2E_NS,
			'/refusals/nonce',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'diluxone_e2e_allowed',
				'callback'            => 'diluxone_e2e_refusals_nonce',
			)
		);

		register_rest_route(
			DILUXONE_E2E_NS,
			'/refusals/meta',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'diluxone_e2e_allowed',
				'callback'            => 'diluxone_e2e_refusals_meta',
			)
		);
	}
);

/* ── hub-: how many additions the membership makes on the spot ─── */

/**
 * The membership adds up to fifty people on the spot and queues the rest for
 * WP-Cron. A test network has a handful of people, so the queued half — the
 * progress list, the "queued" notice, the cron that works it — never happens
 * at the plugin's own threshold. `diluxone_e2e_membership_inline` set to a
 * number on the main site is that threshold for as long as it is set (0:
 * everything queued).
 */
add_filter(
	'diluxone_users_membership_inline',
	static function ( $inline ) {
		// Written through the main site's side door, like every other switch.
		$set = is_multisite() ? get_blog_option( get_main_site_id(), 'diluxone_e2e_membership_inline', '' ) : get_option( 'diluxone_e2e_membership_inline', '' );

		return '' === $set || false === $set ? $inline : (int) $set;
	}
);

/**
 * …and `diluxone_e2e_membership_hold` set on the main site keeps WP-Cron from
 * being handed the queue: a page load can spawn cron here, and a queue worked
 * the moment it is made leaves nothing on the screen to see. The spec then
 * finishes it itself, with WP-CLI.
 */
add_filter(
	'schedule_event',
	static function ( $event ) {
		if ( ! is_object( $event ) || ! function_exists( 'get_blog_option' ) || 'diluxone_users_membership_drain' !== ( $event->hook ?? '' ) ) {
			return $event;
		}

		return get_blog_option( get_main_site_id(), 'diluxone_e2e_membership_hold', '' ) ? false : $event;
	}
);

/* ── admin-reports: rows of the activity log, sessions and mail a spec puts back (helper 3: admin-security, admin-social, admin-status, admin-reports, wp-screens) ─── */

/**
 * How the next message goes, when a spec says so: `diluxone_e2e_mail_outcome`
 * is `fail` (the mailer refuses and WordPress says why) or `ok` (WordPress
 * says it went). Unset, nothing changes: the catcher above keeps the message
 * and WordPress says nothing either way, which is what every other spec sees.
 *
 * After the catcher, which keeps the message whatever is decided here.
 *
 * @param mixed                $pre  What the catcher answered.
 * @param array<string, mixed> $atts to / subject / message / headers.
 * @return mixed
 */
function diluxone_e2e_mail_outcome( $pre, array $atts ) {
	$outcome = (string) get_option( 'diluxone_e2e_mail_outcome', '' );

	if ( 'fail' === $outcome ) {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'The e2e mailer was told to refuse this message.', $atts ) );

		return false;
	}

	if ( 'ok' === $outcome ) {
		do_action( 'wp_mail_succeeded', $atts );
	}

	return $pre;
}
add_filter( 'pre_wp_mail', 'diluxone_e2e_mail_outcome', 20, 2 );

/** The copy of the activity log a spec keeps while it empties the real one. */
function diluxone_e2e_log_keep_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'diluxone_e2e_log_keep';
}

/**
 * Keeps a copy of every row of the activity log, ids and all, so a spec can
 * press "Empty it now" and put back what the site had.
 */
function diluxone_e2e_log_keep(): WP_REST_Response {
	global $wpdb;

	$log  = diluxone_users_log_table();
	$keep = diluxone_e2e_log_keep_table();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- two table names of this install.
	$wpdb->query( "DROP TABLE IF EXISTS {$keep}" );
	$wpdb->query( "CREATE TABLE {$keep} LIKE {$log}" );
	$wpdb->query( "INSERT INTO {$keep} SELECT * FROM {$log}" );
	$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$keep}" );
	// phpcs:enable

	return new WP_REST_Response( array( 'kept' => $rows ) );
}

/** Puts back every row kept above that is no longer there, and drops the copy. */
function diluxone_e2e_admin_reports_log_restore(): WP_REST_Response {
	global $wpdb;

	$log  = diluxone_users_log_table();
	$keep = diluxone_e2e_log_keep_table();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- two table names of this install.
	if ( $keep !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $keep ) ) ) {
		return new WP_REST_Response( array( 'restored' => 0 ) );
	}

	$back = (int) $wpdb->query( "INSERT IGNORE INTO {$log} SELECT * FROM {$keep}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$keep}" );
	// phpcs:enable

	return new WP_REST_Response( array( 'restored' => $back ) );
}

/**
 * Rows of the spec's own in the activity log, of this site: `count` of them,
 * of `event`, for `email`'s account (or nobody), from `ip`, `days` ago, each
 * carrying `tag` in its detail so the spec finds them, and only them.
 */
function diluxone_e2e_log_rows_add( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$count = max( 1, min( 200, (int) $request->get_param( 'count' ) ) );
	$event = sanitize_key( (string) ( $request->get_param( 'event' ) ?: 'sign_in_failed' ) );
	$tag   = sanitize_text_field( (string) $request->get_param( 'tag' ) );
	$ip    = sanitize_text_field( (string) ( $request->get_param( 'ip' ) ?: '192.0.2.10' ) );
	$days  = (int) $request->get_param( 'days' );
	$user  = get_user_by( 'email', (string) $request->get_param( 'email' ) );

	if ( '' === $tag ) {
		return new WP_REST_Response( array( 'error' => 'no-tag' ), 400 );
	}

	for ( $n = 0; $n < $count; $n++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			diluxone_users_log_table(),
			array(
				'site_id'  => diluxone_users_log_site(),
				'user_id'  => $user instanceof WP_User ? (int) $user->ID : 0,
				'event'    => $event,
				'happened' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS - $n ),
				'ip'       => $ip,
				'agent'    => 'diluxone-e2e',
				'detail'   => (string) wp_json_encode( array( 'tried' => $tag . '-' . $n ) ),
			)
		);
	}

	return new WP_REST_Response( array( 'added' => $count ) );
}

/** The spec's own rows, by their tag, gone: whatever the site had stays. */
function diluxone_e2e_log_rows_delete( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$tag = sanitize_text_field( (string) $request->get_param( 'tag' ) );

	if ( '' === $tag ) {
		return new WP_REST_Response( array( 'error' => 'no-tag' ), 400 );
	}

	$table = diluxone_users_log_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$gone = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE agent = 'diluxone-e2e' AND detail LIKE %s", '%' . $wpdb->esc_like( $tag ) . '%' ) );

	return new WP_REST_Response( array( 'deleted' => $gone ) );
}

/**
 * Keeps every account's sessions, so a spec can press "close every session"
 * and put back the ones the site had — the administrator session the other
 * specs share among them.
 */
function diluxone_e2e_sessions_keep(): WP_REST_Response {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'session_tokens' ), ARRAY_A );

	update_option( 'diluxone_e2e_sessions_kept', $rows, false );

	return new WP_REST_Response( array( 'kept' => count( (array) $rows ) ) );
}

/** Writes the kept sessions back, beside any opened since. */
function diluxone_e2e_sessions_restore(): WP_REST_Response {
	$rows = (array) get_option( 'diluxone_e2e_sessions_kept', array() );
	$back = 0;

	foreach ( $rows as $row ) {
		$kept = maybe_unserialize( (string) $row['meta_value'] );
		$now  = get_user_meta( (int) $row['user_id'], 'session_tokens', true );

		if ( is_array( $kept ) ) {
			update_user_meta( (int) $row['user_id'], 'session_tokens', array_merge( is_array( $now ) ? $now : array(), $kept ) );
			++$back;
		}
	}

	delete_option( 'diluxone_e2e_sessions_kept' );

	return new WP_REST_Response( array( 'restored' => $back ) );
}

add_action(
	'rest_api_init',
	static function (): void {
		$guard = 'diluxone_e2e_allowed';

		register_rest_route(
			DILUXONE_E2E_NS,
			'/h3/log-keep',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => 'diluxone_e2e_log_keep',
					'permission_callback' => $guard,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => 'diluxone_e2e_admin_reports_log_restore',
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			DILUXONE_E2E_NS,
			'/h3/log-rows',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => 'diluxone_e2e_log_rows_add',
					'permission_callback' => $guard,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => 'diluxone_e2e_log_rows_delete',
					'permission_callback' => $guard,
				),
			)
		);

		register_rest_route(
			DILUXONE_E2E_NS,
			'/h3/sessions-keep',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => 'diluxone_e2e_sessions_keep',
					'permission_callback' => $guard,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => 'diluxone_e2e_sessions_restore',
					'permission_callback' => $guard,
				),
			)
		);
	}
);

/* ── admin-content: user meta and media for the content screens ─── */

/**
 * Writes user meta for one account, and nothing else.
 *
 * `POST /user` also sets the password, which changes the hash a session
 * cookie is checked against and signs the person out: a test that moves a
 * person's clock (when their public name last changed, how many goes of a
 * field they spent) while they are signed in needs the meta alone.
 */
function diluxone_e2e_h4_usermeta( WP_REST_Request $request ): WP_REST_Response {
	$user = get_user_by( 'email', (string) $request->get_param( 'email' ) );

	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( array( 'error' => 'no-user' ), 404 );
	}

	foreach ( (array) $request->get_param( 'meta' ) as $key => $value ) {
		if ( null === $value ) {
			delete_user_meta( (int) $user->ID, (string) $key );
			continue;
		}

		update_user_meta( (int) $user->ID, (string) $key, $value );
	}

	return new WP_REST_Response( array( 'id' => (int) $user->ID ) );
}

/**
 * A small picture in the media library, made once by name and reused.
 *
 * For the screens that pick an image (the site logo, a cover, the mark on
 * wp-login.php): the picker lists what the library holds, and a test needs
 * one known picture in it. `DELETE` removes it again.
 */
function diluxone_e2e_h4_media( WP_REST_Request $request ): WP_REST_Response {
	$name     = sanitize_file_name( (string) ( $request->get_param( 'name' ) ?: 'e2e-picture' ) );
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'name'        => $name,
			'post_status' => 'inherit',
			'numberposts' => 1,
		)
	);

	if ( 'DELETE' === $request->get_method() ) {
		foreach ( $existing as $post ) {
			wp_delete_attachment( (int) $post->ID, true );
		}

		return new WP_REST_Response( array( 'deleted' => count( $existing ) ) );
	}

	if ( array() !== $existing ) {
		$id = (int) $existing[0]->ID;

		return new WP_REST_Response(
			array(
				'id'  => $id,
				'url' => (string) wp_get_attachment_url( $id ),
			)
		);
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_upload_bits( $name . '.png', null, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGNk+M9AEmAiTfmohlENVNMAAIhCASAXpJGtAAAAAElFTkSuQmCC' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

	if ( ! empty( $upload['error'] ) ) {
		return new WP_REST_Response( array( 'error' => $upload['error'] ), 500 );
	}

	$id = (int) wp_insert_attachment(
		array(
			'post_title'     => $name,
			'post_name'      => $name,
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	return new WP_REST_Response(
		array(
			'id'  => $id,
			'url' => (string) wp_get_attachment_url( $id ),
		)
	);
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			DILUXONE_E2E_NS,
			'/h4/usermeta',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'diluxone_e2e_allowed',
				'callback'            => 'diluxone_e2e_h4_usermeta',
			)
		);

		register_rest_route(
			DILUXONE_E2E_NS,
			'/h4/media',
			array(
				'methods'             => array( 'POST', 'DELETE' ),
				'permission_callback' => 'diluxone_e2e_allowed',
				'callback'            => 'diluxone_e2e_h4_media',
			)
		);
	}
);
