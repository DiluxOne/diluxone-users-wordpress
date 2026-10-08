<?php
/**
 * A field's key, and the rest of the plugin's namespace.
 *
 * A field's key is the user meta key everybody's answer is stored under, so a
 * field named after something the plugin or an add-on of it keeps about a
 * person is a box on the front end that writes over it. The add-on may not be
 * running to say which names are its own. So a new field's key is made in the
 * fields' own corner, `diluxone_users_field_`, and a key elsewhere under the
 * prefix is refused — unless a field already had it before the corner
 * existed, which keeps the answers stored under it.
 *
 * The fields are the network's: written from Network Admin there, from the
 * site on a single one.
 */

namespace Tests\Integration;

class FieldNamespaceTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );
		$this->in_network_admin();

		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				diluxone_users_normalize_field(
					array(
						'key'   => 'diluxone_users_company',
						'label' => 'Company',
					)
				),
			)
		);
	}

	public function test_a_new_field_is_made_in_the_fields_corner(): void {
		$this->assertSame( 'diluxone_users_field_team', diluxone_users_field_save( array( 'label' => 'Team' ) ) );
	}

	public function test_a_key_elsewhere_under_the_prefix_is_refused(): void {
		$this->assertSame( '', diluxone_users_field_save( array( 'key' => 'diluxone_users_addon_data', 'label' => 'Data' ) ) );
		$this->assertNotContains( 'diluxone_users_addon_data', wp_list_pluck( diluxone_users_fields( '', false ), 'key' ) );
	}

	public function test_a_field_made_before_the_corner_keeps_its_key_and_its_answers(): void {
		$user = $this->make_user();

		$this->assertTrue( diluxone_users_field_key_allowed( 'diluxone_users_company' ) );

		diluxone_users_save( $user, array( 'diluxone_users_company' => 'Acme' ) );

		$this->assertSame( 'Acme', get_user_meta( $user, 'diluxone_users_company', true ) );
	}

	public function test_a_settings_file_brings_only_keys_a_field_may_have(): void {
		diluxone_users_tool_restore(
			array(
				'diluxone_users_fields' => array(
					array(
						'key'   => 'diluxone_users_addon_data',
						'label' => 'Data',
					),
					array(
						'key'   => 'diluxone_users_field_ok',
						'label' => 'OK',
					),
				),
			)
		);

		$this->assertSame( array( 'diluxone_users_field_ok' ), wp_list_pluck( diluxone_users_fields( '', false ), 'key' ) );
	}
}
