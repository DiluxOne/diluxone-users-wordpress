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

	/** The whole trip to the provider and back, signed out: where it sends the person. */
	private function trip(): string {
		$this->request(
			array(
				'diluxone_users_sso' => MockProvider::ID,
				'diluxone_users_go'  => '1',
			)
		);

		$out    = $this->expectRedirect( 'diluxone_users_sso_handle' );
		$cookie = self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '';

		$this->request(
			array(
				'diluxone_users_sso' => MockProvider::ID,
				'code'               => 'the-code',
				'state'              => $this->queryArg( $out, 'state' ),
			),
			array( diluxone_users_sso_cookie() => $cookie )
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

	/** The account behind the identity, or 0. */
	private function owner(): int {
		return diluxone_users_sso_owner( MockProvider::ID, $this->sub );
	}

	/**
	 * Opens a confirmation link: where it sends the person.
	 *
	 * In a browser with no cookie of the trip unless one is passed: the link
	 * works in any browser, like the sign-in link.
	 *
	 * @param array<string, string> $cookie
	 */
	private function open( string $link, array $cookie = array() ): string {
		$_COOKIE = $cookie;
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
		$cookie = array(
			DILUXONE_USERS_SSO_PENDING_COOKIE => self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'],
			DILUXONE_USERS_SSO_PENDING_SHOWN  => self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'],
		);

		$this->open( $this->links()[0], $cookie );

		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_SSO_PENDING_COOKIE ]['value'] );
		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_SSO_PENDING_SHOWN ]['value'] );
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
}
