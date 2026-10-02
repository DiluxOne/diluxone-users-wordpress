<?php
/**
 * The linked-accounts tab and the notifications tab, read from their markup.
 *
 * Linked accounts: nothing for somebody signed out, the two boxes the tab is
 * split into, a linked network with its Unlink form and nonce, one not linked
 * with its Link button, and what each box says when it has nothing in it.
 * Notifications: the saved notice, a site that leaves nothing to choose, and
 * one that sends nothing at all.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class CoverAccountLinkedTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		MockProvider::install();
	}

	protected function tearDown(): void {
		MockProvider::remove();
		remove_filter( 'diluxone_users_notification_prefs', '__return_empty_array' );

		parent::tearDown();
	}

	/** One template, as the theme would be handed it. */
	private function template( string $name, array $args ): string {
		ob_start();
		diluxone_users_template_part( $name, $args );

		return (string) ob_get_clean();
	}

	public function test_nothing_is_drawn_for_somebody_signed_out(): void {
		ob_start();
		diluxone_users_accounts_list();
		$this->assertSame( '', (string) ob_get_clean() );

		$this->assertSame( '', diluxone_users_shortcode_notifications() );
	}

	public function test_the_tab_splits_what_is_linked_from_what_can_be(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		ob_start();
		diluxone_users_section_accounts( get_userdata( $user ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Networks you can sign in with', $html );
		$this->assertStringContainsString( 'None yet. Link one below and it opens this same account.', $html );
		$this->assertStringContainsString( 'Networks you can link', $html );
		$this->assertStringContainsString( '<strong>Mock</strong>', $html );
		$this->assertStringContainsString( 'Not linked', $html );
		$this->assertStringContainsString( esc_url( diluxone_users_sso_link_url( MockProvider::ID ) ), $html );
		$this->assertStringNotContainsString( 'diluxone_users_sso_unlink', $html );
	}

	public function test_a_linked_network_offers_to_unlink_with_the_persons_nonce(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_sso_' . MockProvider::ID, 'sub-1' );
		wp_set_current_user( $user );

		ob_start();
		diluxone_users_section_accounts( get_userdata( $user ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'is-linked', $html );
		$this->assertStringContainsString( 'Linked to your account', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_sso_unlink"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_provider" value="' . MockProvider::ID . '"', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( 'diluxone_users_sso_unlink' ) . '"', $html );
		$this->assertStringContainsString( 'You already have them all linked.', $html );
		$this->assertStringContainsString( '--diluxone-users-brand: #000000', $html );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public function states(): array {
		return array(
			'linked' => array( 'linked', 'ok', 'Account linked.' ),
			'taken'  => array( 'taken', 'error', 'That social account already opens another account on this site' ),
		);
	}

	/** @dataProvider states */
	public function test_the_list_says_how_the_trip_to_the_provider_went( string $state, string $tone, string $text ): void {
		$html = $this->template(
			'accounts.php',
			array(
				'providers' => array(),
				'linked'    => array(),
				'state'     => $state,
				'only'      => '',
			)
		);

		$this->assertStringContainsString( 'diluxone-users-notice--' . $tone . '">' . $text, $html );
		$this->assertStringContainsString( 'No provider has been set up yet.', $html );
	}

	public function test_the_notifications_say_saved(): void {
		wp_set_current_user( $this->make_user() );
		$_GET = array( 'diluxone-users' => 'saved' );

		$html = diluxone_users_shortcode_notifications();

		$this->assertStringContainsString( 'diluxone-users-notice--ok">Saved.', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_notifications"', $html );
	}

	public function test_with_nothing_to_choose_the_notifications_only_say_what_is_sent(): void {
		add_filter( 'diluxone_users_notification_prefs', '__return_empty_array' );
		wp_set_current_user( $this->make_user() );

		$html = diluxone_users_shortcode_notifications();

		$this->assertStringContainsString( 'There is nothing to choose here', $html );
		$this->assertStringNotContainsString( 'name="action" value="diluxone_users_notifications"', $html, 'No form with nothing to save' );
	}

	public function test_a_site_that_sends_nothing_says_so(): void {
		$html = $this->template(
			'account/notifications',
			array(
				'prefs' => array(),
				'musts' => array(),
				'user'  => get_userdata( 1 ),
			)
		);

		$this->assertStringContainsString( 'This site does not email you about anything.', $html );
		$this->assertStringNotContainsString( 'What we email you about', $html );
	}
}
