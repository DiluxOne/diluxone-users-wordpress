<?php
/**
 * An export file, put back in.
 *
 * The round trip used to lose the account area: every value inside a
 * section's configuration went through the path meant for lists of keys and
 * came back as a word. It also carried the wipe-on-uninstall decision from
 * one site to another, and accepted a field keyed like some other plugin's
 * data.
 */

namespace Tests\Integration;

class SettingsFileTest extends IntegrationTestCase {

	protected function tearDown(): void {
		diluxone_users_delete_option( 'diluxone_users_uninstall_wipe' );

		parent::tearDown();
	}

	public function test_the_account_sections_survive_the_round_trip(): void {
		$sections = array(
			'home'   => array(
				'position'   => 10,
				'label'      => 'Start',
				'slug'       => 'home',
				'intro'      => 'Everything of yours.',
				'content'    => '<p>Hello <a href="https://example.test/">there</a></p>',
				'placement'  => 'after',
				'roles'      => array( 'subscriber' ),
				'visibility' => 'all',
			),
			'extra' => array(
				'position' => 70,
				'label'    => 'Courses',
				'custom'   => true,
				'content'  => '<p>Yours</p>',
			),
		);
		diluxone_users_update_option( 'diluxone_users_account_sections', $sections );

		$file = diluxone_users_tool_settings();
		diluxone_users_delete_option( 'diluxone_users_account_sections' );

		diluxone_users_tool_restore( $file );

		$this->assertSame( $sections, diluxone_users_raw_get( 'diluxone_users_account_sections' ) );
	}

	public function test_the_wipe_on_uninstall_does_not_travel(): void {
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );
		$file = diluxone_users_tool_settings();

		$this->assertArrayNotHasKey( 'diluxone_users_uninstall_wipe', $file );

		diluxone_users_delete_option( 'diluxone_users_uninstall_wipe' );
		diluxone_users_tool_restore( array( 'diluxone_users_uninstall_wipe' => 1 ) );

		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_uninstall_wipe' ) );
	}

	public function test_a_field_keyed_like_another_plugins_data_is_not_imported(): void {
		// On a network the fields are the network's, written from Network Admin.
		$this->in_network_admin();

		diluxone_users_tool_restore(
			array(
				'diluxone_users_fields' => array(
					array(
						'key'   => 'billing_phone',
						'label' => 'Phone',
						'type'  => 'text',
					),
					array(
						'key'   => 'diluxone_users_phone',
						'label' => 'Phone',
						'type'  => 'text',
					),
				),
			)
		);

		$keys = array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields' ), 'key' );

		$this->assertNotContains( 'billing_phone', $keys );
		$this->assertContains( 'diluxone_users_phone', $keys );
	}

	/**
	 * On a site of a network, a file restores what that site sets and nothing
	 * of the network's: a site administrator with a file cannot switch the
	 * second step off for everybody.
	 */
	public function test_a_file_restored_on_a_site_of_a_network_leaves_the_networks_settings_alone(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );

		diluxone_users_tool_restore(
			array(
				'diluxone_users_2fa_mode'    => 'off',
				'diluxone_users_login_title' => 'From the file',
			)
		);

		$this->assertSame( 'required', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'From the file', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
		$this->assertArrayNotHasKey( 'diluxone_users_2fa_mode', diluxone_users_tool_settings(), 'Nor does it hand them over' );
	}

	/** A setting the site never set travels as nothing, and stays a default. */
	public function test_a_default_is_not_written_back_as_a_setting(): void {
		diluxone_users_delete_option( 'diluxone_users_color_map' );

		$file = diluxone_users_tool_settings();
		$this->assertArrayNotHasKey( 'diluxone_users_color_map', $file );

		diluxone_users_tool_restore( $file );
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_color_map', null ) );
	}
}
