<?php
/**
 * The second-factor policy.
 *
 * It is the part where a mistake does not show: either the condition is wrong
 * and it is asked of everybody when it should not have been, or — worse — it
 * is asked of nobody and the site believes it is protected.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class TwoFactorPolicyTest extends TestCase {

	use ResetsWpStubs;

	private const USER_ID = 7;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth-email.php';
		require_once DILUXONE_USERS_DIR . 'includes/auth.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-door.php';

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

	public function test_safe_mode_never_asks(): void {
		// The link asks it, so that only safe mode can make it a no.
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'link' ), 'the control: asked, without the switch' );

		// DILUXONE_USERS_SAFE_MODE: WordPress's own sign-in and nothing after it.
		\Brain\Monkey\Functions\when( 'diluxone_users_safe_mode' )->justReturn( true );

		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'link' ) );
		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'sso' ) );
	}

	public function test_without_safe_mode_the_constant_is_not_there(): void {
		$this->assertFalse( defined( 'DILUXONE_USERS_SAFE_MODE' ) );
		$this->assertFalse( diluxone_users_safe_mode() );
	}

	public function test_required_asks_everybody(): void {
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_with_no_methods_it_can_ask_for_nothing(): void {
		// Requiring something the person cannot give would lock them out of the site.
		$this->settings( array( 'diluxone_users_2fa_methods' => array() ) );

		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	/**
	 * The link asks it as every other way in does, even when the code goes
	 * back to the inbox the person just opened: working that out is Users+
	 * Pro's "Let it decide", not the plugin's.
	 */
	public function test_with_email_as_the_only_second_step_the_link_still_asks_for_it(): void {
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'link' ) );
	}

	/**
	 * A site whose only ticked method is one nothing registers — Users+ Pro's
	 * app, with Pro gone — still asks the second step: the e-mail code stands
	 * in, so nobody it protected signs in with the password alone, and nobody
	 * who had only the app is locked out.
	 */
	public function test_a_method_nothing_registers_leaves_the_email_code_in_its_place(): void {
		$this->settings( array( 'diluxone_users_2fa_methods' => array( 'totp' ) ) );

		$this->assertSame( array( 'email' ), array_keys( diluxone_users_2fa_methods() ) );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );

		// Ticked beside one there is, it changes nothing.
		$this->settings( array( 'diluxone_users_2fa_methods' => array( 'totp', 'email' ) ) );
		$this->assertSame( array( 'email' ), array_keys( diluxone_users_2fa_methods() ) );

		// And nothing ticked is still nothing: the site chose that.
		$this->settings( array( 'diluxone_users_2fa_methods' => array() ) );
		$this->assertSame( array(), diluxone_users_2fa_methods() );
	}

	/**
	 * A rule for the link saved before it left the plugin is not read: a
	 * site that had "Let it decide" or "never" saved asks it, as "always".
	 */
	public function test_a_rule_for_the_link_saved_before_is_not_read_and_the_link_asks(): void {
		foreach ( array( 'auto', 'never', 'always' ) as $saved ) {
			$this->settings( array( 'diluxone_users_2fa_link' => $saved ) );

			$this->assertTrue( diluxone_users_2fa_worth_it_on_link( self::USER_ID ), $saved );
			$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'link' ), $saved );
		}
	}

	/**
	 * Roles chosen while Users+ Pro was on are not read: the plugin asks it of
	 * everybody, and nobody it was required of goes without it.
	 */
	public function test_roles_chosen_with_pro_are_not_read_and_everybody_is_asked(): void {
		$this->settings( array( 'diluxone_users_2fa_scope' => 'some', 'diluxone_users_2fa_roles' => array( 'administrator' ) ) );

		$this->make_person( 'subscriber' );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );

		$this->make_person( 'administrator' );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_optional_only_asks_whoever_turned_it_on(): void {
		$this->settings( array( 'diluxone_users_2fa_mode' => 'optional' ) );

		$this->assertFalse( diluxone_users_2fa_required( self::USER_ID, 'password' ) );

		update_user_meta( self::USER_ID, 'diluxone_users_2fa_on', 1 );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ) );
	}

	public function test_with_the_link_as_the_only_door_it_is_asked_on_the_link(): void {
		$this->settings( array( 'diluxone_users_login_method' => 'link' ) );

		$this->assertSame( array( 'link' ), array_keys( diluxone_users_2fa_ways( self::USER_ID ) ) );
		$this->assertSame( array( 'the link we email you' ), diluxone_users_2fa_ways_asked( self::USER_ID ) );
	}

	public function test_with_the_password_on_both_ways_in_ask_it(): void {
		$this->settings( array( 'diluxone_users_login_method' => 'both' ) );

		$ways = diluxone_users_2fa_ways( self::USER_ID );

		$this->assertTrue( $ways['link']['asked'] );
		$this->assertTrue( $ways['password']['asked'] );
		$this->assertSame( array( 'the link we email you', 'your password' ), diluxone_users_2fa_ways_asked( self::USER_ID ) );
	}

	/** Nothing remembered lets anybody past the second step: that is an add-on's to answer. */
	public function test_nothing_skips_the_second_step(): void {
		$this->assertFalse( diluxone_users_2fa_skip( self::USER_ID, 'password' ) );
		$this->assertTrue( diluxone_users_2fa_required( self::USER_ID, 'password' ), 'and the step is asked' );
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

	/** Typed the way people type it — in capitals, with a dash or a space — it still counts. */
	public function test_a_backup_code_is_read_the_way_people_type_it(): void {
		$codes = diluxone_users_backup_generate( self::USER_ID, 3 );

		$this->assertTrue( diluxone_users_backup_use( self::USER_ID, ' ' . strtoupper( substr( $codes[0], 0, 5 ) . '-' . substr( $codes[0], 5 ) ) . ' ' ) );
		$this->assertTrue( diluxone_users_backup_use( self::USER_ID, substr( $codes[1], 0, 5 ) . ' ' . substr( $codes[1], 5 ) ) );
		$this->assertSame( 1, diluxone_users_backup_left( self::USER_ID ) );
	}

	public function test_backup_codes_are_ten_letters_or_digits_and_a_new_set_replaces_the_old(): void {
		$old = diluxone_users_backup_generate( self::USER_ID, 3 );
		$new = diluxone_users_backup_generate( self::USER_ID, 3 );

		foreach ( $new as $code ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]{10}$/', $code );
		}

		$this->assertFalse( diluxone_users_backup_use( self::USER_ID, $old[0] ), 'the old set is gone' );
		$this->assertTrue( diluxone_users_backup_use( self::USER_ID, $new[0] ) );
	}

	public function test_a_made_up_backup_code_is_no_good(): void {
		diluxone_users_backup_generate( self::USER_ID, 3 );

		$this->assertFalse( diluxone_users_backup_use( self::USER_ID, 'noexiste00' ) );
		$this->assertSame( 3, diluxone_users_backup_left( self::USER_ID ) );
	}
}
