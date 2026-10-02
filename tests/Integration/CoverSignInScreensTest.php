<?php
/**
 * The screens of signing in, drawn in each of their states: the form, the
 * "check your e-mail" screen, the second step on the sign-in page and on
 * wp-login.php, and choosing a new password.
 *
 * Each is drawn the way a visitor gets it — through the shortcode, or through
 * wp-login.php's action — and read for what it says and what it leaves out.
 * wp-login.php's own header and footer are stood in for by
 * Support/CoverSignInWpLogin.php: WordPress declares them inside wp-login.php.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSignInStop;
use Tests\Integration\Support\MockProvider;

class CoverSignInScreensTest extends IntegrationTestCase {

	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		MockProvider::remove();

		// What drawing the ways in tabs asked the preview for, in the dashboard.
		diluxone_users_preview_scripts( null, true );

		parent::tearDown();
	}

	/** A published sign-in page, chosen in the settings: the second step is then drawn there. */
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

	/**
	 * Starts the second step for the test's person and puts its address in the request.
	 *
	 * @return array{user: string, key: string, method: string}
	 */
	private function halfway( string $state = '' ): array {
		$url = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, 'password', false, home_url( '/after/' ) ) );

		$_GET = array(
			'diluxone_users_2fa'    => $this->queryArg( $url, 'diluxone_users_2fa' ),
			'diluxone_users_key'    => $this->queryArg( $url, 'diluxone_users_key' ),
			'diluxone_users_method' => $this->queryArg( $url, 'diluxone_users_method' ),
		);

		if ( '' !== $state ) {
			$_GET['diluxone-users'] = $state;
		}

		return array(
			'user'   => $_GET['diluxone_users_2fa'],
			'key'    => $_GET['diluxone_users_key'],
			'method' => $_GET['diluxone_users_method'],
		);
	}

	/** The person also has an authenticator app set up, and the site offers it. */
	private function with_an_app(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'totp' ) );
		update_user_meta( $this->user, 'diluxone_users_totp', diluxone_users_totp_secret_new() );
	}

	/** The sign-in shortcode as a visitor gets it, in this state. */
	private function login( string $state = '', $atts = array() ): string {
		if ( '' !== $state ) {
			$_GET['diluxone-users'] = $state;
		}

		return diluxone_users_shortcode_login( $atts );
	}

	/** wp-login.php's second step, drawn until its footer. */
	private function wp_login_screen(): string {
		require_once __DIR__ . '/Support/CoverSignInWpLogin.php';

		$footer = new \ReflectionFunction( 'login_footer' );

		if ( false === strpos( (string) $footer->getFileName(), 'CoverSignInWpLogin.php' ) ) {
			$this->markTestSkipped( 'login_footer() is not the stand-in (it ends in an exit); the same screen on the sign-in page is test_the_second_step_on_the_page_offers_the_other_methods_and_trust.' );
		}

		ob_start();

		try {
			diluxone_users_2fa_wp_login();
			$this->fail( 'The screen has to end at its footer.' );
		} catch ( CoverSignInStop $e ) {
			unset( $e );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/* ── The sign-in form ────────────────────────────────────────────── */

	public function test_somebody_signed_in_sees_no_form(): void {
		wp_set_current_user( $this->user );

		$this->assertSame( '', $this->login() );
	}

	public function test_the_check_your_email_screen_shows_what_was_typed_and_the_way_back(): void {
		$_COOKIE['diluxone_users_sent'] = 'typo@exmaple.test';
		diluxone_users_update_option( 'diluxone_users_sent_icon', 'circle' );
		diluxone_users_update_option( 'diluxone_users_sent_title', 'Look in your <inbox>' );
		diluxone_users_update_option( 'diluxone_users_sent_note', 'Spam, maybe?' );

		$html = $this->login( 'sent' );

		$this->assertStringContainsString( 'diluxone-users-login__icon diluxone-users-login__icon--circle', $html );
		$this->assertStringContainsString( '<svg class="diluxone-users-icon diluxone-users-icon--mail"', $html );
		$this->assertStringContainsString( 'Look in your &lt;inbox&gt;', $html );
		$this->assertStringContainsString( '<strong>typo@exmaple.test</strong>', $html );
		$this->assertStringContainsString( 'It expires in ' . diluxone_users_login_expiry() . ' minutes and works once.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( diluxone_users_login_url() ) . '"', $html );
		$this->assertStringContainsString( 'Use a different address', $html );
		$this->assertStringContainsString( 'Spam, maybe?', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_the_check_your_email_screen_with_the_plain_icon_and_the_plugin_s_words(): void {
		$html = $this->login( 'sent' );

		$this->assertStringContainsString( '<p class="diluxone-users-login__icon ">', $html );
		$this->assertStringContainsString( 'Check your email', $html );
		$this->assertStringContainsString( 'Did not arrive? Check your spam or promotions folder.', $html );
	}

	public function test_the_heading_is_drawn_when_asked_for_or_when_the_site_wrote_one(): void {
		$this->assertStringNotContainsString( 'diluxone-users-login__title', $this->login() );
		$this->assertStringContainsString( "<h2 class=\"diluxone-users-login__title\">\n\t\t\t\tSign in\t\t\t</h2>", $this->login( '', array( 'title' => 'YES' ) ) );

		diluxone_users_update_option( 'diluxone_users_login_title', 'Welcome back' );
		diluxone_users_update_option( 'diluxone_users_login_intro', 'Members only & friends' );
		diluxone_users_update_option( 'diluxone_users_login_legal', 'See the <a href="https://example.test/t">terms</a><script>x</script>' );

		$html = $this->login();

		$this->assertStringContainsString( 'Welcome back', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-login__intro">Members only &amp; friends</p>', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-login__legal">See the <a href="https://example.test/t">terms</a>x</p>', $html );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function states(): array {
		return array(
			'changed' => array( 'changed', 'login_changed' ),
			'expired' => array( 'expired', 'login_expired' ),
			'email'   => array( 'email', 'login_email' ),
			'social'  => array( 'social', 'login_social' ),
			'error'   => array( 'error', 'login_error' ),
			'confirm' => array( 'confirm', 'login_confirm' ),
			'closed'  => array( 'closed', 'login_closed' ),
		);
	}

	/** @dataProvider states */
	public function test_each_state_of_the_form_says_its_own_message( string $state, string $message ): void {
		$html = $this->login( $state );

		$this->assertSame( 1, substr_count( $html, 'data-diluxone-users-message=' ) );
		$this->assertStringContainsString( 'data-diluxone-users-message="' . $message . '"', $html );
	}

	public function test_a_state_nobody_knows_says_nothing(): void {
		$this->assertStringNotContainsString( 'data-diluxone-users-message=', $this->login( 'whatever' ) );
	}

	/* ── The second step on the sign-in page ─────────────────────────── */

	public function test_the_second_step_replaces_the_form_and_carries_the_attempt(): void {
		$this->login_page();
		$attempt = $this->halfway();

		$html = $this->login();

		$this->assertStringContainsString( 'diluxone-users-login--2fa', $html );
		$this->assertStringContainsString( 'One more step', $html );
		$this->assertStringContainsString( 'name="diluxone_users_2fa_user" value="' . $attempt['user'] . '"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_2fa_key" value="' . esc_attr( $attempt['key'] ) . '"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_2fa_method" value="email"', $html );
		$this->assertStringContainsString( 'We send a six-digit code', $html );
		// The e-mail can be sent again; there is nothing else to switch to, and no trust box.
		$this->assertStringContainsString( 'name="diluxone_users_2fa_resend"', $html );
		$this->assertStringNotContainsString( 'Or use:', $html );
		$this->assertStringNotContainsString( 'diluxone_users_2fa_trust', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_email"', $html );
	}

	public function test_the_second_step_on_the_page_offers_the_other_methods_and_trust(): void {
		$this->login_page();
		$this->with_an_app();
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 14 );
		$attempt = $this->halfway();

		$html = $this->login();

		// The app comes first, and has no code to send.
		$this->assertSame( 'totp', $attempt['method'] );
		$this->assertStringNotContainsString( 'name="diluxone_users_2fa_resend"', $html );
		$this->assertStringContainsString( 'Or use:', $html );
		$this->assertStringContainsString( esc_url( diluxone_users_2fa_url( (int) $attempt['user'], $attempt['key'], 'email' ) ), $html );
		$this->assertStringContainsString( 'A code by email', $html );
		$this->assertStringContainsString( 'Do not ask again on this browser for 14 days', $html );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function second_step_states(): array {
		return array(
			'locked' => array( 'locked', 'two_step_locked' ),
			'code'   => array( 'code', 'two_step_wrong' ),
			'sent'   => array( 'sent', 'two_step_sent' ),
		);
	}

	/** @dataProvider second_step_states */
	public function test_each_state_of_the_second_step_on_the_page_says_its_message( string $state, string $message ): void {
		$this->login_page();
		$this->halfway( $state );

		$html = $this->login();

		$this->assertSame( 1, substr_count( $html, 'data-diluxone-users-message=' ) );
		$this->assertStringContainsString( 'data-diluxone-users-message="' . $message . '"', $html );
	}

	public function test_an_attempt_that_is_not_there_draws_the_form_instead(): void {
		$this->login_page();
		$_GET = array(
			'diluxone_users_2fa' => (string) $this->user,
			'diluxone_users_key' => 'made-up',
		);

		$html = $this->login();

		$this->assertStringNotContainsString( 'diluxone-users-login--2fa', $html );
		$this->assertStringNotContainsString( 'diluxone_users_2fa_key', $html );
	}

	/* ── The second step on wp-login.php ─────────────────────────────── */

	public function test_with_no_attempt_wp_login_sends_the_person_to_start_again(): void {
		$url = $this->expectRedirect( 'diluxone_users_2fa_wp_login' );

		$this->assertSame( add_query_arg( 'diluxone-users', 'retry', wp_login_url() ), $url );
	}

	/**
	 * Draws on wp-login.php, which defines DONOTCACHEPAGE for good: in a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_login_draws_the_second_step_with_its_own_markup(): void {
		$attempt = $this->halfway();

		$html = $this->wp_login_screen();

		$this->assertStringContainsString( '<title>One more step</title>', $html );
		$this->assertStringContainsString( 'id="loginform"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( diluxone_users_2fa_url( (int) $attempt['user'], $attempt['key'], 'email' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_2fa_key" value="' . esc_attr( $attempt['key'] ) . '"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_2fa_resend"', $html );
		$this->assertStringNotContainsString( 'Or use:', $html );
		$this->assertStringNotContainsString( 'forgetmenot', $html );
		$this->assertStringNotContainsString( 'data-code=', $html );
		$this->assertStringContainsString( 'data-focus="diluxone-users-2fa-code"', $html );
		// Kept out of page caches: the constant they read is defined.
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/**
	 * Draws on wp-login.php, which defines DONOTCACHEPAGE for good: in a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_login_offers_the_other_methods_and_trust(): void {
		$this->with_an_app();
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 30 );
		$attempt = $this->halfway();

		$html = $this->wp_login_screen();

		$this->assertStringContainsString( 'Or use:', $html );
		$this->assertStringContainsString( esc_url( diluxone_users_2fa_url( (int) $attempt['user'], $attempt['key'], 'email' ) ), $html );
		$this->assertStringContainsString( 'class="forgetmenot"', $html );
		$this->assertStringContainsString( 'for 30 days', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_2fa_resend"', $html );
	}

	/**
	 * Draws on wp-login.php, which defines DONOTCACHEPAGE for good: in a process of its own.
	 *
	 * @dataProvider second_step_states
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_state_of_the_second_step_on_wp_login_is_a_message_of_its_kind( string $state, string $message ): void {
		$this->halfway( $state );

		$html = $this->wp_login_screen();

		$this->assertStringContainsString( 'data-code="diluxone_users_' . $state . '" data-kind="' . ( 'sent' === $state ? 'message' : '' ) . '"', $html );
		$this->assertStringContainsString( esc_html( diluxone_users_login_message( $message ) ), $html );
	}

	/**
	 * Draws on wp-login.php, which defines DONOTCACHEPAGE for good: in a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_state_whose_message_the_site_emptied_says_nothing_on_wp_login(): void {
		$silence = static fn( $text, string $key = '' ) => 'two_step_wrong' === $key ? '' : $text;
		add_filter( 'diluxone_users_login_message', $silence, 10, 2 );
		$this->halfway( 'code' );

		try {
			$html = $this->wp_login_screen();
		} finally {
			remove_filter( 'diluxone_users_login_message', $silence, 10 );
		}

		$this->assertStringNotContainsString( 'data-code=', $html );
	}

	public function test_wp_login_s_form_says_why_somebody_is_back(): void {
		$_GET = array( 'diluxone-users' => 'retry' );
		$retry = diluxone_users_wp_login_states( new \WP_Error() );
		$this->assertSame( array( 'diluxone_users_retry' ), $retry->get_error_codes() );

		$_GET    = array( 'diluxone-users' => 'expired' );
		$expired = diluxone_users_wp_login_states( new \WP_Error() );
		$this->assertSame( array( 'diluxone_users_expired' ), $expired->get_error_codes() );
		$this->assertSame( esc_html( diluxone_users_login_message( 'login_expired' ) ), $expired->get_error_message() );

		$_GET  = array( 'diluxone-users' => 'sent' );
		$other = diluxone_users_wp_login_states( new \WP_Error() );
		$this->assertFalse( $other->has_errors() );

		// Anything that is not WordPress's errors is passed on untouched.
		$this->assertSame( 'not errors', diluxone_users_wp_login_states( 'not errors' ) );
	}

	public function test_an_expired_message_the_site_emptied_is_not_added(): void {
		$silence = static fn( $text, string $key = '' ) => 'login_expired' === $key ? '' : $text;
		add_filter( 'diluxone_users_login_message', $silence, 10, 2 );
		$_GET = array( 'diluxone-users' => 'expired' );

		try {
			$errors = diluxone_users_wp_login_states( new \WP_Error() );
		} finally {
			remove_filter( 'diluxone_users_login_message', $silence, 10 );
		}

		$this->assertFalse( $errors->has_errors() );
	}

	/* ── Choosing a new password ─────────────────────────────────────── */

	private function resetting(): void {
		$user = get_userdata( $this->user );
		$key  = get_password_reset_key( $user );

		$_COOKIE[ diluxone_users_reset_cookie() ] = $user->user_login . ':' . $key;
	}

	public function test_a_reset_in_progress_comes_before_anything_else(): void {
		$this->login_page();
		$this->halfway();
		$this->resetting();

		$html = $this->login();

		$this->assertStringContainsString( 'diluxone-users-login--reset', $html );
		$this->assertStringContainsString( 'For <strong>' . esc_html( get_userdata( $this->user )->user_email ) . '</strong>', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_reset"', $html );
		preg_match( '/name="diluxone_users_reset_nonce" value="([a-f0-9]+)"/', $html, $m );
		$this->assertSame( 1, wp_verify_nonce( $m[1] ?? '', 'diluxone_users_reset' ) );
		$this->assertStringNotContainsString( 'data-diluxone-users-message', $html );
		$this->assertStringNotContainsString( 'diluxone-users-login--2fa', $html );
		// With the link as a way in, the person is told they need no password.
		$this->assertStringContainsString( 'a link by email gets you in without a password', $html );
	}

	public function test_the_reset_screen_says_when_the_two_did_not_match_and_says_nothing_of_links_on_a_password_site(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
		$this->resetting();

		$html = $this->login( 'nomatch' );

		$this->assertStringContainsString( 'data-diluxone-users-message="reset_mismatch"', $html );
		$this->assertStringNotContainsString( 'a link by email gets you in', $html );
	}

	public function test_a_reset_cookie_with_a_key_that_does_not_check_out_draws_the_form(): void {
		$_COOKIE[ diluxone_users_reset_cookie() ] = get_userdata( $this->user )->user_login . ':made-up';

		$html = $this->login();

		$this->assertStringNotContainsString( 'diluxone-users-login--reset', $html );
	}

	/* ── The networks on the form ────────────────────────────────────── */

	public function test_the_networks_are_on_the_form_when_the_site_wants_them_there(): void {
		MockProvider::install();
		diluxone_users_update_option( 'diluxone_users_sso_login', 0 );

		$this->assertStringNotContainsString( esc_url( diluxone_users_sso_login_url( MockProvider::ID ) ), $this->login() );

		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$this->assertStringContainsString( esc_url( diluxone_users_sso_login_url( MockProvider::ID ) ), $this->login() );
	}
}
