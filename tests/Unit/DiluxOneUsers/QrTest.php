<?php
/**
 * The QR code generator.
 *
 * A badly built QR does not look wrong: it looks the same. That is why what
 * is tested here is the structure — size, finder patterns, timing patterns,
 * fixed module — and not that it "returns something". The proof that it also
 * scans is done by a real reader, outside this suite.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use PHPUnit\Framework\TestCase;

class QrTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		require_once DILUXONE_USERS_DIR . 'includes/qr.php';
	}

	public function test_the_version_grows_with_the_text(): void {
		// The side is 17 + 4 × version.
		$this->assertCount( 21, diluxone_users_qr_matrix( 'hola' ) );
		$this->assertCount( 45, diluxone_users_qr_matrix( str_repeat( 'a', 150 ) ) );
		$this->assertCount( 57, diluxone_users_qr_matrix( str_repeat( 'a', 260 ) ) );
	}

	/**
	 * The error correction is the standard's: the worked example of the
	 * specification ("HELLO WORLD", version 1, level M) gives these ten bytes.
	 */
	public function test_the_error_correction_matches_the_standards_example(): void {
		$this->assertSame(
			array( 196, 35, 39, 119, 235, 215, 231, 226, 93, 23 ),
			diluxone_users_qr_ec( array( 32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17 ), 10 )
		);
	}

	/**
	 * The whole symbol for one `otpauth://` address is the one a real reader
	 * decoded (see the fixture's header). The structural tests below would
	 * pass with a wrong mask or a broken error correction; this one would not.
	 */
	public function test_the_symbol_is_the_one_a_reader_decoded(): void {
		$lines   = file( DILUXONE_USERS_DIR . 'tests/Unit/fixtures/qr-otpauth.txt', FILE_IGNORE_NEW_LINES );
		$address = substr( (string) $lines[1], 2 );
		$rows    = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line && '#' !== $line[0] ) );
		$drawn   = array_map( static fn( array $row ): string => implode( '', array_map( static fn( bool $dark ): string => $dark ? '1' : '0', $row ) ), (array) diluxone_users_qr_matrix( $address ) );

		$this->assertSame( $rows, $drawn );
	}

	/** Where one version ends and the next begins, and what is a byte. */
	public function test_the_version_boundaries_and_multibyte_text(): void {
		$this->assertCount( 21, diluxone_users_qr_matrix( '' ) );
		$this->assertCount( 21, diluxone_users_qr_matrix( str_repeat( 'a', 17 ) ) );
		$this->assertCount( 25, diluxone_users_qr_matrix( str_repeat( 'a', 18 ) ) );
		$this->assertCount( 57, diluxone_users_qr_matrix( str_repeat( 'a', 271 ) ) );
		$this->assertNull( diluxone_users_qr_matrix( str_repeat( 'a', 272 ) ) );
		$this->assertCount( 25, diluxone_users_qr_matrix( str_repeat( 'ñ', 9 ) ), 'eighteen bytes, not nine letters' );
	}

	public function test_what_does_not_fit_returns_null(): void {
		$this->assertNull( diluxone_users_qr_matrix( str_repeat( 'a', 500 ) ) );
	}

	public function test_the_three_finder_patterns_are_there(): void {
		$m    = diluxone_users_qr_matrix( 'otpauth://totp/x?secret=ABCDEFGHIJKLMNOP' );
		$side = count( $m );

		foreach ( array( array( 0, 0 ), array( 0, $side - 7 ), array( $side - 7, 0 ) ) as [$row, $col] ) {
			// The outer ring is black and the inner border white.
			$this->assertTrue( $m[ $row ][ $col ] );
			$this->assertTrue( $m[ $row ][ $col + 6 ] );
			$this->assertTrue( $m[ $row + 6 ][ $col ] );
			$this->assertFalse( $m[ $row + 1 ][ $col + 1 ] );
			$this->assertTrue( $m[ $row + 3 ][ $col + 3 ] );
		}
	}

	public function test_the_timing_patterns_alternate(): void {
		$m    = diluxone_users_qr_matrix( 'hola' );
		$side = count( $m );

		for ( $i = 8; $i < $side - 8; $i++ ) {
			$this->assertSame( 0 === $i % 2, $m[6][ $i ] );
			$this->assertSame( 0 === $i % 2, $m[ $i ][6] );
		}
	}

	public function test_the_fixed_module_is_black(): void {
		$m = diluxone_users_qr_matrix( 'hola' );

		$this->assertTrue( $m[ count( $m ) - 8 ][8] );
	}

	public function test_the_svg_carries_the_quiet_zone_the_standard_asks_for(): void {
		$svg = diluxone_users_qr_svg( 'hola', 200 );

		// 21 modules + 4 of margin on each side.
		$this->assertStringContainsString( 'viewBox="0 0 29 29"', $svg );
		$this->assertStringContainsString( 'width="200"', $svg );
	}

	public function test_with_no_room_it_returns_no_svg(): void {
		$this->assertSame( '', diluxone_users_qr_svg( str_repeat( 'a', 500 ) ) );
	}
}
