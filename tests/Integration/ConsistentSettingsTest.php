<?php
/**
 * A setting that makes another one moot takes it along.
 *
 * Each of these was a pair where turning one thing off left a second thing
 * saying, or doing, what the first one had stopped: a registration door that
 * mails a link on a site that mails none, a sign-in box that resolved public
 * names the site no longer has, wp-login.php kept as a second door on a site
 * with no password to type into it. The screen said one thing and the site
 * did another.
 */

namespace Tests\Integration;

class ConsistentSettingsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		do_action( 'diluxone_users_register_panels' );

	}

	public function test_the_link_creates_no_account_on_a_password_only_site(): void {
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );

		$this->assertFalse( diluxone_users_link_registers() );
		$this->assertSame( 'closed', diluxone_users_register_mode() );
		$this->assertNotContains( __( 'the e-mail link', 'diluxone-users' ), diluxone_users_register_doors_open() );
		$this->assertSame( 0, diluxone_users_user_for( 'nobody-' . wp_generate_password( 6, false ) . '@example.test' ) );
	}

	public function test_the_link_creates_the_account_while_it_is_a_way_in(): void {
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );

		$this->assertTrue( diluxone_users_link_registers() );
		$this->assertSame( 'login', diluxone_users_register_mode() );
	}

	public function test_a_social_door_with_nothing_to_press_is_not_counted_open(): void {
		diluxone_users_update_option( 'diluxone_users_login_register', 0 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );
		diluxone_users_update_option( 'diluxone_users_sso_login', 0 );

		$this->assertNotContains( __( 'a social account', 'diluxone-users' ), diluxone_users_register_doors_open() );
	}

	public function test_public_names_off_means_the_box_takes_none(): void {
		$user = get_userdata( $this->make_user() );

		diluxone_users_update_option( 'diluxone_users_handle_login', 1 );
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 0 );

		$this->assertFalse( diluxone_users_handle_login_on() );
		$this->assertSame( $user->user_login, diluxone_users_handle_login_email( $user->user_login ) );
	}

	public function test_turning_the_password_off_moves_the_second_door_to_the_third_answer(): void {
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );
		$this->postPanel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array( 'link' ) ) );

		diluxone_users_login_ways_save();

		$this->assertSame( 'link', diluxone_users_raw_get( 'diluxone_users_login_method' ) );
		$this->assertSame( 'auto', diluxone_users_raw_get( 'diluxone_users_wp_screens' ) );
	}

	public function test_a_password_kept_keeps_the_second_door(): void {
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );
		$this->postPanel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array( 'link', 'password' ) ) );

		diluxone_users_login_ways_save();

		$this->assertSame( 'wp', diluxone_users_raw_get( 'diluxone_users_wp_screens' ) );
	}

	public function test_the_second_door_is_refused_on_save_without_a_password(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		$this->postPanel( 'diluxone-users-login', array( 'diluxone_users_wp_screens' => 'wp' ) );

		diluxone_users_login_page_save();

		$this->assertSame( 'auto', diluxone_users_raw_get( 'diluxone_users_wp_screens' ) );
	}
}
