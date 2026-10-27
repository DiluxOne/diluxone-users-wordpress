<?php
/**
 * Issue #199: the server does not read a phone number written with + or 00.
 *
 * The browser reads a number that starts with `+` or `00` as international.
 * A form sent without JavaScript must be stored the same way: the longest
 * dialling code that matches picks the country, whatever `<key>_dial` says.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue199Test extends TestCase {

	use ResetsWpStubs;

	private const USER_ID = 7;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/core-meta.php';
		require_once DILUXONE_USERS_DIR . 'includes/data-countries.php';
		require_once DILUXONE_USERS_DIR . 'includes/data-phone.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields.php';

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER_ID );

		update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'   => 'mobile',
					'label' => 'Mobile',
					'type'  => 'phone',
				),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The form as a browser without JavaScript sends it: Argentina picked, the number as typed. */
	private function saved_from_argentina( string $typed ): string {
		diluxone_users_save(
			self::USER_ID,
			array(
				'mobile'      => $typed,
				'mobile_dial' => 'AR',
			)
		);

		return (string) get_user_meta( self::USER_ID, 'mobile', true );
	}

	public function test_a_number_written_with_a_plus_is_read_as_international_whatever_country_is_picked(): void {
		$this->assertSame( '+5491141234567', $this->saved_from_argentina( '+54 9 11 4123 4567' ), 'C1: the picked country\'s own code' );

		$this->assertSame( '+56961234567', $this->saved_from_argentina( '+56 9 6123 4567' ), 'C3: a code that is not the picked country\'s' );
	}

	public function test_a_number_written_with_00_is_read_as_international_whatever_country_is_picked(): void {
		$this->assertSame( '+5491141234567', $this->saved_from_argentina( '0054 9 11 4123 4567' ), 'C2' );
	}
}
