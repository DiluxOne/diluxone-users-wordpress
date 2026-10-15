<?php
/**
 * A save writes what its screen drew, and keeps what it does not know.
 *
 * Two ways a screen used to lose somebody's choice without showing it. A save
 * read every answer it had a name for and wrote "empty" for each one that did
 * not arrive — fine while the screen draws all of them, and a wipe of a value
 * the screen never showed the day a part of it is drawn by something else, or
 * by nobody. And a save that writes a list or a map wrote only the entries it
 * recognised, so an entry something else stored (a provider, a second factor,
 * a section of the site's own) went with the next press of Save.
 *
 * Every case is about settings the hub owns or the network's second factor,
 * written from the main site: the same on both topologies.
 */

namespace Tests\Integration;

class SavesKeepUnknownTest extends IntegrationTestCase {

	/* ── Only what the screen drew ─────────────────────────────────── */

	public function test_the_sign_in_design_keeps_what_it_did_not_draw(): void {
		diluxone_users_update_option( 'diluxone_users_login_title', 'Welcome back' );
		diluxone_users_update_option( 'diluxone_users_sent_icon', 'circle' );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_template' => 'card' ) );
		diluxone_users_design_login_save();

		$this->assertSame( 'card', diluxone_users_raw_get( 'diluxone_users_login_template' ) );
		$this->assertSame( 'Welcome back', diluxone_users_raw_get( 'diluxone_users_login_title' ), 'a box that was not on the screen is not emptied' );
		$this->assertSame( 'circle', diluxone_users_raw_get( 'diluxone_users_sent_icon' ) );
	}

	public function test_the_sign_in_design_still_empties_a_box_it_drew(): void {
		diluxone_users_update_option( 'diluxone_users_login_title', 'Welcome back' );

		$this->postPanel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_login_template' => 'card',
				'diluxone_users_login_title'    => '',
			)
		);
		diluxone_users_design_login_save();

		$this->assertSame( '', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
	}

	public function test_the_account_design_keeps_a_ground_it_did_not_draw(): void {
		diluxone_users_update_option( 'diluxone_users_account_ground', '#eef3fb' );
		diluxone_users_update_option( 'diluxone_users_account_header', 1 );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_account_template' => 'plain' ) );
		diluxone_users_design_account_save();

		$this->assertSame( '#eef3fb', diluxone_users_raw_get( 'diluxone_users_account_ground' ), 'no swatches on the screen, no answer about them' );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_account_header' ), 'a tick box that was not drawn is not unticked' );
	}

	public function test_a_tick_box_that_was_drawn_and_left_unticked_is_off(): void {
		diluxone_users_update_option( 'diluxone_users_account_header', 1 );

		$this->postPanel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_account_template' => 'plain',
				'diluxone_users_panel_drawn'      => array( 'diluxone_users_account_header' ),
			)
		);
		diluxone_users_design_account_save();

		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_account_header' ) );
	}

	public function test_the_brand_keeps_the_colours_it_did_not_draw(): void {
		diluxone_users_update_option( 'diluxone_users_style_accent', '#aa0000' );
		diluxone_users_update_option( 'diluxone_users_button_icons', 1 );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_logo' => '7' ) );
		diluxone_users_design_brand_save();

		$this->assertSame( 7, (int) diluxone_users_raw_get( 'diluxone_users_login_logo' ) );
		$this->assertSame( '#aa0000', diluxone_users_raw_get( 'diluxone_users_style_accent' ) );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_button_icons' ) );
	}

	public function test_the_screen_draws_the_mark_a_tick_box_leaves(): void {
		ob_start();
		diluxone_users_ui_choice(
			array(
				'type'  => 'checkbox',
				'name'  => 'diluxone_users_some_list[]',
				'value' => 'one',
				'title' => 'One',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="diluxone_users_panel_drawn[]" value="diluxone_users_some_list">', $html );
	}

	/* ── The entries it does not know ──────────────────────────────── */

	public function test_a_second_factor_nothing_registers_today_is_kept(): void {
		// The second step is the network's: saved from Network Admin there.
		$this->in_network_admin();
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'addon_method' ) );

		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_2fa_methods' => array( 'email' ),
				'diluxone_users_2fa_link'    => 'auto',
			)
		);
		$this->assertTrue( diluxone_users_2fa_save() );

		$this->assertSame( array( 'email', 'addon_method' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ) );
	}

	public function test_a_second_factor_nothing_registers_answers_nothing(): void {
		$this->in_network_admin();
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'addon_method' ) );

		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_2fa_link'    => 'auto',
				// The boxes were drawn and none ticked: the line the save reads.
				'diluxone_users_panel_drawn' => array( 'diluxone_users_2fa_methods' ),
			)
		);

		ob_start();
		$saved = diluxone_users_2fa_save();
		ob_end_clean();

		$this->assertFalse( $saved, 'the step on with only a method that sends no code is refused' );
		$this->assertSame( array( 'email', 'addon_method' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ) );
	}

	public function test_a_provider_and_a_field_nothing_knows_survive_a_save(): void {
		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				'addon_provider' => array(
					'active' => 1,
					'id'     => 'x',
					'secret' => 'y',
				),
				'google'         => array(
					'active'  => 0,
					'id'      => 'old',
					'secret'  => 'old',
					'issuer'  => 'https://example.test',
				),
			),
			false
		);

		diluxone_users_sso_save_credentials(
			'google',
			array(
				'id'     => 'new',
				'secret' => 'new',
			)
		);
		diluxone_users_sso_set_tested( 'google', true );

		$all = (array) diluxone_users_raw_get( 'diluxone_users_sso', array() );

		$this->assertSame( 'x', $all['addon_provider']['id'] ?? null, 'another provider’s row is left alone' );
		$this->assertSame( 'new', $all['google']['id'] );
		$this->assertSame( 'https://example.test', $all['google']['issuer'] ?? null, 'what else the row held stays in it' );
	}

	public function test_a_section_of_the_sites_own_survives_the_others_being_saved(): void {
		diluxone_users_update_option(
			'diluxone_users_account_sections',
			array(
				'addon_section' => array(
					'custom'  => true,
					'label'   => 'Somewhere',
					'slug'    => 'somewhere',
					'enabled' => 1,
				),
			)
		);

		diluxone_users_section_config_save( 'home', array( 'label' => 'Start' ) );
		diluxone_users_section_reorder( array( 'home', 'details' ) );

		$all = (array) diluxone_users_raw_get( 'diluxone_users_account_sections', array() );

		$this->assertSame( 'somewhere', $all['addon_section']['slug'] ?? null );
		$this->assertTrue( $all['addon_section']['custom'] ?? false );
		$this->assertSame( 'Start', $all['home']['label'] ?? null );
	}
}
