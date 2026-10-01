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

		diluxone_users_reset_catch();

		$this->addToAssertionCount( 1 );
	}

	public function test_wordpress_reset_link_with_a_list_for_a_login_is_left_alone(): void {
		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Array sign-in' ) );

		$_GET = array(
			'key'   => 'abc',
			'login' => array( 'admin' ),
		);

		try {
			diluxone_users_reset_to_site();
		} catch ( Support\RedirectException $e ) {
			$this->fail( 'A login sent as a list was carried on to ' . $e->url );
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_a_return_address_sent_as_a_list_after_a_password_is_no_address(): void {
		$user = $this->make_user();

		$this->postAs( null, array( 'redirect_to' => array( home_url( '/' ) ) ) );

		try {
			diluxone_users_return_password( (string) get_userdata( $user )->user_login, get_userdata( $user ) );
		} catch ( Support\RedirectException $e ) {
			$this->assertStringNotContainsString( 'Array', $e->url );
		}

		$this->addToAssertionCount( 1 );
	}
}
