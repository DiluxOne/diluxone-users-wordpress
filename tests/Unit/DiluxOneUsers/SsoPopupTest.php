<?php
/**
 * What a social popup tells the page that opened it, and the address it opens.
 *
 * `in` lets that page reload where it is, signed in; `next` sends it through
 * the resume address to wherever the whole window would have gone. Only a
 * plain sign-in to the default destination, begun away from the plugin's own
 * doors, is `in`.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class SsoPopupTest extends TestCase {

	use ResetsWpStubs;

	private const HOME = 'https://example.test/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/sso-popup.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_plain_sign_in_from_a_page_that_is_not_a_door_reloads_in_place(): void {
		$this->assertSame( 'in', diluxone_users_sso_popup_kind( true, false, self::HOME, self::HOME ) );
		$this->assertSame( 'in', diluxone_users_sso_popup_kind( true, false, 'https://example.test', self::HOME ), 'the trailing slash is the same front page' );
	}

	public function test_a_sign_in_begun_on_a_door_goes_on_to_the_destination(): void {
		$this->assertSame( 'next', diluxone_users_sso_popup_kind( true, true, self::HOME, self::HOME ) );
	}

	public function test_a_destination_of_its_own_is_followed(): void {
		// A return held for another site of the network, or a filter's choice.
		$this->assertSame( 'next', diluxone_users_sso_popup_kind( true, false, 'https://example.test/site-two/', self::HOME ) );
		$this->assertSame( 'next', diluxone_users_sso_popup_kind( true, false, 'https://example.test/welcome/', self::HOME ) );
	}

	public function test_a_return_that_opened_no_session_goes_on(): void {
		// The error, the second step (no session yet), a link from the account.
		$this->assertSame( 'next', diluxone_users_sso_popup_kind( false, false, self::HOME, self::HOME ) );
		$this->assertSame( 'next', diluxone_users_sso_popup_kind( false, false, 'https://example.test/login/?diluxone-users=social', self::HOME ) );
	}

	public function test_the_required_fields_step_goes_on(): void {
		$step = 'https://example.test/account/?diluxone_users_complete=1&redirect_to=https%3A%2F%2Fexample.test%2F';

		$this->assertSame( 'next', diluxone_users_sso_popup_kind( true, false, $step, self::HOME ) );
	}

	public function test_the_popup_opens_the_buttons_own_address_saying_it_is_a_popup(): void {
		$this->assertSame(
			'https://example.test/sso/google/?diluxone_users_go=1&diluxone_users_popup=1',
			diluxone_users_sso_popup_url( 'https://example.test/sso/google/?diluxone_users_go=1', false )
		);
		$this->assertSame(
			'https://example.test/sso/google/?diluxone_users_go=1&diluxone_users_popup=door',
			diluxone_users_sso_popup_url( 'https://example.test/sso/google/?diluxone_users_go=1', true )
		);
		$this->assertSame(
			'https://example.test/sso/google/?diluxone_users_popup=1',
			diluxone_users_sso_popup_url( 'https://example.test/sso/google/', false )
		);
	}
}
