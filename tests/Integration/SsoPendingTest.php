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
}
