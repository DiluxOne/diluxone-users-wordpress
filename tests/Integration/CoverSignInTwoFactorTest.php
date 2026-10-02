<?php
/**
 * The second step's refusals that the other suites leave out, and the two
 * of its doors that only a race or a ticked box reach: the account's count
 * running out without a lock, a backup code, the trust cookie and its
 * signature, the attempt spent by another request between the right code and
 * the session, and an e-mail link starting with the app rather than another
 * e-mail. Then the codes kept sealed for one redirect, and the edges of the
 * code generators and of the machine counts.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class CoverSignInTwoFactorTest extends IntegrationTestCase {

	private int $user = 0;

	/** @var array<int, string> Every login name `wp_login` announced. */
	private array $announced = array();

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		$this->user = $this->make_user();

		add_action( 'wp_login', array( $this, 'heard' ), 1 );
	}

	protected function tearDown(): void {
		remove_action( 'wp_login', array( $this, 'heard' ), 1 );
		remove_action( 'deleted_user_meta', array( $this, 'another_request_wins' ) );
		MockProvider::remove();

		parent::tearDown();
	}

	/** The `wp_login` action. */
	public function heard( string $login ): void {
		$this->announced[] = $login;
	}

	/**
	 * The `deleted_user_meta` action: the moment the e-mail code is spent,
	 * another request carrying the same attempt finishes first.
	 *
	 * @param array<int, int> $ids
	 */
	public function another_request_wins( array $ids, int $user_id, string $key ): void {
		if ( 'diluxone_users_2fa_email' === $key ) {
			delete_user_meta( $user_id, 'diluxone_users_2fa_pending' );
		}
	}

	/**
	 * Starts the second step and returns the attempt's key and the code that was mailed.
	 *
	 * @return array{key: string, code: string}
	 */
	private function halfway( string $via = 'password' ): array {
		$url = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $this->user, $via, false, home_url( '/after/' ) ) );

		preg_match( '/\b(\d{6})\b/', (string) ( $this->lastMail()['message'] ?? '' ), $m );

		return array(
			'key'    => $this->queryArg( $url, 'diluxone_users_key' ),
			'code'   => $m[1] ?? '',
			'method' => $this->queryArg( $url, 'diluxone_users_method' ),
		);
	}

	/** @param array<string, string> $extra */
	private function answer( string $key, string $code, array $extra = array() ): string {
		$this->postAs(
			0,
			$extra + array(
				'diluxone_users_2fa_user'   => (string) $this->user,
				'diluxone_users_2fa_key'    => $key,
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => $code,
			)
		);

		return $this->expectRedirect( 'diluxone_users_2fa_handle' );
	}

	/* ── The account's count ─────────────────────────────────────────── */

	public function test_a_locked_account_is_refused_before_a_code_is_looked_at(): void {
		update_user_meta( $this->user, 'diluxone_users_2fa_lock', time() + 600 );
		$codes = diluxone_users_backup_generate( $this->user );

		$this->assertFalse( diluxone_users_2fa_verify( $this->user, 'email', $codes[0] ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_fails', true ), 'nothing counted' );
		$this->assertTrue( diluxone_users_backup_use( $this->user, $codes[0] ), 'and the backup code was not spent' );
	}

	public function test_a_count_with_no_tries_left_refuses_and_gives_the_try_back(): void {
		update_user_meta( $this->user, 'diluxone_users_2fa_fails', DILUXONE_USERS_2FA_LOCK_AFTER );
		$codes = diluxone_users_backup_generate( $this->user );

		$this->assertFalse( diluxone_users_2fa_verify( $this->user, 'email', $codes[0] ) );
		$this->assertFalse( diluxone_users_2fa_reauth( $this->user, $codes[1] ) );
		$this->assertSame( (string) DILUXONE_USERS_2FA_LOCK_AFTER, get_user_meta( $this->user, 'diluxone_users_2fa_fails', true ) );
		$this->assertTrue( diluxone_users_backup_use( $this->user, $codes[0] ), 'nothing was spent' );
	}

	public function test_a_backup_code_answers_any_method_once_and_clears_the_count(): void {
		$codes = diluxone_users_backup_generate( $this->user );
		update_user_meta( $this->user, 'diluxone_users_2fa_fails', 3 );

		$this->assertTrue( diluxone_users_2fa_verify( $this->user, 'totp', strtoupper( $codes[2] ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_fails', true ) );
		$this->assertFalse( diluxone_users_2fa_verify( $this->user, 'totp', $codes[2] ), 'spent' );
	}

	/* ── The trust cookie ────────────────────────────────────────────── */

	public function test_with_no_days_to_remember_no_browser_is_trusted_or_marked(): void {
		diluxone_users_2fa_trust( $this->user );

		$this->assertSame( array(), self::$cookies );
		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ) );
	}

	public function test_a_browser_is_trusted_only_with_its_own_signed_cookie(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 7 );
		$name = 'diluxone_users_2fa_' . COOKIEHASH;

		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ), 'no cookie' );

		diluxone_users_2fa_trust( $this->user );
		$this->assertArrayHasKey( $name, self::$cookies );
		$_COOKIE[ $name ] = self::$cookies[ $name ]['value'];

		$this->assertTrue( diluxone_users_2fa_trusted( $this->user ) );
		$this->assertFalse( diluxone_users_2fa_trusted( $this->make_user() ), 'somebody else\'s' );

		[ $id, $expires ] = explode( '|', $_COOKIE[ $name ] );
		$_COOKIE[ $name ] = $id . '|' . ( (int) $expires + DAY_IN_SECONDS ) . '|' . diluxone_users_2fa_trust_hash( (int) $id, (int) $expires );
		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ), 'a longer life than it was signed for' );

		$_COOKIE[ $name ] = diluxone_users_2fa_trust_value( $this->user, time() - 1 );
		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ), 'expired' );

		$_COOKIE[ $name ] = self::$cookies[ $name ]['value'];
		diluxone_users_2fa_forget_browsers( $this->user );
		$this->assertFalse( diluxone_users_2fa_trusted( $this->user ), 'revoked' );
	}

	/* ── The second step's form ──────────────────────────────────────── */

	public function test_the_right_code_with_the_box_ticked_signs_in_and_marks_the_browser(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 14 );
		$attempt = $this->halfway();

		$url = $this->answer( $attempt['key'], $attempt['code'], array( 'diluxone_users_2fa_trust' => '1' ) );

		$this->assertSame( home_url( '/after/' ), $url );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertArrayHasKey( 'diluxone_users_2fa_' . COOKIEHASH, self::$cookies );
		$this->assertSame( array(), $this->announced, 'a password sign-in already announced itself' );
	}

	public function test_an_attempt_another_request_spent_first_opens_no_second_session(): void {
		$attempt = $this->halfway();
		add_action( 'deleted_user_meta', array( $this, 'another_request_wins' ), 10, 3 );

		$url = $this->answer( $attempt['key'], $attempt['code'] );

		$this->assertSame( diluxone_users_2fa_restart_url(), $url );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_coming_in_by_link_the_app_is_asked_before_another_email(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'totp' ) );
		update_user_meta( $this->user, 'diluxone_users_totp', diluxone_users_totp_secret_new() );

		$by_link = $this->halfway( 'link' );

		$this->assertSame( 'totp', $by_link['method'] );
		$this->assertSame( array(), $this->lastMail(), 'and no code was mailed to the inbox the link came from' );
	}

	/* ── The ways in, as the security screen lists them ──────────────── */

	public function test_a_social_network_on_is_listed_as_a_way_in_that_asks(): void {
		$this->assertArrayNotHasKey( 'sso', diluxone_users_2fa_ways( $this->user ) );

		MockProvider::install();

		$ways = diluxone_users_2fa_ways( $this->user );
		$this->assertSame( 'a social account', $ways['sso']['label'] );
		$this->assertTrue( $ways['sso']['asked'] );
	}

	public function test_nobody_is_announced_for_an_account_that_is_not_there(): void {
		diluxone_users_announce_wp_login( PHP_INT_MAX, 'link' );

		$this->assertSame( array(), $this->announced );
	}

	/* ── The codes kept for one redirect ─────────────────────────────── */

	public function test_the_codes_come_back_once_and_nothing_else_opens(): void {
		diluxone_users_backup_stash( $this->user, array( 'aaa', 'bbb' ) );

		$this->assertStringNotContainsString( 'aaa', (string) get_transient( 'diluxone_users_backup_' . $this->user ), 'sealed' );
		$this->assertSame( array( 'aaa', 'bbb' ), diluxone_users_backup_unstash( $this->user ) );
		$this->assertSame( array(), diluxone_users_backup_unstash( $this->user ), 'once' );

		set_transient( 'diluxone_users_backup_' . $this->user, base64_encode( 'short' ), 60 );
		$this->assertSame( array(), diluxone_users_backup_unstash( $this->user ), 'truncated' );

		set_transient( 'diluxone_users_backup_' . $this->user, base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 40 ) ), 60 );
		$this->assertSame( array(), diluxone_users_backup_unstash( $this->user ), 'sealed with another key' );

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		set_transient( 'diluxone_users_backup_' . $this->user, base64_encode( $nonce . sodium_crypto_secretbox( 'not json', $nonce, diluxone_users_backup_key() ) ), 60 );
		$this->assertSame( array(), diluxone_users_backup_unstash( $this->user ), 'not a list' );
	}

	/* ── Edges of the code generators and the counts ─────────────────── */

	public function test_an_e_mail_code_for_nobody_is_not_sent_and_an_expired_or_empty_one_is_refused(): void {
		$this->assertFalse( diluxone_users_2fa_email_send( PHP_INT_MAX ) );
		$this->assertSame( array(), self::$mail );

		update_user_meta(
			$this->user,
			'diluxone_users_2fa_email',
			array(
				'hash'    => wp_hash( '123456' ),
				'expires' => time() - 1,
			)
		);
		$this->assertFalse( diluxone_users_2fa_email_verify( $this->user, '123456' ), 'expired' );

		update_user_meta(
			$this->user,
			'diluxone_users_2fa_email',
			array(
				'hash'    => wp_hash( '' ),
				'expires' => time() + 60,
			)
		);
		$this->assertFalse( diluxone_users_2fa_email_verify( $this->user, 'no digits' ), 'empty' );
	}

	public function test_an_app_is_activated_only_by_a_code_from_the_secret_being_tried(): void {
		$this->assertFalse( diluxone_users_totp_activate( $this->user, '123456' ), 'nothing being tried' );

		$secret = diluxone_users_totp_pending( $this->user );
		$wrong  = str_pad( (string) ( ( (int) diluxone_users_totp_code( $secret ) + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );

		$this->assertFalse( diluxone_users_totp_activate( $this->user, $wrong ) );
		$this->assertFalse( diluxone_users_totp_ready( $this->user ) );
		$this->assertFalse( diluxone_users_totp_verify( $this->user, diluxone_users_totp_code( $secret ) ), 'nothing active to verify against' );

		$this->assertTrue( diluxone_users_totp_activate( $this->user, diluxone_users_totp_code( $secret ) ) );
		$this->assertSame( $secret, diluxone_users_totp_secret( $this->user ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_totp_pending', true ) );
	}

	public function test_a_secret_with_nothing_in_it_makes_no_code(): void {
		$this->assertSame( '', diluxone_users_base32_decode( '!!!' ) );
		$this->assertSame( '', diluxone_users_totp_code( '' ) );
		$this->assertFalse( diluxone_users_totp_check( '', '000000' ) );
	}

	public function test_the_edges_of_the_machine_checks(): void {
		$this->assertFalse( diluxone_users_ip_in( '10.0.0.1', '10.0.0.0/33' ), 'more bits than the address has' );
		$this->assertFalse( diluxone_users_ip_in( '10.0.0.1', '10.0.0.0/-1' ) );
		$this->assertTrue( diluxone_users_ip_is_internal( '' ), 'no address is ours' );
		$this->assertTrue( diluxone_users_ip_burst( 'cover', 0 ), 'no ceiling' );
		$this->assertFalse( get_site_transient( 'diluxone_users_burst_cover_' . md5( diluxone_users_client_ip() ) ), 'and nothing counted' );
	}
}
