<?php
/**
 * The required fields an account came in without, asked for after a sign-in.
 *
 * Every door that opens a session is walked to where it sends the person:
 * the e-mail link and add-ons through diluxone_users_complete_login(), a
 * social network through the round trip, a password through WordPress's
 * `login_redirect`, and any of them through the end of the second step. Then
 * the step itself — the account page drawing only what is missing — and its
 * save going on to where they were headed.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class MissingFieldsTest extends IntegrationTestCase {

	private int $user;

	private int $page;

	protected function setUp(): void {
		parent::setUp();

		$this->page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Account',
				'post_content' => '[diluxone_users_account]',
			)
		);

		diluxone_users_update_option( 'diluxone_users_account_page', $this->page );
		$this->required( true );

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		MockProvider::remove();
		$_GET = array();

		parent::tearDown();
	}

	/** The starter fields, with the first name required or not. */
	private function required( bool $required ): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'      => 'first_name',
					'label'    => 'First name',
					'type'     => 'text',
					'required' => $required ? 1 : 0,
				),
				array(
					'key'   => 'last_name',
					'label' => 'Last name',
					'type'  => 'text',
				),
			)
		);
	}

	/** Asserts the address is the step, carrying where they were going. */
	private function assertStep( string $url, string $going ): void {
		$this->assertStringStartsWith( (string) get_permalink( $this->page ), $url, 'the account page' );
		$this->assertSame( '1', $this->queryArg( $url, 'diluxone-users-complete' ) );
		$this->assertSame( $going, rawurldecode( $this->queryArg( $url, 'redirect_to' ) ) );
	}

	/** C1: the e-mail link door ends on the step, and the step goes on to where they were headed. */
	public function test_a_new_account_by_link_lands_on_the_step_and_its_save_goes_on(): void {
		$going = home_url( '/course/' );
		$step  = $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, $going ) );

		$this->assertStep( $step, $going );
		$this->assertSame( $this->user, get_current_user_id(), 'the session is open: the step is a page, not a gate' );

		// The account page, asked for the step, draws the missing field alone.
		$_GET = array(
			'diluxone-users-complete' => '1',
			'redirect_to'             => $going,
		);
		$html = diluxone_users_shortcode_account();

		$this->assertStringContainsString( 'name="first_name"', $html );
		$this->assertStringNotContainsString( 'name="last_name"', $html, 'only what is missing' );
		$this->assertStringNotContainsString( 'Account sections', $html, 'in place of the sections and their nav' );
		$this->assertStringContainsString( 'name="diluxone_users_redirect" value="' . esc_url( $going ) . '"', $html );
		$this->assertStringContainsString( 'Skip for now', $html );

		// Saved with nothing, it stays on the step and says so.
		$post = array(
			'action'                  => 'diluxone_users_fields_save',
			'diluxone_users_group'    => '',
			'_wpnonce'                => wp_create_nonce( 'diluxone_users_fields_save' ),
			'_wp_http_referer'        => wp_make_link_relative( $step ),
			'diluxone_users_redirect' => $going,
		);
		$this->postAs( $this->user, $post + array( 'first_name' => '' ) );
		$back = $this->expectRedirect( 'diluxone_users_save_fields_form' );
		$this->assertSame( 'missing', $this->redirectState( $back ) );
		$this->assertSame( '1', $this->queryArg( $back, 'diluxone-users-complete' ) );

		// Saved with the name, on to where they were going.
		$this->postAs( $this->user, $post + array( 'first_name' => 'Ada' ) );
		$this->assertSame( $going, $this->expectRedirect( 'diluxone_users_save_fields_form' ) );
		$this->assertSame( 'Ada', get_user_meta( $this->user, 'first_name', true ) );
	}

	/** The destination a step carries never leaves the site. */
	public function test_the_step_never_goes_on_off_the_site(): void {
		update_user_meta( $this->user, 'first_name', 'Ada' );
		wp_set_current_user( $this->user );
		$this->postAs(
			$this->user,
			array(
				'_wpnonce'                => wp_create_nonce( 'diluxone_users_fields_save' ),
				'diluxone_users_redirect' => 'https://evil.example/x',
				'first_name'              => 'Ada',
			)
		);

		$this->assertSame( home_url( '/' ), $this->expectRedirect( 'diluxone_users_save_fields_form' ) );

		wp_set_current_user( $this->user );
		$_GET = array(
			'diluxone-users-complete' => '1',
			'redirect_to'             => 'https://evil.example/x',
		);
		delete_user_meta( $this->user, 'first_name' );
		$this->assertStringNotContainsString( 'evil.example', diluxone_users_shortcode_account() );
	}

	/** C2: a social sign-in that makes an account with no name lands on the same step. */
	public function test_a_new_account_by_a_social_network_with_no_name_lands_on_the_step(): void {
		if ( is_multisite() ) {
			update_site_option( 'registration', 'user' );
		}

		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );
		MockProvider::install();
		MockProvider::$profile = array(
			'sub'            => 'sub-' . wp_generate_password( 8, false ),
			'email'          => 'sso-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
			'email_verified' => true,
		);

		wp_set_current_user( 0 );
		$_GET    = array(
			'diluxone_users_sso' => MockProvider::ID,
			'diluxone_users_go'  => '1',
		);
		$_COOKIE = array();
		diluxone_users_sso_query_snapshot();
		$out = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$_GET    = array(
			'diluxone_users_sso' => MockProvider::ID,
			'code'               => 'the-code',
			'state'              => $this->queryArg( $out, 'state' ),
		);
		$_COOKIE = array( diluxone_users_sso_cookie() => self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '' );
		diluxone_users_sso_query_snapshot();
		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertGreaterThan( 0, get_current_user_id(), 'the account was made and is in' );
		$this->assertStep( $url, (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), 0 ) );
	}

	/** C3: every required field filled, straight to the destination. */
	public function test_an_account_with_every_required_field_goes_straight_on(): void {
		update_user_meta( $this->user, 'first_name', 'Ada' );

		$this->assertSame( home_url( '/course/' ), $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, home_url( '/course/' ) ) ) );
	}

	/** C4: with no field required, nobody ever sees it. */
	public function test_with_no_field_required_nobody_sees_it(): void {
		$this->required( false );

		$this->assertSame( home_url( '/course/' ), $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, home_url( '/course/' ) ) ) );
	}

	/** C5: the setting off, nobody sees it. */
	public function test_with_the_setting_off_nobody_sees_it(): void {
		diluxone_users_update_option( 'diluxone_users_ask_missing', 0 );

		$this->assertSame( home_url( '/course/' ), $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, home_url( '/course/' ) ) ) );
	}

	/** The setting is saved from User fields, and its box is drawn only while a field is required. */
	public function test_the_setting_is_saved_from_user_fields(): void {
		// A network setting, saved where the network's are: Network Admin.
		if ( is_multisite() ) {
			$this->in_network_admin();
		}

		$this->postPanel( 'diluxone-users-fields', array( 'diluxone_users_panel_drawn' => array( 'diluxone_users_ask_missing' ) ) );
		diluxone_users_screen_fields_missing_save();
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_ask_missing' ) );

		$this->postPanel( 'diluxone-users-fields', array( 'diluxone_users_ask_missing' => '1' ) );
		diluxone_users_screen_fields_missing_save();
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_ask_missing' ) );

		ob_start();
		diluxone_users_screen_fields_missing();
		$this->assertStringContainsString( 'name="diluxone_users_ask_missing"', (string) ob_get_clean() );

		$this->required( false );
		ob_start();
		diluxone_users_screen_fields_missing();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'name="diluxone_users_ask_missing"', $html, 'switched off while nothing is required' );
		$this->assertStringContainsString( 'No field is required yet', $html );

		$this->required( true );
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		ob_start();
		diluxone_users_screen_fields_missing();
		$this->assertStringContainsString( 'No account page is chosen', (string) ob_get_clean() );
	}

	/** With no account page, there is nowhere to ask, and the sign-in goes on. */
	public function test_with_no_account_page_the_sign_in_goes_on(): void {
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );

		$this->assertSame( home_url( '/course/' ), $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, home_url( '/course/' ) ) ) );
	}

	/** C6: a password with no second step lands on the step too, through WordPress's own filter. */
	public function test_a_password_sign_in_lands_on_the_step(): void {
		$going = home_url( '/course/' );

		$this->assertStep( (string) apply_filters( 'login_redirect', $going, $going, get_userdata( $this->user ) ), $going );
		$this->assertSame( $going, (string) apply_filters( 'login_redirect', $going, $going, new \WP_Error( 'incorrect_password', 'no' ) ), 'a wrong password goes nowhere new' );
	}

	/** C6: after the second step, and never instead of it. */
	public function test_the_second_step_comes_first_and_the_step_after_it(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		$going = home_url( '/course/' );

		wp_set_current_user( 0 );
		$challenge = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, 'password', false, $going ) );
		$this->assertSame( (string) $this->user, $this->queryArg( $challenge, 'diluxone_users_2fa' ), 'the second step first' );

		preg_match( '/\b(\d{6})\b/', (string) ( $this->lastMail()['message'] ?? '' ), $m );
		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $this->user,
				'diluxone_users_2fa_key'    => $this->queryArg( $challenge, 'diluxone_users_key' ),
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => $m[1] ?? '',
			)
		);

		$this->assertStep( $this->expectRedirect( 'diluxone_users_2fa_handle' ), $going );
		$this->assertSame( $this->user, get_current_user_id() );
	}

	/** C6: left without saving, it is asked again at the next sign-in, and nothing is kept about it. */
	public function test_left_without_saving_it_is_asked_again_and_nothing_is_stored(): void {
		$going  = home_url( '/course/' );
		$before = array_keys( (array) get_user_meta( $this->user ) );

		$this->assertStep( diluxone_users_ask_missing_url( $this->user, $going ), $going );
		$this->assertStep( diluxone_users_ask_missing_url( $this->user, $going ), $going );

		$this->assertSame( $before, array_keys( (array) get_user_meta( $this->user ) ), 'nothing written on the person' );
	}
}
