<?php
/**
 * A social sign-in whose address is proved by mail (sso-pending.php).
 *
 * With "Only verified addresses" on, a provider that does not confirm the
 * address no longer ends in an error: nothing is created or linked, a link is
 * mailed to the address, and the sign-in page waits with the address masked.
 * Opening the link creates or finds the account, links the identity and opens
 * the session through the common path. The round trip runs against
 * MockProvider, so nothing leaves the machine.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class SsoPendingTest extends IntegrationTestCase {

	/** The identity the provider hands over in this test. */
	private string $sub = '';

	/** The address the provider hands over in this test. */
	private string $email = '';

	protected function setUp(): void {
		parent::setUp();
		MockProvider::install();

		$this->sub   = 'sub-' . wp_generate_password( 8, false );
		$this->email = 'pending-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';

		// The provider says nothing about the address: no `email_verified`.
		MockProvider::$profile = array(
			'sub'         => $this->sub,
			'email'       => $this->email,
			'given_name'  => 'Grace',
			'family_name' => 'Hopper',
		);

		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );
		diluxone_users_update_option( 'diluxone_users_sso_verified_only', 1 );
	}

	protected function tearDown(): void {
		MockProvider::remove();

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		parent::tearDown();
	}

	/** @param array<string, string> $get */
	private function request( array $get, array $cookie = array() ): void {
		$_GET    = $get;
		$_COOKIE = $cookie;
		diluxone_users_sso_query_snapshot();
	}

	/**
	 * The whole trip to the provider and back, signed out: where it sends the person.
	 *
	 * @param array<string, string> $keep Cookies the browser already holds (the hub's way back).
	 */
	private function trip( array $keep = array() ): string {
		$this->request(
			array(
				'diluxone_users_sso' => MockProvider::ID,
				'diluxone_users_go'  => '1',
			),
			$keep
		);

		$out    = $this->expectRedirect( 'diluxone_users_sso_handle' );
		$cookie = self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '';

		$this->request(
			array(
				'diluxone_users_sso' => MockProvider::ID,
				'code'               => 'the-code',
				'state'              => $this->queryArg( $out, 'state' ),
			),
			$keep + array( diluxone_users_sso_cookie() => $cookie )
		);

		return $this->expectRedirect( 'diluxone_users_sso_handle' );
	}

	/** The confirmation links mailed so far, oldest first. */
	private function links(): array {
		$links = array();

		foreach ( self::$mail as $mail ) {
			if ( preg_match( '#https?://\S*diluxone_users_sso_pending=\S+#', (string) $mail['message'], $m ) ) {
				$links[] = $m[0];
			}
		}

		return $links;
	}

	/** The cookies this browser holds after the trip. */
	private function held(): array {
		$held = array();

		foreach ( array( DILUXONE_USERS_SSO_PENDING_COOKIE, DILUXONE_USERS_SSO_PENDING_SHOWN, DILUXONE_USERS_SSO_PENDING_BROWSER ) as $name ) {
			if ( isset( self::$cookies[ $name ] ) ) {
				$held[ $name ] = self::$cookies[ $name ]['value'];
			}
		}

		return $held;
	}

	/**
	 * Presses "Send it again" in this browser: where it sends the person.
	 *
	 * @param array<string, string> $cookie
	 */
	private function resend( array $cookie, string $nonce = '' ): string {
		$this->postAs( 0, array( 'action' => 'diluxone_users_sso_resend', 'diluxone_users_nonce' => '' !== $nonce ? $nonce : wp_create_nonce( 'diluxone_users_sso_resend' ) ) );
		$_COOKIE = $cookie;

		return $this->expectRedirect( 'diluxone_users_sso_pending_resend' );
	}

	/** The account behind the identity, or 0. */
	private function owner(): int {
		return diluxone_users_sso_owner( MockProvider::ID, $this->sub );
	}

	/**
	 * Opens a confirmation link: where it sends the person.
	 *
	 * In the browser that went to the provider unless cookies are passed: the
	 * link works only there.
	 *
	 * @param array<string, string>|null $cookie
	 */
	private function open( string $link, ?array $cookie = null ): string {
		$_COOKIE = $cookie ?? $this->held();
		$_GET    = array(
			'diluxone_users_sso_pending' => $this->queryArg( $link, 'diluxone_users_sso_pending' ),
			'diluxone_users_token'       => $this->queryArg( $link, 'diluxone_users_token' ),
		);

		return $this->expectRedirect( 'diluxone_users_sso_pending_consume' );
	}

	/* ── C1: back from the provider, nothing yet ─────────────────────── */

	public function test_an_unconfirmed_address_creates_nothing_mails_a_link_and_waits(): void {
		$url = $this->trip();

		$this->assertSame( 'social-pending', $this->redirectState( $url ) );
		$this->assertStringStartsWith( diluxone_users_login_url(), $url, 'on the sign-in page' );
		$this->assertStringNotContainsString( rawurlencode( $this->email ), $url, 'the address never goes in a URL' );
		$this->assertStringNotContainsString( $this->email, $url );

		$this->assertFalse( get_user_by( 'email', $this->email ), 'no account yet' );
		$this->assertSame( 0, $this->owner(), 'no identity linked' );
		$this->assertSame( 0, get_current_user_id(), 'nobody signed in' );

		$this->assertCount( 1, self::$mail );
		$this->assertSame( $this->email, self::$mail[0]['to'] );
		$this->assertCount( 1, $this->links() );
		$this->assertStringContainsString( 'Mock', (string) self::$mail[0]['message'], 'the mail names the network' );
		$this->assertStringStartsWith( home_url( '/' ), $this->links()[0] );

		$this->assertSame( 'p•••@e•••.test', self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'] ?? '' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{32}$/', self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'] ?? '' );
	}

	public function test_only_the_hash_of_the_token_is_kept(): void {
		$this->trip();

		$handle = self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'];
		$record = diluxone_users_sso_pending_get( $handle );
		$token  = $this->queryArg( $this->links()[0], 'diluxone_users_token' );

		$this->assertSame( MockProvider::ID, $record['provider'] );
		$this->assertSame( $this->email, $record['identity']['email'] );
		$this->assertSame( 40, strlen( $token ) );
		$this->assertSame( wp_hash( $token ), $record['hash'] );
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $record ) );
	}

	public function test_a_provider_that_says_no_goes_through_the_mail_too(): void {
		MockProvider::$profile['email_verified'] = false;

		$this->assertSame( 'social-pending', $this->redirectState( $this->trip() ) );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
		$this->assertCount( 1, $this->links() );
	}

	public function test_a_provider_that_confirms_the_address_signs_in_as_before(): void {
		MockProvider::$profile['email_verified'] = true;

		$this->assertSame( home_url( '/' ), $this->trip() );
		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertSame( array(), self::$mail );
	}

	public function test_an_address_that_would_get_nowhere_is_refused_as_before(): void {
		// No account behind it, and social sign-in creates none: a link would
		// lead nowhere, so none is sent.
		diluxone_users_update_option( 'diluxone_users_sso_register', 0 );

		$this->assertSame( 'social', $this->redirectState( $this->trip() ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_the_holding_screen_shows_the_masked_address_and_the_two_ways_on(): void {
		$this->trip();

		$_GET    = array( 'diluxone-users' => 'social-pending' );
		$_COOKIE = array( DILUXONE_USERS_SSO_PENDING_SHOWN => self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'] );

		$html = diluxone_users_shortcode_login();

		$this->assertStringContainsString( 'p•••@e•••.test', $html );
		$this->assertStringNotContainsString( $this->email, $html );
		$this->assertStringContainsString( 'value="diluxone_users_sso_resend"', $html );
		$this->assertStringContainsString( 'Send it again', $html );
		$this->assertStringContainsString( 'Use another address', $html );
		$this->assertStringContainsString( 'href="' . esc_url( diluxone_users_login_url() ) . '"', $html );
	}

	/* ── C2: the link opened ─────────────────────────────────────────── */

	public function test_the_link_creates_the_account_links_the_identity_and_signs_in(): void {
		$this->trip();
		$url = $this->open( $this->links()[0] );

		$user = get_user_by( 'email', $this->email );
		$this->assertInstanceOf( \WP_User::class, $user, 'the account, made now' );
		$this->assertSame( (int) $user->ID, get_current_user_id(), 'and signed in' );
		$this->assertSame( $this->sub, get_user_meta( $user->ID, 'diluxone_users_sso_mock', true ), 'the identity linked' );
		$this->assertSame( 'Grace', get_user_meta( $user->ID, 'first_name', true ) );
		$this->assertSame( 'Hopper', get_user_meta( $user->ID, 'last_name', true ) );
		$this->assertSame( home_url( '/' ), $url );
	}

	public function test_opened_in_the_browser_that_waited_the_pending_sign_in_is_forgotten_there(): void {
		$this->trip();
		$this->open( $this->links()[0] );

		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'] );
		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'] );
		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_SSO_PENDING_BROWSER ]['value'] );
	}

	/*
	 * Whoever starts the trip chooses the address: a provider that does not
	 * check it hands over anybody's. Opened by the owner of that inbox in
	 * their own browser, the link would link the stranger's network to the
	 * owner's account, so it works only where the trip started.
	 */
	public function test_the_link_opened_in_another_browser_creates_and_links_nothing(): void {
		$this->trip();
		$link = $this->links()[0];

		$url = $this->open( $link, array() );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertFalse( get_user_by( 'email', $this->email ), 'no account' );
		$this->assertSame( 0, $this->owner(), 'no identity linked' );
		$this->assertSame( 0, get_current_user_id() );

		// It spent nothing: the browser that waits still gets in.
		$this->open( $link );
		$this->assertGreaterThan( 0, get_current_user_id() );
	}

	public function test_the_link_opened_by_the_owner_of_an_existing_account_links_nothing(): void {
		$victim = (int) wp_insert_user(
			array(
				'user_login' => 'pending_' . wp_generate_password( 6, false ),
				'user_email' => $this->email,
				'user_pass'  => wp_generate_password( 16 ),
			)
		);

		$this->trip();
		$held = $this->held();

		// The owner's browser: signed in, holding the handle the link names, but not the trip's secret.
		wp_set_current_user( $victim );
		$url = $this->open( $this->links()[0], array( DILUXONE_USERS_SSO_PENDING_COOKIE => $held[ DILUXONE_USERS_SSO_PENDING_COOKIE ] ) );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertSame( '', get_user_meta( $victim, 'diluxone_users_sso_mock', true ), 'the stranger’s network is not linked' );
		$this->assertSame( 0, $this->owner() );
	}

	public function test_another_trip_s_secret_does_not_open_the_link(): void {
		$this->trip();
		$first = $this->held();
		$link  = $this->links()[0];

		// A second trip in another browser, with another address.
		self::$mail    = array();
		$this->email   = 'other-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$this->sub     = 'sub-' . wp_generate_password( 8, false );
		MockProvider::$profile['email'] = $this->email;
		MockProvider::$profile['sub']   = $this->sub;
		$this->trip();
		$second = $this->held();

		$url = $this->open( $link, array( DILUXONE_USERS_SSO_PENDING_BROWSER => $second[ DILUXONE_USERS_SSO_PENDING_BROWSER ] ) + $first );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_send_it_again_from_another_browser_sends_nothing(): void {
		$this->trip();
		$held = $this->held();

		delete_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ) );
		unset( $held[ DILUXONE_USERS_SSO_PENDING_BROWSER ] );

		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( $held ) ) );
		$this->assertCount( 1, $this->links(), 'no second mail' );
	}

	public function test_the_link_opens_the_account_that_already_has_the_address(): void {
		$existing = (int) wp_insert_user(
			array(
				'user_login' => 'pending_' . wp_generate_password( 6, false ),
				'user_email' => $this->email,
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => 'subscriber',
			)
		);

		$this->assertSame( 'social-pending', $this->redirectState( $this->trip() ) );
		$this->assertSame( '', get_user_meta( $existing, 'diluxone_users_sso_mock', true ), 'nothing linked before the link is opened' );

		$this->open( $this->links()[0] );

		$this->assertSame( $existing, get_current_user_id(), 'the same account, not a second one' );
		$this->assertSame( $this->sub, get_user_meta( $existing, 'diluxone_users_sso_mock', true ) );
	}

	public function test_an_existing_account_is_not_taken_while_linking_by_email_is_off(): void {
		wp_insert_user(
			array(
				'user_login' => 'pending_' . wp_generate_password( 6, false ),
				'user_email' => $this->email,
				'user_pass'  => wp_generate_password( 16 ),
			)
		);
		diluxone_users_update_option( 'diluxone_users_sso_link_by_email', 0 );

		$this->assertSame( 'social', $this->redirectState( $this->trip() ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_where_the_site_sends_people_after_signing_in_applies(): void {
		$this->hook( 'diluxone_users_login_redirect', static fn(): string => home_url( '/landing/' ) );

		$this->trip();

		$this->assertSame( home_url( '/landing/' ), $this->open( $this->links()[0] ) );
	}

	public function test_the_second_step_is_still_asked_for(): void {
		$user = (int) wp_insert_user(
			array(
				'user_login' => 'pending_' . wp_generate_password( 6, false ),
				'user_email' => $this->email,
				'user_pass'  => wp_generate_password( 16 ),
			)
		);
		update_user_meta( $user, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp' ) );

		$this->trip();
		$url = $this->open( $this->links()[0] );

		$this->assertSame( (string) $user, $this->queryArg( $url, 'diluxone_users_2fa' ), 'sent to the second step' );
		$this->assertSame( 0, get_current_user_id(), 'and nobody is in yet' );
	}

	public function test_a_provider_turned_off_before_the_link_is_opened_signs_nobody_in(): void {
		$this->trip();

		$sso                               = (array) diluxone_users_raw_get( 'diluxone_users_sso' );
		$sso[ MockProvider::ID ]['active'] = 0;
		diluxone_users_update_option( 'diluxone_users_sso', $sso );

		$this->assertSame( 'social', $this->redirectState( $this->open( $this->links()[0] ) ) );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/* ── C3: the link past its time ──────────────────────────────────── */

	public function test_a_link_past_its_time_is_expired_and_makes_nothing(): void {
		$this->trip();

		$handle            = self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'];
		$record            = diluxone_users_sso_pending_get( $handle );
		$record['expires'] = time() - 1;
		set_site_transient( diluxone_users_sso_pending_key( $handle ), $record, HOUR_IN_SECONDS );

		$url = $this->open( $this->links()[0] );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertStringStartsWith( diluxone_users_login_url(), $url );
		$this->assertFalse( get_user_by( 'email', $this->email ), 'no account' );
		$this->assertSame( 0, $this->owner(), 'no identity linked' );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_link_whose_record_is_gone_is_expired_and_makes_nothing(): void {
		$this->trip();

		// The transient lives as long as the link: once it is gone, it is gone.
		delete_site_transient( diluxone_users_sso_pending_key( self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'] ) );

		$this->assertSame( 'expired', $this->redirectState( $this->open( $this->links()[0] ) ) );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
		$this->assertSame( 0, $this->owner() );
	}

	/* ── C5: the link's limits ───────────────────────────────────────── */

	public function test_a_link_works_once(): void {
		$this->trip();
		$link = $this->links()[0];

		$this->open( $link );
		$user = get_current_user_id();
		$this->assertGreaterThan( 0, $user );
		wp_set_current_user( 0 );

		$this->assertSame( 'expired', $this->redirectState( $this->open( $link ) ) );
		$this->assertSame( 0, get_current_user_id(), 'the second time opens nothing' );
	}

	public function test_a_wrong_token_is_expired_and_spends_nothing(): void {
		$this->trip();
		$link  = $this->links()[0];
		$wrong = add_query_arg( 'diluxone_users_token', str_repeat( 'x', 40 ), $link );

		$this->assertSame( 'expired', $this->redirectState( $this->open( $wrong ) ) );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
		$this->assertSame( 0, $this->owner() );

		// Whoever guesses does not get to spend the real one.
		$this->open( $link );
		$this->assertGreaterThan( 0, get_current_user_id() );
	}

	public function test_a_handle_nobody_issued_is_expired(): void {
		$this->assertSame( 'expired', $this->redirectState( $this->open( home_url( '/?diluxone_users_sso_pending=' . str_repeat( 'a', 32 ) . '&diluxone_users_token=' . str_repeat( 'b', 40 ) ) ) ) );
		$this->assertSame( 'expired', $this->redirectState( $this->open( home_url( '/?diluxone_users_sso_pending=../x&diluxone_users_token=' ) ) ) );
	}

	public function test_an_inbox_written_to_a_moment_ago_gets_the_same_screen_and_no_mail(): void {
		set_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ), 1, 60 );

		$this->assertSame( 'social-pending', $this->redirectState( $this->trip() ) );
		$this->assertSame( 'p•••@e•••.test', self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'] ?? '' );
		$this->assertSame( array(), self::$mail );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
	}

	public function test_a_machine_past_its_count_gets_the_same_screen_and_nothing_is_kept(): void {
		for ( $i = 0; $i < diluxone_users_login_burst(); $i++ ) {
			diluxone_users_ip_burst( 'link', diluxone_users_login_burst() );
		}

		$this->assertSame( 'social-pending', $this->redirectState( $this->trip() ) );
		$this->assertSame( 'p•••@e•••.test', self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'] ?? '' );
		$this->assertArrayNotHasKey( DILUXONE_USERS_SSO_PENDING_COOKIE, self::$cookies, 'no record kept' );
		$this->assertSame( array(), self::$mail );
	}

	public function test_a_machine_past_its_account_count_gets_the_same_screen_and_no_mail(): void {
		for ( $i = 0; $i < diluxone_users_register_burst(); $i++ ) {
			diluxone_users_register_allowed();
		}

		$this->assertSame( 'social-pending', $this->redirectState( $this->trip() ) );
		$this->assertSame( array(), self::$mail );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
	}

	public function test_send_it_again_inside_the_wait_gets_the_same_screen_and_no_mail(): void {
		$this->trip();
		$held = $this->held();

		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( $held ) ) );
		$this->assertCount( 1, self::$mail, 'only the first' );
	}

	public function test_send_it_again_past_the_machine_s_count_gets_the_same_screen_and_no_mail(): void {
		$this->trip();
		$held = $this->held();
		delete_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ) );

		for ( $i = 0; $i < diluxone_users_login_burst(); $i++ ) {
			diluxone_users_ip_burst( 'link', diluxone_users_login_burst() );
		}

		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( $held ) ) );
		$this->assertCount( 1, self::$mail );
	}

	public function test_send_it_again_with_nothing_pending_or_no_nonce_gets_the_same_screen(): void {
		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( array() ) ) );
		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( array( DILUXONE_USERS_SSO_PENDING_COOKIE => str_repeat( 'a', 32 ) ) ) ) );

		$this->trip();
		$held = $this->held();
		delete_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ) );

		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( $held, 'forged' ) ) );
		$this->assertCount( 1, self::$mail, 'nothing sent without the nonce' );
	}

	/* ── C6: a network, whose round trip is the hub's ───────────────── */

	public function test_from_a_site_of_the_network_the_link_is_the_hub_s_and_goes_back_there(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network: on a single site the site is its own hub, which every other test here covers.' );
		}

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/sso-pending-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Pending test site',
			)
		);
		$this->assertGreaterThan( 0, $site );

		// Every site's members are the network's: the way back makes them one.
		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP, 'all' );
		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );

		switch_to_blog( $site );
		$back = home_url( '/a-page/' );
		// From that site, the sign-in is the hub's page, carrying the way back.
		$door = diluxone_users_login_url();
		restore_current_blog();

		$this->assertSame( strtok( diluxone_users_login_url(), '?' ), strtok( $door, '?' ), 'the hub’s sign-in page' );
		$this->assertNotSame( '', $this->queryArg( $door, 'redirect_to' ), 'carrying the way back' );

		// The hub's sign-in page holds where the person came from.
		$_GET = array( 'redirect_to' => $back );
		diluxone_users_return_capture();
		$_GET = array();
		$held = array( DILUXONE_USERS_RETURN_COOKIE => (string) ( self::$cookies[ DILUXONE_USERS_RETURN_COOKIE ]['value'] ?? '' ) );
		$this->assertSame( $back, $held[ DILUXONE_USERS_RETURN_COOKIE ] );

		// C1 on the hub: nothing yet, a link mailed, the hub's holding screen.
		$url = $this->trip( $held );

		$this->assertSame( 'social-pending', $this->redirectState( $url ) );
		$this->assertStringStartsWith( diluxone_users_login_url(), $url );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
		$this->assertCount( 1, $this->links() );
		$this->assertStringStartsWith( home_url( '/' ), $this->links()[0], 'the link is the hub’s' );

		// C2, opened in the browser that waits on the hub, without the hub's
		// way back any more: the record carries it, and it is back to the site.
		$this->assertSame( $back, $this->open( $this->links()[0] ) );

		$user = get_current_user_id();
		$this->assertGreaterThan( 0, $user );
		$this->assertSame( $this->sub, get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
		$this->assertTrue( is_user_member_of_blog( $user, $site ), 'a member of the site they came from' );
	}

	public function test_on_a_site_of_the_network_the_route_starts_nothing_and_goes_to_the_hub(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network.' );
		}

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/sso-pending-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Pending test site',
			)
		);

		switch_to_blog( $site );
		$this->request(
			array(
				'diluxone_users_sso' => MockProvider::ID,
				'code'               => 'the-code',
				'state'              => 'any',
			)
		);
		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );
		$hub = diluxone_users_login_url();
		restore_current_blog();

		$this->assertSame( $hub, $url );
		$this->assertSame( array(), self::$mail );
	}

	/* ── C7: "Send it again" and "Use another address" ───────────────── */

	public function test_send_it_again_past_the_wait_sends_a_new_link_and_the_old_one_dies(): void {
		$this->trip();
		$held = $this->held();
		$old  = $this->links()[0];

		// The wait is over.
		delete_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ) );

		$this->assertSame( 'social-pending', $this->redirectState( $this->resend( $held ) ) );
		$this->assertCount( 2, $this->links(), 'a second link' );
		$this->assertSame( $this->email, self::$mail[1]['to'] );

		$new = $this->links()[1];
		$this->assertSame( $this->queryArg( $old, 'diluxone_users_sso_pending' ), $this->queryArg( $new, 'diluxone_users_sso_pending' ), 'the same pending sign-in, replaced rather than added' );
		$this->assertNotSame( $this->queryArg( $old, 'diluxone_users_token' ), $this->queryArg( $new, 'diluxone_users_token' ) );

		$this->assertSame( 'expired', $this->redirectState( $this->open( $old ) ), 'only the newest link works' );
		$this->assertFalse( get_user_by( 'email', $this->email ) );

		$this->open( $new );
		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertSame( $this->sub, get_user_meta( get_current_user_id(), 'diluxone_users_sso_mock', true ) );
	}

	public function test_send_it_again_inside_the_wait_keeps_the_first_link_working(): void {
		$this->trip();
		$held = $this->held();

		$this->resend( $held );
		$this->assertCount( 1, $this->links() );

		$this->open( $this->links()[0] );
		$this->assertGreaterThan( 0, get_current_user_id() );
	}

	public function test_a_pending_sign_in_held_back_by_the_wait_is_sent_once_the_wait_is_over(): void {
		set_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ), 1, 60 );
		$this->trip();
		$this->assertSame( array(), self::$mail );

		delete_site_transient( 'diluxone_users_throttle_' . md5( strtolower( $this->email ) ) );
		$this->resend( $this->held() );

		$this->assertCount( 1, $this->links() );
		$this->open( $this->links()[0] );
		$this->assertGreaterThan( 0, get_current_user_id() );
	}

	public function test_use_another_address_goes_back_to_sign_in(): void {
		$this->trip();

		$_GET    = array( 'diluxone-users' => 'social-pending' );
		$_COOKIE = $this->held();
		$html    = diluxone_users_shortcode_login();

		$this->assertMatchesRegularExpression( '#<a [^>]*href="' . preg_quote( esc_url( diluxone_users_login_url() ), '#' ) . '"[^>]*>\s*Use another address#', $html );
	}
}
