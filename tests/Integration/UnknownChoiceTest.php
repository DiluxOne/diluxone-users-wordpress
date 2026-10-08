<?php
/**
 * A stored choice nothing offers today: the closest one on the front, kept on
 * the screen.
 *
 * An add-on offered a shape for the sign-in page or the account area, or a
 * second factor, the site chose it, and the add-on is not running now. The
 * page does not break and does not fall back to whatever the code happened to
 * say: it uses the closest answer of the plugin's own. The screen shows the
 * choice as inactive and chosen, and saving it does not throw it away.
 *
 * The shapes are the hub's and written from the main site; the second factor
 * is the network's and saved from Network Admin there — the same on both
 * topologies.
 */

namespace Tests\Integration;

class UnknownChoiceTest extends IntegrationTestCase {

	/* ── The sign-in page ──────────────────────────────────────────── */

	public function test_a_sign_in_shape_nothing_draws_is_the_card(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'addon_shape' );

		$this->assertSame( 'card', diluxone_users_login_template(), 'the shape of the plugin’s own that still takes the page over' );

		diluxone_users_update_option( 'diluxone_users_login_template', 'split' );
		$this->assertSame( 'split', diluxone_users_login_template(), 'a shape there is stays itself' );

		diluxone_users_update_option( 'diluxone_users_login_template', '' );
		$this->assertSame( 'plain', diluxone_users_login_template(), 'nothing is the page as it is' );
	}

	public function test_the_sign_in_screen_shows_it_and_its_save_keeps_it(): void {
		wp_set_current_user( 1 );
		diluxone_users_update_option( 'diluxone_users_login_template', 'addon_shape' );

		ob_start();
		diluxone_users_screen_login_shape();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="addon_shape" checked', $html );
		$this->assertStringContainsString( 'Your Pro choice (inactive)', $html );
		$this->assertStringNotContainsString( 'value="card" checked', $html, 'the card it is drawn as is not what is chosen' );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_template' => 'addon_shape' ) );
		diluxone_users_design_login_save();
		$this->assertSame( 'addon_shape', diluxone_users_raw_get( 'diluxone_users_login_template' ), 'kept' );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_template' => 'made_up' ) );
		diluxone_users_design_login_save();
		$this->assertSame( 'plain', diluxone_users_raw_get( 'diluxone_users_login_template' ), 'a word nobody offered is the plain one, as before' );
	}

	/* ── The account area ──────────────────────────────────────────── */

	public function test_an_account_shape_nothing_draws_is_the_simple_one_and_kept(): void {
		wp_set_current_user( 1 );
		diluxone_users_update_option( 'diluxone_users_account_template', 'addon_shape' );

		$this->assertSame( 'plain', diluxone_users_account_template() );

		ob_start();
		diluxone_users_screen_appearance_template();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="addon_shape" checked', $html );
		$this->assertStringContainsString( 'Your Pro choice (inactive)', $html );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_account_template' => 'addon_shape' ) );
		diluxone_users_design_account_save();
		$this->assertSame( 'addon_shape', diluxone_users_raw_get( 'diluxone_users_account_template' ), 'kept' );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_account_template' => 'plain' ) );
		diluxone_users_design_account_save();
		$this->assertSame( 'plain', diluxone_users_raw_get( 'diluxone_users_account_template' ), 'replaced once another is picked' );
	}

	/* ── A second factor ───────────────────────────────────────────── */

	public function test_a_second_factor_nothing_registers_is_shown_inactive(): void {
		wp_set_current_user( 1 );
		$this->in_network_admin();
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'addon_method' ) );

		$this->assertArrayNotHasKey( 'addon_method', diluxone_users_2fa_methods(), 'nobody is asked for it' );

		ob_start();
		diluxone_users_screen_login_2fa();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="diluxone_users_2fa_methods\[\]"\s+value="addon_method"[^>]*checked[^>]*disabled/s', $html, 'ticked and switched off' );
		$this->assertStringContainsString( 'Your Pro choice (inactive)', $html );
	}

	/* ── The pure answers ──────────────────────────────────────────── */

	public function test_what_is_unknown_and_what_a_save_keeps(): void {
		$this->assertSame( array( 'b' ), diluxone_users_unknown_entries( array( 'a', 'b', 3, array( 'c' ), '' ), array( 'a' ) ) );

		diluxone_users_update_option( 'diluxone_users_login_template', 'addon_shape' );

		$known = array( 'plain', 'card' );

		$this->assertSame( 'addon_shape', diluxone_users_unknown_choice( 'diluxone_users_login_template', $known ) );
		$this->assertSame( 'card', diluxone_users_choice_kept( 'diluxone_users_login_template', 'card', $known, 'plain' ) );
		$this->assertSame( 'addon_shape', diluxone_users_choice_kept( 'diluxone_users_login_template', 'addon_shape', $known, 'plain' ) );
		$this->assertSame( 'plain', diluxone_users_choice_kept( 'diluxone_users_login_template', 'other', $known, 'plain' ) );

		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );
		$this->assertSame( '', diluxone_users_unknown_choice( 'diluxone_users_login_template', $known ) );
	}
}
