<?php
/**
 * Deleting the plugin from a network.
 *
 * The network's settings are the network's: one box, in Network Admin.
 * Ticked it takes everything — the network's settings and its log, every
 * site's settings, the copies and the old log tables the sites kept from
 * before the move, what the plugin kept in people's profiles. Unticked it
 * takes nothing, and a site's own old box does not count.
 *
 * A network whose settings never moved — no marker — is decided by the same
 * box: the plugin only runs on a network activated for all of it, and a box a
 * site ticked for itself never counts.
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

			// And the network's log, which a site's setup does not make.
			diluxone_users_log_install();
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

		return $this->table_exists( $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log' );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** The network's log: one table for every site. */
	private function network_log(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'diluxone_users_log';
	}

	/** A site's own log table, as a site kept it before the log was the network's. */
	private function own_log( int $site ): void {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY  (id) )" ); // phpcs:ignore WordPress.DB

		$this->assertTrue( $this->log_table_exists( $site ), "Site {$site} has a log of its own" );
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

		// The network's log, and an old table this site kept from before it.
		diluxone_users_log_install();
		$this->own_log( $site );

		return $site;
	}

	public function test_the_networks_one_box_takes_everything_on_every_site(): void {
		$site = $this->network_activated();
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );

		// A photo uploaded on the other site, one whose site is gone, the
		// network's counts and a cron event on the other site.
		switch_to_blog( $site );
		$photo = (int) wp_insert_attachment( array( 'post_title' => 'Photo', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_author' => $this->user ) );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'diluxone_users_membership_drain' );
		restore_current_blog();
		update_user_meta( $this->user, 'diluxone_users_avatar', $photo );
		update_user_meta( $this->user, 'diluxone_users_avatar_site', $site );
		$lost = $this->make_user();
		update_user_meta( $lost, 'diluxone_users_avatar', 1 );
		update_user_meta( $lost, 'diluxone_users_avatar_site', 999999 );
		set_site_transient( 'diluxone_users_burst_register_x', array( 'n' => 1 ), HOUR_IN_SECONDS );
		update_site_option( 'diluxone_offload_net', 'a sister plugin' );

		$this->uninstall();

		switch_to_blog( $site );
		$this->assertNull( get_post( $photo ), 'the photo, on the site it was uploaded to' );
		$this->assertFalse( wp_next_scheduled( 'diluxone_users_membership_drain' ), 'every site’s cron' );
		restore_current_blog();
		$this->assertNotNull( get_post( 1 ), 'a photo whose site is gone deletes nothing here' );
		$this->assertFalse( get_site_transient( 'diluxone_users_burst_register_x' ), 'the network’s counts' );
		$this->assertSame( 'a sister plugin', get_site_option( 'diluxone_offload_net' ) );
		delete_site_option( 'diluxone_offload_net' );

		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s settings' );
		$this->assertFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertFalse( get_blog_option( $site, 'diluxone_users_2fa_mode' ), 'The copy the site kept' );
		$this->assertFalse( $this->table_exists( $this->network_log() ), 'The network’s log' );
		$this->assertFalse( $this->log_table_exists( $site ), 'The old log the site kept' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The network’s field' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_team', true ), 'The field only the site had' );
	}

	/**
	 * WordPress's own keys outlive the plugin on a network too: from the
	 * network's fields and from a field a site kept from before the move.
	 */
	public function test_wordpress_own_keys_survive_whatever_the_fields_say(): void {
		$site = $this->network_activated();
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );
		update_site_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'description', 'label' => 'Bio' ),
				array( 'key' => 'last_name', 'label' => 'Surname' ),
				array( 'key' => 'phone', 'label' => 'Phone' ),
			)
		);
		$this->site_keeps( $site, 'nickname' );
		update_user_meta( $this->user, 'description', 'A life' );
		update_user_meta( $this->user, 'last_name', 'López' );
		update_user_meta( $this->user, 'nickname', 'ani' );
		update_user_meta( $this->user, 'phone', '555' );

		$this->uninstall();

		$this->assertSame( 'A life', get_user_meta( $this->user, 'description', true ) );
		$this->assertSame( 'López', get_user_meta( $this->user, 'last_name', true ) );
		$this->assertSame( 'ani', get_user_meta( $this->user, 'nickname', true ), 'a site’s old field too' );
		$this->assertSame( '', get_user_meta( $this->user, 'phone', true ), 'a key the network invented goes' );
	}

	/** Unticked, nothing goes — and a site's own box from before the move is not the network's. */
	public function test_the_networks_box_unticked_takes_nothing(): void {
		$site = $this->network_activated();
		update_blog_option( $site, 'diluxone_users_uninstall_wipe', 1 );

		$this->uninstall();

		$this->assertSame( 'required', get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s settings' );
		$this->assertNotFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertSame( 'off', get_blog_option( $site, 'diluxone_users_2fa_mode' ), 'The copy the site kept' );
		$this->assertTrue( $this->table_exists( $this->network_log() ), 'The network’s log' );
		$this->assertTrue( $this->log_table_exists( $site ), 'The old log the site kept' );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '555', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The answers' );
	}

	/* ── A network that never moved its settings ───────────────────── */

	/**
	 * A network whose settings were never the network's — no version marker,
	 * each site with its own copy and its own old box. The plugin only runs on
	 * a network activated for all of it, so the network's box decides there
	 * too, and the boxes the sites ticked for themselves do not count.
	 *
	 * @return int The other site.
	 */
	private function never_moved(): int {
		$site = $this->site();

		delete_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION );
		update_site_option( 'diluxone_users_2fa_mode', 'required' );

		$this->site_keeps( get_main_site_id(), 'diluxone_users_phone' );
		$this->site_keeps( $site, 'diluxone_users_team' );
		$this->own_log( $site );

		foreach ( $this->all_sites() as $each ) {
			update_blog_option( $each, 'diluxone_users_uninstall_wipe', 1 );
		}

		return $site;
	}

	/** Every site ticked its own old box, the network did not: nothing goes. */
	public function test_the_sites_own_boxes_are_not_the_networks_decision(): void {
		$site = $this->never_moved();

		$this->uninstall();

		$this->assertSame( 'off', get_blog_option( $site, 'diluxone_users_2fa_mode' ), 'A site’s copy' );
		$this->assertTrue( $this->log_table_exists( $site ), 'Its old log' );
		$this->assertSame( 'required', get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s options' );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( 'Blue', get_user_meta( $this->user, 'diluxone_users_team', true ), 'The answers' );
	}

	/** The network's box, ticked, takes everything on every site, marker or not. */
	public function test_the_networks_box_decides_even_before_the_settings_moved(): void {
		$site = $this->never_moved();
		update_site_option( 'diluxone_users_uninstall_wipe', 1 );

		$this->uninstall();

		foreach ( array( get_main_site_id(), $site ) as $each ) {
			$this->assertFalse( get_blog_option( $each, 'diluxone_users_2fa_mode' ), "Site {$each}’s settings" );
		}

		$this->assertFalse( $this->log_table_exists( $site ), 'The old log' );
		$this->assertFalse( $this->table_exists( $this->network_log() ), 'The network’s log' );
		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s options' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The main site’s field' );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_team', true ), 'The other site’s field' );
	}
}
