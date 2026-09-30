<?php
/**
 * Deleting the plugin from a single site, with the box ticked.
 *
 * The single-site half of UninstallNetworkTest, and the same one-shot shape:
 * uninstall.php declares its functions when it is loaded, so each suite runs
 * one of the two — this one on a single site, that one on a network — and it
 * puts back what it took.
 */

namespace Tests\Integration;

class UninstallSiteTest extends IntegrationTestCase {

	protected function tearDown(): void {
		if ( ! is_multisite() ) {
			diluxone_users_site_setup();
		}

		parent::tearDown();
	}

	public function test_the_box_ticked_takes_the_settings_the_log_and_the_profiles(): void {
		global $wpdb;

		if ( is_multisite() ) {
			$this->markTestSkipped( 'The single-site uninstall: on a network, UninstallNetworkTest runs instead.' );
		}

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

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( DILUXONE_USERS_FILE ) );
		}

		require DILUXONE_USERS_DIR . 'uninstall.php';
		wp_cache_flush();

		$table = $wpdb->prefix . 'diluxone_users_log';

		$this->assertFalse( get_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_option( 'diluxone_users_uninstall_wipe' ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_2fa_on', true ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_phone', true ) );
	}
}
