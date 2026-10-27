<?php
/**
 * Access › Summary's rail: whether anybody can sign in right now, and through
 * which ways, read from the same settings the table reads.
 *
 * Nobody signing in at all ("Nobody can sign in here.") is not reachable from
 * the settings today: the method always opens the link, the password or both.
 * The cases below are the ones a site can be in.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class AccessSummaryRailTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	protected function tearDown(): void {
		$this->cover_settings_reset();

		parent::tearDown();
	}

	/** A provider set up and tested, so it works. */
	private function a_working_provider(): void {
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

	public function test_open_ways_are_listed_and_active(): void {
		$this->a_working_provider();
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$state = diluxone_users_login_state();
		$names = implode( ', ', diluxone_users_sso_working_names() );

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( '', $state['why'] );
		$this->assertSame( esc_html( 'Open: people sign in with the e-mail link, a username and password, ' . $names . '.' ), $state['detail'] );
	}

	public function test_the_link_alone_is_named_alone(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		diluxone_users_update_option( 'diluxone_users_sso_login', 0 );

		$state = diluxone_users_login_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( 'Open: people sign in with the e-mail link.', $state['detail'] );
	}

	public function test_social_on_with_no_provider_working_is_pending(): void {
		diluxone_users_update_option( 'diluxone_users_sso', array(), false );
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$state = diluxone_users_login_state();

		$this->assertSame( 'pending', $state['state'] );
		$this->assertSame( 'no provider is working yet', $state['why'] );
		$this->assertSame( 'Open: people sign in with a username and password.', $state['detail'] );
	}

	public function test_nobody_decided_reads_not_configured_with_what_wordpress_does(): void {
		diluxone_users_delete_option( 'diluxone_users_login_method' );
		diluxone_users_delete_option( 'diluxone_users_sso_login' );

		$state = diluxone_users_login_state();

		$this->assertSame( 'unset', $state['state'] );
		$this->assertSame( '', $state['why'] );
		$this->assertSame( esc_html( 'Everybody signs in on wp-login.php with a username and password, as WordPress has it.' ), $state['detail'] );
	}

	public function test_the_tab_draws_the_rail_with_one_line(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		diluxone_users_update_option( 'diluxone_users_sso_login', 0 );

		$html = $this->draw( 'diluxone_users_screen_login_summary' );

		$this->assertSame( 1, substr_count( $html, 'class="du-state"' ), 'one state in the rail' );
		$this->assertStringContainsString( '<p class="du-state__line">Open: people sign in with the e-mail link.</p>', $html );
	}
}
