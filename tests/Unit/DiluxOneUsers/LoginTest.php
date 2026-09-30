<?php
/**
 * The sign-in link is the site's only credential: if it fails, either anybody
 * gets in or nobody does. These tests pin down the four properties that make
 * it safe.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class LoginTest extends TestCase {

	private const USER_ID = 4242;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		$GLOBALS['diluxone_users_test_user_meta'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_freshly_created_token_is_valid(): void {
		$token = diluxone_users_token_create( self::USER_ID );

		$this->assertTrue( diluxone_users_token_valid( self::USER_ID, $token ) );
	}

	public function test_the_token_is_not_stored_in_the_clear(): void {
		// If it were stored as it is, anybody with access to the database could
		// sign in as anybody without even touching their e-mail.
		$token = diluxone_users_token_create( self::USER_ID );

		$this->assertNotSame(
			$token,
			$GLOBALS['diluxone_users_test_user_meta'][ self::USER_ID ][ DILUXONE_USERS_META_HASH ],
			'What is stored must be the hash, never the token'
		);
	}

	public function test_a_token_belonging_to_someone_else_is_no_good(): void {
		diluxone_users_token_create( self::USER_ID );

		$this->assertFalse( diluxone_users_token_valid( self::USER_ID, 'made-up-token' ) );
	}

	public function test_somebody_elses_token_is_no_good(): void {
		$mine = diluxone_users_token_create( self::USER_ID );
		diluxone_users_token_create( 9999 );

		$this->assertFalse(
			diluxone_users_token_valid( 9999, $mine ),
			'A valid link cannot let anybody into a different account'
		);
	}

	public function test_the_token_expires(): void {
		$token = diluxone_users_token_create( self::USER_ID );

		// The clock is moved forward by putting the expiry in the past.
		$GLOBALS['diluxone_users_test_user_meta'][ self::USER_ID ][ DILUXONE_USERS_META_EXPIRES ] = time() - 1;

		$this->assertFalse( diluxone_users_token_valid( self::USER_ID, $token ) );
	}

	public function test_the_token_works_only_once(): void {
		$token = diluxone_users_token_create( self::USER_ID );
		$this->assertTrue( diluxone_users_token_valid( self::USER_ID, $token ) );

		diluxone_users_token_burn( self::USER_ID );

		$this->assertFalse(
			diluxone_users_token_valid( self::USER_ID, $token ),
			'A forwarded or leaked link cannot open the session again'
		);
	}

	public function test_with_no_stored_token_nothing_validates(): void {
		// The case of somebody who never asked for a link: there is no hash to compare against.
		$this->assertFalse( diluxone_users_token_valid( self::USER_ID, 'anything-at-all' ) );
	}

	public function test_every_token_is_different(): void {
		$a = diluxone_users_token_create( self::USER_ID );
		$b = diluxone_users_token_create( self::USER_ID );

		$this->assertNotSame( $a, $b );
		$this->assertFalse(
			diluxone_users_token_valid( self::USER_ID, $a ),
			'Asking for a new link must invalidate the previous one'
		);
	}
}
