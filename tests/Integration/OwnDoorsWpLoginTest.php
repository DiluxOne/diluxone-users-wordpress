<?php
/**
 * The plugin's own doors announce a sign-in the way WordPress does.
 *
 * The e-mail link, a social account and a passkey open the session without
 * wp_signon(), so `wp_login` — the action half the plugins of a site listen
 * on — never fired for them. Now each fires it once, with the login and the
 * account, and the plugin's own listeners on it (the second step, the
 * password's announcement, the way back on a network) stand aside: the second
 * step is not asked twice and the log has one row.
 *
 * Every case runs on both topologies.
 */

namespace Tests\Integration;

class OwnDoorsWpLoginTest extends IntegrationTestCase {

	private int $user;

	/** @var array<int, array{login: string, id: int}> Every `wp_login` heard, in order. */
	private array $heard = array();

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$this->user  = $this->make_user();
		$this->heard = array();

		add_action( 'wp_login', array( $this, 'hear' ), 50, 2 );
	}

	protected function tearDown(): void {
		remove_action( 'wp_login', array( $this, 'hear' ), 50 );

		parent::tearDown();
	}

	/**
	 * Listens on `wp_login` like any other plugin would.
	 *
	 * @param string   $login The login name.
	 * @param \WP_User $user  The account.
	 */
	public function hear( $login, $user ): void {
		$this->heard[] = array(
			'login' => (string) $login,
			'id'    => (int) $user->ID,
		);
	}

	/** How many sign-ins the activity log wrote for the person. */
	private function logged(): int {
		$rows = diluxone_users_log_search( array( 'event' => 'signed_in' ), 1, 200 )['rows'];

		return count( array_filter( $rows, fn( $row ): bool => (int) ( (array) $row )['user_id'] === $this->user ) );
	}

	/** @return array<int, array<int, string>> */
	public function doors(): array {
		return array(
			'e-mail link'    => array( 'link' ),
			'social account' => array( 'sso' ),
		);
	}

	/**
	 * @dataProvider doors
	 */
	public function test_a_door_of_the_plugin_fires_wp_login_once( string $via ): void {
		$url = $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, $via, true, home_url( '/after/' ) ) );

		$this->assertSame( home_url( '/after/' ), $url );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertSame(
			array(
				array(
					'login' => get_userdata( $this->user )->user_login,
					'id'    => $this->user,
				),
			),
			$this->heard,
			'wp_login, once, with the login name and the account'
		);
		$this->assertSame( 1, $this->logged(), 'One row in the log, not one for the door and one for wp_login' );
		$this->assertSame( '', diluxone_users_own_door(), 'The plugin\'s own listeners are back for the next password' );
	}

	public function test_a_passkey_fires_wp_login_once(): void {
		// The passkey handler ends in this call once the signature is checked
		// (diluxone_users_passkey_login()); the signature is PasskeyTest's.
		diluxone_users_open_session( $this->user, 'passkey', true );

		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertCount( 1, $this->heard );
		$this->assertSame( 1, $this->logged() );
	}

	public function test_the_second_step_is_asked_once_and_wp_login_fires_when_it_is_done(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_link', 'always' );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		$challenge = $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, home_url( '/after/' ) ) );

		$this->assertSame( array(), $this->heard, 'Nobody has signed in yet: the second step is missing' );
		$this->assertSame( 0, get_current_user_id() );

		preg_match( '/\b(\d{6})\b/', (string) ( $this->lastMail()['message'] ?? '' ), $m );

		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $this->user,
				'diluxone_users_2fa_key'    => $this->queryArg( $challenge, 'diluxone_users_key' ),
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => $m[1] ?? '',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_2fa_handle' );

		$this->assertSame( home_url( '/after/' ), $url, 'In, not sent to a second challenge' );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertCount( 1, $this->heard, 'wp_login once, after the code' );
		$this->assertSame( '', (string) get_user_meta( $this->user, 'diluxone_users_2fa_pending', true ), 'No second attempt was opened' );
		$this->assertSame( 1, $this->logged() );
	}

	public function test_a_password_still_fires_wp_login_once_and_is_announced_once(): void {
		$password = 'Correct-Horse-9';
		wp_set_password( $password, $this->user );

		$user = wp_signon(
			array(
				'user_login'    => get_userdata( $this->user )->user_login,
				'user_password' => $password,
			)
		);

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertCount( 1, $this->heard );
		$this->assertSame( 1, $this->logged(), 'The password is announced by its own listener, once' );
	}
}
