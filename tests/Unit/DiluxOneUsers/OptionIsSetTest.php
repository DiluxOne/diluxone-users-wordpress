<?php
/**
 * A setting is decided when somebody decided it, and not before.
 *
 * `diluxone_users_option()` answers the default when nothing is stored, so a
 * screen reading through it cannot tell "nobody chose this" from "somebody
 * chose the default". `diluxone_users_option_is_set()` can: a stored value,
 * the default included, is a decision; nothing stored is not. A value pinned
 * from code through the filter is one too, which the integration suite
 * covers (NotConfiguredTest): the stubs here run no filters.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class OptionIsSetTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/multisite.php';
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_multisite']  = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_nothing_stored_is_not_decided(): void {
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'off', diluxone_users_option( 'diluxone_users_2fa_mode' ), 'the default still answers' );
	}

	public function test_a_value_saved_equal_to_the_default_is_decided(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$this->assertTrue( diluxone_users_option_is_set( 'diluxone_users_2fa_mode' ) );
	}

	public function test_zero_and_an_empty_list_are_decisions_too(): void {
		diluxone_users_update_option( 'diluxone_users_session_long_days', 0 );
		diluxone_users_update_option( 'diluxone_users_log_levels', array() );

		$this->assertTrue( diluxone_users_option_is_set( 'diluxone_users_session_long_days' ) );
		$this->assertTrue( diluxone_users_option_is_set( 'diluxone_users_log_levels' ) );
	}

	public function test_deleting_it_undoes_the_decision(): void {
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );
		diluxone_users_delete_option( 'diluxone_users_sso_login' );

		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_sso_login' ) );
	}

	public function test_a_row_over_several_settings_is_decided_once_any_of_them_is(): void {
		$keys = array( 'diluxone_users_privacy_export', 'diluxone_users_privacy_delete' );

		$this->assertFalse( diluxone_users_options_any_set( $keys ) );

		diluxone_users_update_option( 'diluxone_users_privacy_delete', 0 );

		$this->assertTrue( diluxone_users_options_any_set( $keys ) );
	}
}
