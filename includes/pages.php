<?php
/**
 * The pages the plugin draws on: sign-in, account and registration.
 *
 * Choosing a page used to be half of the job: the page stayed empty until its
 * shortcode was typed into it by hand, and nothing in the choice said so. Now
 * the page that is chosen draws its part of the plugin by itself, below
 * whatever the page already says; a page that does carry the shortcode keeps
 * it where it is. And when there is no page yet, one click creates it.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each page the plugin can be given: the option holding it, the shortcode it
 * draws, and what a new one is called.
 *
 * @return array<string, array{shortcode: string, title: string, screen: string}> Option => role.
 */
function diluxone_users_page_roles(): array {
	return array(
		'diluxone_users_login_page'    => array(
			'shortcode' => 'diluxone_users_login',
			'title'     => __( 'Sign in', 'diluxone-users' ),
			'screen'    => 'diluxone-users-login',
		),
		'diluxone_users_account_page'  => array(
			'shortcode' => 'diluxone_users_account',
			'title'     => __( 'My account', 'diluxone-users' ),
			'screen'    => 'diluxone-users-account',
		),
		'diluxone_users_register_page' => array(
			'shortcode' => 'diluxone_users_register',
			'title'     => __( 'Create your account', 'diluxone-users' ),
			'screen'    => 'diluxone-users-login',
		),
	);
}

/**
 * The chosen page draws its part even without the shortcode.
 *
 * Priority 8, before do_shortcode() at 11, so the shortcode added here runs
 * like one typed by hand. Only the main query's own page, in the loop: an
 * excerpt or a widget showing that page elsewhere is left alone.
 *
 * @param mixed $content The page content.
 */
function diluxone_users_page_draws_itself( $content ): string {
	$content = (string) $content;

	if ( ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$id = (int) get_the_ID();

	foreach ( diluxone_users_page_roles() as $option => $role ) {
		if ( $id !== diluxone_users_page_here( $option ) ) {
			continue;
		}
		if ( ! has_shortcode( $content, $role['shortcode'] ) ) {
			$content = rtrim( $content ) . "\n\n[" . $role['shortcode'] . ']';
		}
	}

	return $content;
}
add_filter( 'the_content', 'diluxone_users_page_draws_itself', 8 );

/**
 * The status checks stop calling a chosen page "shortcode not found": it
 * draws its part whether the shortcode is typed in it or not.
 *
 * @param mixed $draws  What the check found.
 * @param mixed $option The option holding the page ID.
 */
function diluxone_users_page_draws_check( $draws, $option ): bool {
	return isset( diluxone_users_page_roles()[ (string) $option ] ) ? true : (bool) $draws;
}
add_filter( 'diluxone_users_page_draws', 'diluxone_users_page_draws_check', 10, 2 );

/**
 * The "Create the page" link printed beside a page selector while none is
 * chosen. A link and not a button: the selector sits inside the settings
 * form, and a form cannot hold another.
 *
 * @param string $option One of diluxone_users_page_roles().
 */
function diluxone_users_create_page_link( string $option ): void {
	if ( ! isset( diluxone_users_page_roles()[ $option ] ) || (int) diluxone_users_option( $option ) > 0 ) {
		return;
	}

	$url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'diluxone_users_create_page',
				'page'   => $option,
			),
			admin_url( 'admin-post.php' )
		),
		'diluxone_users_create_page_' . $option
	);

	printf(
		' <a class="button" href="%s">%s</a>',
		esc_url( $url ),
		esc_html__( 'Create the page', 'diluxone-users' )
	);
}

/** Creates the page, chooses it, and goes back to where the click came from. */
function diluxone_users_create_page(): void {
	$option = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below, with the option in the action.
	$roles  = diluxone_users_page_roles();

	// The pages are the hub's: made anywhere else, the page would be on one
	// site and the setting pointing at it on another.
	if ( ! isset( $roles[ $option ] ) || ! diluxone_users_admin_owns( 'hub' ) || ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ), 403 );
	}
	check_admin_referer( 'diluxone_users_create_page_' . $option );

	$back = wp_get_referer() ? wp_get_referer() : diluxone_users_admin_url( $roles[ $option ]['screen'] );

	// Somebody else may have chosen one in the meantime: that one stays.
	if ( (int) diluxone_users_option( $option ) <= 0 ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $roles[ $option ]['title'],
				'post_content' => '<!-- wp:shortcode -->[' . $roles[ $option ]['shortcode'] . ']<!-- /wp:shortcode -->',
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}

		diluxone_users_update_option( $option, (int) $id );
		$back = add_query_arg( 'diluxone_users_created', (int) $id, $back );

		// A new page is new addresses (/my-account/details/, /register/), and
		// the rewrite rules are rebuilt the way every save of a page setting
		// does: by saying they are out of date.
		diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
	}

	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_post_diluxone_users_create_page', 'diluxone_users_create_page' );

/** Says which page was created, once, on the screen the click came from. */
function diluxone_users_created_page_notice(): void {
	$id = absint( wp_unslash( $_GET['diluxone_users_created'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	if ( $id <= 0 || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		sprintf(
			/* translators: %s: the title of the page, linked to it */
			esc_html__( 'The page %s was created and chosen.', 'diluxone-users' ),
			'<a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>'
		)
	);
}
add_action( 'admin_notices', 'diluxone_users_created_page_notice' );
