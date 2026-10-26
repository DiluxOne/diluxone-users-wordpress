<?php
/**
 * The address a social sign-in waits on, as the holding screen shows it.
 *
 * It came from the provider, not from what the person typed, so the screen
 * shows enough of it to recognise and no more: the first letter of the name,
 * the first of the domain and what follows its last dot.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class SsoPendingTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_address_shows_its_first_letters_and_its_ending(): void {
		$this->assertSame( 'a•••@e•••.com', diluxone_users_mask_email( 'ana.perez@example.com' ) );
	}

	public function test_only_the_last_part_of_the_domain_is_shown(): void {
		$this->assertSame( 'j•••@m•••.uk', diluxone_users_mask_email( 'john@mail.example.co.uk' ) );
	}

	public function test_a_one_letter_name_hides_nothing_more_than_it_has(): void {
		$this->assertSame( 'a•••@x•••.io', diluxone_users_mask_email( 'a@x.io' ) );
	}

	public function test_a_domain_with_no_dot_keeps_only_its_first_letter(): void {
		$this->assertSame( 'r•••@l•••', diluxone_users_mask_email( 'root@localhost' ) );
	}

	public function test_what_is_not_an_address_is_hidden_whole(): void {
		$this->assertSame( '•••', diluxone_users_mask_email( 'not-an-address' ) );
		$this->assertSame( '•••', diluxone_users_mask_email( '@example.com' ) );
		$this->assertSame( '•••', diluxone_users_mask_email( 'ana@' ) );
		$this->assertSame( '', diluxone_users_mask_email( '' ) );
	}

	public function test_the_full_address_never_comes_through(): void {
		$masked = diluxone_users_mask_email( 'someone.private@secret-domain.org' );

		$this->assertStringNotContainsString( 'someone', $masked );
		$this->assertStringNotContainsString( 'secret', $masked );
	}
}
