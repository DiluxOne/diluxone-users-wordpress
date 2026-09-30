<?php
/**
 * M-05: the role an account gives itself is never one that runs the site.
 *
 * The screen that saves the setting offers every role there is. Whatever it
 * saves, an account that creates itself from an e-mail address must come out
 * unable to touch other people or the site.
 */

namespace Tests\Integration;

class RegisterRoleTest extends IntegrationTestCase {

	/** @dataProvider rolesAndWhatTheyBecome */
	public function test_the_role_is_read_through_the_check( string $saved, string $given ): void {
		diluxone_users_update_option( 'diluxone_users_login_role', $saved );

		$this->assertSame( $given, diluxone_users_register_role() );
	}

	/** @return array<string, array{string, string}> */
	public static function rolesAndWhatTheyBecome(): array {
		return array(
			'subscriber stays'             => array( 'subscriber', 'subscriber' ),
			'contributor stays'            => array( 'contributor', 'contributor' ),
			'author stays'                 => array( 'author', 'author' ),
			'editor falls back'            => array( 'editor', 'subscriber' ),
			'administrator falls back'     => array( 'administrator', 'subscriber' ),
			'a role that does not exist'   => array( 'overlord', 'subscriber' ),
			'nothing at all'               => array( '', 'subscriber' ),
		);
	}

	public function test_a_self_created_account_is_never_an_administrator(): void {
		diluxone_users_update_option( 'diluxone_users_login_role', 'administrator' );

		$id = diluxone_users_create_account( 'wants-it-all-' . wp_generate_password( 8, false ) . '@example.test' );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( array( 'subscriber' ), get_userdata( $id )->roles );
	}

	public function test_a_custom_role_is_judged_by_what_it_can_do(): void {
		add_role( 'diluxone_users_test_member', 'Member', array( 'read' => true ) );
		add_role( 'diluxone_users_test_staff', 'Staff', array( 'read' => true, 'edit_users' => true ) );

		diluxone_users_update_option( 'diluxone_users_login_role', 'diluxone_users_test_member' );
		$this->assertSame( 'diluxone_users_test_member', diluxone_users_register_role() );

		diluxone_users_update_option( 'diluxone_users_login_role', 'diluxone_users_test_staff' );
		$this->assertSame( 'subscriber', diluxone_users_register_role() );

		remove_role( 'diluxone_users_test_member' );
		remove_role( 'diluxone_users_test_staff' );
	}
}
