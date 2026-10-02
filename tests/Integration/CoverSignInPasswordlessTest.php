<?php
/**
 * WordPress's own doors while the site signs people in its own way: the
 * reset link handed to the site's page only when it should be, wp-login.php
 * left open when it is the only door there is, the escape hatch carried
 * through a POST, the registration door, the notice next to WordPress's own
 * checkbox, and the dashboard profile for each person it does and does not
 * reach.
 */

namespace Tests\Integration;

class CoverSignInPasswordlessTest extends IntegrationTestCase {

	/** @var mixed */
	private $script = null;

	protected function setUp(): void {
		parent::setUp();

		$this->script = $_SERVER['SCRIPT_NAME'] ?? null;
	}

	protected function tearDown(): void {
		remove_filter( 'diluxone_users_login_url', array( $this, 'own_page' ) );

		if ( null === $this->script ) {
			unset( $_SERVER['SCRIPT_NAME'] );
		} else {
			$_SERVER['SCRIPT_NAME'] = $this->script;
		}

		parent::tearDown();
	}

	public function own_page(): string {
		return home_url( '/sign-in/' );
	}

	/** A published sign-in page, chosen in the settings. */
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

	/** @param array<string, string> $get */
	private function request( string $method, array $get = array(), array $post = array() ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = $get;
		$_POST                     = $post;
	}

	/* ── The reset link ──────────────────────────────────────────────── */

	public function test_the_reset_link_goes_to_the_site_s_page_with_the_key_when_the_site_asks_for_it(): void {
		$page = $this->login_page();
		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );
		$this->request( 'GET', array( 'action' => 'rp', 'key' => 'abc123', 'login' => 'some.one' ) );

		$url = $this->expectRedirect( 'diluxone_users_reset_to_site' );

