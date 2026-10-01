<?php
/**
 * The limit that belongs to the account, not to one attempt.
 *
 * `TwoFactorFlowTest` covers the five tries inside a challenge. This one
 * covers what was missing behind it: starting another challenge costs whoever
 * has the password a single request, so a limit that dies with the attempt is
 * not a limit at all. Somebody who knows the password could sit on a million
 * six-digit codes, five at a time, for as long as they liked.
 *
 * What is asserted here is that the count follows the account across
 * challenges, that only a code that is right clears it, that a locked account
 * refuses even the correct code, and that knocking while locked does not push
 * the wait further out — otherwise the lock becomes a way of keeping the
 * owner out.
 */

namespace Tests\Integration;

class TwoFactorLockoutTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		delete_user_meta( $this->user, 'diluxone_users_2fa_fails' );
		delete_user_meta( $this->user, 'diluxone_users_2fa_lock' );

		parent::tearDown();
	}

	/** Opens a challenge and hands back the key and the code that was mailed. */
	private function round(): array {
		wp_set_current_user( 0 );

		$url = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, 'password', false, home_url( '/after/' ) ) );
		$key = $this->queryArg( $url, 'diluxone_users_key' );

		preg_match( '/\b(\d{6})\b/', (string) ( $this->lastMail()['message'] ?? '' ), $m );

		return array( $key, $m[1] ?? '' );
	}

	/** Submits one code against one challenge and returns where it went. */
	private function submit( string $key, string $code ): string {
		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $this->user,
				'diluxone_users_2fa_key'    => $key,
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => $code,
			)
		);

		return $this->expectRedirect( 'diluxone_users_2fa_handle' );
	}

	/** Burns one whole challenge on wrong codes. */
	private function burn(): void {
		[ $key ] = $this->round();

		for ( $i = 0; $i < DILUXONE_USERS_2FA_TRIES; $i++ ) {
			$this->submit( $key, '000000' );
		}
	}

	private function fails(): int {
		return (int) get_user_meta( $this->user, 'diluxone_users_2fa_fails', true );
	}

	private function lockedUntil(): int {
		return (int) get_user_meta( $this->user, 'diluxone_users_2fa_lock', true );
	}

	public function test_the_count_does_not_start_over_with_a_new_challenge(): void {
		$this->burn();

		$this->assertSame( DILUXONE_USERS_2FA_TRIES, $this->fails() );

		$this->burn();

		$this->assertSame( DILUXONE_USERS_2FA_TRIES * 2, $this->fails(), 'The account remembers what the attempt forgot' );
	}

	public function test_enough_wrong_codes_close_the_door(): void {
		$this->assertFalse( diluxone_users_2fa_locked( $this->user ) );

		// Two challenges is ten wrong codes, which is the account's limit.
		$this->burn();
		$this->burn();

		$this->assertTrue( diluxone_users_2fa_locked( $this->user ) );
		$this->assertGreaterThan( time(), $this->lockedUntil() );
	}

	public function test_a_locked_account_refuses_the_right_code_and_says_so(): void {
		$this->burn();
		$this->burn();

		[ $key, $code ] = $this->round();

		$this->assertSame( 'locked', $this->redirectState( $this->submit( $key, $code ) ) );
		$this->assertSame( 0, get_current_user_id(), 'Nobody gets in while it is closed' );
	}

	public function test_knocking_while_locked_does_not_push_the_wait_further_out(): void {
		$this->burn();
		$this->burn();

		$was   = $this->lockedUntil();
		$count = $this->fails();

		[ $key ] = $this->round();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->submit( $key, '000000' );
		}

		$this->assertSame( $was, $this->lockedUntil(), 'Otherwise a stranger can keep the owner out for ever' );
		$this->assertSame( $count, $this->fails() );
	}

	public function test_the_wait_grows_with_every_failure_past_the_limit(): void {
		update_user_meta( $this->user, 'diluxone_users_2fa_fails', DILUXONE_USERS_2FA_LOCK_AFTER - 1 );

		diluxone_users_2fa_close( $this->user, diluxone_users_2fa_count( $this->user, 1 ) );
		$first = $this->lockedUntil() - time();

		diluxone_users_2fa_close( $this->user, diluxone_users_2fa_count( $this->user, 1 ) );
		$second = $this->lockedUntil() - time();

		$this->assertGreaterThan( $first, $second );
		$this->assertLessThanOrEqual( DILUXONE_USERS_2FA_LOCK_MAX, $second );
	}

	public function test_the_wait_never_grows_past_its_ceiling(): void {
		update_user_meta( $this->user, 'diluxone_users_2fa_fails', DILUXONE_USERS_2FA_LOCK_AFTER + 500 );

		diluxone_users_2fa_close( $this->user, diluxone_users_2fa_count( $this->user, 1 ) );

		$this->assertLessThanOrEqual( DILUXONE_USERS_2FA_LOCK_MAX, $this->lockedUntil() - time() );
	}

	public function test_a_code_that_is_right_clears_the_count(): void {
		$this->burn();

		$this->assertSame( DILUXONE_USERS_2FA_TRIES, $this->fails() );

		[ $key, $code ] = $this->round();

		$this->assertSame( home_url( '/after/' ), $this->submit( $key, $code ) );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertSame( 0, $this->fails(), 'Only the owner can clear it, and this is how' );
	}

	public function test_the_door_that_turns_the_second_step_off_counts_too(): void {
		// Otherwise the limit is on the way in and not on the way out, and a
		// stolen session guesses its way to removing the second step instead.
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );

		for ( $i = 0; $i < DILUXONE_USERS_2FA_LOCK_AFTER; $i++ ) {
			$this->assertFalse( diluxone_users_2fa_reauth( $this->user, '000000' ) );
		}

		$this->assertTrue( diluxone_users_2fa_locked( $this->user ) );
	}

	public function test_the_refusal_is_written_down(): void {
		// A wrong second step never reaches wp_login_failed — the password was
		// right — so without its own row the log is quiet during the attack.
		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );

		$this->burn();

		$rows = diluxone_users_log_search( array( 'event' => '2fa_failed' ) );

		$this->assertNotEmpty( $rows['rows'] ?? array() );
	}

	/**
	 * A burst: many requests that all found the door open before any failure
	 * was counted. Each takes its try first, so only as many as the limit
	 * allows get a code looked at, and the ones refused give their try back.
	 */
	public function test_a_burst_gets_no_more_tries_than_the_limit(): void {
		$granted = 0;

		for ( $i = 0; $i < DILUXONE_USERS_2FA_LOCK_AFTER + 15; $i++ ) {
			if ( null !== diluxone_users_2fa_reserve( $this->user ) ) {
				++$granted;
			}
		}

		$this->assertSame( DILUXONE_USERS_2FA_LOCK_AFTER, $granted );
		$this->assertSame( DILUXONE_USERS_2FA_LOCK_AFTER, $this->fails() );
	}

	/** Once a wait is over, one try goes through and the door closes again for longer. */
	public function test_after_a_wait_exactly_one_try_goes_through(): void {
		update_user_meta( $this->user, 'diluxone_users_2fa_fails', DILUXONE_USERS_2FA_LOCK_AFTER - 1 );
		diluxone_users_2fa_close( $this->user, diluxone_users_2fa_count( $this->user, 1 ) );
		$first = $this->lockedUntil() - time();

		// The wait is over.
		update_user_meta( $this->user, 'diluxone_users_2fa_lock', time() - 1 );

		$this->assertFalse( diluxone_users_2fa_verify( $this->user, 'email', '000000' ) );
		$this->assertTrue( diluxone_users_2fa_locked( $this->user ), 'The one try was wrong: closed again' );
		$this->assertGreaterThan( $first, $this->lockedUntil() - time(), 'And for longer' );

		// The next wait is over too; a burst arrives.
		update_user_meta( $this->user, 'diluxone_users_2fa_lock', time() - 1 );

		$this->assertNotNull( diluxone_users_2fa_reserve( $this->user ) );
		$this->assertNull( diluxone_users_2fa_reserve( $this->user ), 'The rest of the burst is refused' );
	}
}
