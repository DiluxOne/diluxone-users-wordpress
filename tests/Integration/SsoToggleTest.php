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

	private int $admin = 0;

	protected function tearDown(): void {
		if ( is_multisite() && $this->admin > 0 ) {
			revoke_super_admin( $this->admin );
		}

		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		$this->admin = $this->make_user( 'administrator' );

		// On a network, whoever switches a provider administers the network.
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}

		wp_set_current_user( $this->admin );

		diluxone_users_update_option(
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
		// On a network the providers are the network's, switched from there.
		$this->in_network_admin();

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

	/**
	 * A site's administrator reaching the same address on their own dashboard
	 * switches nothing: on a network the button is the network's.
	 */
	public function test_on_a_network_a_site_cannot_switch_a_provider(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		$_GET = array(
			'page'                  => 'diluxone-users-social',
			'red'                   => 'google',
			'diluxone_users_action' => 'off',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_social_toggle' );

		diluxone_users_social_toggle();

		$this->assertSame( 'enabled', diluxone_users_sso_state( 'google' ) );
	}

	/** On a single site the site's own screen is where a provider is switched. */
	public function test_on_a_single_site_the_sites_screen_switches_a_provider(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site: on a network the button is the network’s.' );
		}

		$_GET = array(
			'page'                  => 'diluxone-users-social',
			'red'                   => 'google',
			'diluxone_users_action' => 'off',
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_social_toggle' );

		$this->expectRedirect( 'diluxone_users_social_toggle' );

		$this->assertSame( 'disabled', diluxone_users_sso_state( 'google' ) );
	}

	public function test_forgetting_it_deletes_the_app_and_its_test(): void {
		$this->press( 'forget' );

		$this->assertArrayNotHasKey( 'google', (array) diluxone_users_raw_get( 'diluxone_users_sso' ) );
		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'google' ) );
	}
}