		$this->assertStringStartsWith( (string) get_permalink( $page ), $url );
		$this->assertSame( 'abc123', $this->queryArg( $url, 'diluxone_users_key' ) );
		$this->assertSame( 'some.one', $this->queryArg( $url, 'diluxone_users_login' ) );
	}

	public function test_the_reset_link_stays_on_wp_login_when_the_site_leaves_it_there_or_the_hatch_is_open(): void {
		$this->login_page();
		$this->request( 'GET', array( 'action' => 'rp', 'key' => 'abc123', 'login' => 'some.one' ) );

		$this->stays( 'diluxone_users_reset_to_site', 'the site leaves it on wp-login.php' );

		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );
		$this->request( 'GET', array( 'action' => 'resetpass', 'key' => 'abc123', 'login' => 'some.one', 'diluxone-users-admin' => '1' ) );

		$this->stays( 'diluxone_users_reset_to_site', 'the emergency door is open' );
	}

	public function test_the_reset_link_stays_on_wp_login_without_a_page_or_without_a_key(): void {
		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );
		$this->request( 'GET', array( 'action' => 'rp', 'key' => 'abc123', 'login' => 'some.one' ) );

		$this->stays( 'diluxone_users_reset_to_site', 'no page to hand it to' );

		$this->login_page();
		$this->request( 'GET', array( 'action' => 'rp', 'login' => 'some.one' ) );

		$this->stays( 'diluxone_users_reset_to_site', 'a page, and nothing to hand it' );
	}

	/* ── wp-login.php ────────────────────────────────────────────────── */

	public function test_with_no_other_door_wp_login_stays_open(): void {
		// Only the link, and the sign-in screen is wp-login.php itself.
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		$this->request( 'GET' );

		diluxone_users_block_wp_login();

		$this->assertSame( wp_login_url(), diluxone_users_login_url() );
	}

	public function test_the_escape_hatch_survives_the_form_being_posted(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		add_filter( 'diluxone_users_login_url', array( $this, 'own_page' ) );
		$this->request( 'POST', array(), array( 'diluxone-users-admin' => '1', 'log' => 'admin', 'pwd' => 'x' ) );

		diluxone_users_block_wp_login();

		// Without the hatch, the same POST is stopped.
		$this->request( 'POST', array(), array( 'log' => 'admin', 'pwd' => 'x' ) );
		$this->expectException( \WPAjaxDieContinueException::class );
		diluxone_users_block_wp_login();
	}

	public function test_asking_to_register_goes_to_the_registration_page_when_there_is_one(): void {
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'mine' );
		add_filter( 'diluxone_users_login_url', array( $this, 'own_page' ) );

		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Register',
				'post_content' => '[diluxone_users_register]',
			)
		);
		diluxone_users_update_option( 'diluxone_users_register_page', $page );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );
		$this->request( 'GET', array( 'action' => 'register' ) );

		$this->assertSame( diluxone_users_register_url(), $this->expectRedirect( 'diluxone_users_block_wp_login' ) );

		// With the form closed, the same request goes to the sign-in page.
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		$this->assertSame( home_url( '/sign-in/' ), $this->expectRedirect( 'diluxone_users_block_wp_login' ) );
	}

	public function test_the_hatch_is_carried_into_wordpress_s_form_only_when_it_was_opened(): void {
		ob_start();
		diluxone_users_login_hatch_field();
		$this->assertSame( '', ob_get_clean() );

		$_GET = array( 'diluxone-users-admin' => '1' );

		ob_start();
		diluxone_users_login_hatch_field();
		$this->assertSame( '<input type="hidden" name="diluxone-users-admin" value="1">', ob_get_clean() );
	}

	/* ── WordPress's registration checkbox ───────────────────────────── */

	public function test_the_locked_checkbox_is_explained_to_whoever_can_change_it(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );

		ob_start();
		diluxone_users_wp_registration_notice();
		$this->assertSame( '', ob_get_clean(), 'nobody signed in' );

		wp_set_current_user( 1 );

		ob_start();
		diluxone_users_wp_registration_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="notice notice-info"><p>“Anyone can register” is off and locked', $html );
		$this->assertStringContainsString( esc_url( diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'register' ) ) ), $html );
		$this->assertSame( 0, diluxone_users_block_registration( 1 ) );

		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );

		ob_start();
		diluxone_users_wp_registration_notice();
		$this->assertSame( '', ob_get_clean(), 'nothing locked' );
		$this->assertSame( 1, diluxone_users_block_registration( 1 ) );
	}

	public function test_the_notice_is_hooked_on_general_settings_only(): void {
		$this->assertSame( 10, has_action( 'load-options-general.php', 'diluxone_users_wp_registration_notice_hook' ), 'on General Settings' );
		$this->assertFalse( has_action( 'admin_init', 'diluxone_users_wp_registration_notice_hook' ), 'and not on every screen' );

		remove_action( 'admin_notices', 'diluxone_users_wp_registration_notice' );

		diluxone_users_wp_registration_notice_hook();

		$this->assertSame( 10, has_action( 'admin_notices', 'diluxone_users_wp_registration_notice' ) );
		remove_action( 'admin_notices', 'diluxone_users_wp_registration_notice' );
	}

	/* ── The dashboard profile ───────────────────────────────────────── */

	public function test_the_profile_is_closed_with_a_403_to_somebody_it_reaches(): void {
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'block' );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';
		wp_set_current_user( $this->make_user() );

		try {
			diluxone_users_wp_profile_guard();
			$this->fail( 'The profile should be closed.' );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->assertSame( 'Your details are edited from your account on the site, not from here.', $e->getMessage() );
		}
	}

	public function test_the_profile_sends_somebody_it_reaches_to_their_account(): void {
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'redirect' );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';
		wp_set_current_user( $this->make_user() );

		$this->assertSame( diluxone_users_account_url( 'details' ), $this->expectRedirect( 'diluxone_users_wp_profile_guard' ) );
	}

	public function test_the_profile_leaves_alone_other_screens_other_roles_and_whoever_edits_users(): void {
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'block' );
		$user = $this->make_user();
		wp_set_current_user( $user );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
		$this->stays( 'diluxone_users_wp_profile_guard', 'another screen of the dashboard' );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';
		diluxone_users_update_option( 'diluxone_users_wp_profile_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_wp_profile_roles', array( 'editor' ) );
		$this->stays( 'diluxone_users_wp_profile_guard', 'a role the rule does not reach' );

		diluxone_users_update_option( 'diluxone_users_wp_profile_scope', 'all' );
		wp_set_current_user( 1 );
		$this->stays( 'diluxone_users_wp_profile_guard', 'whoever can edit users, whatever the rule' );
	}

	/** A profile rule nobody chose — a value written by hand — leaves the profile screen open. */
	public function test_a_profile_rule_nobody_chose_closes_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'bogus' );
		wp_set_current_user( $this->make_user() );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';

		$this->stays( 'diluxone_users_wp_profile_guard', 'only redirect and block close it' );
	}

	/** "Allow" leaves the profile open; "redirect" with no account page goes to the front page. */
	public function test_allow_leaves_it_open_and_redirect_without_a_page_goes_home(): void {
		wp_set_current_user( $this->make_user() );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/profile.php';

		diluxone_users_update_option( 'diluxone_users_wp_profile', 'allow' );
		$this->stays( 'diluxone_users_wp_profile_guard' );

		diluxone_users_update_option( 'diluxone_users_wp_profile', 'redirect' );
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		$this->assertSame( home_url( '/' ), $this->expectRedirect( 'diluxone_users_wp_profile_guard' ) );
	}
}
