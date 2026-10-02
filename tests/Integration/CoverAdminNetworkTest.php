<?php
/**
 * Network Admin: its menu, its front page, the decision about deleting the
 * plugin, and the Membership screen.
 *
 * The network's screens exist on a network only, are drawn for whoever has
 * the network's capability, and save only behind their screen's nonce and
 * that capability. A site administrator in Network Admin saves nothing. On a
 * single site there is no network: no menu, no tabs, nothing registered.
 *
 * Most cases are a network's; each says which single-site case is its
 * counterpart, and those skip on a network naming theirs.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminNetworkTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var array<string, mixed> The admin menu's globals before the test. */
	private array $menu_globals = array();

	/** @var array<string, mixed> */
	private array $conflicts = array();

	/** @var array<int, int> Sites the test made. */
	private array $sites = array();

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		foreach ( array( 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks' ) as $global ) {
			$this->menu_globals[ $global ] = $GLOBALS[ $global ] ?? null;
		}

		foreach ( array( DILUXONE_USERS_NETWORK_CONFLICTS, DILUXONE_USERS_NETWORK_CONFLICTS_SEEN ) as $key ) {
			$this->conflicts[ $key ] = diluxone_users_raw_get( $key, null );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->menu_globals as $global => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $global ] );
			} else {
				$GLOBALS[ $global ] = $value;
			}
		}

		foreach ( $this->conflicts as $key => $value ) {
			if ( null === $value ) {
				diluxone_users_delete_option( $key );
			} else {
				diluxone_users_update_option( $key, $value );
			}
		}

		remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		if ( is_multisite() ) {
			diluxone_users_membership_queue_save( array() );
			diluxone_users_membership_unlock();
			diluxone_users_membership_unschedule();
		}

		$this->cover_admin_reset();

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		$this->sites = array();

		parent::tearDown();
	}

	/** A site of the network with a domain of its own. */
	private function mapped_site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => 'cover-' . strtolower( wp_generate_password( 6, false, false ) ) . '.example.org',
				'path'   => '/',
				'title'  => 'Mapped one',
			)
		);

		$this->sites[] = $site;

		return $site;
	}

	/* ── The menu ──────────────────────────────────────────────────── */

	public function test_the_network_menu_holds_the_networks_screens(): void {
		$this->network_only( 'test_a_single_site_has_no_network_menu_and_the_whole_menu_on_its_own' );

		$this->in_network_admin();
		$this->the_admin();

		diluxone_users_network_menu();

		$slugs = array_column( $GLOBALS['submenu'][ DILUXONE_USERS_MENU ] ?? array(), 2 );

		$this->assertContains( 'diluxone-users-security', $slugs );
		$this->assertContains( 'diluxone-users-fields', $slugs );
		$this->assertContains( 'diluxone-users-membership', $slugs );
		$this->assertNotContains( 'diluxone-users-design', $slugs, 'the hub’s screens are on the hub' );

		foreach ( $GLOBALS['submenu'][ DILUXONE_USERS_MENU ] as $item ) {
			$this->assertSame( DILUXONE_USERS_NETWORK_CAP, $item[1] );
		}
	}

	public function test_a_single_site_has_no_network_menu_and_the_whole_menu_on_its_own(): void {
		$this->single_only( 'test_the_network_menu_holds_the_networks_screens' );

		$this->the_admin();
		$GLOBALS['submenu'] = array();

		diluxone_users_network_menu();
		$this->assertArrayNotHasKey( DILUXONE_USERS_MENU, $GLOBALS['submenu'] );

		diluxone_users_menu();
		$slugs = array_column( $GLOBALS['submenu'][ DILUXONE_USERS_MENU ], 2 );

		$this->assertContains( 'diluxone-users-security', $slugs );
		$this->assertContains( 'diluxone-users-design', $slugs );
		$this->assertNotContains( 'diluxone-users-membership', $slugs, 'membership is a network’s' );
	}

	public function test_on_a_network_the_hubs_menu_leaves_the_networks_screens_out(): void {
		$this->network_only( 'test_a_single_site_has_no_network_menu_and_the_whole_menu_on_its_own' );

		$this->the_admin();
		$GLOBALS['submenu'] = array();

		diluxone_users_menu();
		$slugs = array_column( $GLOBALS['submenu'][ DILUXONE_USERS_MENU ], 2 );

		$this->assertContains( 'diluxone-users-design', $slugs );
		$this->assertNotContains( 'diluxone-users-security', $slugs );
		$this->assertNotContains( 'diluxone-users-fields', $slugs );
	}

	/* ── The network's front page ─────────────────────────────────── */

	public function test_the_overview_names_the_sites_with_a_domain_of_their_own_and_what_sites_had(): void {
		$this->network_only( 'test_a_single_site_registers_none_of_the_networks_tabs' );

		$this->mapped_site();
		$main = get_main_site_id();

		diluxone_users_update_option(
			DILUXONE_USERS_NETWORK_CONFLICTS,
			array(
				array( 'site' => $main, 'key' => 'diluxone_users_sso', 'kind' => 'secret' ),
				array( 'site' => $main, 'key' => 'diluxone_users_uninstall_wipe', 'kind' => 'wipe' ),
				array( 'site' => $main, 'key' => 'diluxone_users_fields', 'kind' => 'field', 'field' => 'diluxone_users_shoe' ),
				array( 'site' => $main, 'key' => 'diluxone_users_2fa_mode', 'kind' => 'value', 'was' => 'off' ),
			)
		);

		$this->in_network_admin();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_network_overview' );

		$this->assertStringContainsString( 'These sites have a domain of their own', $html );
		$this->assertMatchesRegularExpression( '#<code>cover-[a-z0-9]+\.example\.org</code>#', $html );
		$this->assertStringContainsString( 'What the sites had set differently', $html );
		$this->assertStringContainsString( 'Different credentials, not shown.', $html );
		$this->assertStringContainsString( 'Everything was to go when the plugin is deleted.', $html );
		$this->assertStringContainsString( 'A different definition of the field diluxone_users_shoe.', $html );
		$this->assertStringContainsString( '<code>diluxone_users_2fa_mode</code>', $html );
		$this->assertStringContainsString( 'page=diluxone-users-security', $html, 'the way to every network screen' );
	}

	public function test_the_conflicts_notice_shows_once_and_only_where_it_should(): void {
		$this->network_only( 'test_a_single_site_registers_none_of_the_networks_tabs' );

		diluxone_users_update_option(
			DILUXONE_USERS_NETWORK_CONFLICTS,
			array( array( 'site' => get_main_site_id(), 'key' => 'diluxone_users_2fa_mode', 'kind' => 'value', 'was' => 'off' ) )
		);

		$this->in_network_admin();
		$this->the_admin();

		$pagenow            = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['pagenow'] = 'admin.php';
		$_GET               = array( 'page' => 'diluxone-users' );

		try {
			$html = $this->draw( 'diluxone_users_network_conflicts_notice' );
		} finally {
			$GLOBALS['pagenow'] = $pagenow;
		}

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( get_blog_option( get_main_site_id(), 'blogname' ), $html );
		$this->assertStringContainsString( 'diluxone_users_conflicts_seen=1', $html );

		// The dismissal does nothing unless asked for, and nothing outside Network Admin.
		$_GET = array();
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_network_conflicts_dismiss' )[0] );

		unset( $GLOBALS['current_screen'] );
		$_GET = array( 'diluxone_users_conflicts_seen' => '1' );
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_network_conflicts_dismiss' )[0] );
		$this->assertNull( diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS_SEEN, null ) );
	}

	/* ── Deleting the plugin ───────────────────────────────────────── */

	public function test_the_network_saves_its_answer_about_deleting_the_plugin_behind_its_nonce(): void {
		$this->network_only( 'test_a_single_site_registers_none_of_the_networks_tabs' );

		$this->in_network_admin();
		$this->the_admin();

		$this->postPanel( DILUXONE_USERS_MENU, array( 'diluxone_users_uninstall_wipe' => '1' ) );
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_network_uninstall_save' )[0] );
		$this->assertSame( 1, (int) get_site_option( 'diluxone_users_uninstall_wipe' ) );

		$html = $this->draw( 'diluxone_users_screen_network_uninstall' );
		$this->assertStringContainsString( 'Deleting the plugin removes everything it wrote, on every site.', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_uninstall_wipe"\s+value="1"\s+checked/', $html );

		$this->postPanel( DILUXONE_USERS_MENU, array() );
		$this->ended( 'diluxone_users_network_uninstall_save' );
		$this->assertSame( 0, (int) get_site_option( 'diluxone_users_uninstall_wipe' ) );
		$this->assertStringContainsString( 'leaves the data where it is', $this->draw( 'diluxone_users_screen_network_uninstall' ) );
	}

	public function test_a_site_administrator_in_network_admin_cannot_change_it(): void {
		$this->network_only( 'test_a_single_site_registers_none_of_the_networks_tabs' );

		$this->in_network_admin();
		$admin = $this->site_admin();

		$this->postAs( $admin, array( 'diluxone_users_uninstall_wipe' => '1', 'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_MENU ) ) );
		$this->expectDie( 'diluxone_users_network_uninstall_save', 'You are not allowed to do this.', 403 );

		$this->the_admin();
		$this->postAs( get_current_user_id(), array( 'diluxone_users_uninstall_wipe' => '1', 'diluxone_users_panel_nonce' => 'nope' ) );
		$this->expectDie( 'diluxone_users_network_uninstall_save', self::EXPIRED, 403 );

		$this->assertSame( 0, (int) get_site_option( 'diluxone_users_uninstall_wipe', 0 ) );
	}

	public function test_a_single_site_registers_none_of_the_networks_tabs(): void {
		$this->single_only( 'test_the_network_saves_its_answer_about_deleting_the_plugin_behind_its_nonce' );

		$this->the_admin();
		do_action( 'diluxone_users_register_panels' );

		$this->assertArrayNotHasKey( 'network', diluxone_users_panels( DILUXONE_USERS_MENU ) );
		$this->assertArrayNotHasKey( 'uninstall', diluxone_users_panels( DILUXONE_USERS_MENU ) );
		$this->assertSame( array(), diluxone_users_panels( DILUXONE_USERS_MEMBERSHIP_SCREEN ) );
	}

	/* ── Membership ────────────────────────────────────────────────── */

	public function test_confirming_every_site_on_a_big_network_queues_the_work_and_says_so(): void {
		$this->network_only( 'test_a_single_site_registers_none_of_the_networks_tabs' );

		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		$this->in_network_admin();
		$this->the_admin();

		$this->postPanel( DILUXONE_USERS_MEMBERSHIP_SCREEN, array( DILUXONE_USERS_MEMBERSHIP => 'all' ) );
		$html = $this->draw( 'diluxone_users_membership_save' );

		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringContainsString( 'it goes on in the background', $html );
		$this->assertTrue( diluxone_users_membership_confirmed() );
		$this->assertNotSame( array(), diluxone_users_membership_queue(), 'the work is queued, not done' );
	}

	public function test_the_policy_tab_shows_the_queue_and_the_sync_button_once_confirmed(): void {
		$this->network_only( 'test_a_single_site_registers_none_of_the_networks_tabs' );

		$this->in_network_admin();
		$this->the_admin();

		$person = $this->make_user();
		$site   = $this->mapped_site();

		diluxone_users_save_options(
			array(
				DILUXONE_USERS_MEMBERSHIP           => 'all',
				DILUXONE_USERS_MEMBERSHIP_CONFIRMED => 1,
			)
		);
		diluxone_users_membership_queue_save(
			array(
				array( 'kind' => 'user', 'id' => $person, 'after' => 0, 'done' => 1, 'total' => 4 ),
				array( 'kind' => 'user', 'id' => 999999, 'after' => 0, 'done' => 0, 'total' => 2 ),
				array( 'kind' => 'site', 'id' => $site, 'after' => 0, 'done' => 2, 'total' => 2 ),
				array( 'kind' => 'all', 'id' => 0, 'after' => 0, 'done' => 0, 'total' => 10 ),
			)
		);

		$_GET = array( 'diluxone-users-synced' => 'queued' );
		$html = $this->draw( 'diluxone_users_screen_membership_policy' );

		$this->assertStringContainsString( 'Adding everybody to every site has started.', $html );
		$this->assertStringContainsString( 'A new account, ' . get_userdata( $person )->user_email . ', on every site — 25%', $html );
		$this->assertStringContainsString( 'A new account, #999999, on every site', $html, 'an account gone since is named by its number' );
		$this->assertStringContainsString( 'Every account on a new site, Mapped one — 100%', $html );
		$this->assertStringContainsString( 'Everybody on every site', $html );
		$this->assertStringContainsString( 'action=diluxone_users_membership_sync', $html );
		$this->assertStringContainsString( 'data-diluxone-users-confirm=', $html );
		$this->assertStringContainsString( '4 jobs are adding people in the background.', $html );

		diluxone_users_membership_queue_save( array() );
		$_GET = array( 'diluxone-users-synced' => 'done' );
		$html = $this->draw( 'diluxone_users_screen_membership_policy' );

		$this->assertStringContainsString( 'Everybody is a member of every live site now.', $html );
		$this->assertStringNotContainsString( 'data-diluxone-users-job', $html );
		$this->assertMatchesRegularExpression( '/live sites?\./', $html );
	}
}
