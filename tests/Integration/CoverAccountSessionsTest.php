<?php
/**
 * The person's open sessions: listed, closed one by one, closed all but this one.
 *
 * Every refusal first — signed out, a forged form, a session that belongs to
 * somebody else, a session manager the plugin cannot address — and then what
 * each button does, the list as the shortcode draws it, and the search an
 * administrator runs over everybody's sessions, which on a network stops at
 * this site's members.
 */

namespace Tests\Integration;

class CoverAccountSessionsTest extends IntegrationTestCase {

	protected function tearDown(): void {
		remove_filter( 'session_token_manager', array( $this, 'other_manager' ) );
		unset( $_SERVER['HTTP_REFERER'] );

		parent::tearDown();
	}

	/** The `session_token_manager` filter: a manager of the site's own. */
	public function other_manager(): string {
		return CoverAccountOtherTokens::class;
	}

	/**
	 * Writes sessions straight into the meta WordPress keeps them in.
	 *
	 * @param array<string, array<string, mixed>> $sessions By verifier.
	 */
	private function give_sessions( int $user_id, array $sessions ): void {
		update_user_meta( $user_id, 'session_tokens', $sessions );
	}

	/** One session as WordPress stores it. */
	private function session( int $login, string $ua = '', string $ip = '' ): array {
		return array(
			'expiration' => time() + DAY_IN_SECONDS,
			'ip'         => $ip,
			'ua'         => $ua,
			'login'      => $login,
		);
	}

	/** A real session, made by WordPress, used by this request: its verifier. */
	private function sign_in_here( int $user_id ): string {
		$token = \WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );

		wp_set_current_user( $user_id );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in', $token );

