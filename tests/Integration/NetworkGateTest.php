<?php
/**
 * On a network the plugin is on for the whole network, or it does nothing.
 *
 * On the network: WordPress reads the header as network-only, activating it
 * from a site activates it for the network, a list of network plugins without
 * it puts it to sleep, and asleep it says so only to whoever can manage the
 * network's plugins. On a single site none of it applies: it always runs, and
 * activated on the site it is the site's.
 */

namespace Tests\Integration;

class NetworkGateTest extends IntegrationTestCase {

	/** @var array<int, int> Sites made for the test, deleted after it. */
	private array $sites = array();

	protected function tearDown(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			$basename = plugin_basename( DILUXONE_USERS_FILE );
			$network  = (array) get_site_option( 'active_sitewide_plugins', array() );

			if ( ! isset( $network[ $basename ] ) ) {
				$network[ $basename ] = time();
				update_site_option( 'active_sitewide_plugins', $network );
			}

			foreach ( $this->sites as $site ) {
				wp_delete_site( $site );
			}
		}

		$this->sites = array();

		parent::tearDown();
	}

	/** What the notice prints for the user signed in now. */
	private function notice(): string {
		ob_start();
		diluxone_users_asleep_notice();

		return (string) ob_get_clean();
	}

	/* ── On a network ──────────────────────────────────────────────── */

	public function test_on_a_network_it_is_a_network_only_plugin_and_activated_from_a_site_it_is_the_networks(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network; the single-site half is below.' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$basename = plugin_basename( DILUXONE_USERS_FILE );

		$this->assertTrue( is_network_only_plugin( $basename ), 'WordPress offers only "Network Activate"' );

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/gate-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Gate',
			)
		);
		$this->sites[] = $site;

		deactivate_plugins( $basename, true, true );
		$this->assertFalse( is_plugin_active_for_network( $basename ) );

		switch_to_blog( $site );
		$result = activate_plugin( $basename );
		$here   = (array) get_option( 'active_plugins', array() );
		restore_current_blog();

		$this->assertNull( $result );
		$this->assertTrue( is_plugin_active_for_network( $basename ), 'Asked from a site, activated for the network' );
		$this->assertNotContains( $basename, $here, 'And not for that site alone' );
		$this->assertTrue( diluxone_users_awake() );
	}

	public function test_on_a_network_without_it_in_the_networks_list_it_sleeps_and_says_so_to_the_network_only(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network; the single-site half is below.' );
		}

		$basename = plugin_basename( DILUXONE_USERS_FILE );
		$network  = (array) get_site_option( 'active_sitewide_plugins', array() );

		unset( $network[ $basename ] );
		update_site_option( 'active_sitewide_plugins', $network );

		$this->assertFalse( diluxone_users_network_activated() );
		$this->assertFalse( diluxone_users_awake(), 'On for a site alone, it does nothing' );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$this->assertSame( '', $this->notice(), 'A site administrator is not told to do what only the network can' );

		wp_set_current_user( 1 );
		$said = $this->notice();

		$this->assertStringContainsString( 'data-diluxone-users-asleep', $said );
		$this->assertStringContainsString( esc_url( network_admin_url( 'plugins.php' ) ), $said );
	}

	/* ── On a single site ──────────────────────────────────────────── */

	public function test_on_a_single_site_it_always_runs_and_is_the_sites(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site; the network half is above.' );
		}

		$this->assertTrue( diluxone_users_awake() );
		$this->assertFalse( diluxone_users_network_activated() );
		$this->assertFalse( diluxone_users_scoped_storage_active() );
		$this->assertContains( plugin_basename( DILUXONE_USERS_FILE ), (array) get_option( 'active_plugins', array() ), 'Activated on the site, as on any single site' );
	}
}
