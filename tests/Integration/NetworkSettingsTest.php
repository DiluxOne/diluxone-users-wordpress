<?php
/**
 * A network applies one set of rules to every site.
 *
 * Who gets in and how safely is set in Network Admin and read on every site;
 * a site's own screens can neither change it nor loosen it; the screens that
 * set it leave the sites' menus; what fits into each site's theme stays with
 * the site; and a network that ran with per-site settings gets the main
 * site's as the network's, with what the other sites had written down.
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

			foreach ( array( DILUXONE_USERS_NETWORK_CONFLICTS, DILUXONE_USERS_NETWORK_MIGRATING, DILUXONE_USERS_NETWORK_CONFLICTS_SEEN ) as $key ) {
				delete_site_option( $key );
			}

			update_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_VERSION );
			wp_clear_scheduled_hook( DILUXONE_USERS_NETWORK_MIGRATE_EVENT );
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
		$_POST = array(
			'diluxone_users_2fa_mode'    => $mode,
			'diluxone_users_2fa_methods' => array( 'email' ),
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
		$this->assertSame( array( 'network', 'uninstall' ), array_keys( diluxone_users_panels( DILUXONE_USERS_MENU ) ) );
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
	 * Passkeys and social sign-in are switched on Access on a single site; on
	 * a network there is no Access in Network Admin, so their own tabs carry
	 * the switch — and only there.
	 */
	public function test_the_networks_doors_are_switched_on_their_own_tabs_in_network_admin(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 0 );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$_POST = array( 'diluxone_users_passkey_enabled' => '1' );
		diluxone_users_passkeys_settings_save();
		diluxone_users_social_rules_save();
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_passkey_enabled' ), 'Not from a site' );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_sso_login' ), 'Not from a site' );

		$this->in_network_admin();

		diluxone_users_passkeys_settings_save();
		diluxone_users_social_rules_save();
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_passkey_enabled' ) );
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

		$_POST  = array(
			'diluxone_users_2fa_mode'    => 'required',
			'diluxone_users_2fa_methods' => array( 'email' ),
		);
		$caught = diluxone_users_preview_would_save( diluxone_users_panels( DILUXONE_USERS_SECURITY )['2fa'] );
		$_POST  = array();

		$this->assertSame( 'required', $caught['diluxone_users_2fa_mode'] ?? null, 'What it would have written' );
		$this->assertSame( 'optional', get_site_option( 'diluxone_users_2fa_mode' ), 'and did not' );
	}

	/** Once, and dismissed for good by whoever administers the network. */
	public function test_the_differences_are_announced_once(): void {
		$admin = $this->make_user( 'administrator' );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );

		diluxone_users_update_option(
			DILUXONE_USERS_NETWORK_CONFLICTS,
			array(
				'1|diluxone_users_2fa_mode|' => array(
					'site' => get_main_site_id(),
					'key'  => 'diluxone_users_2fa_mode',
					'kind' => 'value',
					'was'  => 'off',
				),
			)
		);

		ob_start();
		diluxone_users_network_conflicts_notice();
		$this->assertStringContainsString( 'had set some of them differently', (string) ob_get_clean() );

		$this->in_network_admin();
		$_GET                 = array( 'diluxone_users_conflicts_seen' => '1' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_conflicts_seen' );
		$this->expectRedirect( 'diluxone_users_network_conflicts_dismiss' );

		ob_start();
		diluxone_users_network_conflicts_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'Dismissed is dismissed' );

		revoke_super_admin( $admin );
	}

	/* ── The move from each site to the network ────────────────────── */

	/** A network where nobody set anything: the marker, and nothing else. */
	public function test_on_a_fresh_network_the_move_finds_nothing_and_marks_itself_done(): void {
		$this->before_the_move();

		// No site holds a copy of any of the network's settings.
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site ) {
			foreach ( diluxone_users_network_moved_keys() as $key ) {
				delete_blog_option( (int) $site, $key );
			}
		}

		$this->assertTrue( diluxone_users_network_migrate() );

		$this->assertSame( DILUXONE_USERS_NETWORK_VERSION, (int) get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertFalse( get_site_option( DILUXONE_USERS_NETWORK_CONFLICTS ) );
		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ), 'Nothing to take: the defaults stand' );
		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
	}

	/** The network as it was before its settings were the network's. */
	private function before_the_move(): void {
		foreach ( diluxone_users_network_moved_keys() as $key ) {
			delete_site_option( $key );
		}

		foreach ( array( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_CONFLICTS, DILUXONE_USERS_NETWORK_MIGRATING, DILUXONE_USERS_NETWORK_CONFLICTS_SEEN, 'diluxone_users_uninstall_wipe' ) as $key ) {
			delete_site_option( $key );
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function conflicts_of( int $site ): array {
		return array_filter(
			(array) get_site_option( DILUXONE_USERS_NETWORK_CONFLICTS, array() ),
			static fn( array $conflict ): bool => $site === (int) $conflict['site']
		);
	}

	public function test_the_move_takes_the_main_sites_values_and_writes_down_the_rest(): void {
		$other = $this->site();
		$this->before_the_move();

		update_blog_option( get_main_site_id(), 'diluxone_users_2fa_mode', 'required' );
		update_blog_option( get_main_site_id(), 'diluxone_users_session_long_days', 60 );
		update_blog_option( $other, 'diluxone_users_2fa_mode', 'off' );
		update_blog_option( $other, 'diluxone_users_session_long_days', 60 );

		$this->assertTrue( diluxone_users_network_migrate() );

		$this->assertSame( 'required', get_site_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 60, (int) get_site_option( 'diluxone_users_session_long_days' ) );

		$found = array_values( $this->conflicts_of( $other ) );

		$this->assertCount( 1, $found, 'Only what differs: the same number of days is no conflict' );
		$this->assertSame( 'diluxone_users_2fa_mode', $found[0]['key'] );
		$this->assertSame( 'off', $found[0]['was'] );

		$this->assertSame( DILUXONE_USERS_NETWORK_VERSION, (int) get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertSame( 'off', get_blog_option( $other, 'diluxone_users_2fa_mode' ), 'The old copy stays until the plugin is deleted' );
	}

	/** A credential is never copied into the list of differences. */
	public function test_credentials_are_only_noted_as_different(): void {
		$other = $this->site();
		$this->before_the_move();

		update_blog_option( get_main_site_id(), 'diluxone_users_sso', array( 'google' => array( 'id' => 'main-id', 'secret' => 'main-secret' ) ) );
		update_blog_option( $other, 'diluxone_users_sso', array( 'google' => array( 'id' => 'other-id', 'secret' => 'other-secret' ) ) );

		diluxone_users_network_migrate();

		$this->assertSame( 'main-secret', get_site_option( 'diluxone_users_sso' )['google']['secret'] );

		$found = array_values( $this->conflicts_of( $other ) );

		$this->assertSame( 'secret', $found[0]['kind'] );
		$this->assertStringNotContainsString( 'other-secret', (string) wp_json_encode( get_site_option( DILUXONE_USERS_NETWORK_CONFLICTS ) ) );
		$this->assertStringNotContainsString( 'other-id', (string) wp_json_encode( get_site_option( DILUXONE_USERS_NETWORK_CONFLICTS ) ) );
	}

	/**
	 * Every site's fields are kept: a field is where people's answers are, and
	 * a field one site had would otherwise leave them with nothing to show
	 * them. The main site's definition wins where two define one key.
	 */
	public function test_the_fields_are_every_sites_with_the_main_sites_winning(): void {
		$other = $this->site();
		$this->before_the_move();

		update_blog_option(
			get_main_site_id(),
			'diluxone_users_fields',
			array(
				array(
					'key'   => 'diluxone_users_phone',
					'label' => 'Phone',
				),
				array(
					'key'   => 'diluxone_users_city',
					'label' => 'City',
				),
			)
		);
		update_blog_option(
			$other,
			'diluxone_users_fields',
			array(
				array(
					'key'   => 'diluxone_users_phone',
					'label' => 'Mobile',
				),
				array(
					'key'   => 'diluxone_users_team',
					'label' => 'Team',
				),
			)
		);

		diluxone_users_network_migrate();

		$fields = (array) get_site_option( 'diluxone_users_fields' );
		$keys   = array_column( $fields, 'key' );

		// The main site's first, in its order; then whatever the other sites
		// of this network had that it did not, this one's among them.
		$this->assertSame( array( 'diluxone_users_phone', 'diluxone_users_city' ), array_slice( $keys, 0, 2 ) );
		$this->assertContains( 'diluxone_users_team', $keys );
		$this->assertSame( 1, count( array_keys( $keys, 'diluxone_users_phone', true ) ), 'One field per key' );
		$this->assertSame( 'Phone', $fields[0]['label'] );

		$found = array_values( $this->conflicts_of( $other ) );

		$this->assertSame( 'field', $found[0]['kind'] );
		$this->assertSame( 'diluxone_users_phone', $found[0]['field'] );
	}

	/** Deleting everybody's data is not something one site decides for all. */
	public function test_the_wipe_is_not_carried_over(): void {
		$this->before_the_move();

		update_blog_option( get_main_site_id(), 'diluxone_users_uninstall_wipe', 1 );

		diluxone_users_network_migrate();

		$this->assertFalse( get_site_option( 'diluxone_users_uninstall_wipe' ) );
		$this->assertSame( 'wipe', array_values( $this->conflicts_of( get_main_site_id() ) )[0]['kind'] );

		delete_blog_option( get_main_site_id(), 'diluxone_users_uninstall_wipe' );
	}

	/**
	 * Running it again does nothing: what the network already has is not
	 * overwritten, and what was written down is not written twice.
	 */
	public function test_the_move_is_idempotent(): void {
		$other = $this->site();
		$this->before_the_move();

		update_blog_option( get_main_site_id(), 'diluxone_users_2fa_mode', 'required' );
		update_blog_option( $other, 'diluxone_users_2fa_mode', 'off' );

		diluxone_users_network_migrate();
		update_site_option( 'diluxone_users_2fa_mode', 'optional' );

		$this->assertTrue( diluxone_users_network_migrate(), 'Finished is finished' );

		delete_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION );
		diluxone_users_network_migrate();

		$this->assertSame( 'optional', get_site_option( 'diluxone_users_2fa_mode' ), 'What the network was given since is kept' );
		$this->assertCount( 1, $this->conflicts_of( $other ) );
	}

	/**
	 * On a network of many sites it runs in batches, and the version is only
	 * written when the last one is done: an interrupted move is a move not
	 * done, and it carries on from where it stopped.
	 */
	public function test_the_version_is_written_last(): void {
		$one = $this->site();
		$two = $this->site();
		$this->before_the_move();

		update_blog_option( get_main_site_id(), 'diluxone_users_2fa_mode', 'required' );
		update_blog_option( $one, 'diluxone_users_2fa_mode', 'off' );
		update_blog_option( $two, 'diluxone_users_2fa_mode', 'off' );

		$total = count(
			get_sites(
				array(
					'fields'       => 'ids',
					'number'       => 0,
					'site__not_in' => array( get_main_site_id() ),
				)
			)
		);
		$runs  = 0;

		while ( ! diluxone_users_network_migrate( 1 ) ) {
			++$runs;
			$this->assertFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ), 'Not written while sites are left' );
			$this->assertIsArray( get_site_option( DILUXONE_USERS_NETWORK_MIGRATING ) );
			$this->assertNotFalse( wp_next_scheduled( DILUXONE_USERS_NETWORK_MIGRATE_EVENT ), 'The rest is left to cron' );
		}

		$this->assertSame( $total, $runs );
		$this->assertSame( DILUXONE_USERS_NETWORK_VERSION, (int) get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertFalse( get_site_option( DILUXONE_USERS_NETWORK_MIGRATING ) );
		$this->assertCount( 1, $this->conflicts_of( $one ) );
		$this->assertCount( 1, $this->conflicts_of( $two ) );
	}
}