		return hash( 'sha256', $token );
	}

	public function test_a_phone_and_an_unknown_browser_are_told_apart(): void {
		$phone = diluxone_users_user_agent( 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36' );

		$this->assertSame( 'Chrome', $phone['browser'] );
		$this->assertSame( 'Android', $phone['os'] );
		$this->assertSame( 'Phone', $phone['device'] );

		$nothing = diluxone_users_user_agent( '' );

		$this->assertSame( 'Unknown browser', $nothing['browser'] );
		$this->assertSame( '', $nothing['os'] );
		$this->assertSame( 'Computer', $nothing['device'] );
	}

	public function test_the_list_is_newest_first_and_marks_the_one_in_use(): void {
		$user = $this->make_user();
		$here = $this->sign_in_here( $user );
		$all  = diluxone_users_meta_list( $user, 'session_tokens' );

		$all['old-one'] = $this->session( time() - WEEK_IN_SECONDS, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Version/17.0 Safari/605.1.15', '198.51.100.7' );
		$this->give_sessions( $user, $all );

		$sessions = diluxone_users_sessions( $user );

		$this->assertSame( array( $here, 'old-one' ), array_column( $sessions, 'id' ) );
		$this->assertSame( array( true, false ), array_column( $sessions, 'current' ) );
		$this->assertSame( 'Safari', $sessions[1]['browser'] );
		$this->assertSame( '198.51.100.7', $sessions[1]['ip'] );

		// Somebody else looking at the same list has no session among them.
		wp_set_current_user( 1 );
		$this->assertSame( array( false, false ), array_column( diluxone_users_sessions( $user ), 'current' ) );
	}

	public function test_closing_one_refuses_what_it_cannot_address(): void {
		$user = $this->make_user();
		$this->give_sessions(
			$user,
			array(
				'a' => $this->session( time() - 10 ),
				'b' => $this->session( time() - 20 ),
			)
		);
		wp_set_current_user( $user );

		$this->assertFalse( diluxone_users_session_close( $user, 'not-there' ), 'A verifier that is not theirs' );
		$this->assertCount( 2, diluxone_users_meta_list( $user, 'session_tokens' ) );

		add_filter( 'session_token_manager', array( $this, 'other_manager' ) );
		$this->assertFalse( diluxone_users_sessions_addressable() );
		$this->assertFalse( diluxone_users_session_close( $user, 'a' ), 'Another manager’s format is not touched' );
		$this->assertCount( 2, diluxone_users_meta_list( $user, 'session_tokens' ) );
		remove_filter( 'session_token_manager', array( $this, 'other_manager' ) );

		$this->assertTrue( diluxone_users_session_close( $user, 'a' ) );
		$this->assertSame( array( 'b' ), array_keys( diluxone_users_meta_list( $user, 'session_tokens' ) ) );

		$this->assertTrue( diluxone_users_session_close( $user, 'b' ) );
		$this->assertSame( '', get_user_meta( $user, 'session_tokens', true ), 'The last one leaves no empty row behind' );
	}

	public function test_the_button_refuses_somebody_signed_out(): void {
		$this->postAs( 0, array( '_wpnonce' => 'x' ) );

		$this->expectDie( 'diluxone_users_sessions_action', 'You have to sign in first.', 401 );
	}

	public function test_the_button_refuses_a_forged_form(): void {
		$user = $this->make_user();
		$this->give_sessions( $user, array( 'a' => $this->session( time() ) ) );
		$this->postAs(
			$user,
			array(
				'_wpnonce'               => 'forged',
				'diluxone_users_session' => 'a',
			)
		);

		$this->expectDie( 'diluxone_users_sessions_action', self::EXPIRED, 403 );
		$this->assertCount( 1, diluxone_users_meta_list( $user, 'session_tokens' ), 'Nothing closed' );
	}

	public function test_the_button_cannot_close_somebody_elses_session(): void {
		$me    = $this->make_user();
		$other = $this->make_user();
		$this->give_sessions( $other, array( 'theirs' => $this->session( time() ) ) );
		$this->give_sessions( $me, array( 'mine' => $this->session( time() ) ) );

		wp_set_current_user( $me );
		$this->postAs(
			$me,
			array(
				'_wpnonce'               => wp_create_nonce( 'diluxone_users_sessions' ),
				'diluxone_users_session' => 'theirs',
			)
		);
		$_SERVER['HTTP_REFERER'] = home_url( '/account/' );

		$url = $this->expectRedirect( 'diluxone_users_sessions_action' );

		$this->assertSame( 'sessions', $this->redirectState( $url ) );
		$this->assertStringStartsWith( home_url( '/account/' ), $url );
		$this->assertSame( array( 'theirs' ), array_keys( diluxone_users_meta_list( $other, 'session_tokens' ) ), 'The other person’s session stays open' );
		$this->assertSame( array( 'mine' ), array_keys( diluxone_users_meta_list( $me, 'session_tokens' ) ) );
	}

	public function test_the_button_closes_one_of_mine(): void {
		$me = $this->make_user();
		$this->give_sessions(
			$me,
			array(
				'keep'  => $this->session( time() ),
				'close' => $this->session( time() - 60 ),
			)
		);

		wp_set_current_user( $me );
		$this->postAs(
			$me,
			array(
				'_wpnonce'               => wp_create_nonce( 'diluxone_users_sessions' ),
				'diluxone_users_session' => 'close',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_sessions_action' );

		$this->assertStringStartsWith( home_url( '/' ), $url, 'With no referrer, home' );
		$this->assertSame( array( 'keep' ), array_keys( diluxone_users_meta_list( $me, 'session_tokens' ) ) );
	}

	public function test_closing_the_others_keeps_this_one_and_forgets_the_trusted_browsers(): void {
		$me   = $this->make_user();
		$here = $this->sign_in_here( $me );
		$all  = diluxone_users_meta_list( $me, 'session_tokens' );

		$all['lost-phone'] = $this->session( time() - 3600 );
		$this->give_sessions( $me, $all );
		update_user_meta( $me, 'diluxone_users_2fa_epoch', 'before' );

		$this->postAs( $me, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_sessions' ) ) );

		$this->expectRedirect( 'diluxone_users_sessions_action' );

		$this->assertSame( array( $here ), array_keys( diluxone_users_meta_list( $me, 'session_tokens' ) ) );
		$this->assertNotSame( 'before', get_user_meta( $me, 'diluxone_users_2fa_epoch', true ), 'A lost device does not walk back in as trusted' );
	}

	public function test_closing_the_others_for_somebody_else_closes_them_all(): void {
		$person = $this->make_user();
		$this->give_sessions(
			$person,
			array(
				'a' => $this->session( time() ),
				'b' => $this->session( time() ),
			)
		);

		wp_set_current_user( 1 );
		diluxone_users_sessions_close_others( $person );

		$this->assertSame( array(), diluxone_users_meta_list( $person, 'session_tokens' ) );
	}

	public function test_the_shortcode_draws_nothing_for_somebody_signed_out(): void {
		$this->assertSame( '', diluxone_users_shortcode_sessions() );
	}

	public function test_the_shortcode_draws_each_session_and_its_buttons(): void {
		$me   = $this->make_user();
		$here = $this->sign_in_here( $me );
		$all  = diluxone_users_meta_list( $me, 'session_tokens' );

		$all['phone'] = $this->session( time() - HOUR_IN_SECONDS, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1', '198.51.100.9' );
		$all['bare']  = $this->session( 0 );
		$this->give_sessions( $me, $all );
		$_GET['diluxone-users'] = 'sessions';

		$html = diluxone_users_shortcode_sessions();

		$this->assertStringContainsString( 'diluxone-users-notice--ok', $html, 'The redirect’s state is said' );
		$this->assertSame( 3, substr_count( $html, '<li class="diluxone-users-session' ) );
		$this->assertSame( 1, substr_count( $html, 'diluxone-users-session--current' ) );
		$this->assertStringContainsString( 'This session', $html );
		$this->assertStringContainsString( 'Safari', $html );
		$this->assertStringContainsString( '· iOS', $html );
		$this->assertStringContainsString( '· 198.51.100.9', $html );
		$this->assertStringContainsString( 'started 1 hour ago', $html );
		$this->assertStringContainsString( 'value="phone"', $html );
		$this->assertStringContainsString( 'value="bare"', $html );
		$this->assertStringNotContainsString( 'value="' . $here . '"', $html, 'The one in use has no Close button' );
		$this->assertStringContainsString( 'Close the others', $html );
		$this->assertSame( 3, substr_count( $html, 'name="_wpnonce"' ), 'Every form carries the nonce' );
	}

	public function test_the_shortcode_offers_no_single_close_when_the_manager_is_another(): void {
		$me = $this->make_user();
		$this->give_sessions( $me, array( 'only' => $this->session( time() - 60 ) ) );
		wp_set_current_user( $me );
		add_filter( 'session_token_manager', array( $this, 'other_manager' ) );

		$html = diluxone_users_shortcode_sessions();

		$this->assertStringContainsString( 'Unknown browser', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_session"', $html );
		$this->assertStringNotContainsString( 'Close the others', $html, 'One session has no others' );
	}

	public function test_the_shortcode_says_when_there_are_none(): void {
		wp_set_current_user( $this->make_user() );

		$html = diluxone_users_shortcode_sessions();

		$this->assertStringContainsString( 'There are no open sessions.', $html );
		$this->assertStringNotContainsString( 'diluxone-users-notice--ok', $html );
	}

	public function test_the_search_finds_a_member_by_any_of_their_names(): void {
		$person = $this->make_user();
		$login  = get_userdata( $person )->user_login;
		$this->give_sessions(
			$person,
			array(
				'older' => $this->session( time() - 100, 'Firefox', '' ),
				'newer' => $this->session( time() - 10, 'Mozilla/5.0 (Windows NT 10.0) Edg/120.0', '198.51.100.20' ),
			)
		);
		$this->give_sessions( $this->make_user(), array( 'x' => $this->session( time() ) ) );

		$found = diluxone_users_sessions_search( $login, 1, 20 );

		$this->assertSame( 1, $found['total'] );
		$this->assertSame( $person, $found['rows'][0]['user_id'] );
		$this->assertSame( 2, $found['rows'][0]['sessions'] );
		$this->assertSame( 'Edge', $found['rows'][0]['browser'], 'The newest session describes the row' );
		$this->assertSame( '198.51.100.20', $found['rows'][0]['ip'] );

		$none = diluxone_users_sessions_search( 'no-such-person-' . wp_generate_password( 6, false ), 1, 20 );
		$this->assertSame( array( 'rows' => array(), 'total' => 0 ), $none );
	}

	public function test_an_empty_list_of_sessions_is_not_a_row(): void {
		$person = $this->make_user();
		$this->give_sessions( $person, array() );
		update_user_meta( $person, 'session_tokens', 'not a list' );

		$found = diluxone_users_sessions_search( get_userdata( $person )->user_login, 1, 20 );

		$this->assertSame( 1, $found['total'], 'Counted by the query' );
		$this->assertSame( array(), $found['rows'], 'And left out of what is shown' );
	}

	public function test_on_a_network_the_search_stops_at_this_sites_members(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site has nobody who is not a member; the single-site case is test_the_search_finds_a_member_by_any_of_their_names.' );
		}

		$elsewhere = $this->make_user();
		remove_user_from_blog( $elsewhere, get_current_blog_id() );
		$this->give_sessions( $elsewhere, array( 'x' => $this->session( time() ) ) );

		$found = diluxone_users_sessions_search( get_userdata( $elsewhere )->user_login, 1, 20 );

		$this->assertSame( 0, $found['total'], 'Somebody of another site is not this site’s administrator’s to see' );
	}
}

/** A session manager of the site's own: same storage, another name. */
class CoverAccountOtherTokens extends \WP_User_Meta_Session_Tokens {}
