<?php
/**
 * A field is never the way to write a key WordPress or the plugin keeps for
 * itself, however the field reached the list; and the public-name box takes
 * no more than the address can hold.
 */

namespace Tests\Integration;

class FieldSaveGuardTest extends IntegrationTestCase {

	/** A field added by a filter, keyed as a role, is not saved; its neighbour is. */
	public function test_a_protected_key_that_came_from_a_filter_is_not_written(): void {
		global $wpdb;

		$caps = $wpdb->get_blog_prefix() . 'capabilities';
		$user = $this->make_user( 'subscriber' );

		$fields = static function ( array $fields ) use ( $caps ): array {
			$fields[] = array(
				'key'      => $caps,
				'label'    => 'Role',
				'type'     => 'text',
				'group'    => '',
				'active'   => 1,
				'required' => 0,
			);
			$fields[] = array(
				'key'      => 'diluxone_test_city',
				'label'    => 'City',
				'type'     => 'text',
				'group'    => '',
				'active'   => 1,
				'required' => 0,
			);

			return $fields;
		};

		add_filter( 'diluxone_users_fields', $fields );
		wp_set_current_user( $user );

		diluxone_users_save(
			$user,
			array(
				$caps                => 'administrator',
				'diluxone_test_city' => 'Rosario',
			)
		);

		remove_filter( 'diluxone_users_fields', $fields );

		$this->assertFalse( diluxone_users_field_key_allowed( $caps ) );
		$this->assertSame( array( 'subscriber' => true ), get_user_meta( $user, $caps, true ), 'The role is untouched' );
		$this->assertFalse( user_can( $user, 'manage_options' ) );
		$this->assertSame( 'Rosario', get_user_meta( $user, 'diluxone_test_city', true ), 'An ordinary field still saves' );
	}

	/** The box's limits are the address's: a setting above 50 is held to 50. */
	public function test_the_public_name_box_takes_no_more_than_the_address_holds(): void {
		$user = $this->make_user();

		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );
		diluxone_users_update_option( 'diluxone_users_handle_min', 3 );
		diluxone_users_update_option( 'diluxone_users_handle_max', 80 );

		$this->assertSame( array( 3, 50 ), diluxone_users_handle_limits() );

		$html = diluxone_users_handle_field( $user );

		$this->assertStringContainsString( 'maxlength="50"', $html );
		$this->assertStringContainsString( 'minlength="3"', $html );
		$this->assertInstanceOf( \WP_Error::class, diluxone_users_handle_validate( str_repeat( 'a', 51 ), $user ) );
	}

	/**
	 * A field a theme or another plugin adds through the filter with only a
	 * key and a label is a whole field: the rest is filled in by default, the
	 * way a stored one is, and nothing reads a key it does not have.
	 */
	public function test_a_field_added_through_the_filter_is_filled_in_like_a_stored_one(): void {
		$this->hook(
			'diluxone_users_fields',
			static function ( array $fields ): array {
				$fields[] = array(
					'key'   => 'diluxone_test_team',
					'label' => 'Team',
				);
				$fields[] = 'not a field';

				return $fields;
			}
		);

		$field = diluxone_users_field( 'diluxone_test_team' );

		$this->assertIsArray( $field );
		$this->assertSame( 'always', $field['edit'] );
		$this->assertSame( 'text', $field['type'] );
		$this->assertNotContains( 'not a field', diluxone_users_fields() );

		$user = $this->make_user();
		wp_set_current_user( $user );
		diluxone_users_save( $user, array( 'diluxone_test_team' => 'Blue' ) );

		$this->assertSame( 'Blue', get_user_meta( $user, 'diluxone_test_team', true ) );
	}
}
