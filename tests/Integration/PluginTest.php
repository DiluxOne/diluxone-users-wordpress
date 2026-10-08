<?php
/**
 * That the plugin really boots on a clean WordPress.
 *
 * The unit tests run against stubs and do not see this: that the main file
 * loads its twenty-odd includes without clashing, that the options seed
 * themselves, and that the account area exists before anybody configures it.
 * It is the floor of "it works on a fresh install".
 */

namespace Tests\Integration;

class PluginTest extends IntegrationTestCase {

	public function test_the_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'DILUXONE_USERS_VERSION' ) );
		$this->assertTrue( function_exists( 'diluxone_users_option' ) );
	}

	public function test_options_have_a_default_value(): void {
		// With nothing stored, every setting has to answer something reasonable:
		// a freshly installed plugin cannot depend on somebody going through
		// all of its screens before the site works. And the answer is the one
		// that leaves the site as WordPress has it.
		foreach ( array( 'diluxone_users_login_method', 'diluxone_users_2fa_mode', 'diluxone_users_privacy_export' ) as $key ) {
			diluxone_users_delete_option( $key );
		}

		$this->assertSame( 'password', diluxone_users_option( 'diluxone_users_login_method' ) );
		$this->assertSame( 'off', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_privacy_export' ) );
	}

	public function test_the_account_area_ships_its_sections(): void {
		$sections = diluxone_users_sections( true );

		foreach ( array( 'home', 'details', 'accounts', 'security', 'privacy', 'notifications' ) as $id ) {
			$this->assertArrayHasKey( $id, $sections, "the section $id is missing" );
		}
	}

	public function test_with_no_social_networks_there_is_no_linked_accounts_section(): void {
		// The rule that holds the plugin together: what is not configured does
		// not show up, with nothing to turn off by hand.
		wp_set_current_user( $this->make_user() );

		$this->assertArrayNotHasKey( 'accounts', diluxone_users_sections() );
	}

	public function test_wordpress_own_fields_are_among_the_fields(): void {
		$keys = wp_list_pluck( diluxone_users_fields(), 'key' );

		$this->assertContains( 'first_name', $keys );
		$this->assertContains( 'last_name', $keys );
	}

	public function test_the_plugin_ships_its_own_notifications(): void {
		$this->assertArrayHasKey( 'diluxone_users_notify_login', diluxone_users_notification_prefs() );
		$this->assertArrayHasKey( 'diluxone_users_notify_security', diluxone_users_notification_prefs() );
	}
}
