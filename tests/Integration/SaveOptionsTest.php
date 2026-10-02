<?php
/**
 * What diluxone_users_save_options() keeps, for each shape a setting has.
 *
 * Every screen's save goes through it, and so does a settings file restored
 * from Tools, which skips the screens' own checks. So it is the last place a
 * value sent in the wrong shape can be stopped: a list where a word belongs,
 * a list inside a list, a key nobody registered. Each is either cleaned into
 * the setting's shape or left out — never stored as the string "Array", and
 * never a PHP warning.
 *
 * The settings used are the hub's (sign-in words, its order, colours), written on the
 * main site, which is the hub on a network: the same on both topologies.
 */

namespace Tests\Integration;

class SaveOptionsTest extends IntegrationTestCase {

	/** @var array<int, string> Warnings raised while saving. */
	private array $warnings = array();

	/**
	 * Saves, and keeps every warning PHP raised on the way.
	 *
	 * @param array<string, mixed> $input
	 */
	private function save( array $input ): void {
		set_error_handler(
			function ( int $level, string $message ): bool {
				$this->warnings[] = $message;

				return true;
			}
		);

		try {
			diluxone_users_save_options( $input );
		} finally {
			restore_error_handler();
		}
	}

	public function test_a_key_nobody_registered_is_not_written(): void {
		$this->save( array( 'diluxone_users_nope' => 'x' ) );

		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_nope' ) );
		$this->assertFalse( get_option( 'diluxone_users_nope' ) );
	}

	public function test_a_list_is_kept_clean_once_each_in_its_order(): void {
		$this->save( array( 'diluxone_users_login_order' => array( 'Pass word', 'email', '<b>sso</b>', 'email' ) ) );

		$this->assertSame( array( 'password', 'email', 'bssob' ), diluxone_users_raw_get( 'diluxone_users_login_order' ) );
	}

	public function test_a_map_keeps_its_keys_cleaned(): void {
		$this->save( array( 'diluxone_users_color_map' => array( 'Bad Key' => 'V!' ) ) );

		$this->assertSame( array( 'badkey' => 'v' ), diluxone_users_raw_get( 'diluxone_users_color_map' ) );
	}

	public function test_a_word_keeps_no_markup_unless_it_may(): void {
		$this->save( array( 'diluxone_users_login_title' => '<b>Hi</b><script>x</script>' ) );

		$this->assertSame( 'Hi', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
	}

	/**
	 * A list where a word belongs is not a word: the setting keeps what it had.
	 * Cast as it came it was stored as "Array" — on the sign-in page, as its
	 * title — with a warning in the log.
	 */
	public function test_a_list_where_a_word_belongs_leaves_the_word_alone(): void {
		diluxone_users_update_option( 'diluxone_users_login_title', 'Welcome' );

		$this->save( array( 'diluxone_users_login_title' => array( 'x' ) ) );

		$this->assertSame( 'Welcome', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
		$this->assertSame( array(), $this->warnings );
	}

	/** A list where a number belongs is not 1. */
	public function test_a_list_where_a_number_belongs_leaves_the_number_alone(): void {
		diluxone_users_update_option( 'diluxone_users_avatar_max_kb', 512 );

		$this->save( array( 'diluxone_users_avatar_max_kb' => array( 99 ) ) );

		$this->assertSame( 512, (int) diluxone_users_raw_get( 'diluxone_users_avatar_max_kb' ) );
	}

	/** A list inside a list is dropped, and says nothing on the way out. */
	public function test_a_list_inside_a_list_is_dropped_without_a_warning(): void {
		$this->save( array( 'diluxone_users_login_order' => array( 'email', array( 'password' ) ) ) );

		$this->assertSame( array( 'email' ), diluxone_users_raw_get( 'diluxone_users_login_order' ) );
		$this->assertSame( array(), $this->warnings );
	}

	/** A word where a list belongs is a list of one. */
	public function test_a_word_where_a_list_belongs_is_a_list_of_one(): void {
		$this->save( array( 'diluxone_users_login_order' => 'password' ) );

		$this->assertSame( array( 'password' ), diluxone_users_raw_get( 'diluxone_users_login_order' ) );
	}
}
