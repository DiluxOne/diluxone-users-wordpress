<?php
/**
 * A control that does not apply right now is not a task left to do.
 *
 * `diluxone_users_not_now()` used to wear the "Pending" pill, which on these
 * screens means something is half set up and waits on somebody. A section that
 * simply does not apply while the site is set up the way it is waits on
 * nobody, so it wears a state of its own: "Not in use now".
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class NotNowTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/templates.php';
		require_once DILUXONE_USERS_DIR . 'includes/admin-state.php';

		// WordPress, which the unit bootstrap does not stub.
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'wp_kses_allowed_html' )->justReturn( array_fill_keys( array( 'a', 'img', 'p', 'span' ), array() ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_state_has_a_word_of_its_own(): void {
		$states = diluxone_users_states();

		$this->assertArrayHasKey( 'notnow', $states );
		$this->assertSame( 'Not in use now', $states['notnow'] );
	}

	public function test_the_line_wears_not_in_use_now_and_never_pending(): void {
		ob_start();
		diluxone_users_not_now( 'The ways in are stacked.', 'https://example.test/fix', 'Fix it' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="diluxone-users-not-now"', $html );
		$this->assertStringContainsString( 'diluxone-users-state--notnow', $html );
		$this->assertStringContainsString( 'Not in use now', $html );
		$this->assertStringNotContainsString( 'diluxone-users-state--pending', $html, 'not a task left to do' );
		$this->assertStringContainsString( 'The ways in are stacked.', $html );
		$this->assertStringContainsString( 'href="https://example.test/fix"', $html );
	}
}
