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
		delete_option( 'diluxone_users_uninstall_wipe' );

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
		update_option( 'diluxone_users_account_sections', $sections );

		$file = diluxone_users_tool_settings();
		delete_option( 'diluxone_users_account_sections' );

		diluxone_users_tool_restore( $file );

		$this->assertSame( $sections, get_option( 'diluxone_users_account_sections' ) );
	}

	public function test_the_wipe_on_uninstall_does_not_travel(): void {
		update_option( 'diluxone_users_uninstall_wipe', 1 );
		$file = diluxone_users_tool_settings();

		$this->assertArrayNotHasKey( 'diluxone_users_uninstall_wipe', $file );

		delete_option( 'diluxone_users_uninstall_wipe' );
		diluxone_users_tool_restore( array( 'diluxone_users_uninstall_wipe' => 1 ) );

		$this->assertFalse( get_option( 'diluxone_users_uninstall_wipe' ) );
	}

	public function test_a_field_keyed_like_another_plugins_data_is_not_imported(): void {
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

		$keys = array_column( (array) get_option( 'diluxone_users_fields' ), 'key' );

		$this->assertNotContains( 'billing_phone', $keys );
		$this->assertContains( 'diluxone_users_phone', $keys );
	}
}
