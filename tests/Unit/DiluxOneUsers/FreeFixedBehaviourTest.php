<?php
/**
 * The six behaviours the plugin no longer offers as settings (#188).
 *
 * Each has one answer in the plugin, fixed, and the setting that would change
 * it is Users+ Pro's. Each is read through a filter whose default is that
 * answer: here, the answer with nothing hooked, the one an add-on gives when
 * it is hooked, and that a value saved before the setting left is not read.
 * What each answer does on a real site is walked by ProSeamsFixedTest.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FreeFixedBehaviourTest extends TestCase {

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
		require_once DILUXONE_USERS_DIR . 'includes/sso-providers.php';
		require_once DILUXONE_USERS_DIR . 'includes/sso.php';
		require_once DILUXONE_USERS_DIR . 'includes/handle.php';
		require_once DILUXONE_USERS_DIR . 'includes/reset.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields-missing.php';
		require_once DILUXONE_USERS_DIR . 'includes/theme-colors.php';

		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/account/' );
		Functions\when( 'current_user_can' )->justReturn( false );

		$GLOBALS['_test_wp_users'][ self::USER_ID ] = new \WP_User( self::USER_ID, array( 'administrator' ) );

		// What a site may have had saved before the settings left.
		update_option( 'diluxone_users_2fa_link', 'never' );
		update_option( 'diluxone_users_reset_ends_sessions', 1 );
		update_option( 'diluxone_users_sso_scope', 'some' );
		update_option( 'diluxone_users_sso_roles', array( 'subscriber' ) );
		update_option( 'diluxone_users_handle_cooldown', 30 );
		update_option( 'diluxone_users_ask_missing', 1 );
		update_option( 'diluxone_users_colors', 'theme' );

		// A required field and an account page, so asking is only the filter's to decide.
		update_option( 'diluxone_users_account_page', 12 );
		update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'      => 'first_name',
					'label'    => 'First name',
					'type'     => 'text',
					'required' => 1,
				),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** An add-on hooked to one filter, answering with whatever $answer returns. */
	private function answer( string $hook, callable $answer ): void {
		$GLOBALS['_test_wp_filters'][ $hook ] = $answer;
	}

	/* ── The plugin alone ──────────────────────────────────────────── */

	public function test_the_plugin_alone_has_one_answer_for_each(): void {
		$this->assertTrue( diluxone_users_2fa_worth_it_on_link( self::USER_ID ), 'the link asks the second step' );
		$this->assertTrue( diluxone_users_sso_role_allowed( self::USER_ID ), 'every role signs in with a network' );
		$this->assertTrue( diluxone_users_sso_role_allowed( 0, 'register' ), 'and every role gets an account by one' );
		$this->assertSame( 0, diluxone_users_handle_cooldown( self::USER_ID ), 'a public name changes whenever its owner likes' );
		$this->assertFalse( diluxone_users_ask_missing_on(), 'no step asks for the missing fields' );
		$this->assertFalse( diluxone_users_colors_from_theme(), 'the plugin’s own palette' );

	}

	/** None of the six is a setting: no default, no scope. */
	public function test_none_of_them_is_a_setting(): void {
		$keys = array(
			'diluxone_users_2fa_link',
			'diluxone_users_reset_ends_sessions',
			'diluxone_users_sso_scope',
			'diluxone_users_sso_roles',
			'diluxone_users_handle_cooldown',
			'diluxone_users_ask_missing',
			'diluxone_users_colors',
		);

		foreach ( $keys as $key ) {
			$this->assertArrayNotHasKey( $key, diluxone_users_option_defaults(), $key );
			$this->assertArrayNotHasKey( $key, diluxone_users_option_scopes(), $key );
		}
	}

	/* ── An add-on answering ───────────────────────────────────────── */

	public function test_an_add_on_answers_the_link(): void {
		$heard = array();
		$this->answer(
			'diluxone_users_2fa_on_link',
			static function ( bool $ask, int $user_id ) use ( &$heard ): bool {
				$heard = array( $ask, $user_id );

				return false;
			}
		);

		$this->assertFalse( diluxone_users_2fa_worth_it_on_link( self::USER_ID ) );
		$this->assertSame( array( true, self::USER_ID ), $heard, 'given the plugin’s answer and who' );
	}

	public function test_an_add_on_answers_who_signs_in_with_a_network(): void {
		$heard = array();
		$this->answer(
			'diluxone_users_sso_role_allowed',
			static function ( bool $allowed, int $user_id, string $context ) use ( &$heard ): bool {
				$heard[] = array( $allowed, $user_id, $context );

				return 'register' === $context;
			}
		);

		$this->assertFalse( diluxone_users_sso_role_allowed( self::USER_ID ) );
		$this->assertTrue( diluxone_users_sso_role_allowed( 0, 'register' ) );
		$this->assertSame( array( array( true, self::USER_ID, 'account' ), array( true, 0, 'register' ) ), $heard );
	}

	public function test_an_add_on_answers_how_often_a_name_changes(): void {
		$this->answer( 'diluxone_users_handle_cooldown', static fn( int $days, int $user_id ): int => self::USER_ID === $user_id ? 14 : $days );

		$this->assertSame( 14, diluxone_users_handle_cooldown( self::USER_ID ) );
		$this->assertSame( 0, diluxone_users_handle_cooldown( self::USER_ID + 1 ), 'the plugin’s answer for anybody else' );

		$this->answer( 'diluxone_users_handle_cooldown', static fn(): int => -5 );
		$this->assertSame( 0, diluxone_users_handle_cooldown( self::USER_ID ), 'never less than none' );
	}

	public function test_an_add_on_answers_whether_the_missing_fields_are_asked(): void {
		$this->answer( 'diluxone_users_ask_missing', static fn( bool $ask ): bool => ! $ask );

		$this->assertTrue( diluxone_users_ask_missing_on() );
	}

	public function test_an_add_on_answers_whose_colours(): void {
		$this->answer( 'diluxone_users_colors_from_theme', static fn( bool $theme ): bool => ! $theme );

		$this->assertTrue( diluxone_users_colors_from_theme() );
	}
}
