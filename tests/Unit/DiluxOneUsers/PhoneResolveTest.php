<?php
/**
 * The country a phone number belongs to, read on the server as the browser
 * reads it: a number written with "+" or "00" picks its country by the
 * longest dialling code it starts with.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class PhoneResolveTest extends TestCase {

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

	public function test_an_international_number_picks_its_country(): void {
		$this->assertSame( array( 'CL', '961234567' ), diluxone_users_phone_resolve( 'AR', '+56 9 6123 4567' ) );
		$this->assertSame( array( 'AR', '91141234567' ), diluxone_users_phone_resolve( 'CL', '00 54 9 11 4123 4567' ) );
		$this->assertSame( array( 'AG', '4641234' ), diluxone_users_phone_resolve( 'US', '+1 268 464 1234' ), 'the longest code wins' );
	}

	public function test_a_shared_code_keeps_the_country_picked_when_it_has_it(): void {
		$this->assertSame( array( 'CA', '4165551234' ), diluxone_users_phone_resolve( 'CA', '+1 416 555 1234' ) );
		$this->assertSame( array( 'KZ', '7012345678' ), diluxone_users_phone_resolve( 'kz', '+7 701 234 5678' ) );
	}

	public function test_a_shared_code_picks_the_first_in_the_list_when_the_country_picked_has_another(): void {
		$first = '';

		foreach ( diluxone_users_countries() as $iso => $data ) {
			if ( '1' === $data[1] ) {
				$first = $iso;
				break;
			}
		}

		$this->assertSame( array( $first, '4165551234' ), diluxone_users_phone_resolve( 'AR', '+1 416 555 1234' ) );
	}

	public function test_a_code_no_country_has_is_left_as_written(): void {
		$this->assertSame( array( 'AR', '+999 123 456' ), diluxone_users_phone_resolve( 'AR', '+999 123 456' ) );
	}

	public function test_a_national_number_is_left_unchanged(): void {
		$this->assertSame( array( 'AR', '011 4123 4567' ), diluxone_users_phone_resolve( 'AR', '011 4123 4567' ) );
		$this->assertSame( array( 'AR', '11 4123 4567' ), diluxone_users_phone_resolve( 'AR', '11 4123 4567' ) );
	}
}
