<?php
/**
 * H-05: taking over WordPress's screens must not take away the password.
 *
 * With "my screens" chosen and the password still a way in, the site's own
 * page draws WordPress's password form — which posts to wp-login.php. That
 * POST used to meet a 403, and nobody could sign in with a password.
 */

namespace Tests\Integration;

class PasswordlessGateTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_wp_screens', 'mine' );
		add_filter( 'diluxone_users_login_url', array( $this, 'own_page' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'diluxone_users_login_url', array( $this, 'own_page' ) );
		parent::tearDown();
	}

	public function own_page(): string {
		return home_url( '/sign-in/' );
	}

	/** @param array<string, string> $get */
	private function request( string $method, array $get = array(), array $post = array() ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = $get;
		$_POST                     = $post;
	}

	public function test_the_password_form_can_still_post_to_wp_login(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		$this->request( 'POST', array(), array( 'log' => 'someone', 'pwd' => 'secret' ) );

		diluxone_users_block_wp_login();

		$this->assertTrue( true, 'The POST went through: no 403, no redirect' );
	}

	public function test_with_only_the_link_the_post_meets_the_wall(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		$this->request( 'POST', array(), array( 'log' => 'someone', 'pwd' => 'secret' ) );

		$this->expectException( \WPAjaxDieContinueException::class );

		diluxone_users_block_wp_login();
	}

	/**
	 * Asking for a reset is a form of its own, and only one answer closes it.
	 *
	 * It used to be closed whenever the screens were taken over, which is how
	 * a site that sends people to its own page to type a new password ended up
	 * with no form anywhere that sends them the e-mail to get there. Where the
	 * new password is typed is now its own question, with three answers, and
	 * only the one that says nobody resets anything shuts the ask.
	 */
	public function test_asking_for_a_reset_goes_through_when_somebody_resets_something(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );
		$this->request( 'POST', array( 'action' => 'lostpassword' ), array( 'user_login' => 'someone' ) );

		diluxone_users_block_wp_login();

		$this->assertTrue( true, 'The form that sends the reset e-mail is still reachable' );
	}

	public function test_asking_for_a_reset_meets_the_wall_when_there_is_no_password_to_reset(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		diluxone_users_update_option( 'diluxone_users_lost_password', 'link' );
		$this->request( 'POST', array( 'action' => 'lostpassword' ), array( 'user_login' => 'someone' ) );

		$this->expectException( \WPAjaxDieContinueException::class );

		diluxone_users_block_wp_login();
	}

	public function test_opening_wp_login_goes_to_the_site_page(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		$this->request( 'GET' );

		$this->assertSame( home_url( '/sign-in/' ), $this->expectRedirect( 'diluxone_users_block_wp_login' ) );
	}

	public function test_the_escape_hatch_still_opens_the_native_form(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		$this->request( 'GET', array( 'diluxone-users-admin' => '1' ) );

		diluxone_users_block_wp_login();

		$this->assertTrue( true );
	}

	public function test_signing_out_is_left_alone(): void {
		$this->request( 'GET', array( 'action' => 'logout' ) );

		diluxone_users_block_wp_login();

		$this->assertTrue( true );
	}

	public function test_with_wordpress_screens_nothing_is_touched(): void {
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		$this->request( 'GET' );

		diluxone_users_block_wp_login();

		$this->assertTrue( true );
	}

	/**
	 * "Keep it as a second sign-in screen" on a site that closed the password.
	 *
	 * The native form takes a username and a password and nothing else, so
	 * leaving it open was leaving a password working on a site whose every
	 * screen says a password opens nothing. The answer stored is kept for the
	 * day the password comes back; today the second door is shut.
	 */
	public function test_with_only_the_link_the_second_door_is_shut_too(): void {
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		$this->request( 'POST', array(), array( 'log' => 'someone', 'pwd' => 'secret' ) );

		$this->expectException( \WPAjaxDieContinueException::class );

		diluxone_users_block_wp_login();
	}
}
