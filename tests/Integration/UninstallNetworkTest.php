<?php
/**
 * Deleting the plugin from a network whose settings are the network's.
 *
 * One box, in Network Admin, and ticked it takes everything: the network's
 * settings, every site's settings and log — the copies the sites kept from
 * before the move included — and what the plugin kept in people's profiles.
 *
 * uninstall.php declares its functions when it is loaded, so it can run once
 * per process: this is one test, and it puts back what it took so the tests
 * after it find a plugin that is installed.
 */

namespace Tests\Integration;

class UninstallNetworkTest extends IntegrationTestCase {

	private int $site = 0;

	protected function tearDown(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			if ( $this->site > 0 ) {
				wp_delete_site( $this->site );
			}

			// Installed again, as the rest of the suite expects to find it.
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site ) {
				switch_to_blog( (int) $site );
				diluxone_users_site_setup();
				restore_current_blog();
			}

			update_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_VERSION );
		}

		parent::tearDown();
	}

	public function test_the_networks_one_box_takes_everything_on_every_site(): void {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		$this->site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/gone-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Gone',
			)
		);

		$user = $this->make_user();

		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );
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
		update_user_meta( $user, 'diluxone_users_phone', '555' );
		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );

		// The copy a site kept from before the move, and a field only it had.
		update_blog_option( $this->site, 'diluxone_users_2fa_mode', 'off' );
		update_blog_option(
			$this->site,
			'diluxone_users_fields',
			array(
				array(
					'key'   => 'diluxone_users_team',
					'label' => 'Team',
				),
			)
		);
		update_user_meta( $user, 'diluxone_users_team', 'Blue' );

		$table = $wpdb->get_blog_prefix( $this->site ) . 'diluxone_users_log';

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( DILUXONE_USERS_FILE ) );
		}

		require DILUXONE_USERS_DIR . 'uninstall.php';

		// The request that deletes a plugin ends there; the next one starts
		// with nothing in memory. The rows are what is being asked about.
		wp_cache_flush();

		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ), 'The network’s settings' );
		$this->assertFalse( get_site_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertFalse( get_blog_option( $this->site, 'diluxone_users_2fa_mode' ), 'The copy the site kept' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Its log' );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_phone', true ), 'The network’s field' );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_team', true ), 'The field only the site had' );
	}
}
