<?php
/**
 * A phone number the wrong length for its country, on every form that saves
 * one: the account area (and the step after a sign-in, which is the same
 * form), the registration form, WordPress's own registration, the profile in
 * wp-admin and Users → Add New.
 *
 * Each one refuses the whole form — nothing on it is saved, no account is
 * made — and says so under the number, with `aria-invalid` on it. And a
 * number saved is drawn back grouped, with its country picked.
 */

namespace Tests\Integration;

class CoverFieldsPhoneTest extends IntegrationTestCase {

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
			'key'      => 'test_mobile',
			'label'    => 'Mobile',
			'type'     => 'phone',
			'options'  => array( 'AR' ),
			'required' => 1,
			'active'   => 1,
			'group'    => 'main',
			'edit'     => 'always',
		),
	);

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_fields', self::FIELDS );
	}

	/** The account area's form, sent from its page. */
	private function sendFields( int $user, array $answers ): string {
		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'         => wp_create_nonce( 'diluxone_users_fields_save' ),
				'_wp_http_referer' => home_url( '/my-account/' ),
			) + $answers
		);

		return $this->expectRedirect( 'diluxone_users_save_fields_form' );
	}

	/** The account area's form as the shortcode draws it, after a refusal. */
	private function drawnAfter( string $url ): string {
		$_GET = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );

		return diluxone_users_shortcode_fields();
	}

	public function test_the_account_form_refuses_a_number_too_short_and_saves_nothing_on_it(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'test_city', 'Rosario' );
		update_user_meta( $user, 'test_mobile', '+541123456789' );

		$url = $this->sendFields(
			$user,
			array(
				'test_city'        => 'Mendoza',
				'test_mobile'      => '11 2345',
				'test_mobile_dial' => 'AR',
			)
		);

		$this->assertSame( 'phone', $this->redirectState( $url ) );
		$this->assertSame( 'test_mobile', $this->queryArg( $url, 'diluxone-users-field' ) );
		$this->assertSame( 'short', $this->queryArg( $url, 'diluxone-users-code' ) );
		$this->assertStringStartsWith( home_url( '/my-account/' ), $url, 'back to the form' );
		$this->assertStringNotContainsString( '2345', $url, 'the number itself never travels in the address' );
		$this->assertSame( 'Rosario', get_user_meta( $user, 'test_city', true ), 'nothing on the form is saved' );
		$this->assertSame( '+541123456789', get_user_meta( $user, 'test_mobile', true ) );

		// Drawn again: the error under the number, the number marked invalid.
		$html = $this->drawnAfter( $url );

		$this->assertStringContainsString( 'diluxone-users-notice--error', $html );
		$this->assertMatchesRegularExpression( '/<input type="tel" id="test_mobile"[^>]* aria-invalid="true" aria-describedby="test_mobile-error">/', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-field__error" id="test_mobile-error">This number is too short for the country chosen.</p>', $html );
	}

	public function test_too_long_says_too_long_and_a_good_one_is_saved(): void {
		$user = $this->make_user();

		$url = $this->sendFields(
			$user,
			array(
				'test_city'        => 'Mendoza',
				'test_mobile'      => '11 2345 6789 0123',
				'test_mobile_dial' => 'AR',
			)
		);

		$this->assertSame( 'long', $this->queryArg( $url, 'diluxone-users-code' ) );
		$this->assertStringContainsString( 'This number is too long for the country chosen.', $this->drawnAfter( $url ) );
		$this->assertSame( '', get_user_meta( $user, 'test_mobile', true ) );

		$url = $this->sendFields(
			$user,
			array(
				'test_city'        => 'Mendoza',
				'test_mobile'      => '11 2345-6789',
				'test_mobile_dial' => 'AR',
			)
		);

		$this->assertSame( 'saved', $this->redirectState( $url ) );
		$this->assertSame( '', $this->queryArg( $url, 'diluxone-users-field' ) );
		$this->assertSame( '+541123456789', get_user_meta( $user, 'test_mobile', true ) );
		$this->assertSame( 'Mendoza', get_user_meta( $user, 'test_city', true ) );
	}

	/** The phone field marked "It is a mobile", or not, with the country it comes with. */
	private function markMobile( bool $mobile, string $country = 'AR' ): void {
		$fields                = self::FIELDS;
		$fields[1]['mobile']   = $mobile ? 1 : 0;
		$fields[1]['options']  = array( $country );
		diluxone_users_update_option( 'diluxone_users_fields', $fields );
	}

	public function test_a_field_marked_as_a_mobile_stores_the_lead_its_country_puts_in_front(): void {
		$this->markMobile( true );
		$user = $this->make_user();

		$url = $this->sendFields(
			$user,
			array(
				'test_city'        => 'Mendoza',
				'test_mobile'      => '11 4123 4567',
				'test_mobile_dial' => 'AR',
			)
		);

		$this->assertSame( 'saved', $this->redirectState( $url ) );
		$this->assertSame( '+5491141234567', get_user_meta( $user, 'test_mobile', true ) );

		wp_set_current_user( $user );
		$html = diluxone_users_shortcode_fields();

		$this->assertStringContainsString( 'value="9 11 4123 4567"', $html, 'drawn back as it is stored' );
		$this->assertMatchesRegularExpression( '/<span class="diluxone-users-phone" data-diluxone-users-phone [^>]* data-mobile-field="1">/', $html, 'and the script is told the field is a mobile' );
	}

	public function test_a_field_key_in_the_address_that_is_not_a_phone_being_drawn_shows_nothing(): void {
		wp_set_current_user( $this->make_user() );

		foreach ( array( 'test_city', 'nope', '<script>' ) as $key ) {
			$html = $this->drawnAfter( add_query_arg( array( 'diluxone-users' => 'phone', 'diluxone-users-field' => rawurlencode( $key ) ), home_url( '/' ) ) );

			$this->assertStringNotContainsString( 'aria-invalid', $html, $key );
			$this->assertStringNotContainsString( 'diluxone-users-field__error', $html, $key );
		}
	}

	public function test_the_step_after_a_sign_in_goes_back_to_itself_with_the_error(): void {
		$user = $this->make_user();
		$to   = home_url( '/somewhere/' );

		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                => wp_create_nonce( 'diluxone_users_fields_save' ),
				'diluxone_users_redirect' => $to,
				'test_city'               => 'Mendoza',
				'test_mobile'             => '123',
				'test_mobile_dial'        => 'AR',
			)
		);
		unset( $_SERVER['HTTP_REFERER'] );

		$url = $this->expectRedirect( 'diluxone_users_save_fields_form' );

		$this->assertSame( 'phone', $this->redirectState( $url ) );
		$this->assertSame( '1', $this->queryArg( $url, DILUXONE_USERS_COMPLETE_VAR ), 'on the step, not on where it was going' );
		$this->assertSame( '', get_user_meta( $user, 'test_city', true ) );
	}

	public function test_the_registration_form_refuses_before_the_account_exists(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Register',
				'post_content' => '[diluxone_users_register]',
			)
		);

		diluxone_users_update_option( 'diluxone_users_register_page', $page );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );

		$email = 'phone-' . strtolower( wp_generate_password( 8, false ) ) . '@example.test';

		$this->postAs(
			0,
			array(
				'diluxone_users_register_nonce' => wp_create_nonce( 'diluxone_users_register' ),
				'diluxone_users_email'          => $email,
				'test_city'                     => 'Mendoza',
				'test_mobile'                   => '11 23',
				'test_mobile_dial'              => 'AR',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_register_request' );

		$this->assertStringStartsWith( diluxone_users_register_url(), $url );
		$this->assertSame( 'phone', $this->redirectState( $url ) );
		$this->assertSame( 'test_mobile', $this->queryArg( $url, 'diluxone-users-field' ) );
		$this->assertFalse( email_exists( $email ), 'no account was made' );

		// The form drawn again: the notice, and the error under the number.
		$_GET = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
		$html = diluxone_users_shortcode_register();

		$this->assertStringContainsString( 'data-diluxone-users-message="register_phone"', $html );
		$this->assertMatchesRegularExpression( '/id="diluxone-users-test_mobile"[^>]* aria-invalid="true" aria-describedby="diluxone-users-test_mobile-error">/', $html );
		$this->assertStringContainsString( 'id="diluxone-users-test_mobile-error">This number is too short for the country chosen.</p>', $html );
	}

	public function test_wordpress_registration_form_refuses_a_number_the_wrong_length(): void {
		diluxone_users_update_option( 'diluxone_users_wp_register_fields', 1 );

		$this->postAs(
			0,
			array(
				'diluxone_users_wp_register_nonce' => wp_create_nonce( 'diluxone_users_wp_register' ),
				'test_city'                        => 'Mendoza',
				'test_mobile'                      => '11 2345 6789 0123',
				'test_mobile_dial'                 => 'AR',
			)
		);

		$errors = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );

		$this->assertContains( 'diluxone_users_test_mobile_length', $errors->get_error_codes() );
		$this->assertStringContainsString( 'too long', (string) $errors->get_error_message( 'diluxone_users_test_mobile_length' ) );

		$_POST['test_mobile'] = '11 2345 6789';
		$errors               = diluxone_users_register_validate( new \WP_Error(), 'someone', 'someone@example.test' );

		$this->assertNotContains( 'diluxone_users_test_mobile_length', $errors->get_error_codes(), 'a good number goes through' );
	}

	public function test_the_profile_in_wp_admin_saves_nothing_and_says_why_under_the_number(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'test_city', 'Rosario' );

		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'         => wp_create_nonce( 'update-user_' . $user ),
				'test_city'        => 'Mendoza',
				'test_mobile'      => '11',
				'test_mobile_dial' => 'AR',
			)
		);

		diluxone_users_profile_save( $user );

		$this->assertSame( 'Rosario', get_user_meta( $user, 'test_city', true ), 'nothing of the plugin’s is saved' );
		$this->assertSame( '', get_user_meta( $user, 'test_mobile', true ) );

		$errors = new \WP_Error();
		diluxone_users_profile_phone_errors( $errors, true, get_userdata( $user ) );

		$this->assertContains( 'diluxone_users_test_mobile', $errors->get_error_codes(), 'and WordPress saves none of its part either' );

		ob_start();
		diluxone_users_fields_block( $user );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/id="diluxone-users-test_mobile"[^>]* aria-invalid="true"/', $html );
		$this->assertStringContainsString( 'id="diluxone-users-test_mobile-error">This number is too short for the country chosen.</p>', $html );
	}

	public function test_users_add_new_refuses_to_create_the_account(): void {
		$admin = $this->make_user( 'administrator' );

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		wp_set_current_user( $admin );
		$this->postAs(
			$admin,
			array(
				'_wpnonce_create-user' => wp_create_nonce( 'create-user' ),
				'test_mobile'          => '11 2345 6789 0123',
				'test_mobile_dial'     => 'AR',
			)
		);

		$errors = new \WP_Error();
		diluxone_users_profile_phone_errors( $errors, false, (object) array() );

		$this->assertContains( 'diluxone_users_test_mobile', $errors->get_error_codes() );

		// Nobody who may create accounts: nothing read, nothing said.
		$nobody = $this->make_user();
		wp_set_current_user( $nobody );
		$this->postAs(
			$nobody,
			array(
				'_wpnonce_create-user' => wp_create_nonce( 'create-user' ),
				'test_mobile'          => '11',
				'test_mobile_dial'     => 'AR',
			)
		);

		$errors = new \WP_Error();
		diluxone_users_profile_phone_errors( $errors, false, (object) array() );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_a_caller_that_does_not_check_first_still_does_not_store_a_wrong_length(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'test_mobile', '+541123456789' );

		diluxone_users_save(
			$user,
			array(
				'test_mobile'      => '123',
				'test_mobile_dial' => 'AR',
			)
		);

		$this->assertSame( '+541123456789', get_user_meta( $user, 'test_mobile', true ), 'kept what was there' );
	}

	public function test_a_saved_number_is_drawn_with_its_country_picked_and_grouped(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );
		update_user_meta( $user, 'test_mobile', '+541123456789' );

		$html = diluxone_users_shortcode_fields();

		$this->assertMatchesRegularExpression( "/<option value=\"AR\"[^>]* selected='selected'>Argentina \\+54</", $html );
		$this->assertMatchesRegularExpression( '/<input type="tel" id="test_mobile" name="test_mobile" value="11 2345 6789"/', $html );

		// A mobile, with its 9.
		update_user_meta( $user, 'test_mobile', '+5491123456789' );
		$this->assertStringContainsString( 'value="9 11 2345 6789"', diluxone_users_shortcode_fields() );

		// A code two countries share: the field's own country first.
		$fields                  = self::FIELDS;
		$fields[1]['options']    = array( 'US' );
		diluxone_users_update_option( 'diluxone_users_fields', $fields );
		update_user_meta( $user, 'test_mobile', '+12125551234' );
		$html = diluxone_users_shortcode_fields();

		$this->assertMatchesRegularExpression( "/<option value=\"US\"[^>]* selected='selected'>United States \\+1</", $html );
		$this->assertStringContainsString( 'value="212 555 1234"', $html );
	}
}
