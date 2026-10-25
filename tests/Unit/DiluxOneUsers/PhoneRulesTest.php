<?php
/**
 * The rules a phone number is grouped and checked by, per country.
 *
 * Every country of the list has one, and nothing in the code names a
 * country: what is particular to each is in its row. A number is written as
 * its country writes it, the trunk prefix typed out of habit is not part of
 * it, and its length is checked against the country's national plan.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class PhoneRulesTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/data-countries.php';
		require_once DILUXONE_USERS_DIR . 'includes/data-phone.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_argentine_number_is_grouped_as_it_is_written_there(): void {
		$this->assertSame( '11 2345 6789', diluxone_users_phone_format( 'AR', '1123456789' ) );
		$this->assertSame( '11 2345 6789', diluxone_users_phone_format( 'ar', '11-2345 6789' ), 'whatever it was typed with' );
		$this->assertSame( '9 11 2345 6789', diluxone_users_phone_format( 'AR', '91123456789' ), 'a mobile, with its 9' );
		$this->assertSame( '11 2', diluxone_users_phone_format( 'AR', '112' ), 'still being typed: as the shortest number of the country starts' );
		$this->assertSame( '', diluxone_users_phone_format( 'AR', '' ) );
	}

	public function test_a_trunk_prefix_typed_out_of_habit_is_not_part_of_the_number(): void {
		$this->assertSame( '1123456789', diluxone_users_phone_national( 'AR', '011 2345-6789' ) );
		$this->assertSame( '11 2345 6789', diluxone_users_phone_format( 'AR', '011 2345 6789' ) );
		$this->assertSame( '2125551234', diluxone_users_phone_national( 'US', '1 (212) 555-1234' ), 'a 1 in the North American plan' );
		$this->assertSame( '612345678', diluxone_users_phone_national( 'FR', '06 12 34 56 78' ) );
		$this->assertSame( '612345678', diluxone_users_phone_national( 'FR', '6 12 34 56 78' ), 'without it, the same number' );
		$this->assertSame( '9123456789', diluxone_users_phone_national( 'RU', '8 912 345-67-89' ), 'an 8 in Russia' );
		$this->assertSame( '8001234567', diluxone_users_phone_national( 'RU', '800 123-45-67' ), 'a number that itself starts with the 8 is left whole' );
		$this->assertSame( '301234567', diluxone_users_phone_national( 'HU', '06 30 123 4567' ), 'a prefix of two digits' );
		$this->assertSame( '0612345678', diluxone_users_phone_national( 'IT', '06 1234 5678' ), 'where 0 is part of the number, it stays' );
		$this->assertSame( '012', diluxone_users_phone_national( 'AR', '012' ), 'too short to be a number without it: kept, and the check says so' );
	}

	public function test_a_country_without_a_grouping_of_its_own_is_written_in_threes_ending_in_four(): void {
		$this->assertSame( '212 555 1234', diluxone_users_phone_format( 'US', '2125551234' ) );
		$this->assertSame( array( 3, 4 ), diluxone_users_phone_groups( 'AG', 7 ) );
		$this->assertSame( array( 4, 4 ), diluxone_users_phone_groups( 'SG', 8 ) );
		$this->assertSame( array( 3, 3, 3 ), diluxone_users_phone_groups( 'CZ', 9 ) );
		$this->assertSame( array( 2, 3 ), diluxone_users_phone_groups( 'CK', 5 ) );
	}

	public function test_a_number_past_the_longest_is_written_as_the_longest_with_the_rest_after_it(): void {
		$this->assertSame( '9 11 2345 6789 0123', diluxone_users_phone_format( 'AR', '911234567890123' ) );
	}

	public function test_every_country_writes_a_number_of_each_of_its_lengths_without_losing_a_digit(): void {
		foreach ( array_keys( diluxone_users_countries() ) as $iso ) {
			$rule = diluxone_users_phone_rule( $iso );

			for ( $length = 1; $length <= $rule['max'] + 2; $length++ ) {
				$number = substr( str_repeat( '987654321', 3 ), 0, $length );
				$shown  = diluxone_users_phone_format( $iso, $number );

				$this->assertSame( $number, str_replace( ' ', '', $shown ), "{$iso}, {$length} digits: {$shown}" );
				$this->assertDoesNotMatchRegularExpression( '/^ | $|  /', $shown, "{$iso}, {$length} digits: spaces only between groups" );
			}

			for ( $length = $rule['min']; $length <= $rule['max']; $length++ ) {
				$this->assertSame( $length, array_sum( diluxone_users_phone_groups( $iso, $length ) ), "{$iso}: the groups of a {$length}-digit number add up to it" );
			}
		}
	}
}
