<?php
/**
 * The e-mail templates' store and how a message is put together from it.
 *
 * What is stored is read defensively — a row edited by hand must not reach a
 * sending as an array — a rewrite that loses a placeholder the message cannot
 * go without is not used, and an empty rewrite is no rewrite at all. Every
 * case runs on both topologies: the templates are the hub's.
 */

namespace Tests\Integration;

class CoverSettingsMailTest extends IntegrationTestCase {

	protected function tearDown(): void {
		// A map of languages and not a setting with a default: the base class
		// does not know it, so it is cleaned here.
		diluxone_users_delete_option( DILUXONE_USERS_MAIL_TEMPLATES );

		parent::tearDown();
	}

	public function test_a_message_nobody_registered_has_no_text(): void {
		$this->assertSame(
			array(
				'subject' => '',
				'body'    => '',
			),
			diluxone_users_mail_shipped( 'no_such_message' )
		);

		$this->assertSame( diluxone_users_mail_shipped( 'no_such_message' ), diluxone_users_mail_compose( 'no_such_message', array( '{link}' => 'x' ) ) );
	}

	public function test_a_store_edited_by_hand_is_cut_down_to_its_shape(): void {
		$clean = diluxone_users_mail_clean(
			array(
				0             => array( 'login_link' => array( 'subject' => 'Numbered language' ) ),
				'es_AR'       => 'not a list of messages',
				'en_US'       => array(
					0            => array( 'subject' => 'Numbered message' ),
					'login_link' => array(
						'subject' => array( 'not', 'a', 'string' ),
						'body'    => 'Only the body {link}',
					),
					'second_step' => array(
						'subject' => '',
						'body'    => '',
					),
					'broken'     => 'not a message',
				),
				'not a locale' => array( 'login_link' => array( 'subject' => 'Lands in English' ) ),
			)
		);

		$this->assertSame(
			array(
				'en_US' => array(
					'login_link' => array(
						'subject' => 'Lands in English',
						'body'    => '',
					),
				),
			),
			$clean,
			'only rows shaped like a rewrite survive, and a key that is not a locale is the plugin’s own language'
		);

		$this->assertSame( array(), diluxone_users_mail_clean( 'a string' ) );
	}

	public function test_a_rewrite_is_written_and_an_empty_one_takes_its_language_with_it(): void {
		diluxone_users_mail_rewrite( 'login_link', 'en_US', 'Your link', 'Click {link}' );
		diluxone_users_mail_rewrite( 'second_step', 'en_US', 'Your code', '' );

		$this->assertSame(
			array(
				'subject' => 'Your link',
				'body'    => 'Click {link}',
			),
			diluxone_users_mail_rewritten( 'login_link', 'en_US' )
		);

		diluxone_users_mail_rewrite( 'login_link', 'en_US', '', '' );
		$this->assertArrayHasKey( 'en_US', diluxone_users_mail_store(), 'the language stays while it holds something' );

		diluxone_users_mail_rewrite( 'second_step', 'en_US', '', '' );
		$this->assertSame( array(), diluxone_users_mail_store(), 'and goes when it holds nothing' );
	}

	public function test_a_rewrite_that_drops_the_link_is_not_sent(): void {
		$shipped = diluxone_users_mail_shipped( 'login_link' );

		diluxone_users_mail_rewrite( 'login_link', diluxone_users_mail_locale(), 'Hello {name}', 'No link in here.' );

		$mail = diluxone_users_mail_compose(
			'login_link',
			array(
				'{link}' => 'https://example.test/go',
				'{name}' => 'Ana',
			)
		);

		$this->assertSame( 'Hello Ana', $mail['subject'], 'the subject it rewrote is used' );
		$this->assertStringContainsString( 'https://example.test/go', $mail['body'], 'the plugin’s own body, with its link' );
		$this->assertStringNotContainsString( 'No link in here.', $mail['body'] );
		$this->assertNotSame( '', $shipped['body'] );
	}

	public function test_somebody_with_no_account_is_called_by_their_address(): void {
		$this->assertSame( '', diluxone_users_mail_person( '' ) );
		$this->assertSame( diluxone_users_name_from_email( 'juan.perez88@example.test' ), diluxone_users_mail_person( 'juan.perez88@example.test' ) );

		$user = $this->make_user();
		wp_update_user(
			array(
				'ID'           => $user,
				'display_name' => 'Cover Person',
			)
		);

		$this->assertSame( 'Cover Person', diluxone_users_mail_person( 'whatever@example.test', $user ) );
	}

	public function test_a_language_with_no_name_of_its_own_is_called_by_its_code(): void {
		$this->assertSame( 'xx', diluxone_users_mail_language_name( 'xx' ) );
		$this->assertContains( 'en_US', diluxone_users_mail_languages() );
	}

	/** A value is filled in once: one that looks like a placeholder stays text. */
	public function test_a_value_cannot_bring_in_another_placeholder(): void {
		diluxone_users_mail_rewrite( 'login_link', diluxone_users_mail_locale(), 'Your link', 'Hello {name}: {link}' );

		$mail = diluxone_users_mail_compose(
			'login_link',
			array(
				'{link}'    => 'https://evil.test/the-link',
				'{name}'    => '{link}',
				'{minutes}' => '15',
				'{site}'    => 'Site',
			)
		);

		$this->assertSame( 'Hello {link}: https://evil.test/the-link', $mail['body'], 'the link once, where the link goes, and the name as it was typed' );
	}

	/**
	 * A message an add-on registers through `diluxone_users_mail_templates` is
	 * written and filled like the plugin's own, and a rewrite that drops a
	 * required placeholder falls back to the shipped text.
	 */
	public function test_an_add_ons_message_is_filled_and_kept_whole(): void {
		$this->hook(
			'diluxone_users_mail_templates',
			static function ( array $templates ): array {
				$templates['acme_welcome'] = array(
					'label'    => 'Welcome',
					'help'     => '',
					'vars'     => array( '{x}' => 'x' ),
					'required' => array( '{x}' ),
					'shipped'  => static fn(): array => array(
						'subject' => 'Hi {x}',
						'body'    => 'Body {x}',
					),
				);

				return $templates;
			}
		);

		$this->assertSame( array( 'subject' => 'Hi Y', 'body' => 'Body Y' ), diluxone_users_mail_compose( 'acme_welcome', array( '{x}' => 'Y' ) ) );

		diluxone_users_mail_rewrite( 'acme_welcome', diluxone_users_mail_locale(), 'S', 'no placeholder' );

		$this->assertSame( 'Body Y', diluxone_users_mail_compose( 'acme_welcome', array( '{x}' => 'Y' ) )['body'], 'the shipped body, since the rewrite lost {x}' );
	}
}
