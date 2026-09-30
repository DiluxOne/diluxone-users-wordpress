<?php
/**
 * The button that saves a tab is in the column beside it, on every tab.
 *
 * It used to end the form, two windows of scrolling below the first setting.
 * Now whichever second column a tab has draws it first — the rail, the
 * preview, or a rail of its own when the tab had nothing else to put there —
 * and it reaches its form by id, since it is no longer inside it.
 */

namespace Tests\Integration;

class SaveBoxTest extends IntegrationTestCase {

	protected function tearDown(): void {
		diluxone_users_ui_save_queue();
		parent::tearDown();
	}

	public function test_the_rail_opens_with_the_button(): void {
		ob_start();
		diluxone_users_ui_aside_open();
		diluxone_users_ui_save( 'a-form', 'Keep it' );
		diluxone_users_ui_aside_close(
			static function (): void {
				echo '<p class="said">about this tab</p>';
			}
		);
		$html = (string) ob_get_clean();

		$aside = substr( $html, (int) strpos( $html, 'diluxone-users-studio__aside' ) );

		$this->assertStringContainsString( 'form="a-form"', $aside );
		$this->assertLessThan( strpos( $aside, 'class="said"' ), strpos( $aside, 'du-save' ), 'The button comes before what the rail says' );
		$this->assertFalse( diluxone_users_ui_save_pending(), 'Drawn once, and not again by the next column' );
	}

	public function test_a_tab_with_nothing_beside_it_gets_a_rail_for_the_button(): void {
		diluxone_users_register_panel(
			'diluxone-users-test-save',
			'plain',
			array(
				'label'  => 'Plain',
				'render' => static function (): void {
					echo '<p class="only-settings">settings</p>';
				},
				'save'   => static function (): void {},
			)
		);

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET['tab'] = 'plain';

		ob_start();
		diluxone_users_screen_panels( 'diluxone-users-test-save', 'Test' );
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'diluxone-users-studio__aside', $html );
		$this->assertStringContainsString( 'form="' . DILUXONE_USERS_PANEL_FORM . '"', $html );
		$this->assertStringNotContainsString( 'id="submit"', $html, 'No button left at the foot of the form' );
	}

	public function test_a_tab_that_saves_nothing_draws_no_button(): void {
		diluxone_users_register_panel(
			'diluxone-users-test-save',
			'summary',
			array(
				'label'  => 'Summary',
				'render' => static function (): void {
					echo '<p>state</p>';
				},
				'form'   => false,
			)
		);

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET['tab'] = 'summary';

		ob_start();
		diluxone_users_screen_panels( 'diluxone-users-test-save', 'Test' );
		$html = (string) ob_get_clean();

		unset( $_GET['tab'] );

		$this->assertStringNotContainsString( 'du-save', $html );
	}
}
