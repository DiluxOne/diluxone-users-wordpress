<?php
/**
 * The second step always has somewhere to be answered.
 *
 * With no sign-in page the challenge used to send people to wp-login.php,
 * which drew the password form again: whoever had turned the second step on
 * could not finish signing in. Now wp-login.php draws it, a page nobody can
 * open counts as no page, and where there is truly nowhere — no page, and a
 * site that says wp-login.php does not draw it — the step is neither asked
 * nor offered, and "required" cannot be saved.
 *
 * Every case runs on both topologies: on a network the test site is the hub,
 * where the sign-in page lives.
 */

namespace Tests\Integration;

class TwoFactorSurfaceTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		remove_all_filters( 'diluxone_users_2fa_on_wp_login' );

		parent::tearDown();
	}

	/** A sign-in page in whatever state is asked for, chosen in the settings. */
	private function page( string $status = 'publish', string $password = '' ): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'     => 'page',
				'post_status'   => $status,
				'post_title'    => 'Sign in',
				'post_content'  => '[diluxone_users_login]',
				'post_password' => $password,
			)
		);

		diluxone_users_update_option( 'diluxone_users_login_page', $id );

		return $id;
	}

	/** Starts a challenge and returns where it sent the person. */
	private function challenge(): string {
		return $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, 'password', false, home_url( '/after/' ) ) );
	}

	public function test_with_no_page_the_challenge_is_drawn_by_wp_login(): void {
		$url = $this->challenge();

		$this->assertSame( 'wp-login', diluxone_users_2fa_surface() );
		$this->assertStringStartsWith( wp_login_url(), $url );
		$this->assertSame( DILUXONE_USERS_2FA_ACTION, $this->queryArg( $url, 'action' ) );
		$this->assertSame( (string) $this->user, $this->queryArg( $url, 'diluxone_users_2fa' ) );
		$this->assertNotSame( '', $this->queryArg( $url, 'diluxone_users_key' ) );

		// WordPress keeps an action of its own only while something listens on
		// `login_form_{action}`: without it wp-login.php draws the password form.
		$this->assertNotFalse( has_action( 'login_form_' . DILUXONE_USERS_2FA_ACTION, 'diluxone_users_2fa_wp_login' ) );
	}

	public function test_with_a_page_the_challenge_is_drawn_by_the_page(): void {
		$page = $this->page();
		$url  = $this->challenge();

		$this->assertSame( 'page', diluxone_users_2fa_surface() );
		$this->assertStringStartsWith( (string) get_permalink( $page ), $url );
		$this->assertSame( '', $this->queryArg( $url, 'action' ) );
	}

	public function test_a_page_nobody_can_open_is_no_page(): void {
		foreach ( array( array( 'draft', '' ), array( 'private', '' ), array( 'trash', '' ), array( 'publish', 'secret' ) ) as [ $status, $password ] ) {
			$this->page( $status, $password );

			$this->assertSame( 'wp-login', diluxone_users_2fa_surface(), "A {$status} page" . ( '' !== $password ? ' with a password' : '' ) );
			$this->assertSame( wp_login_url(), diluxone_users_login_url(), 'The sign-in address falls back to wp-login.php' );
			$this->assertSame( DILUXONE_USERS_2FA_ACTION, $this->queryArg( $this->challenge(), 'action' ) );
		}
	}

	public function test_a_wrong_code_on_wp_login_comes_back_to_wp_login(): void {
		$key = $this->queryArg( $this->challenge(), 'diluxone_users_key' );

		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $this->user,
				'diluxone_users_2fa_key'    => $key,
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => '000000',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_2fa_handle' );

		$this->assertStringStartsWith( wp_login_url(), $url );
		$this->assertSame( DILUXONE_USERS_2FA_ACTION, $this->queryArg( $url, 'action' ) );
		$this->assertSame( 'code', $this->redirectState( $url ) );
	}

	public function test_an_attempt_that_ran_out_restarts_at_wp_login_and_says_so(): void {
		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user' => (string) $this->user,
				'diluxone_users_2fa_key'  => 'not-an-attempt',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_2fa_handle' );

		$this->assertStringStartsWith( wp_login_url(), $url );
		$this->assertSame( 'retry', $this->redirectState( $url ) );

		$_GET   = array( 'diluxone-users' => 'retry' );
		$errors = diluxone_users_wp_login_states( new \WP_Error() );

		$this->assertContains( 'diluxone_users_retry', $errors->get_error_codes() );
	}

	public function test_with_nowhere_to_answer_it_the_step_is_neither_asked_nor_offered(): void {
		add_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );

		$this->assertSame( '', diluxone_users_2fa_surface() );
		$this->assertFalse( diluxone_users_2fa_required( $this->user, 'password' ), 'Asked, it would lock them out' );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );

		$this->assertFalse( diluxone_users_2fa_required( $this->user, 'password' ), 'Not even of whoever turned it on' );
		$this->assertFalse( diluxone_users_2fa_offered( $this->user ), 'The account does not offer it' );

		// A page brings it back.
		$this->page();

		$this->assertSame( 'page', diluxone_users_2fa_surface() );
		$this->assertTrue( diluxone_users_2fa_required( $this->user, 'password' ) );
	}

	public function test_required_cannot_be_saved_with_nowhere_to_answer_it(): void {
		add_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );

		$this->in_network_admin();
		wp_set_current_user( 1 );

		$_POST = array(
			'diluxone_users_2fa_mode'    => 'required',
			'diluxone_users_2fa_methods' => array( 'email' ),
		);

		ob_start();
		$saved = diluxone_users_2fa_save();
		$said  = (string) ob_get_clean();

		$this->assertFalse( $saved );
		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ), 'Nothing was written' );
		$this->assertStringContainsString( esc_html( diluxone_users_2fa_needs_a_surface() ), $said );

		// With somewhere to answer it, the same form saves.
		remove_all_filters( 'diluxone_users_2fa_on_wp_login' );

		ob_start();
		$this->assertTrue( diluxone_users_2fa_save() );
		ob_end_clean();
		$this->assertSame( 'required', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
	}

	public function test_the_account_refuses_to_turn_it_on_with_nowhere_to_answer_it(): void {
		add_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );

		wp_set_current_user( $this->user );

		$this->postAs(
			$this->user,
			array(
				'diluxone_users_security' => 'on',
				'_wpnonce'                => wp_create_nonce( 'diluxone_users_security' ),
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_security_submit' );

		$this->assertSame( 'notoffered', $this->redirectState( $url ) );
		$this->assertSame( '', (string) get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}
}
