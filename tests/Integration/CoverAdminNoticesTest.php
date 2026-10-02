<?php
/**
 * E-mail notices: the summary, the rules and the e-mails a site rewrites.
 *
 * A rewritten e-mail is saved only behind the screen's nonce and the
 * capability, for the language it was written in. The plugin's own words sent
 * back unchanged are not a rewrite, ticking "put the plugin's own words back"
 * drops the site's text, an empty box asks for the plugin's own again, and a
 * text that lost what it cannot go out without is left exactly as it was and
 * the screen says why.
 *
 * Every case runs on both topologies: the screen is the hub's.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminNoticesTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var mixed */
	private $templates;

	protected function setUp(): void {
		parent::setUp();

		$this->templates = diluxone_users_raw_get( DILUXONE_USERS_MAIL_TEMPLATES, null );
		diluxone_users_delete_option( DILUXONE_USERS_MAIL_TEMPLATES );
	}

	protected function tearDown(): void {
		remove_filter( 'diluxone_users_notification_prefs', '__return_empty_array' );

		if ( null === $this->templates ) {
			diluxone_users_delete_option( DILUXONE_USERS_MAIL_TEMPLATES );
		} else {
			diluxone_users_update_option( DILUXONE_USERS_MAIL_TEMPLATES, $this->templates );
		}

		$this->cover_admin_reset();

		parent::tearDown();
	}

	/**
	 * Sends the templates tab as the screen does.
	 *
	 * @param array<string, mixed> $mail What each e-mail's boxes hold, by key.
	 * @return array{0: string, 1: string} How it ended, and what it printed.
	 */
	private function save( array $mail ): array {
		$this->postPanel(
			DILUXONE_USERS_NOTICES,
			array(
				'diluxone_users_mail'        => $mail,
				'diluxone_users_mail_locale' => get_locale(),
			)
		);

		ob_start();

		try {
			diluxone_users_mail_templates_save();
			$ended = 'returned';
		} catch ( \WPAjaxDieContinueException $e ) {
			$ended = 'died';
		}

		return array( $ended, (string) ob_get_clean() );
	}

	private function locale(): string {
		return diluxone_users_mail_locale_key( get_locale() );
	}

	/* ── Saving the e-mails ───────────────────────────────────────── */

	public function test_a_rewritten_email_is_saved_for_its_language_with_its_line_breaks_mended(): void {
		$this->the_admin();

		$ended = $this->save(
			array(
				'login_link' => array(
					'subject' => ' Come in to {site} <b>now</b> ',
					'body'    => "Hello {name},\r\nhere: {link}\r\n",
				),
			)
		);

		$this->assertSame( 'returned', $ended[0] );
		$this->assertSame(
			array(
				'subject' => 'Come in to {site} now',
				'body'    => "Hello {name},\nhere: {link}",
			),
			diluxone_users_mail_rewritten( 'login_link', $this->locale() )
		);
	}

	public function test_the_plugins_own_words_sent_back_are_not_a_rewrite(): void {
		$this->the_admin();
		$shipped = diluxone_users_mail_shipped( 'login_link', $this->locale() );

		$this->save( array( 'login_link' => $shipped ) );

		$this->assertSame( array( 'subject' => '', 'body' => '' ), diluxone_users_mail_rewritten( 'login_link', $this->locale() ) );
		$this->assertSame( array(), (array) diluxone_users_raw_get( DILUXONE_USERS_MAIL_TEMPLATES, array() ), 'nothing stored' );
	}

	public function test_putting_the_plugins_words_back_drops_the_sites_text(): void {
		$this->the_admin();
		diluxone_users_mail_rewrite( 'login_link', $this->locale(), 'Mine', 'Mine {link}' );

		$this->save( array( 'login_link' => array( 'shipped' => '1', 'subject' => 'Mine', 'body' => 'Mine {link}' ) ) );

		$this->assertSame( array( 'subject' => '', 'body' => '' ), diluxone_users_mail_rewritten( 'login_link', $this->locale() ) );
	}

	public function test_a_text_without_what_it_cannot_go_out_without_is_left_as_it_was(): void {
		$this->the_admin();
		diluxone_users_mail_rewrite( 'login_link', $this->locale(), 'Mine', 'Mine {link}' );

		$ended = $this->save(
			array(
				'login_link' => array(
					'subject' => 'Broken',
					'body'    => 'There is no way in here.',
				),
				'not_an_email' => array( 'subject' => 'x', 'body' => 'y' ),
			)
		);

		$this->assertStringContainsString( 'notice-error', $ended[1] );
		$this->assertStringContainsString( 'it cannot go out without {link}', $ended[1] );
		$this->assertSame( array( 'subject' => 'Mine', 'body' => 'Mine {link}' ), diluxone_users_mail_rewritten( 'login_link', $this->locale() ) );
	}

	public function test_an_empty_box_is_the_way_back_to_the_plugins_own(): void {
		$this->the_admin();
		diluxone_users_mail_rewrite( 'login_link', $this->locale(), 'Mine', 'Mine {link}' );

		$this->save( array( 'login_link' => array( 'subject' => '', 'body' => '' ) ) );

		$this->assertSame( array( 'subject' => '', 'body' => '' ), diluxone_users_mail_rewritten( 'login_link', $this->locale() ) );
	}

	public function test_the_emails_are_not_saved_without_the_nonce_or_the_capability(): void {
		$this->the_admin();
		$this->postAs( get_current_user_id(), array( 'diluxone_users_mail' => array( 'login_link' => array( 'subject' => 'X', 'body' => '{link}' ) ) ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_mail_templates_save' ) );

		$editor = $this->make_user( 'editor' );
		wp_set_current_user( $editor );
		$this->postAs(
			$editor,
			array(
				'diluxone_users_mail'        => array( 'login_link' => array( 'subject' => 'X', 'body' => '{link}' ) ),
				'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_NOTICES ),
			)
		);
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( 'diluxone_users_mail_templates_save' ) );

		$this->assertSame( array( 'subject' => '', 'body' => '' ), diluxone_users_mail_rewritten( 'login_link', $this->locale() ) );
	}

	public function test_the_language_written_in_comes_from_the_form_and_falls_back_to_the_sites(): void {
		$_POST = array( 'diluxone_users_mail_locale' => get_locale() );
		$this->assertSame( $this->locale(), diluxone_users_mail_screen_locale() );

		$_POST = array( 'diluxone_users_mail_locale' => 'xx_NOPE' );
		$this->assertSame( $this->locale(), diluxone_users_mail_screen_locale(), 'a language the site cannot run in is not one' );
	}

	/* ── The other two tabs ───────────────────────────────────────── */

	public function test_the_summary_warns_while_the_last_email_did_not_go_out(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time(), 'error' => 'refused' ) );

		$html = $this->draw( 'diluxone_users_screen_notices_summary' );

		$this->assertStringContainsString( 'The last e-mail this site sent did not go out', $html );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 1, 'time' => time() ) );
		$this->assertStringNotContainsString( 'did not go out', $this->draw( 'diluxone_users_screen_notices_summary' ) );
	}

	public function test_the_rules_with_nothing_registered_say_nobody_is_told_anything(): void {
		$this->the_admin();
		add_filter( 'diluxone_users_notification_prefs', '__return_empty_array' );

		$html = $this->draw( 'diluxone_users_screen_notices_rules' );

		$this->assertStringContainsString( 'Nothing is registered', $html );
		$this->assertStringNotContainsString( 'diluxone-users-rules', $html );
	}

	/* ── The rules ─────────────────────────────────────────────────── */

	/**
	 * The rules keep a policy for each notice the screen drew, with one of
	 * the answers it offers, and nothing else: a key nobody registered was
	 * kept as a rule for nothing.
	 */
	public function test_the_rules_keep_only_notices_there_are_with_answers_there_are(): void {
		$this->postPanel(
			DILUXONE_USERS_NOTICES,
			array(
				'diluxone_users_notice_rules' => array(
					'diluxone_users_notify_login'    => 'never',
					'diluxone_users_notify_security' => 'sometimes',
					'made_up'                        => 'never',
					'diluxone_users_notify_other'    => array( 'never' ),
				),
			)
		);
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_notices_rules_save' )[0] );

		$this->assertSame( array( 'diluxone_users_notify_login' => 'never' ), diluxone_users_notice_rules() );
		$this->assertArrayNotHasKey( 'made_up', (array) diluxone_users_raw_get( 'diluxone_users_notice_rules' ) );
		$this->assertSame( 'never', diluxone_users_notice_policy( 'diluxone_users_notify_login' ) );
	}

	public function test_the_rules_are_not_saved_without_the_nonce_or_the_capability(): void {
		$this->postAs( 1, array( 'diluxone_users_notice_rules' => array( 'diluxone_users_notify_login' => 'never' ), 'diluxone_users_panel_nonce' => 'nope' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_notices_rules_save' ) );

		$editor = $this->make_user( 'editor' );
		wp_set_current_user( $editor );
		$this->postAs( $editor, array( 'diluxone_users_notice_rules' => array( 'diluxone_users_notify_login' => 'never' ), 'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_NOTICES ) ) );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( 'diluxone_users_notices_rules_save' ) );

		$this->assertSame( array(), diluxone_users_notice_rules() );
	}
}
