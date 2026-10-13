<?php
/**
 * A network applies one set of rules to every site.
 *
 * Who gets in and how safely is set in Network Admin and read on every site;
 * a site's own screens can neither change it nor loosen it; the screens that
 * set it leave the sites' menus; and what fits into each site's theme stays
 * with the site.
 *
 * On a single site every test here is skipped: there is no network to apply
 * anything to.
 */

namespace Tests\Integration;

class NetworkSettingsTest extends IntegrationTestCase {

	/** @var array<int, int> Sites made for the test, deleted after it. */
	private array $sites = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			foreach ( $this->sites as $site ) {
				wp_delete_site( $site );
			}
		}

		$this->sites = array();

		parent::tearDown();
	}

	/** One more site on the network, deleted when the test ends. */
	private function site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/net-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Site ' . ( count( $this->sites ) + 1 ),
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	/** Sends the second-step tab as a form would. */
	private function save_second_step( string $mode ): void {
		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => $mode,
				'diluxone_users_2fa_methods' => array( 'email' ),
			)
		);

		diluxone_users_2fa_save();

		$_POST = array();
	}

	/* ── Set once, for every site ──────────────────────────────────── */

	public function test_a_setting_saved_in_network_admin_is_read_on_every_site(): void {
		$one = $this->site();
		$two = $this->site();

		$this->in_network_admin();
		$this->save_second_step( 'required' );

		foreach ( array( get_main_site_id(), $one, $two ) as $site ) {
			switch_to_blog( $site );
			$this->assertSame( 'required', diluxone_users_option( 'diluxone_users_2fa_mode' ), "Site {$site}" );
			restore_current_blog();
		}
	}

	/**
	 * A site's own screen writes nothing of the network's, whatever the form
	 * sends: the second step cannot be switched off from one site's dashboard.
	 */
	public function test_a_sites_screen_cannot_change_or_loosen_the_networks_settings(): void {
		$other = $this->site();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );

		// From the main site's dashboard…
		$this->save_second_step( 'off' );
		$this->assertSame( 'required', diluxone_users_option( 'diluxone_users_2fa_mode' ) );

		// …and from another site's.
		switch_to_blog( $other );
		$this->save_second_step( 'off' );
		$this->assertSame( 'required', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		restore_current_blog();
	}

	/** On a site that is not the hub, the hub's settings are not written either. */
	public function test_another_site_writes_only_its_own_settings(): void {
		$other = $this->site();

		diluxone_users_update_option( 'diluxone_users_login_title', 'The hub’s' );

		switch_to_blog( $other );
		diluxone_users_save_options(
			array(
				'diluxone_users_login_title'   => 'Mine',
				'diluxone_users_menu_location' => 'primary',
			)
		);
		$this->assertSame( 'The hub’s', diluxone_users_option( 'diluxone_users_login_title' ) );
		$this->assertSame( 'primary', diluxone_users_option( 'diluxone_users_menu_location' ) );
		restore_current_blog();

		$this->assertSame( '', (string) diluxone_users_raw_get( 'diluxone_users_menu_location', '' ), 'Where the account link goes is each site’s' );
	}

	/* ── Where each screen is ──────────────────────────────────────── */

	public function test_the_networks_screens_leave_the_sites_menus(): void {
		foreach ( array( 'diluxone-users-security', 'diluxone-users-social', 'diluxone-users-fields' ) as $screen ) {
			$this->assertTrue( diluxone_users_screen_here( $screen, 'network' ), $screen );
			$this->assertFalse( diluxone_users_screen_here( $screen, 'hub' ), $screen );
			$this->assertFalse( diluxone_users_screen_here( $screen, 'site' ), $screen );
		}

		// The hub's screens are the hub's; a site keeps what fits its theme.
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users-design', 'hub' ) );
		$this->assertFalse( diluxone_users_screen_here( 'diluxone-users-design', 'site' ) );
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users-account', 'site' ) );
		$this->assertFalse( diluxone_users_screen_here( 'diluxone-users-account', 'network' ) );

		// Reports stay on every site; the network has its log settings.
		$this->assertTrue( diluxone_users_screen_here( DILUXONE_USERS_REPORTS, 'site' ) );
		$this->assertTrue( diluxone_users_screen_here( DILUXONE_USERS_REPORTS, 'network' ) );
	}

	public function test_each_place_draws_only_its_own_tabs(): void {
		$this->assertArrayNotHasKey( 'logging', diluxone_users_panels( DILUXONE_USERS_REPORTS ), 'A site has the rows, not the rules' );
		$this->assertArrayHasKey( 'activity', diluxone_users_panels( DILUXONE_USERS_REPORTS ) );
		$this->assertArrayNotHasKey( 'network-activity', diluxone_users_panels( DILUXONE_USERS_REPORTS ), 'A site has its own rows, not everybody’s' );
		$this->assertSame( array(), diluxone_users_panels( DILUXONE_USERS_SECURITY ) );

		$this->in_network_admin();

		$this->assertSame( array( 'network-activity', 'logging' ), array_keys( diluxone_users_panels( DILUXONE_USERS_REPORTS ) ), 'Every site’s rows, and the rules' );
		$this->assertContains( '2fa', array_keys( diluxone_users_panels( DILUXONE_USERS_SECURITY ) ) );
		$this->assertSame( array( 'network', 'hub', 'previews', 'uninstall' ), array_keys( diluxone_users_panels( DILUXONE_USERS_MENU ) ) );
	}

	public function test_a_link_to_a_screen_goes_where_the_screen_is(): void {
		$other = $this->site();

		$this->assertStringStartsWith( network_admin_url(), diluxone_users_admin_url( DILUXONE_USERS_SECURITY ) );
		$this->assertStringStartsWith( network_admin_url(), diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array( 'tab' => 'logging' ) ) );

		switch_to_blog( $other );
		$this->assertStringStartsWith( get_admin_url( get_main_site_id() ), diluxone_users_admin_url( 'diluxone-users-design' ), 'The hub’s screens are on the hub' );
		$this->assertStringStartsWith( admin_url(), diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array( 'tab' => 'activity' ) ) );
		restore_current_blog();
	}

	/**
	 * A site's Overview says where each area went, and offers the way there
	 * only to whoever can go: a site administrator is not sent to a Network
	 * Admin that refuses them.
	 */
	public function test_a_site_administrator_is_told_where_things_went_and_not_sent_there(): void {
		$other = $this->site();
		$admin = $this->make_user( 'subscriber' );
		add_user_to_blog( $other, $admin, 'administrator' );
		wp_set_current_user( $admin );

		switch_to_blog( $other );

		ob_start();
		diluxone_users_managed_elsewhere();
		$said = (string) ob_get_clean();

		$this->assertStringContainsString( 'Managed by the network', $said );
		$this->assertStringNotContainsString( '/wp-admin/network/', $said );
		$this->assertSame( array(), diluxone_users_home_card_link( 'diluxone-users-social', 'Set them up' ) );

		restore_current_blog();
	}

	/**
	 * A hub page is a page of the hub. On another site the same number is some
	 * other page: it is neither drawn as the sign-in page nor routed as one,
	 * and a link to it goes to the hub.
	 */
	public function test_the_pages_are_the_hubs(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Hub sign-in',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		$url = (string) get_permalink( $page );

		$other = $this->site();
		switch_to_blog( $other );

		$this->assertSame( 0, diluxone_users_page_here( 'diluxone_users_login_page' ) );
		$this->assertSame( $url, diluxone_users_page_url( 'diluxone_users_login_page' ) );
		// The hub's page, with the way back to this site (NetworkHubTest).
		$this->assertSame( $url, strtok( diluxone_users_login_url(), '?' ) );

		restore_current_blog();

		$this->assertSame( $page, diluxone_users_page_here( 'diluxone_users_login_page' ) );

		wp_delete_post( $page, true );
	}

	/* ── What each save writes, from where ─────────────────────────── */

	/**
	 * Social sign-in is switched on Access on a single site; on a network
	 * there is no Access in Network Admin, so its own tab carries the switch —
	 * and only there.
	 */
	public function test_the_networks_doors_are_switched_on_their_own_tabs_in_network_admin(): void {
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$this->postPanel( 'diluxone-users-social', array() );
		diluxone_users_social_rules_save();
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_sso_login' ), 'Not from a site' );

		$this->in_network_admin();

		$this->postPanel( 'diluxone-users-social', array() );
		diluxone_users_social_rules_save();
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_sso_login' ), 'Unticked, it is off' );

		$_POST = array();
	}

	/**
	 * "Show me what I chose" runs a tab's save and writes nothing: on a network
	 * that includes the settings that go to the network's own options, which
	 * have no filter for every write.
	 */
	public function test_a_trial_run_writes_nothing_of_the_networks(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		$this->in_network_admin();

		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'required',
				'diluxone_users_2fa_methods' => array( 'email' ),
			)
		);
		$caught = diluxone_users_preview_would_save( diluxone_users_panels( DILUXONE_USERS_SECURITY )['2fa'] );
		$_POST  = array();

		$this->assertSame( 'required', $caught['diluxone_users_2fa_mode'] ?? null, 'What it would have written' );
		$this->assertSame( 'optional', get_site_option( 'diluxone_users_2fa_mode' ), 'and did not' );
	}
}
