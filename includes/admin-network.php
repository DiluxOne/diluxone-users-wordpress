<?php
/**
 * Where each screen lives on a network, and the screens the network has.
 *
 * On a network the settings stopped being one site's. Who gets in and how
 * safely — the second step, passkeys, sessions, the proxy, the social
 * credentials, the fields a person has, what the log keeps — is one decision
 * for every site, because the person it is about is one person on every site
 * and the session they open reaches all of them. A site administrator cannot
 * change it, and cannot loosen it for their own site either: a second step
 * one site of the network can switch off is a second step anybody can walk
 * round by signing in there.
 *
 * So those screens leave the sites' menus and go to Network Admin, where only
 * whoever administers the network reaches them. They are the same screens,
 * drawn by the same panels, and each panel says where it belongs rather than
 * each screen being written twice: the screen asks which of its tabs belong
 * here and draws those.
 *
 * Four places a screen can be looked at from, and one question each answers:
 *
 *   - 'single': a site on its own. Everything is here, as it always was.
 *   - 'network': Network Admin. The network's settings and nothing else.
 *   - 'hub': the site people sign in, register and keep their account on. Its
 *     own screens and the site's.
 *   - 'site': any other site of the network. Only what belongs to that site —
 *     its menus, its admin bar, its reports, its maintenance. The hub's screens
 *     are the hub's, and they are edited there.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The capability every network screen and every network save asks for. */
const DILUXONE_USERS_NETWORK_CAP = 'manage_network_options';

/** The capability the plugin's screens ask for, here. */
function diluxone_users_admin_cap(): string {
	return 'network' === diluxone_users_admin_context() ? DILUXONE_USERS_NETWORK_CAP : 'manage_options';
}

/**
 * Where each screen belongs, when nothing more precise is said about a tab.
 *
 * @return array<string, string> Screen slug => scope.
 */
function diluxone_users_screen_scopes(): array {
	return array(
		DILUXONE_USERS_MENU       => 'site',
		'diluxone-users-login'    => 'hub',
		'diluxone-users-security' => 'network',
		'diluxone-users-social'   => 'network',
		'diluxone-users-account'  => 'hub',
		'diluxone-users-fields'   => 'network',
		'diluxone-users-design'   => 'hub',
		'diluxone-users-notices'  => 'hub',
		'diluxone-users-reports'  => 'site',
		'diluxone-users-status'   => 'site',
	);
}

/**
 * The tabs that do not belong where their screen does.
 *
 * Written out like the settings map, and for the same reason: a tab that
 * saves the network's settings from a site's screen is the one mistake this
 * whole file is here to prevent.
 *
 * @return array<string, array<string, string>> Screen slug => tab => scope.
 */
function diluxone_users_panel_scopes(): array {
	return array(
		// The network's own page: how it is set up, and what happens to
		// everybody's data when the plugin is deleted.
		DILUXONE_USERS_MENU      => array(
			'network'   => 'network',
			'uninstall' => 'network',
		),
		// Each site has its own theme and its own dashboard: where the
		// account link goes in its menus, and what its admin bar and its
		// dashboard profile show, are that site's.
		'diluxone-users-account' => array(
			'menu'      => 'site',
			'dashboard' => 'site',
		),
		// What the log records and for how long is the network's, and so is
		// the report of every site's rows; each site's own rows are still on
		// its own Activity tab.
		'diluxone-users-reports' => array(
			'network-activity' => 'network',
			'logging'          => 'network',
		),
	);
}

/**
 * Where one tab of one screen belongs.
 *
 * @param string $screen Screen slug.
 * @param string $panel  Tab id, or '' for the screen as a whole.
 */
function diluxone_users_panel_scope( string $screen, string $panel = '' ): string {
	$scope = diluxone_users_panel_scopes()[ $screen ][ $panel ] ?? ( diluxone_users_screen_scopes()[ $screen ] ?? 'site' );

	/**
	 * Filters where one tab of one screen belongs on a network.
	 *
	 * An add-on whose tab saves network settings says so here, and its tab
	 * moves to Network Admin with the plugin's own. Anything other than
	 * 'network', 'hub' or 'site' is read as 'site'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope  'network', 'hub' or 'site'.
	 * @param string $screen Screen slug.
	 * @param string $panel  Tab id, '' for the screen as a whole.
	 */
	$scope = apply_filters( 'diluxone_users_panel_scope', $scope, $screen, $panel );

	return in_array( $scope, array( 'network', 'hub', 'site' ), true ) ? $scope : 'site';
}

