<?php
/**
 * The countries the "country" and "phone" fields offer.
 *
 * What is stored is the ISO code and nothing else, so the list has to be one a
 * code can be trusted against: every key two capital letters, every country
 * with a name and a dialling code made of digits, none twice. And the order
 * people see it in puts the site's own countries first.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class CountriesTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/data-countries.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_every_country_is_a_two_letter_code_with_a_name_and_a_dialling_code(): void {
		$countries = diluxone_users_countries();

		$this->assertGreaterThan( 190, count( $countries ), 'the whole world, not a sample' );

		foreach ( $countries as $iso => $country ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $iso );
			$this->assertNotSame( '', $country[0], "{$iso} has a name" );
			$this->assertMatchesRegularExpression( '/^\d{1,4}$/', $country[1], "{$iso}'s dialling code is digits, without the +" );
		}

		$this->assertSame( array_unique( array_column( $countries, 0 ) ), array_column( $countries, 0 ), 'no country twice' );
	}

	public function test_a_dialling_code_is_found_by_its_code_in_either_case(): void {
		$this->assertSame( '54', diluxone_users_country_dial( 'AR' ) );
		$this->assertSame( '54', diluxone_users_country_dial( 'ar' ) );
		$this->assertSame( '1268', diluxone_users_country_dial( 'AG' ) );
		$this->assertSame( '', diluxone_users_country_dial( 'ZZ' ), 'an unknown code has none' );
		$this->assertSame( '', diluxone_users_country_dial( '' ) );
	}

	public function test_sorted_by_name_with_the_preferred_ones_first(): void {
		$sorted = diluxone_users_countries_sorted( array( 'uy', 'AR', 'ZZ' ) );

		$this->assertSame( array( 'UY', 'AR' ), array_slice( array_keys( $sorted ), 0, 2 ), 'the preferred, in the order given; an unknown one ignored' );
		$this->assertSame( 'Uruguay', $sorted['UY'] );
		$this->assertCount( count( diluxone_users_countries() ), $sorted, 'every country once, the preferred not repeated below' );

		$rest = array_slice( $sorted, 2 );

		$this->assertArrayNotHasKey( 'UY', array_slice( $sorted, 2, null, true ) );

		// By name as a person reads it, accents and all: Côte d'Ivoire between
		// Costa Rica and Croatia, not after Cuba.
		$order = array_keys( $rest );
		$this->assertLessThan( array_search( 'CI', $order, true ), array_search( 'CR', $order, true ) );
		$this->assertLessThan( array_search( 'HR', $order, true ), array_search( 'CI', $order, true ) );
		$this->assertLessThan( array_search( 'CU', $order, true ), array_search( 'HR', $order, true ) );
		$this->assertSame( 'AF', $order[0], 'Afghanistan first' );
	}

	public function test_without_preferred_ones_it_is_the_list_by_name(): void {
		$sorted = diluxone_users_countries_sorted();

		$this->assertSame( 'AF', array_key_first( $sorted ) );
		$this->assertCount( count( diluxone_users_countries() ), $sorted );
	}
}
