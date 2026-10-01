<?php
/**
 * A new site asks for a name, and nothing else until the site says so.
 *
 * The plugin used to arrive with a country, a birthday, a gender and a phone
 * on the registration form, and the first name required. Now it seeds
 * WordPress's two, neither required, and offers the rest on User fields ›
 * Suggested fields, added on purpose. A site that already has its list keeps
 * it whole: nothing is deleted from an existing install.
 *
 * Every case runs on both topologies; on a network the fields are the
 * network's and are added from Network Admin.
 */

namespace Tests\Integration;

class SuggestedFieldsTest extends IntegrationTestCase {

	/** The keys on the stored list, in order. */
	private function keys(): array {
		return array_values( array_map( 'strval', array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields', array() ), 'key' ) ) );
	}

	public function test_a_fresh_install_seeds_only_the_two_names_and_neither_is_required(): void {
		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_fields', false ), 'Nothing stored yet' );

		diluxone_users_seed_fields();

		$this->assertSame( array( 'first_name', 'last_name' ), $this->keys() );

		foreach ( diluxone_users_fields() as $field ) {
			$this->assertFalse( (bool) $field['required'], $field['key'] . ' is not required' );
		}
	}

	public function test_an_existing_list_is_kept_as_it_is(): void {
		$list = array_merge( diluxone_users_default_fields(), array( diluxone_users_suggested_fields()[0] ) );
		diluxone_users_update_option( 'diluxone_users_fields', $list );

		diluxone_users_seed_fields();

		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_country' ), $this->keys(), 'Nothing seeded over it, nothing taken away' );
	}

	public function test_the_suggested_fields_are_offered_and_added_once(): void {
		diluxone_users_seed_fields();

		$offered = array_column( diluxone_users_suggested_fields(), 'key' );

		$this->assertSame( array( 'diluxone_users_country', 'diluxone_users_birthday', 'diluxone_users_gender', 'diluxone_users_phone' ), $offered );
		$this->assertSame( $offered, array_column( diluxone_users_suggested_missing(), 'key' ), 'All of them are missing on a fresh site' );

		$this->assertSame( 2, diluxone_users_add_suggested_fields( array( 'diluxone_users_birthday', 'diluxone_users_phone', 'not_a_suggestion' ) ) );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_birthday', 'diluxone_users_phone' ), $this->keys(), 'At the end, in the order suggested' );

		$this->assertSame( 0, diluxone_users_add_suggested_fields( array( 'diluxone_users_birthday' ) ), 'A field on the list is not added twice' );
		$this->assertSame( array( 'diluxone_users_country', 'diluxone_users_gender' ), array_column( diluxone_users_suggested_missing(), 'key' ) );

		foreach ( diluxone_users_fields() as $field ) {
			$this->assertFalse( (bool) $field['required'], $field['key'] . ' is not required' );
		}
	}

	public function test_the_screen_adds_the_ones_ticked_behind_its_nonce(): void {
		diluxone_users_seed_fields();

		$this->in_network_admin();
		wp_set_current_user( 1 );

		// Without the nonce nothing is added.
		$this->postAs(
			1,
			array( 'diluxone_users_suggested' => array( 'diluxone_users_country' ) ),
			array( 'page' => 'diluxone-users-fields' )
		);
		diluxone_users_fields_actions();
		$this->assertSame( array( 'first_name', 'last_name' ), $this->keys() );

		$this->postAs(
			1,
			array(
				'diluxone_users_suggested_nonce' => wp_create_nonce( 'diluxone_users_suggested' ),
				'diluxone_users_suggested'       => array( 'diluxone_users_country', 'diluxone_users_gender' ),
			),
			array( 'page' => 'diluxone-users-fields' )
		);

		$url = $this->expectRedirect( 'diluxone_users_fields_actions' );

		$this->assertSame( 'suggested', $this->queryArg( $url, 'diluxone_users_done' ) );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_country', 'diluxone_users_gender' ), $this->keys() );
	}

	public function test_a_site_administrator_on_a_network_adds_nothing(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'On a single site the fields screen is the site\'s own: SuggestedFieldsTest › the screen adds the ones ticked.' );
		}

		diluxone_users_seed_fields();

		// A site's dashboard, not Network Admin: the fields are the network's.
		$this->postAs(
			1,
			array(
				'diluxone_users_suggested_nonce' => wp_create_nonce( 'diluxone_users_suggested' ),
				'diluxone_users_suggested'       => array( 'diluxone_users_country' ),
			),
			array( 'page' => 'diluxone-users-fields' )
		);

		diluxone_users_fields_actions();

		$this->assertSame( array( 'first_name', 'last_name' ), $this->keys() );
	}
}
