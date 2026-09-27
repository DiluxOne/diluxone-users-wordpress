<?php
/**
 * A user meta that holds a list reads as a list, empty when there is none.
 *
 * `(array) get_user_meta( $id, $key, true )` turned "no row" ('') into a list
 * with one empty entry, and read as sessions that entry was a session started
 * at the Unix epoch: "57 years ago" on the profile of somebody who never
 * signed in.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class MetaListTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		// The shared stub answers get_user_meta() from this array.
		$GLOBALS['diluxone_users_test_user_meta'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_row_is_an_empty_list(): void {
		$this->assertSame( array(), diluxone_users_meta_list( 7, 'session_tokens' ) );
	}

	public function test_a_stored_list_comes_back_as_it_is(): void {
		$list = array( 'abc' => array( 'login' => 1790000000 ) );
		$GLOBALS['diluxone_users_test_user_meta'][7]['session_tokens'] = $list;
		$this->assertSame( $list, diluxone_users_meta_list( 7, 'session_tokens' ) );
	}

	public function test_a_scalar_that_is_not_a_list_is_no_list(): void {
		$GLOBALS['diluxone_users_test_user_meta'][7]['session_tokens'] = 'garbage';
		$this->assertSame( array(), diluxone_users_meta_list( 7, 'session_tokens' ) );
	}
}
