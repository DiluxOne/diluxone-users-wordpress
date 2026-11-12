<?php
/**
 * What the wordpress.org listing of 1.0.0 shows.
 *
 * The listing is built from readme.txt and .wordpress-org/, and is read by
 * somebody deciding whether to install the plugin: a first release, not the
 * log of how it was built. These read the files the listing is made of.
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReadmeListingTest extends TestCase {

	private static function readme(): string {
		$readme = file_get_contents( DILUXONE_USERS_DIR . 'readme.txt' );

		self::assertIsString( $readme );

		return str_replace( "\r\n", "\n", $readme );
	}

	/**
	 * The lines of the `= 1.0.0 =` entry, up to the next entry or the end.
	 *
	 * @return list<string>
	 */
	private static function first_release(): array {
		$readme = self::readme();

		self::assertSame( 1, preg_match( '/^= 1\.0\.0 =\n(.*?)(?=^= |\z)/ms', $readme, $entry ) );

		return explode( "\n", trim( $entry[1] ) );
	}

	/**
	 * Whether 1.0.0 is still waiting to be published: its entry is the
	 * newest and starts with the maintainer's `Unreleased.` line. The pull
	 * request that removes the line publishes it, and from there the
	 * fragments of later changes wait in `changelog.d/` for the next entry.
	 */
	private static function first_release_is_unpublished( string $readme ): bool {
		return 1 === preg_match( '/^== Changelog ==\n\s*= 1\.0\.0 =\nUnreleased\.\n/m', $readme );
	}

	public function test_c1_the_first_release_says_it_is_the_first(): void {
		$lines = self::first_release();

		if ( self::first_release_is_unpublished( self::readme() ) ) {
			$this->assertSame( 'Unreleased.', array_shift( $lines ) );
		}

		$lines = array_values( array_filter( $lines, static fn ( string $line ): bool => '' !== $line ) );
		$this->assertSame( 'First public release.', $lines[0] );
	}

	public function test_c1_the_pins_hold_only_until_the_maintainer_publishes_1_0_0(): void {
		$entry = "\n\nFirst public release.\n\n* User fields.\n";

		$this->assertTrue( self::first_release_is_unpublished( "== Changelog ==\n\n= 1.0.0 =\nUnreleased." . $entry ) );
		$this->assertFalse( self::first_release_is_unpublished( "== Changelog ==\n\n= 1.0.0 =" . $entry ) );
		$this->assertFalse( self::first_release_is_unpublished( "== Changelog ==\n\n= 1.0.1 =\nUnreleased.\n\n* A fix.\n\n= 1.0.0 =\nUnreleased." . $entry ) );
	}

	public function test_c1_the_first_release_names_what_it_brings_in_eight_bullets_or_fewer(): void {
		$bullets = array_values(
			array_filter(
				self::first_release(),
				static fn ( string $line ): bool => str_starts_with( $line, '* ' )
			)
		);

		$this->assertNotEmpty( $bullets );
		$this->assertLessThanOrEqual( 8, count( $bullets ) );

		foreach ( $bullets as $bullet ) {
			foreach ( array( 'no longer', 'instead of', 'as before', 'now ', 'after the update' ) as $change ) {
				$this->assertStringNotContainsStringIgnoringCase( $change, $bullet, 'A first release changed nothing: ' . $bullet );
			}
		}
	}

	public function test_c2_no_fragment_waits_to_be_added_to_the_first_release(): void {
		$fragments = glob( DILUXONE_USERS_DIR . 'changelog.d/*.md' );

		if ( ! self::first_release_is_unpublished( self::readme() ) ) {
			// Published: a later change's fragment waits for the next entry.
			$this->assertIsArray( $fragments );
			return;
		}

		$this->assertSame( array(), $fragments );
	}

	public function test_c3_the_readme_is_short_enough_to_be_read(): void {
		$this->assertLessThan( 14000, strlen( self::readme() ) );
	}

	/**
	 * A caption as the readme and the spec both write it: no capital to
	 * start, one kind of apostrophe, no full stop to end.
	 */
	private static function caption( string $caption ): string {
		$caption = rtrim( str_replace( "\u{2019}", "'", trim( $caption ) ), '.' );

		return strtolower( substr( $caption, 0, 1 ) ) . substr( $caption, 1 );
	}

	public function test_c5_each_of_the_thirteen_pictures_has_the_caption_its_spec_gives_it(): void {
		$readme = self::readme();

		$this->assertSame( 1, preg_match( '/^== Screenshots ==\n(.*?)(?=^== )/ms', $readme, $section ) );
		preg_match_all( '/^(\d+)\. (.+)$/m', $section[1], $lines, PREG_SET_ORDER );

		$readme_captions = array();
		foreach ( $lines as $line ) {
			$readme_captions[ (int) $line[1] ] = self::caption( $line[2] );
		}

		$this->assertSame( range( 1, 13 ), array_keys( $readme_captions ) );

		$spec = file_get_contents( DILUXONE_USERS_DIR . 'tests/e2e/specs/listing-screenshots.spec.ts' );
		$this->assertIsString( $spec );

		// The dashboard's pictures, from the SCREENS table; the rest, from the
		// titles of their own tests.
		preg_match_all( "/n: (\\d+),\\s*screen: '[^']*',\\s*tab: '[^']*',\\s*caption: '([^']*)'/", $spec, $rows, PREG_SET_ORDER );
		preg_match_all( "/test\\('(\\d+) · ([^']*)'/u", $spec, $titles, PREG_SET_ORDER );

		$spec_captions = array();
		foreach ( array_merge( $rows, $titles ) as $match ) {
			$spec_captions[ (int) $match[1] ] = self::caption( $match[2] );
		}
		ksort( $spec_captions );

		$this->assertSame( $spec_captions, $readme_captions );

		foreach ( array_keys( $readme_captions ) as $n ) {
			$this->assertFileExists( DILUXONE_USERS_DIR . ".wordpress-org/screenshot-{$n}.png" );
		}
	}
}
