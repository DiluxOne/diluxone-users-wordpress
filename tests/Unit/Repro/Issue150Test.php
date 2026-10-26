<?php
/**
 * Issue #150: the phone field misreads numbers as people type them.
 *
 * Argentina's mobiles are written nationally with a trunk prefix and a local
 * mobile token ("011 15 4123 4567"), and their area codes are 2, 3 or 4
 * digits long, so a fixed grouping is wrong for some of them.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class Issue150Test extends TestCase {

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

	public function test_an_argentine_mobile_with_trunk_prefix_and_15_is_accepted_not_too_long(): void {
		$this->assertSame( '', diluxone_users_phone_check( 'AR', '011 15 4123 4567' ), 'Buenos Aires, 011 15 …' );
		$this->assertSame( '', diluxone_users_phone_check( 'AR', '0221 15 412-3456' ), 'La Plata, 0221 15 …' );
	}

	public function test_a_three_digit_area_code_is_never_grouped_as_two(): void {
		$this->assertNotSame( '22 1412 3456', diluxone_users_phone_format( 'AR', '2214123456' ), '221 is the area code, not 22' );
	}
}
