<?php
/**
 * The emergency switch: `DILUXONE_USERS_SAFE_MODE` in wp-config.php.
 *
 * While it is on, the plugin steps aside at every door it put up — nothing
 * redirects, nobody is asked for the second step, passkeys and social
 * sign-in are shut — and WordPress's own wp-login.php works as it does
 * without the plugin. The dashboard says so on every page.
 *
 * The constant cannot be taken back inside one process, so the cases turn
 * it on through its filter, which is what the constant feeds. Each case
 * shows the door doing its job first and then standing aside. Every case
 * runs on both topologies; the network's own doors have a single-site
 * counterpart.
 */

namespace Tests\Integration;

class SafeModeTest extends IntegrationTestCase {

	/** @var array<int, int> Sites this test made. */
	private array $sites = array();

	/** @var array<string, string|null> What was in $_SERVER before a test changed it. */
	private array $server = array();

	protected function setUp(): void {
		parent::setUp();

		$this->server = array( 'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null );
	}

	protected function tearDown(): void {
		remove_all_filters( 'diluxone_users_safe_mode' );

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		parent::tearDown();
	}

	private function safe_mode(): void {
		add_filter( 'diluxone_users_safe_mode', '__return_true' );
	}

	/** Runs a handler; returns where it redirected, or '' when it let the request through. */
	private function maybe_redirect( callable $handler ): string {
		try {
			$handler();
		} catch ( Support\RedirectException $e ) {
			return $e->url;
		}

		return '';
	}

	private function login_page(): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Sign in',
				'post_content' => '[diluxone_users_login]',
			)
		);

		diluxone_users_update_option( 'diluxone_users_login_page', $id );

		return $id;
	}

	public function test_it_is_off_unless_the_site_turns_it_on(): void {
		$this->assertFalse( defined( 'DILUXONE_USERS_SAFE_MODE' ), 'The test environment does not define it' );
		$this->assertFalse( diluxone_users_safe_mode() );

		$this->safe_mode();

		$this->assertTrue( diluxone_users_safe_mode() );
	}

	public function test_wp_login_is_wordpress_s_again(): void {
		$this->login_page();
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'mine' );

		$_GET = array();
		$this->assertNotSame( '', $this->maybe_redirect( 'diluxone_users_block_wp_login' ), 'Taken over, it sends wp-login.php to the page' );

		$this->safe_mode();

		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_block_wp_login' ), 'In safe mode it is left alone' );
	}

	public function test_the_reset_link_stays_on_wp_login(): void {
		$this->login_page();
		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );

		$_GET = array(
			'action' => 'rp',
			'key'    => 'a-key',
			'login'  => 'someone',
		);
		$this->assertNotSame( '', $this->maybe_redirect( 'diluxone_users_reset_to_site' ) );

		$this->safe_mode();

		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_reset_to_site' ) );
	}

	public function test_forgetting_a_password_is_wordpress_s_again(): void {
		$page = $this->login_page();
		diluxone_users_update_option( 'diluxone_users_lost_password', 'link' );
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );

		$core = site_url( 'wp-login.php?action=lostpassword', 'login' );

		$this->assertSame( (string) get_permalink( $page ), diluxone_users_lost_password_url( $core ), 'The plugin points it at its page' );

		$this->safe_mode();

		$this->assertSame( $core, diluxone_users_lost_password_url( $core ) );
	}

	public function test_the_dashboard_profile_is_not_sent_anywhere(): void {
		$account = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Account',
				'post_content' => '[diluxone_users_account]',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $account );
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'redirect' );

		wp_set_current_user( $this->make_user() );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';

		$this->assertNotSame( '', $this->maybe_redirect( 'diluxone_users_wp_profile_guard' ) );

		$this->safe_mode();

		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_wp_profile_guard' ) );
	}

	public function test_the_privacy_confirmation_stays_on_wp_login(): void {
		$user    = $this->make_user();
		// Filed the way the account area files it, so the link is the plugin's.
		$request = wp_create_user_request( get_userdata( $user )->user_email, 'export_personal_data', array( DILUXONE_USERS_EXPORT_KEY => $user ) );
		$this->assertIsInt( $request );
		$key = wp_generate_user_request_key( $request );

		wp_set_current_user( $user );
		$_GET = array(
			'action'      => 'confirmaction',
			'request_id'  => (string) $request,
			'confirm_key' => $key,
		);

		$this->assertNotSame( '', $this->maybe_redirect( 'diluxone_users_confirm_intercept' ), 'Taken to the account area' );

		$this->safe_mode();

		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_confirm_intercept' ), 'Left to WordPress' );
	}

	public function test_nobody_is_asked_for_the_second_step(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		$user     = $this->make_user();
		$password = 'Correct-Horse-9';
		wp_set_password( $password, $user );
		$login = get_userdata( $user )->user_login;

		$this->assertTrue( diluxone_users_2fa_required( $user, 'password' ) );

		$this->safe_mode();

		$this->assertFalse( diluxone_users_2fa_required( $user, 'password' ) );

		$signed = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
			)
		);

		$this->assertInstanceOf( \WP_User::class, $signed, 'WordPress\'s own sign-in, with nothing after it' );
		$this->assertSame( '', (string) get_user_meta( $user, 'diluxone_users_2fa_pending', true ) );
	}

	public function test_passkeys_and_social_sign_in_are_shut_and_their_settings_kept(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
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

		$this->assertTrue( diluxone_users_passkeys_enabled() );
		$this->assertArrayHasKey( 'google', diluxone_users_sso_available() );

		// The round trip, heading out to the provider.
		$_GET = array(
			'diluxone_users_sso' => 'google',
			'diluxone_users_go'  => '1',
		);
		diluxone_users_sso_query( true );
		$this->assertNotSame( '', $this->maybe_redirect( 'diluxone_users_sso_handle' ), 'Off, it heads out' );
		self::$cookies = array();

		$this->safe_mode();

		$this->assertFalse( diluxone_users_passkeys_enabled(), 'No passkey door' );
		$this->assertFalse( diluxone_users_has_passkeys() );
		$this->assertSame( array(), diluxone_users_sso_available(), 'No social buttons' );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_passkey_enabled' ), 'The setting is kept' );
		$this->assertSame( 'enabled', diluxone_users_sso_state( 'google' ), 'The provider is kept' );

		// The round trip's route is nothing: no state cookie, no redirect.
		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_sso_handle' ) );
		$this->assertSame( array(), self::$cookies );
	}

	public function test_the_dashboard_says_so_on_every_page(): void {
		wp_set_current_user( 1 );

		ob_start();
		diluxone_users_safe_mode_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing while it is off' );

		$this->safe_mode();

		ob_start();
		diluxone_users_safe_mode_notice();
		$said = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-diluxone-users-safe-mode', $said );
		$this->assertStringContainsString( 'DILUXONE_USERS_SAFE_MODE', $said );
		$this->assertNotFalse( has_action( 'admin_notices', 'diluxone_users_safe_mode_notice' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices', 'diluxone_users_safe_mode_notice' ) );

		wp_set_current_user( $this->make_user() );
		ob_start();
		diluxone_users_safe_mode_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'Only to whoever manages the site' );
	}

	public function test_on_a_network_every_site_keeps_its_own_wp_login(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network; on a single site wp-login.php is the site\'s own either way: SafeModeTest › on a single site wp-login is the site\'s own.' );
		}

		$this->login_page();

		$beta          = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/safe-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Safe mode test site',
			)
		);
		$this->sites[] = $beta;

		switch_to_blog( $beta );

		$this->assertTrue( diluxone_users_sends_to_hub() );
		$_GET = array();
		$this->assertNotSame( '', $this->maybe_redirect( 'diluxone_users_wp_login_to_hub' ), 'Sent to the hub' );
		$this->assertStringNotContainsString( home_url(), wp_login_url(), 'Its wp_login_url() is the hub\'s' );

		$this->safe_mode();

		$this->assertFalse( diluxone_users_sends_to_hub() );
		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_wp_login_to_hub' ), 'Its own wp-login.php' );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_post_to_hub' ), 'A form posted here is not sent to the hub' );

		restore_current_blog();
	}

	public function test_on_a_single_site_wp_login_is_the_site_s_own(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site: the network half is SafeModeTest › on a network every site keeps its own wp-login.' );
		}

		$this->safe_mode();

		$this->assertFalse( diluxone_users_sends_to_hub() );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );
	}
}
