<?php
/**
 * The step that asks for the required fields an account has no answer for.
 *
 * What is decided here is where a sign-in sends somebody: their destination,
 * or the step carrying it. The step itself and the doors that ask are walked
 * by the integration and end-to-end suites.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class MissingFieldsTest extends TestCase {

	use ResetsWpStubs;

	private const USER_ID = 7;

	private const ACCOUNT = 'https://example.test/account/';

	private const GOING = 'https://example.test/course/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields-missing.php';

		Functions\when( 'get_permalink' )->justReturn( self::ACCOUNT );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER_ID );

		$GLOBALS['_test_wp_users'][ self::USER_ID ] = new \WP_User( self::USER_ID, array( 'subscriber' ) );

		update_option( 'diluxone_users_account_page', 12 );
		$this->first_name_required( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The starter fields, with the first name required or not. */
	private function first_name_required( bool $required ): void {
		update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'      => 'first_name',
					'label'    => 'First name',
					'type'     => 'text',
					'required' => $required ? 1 : 0,
				),
				array(
					'key'   => 'last_name',
					'label' => 'Last name',
					'type'  => 'text',
				),
			)
		);
	}

	/** The query of an address, as an array. */
	private function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	public function test_a_required_field_with_no_answer_sends_to_the_step_carrying_the_destination(): void {
		$url = diluxone_users_ask_missing_url( self::USER_ID, self::GOING );

		$this->assertStringStartsWith( self::ACCOUNT, $url );
		$this->assertSame( '1', $this->query( $url )['diluxone-users-complete'] ?? '' );
		$this->assertSame( self::GOING, rawurldecode( $this->query( $url )['redirect_to'] ?? '' ) );
		$this->assertSame( array( 'first_name' ), array_column( diluxone_users_missing_required( self::USER_ID ), 'key' ), 'only the required one is asked' );
	}

	/** C3: every required field filled, straight to the destination. */
	public function test_every_required_field_filled_goes_straight_to_the_destination(): void {
		update_user_meta( self::USER_ID, 'first_name', 'Ada' );

		$this->assertSame( array(), diluxone_users_missing_required( self::USER_ID ) );
		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** An answer of spaces is no answer. */
	public function test_an_answer_of_spaces_is_still_missing(): void {
		update_user_meta( self::USER_ID, 'first_name', '   ' );

		$this->assertNotSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** C4: no field required, no step, whoever signs in. */
	public function test_with_no_field_required_the_step_never_appears(): void {
		$this->first_name_required( false );

		$this->assertFalse( diluxone_users_has_required_fields() );
		$this->assertFalse( diluxone_users_ask_missing_on() );
		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** C5: the setting off, no step. */
	public function test_with_the_setting_off_the_step_does_not_appear(): void {
		update_option( 'diluxone_users_ask_missing', 0 );

		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** On by default: nothing stored, and it acts as soon as a field is required. */
	public function test_the_setting_is_on_by_default(): void {
		$this->assertSame( 1, diluxone_users_option_defaults()['diluxone_users_ask_missing'] );
		$this->assertSame( 'network', diluxone_users_option_scopes()['diluxone_users_ask_missing'] );
		$this->assertTrue( diluxone_users_ask_missing_on() );
	}

	/** With no account page there is nowhere to ask, and nobody is sent anywhere new. */
	public function test_with_no_account_page_the_step_is_skipped(): void {
		update_option( 'diluxone_users_account_page', 0 );

		$this->assertFalse( diluxone_users_ask_missing_on() );
		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** A field the person may not change is not asked of them: the save would refuse it. */
	public function test_a_field_they_cannot_edit_is_not_asked(): void {
		$fields              = (array) get_option( 'diluxone_users_fields' );
		$fields[0]['edit']   = 'never';
		update_option( 'diluxone_users_fields', $fields );

		$this->assertSame( array(), diluxone_users_missing_required( self::USER_ID ) );
		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** A hidden field is not asked either. */
	public function test_a_hidden_required_field_is_not_asked(): void {
		$fields              = (array) get_option( 'diluxone_users_fields' );
		$fields[0]['active'] = 0;
		update_option( 'diluxone_users_fields', $fields );

		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( self::USER_ID, self::GOING ) );
	}

	/** Nobody is nobody's step. */
	public function test_nobody_is_sent_nowhere_new(): void {
		$this->assertSame( self::GOING, diluxone_users_ask_missing_url( 0, self::GOING ) );
	}

	/** A password sign-in that failed is left as WordPress had it. */
	public function test_a_failed_password_is_left_alone(): void {
		$this->assertSame( self::GOING, diluxone_users_ask_missing_password( self::GOING, '', new \WP_Error( 'x', 'no' ) ) );
		$this->assertNotSame( self::GOING, diluxone_users_ask_missing_password( self::GOING, '', $GLOBALS['_test_wp_users'][ self::USER_ID ] ) );
	}
}
