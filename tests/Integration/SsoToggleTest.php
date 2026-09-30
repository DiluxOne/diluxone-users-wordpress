<?php
/**
 * Turning a network off and forgetting its app are two things.
 *
 * They were one button that said "take it off", which read as deleting the
 * app and only hid its button. Now "off" hides the button and keeps the app,
 * and "delete its settings" forgets the ID, the secret and the test.
 */

namespace Tests\Integration;

class SsoToggleTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( $this->make_user( 'administrator' ) );

		update_option(
			'diluxone_users_sso',
			array(
				'google' => array(
					'active' => 1,
					'id'     => 'client-id',
					'secret' => 'client-secret',
					'tested' => 1,
				),
			),
			false
		);
	}

	private function press( string $action ): void {
		$_GET = array(
			'page'                  => 'diluxone-users-social',
			'red'                   => 'google',
			'diluxone_users_action' => $action,
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_social_toggle' );

		$this->expectRedirect( 'diluxone_users_social_toggle' );
	}

	public function test_off_hides_the_button_and_keeps_the_app(): void {
		$this->press( 'off' );

		$credentials = diluxone_users_sso_credentials( 'google' );

		$this->assertFalse( $credentials['active'] );
		$this->assertSame( 'client-id', $credentials['id'] );
		$this->assertSame( 'client-secret', $credentials['secret'] );
		$this->assertSame( 'disabled', diluxone_users_sso_state( 'google' ) );
	}

	public function test_forgetting_it_deletes_the_app_and_its_test(): void {
		$this->press( 'forget' );

		$this->assertArrayNotHasKey( 'google', (array) get_option( 'diluxone_users_sso' ) );
		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'google' ) );
	}
}
