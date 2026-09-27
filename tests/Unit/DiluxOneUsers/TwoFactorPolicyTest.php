<?php
/**
 * The second-factor policy.
 *
 * It is the part where a mistake does not show: either the condition is wrong
 * and it is asked of everybody when it should not have been, or — worse — it
 * is asked of nobody and the site believes it is protected.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class TwoFactorPolicyTest extends TestCase {

	private const USER_ID = 7;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth-totp.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth-email.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';

		$GLOBALS['diluxone_users_test_user_meta'] = array();
		$GLOBALS['_test_wp_options']   = array();
		$GLOBALS['_test_wp_users']     = array();

		$this->settings( array() );
		$this->make_person();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Leaves the settings in a known state. */
	private function settings( array $values ): void {
		$base = array(
			'diluxone_users_2fa_mode'          => 'required',
			'diluxone_users_2fa_methods'       => array( 'email' ),
			'diluxone_users_2fa_roles'         => array(),
			'diluxone_users_2fa_link'          => 'auto',
			'diluxone_users_2fa_remember_days' => 0,
		);

		foreach ( array_merge( $base, $values ) as $key => $value ) {
			update_option( $key, $value );
		}
	}

	/** Somebody with whatever role is passed. */
	private function make_person( string $role = 'subscriber' ): void {
		$GLOBALS['_test_wp_users'][ self::USER_ID ] = new \WP_User( self::USER_ID, array( $role ) );
	}

	public function test_off_never_asks(): void {
		$this->settings( array( 'diluxone_users_2fa_mode' => 'off' ) );

		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_required_asks_everybody(): void {
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_with_no_methods_it_can_ask_for_nothing(): void {
		// Requiring something the person cannot give would lock them out of the site.
		$this->settings( array( 'diluxone_users_2fa_methods' => array() ) );

		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_with_email_as_the_only_second_step_the_link_does_not_ask_for_it(): void {
		// A code to the same inbox the person just opened to follow the link
		// proves nothing the link has not proved already.
		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'link' ) );
	}

	public function test_with_an_app_available_the_link_does_ask_for_it(): void {
		$this->settings( array( 'diluxone_users_2fa_methods' => array( 'email', 'totp' ) ) );
		update_user_meta( self::USER_ID, 'diluxone_users_totp', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' );

		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'link' ) );
	}

	public function test_the_site_can_force_both_answers(): void {
		$this->settings( array( 'diluxone_users_2fa_link' => 'always' ) );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'link' ) );

		$this->settings( array( 'diluxone_users_2fa_link' => 'never', 'diluxone_users_2fa_methods' => array( 'email', 'totp' ) ) );
		update_user_meta( self::USER_ID, 'diluxone_users_totp', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' );
		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'link' ) );
	}

	public function test_with_chosen_roles_only_those(): void {
		$this->settings( array( 'diluxone_users_2fa_scope' => 'some', 'diluxone_users_2fa_roles' => array( 'administrator' ) ) );

		$this->make_person( 'subscriber' );
		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );

		$this->make_person( 'administrator' );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_optional_only_asks_whoever_turned_it_on(): void {
		$this->settings( array( 'diluxone_users_2fa_mode' => 'optional' ) );

		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );

		update_user_meta( self::USER_ID, 'diluxone_users_2fa_on', 1 );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_with_the_link_as_the_only_door_it_is_asked_nowhere(): void {
		// It is the case where the screen says "you are not being asked": it has
		// to be true, and it only is when there is no other door.
		$this->settings( array( 'diluxone_users_login_method' => 'link' ) );

		$this->assertSame( array( 'link' ), array_keys( diluxone_users_2fa_ways( self::USER_ID ) ) );
		$this->assertSame( array(), diluxone_users_2fa_ways_asked( self::USER_ID ) );
	}

	public function test_with_the_password_on_the_link_is_skipped_but_the_password_is_not(): void {
		$this->settings( array( 'diluxone_users_login_method' => 'both' ) );

		$ways = diluxone_users_2fa_ways( self::USER_ID );

		$this->assertFalse( $ways['link']['asked'] );
		$this->assertTrue( $ways['password']['asked'] );
		$this->assertSame( array( 'your password' ), diluxone_users_2fa_ways_asked( self::USER_ID ) );
	}

	public function test_with_no_days_of_memory_no_browser_is_trusted(): void {
		$this->assertFalse( diluxone_users_2fa_trusted( self::USER_ID ) );
	}

	public function test_backup_codes_are_not_stored_in_the_clear(): void {
		$codes = diluxone_users_backup_generate( self::USER_ID, 4 );

		$this->assertCount( 4, $codes );
		$this->assertSame( 4, diluxone_users_backup_left( self::USER_ID ) );

		foreach ( $GLOBALS['diluxone_users_test_user_meta'][ self::USER_ID ]['diluxone_users_backup_codes'] as $saved ) {
			$this->assertNotContains( $saved, $codes );
		}
	}

	public function test_a_backup_code_works_only_once(): void {
		$codes = diluxone_users_backup_generate( self::USER_ID, 3 );

		$this->assertTrue( diluxone_users_backup_use( self::USER_ID, $codes[1] ) );
		$this->assertSame( 2, diluxone_users_backup_left( self::USER_ID ) );
		$this->assertFalse( diluxone_users_backup_use( self::USER_ID, $codes[1] ) );
	}

	public function test_a_made_up_backup_code_is_no_good(): void {
		diluxone_users_backup_generate( self::USER_ID, 3 );

		$this->assertFalse( diluxone_users_backup_use( self::USER_ID, 'noexiste00' ) );
		$this->assertSame( 3, diluxone_users_backup_left( self::USER_ID ) );
	}
}
