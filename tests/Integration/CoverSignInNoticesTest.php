<?php
/**
 * What the plugin knows about outgoing mail, and the notices it sends about
 * signing in: the record of the last send kept without a row per mail, a
 * notice nobody wants or nobody can receive not sent, and the new-device
 * notice sent once per device and never on the very first one.
 *
 * The suite catches every mail before it is sent, so WordPress never fires
 * `wp_mail_succeeded` or `wp_mail_failed` here: the two listeners are called
 * the way WordPress calls them.
 */

namespace Tests\Integration;

class CoverSignInNoticesTest extends IntegrationTestCase {

	/** @var mixed */
	private $agent = null;

	protected function setUp(): void {
		parent::setUp();

		$this->agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->agent;
		}

		parent::tearDown();
	}

	/* ── Outgoing mail ───────────────────────────────────────────────── */

	public function test_before_any_send_the_mail_is_unknown_and_trusted(): void {
		$this->assertSame(
			array(
				'state' => 'unknown',
				'time'  => 0,
				'error' => '',
			),
			diluxone_users_mail_status()
		);
		$this->assertTrue( diluxone_users_mail_works() );
	}

	public function test_a_send_that_went_through_is_written_once_an_hour_at_most(): void {
		diluxone_users_mail_ok();

		$first = diluxone_users_raw_get( 'diluxone_users_mail_last' );
		$this->assertSame( 1, $first['ok'] );
		$this->assertSame( 'ok', diluxone_users_mail_status()['state'] );

		// The next one inside the hour writes nothing.
		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 1, 'time' => time() - 60, 'error' => '' ), false );
		diluxone_users_mail_ok();
		$this->assertSame( time() - 60, (int) diluxone_users_raw_get( 'diluxone_users_mail_last' )['time'], 'not rewritten' );

		// After the hour it is refreshed.
		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 1, 'time' => time() - 2 * HOUR_IN_SECONDS, 'error' => '' ), false );
		diluxone_users_mail_ok();
		$this->assertGreaterThanOrEqual( time() - 5, (int) diluxone_users_raw_get( 'diluxone_users_mail_last' )['time'] );
	}

	public function test_a_failed_send_keeps_the_mailer_s_words_short_and_clean_and_the_next_good_one_clears_it(): void {
		diluxone_users_mail_failed( new \WP_Error( 'wp_mail_failed', '<b>SMTP</b> connect() failed. ' . str_repeat( 'x', 400 ) ) );

		$status = diluxone_users_mail_status();
		$this->assertSame( 'fail', $status['state'] );
		$this->assertFalse( diluxone_users_mail_works() );
		$this->assertStringStartsWith( 'SMTP connect() failed.', $status['error'] );
		$this->assertSame( 200, mb_strlen( $status['error'] ) );
		$this->assertGreaterThan( 0, $status['time'] );

		// A failure is never inside the hour's quiet: the next success writes.
		diluxone_users_mail_ok();
		$this->assertSame( 'ok', diluxone_users_mail_status()['state'] );
		$this->assertSame( '', diluxone_users_mail_status()['error'] );
	}

	/* ── Notices ─────────────────────────────────────────────────────── */

	public function test_a_notice_nobody_registered_is_not_sent(): void {
		$this->assertFalse( diluxone_users_notify( $this->make_user(), 'diluxone_users_notify_nothing', 'S', 'B' ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_a_notice_for_an_account_that_is_gone_is_not_sent(): void {
		diluxone_users_update_option( 'diluxone_users_notice_rules', array( 'diluxone_users_notify_security' => 'always' ) );

		$this->assertFalse( diluxone_users_notify( PHP_INT_MAX, 'diluxone_users_notify_security', 'S', 'B' ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_a_notice_the_site_never_sends_is_not_sent_whatever_the_person_chose(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_notify_security', '1' );
		diluxone_users_update_option( 'diluxone_users_notice_rules', array( 'diluxone_users_notify_security' => 'never' ) );

		$this->assertFalse( diluxone_users_notify( $user, 'diluxone_users_notify_security', 'S', 'B' ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_a_sent_notice_can_be_rewritten_by_a_filter(): void {
		$user    = $this->make_user();
		$rewrite = static fn( array $mail ): array => array(
			'subject' => '[Site] ' . $mail['subject'],
			'body'    => $mail['body'] . "\n--",
		);
		add_filter( 'diluxone_users_notification', $rewrite );

		try {
			$this->assertTrue( diluxone_users_notify( $user, 'diluxone_users_notify_security', 'Subject', 'Body' ) );
		} finally {
			remove_filter( 'diluxone_users_notification', $rewrite );
		}

		$this->assertSame( get_userdata( $user )->user_email, $this->lastMail()['to'] );
		$this->assertSame( '[Site] Subject', $this->lastMail()['subject'] );
		$this->assertSame( "Body\n--", $this->lastMail()['message'] );
	}

	/* ── A new device ────────────────────────────────────────────────── */

	public function test_the_first_device_is_remembered_and_not_announced(): void {
		$user                       = $this->make_user();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0';

		diluxone_users_notify_new_device( $user, 'link' );

		$this->assertSame( array(), self::$mail );
		$this->assertSame( array( diluxone_users_device_id() ), get_user_meta( $user, 'diluxone_users_devices', true ) );
	}

	public function test_a_second_device_is_announced_once_with_how_they_came_in(): void {
		$user                       = $this->make_user();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0';
		diluxone_users_notify_new_device( $user, 'link' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1';
		diluxone_users_notify_new_device( $user, 'passkey' );

		$this->assertCount( 1, self::$mail );
		$this->assertSame( get_userdata( $user )->user_email, $this->lastMail()['to'] );
		$this->assertStringContainsString( 'a passkey', (string) $this->lastMail()['message'] );
		$this->assertStringContainsString( 'Phone · Safari iOS', (string) $this->lastMail()['message'] );

		// The same device again is not news.
		diluxone_users_notify_new_device( $user, 'passkey' );
		$this->assertCount( 1, self::$mail );
		$this->assertCount( 2, get_user_meta( $user, 'diluxone_users_devices', true ) );
	}

	public function test_only_the_last_twenty_devices_are_remembered(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_devices', array_map( static fn( int $i ): string => 'device-' . $i, range( 1, 20 ) ) );
		$_SERVER['HTTP_USER_AGENT'] = 'Cover/1.0';

		diluxone_users_notify_new_device( $user, 'password' );

		$known = get_user_meta( $user, 'diluxone_users_devices', true );
		$this->assertCount( 20, $known );
		$this->assertSame( 'device-2', $known[0] );
		$this->assertSame( diluxone_users_device_id(), end( $known ) );
		$this->assertStringContainsString( 'your password', (string) $this->lastMail()['message'] );
	}

	public function test_each_way_in_has_its_words_and_an_unknown_one_keeps_its_name(): void {
		$this->assertSame( 'a link sent to your email', diluxone_users_via_label( 'link' ) );
		$this->assertSame( 'your password', diluxone_users_via_label( 'password' ) );
		$this->assertSame( 'a social account', diluxone_users_via_label( 'sso' ) );
		$this->assertSame( 'a passkey', diluxone_users_via_label( 'passkey' ) );
		$this->assertSame( 'magic', diluxone_users_via_label( 'magic' ) );
	}
}
