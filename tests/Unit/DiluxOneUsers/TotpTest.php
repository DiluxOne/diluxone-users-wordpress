<?php
/**
 * The authenticator-app second factor.
 *
 * It is tested against the RFC 6238 vectors, the same ones every
 * implementation in the world is tested with: if these pass, the code any app
 * shows is going to match the one the site expects. Without this, a one-bit
 * mistake is discovered the day somebody cannot get in.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase {

	use ResetsWpStubs;

	/** The secret from the RFC vectors: "12345678901234567890" in base32. */
	private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

	protected function setUp(): void {
		parent::setUp();
		require_once DILUXONE_USERS_DIR . 'includes/auth-totp.php';
	}

	/**
	 * @dataProvider vectors
	 */
	public function test_the_rfc_vectors( int $timestamp, string $expected ): void {
		$this->assertSame( $expected, diluxone_users_totp_code( self::SECRET, $timestamp ) );
	}

	/** @return array<int, array{int, string}> */
	public static function vectors(): array {
		return array(
			array( 59, '287082' ),
			array( 1111111109, '081804' ),
			array( 1111111111, '050471' ),
			array( 1234567890, '005924' ),
			array( 2000000000, '279037' ),
		);
	}

	public function test_base32_decodes_what_the_rfc_says(): void {
		$this->assertSame( '12345678901234567890', diluxone_users_base32_decode( self::SECRET ) );
	}

	public function test_base32_ignores_spaces_and_lowercase(): void {
		// The key is shown in groups of four so it can be typed; it has to be
		// accepted back exactly as a person copies it.
		$this->assertSame(
			diluxone_users_base32_decode( self::SECRET ),
			diluxone_users_base32_decode( strtolower( trim( chunk_split( self::SECRET, 4, ' ' ) ) ) )
		);
	}

	public function test_accepts_the_code_for_right_now(): void {
		$this->assertTrue( diluxone_users_totp_check( self::SECRET, diluxone_users_totp_code( self::SECRET ) ) );
	}

	public function test_accepts_one_window_of_drift(): void {
		// A clock thirty seconds out is the commonest thing in the world.
		$now = time();

		$this->assertTrue( diluxone_users_totp_check( self::SECRET, diluxone_users_totp_code( self::SECRET, $now - 30 ), $now ) );
		$this->assertTrue( diluxone_users_totp_check( self::SECRET, diluxone_users_totp_code( self::SECRET, $now + 30 ), $now ) );
		$this->assertTrue( diluxone_users_totp_check( self::SECRET, diluxone_users_totp_code( self::SECRET, $now + 29 ), $now ) );
	}

	public function test_it_rejects_the_first_step_outside_the_window(): void {
		$now  = 1700000010; // Ten seconds into a step: no boundary nearby.
		$edge = 60; // Two steps of thirty seconds: the window is one step either side, and no more.

		foreach ( array( $now - $edge, $now + $edge ) as $when ) {
			$code = diluxone_users_totp_code( self::SECRET, $when );

			// The same six digits can turn up in a step inside the window by
			// chance; that step is not this one.
			if ( diluxone_users_totp_code( self::SECRET, $now ) === $code || diluxone_users_totp_code( self::SECRET, $now - 30 ) === $code || diluxone_users_totp_code( self::SECRET, $now + 30 ) === $code ) {
				continue;
			}

			$this->assertFalse( diluxone_users_totp_check( self::SECRET, $code, $now ), 'one step past the drift' );
		}

		$this->assertFalse( diluxone_users_totp_check( self::SECRET, diluxone_users_totp_code( self::SECRET, $now - 300 ), $now ) );
	}

	public function test_a_code_typed_with_separators_counts_and_one_of_seven_digits_does_not(): void {
		$now  = time();
		$code = diluxone_users_totp_code( self::SECRET, $now );

		$this->assertTrue( diluxone_users_totp_check( self::SECRET, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), $now ) );
		$this->assertTrue( diluxone_users_totp_check( self::SECRET, substr( $code, 0, 3 ) . '-' . substr( $code, 3 ), $now ) );
		$this->assertFalse( diluxone_users_totp_check( self::SECRET, $code . '1', $now ) );
	}

	public function test_it_rejects_anything(): void {
		$this->assertFalse( diluxone_users_totp_check( self::SECRET, '000000' ) );
		$this->assertFalse( diluxone_users_totp_check( self::SECRET, '12345' ) );
		$this->assertFalse( diluxone_users_totp_check( self::SECRET, '' ) );
		$this->assertFalse( diluxone_users_totp_check( self::SECRET, 'abcdef' ) );
	}

	public function test_a_fresh_secret_is_base32_of_the_requested_length(): void {
		$secret = diluxone_users_totp_secret_new( 32 );

		$this->assertSame( 32, strlen( $secret ) );
		$this->assertSame( 1, preg_match( '/^[A-Z2-7]+$/', $secret ) );
	}

	public function test_two_fresh_secrets_are_not_equal(): void {
		$this->assertNotSame( diluxone_users_totp_secret_new(), diluxone_users_totp_secret_new() );
	}
}
