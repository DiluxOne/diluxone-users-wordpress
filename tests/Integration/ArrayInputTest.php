<?php
/**
 * A value sent as a list where a string is expected is no value, not a crash.
 *
 * `field[]=x` in a form, a query string or a cookie name turns what PHP hands
 * over into an array. Several readers passed it straight to a WordPress
 * function that takes a string — sanitize_email(), esc_url_raw(),
 * sanitize_title(), sanitize_user() — which on PHP 8 is a TypeError: a 500
 * and a fatal in the log, from a public form or a link anybody can type. Each
 * of them now reads it as absent.
 */

namespace Tests\Integration;

class ArrayInputTest extends IntegrationTestCase {

	/** @var array<int, int> Pages made by the test. */
	private array $pages = array();

	protected function setUp(): void {
		parent::setUp();

		add_filter( 'wp_redirect', array( $this, 'throw_redirect' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_redirect' ) );

		foreach ( $this->pages as $page ) {
			wp_delete_post( $page, true );
		}

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] );

		parent::tearDown();
	}

	private function page( string $title ): int {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		$this->pages[] = $page;

		return $page;
	}

	public function test_the_registration_form_reads_an_address_sent_as_a_list_as_no_address(): void {
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );
		diluxone_users_update_option( 'diluxone_users_register_page', $this->page( 'Array register' ) );
		update_option( 'users_can_register', 1 );

		$this->postAs(
			null,
			array(
				'diluxone_users_register_nonce' => wp_create_nonce( 'diluxone_users_register' ),
				'diluxone_users_email'          => array( 'someone@example.test' ),
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_register_request' );

		$this->assertSame( 'email', $this->redirectState( $url ), 'told the address is missing' );
	}

	public function test_a_section_asked_as_a_list_draws_the_first_section(): void {
		$_GET = array( 'section' => array( 'security' ) );

		$this->assertIsString( diluxone_users_current_section() );
	}

	public function test_a_return_cookie_holding_a_list_holds_nothing(): void {
		$_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] = array( home_url( '/' ) );

		$this->assertSame( '', diluxone_users_return_held() );
	}

	public function test_a_reset_link_with_a_list_for_a_login_is_left_alone(): void {
		$_GET = array(
			'diluxone_users_key'   => 'abc',
			'diluxone_users_login' => array( 'admin' ),
		);

		$this->stays( 'diluxone_users_reset_catch' );
		$this->assertSame( array(), self::$cookies, 'no reset cookie' );
	}

	public function test_wordpress_reset_link_with_a_list_for_a_login_is_left_alone(): void {
		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Array sign-in' ) );

		$_GET = array(
			'key'   => 'abc',
			'login' => array( 'admin' ),
		);

		$this->stays( 'diluxone_users_reset_to_site', 'a login sent as a list is carried nowhere' );
	}

	public function test_a_return_address_sent_as_a_list_after_a_password_is_no_address(): void {
		$user = $this->make_user();

		$this->postAs( null, array( 'redirect_to' => array( home_url( '/' ) ) ) );

		$this->stays( fn() => diluxone_users_return_password( (string) get_userdata( $user )->user_login, get_userdata( $user ) ), 'no address, nowhere to go back to' );
	}

	/**
	 * Every whole number a settings screen reads, and what it saves when the
	 * value is not one value.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: array<string, mixed>}>
	 */
	public static function numbers(): array {
		$design = 'diluxone-users-design';

		return array(
			'the days a browser is remembered' => array( 'diluxone_users_2fa_save', 'diluxone-users-security', 'diluxone_users_2fa_remember_days', 30, array( 'diluxone_users_2fa_mode' => 'off' ) ),
			'the long session'                 => array( 'diluxone_users_sessions_save', 'diluxone-users-security', 'diluxone_users_session_long_days', 0, array() ),
			'the short session'                => array( 'diluxone_users_sessions_save', 'diluxone-users-security', 'diluxone_users_session_short_days', 0, array() ),
			'the days the log keeps'           => array( 'diluxone_users_log_settings_save', 'diluxone-users-reports', 'diluxone_users_log_days', 90, array() ),
			'the sign-in page'                 => array( 'diluxone_users_login_page_save', 'diluxone-users-login', 'diluxone_users_login_page', 0, array() ),
			'the life of a link'               => array( 'diluxone_users_login_ways_save', 'diluxone-users-login', 'diluxone_users_login_expiry', 15, array( 'diluxone_users_login_method' => array( 'link', 'password' ) ) ),
			'the wait between links'           => array( 'diluxone_users_login_ways_save', 'diluxone-users-login', 'diluxone_users_login_throttle', 60, array( 'diluxone_users_login_method' => array( 'link', 'password' ) ) ),
			'the registration page'            => array( 'diluxone_users_screen_register_save', 'diluxone-users-login', 'diluxone_users_register_page', 0, array() ),
			'the account page'                 => array( 'diluxone_users_account_page_save', 'diluxone-users-account', 'diluxone_users_account_page', 0, array() ),
			'the shortest public name'         => array( 'diluxone_users_account_handle_save', 'diluxone-users-account', 'diluxone_users_handle_min', 3, array() ),
			'the longest public name'          => array( 'diluxone_users_account_handle_save', 'diluxone-users-account', 'diluxone_users_handle_max', 30, array() ),
			'the wait between public names'    => array( 'diluxone_users_account_handle_save', 'diluxone-users-account', 'diluxone_users_handle_cooldown', 30, array() ),
			'the sign-in logo'                 => array( 'diluxone_users_design_brand_save', $design, 'diluxone_users_login_logo', 0, array() ),
			'the sign-in picture'              => array( 'diluxone_users_design_login_save', $design, 'diluxone_users_login_image', 0, array() ),
			'the panel logo'                   => array( 'diluxone_users_design_login_save', $design, 'diluxone_users_login_panel_logo', 0, array() ),
			'the account cover'                => array( 'diluxone_users_design_account_save', $design, 'diluxone_users_account_cover_image', 0, array() ),
			'the largest photo'                => array( 'diluxone_users_design_photo_save', $design, 'diluxone_users_avatar_max_kb', 2048, array() ),
			'the wp-login.php logo'            => array( 'diluxone_users_design_wp_save', $design, 'diluxone_users_wp_login_logo', 0, array() ),
		);
	}

