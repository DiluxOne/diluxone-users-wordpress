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

	public function test_c1_the_first_release_starts_unreleased_and_says_it_is_the_first(): void {
		$lines = self::first_release();

		$this->assertSame( 'Unreleased.', $lines[0] );
		$this->assertContains( 'First public release.', $lines );
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
		$this->assertSame( array(), glob( DILUXONE_USERS_DIR . 'changelog.d/*.md' ) );
	}
}
