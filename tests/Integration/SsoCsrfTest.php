<?php
/**
 * H-02: the return from a social network has to land in the browser — and
 * on the person — that started the trip.
 *
 * The attack it pins down: the attacker starts a trip on their own browser,
 * takes the callback URL the network sends them back to, and makes a
 * signed-in victim open it. Before, the plugin linked the attacker's Google
 * to the victim's account. The whole round trip is played here against the
 * mock provider, request by request.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class SsoCsrfTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		MockProvider::install();

		// One identity, one account: links an earlier test left behind would
		// make this identity somebody else's before the test begins.
		delete_metadata( 'user', 0, 'diluxone_users_sso_' . MockProvider::ID, 'attacker-google-id', true );

		MockProvider::$profile = array(
			'sub'            => 'attacker-google-id',
			'email'          => 'attacker@example.test',
			'email_verified' => true,
			'given_name'     => 'Mallory',
		);
	}

	protected function tearDown(): void {
		MockProvider::remove();
		parent::tearDown();
	}

	/**
	 * Starts the trip as somebody (or nobody) and returns what the browser
	 * would hold on the way out: the state in the URL and the cookie.
	 *
	 * @return array{state: string, cookie: string}
	 */
	private function start( int $user_id, bool $with_nonce = true ): array {
		wp_set_current_user( $user_id );

		$_GET = array(
			'diluxone_users_sso' => MockProvider::ID,
			'diluxone_users_go'  => '1',
		);

		if ( $user_id > 0 && $with_nonce ) {
			$_GET['diluxone_users_nonce'] = wp_create_nonce( 'diluxone_users_sso_link_' . MockProvider::ID );
		}

		diluxone_users_sso_query_snapshot();

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		return array(
			'state'  => $this->queryArg( $url, 'state' ),
			'cookie' => self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '',
		);
	}

	/** Plays the network's return, in a browser holding this cookie, as this person. */
	private function come_back( int $user_id, string $state, string $cookie ): string {
		wp_set_current_user( $user_id );

		$_COOKIE = '' === $cookie ? array() : array( diluxone_users_sso_cookie() => $cookie );
		$_GET    = array(
			'diluxone_users_sso' => MockProvider::ID,
			'code'               => 'the-code',
			'state'              => $state,
		);

		diluxone_users_sso_query_snapshot();

		return $this->expectRedirect( 'diluxone_users_sso_handle' );
	}

	public function test_the_trip_leaves_a_state_in_the_url_and_a_secret_in_the_browser(): void {
		$trip = $this->start( 0 );

		$this->assertNotSame( '', $trip['state'] );
		$this->assertNotSame( '', $trip['cookie'] );
		$this->assertNotSame( $trip['state'], $trip['cookie'], 'The cookie must not be the state: the state is in the URL for anybody to read' );
		$this->assertSame( 'Lax', self::$cookies[ diluxone_users_sso_cookie() ]['options']['samesite'] );
	}

	public function test_the_same_browser_signs_in(): void {
		$trip = $this->start( 0 );
		$url  = $this->come_back( 0, $trip['state'], $trip['cookie'] );

		$this->assertSame( '', $this->redirectState( $url ) );
		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertSame( 'attacker@example.test', wp_get_current_user()->user_email );
	}

	public function test_a_signed_in_victim_opening_the_attackers_callback_links_nothing(): void {
		$victim = $this->make_user();
		$trip   = $this->start( 0 ); // The attacker, signed in as nobody.

		// The victim's browser has no cookie from a trip it never made — and
		// even handing it the attacker's cookie changes nothing, because the
		// trip was started by nobody and ends with somebody.
		foreach ( array( '', $trip['cookie'] ) as $cookie ) {
			$url = $this->come_back( $victim, $trip['state'], $cookie );

			$this->assertSame( 'social', $this->redirectState( $url ) );
			$this->assertSame( '', get_user_meta( $victim, 'diluxone_users_sso_mock', true ), 'The attacker\'s network must not be linked to the victim' );
		}
	}

	public function test_a_trip_started_by_one_account_cannot_end_in_another(): void {
		$attacker = $this->make_user();
		$victim   = $this->make_user();
		$trip     = $this->start( $attacker );

		$url = $this->come_back( $victim, $trip['state'], $trip['cookie'] );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( '', get_user_meta( $victim, 'diluxone_users_sso_mock', true ) );
	}

	public function test_a_signed_out_victim_opening_the_attackers_callback_is_not_signed_in_as_the_attacker(): void {
		// Login CSRF: the victim would end up inside the attacker's account
		// and type their things into it.
		$trip = $this->start( 0 );
		$url  = $this->come_back( 0, $trip['state'], '' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_the_same_person_in_the_same_browser_links_the_network(): void {
		$user = $this->make_user();
		$trip = $this->start( $user );
		$url  = $this->come_back( $user, $trip['state'], $trip['cookie'] );

		$this->assertSame( 'linked', $this->redirectState( $url ) );
		$this->assertSame( 'attacker-google-id', get_user_meta( $user, 'diluxone_users_sso_mock', true ) );
	}

	public function test_linking_needs_the_nonce_from_the_account_screen(): void {
		// Without it, any page could start the trip for a signed-in person.
		$user = $this->make_user();
		wp_set_current_user( $user );

		$_GET = array(
			'diluxone_users_sso' => MockProvider::ID,
			'diluxone_users_go'  => '1',
		);
		diluxone_users_sso_query_snapshot();

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( array(), MockProvider::$requests, 'No trip should have started' );
	}

	public function test_the_account_screen_link_carries_that_nonce(): void {
		wp_set_current_user( $this->make_user() );

		$url = diluxone_users_sso_link_url( MockProvider::ID );

		$this->assertSame( '1', $this->queryArg( $url, 'diluxone_users_go' ) );
		$this->assertSame( 1, wp_verify_nonce( $this->queryArg( $url, 'diluxone_users_nonce' ), 'diluxone_users_sso_link_' . MockProvider::ID ) );
	}

	public function test_a_state_works_once(): void {
		$trip = $this->start( 0 );
		$this->come_back( 0, $trip['state'], $trip['cookie'] );

		wp_set_current_user( 0 );
		$url = $this->come_back( 0, $trip['state'], $trip['cookie'] );

		$this->assertSame( 'social', $this->redirectState( $url ) );
	}

	public function test_a_made_up_state_fails_before_any_request_leaves(): void {
		$url = $this->come_back( 0, 'made-up', 'whatever' );

		$this->assertSame( 'social', $this->redirectState( $url ) );
		$this->assertSame( array(), MockProvider::$requests );
	}
}
