<?php
/**
 * The fields the plugin adds to WordPress's own registration form, and only
 * there.
 *
 * They used to be saved on `user_register`, which fires for every account
 * created anywhere — the REST API, a shop's checkout, another plugin — so
 * whatever that request happened to post under a field's key went into the
 * new person's profile, with no nonce anywhere. They are saved now on the
 * form's own hook, behind a nonce the form's fields carry, and read one value
 * per field, cleaned as they are read.
 */

namespace Tests\Integration;

class WpRegisterFieldsTest extends IntegrationTestCase {

	/** @var array<int, array<string, mixed>> */
	private const FIELDS = array(
		array(
			'key'      => 'test_city',
			'label'    => 'City',
			'type'     => 'text',
			'required' => 1,
			'active'   => 1,
			'group'    => 'main',
			'edit'     => 'always',
		),
		array(
			'key'      => 'test_site',
			'label'    => 'Site',
			'type'     => 'url',
			'required' => 0,
			'active'   => 1,
			'group'    => 'main',
			'edit'     => 'always',
		),
	);

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_fields', self::FIELDS );
	}

	public function test_an_account_made_anywhere_else_takes_nothing_from_the_request(): void {
		$this->postAs( 0, array( 'test_city' => 'Injected' ) );

		$user = $this->make_user();

		$this->assertSame( '', (string) get_user_meta( $user, 'test_city', true ) );
	}

	public function test_a_registration_that_never_drew_the_fields_goes_through(): void {
		// Another plugin's sign-up, a checkout: register_new_user() with none
		// of this plugin's fields and no nonce. A required field it never
		// showed is not a reason to refuse it.
		$this->postAs( 0, array() );

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );
		$this->assertSame( array(), $errors->get_error_codes() );

		$login = 'dlxreg' . strtolower( wp_generate_password( 8, false ) );
		$user  = register_new_user( $login, $login . '@example.test' );

		$this->assertIsInt( $user, 'register_new_user() made the account' );
		$this->assertSame( '', (string) get_user_meta( $user, 'test_city', true ) );
	}

	public function test_the_fields_with_a_wrong_nonce_are_refused(): void {
		$this->postAs(
			0,
			array(
				'diluxone_users_wp_register_nonce' => 'not-the-nonce',
				'test_city'                        => 'Mendoza',
			)
		);

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );

		$this->assertContains( 'diluxone_users_nonce', $errors->get_error_codes() );
	}

	public function test_a_wrong_nonce_alone_is_refused(): void {
		// The nonce is the form's: posted, it is checked, fields or not.
		$this->postAs( 0, array( 'diluxone_users_wp_register_nonce' => 'not-the-nonce' ) );

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );

		$this->assertContains( 'diluxone_users_nonce', $errors->get_error_codes() );
	}

	public function test_a_field_posted_as_a_list_still_asks_for_the_nonce(): void {
		$this->postAs( 0, array( 'test_city' => array( 'a', 'b' ) ) );

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );

		$this->assertContains( 'diluxone_users_nonce', $errors->get_error_codes() );
	}

	public function test_the_form_without_its_nonce_is_refused(): void {
		$this->postAs( 0, array( 'test_city' => 'Mendoza' ) );

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );

		$this->assertContains( 'diluxone_users_nonce', $errors->get_error_codes() );
	}

	public function test_the_form_with_its_nonce_is_checked_and_saved(): void {
		$this->postAs(
			0,
			array(
				'diluxone_users_wp_register_nonce' => wp_create_nonce( 'diluxone_users_wp_register' ),
				'test_city'                        => '',
			)
		);

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );
		$this->assertContains( 'diluxone_users_test_city', $errors->get_error_codes(), 'A required field left empty' );

		$_POST['test_city'] = 'Mendoza';
		$_POST['test_site'] = 'https://example.test/a%20b';

		$user = $this->make_user();
		diluxone_users_register_save( $user );

		$this->assertSame( 'Mendoza', (string) get_user_meta( $user, 'test_city', true ) );
		$this->assertSame( 'https://example.test/a%20b', (string) get_user_meta( $user, 'test_site', true ), 'An address keeps its encoded characters' );
	}

	public function test_a_field_posted_as_a_list_is_not_an_answer(): void {
		$this->postAs( 0, array( 'test_city' => array( 'a', 'b' ) ) );

		$this->assertArrayNotHasKey( 'test_city', diluxone_users_posted_fields() );
	}
}
