<?php
/**
 * The sign-in shortcode in each state it can find somebody in.
 *
 * Signed in, it draws nothing. Halfway through a password reset whose key
 * still checks out, it is the new-password form. Halfway through the second
 * step, with the pending attempt's own key, it is the code form, on the
 * method asked for when the person has it and on their first one otherwise;
 * a key that is not the attempt's is the plain form again, so the address of
 * somebody else's attempt opens nothing.
 */

namespace Tests\Integration;

class CoverFieldsLoginStatesTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ diluxone_users_reset_cookie() ] );

		parent::tearDown();
	}

	public function test_somebody_signed_in_gets_no_form(): void {
		wp_set_current_user( $this->make_user() );

		$this->assertSame( '', diluxone_users_shortcode_login() );
	}

	public function test_a_reset_in_progress_is_the_new_password_form(): void {
		$user = get_userdata( $this->make_user() );
		$key  = get_password_reset_key( $user );

		$_COOKIE[ diluxone_users_reset_cookie() ] = $user->user_login . ':' . $key;

		$html = diluxone_users_shortcode_login();

		$this->assertStringContainsString( 'diluxone_users_reset_nonce', $html );
		$this->assertStringNotContainsString( 'diluxone_users_2fa_code', $html );
	}

	public function test_the_second_step_opens_only_with_the_attempts_own_key(): void {
		$user  = $this->make_user();
		$nonce = diluxone_users_2fa_pending_start( $user, 'password', false, home_url( '/' ) );

		$_GET = array(
			'diluxone_users_2fa'    => (string) $user,
			'diluxone_users_key'    => $nonce,
			'diluxone_users_method' => 'totp',
		);

		$challenge = diluxone_users_login_challenge();

		$this->assertSame( $user, $challenge['user_id'] );
		$this->assertSame( 'email', $challenge['method'], 'A method they do not have falls back to their first' );
		$this->assertSame( array( 'email' ), array_keys( $challenge['methods'] ) );

		$_GET['diluxone_users_method'] = 'email';
		$this->assertSame( 'email', diluxone_users_login_challenge()['method'] );

		$form = diluxone_users_shortcode_login();
		$this->assertStringContainsString( 'value="' . esc_attr( $nonce ) . '"', $form );

		$_GET['diluxone_users_key'] = 'not-the-key';
		$this->assertSame( array(), diluxone_users_login_challenge() );
		$this->assertStringNotContainsString( 'not-the-key', diluxone_users_shortcode_login() );

		$_GET = array( 'diluxone_users_2fa' => (string) $user );
		$this->assertSame( array(), diluxone_users_login_challenge(), 'No key at all' );
	}
}
