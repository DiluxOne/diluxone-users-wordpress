<?php
/**
 * Choosing a new password on the site's page: the key moved out of the
 * address into a cookie, and every way the save is refused before the
 * password changes — no nonce, no reset in progress, a key that no longer
 * checks out, two passwords that differ, an array where a password goes.
 */

namespace Tests\Integration;

class CoverSignInResetTest extends IntegrationTestCase {

	private int $user = 0;

	private string $uri = '';

	protected function setUp(): void {
		parent::setUp();

		$this->uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		$_SERVER['REQUEST_URI'] = $this->uri;

		parent::tearDown();
	}

	private function key(): string {
		return (string) get_password_reset_key( get_userdata( $this->user ) );
	}

	private function login(): string {
		return get_userdata( $this->user )->user_login;
	}

	/** Opens the link from the e-mail, as it arrives on the sign-in page. */
	private function open_link( string $key, string $login ): string {
		$_GET = array(
			'diluxone_users_key'   => $key,
			'diluxone_users_login' => $login,
		);

		$_SERVER['REQUEST_URI'] = '/sign-in/?diluxone_users_key=' . rawurlencode( $key ) . '&diluxone_users_login=' . rawurlencode( $login ) . '&keep=1';

		return $this->expectRedirect( 'diluxone_users_reset_catch' );
	}

	/** @param array<string, mixed> $post */
	private function save( array $post, bool $nonce = true ): string {
		$this->postAs( 0, $post + ( $nonce ? array( 'diluxone_users_reset_nonce' => wp_create_nonce( 'diluxone_users_reset' ) ) : array() ) );

		$url = $this->expectRedirect( 'diluxone_users_reset_save' );

		$this->assertStringStartsWith( diluxone_users_login_url(), $url, 'It always ends on the sign-in page' );

		return $this->redirectState( $url );
	}

	private function password_is( string $password ): bool {
		clean_user_cache( $this->user );

		return wp_check_password( $password, get_userdata( $this->user )->user_pass, $this->user );
	}

	/* ── The link ────────────────────────────────────────────────────── */

	public function test_a_page_without_a_key_is_left_alone(): void {
		$_GET = array( 'diluxone_users_key' => 'k' );
		diluxone_users_reset_catch();

		$_GET = array(
			'diluxone_users_key'   => array( 'k' ),
			'diluxone_users_login' => 'x',
		);
		diluxone_users_reset_catch();

		$this->assertSame( array(), self::$cookies );
	}

	public function test_a_good_key_moves_into_a_session_cookie_and_out_of_the_address(): void {
		$key = $this->key();

		$url = $this->open_link( $key, $this->login() );

		$this->assertSame( 'reset', $this->redirectState( $url ) );
		$this->assertStringNotContainsString( $key, $url );
		$this->assertStringNotContainsString( 'diluxone_users_login', $url );
		$this->assertSame( '1', $this->queryArg( $url, 'keep' ), 'the rest of the address is kept' );

		$cookie = self::$cookies[ diluxone_users_reset_cookie() ];
		$this->assertSame( $this->login() . ':' . $key, $cookie['value'] );
		$this->assertSame( 0, $cookie['options']['expires'], 'until the browser closes' );
		$this->assertTrue( $cookie['options']['httponly'] );
	}

	/** @return array<string, array{0: string}> */
	public static function bad_keys(): array {
		return array(
			'a made-up key'      => array( 'made-up' ),
			'another person'     => array( 'other' ),
			'a key already used' => array( 'used' ),
		);
	}

	/** @dataProvider bad_keys */
	public function test_a_key_wordpress_does_not_accept_is_answered_expired_and_kept_nowhere( string $case ): void {
		$key   = $this->key();
		$login = $this->login();

		if ( 'made-up' === $case ) {
			$key = 'made-up-key';
		} elseif ( 'other' === $case ) {
			$login = get_userdata( $this->make_user() )->user_login;
		} else {
			reset_password( get_userdata( $this->user ), 'a-new-password-1' );
		}

		$url = $this->open_link( $key, $login );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertStringNotContainsString( $key, $url );
		$this->assertArrayNotHasKey( diluxone_users_reset_cookie(), self::$cookies );
	}

	/* ── Whose reset it is ───────────────────────────────────────────── */

	public function test_the_cookie_names_the_person_only_while_its_key_checks_out(): void {
		$this->assertNull( diluxone_users_reset_user(), 'no cookie' );

		$_COOKIE[ diluxone_users_reset_cookie() ] = 'no-colon-here';
		$this->assertNull( diluxone_users_reset_user() );

		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':made-up';
		$this->assertNull( diluxone_users_reset_user() );

		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':' . $this->key();
		$this->assertSame( $this->user, diluxone_users_reset_user()->ID );
	}

	/* ── The save ────────────────────────────────────────────────────── */

	public function test_a_save_without_its_nonce_changes_nothing(): void {
		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':' . $this->key();

		$state = $this->save(
			array(
				'diluxone_users_pass'  => 'brand-new-pass',
				'diluxone_users_pass2' => 'brand-new-pass',
			),
			false
		);

		$this->assertSame( 'error', $state );
		$this->assertFalse( $this->password_is( 'brand-new-pass' ) );
	}