	/**
	 * `field[]=99` is no number. absint() reads a list as 1: the first
	 * attachment as the logo, page 1 as the sign-in page, a log kept for one
	 * day and purged down to nothing the next night. A list is read as no
	 * answer at all, the way a field that was not sent is.
	 *
	 * @dataProvider numbers
	 *
	 * @param array<string, mixed> $with
	 */
	public function test_a_number_sent_as_a_list_is_no_number( string $save, string $screen, string $key, int $absent, array $with ): void {
		if ( is_multisite() && 'network' === diluxone_users_option_scope( $key ) ) {
			$this->in_network_admin();
		}

		// The registration screen lists roles with WordPress's admin helper.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		diluxone_users_update_option( $key, 7 );
		$this->postPanel( $screen, $with + array( $key => array( '99' ) ) );

		$errors = array();
		set_error_handler(
			static function ( int $level, string $message ) use ( &$errors ): bool {
				$errors[] = $message;

				return true;
			}
		);

		ob_start();

		try {
			call_user_func( $save );
		} finally {
			ob_end_clean();
			restore_error_handler();
		}

		$this->assertSame( array(), $errors, 'no warning' );
		$this->assertSame( $absent, (int) diluxone_users_raw_get( $key ), 'read as not sent, never as 1' );
	}

	/**
	 * A sign-in link whose parts arrive as lists is no link: absint() would
	 * read the person as 1, the first administrator. Nobody is signed in.
	 */
	public function test_a_link_whose_parts_are_lists_signs_nobody_in(): void {
		$user  = $this->make_user();
		$token = diluxone_users_token_create( $user );

		foreach ( array( array( array( (string) $user ), array( $token ) ), array( array( '1' ), $token ) ) as [ $who, $what ] ) {
			$_GET = array(
				'diluxone_users_login' => $who,
				'diluxone_users_token' => $what,
			);

			$url = $this->expectRedirect( 'diluxone_users_login_consume' );

			$this->assertSame( 'expired', $this->redirectState( $url ) );
			$this->assertSame( 0, get_current_user_id() );
		}
	}

	/** An address sent as a list to the link request is no address: nothing is mailed, nothing remembered. */
	public function test_an_address_sent_as_a_list_asks_for_no_link(): void {
		$this->postAs(
			null,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => array( 'a@b.c' ),
			)
		);

		$this->stays_or_redirects( 'diluxone_users_login_request' );

		$this->assertSame( array(), self::$mail );
		$this->assertArrayNotHasKey( 'diluxone_users_sent', self::$cookies );
	}

	/** Runs a handler that may redirect, and swallows only that. */
	private function stays_or_redirects( callable $handler ): void {
		try {
			$handler();
		} catch ( Support\RedirectException $e ) {
			$this->assertStringNotContainsString( 'Array', $e->url );
		}
	}

	/** The social return with lists for its parameters reads them as absent, and asks the provider nothing. */
	public function test_the_social_return_with_lists_asks_for_nothing(): void {
		Support\MockProvider::install();

		try {
			$_GET = array(
				'diluxone_users_sso' => array( 'mock' ),
				'code'               => array( 'x' ),
				'state'              => array( 'y' ),
			);
			diluxone_users_sso_query_snapshot();

			$this->assertSame( '', diluxone_users_sso_param( 'code' ) );
			$this->stays( 'diluxone_users_sso_handle', 'no provider named: nothing to do' );
			$this->assertSame( array(), Support\MockProvider::$requests );

			$_GET = array(
				'diluxone_users_sso' => 'mock',
				'error'              => array( 'a' ),
			);
			diluxone_users_sso_query_snapshot();
			$this->stays_or_redirects( 'diluxone_users_sso_handle' );
			$this->assertSame( array(), Support\MockProvider::$requests );
		} finally {
			Support\MockProvider::remove();
		}
	}
}
