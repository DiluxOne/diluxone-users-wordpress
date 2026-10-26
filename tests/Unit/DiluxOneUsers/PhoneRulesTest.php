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

	public function test_every_country_has_a_rule_that_holds_together(): void {
		$rules = diluxone_users_phone_rules();

		$this->assertSame( array(), array_values( array_diff( array_keys( diluxone_users_countries() ), array_keys( $rules ) ) ), 'every country of the list has its row' );
		$this->assertSame( array(), array_values( array_diff( array_keys( $rules ), array_keys( diluxone_users_countries() ) ) ), 'and no row is for a country the list does not have' );

		foreach ( $rules as $iso => $row ) {
			list( $min, $max, $group, $trunk ) = $row;

			$this->assertGreaterThanOrEqual( 4, $min, "{$iso}: no number is shorter than four digits" );
			$this->assertLessThanOrEqual( $max, $min, "{$iso}: the shortest is not longer than the longest" );
			$this->assertLessThanOrEqual( 15, $max + strlen( diluxone_users_country_dial( $iso ) ), "{$iso}: E.164 numbers have at most fifteen digits, dialling code included" );
			$this->assertMatchesRegularExpression( '/^(\d+( \d+)*(\|\d+( \d+)*)*)?$/', $group, "{$iso}: groups of digits, patterns apart by |" );
			$this->assertMatchesRegularExpression( '/^\d{0,2}$/', $trunk, "{$iso}: a trunk prefix is a digit or two, or none" );

			foreach ( '' === $group ? array() : explode( '|', $group ) as $pattern ) {
				$sum = array_sum( array_map( 'intval', explode( ' ', $pattern ) ) );

				$this->assertTrue( $sum >= $min && $sum <= $max, "{$iso}: the pattern {$pattern} is for a length the country has" );
			}
		}
	}

	public function test_a_number_too_short_or_too_long_for_its_country_says_which(): void {
		$this->assertSame( '', diluxone_users_phone_check( 'AR', '11 2345 6789' ) );
		$this->assertSame( '', diluxone_users_phone_check( 'AR', '9 11 2345 6789' ), 'a mobile with its 9' );
		$this->assertSame( '', diluxone_users_phone_check( 'AR', '011 2345 6789' ), 'with the trunk prefix typed out of habit' );
		$this->assertSame( 'short', diluxone_users_phone_check( 'AR', '12345' ) );
		$this->assertSame( 'long', diluxone_users_phone_check( 'AR', '123456789012' ) );
		$this->assertSame( '', diluxone_users_phone_check( 'AR', '' ), 'nothing typed is no wrong length' );
		$this->assertSame( '', diluxone_users_phone_check( 'AR', ' - ' ) );
		$this->assertSame( 'short', diluxone_users_phone_check( 'US', '212 555 123' ) );
		$this->assertSame( '', diluxone_users_phone_check( 'US', '1 212 555 1234' ) );
		$this->assertSame( 'long', diluxone_users_phone_check( 'AG', '268 464 1234' ), 'the area code is already in this dialling code' );
		$this->assertSame( '', diluxone_users_phone_check( 'AG', '464 1234' ) );

		foreach ( diluxone_users_phone_rules() as $iso => $row ) {
			$this->assertSame( 'short', diluxone_users_phone_check( $iso, str_repeat( '5', $row[0] - 1 ) ), "{$iso}: one digit short" );
			$this->assertSame( '', diluxone_users_phone_check( $iso, str_repeat( '5', $row[0] ) ), "{$iso}: the shortest" );
			$this->assertSame( '', diluxone_users_phone_check( $iso, str_repeat( '5', $row[1] ) ), "{$iso}: the longest" );
			$this->assertSame( 'long', diluxone_users_phone_check( $iso, str_repeat( '5', $row[1] + 1 ) ), "{$iso}: one digit too many" );
		}
	}

	public function test_a_country_the_table_does_not_have_is_held_to_e164_alone(): void {
		$this->assertSame( array( 'min' => 4, 'max' => 15, 'group' => '', 'trunk' => '' ), diluxone_users_phone_rule( 'ZZ' ) );
		$this->assertSame( 'short', diluxone_users_phone_check( 'ZZ', '123' ) );
		$this->assertSame( 'long', diluxone_users_phone_check( 'ZZ', '1234567890123456' ) );
		$this->assertSame( '', diluxone_users_phone_check( 'ZZ', '12345678' ) );
	}
}