/**
 * Whether a screen has anything to show from here.
 *
 * Asked of the scopes and not of the registered panels, because the menu is
 * built before the panels are registered: WordPress fires `admin_menu` before
 * `admin_init`.
 *
 * @param string $screen  Screen slug.
 * @param string $context Where from; the current place when left out.
 */
function diluxone_users_screen_here( string $screen, string $context = '' ): bool {
	$scopes = array( diluxone_users_panel_scope( $screen ) );

	foreach ( array_keys( diluxone_users_panel_scopes()[ $screen ] ?? array() ) as $panel ) {
		$scopes[] = diluxone_users_panel_scope( $screen, (string) $panel );
	}

	foreach ( array_unique( $scopes ) as $scope ) {
		if ( diluxone_users_admin_owns( $scope, $context ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The tabs of a screen that belong here, the others left out.
 *
 * @param array<string, array<string, mixed>> $panels Id => panel.
 * @param string                              $screen Screen slug.
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_panels_here( array $panels, string $screen ): array {
	return array_filter(
		$panels,
		static fn( string $id ): bool => diluxone_users_admin_owns( diluxone_users_panel_scope( $screen, $id ) ),
		ARRAY_FILTER_USE_KEY
	);
}

/**
 * The admin address of a screen, from wherever it is asked for.
 *
 * A link to a network screen goes to Network Admin, a link to the hub's
 * screens goes to the hub's dashboard, and a link to a site's own screen
 * drawn in Network Admin goes to the hub's, which is the one site the network
 * screens speak for.
 *
 * @param string $screen Screen slug.
 * @param string $tab    Tab id, or '' for the screen's first.
 */
function diluxone_users_admin_base( string $screen, string $tab = '' ): string {
	$context = diluxone_users_admin_context();

	if ( 'single' === $context ) {
		return admin_url( 'admin.php' );
	}

	$scope = diluxone_users_panel_scope( $screen, $tab );

	if ( 'network' === $scope ) {
		return network_admin_url( 'admin.php' );
	}

	if ( 'network' === $context || ( 'hub' === $scope && 'site' === $context ) ) {
		return get_admin_url( diluxone_users_hub_site_id(), 'admin.php' );
	}

	return admin_url( 'admin.php' );
}

/**
 * Whether the person looking can open that screen where it lives.
 *
 * For the cards that point at the screens a site no longer has: a link to
 * Network Admin is only worth drawing for somebody who can go in.
 *
 * @param string $scope 'network' or 'hub'.
 */
function diluxone_users_admin_can_open( string $scope ): bool {
	if ( 'network' === $scope ) {
		return current_user_can( DILUXONE_USERS_NETWORK_CAP );
	}

	// current_user_can_for_site() only arrived in 6.7, and its older name is
	// deprecated since: the switch is what both do inside.
	return (bool) diluxone_users_on_hub( static fn(): bool => current_user_can( 'manage_options' ) );
}

/** The hub's name, as its administrators know it. */
function diluxone_users_hub_name(): string {
	$name = (string) get_blog_option( diluxone_users_hub_site_id(), 'blogname' );

	return '' !== $name ? $name : (string) get_home_url( diluxone_users_hub_site_id() );
}

/* ── The network's menu ────────────────────────────────────────────── */

/**
 * The menu in Network Admin: the screens that hold the network's settings.
 *
 * The screen titles are the same words as on a site, with one exception: on a
 * site the log is a tab of Reports, beside the sessions; the network has the
 * log — every site's rows and the settings — and nothing else of Reports, so
 * the screen is named after what it holds.
 */
function diluxone_users_network_menu(): void {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return;
	}

	add_menu_page(
		diluxone_users_plugin_name(),
		diluxone_users_plugin_name(),
		DILUXONE_USERS_NETWORK_CAP,
		DILUXONE_USERS_MENU,
		'diluxone_users_screen_network_home',
		'dashicons-groups',
		22
	);

	$callbacks = diluxone_users_screen_callbacks();

	$callbacks[ DILUXONE_USERS_MENU ] = 'diluxone_users_screen_network_home';

	foreach ( diluxone_users_screens() as $slug => $title ) {
		if ( diluxone_users_screen_here( $slug, 'network' ) ) {
			add_submenu_page( DILUXONE_USERS_MENU, $title, $title, DILUXONE_USERS_NETWORK_CAP, $slug, $callbacks[ $slug ] );
		}
	}
}
add_action( 'network_admin_menu', 'diluxone_users_network_menu' );

/**
 * The network's two tabs on its front page.
 *
 * Registered on the same screen slug as a site's Overview, and kept apart from
 * it by where they belong: a site never sees them, and the network sees
 * nothing else.
 */
function diluxone_users_network_panels(): void {
	// A single site has no network to describe, and its own box about
	// deleting the plugin is on Maintenance › Tools.
	if ( ! diluxone_users_scoped_storage_active() ) {
		return;
	}

	diluxone_users_register_panel(
		DILUXONE_USERS_MENU,
		'network',
		array(
			'label'    => __( 'The network', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_network_overview',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_MENU,
		'uninstall',
		array(
			'label'    => __( 'Deleting the plugin', 'diluxone-users' ),
			'position' => 90,
			'render'   => 'diluxone_users_screen_network_uninstall',
			'save'     => 'diluxone_users_network_uninstall_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_network_panels' );

/** The network's front page. */
function diluxone_users_screen_network_home(): void {
	diluxone_users_screen_panels( DILUXONE_USERS_MENU, diluxone_users_screens()[ DILUXONE_USERS_MENU ] );
}

/* ── What the network is ───────────────────────────────────────────── */

/**
 * How the network is set up, what it decides, and what moving here found.
 *
 * Nothing is edited on this tab. It says which site people's accounts live
 * on, how the sites are addressed, which of them will make people sign in
 * again, and — once — which sites had set something differently from the main
 * site before the settings became the network's.
 */
function diluxone_users_screen_network_overview(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'On this network the plugin applies one set of rules to every site. Who gets in and how safely, the social sign-in apps, the fields a person has and what the activity log keeps are decided here, for all of them. Each site keeps its own menus, admin bar, reports and maintenance.', 'diluxone-users' ) );

	$hub       = diluxone_users_hub_site_id();
	$sites     = (int) get_sites(
		array(
			'count'      => true,
			'network_id' => get_current_network_id(),
		)
	);
	$subdomain = is_subdomain_install();

	diluxone_users_ui_wide_open();
	diluxone_users_ui_cards_open();

	diluxone_users_ui_card(
		array(
			'icon'   => 'dashicons-admin-home',
			'title'  => __( 'Where the accounts live', 'diluxone-users' ),
			'value'  => diluxone_users_hub_name(),
			'detail' => __( 'The main site of the network. Its sign-in, registration and account pages, and how they look, are set on its own dashboard.', 'diluxone-users' ),
			'links'  => array(
				array(
					'url'   => get_admin_url( $hub, 'admin.php?page=' . DILUXONE_USERS_MENU ),
					'label' => __( 'Open its dashboard', 'diluxone-users' ),
				),
			),
		)
	);

	diluxone_users_ui_card(
		array(
			'icon'   => 'dashicons-networking',
			'title'  => __( 'How the sites are addressed', 'diluxone-users' ),
			'value'  => $subdomain ? __( 'Subdomains', 'diluxone-users' ) : __( 'Subdirectories', 'diluxone-users' ),
			'detail' => sprintf(
				/* translators: %s: number of sites */
				_n( '%s site on this network.', '%s sites on this network.', $sites, 'diluxone-users' ),
				number_format_i18n( $sites )
			),
		)
	);

	diluxone_users_ui_cards_close();
	diluxone_users_ui_wide_close();

	$mapped = diluxone_users_mapped_sites();

	if ( array() !== $mapped ) {
		$names = array_map(
			static fn( array $site ): string => '<code>' . esc_html( $site['domain'] ) . '</code>',
			$mapped
		);

		diluxone_users_ui_notice(
			array(
				sprintf(
					/* translators: %s: the addresses of the sites, each in <code> */
					esc_html__( 'These sites have a domain of their own: %s.', 'diluxone-users' ),
					implode( ', ', $names )
				),
				esc_html__( 'People have to sign in again on these sites: a session opened on the rest of the network does not reach a domain of its own. That is not supported in this version.', 'diluxone-users' ),
			),
			'warning'
		);
	}

	diluxone_users_network_conflicts_table();

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_ui_note(
				__( 'What a site can no longer change', 'diluxone-users' ),
				array(
					__( 'The second step, passkeys, how long a session lasts, the proxy, the social sign-in apps and their rules, the fields a person has and what the log keeps. A site administrator does not see these screens, and nothing sent to their own site writes them.', 'diluxone-users' ),
					__( 'What fits into each site’s theme is still that site’s: where the account link goes in its menus, its admin bar and its dashboard profile.', 'diluxone-users' ),
				)
			);

			$links = array();

			foreach ( diluxone_users_screens() as $slug => $title ) {
				if ( DILUXONE_USERS_MENU !== $slug && diluxone_users_screen_here( $slug, 'network' ) ) {
					$links[] = array(
						'url'   => network_admin_url( 'admin.php?page=' . $slug ),
						'label' => (string) $title,
					);
				}
			}

			diluxone_users_ui_links( __( 'Set for the whole network', 'diluxone-users' ), $links );
		}
	);
}

/**
 * The settings sites had differently before they became the network's.
 *
 * Kept after the notice that announced them is dismissed: whoever looks later
 * — the day somebody on one of those sites asks why their setting changed —
 * finds the answer where the settings are.
 */
function diluxone_users_network_conflicts_table(): void {
	$conflicts = (array) diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS, array() );

	if ( array() === $conflicts ) {
		return;
	}

	diluxone_users_ui_section(
		__( 'What the sites had set differently', 'diluxone-users' ),
		__( 'When the settings became the network’s, the main site’s were kept. These sites had something else, and now follow the network. The user fields are the exception: every site’s fields were kept.', 'diluxone-users' )
	);
	?>
	<table class="wp-list-table widefat striped diluxone-users-list">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Site', 'diluxone-users' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Setting', 'diluxone-users' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it had', 'diluxone-users' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $conflicts as $conflict ) : ?>
				<tr>
					<td><?php echo esc_html( (string) get_blog_option( (int) ( $conflict['site'] ?? 0 ), 'blogname' ) ); ?></td>
					<td><code><?php echo esc_html( (string) ( $conflict['key'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( diluxone_users_network_conflict_says( (array) $conflict ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * One line of that table, in words.
 *
 * @param array<string, mixed> $conflict As diluxone_users_network_migrate() records it.
 */
function diluxone_users_network_conflict_says( array $conflict ): string {
	switch ( (string) ( $conflict['kind'] ?? '' ) ) {
		case 'secret':
			return __( 'Different credentials, not shown. The main site’s are the network’s now.', 'diluxone-users' );
		case 'wipe':
			return __( 'Everything was to go when the plugin is deleted. The network’s answer starts unticked: it is decided on the Deleting the plugin tab.', 'diluxone-users' );
		case 'field':
			return sprintf(
				/* translators: %s: the key of a user field */
				__( 'A different definition of the field %s. The main site’s was kept.', 'diluxone-users' ),
				(string) ( $conflict['field'] ?? '' )
			);
		default:
			return (string) ( $conflict['was'] ?? '' );
	}
}

/**
 * Says once, in Network Admin, that some sites had set things differently.
 *
 * Once: a notice that comes back on every screen until somebody reads a table
 * is a notice nobody reads. Dismissing it only stops the notice; the table
 * stays on the network's Overview.
 */
function diluxone_users_network_conflicts_notice(): void {
	$conflicts = (array) diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS, array() );

	if ( array() === $conflicts || diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS_SEEN, 0 ) || ! current_user_can( DILUXONE_USERS_NETWORK_CAP ) ) {
		return;
	}

	$sites = array();

	foreach ( $conflicts as $conflict ) {
		$sites[ (int) ( $conflict['site'] ?? 0 ) ] = (string) get_blog_option( (int) ( $conflict['site'] ?? 0 ), 'blogname' );
	}

	printf(
		'<div class="notice notice-warning"><p>%1$s</p><p><a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p></div>',
		esc_html(
			sprintf(
				/* translators: 1: plugin name, 2: the names of the sites, comma separated */
				__( '%1$s now applies one set of settings to the whole network, taken from the main site. These sites had set some of them differently: %2$s.', 'diluxone-users' ),
				diluxone_users_plugin_name(),
				implode( ', ', array_filter( $sites ) )
			)
		),
		esc_url( network_admin_url( 'admin.php?page=' . DILUXONE_USERS_MENU ) ),
		esc_html__( 'See what was different', 'diluxone-users' ),
		esc_url( wp_nonce_url( network_admin_url( 'admin.php?page=' . DILUXONE_USERS_MENU . '&diluxone_users_conflicts_seen=1' ), 'diluxone_users_conflicts_seen' ) ),
		esc_html__( 'Dismiss', 'diluxone-users' )
	);
}
add_action( 'network_admin_notices', 'diluxone_users_network_conflicts_notice' );

/** Dismisses that notice, for good. */
function diluxone_users_network_conflicts_dismiss(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether to go on; the nonce is checked below.
	if ( ! isset( $_GET['diluxone_users_conflicts_seen'] ) || ! is_network_admin() || ! current_user_can( DILUXONE_USERS_NETWORK_CAP ) ) {
		return;
	}

	check_admin_referer( 'diluxone_users_conflicts_seen' );

	diluxone_users_update_option( DILUXONE_USERS_NETWORK_CONFLICTS_SEEN, 1 );

	wp_safe_redirect( network_admin_url( 'admin.php?page=' . DILUXONE_USERS_MENU ) );
	exit;
}
add_action( 'admin_init', 'diluxone_users_network_conflicts_dismiss' );

/* ── Deleting the plugin ───────────────────────────────────────────── */

/** Saves whether deleting the plugin takes everything with it. */
function diluxone_users_network_uninstall_save(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options( array( 'diluxone_users_uninstall_wipe' => isset( $_POST['diluxone_users_uninstall_wipe'] ) ? 1 : 0 ) );
}

/**
 * The one decision about deleting the plugin, for the whole network.
 *
 * It used to be every site's, and people's data only went when every site had
 * ticked it — a rule the network had to piece together by waiting. The data is
 * about the network's people, so it is the network's decision, taken once.
 */
function diluxone_users_screen_network_uninstall(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'By default, deleting the plugin removes nothing. The settings stay, every site’s activity log stays, and so does everything in people’s profiles — their details, their public names, their passkeys and their second factors. A plugin deleted by accident, or deleted to be installed again, should not be what loses somebody their account.', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_uninstall_wipe',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_uninstall_wipe' ),
				'title'   => __( 'Remove everything this plugin wrote when it is deleted', 'diluxone-users' ),
				'help'    => __( 'The network’s settings, every site’s settings and activity log, and what the plugin keeps in people’s profiles. It cannot be undone, and it runs on delete, not on deactivate.', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_ui_aside_state(
				diluxone_users_option( 'diluxone_users_uninstall_wipe' )
					? esc_html__( 'Deleting the plugin removes everything it wrote, on every site.', 'diluxone-users' )
					: esc_html__( 'Deleting the plugin leaves the data where it is.', 'diluxone-users' ),
				diluxone_users_option( 'diluxone_users_uninstall_wipe' ) ? 'active' : 'off'
			);
		}
	);
}

/* ── What a site's Overview says about the rest ────────────────────── */

/**
 * The areas a site no longer sets itself, as cards on its Overview.
 *
 * A site administrator used to find Security, Social login and User fields in
 * the menu. Gone without a word, they read as a plugin that lost half of
 * itself; so the Overview says where each one went and, for whoever can go
 * there, has the way in.
 */
function diluxone_users_managed_elsewhere(): void {
	$context = diluxone_users_admin_context();

	if ( 'hub' !== $context && 'site' !== $context ) {
		return;
	}

	$cards = array();

	foreach ( diluxone_users_screens() as $slug => $title ) {
		$scope = diluxone_users_panel_scope( $slug );

		if ( 'network' === $scope || ( 'hub' === $scope && 'site' === $context ) ) {
			$cards[] = array(
				'screen' => $slug,
				'tab'    => '',
				'title'  => (string) $title,
				'where'  => $scope,
			);
		}
	}

	// A tab that moved while its screen stayed: the log's settings.
	$cards[] = array(
		'screen' => DILUXONE_USERS_REPORTS,
		'tab'    => 'logging',
		'title'  => __( 'Log settings', 'diluxone-users' ),
		'where'  => 'network',
	);

	diluxone_users_ui_section(
		__( 'Set somewhere else', 'diluxone-users' ),
		'site' === $context
			? __( 'On this network the rules about people are the network’s, and the screens people sign in and keep their account on are the main site’s. This site keeps its own menus, admin bar, reports and maintenance.', 'diluxone-users' )
			: __( 'On this network the rules about people are the network’s: they apply to every site, and they are set in Network Admin.', 'diluxone-users' )
	);

	diluxone_users_ui_wide_open();
	diluxone_users_ui_cards_open();

	foreach ( $cards as $card ) {
		$links = array();

		if ( diluxone_users_admin_can_open( $card['where'] ) ) {
			$links[] = array(
				'url'   => diluxone_users_admin_url( $card['screen'], '' === $card['tab'] ? array() : array( 'tab' => $card['tab'] ) ),
				'label' => __( 'Open it', 'diluxone-users' ),
			);
		}

		diluxone_users_ui_card(
			array(
				'icon'   => 'network' === $card['where'] ? 'dashicons-networking' : 'dashicons-admin-home',
				'title'  => $card['title'],
				'detail' => 'network' === $card['where']
					? __( 'Managed by the network: one setting for every site, set in Network Admin.', 'diluxone-users' )
					: sprintf(
						/* translators: %s: the name of the network's main site */
						__( 'Set on %s, the site where people sign in and keep their account.', 'diluxone-users' ),
						diluxone_users_hub_name()
					),
				'links'  => $links,
			)
		);
	}

	diluxone_users_ui_cards_close();
	diluxone_users_ui_wide_close();
}
