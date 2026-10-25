<?php
/**
 * The social round trip in a popup (sso-popup.php).
 *
 * The whole trip is played against the mock provider, request by request: the
 * start says it is a popup and the state keeps it; the return, whatever it
 * ends in, draws the bridge page instead of redirecting and keeps where it
 * would have gone; the resume address, once, sends the page that opened the
 * popup there.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class SsoPopupTest extends IntegrationTestCase {

	/** Identities are the test's own: links outlive nothing, but ids are compared across accounts. */
	private string $sub = '';

	protected function setUp(): void {
		parent::setUp();
		MockProvider::install();

		$this->sub = 'sub-' . wp_generate_password( 8, false );

		MockProvider::$profile = array(
			'sub'            => $this->sub,
			'email'          => 'popup-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
			'email_verified' => true,
			'given_name'     => 'Grace',
		);

		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', 'diluxone_users_sso_popup_finish', 0 );
		remove_filter( 'script_loader_src', array( $this, 'stop_at_the_script' ) );
		diluxone_users_sso_popup_context( null, true );
		MockProvider::remove();

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		parent::tearDown();
	}

	/**
	 * One request: the query and the cookies the browser sends.
	 *
	 * @param array<string, string> $get
	 * @param array<string, string> $cookie
	 */
	private function request( array $get, array $cookie = array() ): void {
		$_GET    = $get;
		$_COOKIE = $cookie;
		diluxone_users_sso_query_snapshot();
	}

	/**
	 * Starts a trip from a button, in a popup unless told otherwise.
	 *
	 * @param string                $popup `1`, `door`, or '' for the whole window.
	 * @param array<string, string> $extra
	 * @return array{state: string, cookie: string, url: string}
	 */
	private function start( string $popup = '1', array $extra = array() ): array {
		$get = $extra + array(
			'diluxone_users_sso' => MockProvider::ID,
			'diluxone_users_go'  => '1',
		);

		if ( '' !== $popup ) {
			$get['diluxone_users_popup'] = $popup;
		}

		$this->request( $get );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		return array(
			'state'  => $this->queryArg( $url, 'state' ),
			'cookie' => self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '',
			'url'    => $url,
		);
	}

	/**
	 * The provider's return, to the browser that left.
	 *
	 * @param array{state: string, cookie: string} $trip
	 * @param array<string, string>                $extra
	 */
	private function back( array $trip, array $extra = array() ): void {
		$this->request(
			$extra + array(
				'diluxone_users_sso' => MockProvider::ID,
				'code'               => 'the-code',
				'state'              => $trip['state'],
			),
			array( diluxone_users_sso_cookie() => $trip['cookie'] )
		);
	}

	/** The bridge page ends in an exit after its script: the test stops it there. */
	public function stop_at_the_script( string $src, string $handle ): string {
		if ( 'diluxone-users-sso-bridge' === $handle ) {
			throw new \RuntimeException( 'sso-bridge-page' );
		}

		return $src;
	}

	/** Runs the handler to the bridge page and returns what it drew; fails on a redirect. */
	private function bridge(): string {
		add_filter( 'script_loader_src', array( $this, 'stop_at_the_script' ), 10, 2 );
		ob_start();

		try {
			diluxone_users_sso_handle();
			$this->fail( 'The bridge page was expected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sso-bridge-page', $e->getMessage(), 'a popup return draws the bridge instead of sending a Location' );
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'script_loader_src', array( $this, 'stop_at_the_script' ) );
		}

		return $html;
	}

	/** One attribute of the bridge page's body. */
	private function data( string $html, string $name ): string {
		return preg_match( '/<body[^>]*\sdata-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : '';
	}

	/** The page that opened the popup, at the resume address, with the cookie the bridge left. */
	private function resume(): string {
		$name = diluxone_users_sso_resume_cookie();

		$this->request(
			array(
				'diluxone_users_sso'    => MockProvider::ID,
				'diluxone_users_resume' => '1',
			),
			isset( self::$cookies[ $name ] ) ? array( $name => (string) self::$cookies[ $name ]['value'] ) : array()
		);

		return $this->expectRedirect( 'diluxone_users_sso_handle' );
	}

	/* ── C1: the trip starts in the popup ────────────────────────────── */

	public function test_a_popup_trip_keeps_popup_in_its_state(): void {
		$trip   = $this->start( '1' );
		$stored = get_transient( 'diluxone_users_sso_' . $trip['state'] );

		$this->assertStringStartsWith( MockProvider::BASE . 'authorize', $trip['url'], 'out to the provider, as in the whole window' );
		$this->assertIsArray( $stored );
		$this->assertSame( 1, $stored['popup'] );
		$this->assertSame( 0, $stored['door'] );

		$door = get_transient( 'diluxone_users_sso_' . $this->start( 'door' )['state'] );
		$this->assertSame( 1, $door['door'], 'a trip begun on a door says so' );
	}

	public function test_a_trip_in_the_whole_window_keeps_no_popup(): void {
		$stored = get_transient( 'diluxone_users_sso_' . $this->start( '' )['state'] );

		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'popup', $stored );
	}

	public function test_a_live_button_carries_its_popup_and_its_resume_address(): void {
		$html = diluxone_users_sso_buttons();

		$this->assertStringContainsString( 'data-diluxone-users-sso-popup=', $html );
		$this->assertStringContainsString( 'diluxone_users_popup=1', $html );
		$this->assertStringContainsString( 'data-diluxone-users-sso-resume=', $html );
		$this->assertStringContainsString( 'diluxone_users_resume=1', $html );
		$this->assertTrue( wp_script_is( 'diluxone-users-sso-popup', 'enqueued' ), 'the popup script comes with the buttons' );

		// Kept through the plugin's own allowed markup, as the form prints it.
		$this->assertStringContainsString( 'data-diluxone-users-sso-popup=', wp_kses( $html, diluxone_users_allowed_html() ) );

		// The preview on the settings screen opens nothing.
		$this->assertStringNotContainsString( 'data-diluxone-users-sso-popup', diluxone_users_sso_buttons( diluxone_users_sso_providers(), false ) );
	}

	/* ── C2: the popup closes, the page that opened it is signed in ──── */

	public function test_a_popup_sign_in_draws_the_bridge_and_the_opener_may_reload_in_place(): void {
		$this->back( $this->start( '1' ) );

		$html = $this->bridge();

		$this->assertGreaterThan( 0, get_current_user_id(), 'the session is open, in the popup' );
		$this->assertSame( MockProvider::$profile['email'], wp_get_current_user()->user_email );
		$this->assertSame( 'in', $this->data( $html, 'kind' ), 'nowhere of its own to go, from a page that is not a door' );
		$this->assertSame( diluxone_users_sso_resume_url( MockProvider::ID ), $this->data( $html, 'resume' ) );
		$this->assertFalse( has_filter( 'wp_redirect', 'diluxone_users_sso_popup_finish' ), 'the catch is spent with the request' );

		// The opener asks once and is sent where the whole window would have gone.
		$this->assertSame( home_url( '/' ), $this->resume() );
		$this->assertSame( diluxone_users_login_url(), $this->resume(), 'a second ask finds nothing waiting' );
	}

	public function test_a_popup_sign_in_begun_on_a_door_sends_the_opener_on_to_the_destination(): void {
		$this->back( $this->start( 'door' ) );

		$html = $this->bridge();

		$this->assertSame( 'next', $this->data( $html, 'kind' ) );
		$this->assertSame( home_url( '/' ), $this->resume() );
	}

	public function test_a_destination_of_its_own_is_followed_through_the_resume_address(): void {
		$welcome = home_url( '/welcome/' );
		$this->hook(
			'diluxone_users_login_redirect',
			static fn (): string => $welcome
		);

		$this->back( $this->start( '1' ) );

		$this->assertSame( 'next', $this->data( $this->bridge(), 'kind' ) );
		$this->assertSame( $welcome, $this->resume() );
	}

	public function test_the_return_of_a_trip_in_the_whole_window_still_redirects(): void {
		$this->back( $this->start( '' ) );

		$this->assertSame( home_url( '/' ), $this->expectRedirect( 'diluxone_users_sso_handle' ) );
		$this->assertArrayNotHasKey( diluxone_users_sso_resume_cookie(), self::$cookies );
	}

	public function test_a_resume_with_nothing_waiting_goes_to_the_sign_in_page(): void {
		$this->assertSame( diluxone_users_login_url(), $this->resume() );

		self::$cookies[ diluxone_users_sso_resume_cookie() ] = array(
			'value'   => 'made-up',
			'options' => array(),
		);
		$this->assertSame( diluxone_users_login_url(), $this->resume(), 'a name nobody issued is nothing' );
	}

	public function test_an_account_link_in_a_popup_resumes_on_the_account_with_its_notice(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );
		set_transient( 'diluxone_users_sso_back_' . $user, home_url( '/account/' ), 600 );

		$this->back( $this->start( '1', array( 'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_sso_link_' . MockProvider::ID ) ) ) );
		wp_set_current_user( $user );

		$this->assertSame( 'next', $this->data( $this->bridge(), 'kind' ), 'the account shows its notice' );
		$this->assertSame( $this->sub, get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		$this->assertSame( 'linked', $this->redirectState( $this->resume() ) );
	}

	/* ── C4: the second step and the required fields reach the opener ── */

	public function test_the_second_step_reaches_the_opener_and_its_key_never_enters_the_bridge(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );
		update_user_meta( $user, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp' ) );

		$this->back( $this->start( '1' ) );

		$html = $this->bridge();

		$this->assertSame( 0, get_current_user_id(), 'nobody is in before the code' );
		$this->assertSame( 'next', $this->data( $html, 'kind' ) );
		$this->assertStringNotContainsString( 'diluxone_users_key', $html );
		$this->assertStringNotContainsString( 'diluxone_users_2fa', $html );

		$step = $this->resume();
		$key  = $this->queryArg( $step, 'diluxone_users_key' );

		$this->assertSame( (string) $user, $this->queryArg( $step, 'diluxone_users_2fa' ), 'the opener is sent to the second step' );
		$this->assertNotSame( '', $key );
		$this->assertStringNotContainsString( $key, $html, 'the key stays server-side until the opener asks for it' );
	}

	public function test_the_required_fields_step_reaches_the_opener(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Account',
				'post_content' => '[diluxone_users_account]',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $page );
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'      => 'first_name',
					'label'    => 'First name',
					'type'     => 'text',
					'required' => 1,
				),
			)
		);
		unset( MockProvider::$profile['given_name'] );

		$this->back( $this->start( '1' ) );

		$this->assertSame( 'next', $this->data( $this->bridge(), 'kind' ), 'signed in, but not yet where a sign-in ends' );

		$step = $this->resume();

		$this->assertStringStartsWith( (string) get_permalink( $page ), $step );
		$this->assertSame( '1', $this->queryArg( $step, DILUXONE_USERS_COMPLETE_VAR ) );

		wp_delete_post( $page, true );
	}

	/* ── C5: an error or a cancel closes the popup, the opener shows it ── */

	public function test_a_cancel_at_the_provider_ends_in_the_bridge_and_the_opener_shows_the_error(): void {
		$this->back( $this->start( '1' ), array( 'code' => '', 'error' => 'access_denied' ) );

		$this->assertSame( 'next', $this->data( $this->bridge(), 'kind' ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, self::$cookies, 'no session' );
		$this->assertSame( 'social', $this->redirectState( $this->resume() ) );
	}

	public function test_a_forged_state_in_a_popup_still_ends_in_the_bridge_by_this_browsers_mark(): void {
		$trip = $this->start( '1' );
		$this->back( array( 'state' => 'forged', 'cookie' => $trip['cookie'] ) );

		$this->assertSame( 'next', $this->data( $this->bridge(), 'kind' ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( array(), MockProvider::$requests, 'nothing was asked of the provider' );
		$this->assertSame( 'social', $this->redirectState( $this->resume() ) );
	}

	public function test_a_forged_state_with_no_popup_trip_behind_it_redirects_as_before(): void {
		$trip = $this->start( '' );
		$this->back( array( 'state' => 'forged', 'cookie' => $trip['cookie'] ) );

		$this->assertSame( 'social', $this->redirectState( $this->expectRedirect( 'diluxone_users_sso_handle' ) ) );
	}

	public function test_a_refusal_at_the_start_of_a_popup_trip_ends_in_the_bridge(): void {
		// A link with no nonce: refused before the provider, in the popup.
		wp_set_current_user( $this->make_user() );
		$this->request(
			array(
				'diluxone_users_sso'   => MockProvider::ID,
				'diluxone_users_go'    => '1',
				'diluxone_users_popup' => '1',
			)
		);

		$this->assertSame( 'next', $this->data( $this->bridge(), 'kind' ) );
		$this->assertSame( 'social', $this->redirectState( $this->resume() ) );
	}
}
