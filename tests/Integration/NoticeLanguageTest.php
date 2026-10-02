<?php
/**
 * A notice is written in the language of the person it goes to.
 *
 * The security notices are not always sent from that person's own request:
 * an administrator, or somebody else's browser on a stolen session, can be the
 * one whose request changes the account. The notice used to be written in
 * that request's language. These tests give the recipient a language of their
 * own, give the site a rewrite of each notice in that language and in the
 * site's, and require the recipient's.
 *
 * @package DiluxOneUsers
 */

namespace Tests\Integration;

class NoticeLanguageTest extends IntegrationTestCase {

	/** @var mixed */
	private $agent = null;

	protected function setUp(): void {
		parent::setUp();

		$this->agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

		$site = diluxone_users_mail_locale_key( get_locale() );

		diluxone_users_mail_rewrite( 'security_changed', $site, 'Site language: {site}', "{event}\n{account}" );
		diluxone_users_mail_rewrite( 'security_changed', 'es_ES', 'Idioma de la persona: {site}', "{event}\n{account}" );
		diluxone_users_mail_rewrite( 'new_device', $site, 'Site language: a device', "{device}\n{when}\n{via}\n{account}" );
		diluxone_users_mail_rewrite( 'new_device', 'es_ES', 'Idioma de la persona: un dispositivo', "{device}\n{when}\n{via}\n{account}" );
	}

	protected function tearDown(): void {
		if ( null === $this->agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->agent;
		}

		diluxone_users_delete_option( DILUXONE_USERS_MAIL_TEMPLATES );

		parent::tearDown();
	}

	private function person( string $locale ): int {
		$user = $this->make_user();

		update_user_meta( $user, 'locale', $locale );

		return $user;
	}

	public function test_a_security_notice_is_written_in_the_recipients_language(): void {
		$user = $this->person( 'es_ES' );

		diluxone_users_notify_security( $user, static fn (): string => 'Algo cambió.' );

		$this->assertSame( get_userdata( $user )->user_email, $this->lastMail()['to'] );
		$this->assertStringStartsWith( 'Idioma de la persona:', $this->lastMail()['subject'] );
		$this->assertStringContainsString( 'Algo cambió.', (string) $this->lastMail()['message'] );
	}

	public function test_somebody_with_no_language_of_their_own_reads_the_sites(): void {
		$user = $this->person( '' );

		diluxone_users_notify_security( $user, static fn (): string => 'Something changed.' );

		$this->assertStringStartsWith( 'Site language:', $this->lastMail()['subject'] );
	}

	public function test_the_language_is_put_back_after_the_notice_even_when_nobody_wants_it(): void {
		$before = get_locale();
		$user   = $this->person( 'es_ES' );

		update_user_meta( $user, 'diluxone_users_notify_security', '0' );
		diluxone_users_notify_security( $user, static fn (): string => 'Nadie lo quiere.' );

		$this->assertSame( array(), self::$mail, 'switched off, nothing is sent' );
		$this->assertSame( $before, get_locale() );
		$this->assertFalse( is_locale_switched() );
	}

	public function test_a_new_device_notice_is_written_in_the_recipients_language(): void {
		$user = $this->person( 'es_ES' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0';
		diluxone_users_notify_new_device( $user, 'link' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1';
		diluxone_users_notify_new_device( $user, 'passkey' );

		$this->assertCount( 1, self::$mail );
		$this->assertSame( 'Idioma de la persona: un dispositivo', $this->lastMail()['subject'] );
		$this->assertFalse( is_locale_switched() );
	}
}
