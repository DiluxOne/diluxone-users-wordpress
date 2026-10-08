<?php
/**
 * Activating the plugin decides nothing for the site.
 *
 * Every feature a person could notice starts off, and every setting that
 * changed its default on the way there has the old answer in
 * diluxone_users_defaults_before(), so a site that ran 1.0.0 keeps behaving
 * the way it did. A default changed without its old value written down here
 * would switch something off on those sites without anybody deciding to.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class DefaultsTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/multisite.php';
		require_once DILUXONE_USERS_DIR . 'includes/migrate.php';
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_multisite']  = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array{0: string, 1: mixed}> */
	public function off_by_default(): array {
		return array(
			'sign-in stays WordPress\'s'        => array( 'diluxone_users_login_method', 'password' ),
			'nothing is recorded'                => array( 'diluxone_users_log_levels', array() ),
			'no second step offered'             => array( 'diluxone_users_2fa_mode', 'off' ),
			'no list of sessions'                => array( 'diluxone_users_sessions_show', 0 ),
			'sessions survive a reset'           => array( 'diluxone_users_reset_ends_sessions', 0 ),
			'session length is WordPress\'s'     => array( 'diluxone_users_session_long_days', 0 ),
			'no data export from the account'    => array( 'diluxone_users_privacy_export', 0 ),
			'no account closing from it'         => array( 'diluxone_users_privacy_delete', 0 ),
			'no photo of their own'              => array( 'diluxone_users_avatar_upload', 0 ),
			'Gravatar as WordPress has it'       => array( 'diluxone_users_avatar_gravatar', 1 ),
			'WordPress\'s registration form'     => array( 'diluxone_users_wp_register_fields', 0 ),
			'no registration form of its own'    => array( 'diluxone_users_register_form', 0 ),
			'no account by e-mail link'          => array( 'diluxone_users_login_register', 0 ),
			'no account by social sign-in'       => array( 'diluxone_users_sso_register', 0 ),
			'no public name'                     => array( 'diluxone_users_handle_enabled', 0 ),
			'the admin bar as WordPress has it'  => array( 'diluxone_users_admin_bar', 'wp' ),
			'the dashboard profile open'         => array( 'diluxone_users_wp_profile', 'allow' ),
			'wp-login.php not branded'           => array( 'diluxone_users_wp_login_brand', 0 ),
			'wp-login.php not taken over'        => array( 'diluxone_users_wp_screens', 'auto' ),
			'the lost password is WordPress\'s'  => array( 'diluxone_users_lost_password', 'wp' ),
			'no sign-in page'                    => array( 'diluxone_users_login_page', 0 ),
			'no account page'                    => array( 'diluxone_users_account_page', 0 ),
			'nothing in the site\'s menu'        => array( 'diluxone_users_menu_location', '' ),
			'every site keeps its wp-login.php'  => array( 'diluxone_users_network_hub', 0 ),
			'membership adds nobody'             => array( 'diluxone_users_membership_confirmed', 0 ),
		);
	}

	/**
	 * @dataProvider off_by_default
	 * @param mixed $expected
	 */
	public function test_a_fresh_install_leaves_it_as_wordpress_has_it( string $key, $expected ): void {
		$this->assertSame( $expected, diluxone_users_option( $key ) );
	}

	public function test_the_plugin_sends_none_of_its_own_notices_until_asked(): void {
		$rules = diluxone_users_option( 'diluxone_users_notice_rules' );

		$this->assertSame( 'never', $rules['diluxone_users_notify_login'] );
		$this->assertSame( 'never', $rules['diluxone_users_notify_security'] );
	}

	public function test_every_old_answer_names_a_setting_that_exists(): void {
		$defaults = diluxone_users_option_defaults();

		foreach ( array_keys( diluxone_users_defaults_before() ) as $key ) {
			$this->assertArrayHasKey( $key, $defaults, $key );
		}
	}

	public function test_the_old_answers_are_what_1_0_0_did(): void {
		$GLOBALS['_test_wp_options']['users_can_register'] = 1;

		$before = diluxone_users_defaults_before();

		$this->assertSame( 'both', $before['diluxone_users_login_method'] );
		$this->assertSame( array( 'access' ), $before['diluxone_users_log_levels'] );
		$this->assertSame( array(), $before['diluxone_users_notice_rules'] );
		$this->assertSame( 'optional', $before['diluxone_users_2fa_mode'] );
		$this->assertSame( 1, $before['diluxone_users_sessions_show'] );
		$this->assertSame( 1, $before['diluxone_users_reset_ends_sessions'] );
		$this->assertSame( 1, $before['diluxone_users_privacy_export'] );
		$this->assertSame( 1, $before['diluxone_users_privacy_delete'] );
		$this->assertSame( 1, $before['diluxone_users_avatar_upload'] );
		$this->assertSame( 1, $before['diluxone_users_wp_register_fields'] );
		$this->assertSame( 1, $before['diluxone_users_login_register'], 'seeded from WordPress\'s own answer' );
		$this->assertSame( 1, $before['diluxone_users_sso_register'] );
		$this->assertArrayNotHasKey( 'diluxone_users_network_hub', $before, 'a single site has no hub' );
	}

	/** On a network the hub is an old answer too, beside the registration form's fields. */
	public function test_on_a_network_the_old_answers_have_the_hub(): void {
		$GLOBALS['_test_multisite'] = true;
		Functions\when( 'diluxone_users_network_takes_accounts' )->justReturn( false );

		$before = diluxone_users_defaults_before();

		$this->assertSame( 1, $before['diluxone_users_network_hub'] );
		$this->assertSame( 1, $before['diluxone_users_wp_register_fields'] );
		$this->assertSame( 0, $before['diluxone_users_login_register'] );
	}

	public function test_every_changed_default_has_its_old_answer(): void {
		$before   = diluxone_users_defaults_before();
		$defaults = diluxone_users_option_defaults();

		foreach ( $before as $key => $old ) {
			if ( in_array( $key, array( 'diluxone_users_login_register', 'diluxone_users_sso_register' ), true ) ) {
				continue;
			}

			$this->assertNotSame( $defaults[ $key ], $old, $key . ' did not change: it has no business here' );
		}
	}
}
