<?php
/**
 * The second step's refusals that the other suites leave out, and the two
 * of its doors that only a race or a ticked box reach: the account's count
 * running out without a lock, a backup code, nothing remembered unless an
 * add-on says so, the attempt spent by another request between the right code
 * and the session, and an e-mail link starting with a method that is not
 * another e-mail. Then the codes kept sealed for one redirect, and the edges of the
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

	/** @param array<string, mixed> $extra */
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

	/* ── Nothing remembered ─────────────────────────────────────────── */

	/**
	 * A browser is remembered by an add-on (Users+ Pro), not by the plugin: a
	 * cookie that looks like one skips nothing, and only an add-on's answer
	 * through `diluxone_users_2fa_skip` lets a sign-in past the step.
	 */
	public function test_nothing_remembered_skips_the_step_but_an_add_ons_answer(): void {
		$_COOKIE[ 'diluxone_users_2fa_' . COOKIEHASH ] = $this->user . '|' . ( time() + 3600 ) . '|' . wp_hash( 'anything', 'secure_auth' );

		$this->assertFalse( diluxone_users_2fa_skip( $this->user, 'password' ) );

		$asked = $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'sso', false, home_url( '/after/' ) ) );
		$this->assertNotSame( '', $this->queryArg( $asked, 'diluxone_users_key' ), 'asked' );
		$this->assertSame( 0, get_current_user_id() );

		$this->hook(
			'diluxone_users_2fa_skip',
			fn( bool $skip, int $user_id, string $via ): bool => $user_id === $this->user && 'sso' === $via,
			10,
			3
		);

		$this->assertSame( home_url( '/after/' ), $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'sso', false, home_url( '/after/' ) ) ) );
		$this->assertSame( $this->user, get_current_user_id(), 'straight in' );
	}

	/* ── The second step's form ──────────────────────────────────────── */

	/** The right code signs in, and what an add-on's fields sent reaches it, cleaned, once the code is right. */
	public function test_the_right_code_signs_in_and_hands_an_add_on_what_its_fields_sent(): void {
		$heard = array();
		$this->hook(
			'diluxone_users_2fa_passed',
			static function ( int $user_id, array $extra, string $via ) use ( &$heard ): void {
				$heard[] = array( $user_id, $extra, $via );
			},
			10,
			3
		);

		$attempt = $this->halfway();
		$this->answer( $attempt['key'], 'wrong!', array( 'diluxone_users_2fa_extra' => array( 'trust' => '1' ) ) );
		$this->assertSame( array(), $heard, 'a wrong code hands over nothing' );

		$url = $this->answer( $attempt['key'], $attempt['code'], array( 'diluxone_users_2fa_extra' => array( 'trust' => '<b>1</b>' ) ) );

		$this->assertSame( home_url( '/after/' ), $url );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertSame( array( array( $this->user, array( 'trust' => '1' ), 'password' ) ), $heard );
		$this->assertSame( array(), $this->announced, 'a password sign-in already announced itself' );
	}

	public function test_an_attempt_another_request_spent_first_opens_no_second_session(): void {
		$attempt = $this->halfway();
		add_action( 'deleted_user_meta', array( $this, 'another_request_wins' ), 10, 3 );

		$url = $this->answer( $attempt['key'], $attempt['code'] );

		$this->assertSame( diluxone_users_2fa_restart_url(), $url );
		$this->assertSame( 0, get_current_user_id() );
	}

	/** By link, a method that does not arrive by e-mail — an add-on's — is asked before another e-mail. */
	public function test_coming_in_by_link_a_method_that_is_not_an_email_is_asked_first(): void {
		$this->hook(
			'diluxone_users_2fa_methods',
			static function ( array $methods ): array {
				$methods['device'] = array(
					'channel'  => 'device',
					'label'    => 'A device',
					'help'     => '',
					'ready'    => '__return_true',
					'verify'   => '__return_false',
					'position' => 30,
				);

				return $methods;
			}
		);
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'device' ) );

		$by_link = $this->halfway( 'link' );

		$this->assertSame( 'device', $by_link['method'] );
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

	public function test_the_edges_of_the_machine_checks(): void {
		$this->assertFalse( diluxone_users_ip_in( '10.0.0.1', '10.0.0.0/33' ), 'more bits than the address has' );
		$this->assertFalse( diluxone_users_ip_in( '10.0.0.1', '10.0.0.0/-1' ) );
		$this->assertTrue( diluxone_users_ip_is_internal( '' ), 'no address is ours' );
		$this->assertTrue( diluxone_users_ip_burst( 'cover', 0 ), 'no ceiling' );
		$this->assertFalse( get_site_transient( 'diluxone_users_burst_cover_' . md5( diluxone_users_client_ip() ) ), 'and nothing counted' );
	}
}
