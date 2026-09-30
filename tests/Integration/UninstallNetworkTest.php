<?php
/**
 * Deleting the plugin from a network, both ways it can have been on.
 *
 * Network-activated, the network's settings are the network's: one box, in
 * Network Admin. Ticked it takes everything — the network's settings, every
 * site's settings and log, the copies the sites kept from before the move,
 * what the plugin kept in people's profiles. Unticked it takes nothing, and a
 * site's own old box does not count.
 *
 * Switched on site by site, each site kept its own settings and its own box:
 * each site that ticked it loses its settings and its log, and the people's
 * data goes only when every site that used the plugin ticked it.
 *
 * uninstall.php declares its functions when it is loaded, so it can be loaded
 * once per process: every test here runs in a process of its own, and puts
 * back what it took so the tests after it find a plugin that is installed.
 */

namespace Tests\Integration;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallNetworkTest extends IntegrationTestCase {

	/** @var array<int, int> Sites made for the test, deleted after it. */
	private array $sites = array();

	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: the single-site half is UninstallSiteTest.' );
		}

		$this->user = $this->make_user();

		update_user_meta( $this->user, 'diluxone_users_phone', '555' );
		update_user_meta( $this->user, 'diluxone_users_team', 'Blue' );
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
	}

	protected function tearDown(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			foreach ( $this->sites as $site ) {
				wp_delete_site( $site );
			}

			// Installed again, as the rest of the suite expects to find it.
			foreach ( $this->all_sites() as $site ) {
				switch_to_blog( $site );
				delete_option( 'diluxone_users_uninstall_wipe' );
				delete_option( 'diluxone_users_2fa_mode' );
				delete_option( 'diluxone_users_fields' );
				diluxone_users_site_setup();
				restore_current_blog();
			}

			update_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_VERSION );
		}

		$this->sites = array();

		parent::tearDown();
	}

	/** @return array<int, int> */
	private function all_sites(): array {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
	}

	/** One more site on the network, deleted when the test ends. */
	private function site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/gone-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Gone',
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	/**
	 * A site's own copy of a setting and of a field list.
	 *
	 * @param string $field The key of a field only this site has.
	 */
	private function site_keeps( int $site, string $field ): void {
		update_blog_option( $site, 'diluxone_users_2fa_mode', 'off' );
		update_blog_option(
			$site,
			'diluxone_users_fields',
			array(
				array(
					'key'   => $field,
					'label' => $field,
				),
			)
		);
	}

	/** What deleting the plugin runs, the way WordPress runs it. */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( DILUXONE_USERS_FILE ) );
		}

		require DILUXONE_USERS_DIR . 'uninstall.php';

		// The request that deletes a plugin ends there; the next one starts
		// with nothing in memory. The rows are what is being asked about.
		wp_cache_flush();
	}

	private function log_table_exists( int $site ): bool {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/* ── Network-activated ─────────────────────────────────────────── */

	/** The network's settings, and a site's copy from before the move. */
	private function network_activated(): int {
		$site = $this->site();

		$this->assertNotFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ), 'The settings are the network’s' );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'   => 'diluxone_users_phone',
					'label' => 'Phone',
				),
			)
		);
		$this->site_keeps( $site, 'diluxone_users_team' );

		return $site;
	}

	public function test_the_networks_one_box_takes_everything_on_every_site(): void {
		$site = $this->network_activated();
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );

		$this->uninstall();

		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s settings' );
		$this->assertFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertFalse( get_blog_option( $site, 'diluxone_users_2fa_mode' ), 'The copy the site kept' );
		$this->assertFalse( $this->log_table_exists( $site ), 'Its log' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The network’s field' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_team', true ), 'The field only the site had' );
	}

	/** Unticked, nothing goes — and a site's own box from before the move is not the network's. */
	public function test_the_networks_box_unticked_takes_nothing(): void {
		$site = $this->network_activated();
		update_blog_option( $site, 'diluxone_users_uninstall_wipe', 1 );

		$this->uninstall();

		$this->assertSame( 'required', get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s settings' );
		$this->assertNotFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertSame( 'off', get_blog_option( $site, 'diluxone_users_2fa_mode' ), 'The copy the site kept' );
		$this->assertTrue( $this->log_table_exists( $site ), 'Its log' );
		$this->assertTrue( $this->log_table_exists( get_main_site_id() ), 'The main site’s log' );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '555', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The answers' );
	}

	/* ── Switched on site by site ──────────────────────────────────── */

	/**
	 * A network whose settings were never the network's: no version marker,
	 * and each site with its own. Two sites here: the main one and another.
	 *
	 * @return int The other site.
	 */
	private function per_site(): int {
		$site = $this->site();

		delete_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION );
		update_site_option( 'diluxone_users_2fa_mode', 'required' );

		$this->site_keeps( get_main_site_id(), 'diluxone_users_phone' );
		$this->site_keeps( $site, 'diluxone_users_team' );

		return $site;
	}

	/** Every site that used it ticked the box: everything goes, the people's data too. */
	public function test_site_by_site_every_box_ticked_takes_everything(): void {
		$site = $this->per_site();

		foreach ( $this->all_sites() as $each ) {
			update_blog_option( $each, 'diluxone_users_uninstall_wipe', 1 );
		}

		$this->uninstall();

		foreach ( array( get_main_site_id(), $site ) as $each ) {
			$this->assertFalse( get_blog_option( $each, 'diluxone_users_2fa_mode' ), "Site {$each}’s settings" );
			$this->assertFalse( get_blog_option( $each, 'diluxone_users_uninstall_wipe' ), "Site {$each}’s box" );
			$this->assertFalse( $this->log_table_exists( $each ), "Site {$each}’s log" );
		}

		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s options' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The main site’s field' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_team', true ), 'The other site’s field' );
	}

	/**
	 * One site that used it did not tick the box: the site that did loses its
	 * own settings and log, and the people's data — everybody's — stays.
	 */
	public function test_site_by_site_one_box_unticked_keeps_the_people(): void {
		$site = $this->per_site();

		update_blog_option( get_main_site_id(), 'diluxone_users_uninstall_wipe', 1 );

		$this->uninstall();

		$this->assertFalse( get_blog_option( get_main_site_id(), 'diluxone_users_2fa_mode' ), 'The site that ticked it' );
		$this->assertFalse( $this->log_table_exists( get_main_site_id() ), 'and its log' );

		$this->assertSame( 'off', get_blog_option( $site, 'diluxone_users_2fa_mode' ), 'The site that did not' );
		$this->assertNotFalse( get_blog_option( $site, 'diluxone_users_fields' ) );
		$this->assertTrue( $this->log_table_exists( $site ), 'and its log' );

		$this->assertSame( 'required', get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s options' );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '555', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The answers' );
		$this->assertSame( 'Blue', get_user_meta( $this->user, 'diluxone_users_team', true ) );
	}
}
