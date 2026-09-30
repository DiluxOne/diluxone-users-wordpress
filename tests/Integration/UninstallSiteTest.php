<?php
/**
 * Deleting the plugin from a single site: the box ticked, and not.
 *
 * The single-site half of UninstallNetworkTest. uninstall.php declares its
 * functions when it is loaded, so it can be loaded once per process: every
 * test here runs in a process of its own, and puts back what it took so the
 * tests after it find a plugin that is installed.
 */

namespace Tests\Integration;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallSiteTest extends IntegrationTestCase {

	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( is_multisite() ) {
			$this->markTestSkipped( 'The single-site uninstall: on a network, UninstallNetworkTest runs instead.' );
		}

		$this->user = $this->make_user();

		diluxone_users_log_install();
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
		update_user_meta( $this->user, 'diluxone_users_phone', '555' );
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
	}

	protected function tearDown(): void {
		if ( ! is_multisite() ) {
			diluxone_users_site_setup();
		}

		parent::tearDown();
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

	private function log_table_exists(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'diluxone_users_log';

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public function test_the_box_ticked_takes_the_settings_the_log_and_the_profiles(): void {
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );

		$this->uninstall();

		$this->assertFalse( get_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_option( 'diluxone_users_fields' ) );
		$this->assertFalse( get_option( 'diluxone_users_uninstall_wipe' ) );
		$this->assertFalse( $this->log_table_exists() );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_phone', true ) );
	}

	/** Unticked — the default — deleting the plugin takes nothing at all. */
	public function test_the_box_unticked_takes_nothing(): void {
		$this->uninstall();

		$this->assertSame( 'required', get_option( 'diluxone_users_2fa_mode' ), 'The settings' );
		$this->assertNotFalse( get_option( 'diluxone_users_fields' ), 'The fields' );
		$this->assertTrue( $this->log_table_exists(), 'The log' );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ), 'The second factor' );
		$this->assertSame( '555', get_user_meta( $this->user, 'diluxone_users_phone', true ), 'The answers' );
	}
}
