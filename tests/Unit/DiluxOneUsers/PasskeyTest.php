<?php
/**
 * Verifying a passkey.
 *
 * A mistake here breaks nothing visible: it simply lets in somebody who
 * should not get in. The three checks that make a passkey worth anything are
 * tested — the origin, the domain and the presence of the person — and that
 * the challenge cannot be used twice.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class PasskeyTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth-passkeys.php';

		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();

		update_option( 'diluxone_users_passkey_verify', 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The `authenticatorData` an authenticator would return. */
	private function auth_data( string $rp_id, int $flags = 0x01, int $counter = 0 ): string {
		return hash( 'sha256', $rp_id, true ) . chr( $flags ) . pack( 'N', $counter );
	}

	public function test_base64url_round_trips(): void {
		$bytes = random_bytes( 64 );

		$this->assertSame( $bytes, diluxone_users_b64url_decode( diluxone_users_b64url_encode( $bytes ) ) );
	}

	public function test_base64url_carries_no_padding_or_url_characters(): void {
		$text = diluxone_users_b64url_encode( random_bytes( 31 ) );

		$this->assertSame( 0, preg_match( '/[+\/=]/', $text ) );
	}

	public function test_accepts_data_from_the_right_domain(): void {
		$this->assertNotNull( diluxone_users_passkey_auth_data( $this->auth_data( diluxone_users_passkey_rp_id() ) ) );
	}

	public function test_it_rejects_those_from_another_domain(): void {
		// It is what stops a signature made for another site working here.
		$this->assertNull( diluxone_users_passkey_auth_data( $this->auth_data( 'otro-sitio.com' ) ) );
	}

	public function test_it_rejects_when_nobody_was_present(): void {
		// Without that flag, the signature could have been asked for by a process alone.
		$this->assertNull( diluxone_users_passkey_auth_data( $this->auth_data( diluxone_users_passkey_rp_id(), 0x00 ) ) );
	}

	public function test_it_rejects_something_shorter_than_possible(): void {
		$this->assertNull( diluxone_users_passkey_auth_data( 'corto' ) );
	}

	public function test_it_requires_user_verification_when_the_site_asks_for_it(): void {
		update_option( 'diluxone_users_passkey_verify', 1 );

		// Presence only: not enough.
		$this->assertNull( diluxone_users_passkey_auth_data( $this->auth_data( diluxone_users_passkey_rp_id(), 0x01 ) ) );

		// Presence and verification.
		$this->assertNotNull( diluxone_users_passkey_auth_data( $this->auth_data( diluxone_users_passkey_rp_id(), 0x05 ) ) );
	}

	public function test_returns_the_counter(): void {
		$data = diluxone_users_passkey_auth_data( $this->auth_data( diluxone_users_passkey_rp_id(), 0x01, 42 ) );

		$this->assertSame( 42, $data['counter'] );
	}

	public function test_a_challenge_works_only_once(): void {
		$challenge = diluxone_users_passkey_challenge_new( 'log' );

		$this->assertTrue( diluxone_users_passkey_challenge_use( 'log', $challenge ) );
		$this->assertFalse( diluxone_users_passkey_challenge_use( 'log', $challenge ) );
	}

	public function test_a_registration_challenge_is_no_good_for_signing_in(): void {
		// If it worked, somebody could reuse one screen's on the other.
		$challenge = diluxone_users_passkey_challenge_new( 'reg' );

		$this->assertFalse( diluxone_users_passkey_challenge_use( 'log', $challenge ) );
	}

	public function test_a_made_up_challenge_is_no_good(): void {
		$this->assertFalse( diluxone_users_passkey_challenge_use( 'log', 'cualquier-cosa' ) );
	}

	public function test_accepts_well_formed_client_data(): void {
		$challenge = diluxone_users_passkey_challenge_new( 'log' );

		$json = wp_json_encode( array(
			'type'      => 'webauthn.get',
			'challenge' => $challenge,
			'origin'    => diluxone_users_passkey_origin(),
		) );

		$data = diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' );

		$this->assertSame( $challenge, $data['challenge'] ?? null );
		$this->assertNull( diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' ), 'the same answer twice: the challenge was spent' );
	}

	/** @return array<string, array{0: string}> */
	public static function not_client_data(): array {
		return array(
			'not JSON'          => array( 'nope' ),
			'a JSON word'       => array( '"x"' ),
			'with no challenge' => array( '{"type":"webauthn.get"}' ),
		);
	}

	/** @dataProvider not_client_data */
	public function test_what_is_not_client_data_is_refused( string $json ): void {
		$this->assertNull( diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' ) );
	}

	public function test_it_rejects_another_origin(): void {
		// It is what stops a cloned site using this one's passkey.
		$challenge = diluxone_users_passkey_challenge_new( 'log' );

		$json = wp_json_encode( array(
			'type'      => 'webauthn.get',
			'challenge' => $challenge,
			'origin'    => 'https://sitio-falso.example',
		) );

		$this->assertNull( diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' ) );
	}

	public function test_it_rejects_the_wrong_operation(): void {
		$challenge = diluxone_users_passkey_challenge_new( 'log' );

		$json = wp_json_encode( array(
			'type'      => 'webauthn.create',
			'challenge' => $challenge,
			'origin'    => diluxone_users_passkey_origin(),
		) );

		$this->assertNull( diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' ) );
	}

	public function test_it_rejects_a_challenge_we_did_not_issue(): void {
		$json = wp_json_encode( array(
			'type'      => 'webauthn.get',
			'challenge' => diluxone_users_b64url_encode( random_bytes( 32 ) ),
			'origin'    => diluxone_users_passkey_origin(),
		) );

		$this->assertNull( diluxone_users_passkey_client_data( $json, 'webauthn.get', 'log' ) );
	}

	/**
	 * A real P-256 key and a real signature over what WebAuthn signs.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string} DER public key, authenticator data, client JSON, signature.
	 */
	private function signed(): array {
		$key  = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		$pem  = (string) openssl_pkey_get_details( $key )['key'];
		$der  = (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s/', '', $pem ) );
		$auth = $this->auth_data( 'example.test', 0x05, 7 );
		$json = '{"type":"webauthn.get","challenge":"abc"}';

		openssl_sign( $auth . hash( 'sha256', $json, true ), $signature, $key, OPENSSL_ALGO_SHA256 );

		return array( $der, $auth, $json, (string) $signature );
	}

	public function test_a_signature_from_the_key_over_what_was_signed_is_good(): void {
		[ $der, $auth, $json, $signature ] = $this->signed();

		$this->assertTrue( diluxone_users_passkey_signature_ok( $der, -7, $auth, $json, $signature ) );
	}

	public function test_a_signature_over_anything_else_is_not(): void {
		[ $der, $auth, $json, $signature ] = $this->signed();

		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -7, $auth . 'x', $json, $signature ), 'other data' );
		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -7, $auth, $json . ' ', $signature ), 'another client' );
		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -7, $auth, $json, substr( $signature, 0, -1 ) . chr( ord( substr( $signature, -1 ) ) ^ 1 ) ), 'a byte changed' );
		// openssl_verify() answers -1 on an error, which is not a yes.
		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -7, $auth, $json, 'not a signature' ), 'not even a signature' );
	}

	public function test_it_rejects_a_signature_from_an_algorithm_it_does_not_verify(): void {
		[ $der, $auth, $json, $signature ] = $this->signed();

		// The key and the signature are good: only the algorithm is not one
		// this verifies, and that alone is a no.
		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -37, $auth, $json, $signature ) );
	}
}
