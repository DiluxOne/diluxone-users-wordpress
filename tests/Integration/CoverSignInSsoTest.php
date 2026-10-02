<?php
/**
 * The social sign-in engine, every way out of it: the credentials and their
 * states, the URLs, each provider's profile, the exchange, and the round trip
 * — refusals first (an unknown route, a half-configured network, a throttled
 * machine, an error from the provider, a network turned off midway, no token,
 * nobody behind the identity, an identity taken), then the administrator's
 * live test with its result page.
 *
 * The round trip runs against MockProvider; the requests to real providers'
 * addresses (GitHub's list of e-mails, X's token endpoint) are answered by
 * this class's own `pre_http_request` filter, so nothing leaves the machine.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class CoverSignInSsoTest extends IntegrationTestCase {

	/** Identities are the test's own: links outlive nothing, but ids are compared across accounts. */
	private string $sub = '';

	/** @var string|null The permalink structure before the test, when it changed it. */
	private ?string $permalinks = null;

	/** @var array<string, mixed> Answers by URL, for this class's HTTP filter. */
	private array $answers = array();

	/** @var array<int, array{url: string, args: array<string, mixed>}> Requests this class's filter answered. */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();
		MockProvider::install();

		$this->sub = 'sub-' . wp_generate_password( 8, false );

		MockProvider::$profile = array(
			'sub'            => $this->sub,
			'email'          => 'sso-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
			'email_verified' => true,
			'given_name'     => 'Grace',
			'family_name'    => 'Hopper',
		);

		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );

		add_filter( 'pre_http_request', array( $this, 'http' ), 20, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'http' ), 20 );
		remove_filter( 'script_loader_src', array( $this, 'stop_at_the_script' ) );
		MockProvider::remove();

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		if ( null !== $this->permalinks ) {
			update_option( 'permalink_structure', $this->permalinks );
			$this->permalinks = null;
		}

		wp_deregister_style( 'diluxone-users-sso-test' );
		wp_deregister_script( 'diluxone-users-sso-test' );

		parent::tearDown();
	}

	/**
	 * Answers the addresses the test named, after MockProvider, so its answer wins; anything else is left as it was.
	 *
	 * @param mixed                $pre
	 * @param array<string, mixed> $args
	 * @return mixed
	 */
	public function http( $pre, array $args, string $url ) {
		if ( ! array_key_exists( $url, $this->answers ) ) {
			return $pre;
		}

		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		$answer = $this->answers[ $url ];

		if ( $answer instanceof \WP_Error ) {
			return $answer;
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $answer ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The result page ends in an exit, after its last script is printed: the
	 * test stops it there, with the page drawn.
	 */
	public function stop_at_the_script( string $src, string $handle ): string {
		if ( 'diluxone-users-sso-test' === $handle ) {
			throw new \RuntimeException( 'sso-test-page' );
		}

		return $src;
	}

	/** Runs the result page, and returns what it drew. */
	private function result_page( callable $run ): string {
		add_filter( 'script_loader_src', array( $this, 'stop_at_the_script' ), 10, 2 );
		ob_start();

		try {
			$run();
			$this->fail( 'The result page was expected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sso-test-page', $e->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'script_loader_src', array( $this, 'stop_at_the_script' ) );
		}

		return $html;
	}

	/** @param array<string, string> $get */
	private function request( array $get, array $cookie = array() ): void {
		$_GET    = $get;
		$_COOKIE = $cookie;
		diluxone_users_sso_query_snapshot();
	}

	/**
	 * Starts a trip as whoever is signed in and returns the state and cookie.
	 *
	 * @param array<string, string> $extra
	 * @return array{state: string, cookie: string, url: string}
	 */
	private function start( array $extra = array() ): array {
		$this->request(
			$extra + array(
				'diluxone_users_sso' => MockProvider::ID,
				'diluxone_users_go'  => '1',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		return array(
			'state'  => $this->queryArg( $url, 'state' ),
			'cookie' => self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '',
			'url'    => $url,
		);
	}

	/**
	 * The provider's return to this browser.
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

	/** The mock provider configured but never tested: the state the live test starts from. */
	private function untested(): void {
		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				MockProvider::ID => array(
					'active' => 0,
					'id'     => 'client-id',
					'secret' => 'client-secret',
					'tested' => 0,
				),
			)
		);
	}

	/** An administrator of what is being tested, with the live test's link. */
	private function admin_with_test_link(): array {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$url = diluxone_users_sso_test_url( MockProvider::ID );

		return array(
			'diluxone_users_sso'   => MockProvider::ID,
			'diluxone_users_go'    => '1',
			'diluxone_users_test'  => '1',
			'diluxone_users_nonce' => $this->queryArg( $url, 'diluxone_users_nonce' ),
		);
	}

	/* ── Credentials and states ──────────────────────────────────────── */

	public function test_new_credentials_lose_the_test_and_cannot_be_turned_on_untested(): void {
		$this->assertSame( 'enabled', diluxone_users_sso_state( MockProvider::ID ) );

		diluxone_users_sso_save_credentials(
			MockProvider::ID,
			array(
				'active' => 1,
				'id'     => 'another-client',
				'secret' => ' another-secret ',
			)
		);

		$this->assertSame( 'not-tested', diluxone_users_sso_state( MockProvider::ID ) );
		$this->assertSame(
			array(
				'active' => false,
				'id'     => 'another-client',
				'secret' => 'another-secret',
			),
			diluxone_users_sso_credentials( MockProvider::ID )
		);
	}

	public function test_the_same_credentials_saved_again_keep_the_test_and_may_be_switched(): void {
		diluxone_users_sso_save_credentials(
			MockProvider::ID,
			array(
				'active' => 0,
				'id'     => 'client-id',
				'secret' => 'client-secret',
			)
		);
		$this->assertSame( 'disabled', diluxone_users_sso_state( MockProvider::ID ) );

		diluxone_users_sso_save_credentials(
			MockProvider::ID,
			array(
				'active' => 1,
				'id'     => 'client-id',
				'secret' => 'client-secret',
			)
		);
		$this->assertSame( 'enabled', diluxone_users_sso_state( MockProvider::ID ) );
	}

	public function test_saving_one_provider_leaves_the_others_alone(): void {
		diluxone_users_sso_save_credentials( 'github', array( 'id' => 'gh', 'secret' => 'shh' ) );

		$this->assertSame( 'enabled', diluxone_users_sso_state( MockProvider::ID ) );
		$this->assertSame( 'not-tested', diluxone_users_sso_state( 'github' ) );
		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'discord' ) );
	}

	public function test_the_tested_mark_can_be_set_and_taken_away(): void {
		$this->untested();

		diluxone_users_sso_set_tested( MockProvider::ID, true );
		$this->assertTrue( diluxone_users_sso_tested( MockProvider::ID ) );

		diluxone_users_sso_set_tested( MockProvider::ID, false );
		$this->assertFalse( diluxone_users_sso_tested( MockProvider::ID ) );

		// A provider with no entry at all gets one holding only the mark.
		diluxone_users_sso_set_tested( 'amazon', true );
		$this->assertTrue( diluxone_users_sso_tested( 'amazon' ) );
		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'amazon' ) );
	}

	/* ── Addresses ───────────────────────────────────────────────────── */

	public function test_with_plain_permalinks_the_callback_is_a_parameter(): void {
		$this->permalinks = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '' );

		$this->assertSame( add_query_arg( 'diluxone_users_sso', 'google', home_url( '/' ) ), diluxone_users_sso_redirect_uri( 'google' ) );
	}

	public function test_with_pretty_permalinks_the_callback_is_a_path_with_no_query(): void {
		$this->permalinks = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );

		$uri = diluxone_users_sso_redirect_uri( 'google' );

		$this->assertSame( home_url( '/sso/google/' ), $uri );
		$this->assertNull( wp_parse_url( $uri, PHP_URL_QUERY ) );
	}

	public function test_the_live_test_link_carries_its_own_nonce(): void {
		wp_set_current_user( $this->make_user( 'administrator' ) );

		$url = diluxone_users_sso_test_url( 'google' );

		$this->assertSame( '1', $this->queryArg( $url, 'diluxone_users_go' ) );
		$this->assertSame( '1', $this->queryArg( $url, 'diluxone_users_test' ) );
		$this->assertSame( 1, wp_verify_nonce( $this->queryArg( $url, 'diluxone_users_nonce' ), 'diluxone_users_sso_test_google' ) );
		$this->assertFalse( wp_verify_nonce( $this->queryArg( $url, 'diluxone_users_nonce' ), 'diluxone_users_sso_test_github' ) );
	}

	public function test_the_route_s_value_is_kept_by_wordpress(): void {
		$this->assertSame( array( 'p', 'diluxone_users_sso' ), diluxone_users_sso_query_var( array( 'p' ) ) );
	}

	/* ── Each provider's profile ─────────────────────────────────────── */

	public function test_wordpress_com_s_profile_is_read_with_its_own_keys(): void {
		$this->assertSame(
			array(
				'name'      => 'Ada',
				'last_name' => 'King Lovelace',
				'id'        => '42',
				'email'     => 'ada@example.test',
				'verified'  => false,
			),
			diluxone_users_sso_map_wordpress(
				array(
					'display_name'   => ' Ada  King Lovelace ',
					'ID'             => 42,
					'email'          => 'ada@example.test',
					'email_verified' => 'false',
				),
				'token'
			)
		);
	}

	public function test_amazon_vouches_for_the_address_it_hands_over_and_only_then(): void {
		$with = diluxone_users_sso_map_amazon( array( 'name' => 'Ada Lovelace', 'email' => 'a@example.test', 'user_id' => 'amzn1' ), 't' );
		$none = diluxone_users_sso_map_amazon( array( 'name' => 'Ada', 'user_id' => 'amzn2' ), 't' );

		$this->assertSame( array( 'name' => 'Ada', 'last_name' => 'Lovelace', 'id' => 'amzn1', 'email' => 'a@example.test', 'verified' => true ), $with );
		$this->assertSame( '', $none['email'] );
		$this->assertNull( $none['verified'] );
	}

	public function test_github_takes_the_verified_primary_address_over_the_public_one(): void {
		$this->answers['https://api.github.com/user/emails'] = array(
			array( 'email' => 'secondary@example.test', 'primary' => false, 'verified' => true ),
			array( 'email' => 'primary@example.test', 'primary' => true, 'verified' => true ),
		);

		$identity = diluxone_users_sso_map_github( array( 'id' => 7, 'name' => 'Ada Lovelace', 'email' => 'public@example.test' ), 'gh-token' );

		$this->assertSame( 'primary@example.test', $identity['email'] );
		$this->assertTrue( $identity['verified'] );
		$this->assertSame( '7', $identity['id'] );
		$this->assertSame( 'Bearer gh-token', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'diluxone-users', $this->requests[0]['args']['headers']['User-Agent'] );
	}

	public function test_github_with_no_verified_primary_falls_back_to_the_public_address_unvouched(): void {
		$this->answers['https://api.github.com/user/emails'] = array(
			array( 'email' => 'primary@example.test', 'primary' => true, 'verified' => false ),
		);

		$identity = diluxone_users_sso_map_github( array( 'id' => 7, 'name' => 'Ada', 'email' => 'public@example.test' ), 't' );

		$this->assertSame( 'public@example.test', $identity['email'] );
		$this->assertNull( $identity['verified'] );
		// And that address, which anybody can type, links nobody's account.
		$this->assertFalse( diluxone_users_sso_email_trusted( $identity, true ) );
	}

	public function test_a_profile_request_that_fails_reads_as_nothing(): void {
		$this->answers['https://api.github.com/user/emails'] = new \WP_Error( 'http_request_failed', 'down' );

		$this->assertSame( array(), diluxone_users_sso_get( 'https://api.github.com/user/emails', 't' ) );
	}

	/* ── The exchange ────────────────────────────────────────────────── */

	public function test_x_gets_its_secret_in_basic_auth_and_the_verifier_in_the_body(): void {
		diluxone_users_sso_save_credentials( 'twitter', array( 'id' => 'x-id', 'secret' => 'x-secret' ) );
		$this->answers['https://x.test/token'] = array( 'access_token' => 'x-token' );

		$token = diluxone_users_sso_token( 'twitter', array( 'token' => 'https://x.test/token' ), 'the-code', 'the-verifier' );

		$this->assertSame( 'x-token', $token );
		$sent = $this->requests[0]['args'];
		$this->assertSame( 'Basic ' . base64_encode( 'x-id:x-secret' ), $sent['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'client_secret', $sent['body'] );
		$this->assertSame( 'the-verifier', $sent['body']['code_verifier'] );
	}

	public function test_an_exchange_that_fails_hands_over_no_token(): void {
		$this->answers['https://x.test/token'] = new \WP_Error( 'http_request_failed', 'down' );

		$this->assertSame( '', diluxone_users_sso_token( MockProvider::ID, array( 'token' => 'https://x.test/token' ), 'code' ) );
	}

	public function test_a_provider_that_wants_pkce_is_sent_the_challenge_and_the_verifier_is_kept(): void {
		$pkce = static function ( array $providers ): array {
			$providers[ MockProvider::ID ]['pkce'] = true;

			return $providers;
		};
		add_filter( 'diluxone_users_sso_providers', $pkce, 20 );

		try {
			$trip = $this->start();
		} finally {
			remove_filter( 'diluxone_users_sso_providers', $pkce, 20 );
		}

		$stored = get_transient( 'diluxone_users_sso_' . $trip['state'] );
		$this->assertSame( 'S256', $this->queryArg( $trip['url'], 'code_challenge_method' ) );
		$this->assertSame(
			rtrim( strtr( base64_encode( hash( 'sha256', $stored['verifier'], true ) ), '+/', '-_' ), '=' ),
			$this->queryArg( $trip['url'], 'code_challenge' )
		);
		$this->assertSame( 64, strlen( $stored['verifier'] ) );
	}

	/* ── Who is behind an identity ───────────────────────────────────── */

	public function test_an_empty_identity_is_nobody_s(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', '' );

		$this->assertSame( 0, diluxone_users_sso_owner( MockProvider::ID, '' ) );
	}

	public function test_an_identity_with_no_usable_address_makes_no_account(): void {
		foreach ( array( '', 'not-an-address' ) as $email ) {
			$this->assertSame(
				0,
				diluxone_users_sso_user(
					MockProvider::ID,
					array(
						'id'        => $this->sub,
						'email'     => $email,
						'name'      => '',
						'last_name' => '',
						'verified'  => true,
					)
				)
			);
		}
	}

	public function test_an_account_created_with_a_role_kept_out_is_not_linked(): void {
		// The role a new account gets may use social sign-in; another plugin
		// then changes it, on `user_register`, to one that may not. The check
		// after creating catches what the check before could not see.
		$email  = 'kept-out-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$demote = static function ( int $user_id ): void {
			( new \WP_User( $user_id ) )->set_role( 'contributor' );
		};
		diluxone_users_update_option( 'diluxone_users_sso_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_sso_roles', array( diluxone_users_register_role() ) );
		add_action( 'user_register', $demote );

		try {
			$user = diluxone_users_sso_user(
				MockProvider::ID,
				array(
					'id'        => $this->sub,
					'email'     => $email,
					'name'      => 'Ada',
					'last_name' => '',
					'verified'  => true,
				)
			);
		} finally {
			remove_action( 'user_register', $demote );
		}

		$this->assertSame( 0, $user );
		$made = get_user_by( 'email', $email );
		$this->assertInstanceOf( \WP_User::class, $made );
		$this->assertSame( '', get_user_meta( $made->ID, 'diluxone_users_sso_mock', true ), 'no link to an account that may not use it' );
	}

	public function test_nobody_is_not_kept_out(): void {
		$this->assertFalse( diluxone_users_sso_role_blocked( PHP_INT_MAX ) );
	}

	public function test_a_name_typed_on_the_site_wins_over_the_provider_s(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'first_name', 'Typed' );

		$this->assertSame(
			$user,
			diluxone_users_sso_user(
				MockProvider::ID,
				array(
					'id'        => $this->sub,
					'email'     => get_userdata( $user )->user_email,
					'name'      => 'Provider',
					'last_name' => 'Surname',
					'verified'  => true,
				)
			)
		);
		$this->assertSame( 'Typed', get_user_meta( $user, 'first_name', true ) );
		$this->assertSame( 'Surname', get_user_meta( $user, 'last_name', true ) );
	}

	public function test_the_networks_linked_are_listed(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );
		update_user_meta( $user, 'diluxone_users_sso_github', 'gh-1' );

		$linked = diluxone_users_sso_linked( $user );
		sort( $linked );

		$this->assertSame( array( 'github', MockProvider::ID ), $linked );
		$this->assertSame( array(), diluxone_users_sso_linked( $this->make_user() ) );
	}

	/* ── The round trip's refusals ───────────────────────────────────── */

	public function test_a_route_to_a_network_that_is_not_there_says_so(): void {
		$wp             = new \WP();
		$wp->query_vars = array( 'diluxone_users_sso' => 'gone' );

		$url = $this->expectRedirect( static fn() => diluxone_users_sso_handle( $wp ) );

		$this->assertSame( 'social', $this->redirectState( $url ) );
	}

	public function test_a_parameter_naming_no_network_is_ignored(): void {
		$this->request( array( 'diluxone_users_sso' => 'gone' ) );

		diluxone_users_sso_handle();

		$this->assertSame( array(), MockProvider::$requests );
	}

	public function test_a_request_with_no_code_and_no_error_does_nothing(): void {
		$this->request( array( 'diluxone_users_sso' => MockProvider::ID, 'state' => 'abc' ) );

		diluxone_users_sso_handle();

		$this->assertSame( array(), MockProvider::$requests );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_half_configured_network_sends_nobody_out(): void {
		$this->untested();

		$url = $this->start()['url'];

		$this->assertSame( 'social', $this->redirectState( $url ) );
	}

	public function test_a_machine_starting_trips_in_a_loop_meets_the_ceiling(): void {
		for ( $i = 0; $i < diluxone_users_login_burst(); $i++ ) {
			diluxone_users_ip_burst( 'sso', diluxone_users_login_burst() );
		}

		$url = $this->start()['url'];

		$this->assertSame( 'social', $this->redirectState( $url ) );
	}

	public function test_off_the_hub_an_old_address_goes_to_the_hub(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site is its own hub; there the trip starts, as in test_a_provider_that_wants_pkce_is_sent_the_challenge_and_the_verifier_is_kept.' );
		}

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-signin-sso-' . strtolower( wp_generate_password( 6, false ) ) . '/',
			)
		);
		switch_to_blog( $site );

		$out = $this->start()['url'];
		$this->assertSame( diluxone_users_login_url(), $out );

		$user = $this->make_user();
		wp_set_current_user( $user );
		$in = $this->start()['url'];
		$this->assertSame( diluxone_users_account_url(), $in );
		$this->assertSame( array(), MockProvider::$requests );
	}

	public function test_a_provider_s_error_on_a_sign_in_goes_back_to_the_form(): void {
		$trip = $this->start();
		$this->back( $trip, array( 'error' => 'access_denied', 'code' => '' ) );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( array(), MockProvider::$requests );
	}

	public function test_a_network_turned_off_between_leaving_and_coming_back_signs_nobody_in(): void {
		$trip = $this->start();
		diluxone_users_sso_save_credentials( MockProvider::ID, array( 'active' => 0, 'id' => 'client-id', 'secret' => 'client-secret' ) );
		$this->back( $trip );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( array(), MockProvider::$requests, 'no code exchanged' );
	}

	public function test_no_token_from_the_provider_signs_nobody_in(): void {
		$this->answers[ MockProvider::BASE . 'token' ] = array( 'error' => 'invalid_grant' );
		$trip = $this->start();
		$this->back( $trip );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_an_identity_with_no_account_behind_it_signs_nobody_in(): void {
		diluxone_users_update_option( 'diluxone_users_sso_register', 0 );
		$trip = $this->start();
		$this->back( $trip );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( get_user_by( 'email', MockProvider::$profile['email'] ) );
	}

	public function test_an_identity_linked_to_somebody_else_is_not_linked_twice(): void {
		$owner = $this->make_user();
		update_user_meta( $owner, 'diluxone_users_sso_mock', $this->sub );

		$user = $this->make_user();
		wp_set_current_user( $user );
		set_transient( 'diluxone_users_sso_back_' . $user, home_url( '/account/' ), 60 );
		$trip = $this->start( array( 'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_sso_link_' . MockProvider::ID ) ) );
		$this->back( $trip );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'taken', $this->redirectState( $url ) );
		$this->assertStringStartsWith( home_url( '/account/' ), $url );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		delete_transient( 'diluxone_users_sso_back_' . $user );
	}

	public function test_a_link_with_an_empty_profile_links_nothing_and_says_linked(): void {
		MockProvider::$profile = array();
		$user                  = $this->make_user();
		wp_set_current_user( $user );
		$trip = $this->start( array( 'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_sso_link_' . MockProvider::ID ) ) );
		$this->back( $trip );

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'linked', $this->redirectState( $url ) );
		$this->assertStringStartsWith( home_url( '/' ), $url );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		$this->assertSame( array(), self::$mail, 'nothing linked, nothing to tell' );
	}

	public function test_the_emergency_switch_closes_the_route(): void {
		$on = '__return_true';
		add_filter( 'diluxone_users_safe_mode', $on );
		$this->request( array( 'diluxone_users_sso' => MockProvider::ID, 'diluxone_users_go' => '1' ) );

		try {
			diluxone_users_sso_handle();
		} finally {
			remove_filter( 'diluxone_users_safe_mode', $on );
		}

		$this->assertArrayNotHasKey( diluxone_users_sso_cookie(), self::$cookies );
	}

	public function test_unlinking_without_the_form_nonce_unlinks_nothing(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );
		$this->postAs( $user, array( 'diluxone_users_provider' => MockProvider::ID, '_wpnonce' => 'forged' ) );

		try {
			diluxone_users_sso_unlink();
			$this->fail( 'A forged nonce has to stop the request.' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$this->assertSame( $this->sub, get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
	}

	public function test_unlinking_tells_the_owner_and_goes_back_where_they_were(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );
		wp_set_current_user( $user );
		$this->postAs( $user, array( 'diluxone_users_provider' => MockProvider::ID, '_wpnonce' => wp_create_nonce( 'diluxone_users_sso_unlink' ) ) );

		$url = $this->expectRedirect( 'diluxone_users_sso_unlink' );

		$this->assertSame( home_url( '/' ), $url );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		$this->assertStringContainsString( 'The Mock account was unlinked.', (string) $this->lastMail()['message'] );
	}

	/* ── The administrator's live test ───────────────────────────────── */

	public function test_somebody_who_is_not_an_administrator_cannot_start_the_test(): void {
		$this->untested();

		// The editor's own link, with a nonce good for the editor: what stops
		// them is the capability, not a nonce made for somebody else.
		wp_set_current_user( $this->make_user( 'editor' ) );
		$link = diluxone_users_sso_test_url( MockProvider::ID );

		$url = $this->start(
			array(
				'diluxone_users_test'  => '1',
				'diluxone_users_nonce' => $this->queryArg( $link, 'diluxone_users_nonce' ),
			)
		)['url'];

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( array(), MockProvider::$requests );
		$this->assertSame( 0, $this->states(), 'no trip was started' );
	}

	public function test_an_administrator_without_the_tests_nonce_cannot_start_it(): void {
		$this->untested();
		$get                         = $this->admin_with_test_link();
		$get['diluxone_users_nonce'] = 'forged';

		$this->assertSame( 'social', $this->redirectState( $this->start( $get )['url'] ) );
		$this->assertSame( 0, $this->states() );
	}

	/** How many trips are waiting to come back. */
	private function states(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_diluxone\\_users\\_sso\\_%'" );
	}

	public function test_the_live_test_of_an_untested_network_works_and_marks_it_tested(): void {
		$this->untested();
		$trip = $this->start( $this->admin_with_test_link() );

		$this->assertStringStartsWith( MockProvider::BASE . 'authorize', $trip['url'] );
		$this->assertSame( 1, get_transient( 'diluxone_users_sso_' . $trip['state'] )['test'] );

		$this->back( $trip );
		$html = $this->result_page( 'diluxone_users_sso_handle' );

		$this->assertTrue( diluxone_users_sso_tested( MockProvider::ID ) );
		$this->assertStringContainsString( '<h1>It works</h1>', $html );
		$this->assertStringContainsString( 'is-ok', $html );
		$this->assertStringContainsString( esc_html( 'The provider handed over this email: ' . MockProvider::$profile['email'] ), $html );
		$this->assertStringContainsString( 'diluxone-users-sso-test.css', $html );
		// It signs nobody in and links nothing.
		$this->assertSame( '', get_user_meta( get_current_user_id(), 'diluxone_users_sso_mock', true ) );
		$this->assertFalse( get_user_by( 'email', MockProvider::$profile['email'] ) );
	}

	public function test_the_live_test_reports_an_empty_profile(): void {
		$this->untested();
		MockProvider::$profile = array( 'email' => 'x@example.test' );
		$trip                  = $this->start( $this->admin_with_test_link() );
		$this->back( $trip );

		$html = $this->result_page( 'diluxone_users_sso_handle' );

		$this->assertStringContainsString( '<h1>It did not work</h1>', $html );
		$this->assertStringContainsString( 'the profile came back empty', $html );
		$this->assertFalse( diluxone_users_sso_tested( MockProvider::ID ) );
	}

	public function test_the_live_test_reports_a_missing_token(): void {
		$this->untested();
		$this->answers[ MockProvider::BASE . 'token' ] = array();
		$trip = $this->start( $this->admin_with_test_link() );
		$this->back( $trip );

		$html = $this->result_page( 'diluxone_users_sso_handle' );

		$this->assertStringContainsString( 'is-failed', $html );
		$this->assertStringContainsString( 'did not hand over an access token', $html );
		$this->assertFalse( diluxone_users_sso_tested( MockProvider::ID ) );
	}

	public function test_the_live_test_shows_the_provider_s_error(): void {
		$this->untested();
		$trip = $this->start( $this->admin_with_test_link() );
		$this->back(
			$trip,
			array(
				'code'              => '',
				'error'             => 'redirect_uri_mismatch',
				'error_description' => 'The <b>redirect</b> URI is wrong',
			)
		);

		$html = $this->result_page( 'diluxone_users_sso_handle' );

		$this->assertStringContainsString( 'The round trip with Mock failed.', $html );
		$this->assertStringContainsString( 'The redirect URI is wrong', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_the_live_test_shows_the_bare_error_when_there_is_no_description(): void {
		$this->untested();
		$trip = $this->start( $this->admin_with_test_link() );
		$this->back( $trip, array( 'code' => '', 'error' => 'access_denied' ) );

		$html = $this->result_page( 'diluxone_users_sso_handle' );

		$this->assertStringContainsString( 'access_denied', $html );
	}

	public function test_the_result_page_with_no_detail_has_no_detail_line(): void {
		$html = $this->result_page(
			static function (): void {
				diluxone_users_sso_test_result( array( 'name' => 'Mock' ), true );
			}
		);

		$this->assertStringContainsString( 'You can enable the button now.', $html );
		$this->assertStringNotContainsString( 'diluxone-users-sso-test__detail', $html );
	}

	/* ── The round trip's other outcomes ──────────────────────────── */

	/** The trip, start to return, as whoever is signed in now; where it ended. */
	private function trip(): string {
		$trip = $this->start();
		$this->back( $trip );

		return $this->expectRedirect( 'diluxone_users_sso_handle' );
	}

	/** An account linked before its role was kept out of social sign-in does not get in by it. */
	public function test_a_linked_account_whose_role_is_now_kept_out_does_not_get_in(): void {
		$admin = $this->make_user( 'administrator' );
		update_user_meta( $admin, 'diluxone_users_sso_mock', $this->sub );
		diluxone_users_update_option( 'diluxone_users_sso_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_sso_roles', array( 'subscriber' ) );

		$this->assertSame( 'social', $this->redirectState( $this->trip() ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertArrayNotHasKey( LOGGED_IN_COOKIE, self::$cookies );
	}

	/** Somebody with a second step meets it after a social sign-in too. */
	public function test_a_social_sign_in_meets_the_second_step(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );
		update_user_meta( $user, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp' ) );

		$url = $this->trip();

		$this->assertSame( (string) $user, $this->queryArg( $url, 'diluxone_users_2fa' ), 'sent to the second step' );
		$this->assertSame( 0, get_current_user_id(), 'and nobody is in yet' );
		$this->assertNotSame( array(), diluxone_users_meta_list( $user, 'diluxone_users_2fa_pending' ) );
	}

	/** A link sends the person back to where they started it, and never off the site. */
	public function test_a_link_goes_back_where_it_was_asked_and_never_off_the_site(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );
		set_transient( 'diluxone_users_sso_back_' . $user, 'https://evil.test/', 600 );

		$trip = $this->start( array( 'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_sso_link_' . MockProvider::ID ) ) );
		$this->back( $trip );
		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'linked', $this->redirectState( $url ) );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/** A social sign-in opens the session the way every door does, and a new account carries the profile. */
	public function test_a_social_sign_in_that_makes_an_account_carries_the_profile_and_is_logged(): void {
		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );
		$heard = 0;
		$this->hook(
			'wp_login',
			static function () use ( &$heard ): void {
				++$heard;
			}
		);

		$this->trip();
		$user = get_current_user_id();

		$this->assertGreaterThan( 0, $user );
		$this->assertSame( $this->sub, get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		$this->assertSame( 'Grace', get_user_meta( $user, 'first_name', true ) );
		$this->assertSame( 'Hopper', get_user_meta( $user, 'last_name', true ) );
		$this->assertSame( 1, $heard, 'wp_login, once' );

		$rows = (array) diluxone_users_log_search( array( 'event' => 'signed_in' ), 1, 50 )['rows'];
		$mine = array_values( array_filter( $rows, static fn( $row ): bool => $user === (int) ( (array) $row )['user_id'] ) );
		$this->assertCount( 1, $mine );
		$this->assertStringContainsString( 'sso', (string) wp_json_encode( ( (array) $mine[0] )['detail'] ) );
	}

	/** Where a site sends people after signing in applies to the social door too. */
	public function test_the_login_redirect_filter_applies_to_the_social_door(): void {
		$this->hook( 'diluxone_users_login_redirect', static fn(): string => home_url( '/landing/' ) );

		$this->assertSame( home_url( '/landing/' ), $this->trip() );
	}

	/** A network switched off (tested, but off) sends nobody out. */
	public function test_a_network_switched_off_sends_nobody_out(): void {
		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				MockProvider::ID => array(
					'active' => 0,
					'id'     => 'client-id',
					'secret' => 'client-secret',
					'tested' => 1,
				),
			)
		);

		$this->assertSame( 'social', $this->redirectState( $this->start()['url'] ) );
		$this->assertSame( array(), MockProvider::$requests );
	}

	/** Unlinking while signed out touches nothing and mails nobody. */
	public function test_unlinking_signed_out_touches_nothing(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_mock', $this->sub );
		$this->postAs( 0, array( 'diluxone_users_provider' => MockProvider::ID, '_wpnonce' => wp_create_nonce( 'diluxone_users_sso_unlink' ) ) );

		$this->assertSame( home_url( '/' ), $this->expectRedirect( 'diluxone_users_sso_unlink' ) );
		$this->assertSame( $this->sub, get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		$this->assertSame( array(), self::$mail );
	}

	/** The buttons on the sign-in page: none while social sign-in is off there, and only the ones switched on. */
	public function test_the_sign_in_page_offers_only_the_networks_switched_on(): void {
		diluxone_users_update_option( 'diluxone_users_sso_login', 0 );
		$this->assertSame( array(), diluxone_users_sso_for_login() );

		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );
		$this->assertSame( array( MockProvider::ID ), array_keys( diluxone_users_sso_for_login() ) );

		$sso                              = (array) diluxone_users_raw_get( 'diluxone_users_sso' );
		$sso[ MockProvider::ID ]['active'] = 0;
		diluxone_users_update_option( 'diluxone_users_sso', $sso );
		$this->assertSame( array(), diluxone_users_sso_for_login(), 'switched off' );
	}

	/** A token answer that is not JSON is no token. */
	public function test_a_token_answer_that_is_not_json_is_no_token(): void {
		$this->hook(
			'pre_http_request',
			static fn( $pre, $args, $url ) => MockProvider::BASE . 'token' === $url ? array( 'headers' => array(), 'body' => '<html>oops</html>', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ) : $pre,
			15,
			3
		);

		$this->assertSame( '', diluxone_users_sso_token( MockProvider::ID, diluxone_users_sso_providers()[ MockProvider::ID ], 'the-code' ) );
	}

	/**
	 * On a network that takes no accounts, social sign-in creates none;
	 * on a single site it is the plugin's own switch that decides, whatever
	 * "Anyone can register" says.
	 */
	public function test_who_decides_whether_a_social_sign_in_creates_an_account(): void {
		if ( is_multisite() ) {
			update_site_option( 'registration', 'none' );

			$this->assertSame( 'social', $this->redirectState( $this->trip() ) );
			$this->assertFalse( get_user_by( 'email', MockProvider::$profile['email'] ) );

			return;
		}

		update_option( 'users_can_register', 0 );
		$this->trip();

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', MockProvider::$profile['email'] ), 'the plugin’s switch, not WordPress’s, on a single site' );
	}
}
