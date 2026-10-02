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

	/** An attachment in this site's library, by this author. */
	private function photo( int $author ): int {
		return (int) wp_insert_attachment(
			array(
				'post_title'     => 'Photo',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
				'post_author'    => $author,
			)
		);
	}

	public function test_the_box_ticked_takes_the_settings_the_log_and_the_profiles(): void {
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );

		// What the plugin kept elsewhere: a throttle, a trip, a cron event,
		// a secret, a passkey, a public name, a person's photo — and, beside
		// it, a picture they did not upload, other plugins' data, and the
		// family's other plugins'.
		set_transient( 'diluxone_users_flash_x_' . $this->user, 'm', HOUR_IN_SECONDS );
		set_site_transient( 'diluxone_users_burst_register_x', array( 'n' => 1 ), HOUR_IN_SECONDS );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'diluxone_users_log_purge' );
		foreach ( array( 'diluxone_users_totp' => 'S', 'diluxone_users_passkeys' => array( array( 'id' => 'x' ) ), 'diluxone_users_handle' => 'ana', '_diluxone_users_link_hash' => 'h', 'diluxone_users_removed_from' => array( 2 ) ) as $key => $value ) {
			update_user_meta( $this->user, $key, $value );
		}
		$mine    = $this->photo( $this->user );
		$someone = $this->make_user();
		$theirs  = $this->photo( 1 );
		update_user_meta( $this->user, 'diluxone_users_avatar', $mine );
		update_user_meta( $someone, 'diluxone_users_avatar', $theirs );
		update_option( 'diluxone_usersx', 'not ours: no underscore after the prefix' );
		update_option( 'diluxone_offload_settings', 'a sister plugin' );
		update_user_meta( $this->user, 'diluxone_offload_key', 'k' );
		update_user_meta( $this->user, 'nickname', 'nick' );

		$this->uninstall();

		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%transient%diluxone\\_users\\_%'" ), 'the transients' );
		$this->assertFalse( wp_next_scheduled( 'diluxone_users_log_purge' ), 'the cron events' );
		foreach ( array( 'diluxone_users_totp', 'diluxone_users_passkeys', 'diluxone_users_handle', '_diluxone_users_link_hash', 'diluxone_users_removed_from', 'diluxone_users_avatar' ) as $key ) {
			$this->assertSame( '', get_user_meta( $this->user, $key, true ), $key );
		}
		$this->assertNull( get_post( $mine ), 'the photo they uploaded' );
		$this->assertNotNull( get_post( $theirs ), 'a picture somebody else uploaded stays' );
		$this->assertSame( 'not ours: no underscore after the prefix', get_option( 'diluxone_usersx' ) );
		$this->assertSame( 'a sister plugin', get_option( 'diluxone_offload_settings' ) );
		$this->assertSame( 'k', get_user_meta( $this->user, 'diluxone_offload_key', true ) );
		$this->assertSame( 'nick', get_user_meta( $this->user, 'nickname', true ) );

		delete_option( 'diluxone_usersx' );
		delete_option( 'diluxone_offload_settings' );
		wp_delete_attachment( $theirs, true );

		$this->assertFalse( get_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_option( 'diluxone_users_fields' ) );
		$this->assertFalse( get_option( 'diluxone_users_uninstall_wipe' ) );
		$this->assertFalse( $this->log_table_exists() );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_phone', true ) );
	}

	/**
	 * A field keyed like one of WordPress's own keeps WordPress's data.
	 *
	 * A field's answers are deleted by its key, for every person at once. A
	 * field stored under `description` — a hand-edited settings file, a
	 * suggestion an add-on made — would take every biography on the site
	 * with it, and `first_name` every name. WordPress's keys outlive the
	 * plugin whatever the fields say; a key the site invented goes.
	 */
	public function test_wordpress_own_keys_survive_whatever_the_fields_say(): void {
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );
		update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'description', 'label' => 'Bio' ),
				array( 'key' => 'first_name', 'label' => 'Name' ),
				array( 'key' => 'nickname', 'label' => 'Nick' ),
				array( 'key' => '_application_passwords', 'label' => 'Keys' ),
				array( 'key' => '_new_email', 'label' => 'Pending address' ),
				array( 'key' => 'phone', 'label' => 'Phone' ),
			)
		);
		update_user_meta( $this->user, '_application_passwords', array( array( 'uuid' => 'u', 'name' => 'CLI' ) ) );
		update_user_meta( $this->user, '_new_email', array( 'hash' => 'h', 'newemail' => 'ana@example.test' ) );
		update_user_meta( $this->user, 'description', 'A life' );
		update_user_meta( $this->user, 'first_name', 'Ana' );
		update_user_meta( $this->user, 'nickname', 'ani' );
		update_user_meta( $this->user, 'phone', '555' );

		$this->uninstall();

		$this->assertSame( 'A life', get_user_meta( $this->user, 'description', true ) );
		$this->assertSame( 'Ana', get_user_meta( $this->user, 'first_name', true ) );
		$this->assertSame( 'ani', get_user_meta( $this->user, 'nickname', true ) );
		$this->assertNotEmpty( get_user_meta( $this->user, '_application_passwords', true ), 'application passwords are WordPress’s' );
		$this->assertNotEmpty( get_user_meta( $this->user, '_new_email', true ), 'a pending address change is WordPress’s' );
		$this->assertSame( '', get_user_meta( $this->user, 'phone', true ), 'a key the site invented goes' );
	}

	/** A fields list somebody broke by hand does not break the wipe, and a key that is no key touches nothing. */
	public function test_a_malformed_fields_list_still_wipes_what_it_can(): void {
		global $wpdb;

		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );
		update_option(
			'diluxone_users_fields',
			array(
				'junk',
				array( 'key' => array( 'x' ) ),
				array( 'key' => '' ),
				array( 'label' => 'No key' ),
				array( 'key' => 'diluxone_users_team' ),
			)
		);
		update_user_meta( $this->user, 'diluxone_users_team', 'Blue' );
		update_user_meta( $this->user, 'team', 'kept' );

		$this->uninstall();

		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_team', true ) );
		$this->assertSame( 'kept', get_user_meta( $this->user, 'team', true ), 'nothing but what a field named' );
		$this->assertFalse( get_option( 'diluxone_users_fields' ) );
		$this->assertFalse( $this->log_table_exists() );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'nickname'" ), 'and WordPress\'s own rows are all there' );
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