	public function test_a_save_with_no_reset_in_progress_is_expired_and_forgets_the_cookie(): void {
		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':made-up';

		$state = $this->save(
			array(
				'diluxone_users_pass'  => 'brand-new-pass',
				'diluxone_users_pass2' => 'brand-new-pass',
			)
		);

		$this->assertSame( 'expired', $state );
		$this->assertFalse( $this->password_is( 'brand-new-pass' ) );
		$this->assertLessThan( time(), self::$cookies[ diluxone_users_reset_cookie() ]['options']['expires'] );
	}

	/** @return array<string, array{0: mixed, 1: mixed}> */
	public static function mismatches(): array {
		return array(
			'different'         => array( 'one-password', 'another-one' ),
			'empty'             => array( '', '' ),
			'arrays, not words' => array( array( 'x' ), array( 'x' ) ),
		);
	}

	/**
	 * @dataProvider mismatches
	 * @param mixed $pass
	 * @param mixed $again
	 */
	public function test_two_passwords_that_are_not_one_password_change_nothing( $pass, $again ): void {
		$key = $this->key();
		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':' . $key;

		$state = $this->save(
			array(
				'diluxone_users_pass'  => $pass,
				'diluxone_users_pass2' => $again,
			)
		);

		$this->assertSame( 'nomatch', $state );
		$this->assertFalse( $this->password_is( 'Array' ) );
		// The key is still good: the person can try again.
		$this->assertSame( $this->user, diluxone_users_reset_user()->ID );
		$this->assertArrayNotHasKey( diluxone_users_reset_cookie(), self::$cookies );
	}

	public function test_a_good_save_changes_the_password_spends_the_key_and_forgets_the_cookie(): void {
		$key = $this->key();
		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':' . $key;

		$state = $this->save(
			array(
				'diluxone_users_pass'  => 'brand new "pass"',
				'diluxone_users_pass2' => 'brand new "pass"',
			)
		);

		$this->assertSame( 'changed', $state );
		$this->assertTrue( $this->password_is( 'brand new "pass"' ), 'used as typed, spaces and quotes included' );
		$this->assertNull( diluxone_users_reset_user(), 'the key is spent' );
		$this->assertLessThan( time(), self::$cookies[ diluxone_users_reset_cookie() ]['options']['expires'] );
	}

	/**
	 * A new password ends every session the old one opened.
	 *
	 * Somebody resets a password because the old one may be in somebody
	 * else's hands, and whoever holds it may already be signed in: a reset
	 * that leaves those sessions open has changed the lock and left the door
	 * ajar. The reset itself signs nobody in, so every session goes — on the
	 * site's own page and through WordPress's wp-login.php alike.
	 */
	public function test_a_new_password_ends_every_session(): void {
		$sessions = \WP_Session_Tokens::get_instance( $this->user );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$this->assertCount( 2, $sessions->get_all() );

		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':' . $this->key();

		$this->assertSame(
			'changed',
			$this->save(
				array(
					'diluxone_users_pass'  => 'another new one',
					'diluxone_users_pass2' => 'another new one',
				)
			)
		);
		$this->assertSame( array(), \WP_Session_Tokens::get_instance( $this->user )->get_all(), 'the site’s own page' );

		// WordPress's own reset form ends in the same place.
		$sessions->create( time() + HOUR_IN_SECONDS );
		reset_password( get_userdata( $this->user ), 'and one more' );
		$this->assertSame( array(), \WP_Session_Tokens::get_instance( $this->user )->get_all(), 'wp-login.php' );
	}

	/** A key past its life is expired, on the way in and on the save, and the save forgets the cookie. */
	public function test_a_key_past_its_life_is_expired(): void {
		global $wpdb, $wp_hasher;

		$key = $this->key();
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher = $wp_hasher instanceof \PasswordHash ? $wp_hasher : new \PasswordHash( 8, true );
		$wpdb->update( $wpdb->users, array( 'user_activation_key' => ( time() - 10 ) . ':' . wp_fast_hash( $key ) ), array( 'ID' => $this->user ) );
		clean_user_cache( $this->user );
		$this->hook( 'password_reset_expiration', static fn(): int => 1 );
		unset( $hasher );

		$this->assertSame( 'expired', $this->redirectState( $this->open_link( $key, $this->login() ) ) );
		$this->assertArrayNotHasKey( diluxone_users_reset_cookie(), self::$cookies, 'no cookie for a dead key' );

		$_COOKIE[ diluxone_users_reset_cookie() ] = $this->login() . ':' . $key;
		$this->assertSame( 'expired', $this->save( array( 'diluxone_users_pass' => 'x-new-1', 'diluxone_users_pass2' => 'x-new-1' ) ) );
		$this->assertLessThan( time(), self::$cookies[ diluxone_users_reset_cookie() ]['options']['expires'], 'and the cookie is forgotten' );
	}
}
