<?php
/**
 * Where a Users+ Pro feature would go: a picture of it, and nothing more.
 *
 * Drawn only to whoever administers the plugin's screens, only on them, only
 * while the setting is on, and only while nothing — Pro itself, or a site —
 * said no for that place. The picture is out of reach and sends nothing with
 * the form it is drawn in; the way out is an escaped link to diluxone.com
 * that says which screen it came from.
 *
 * The setting is the network's: on a network it is set in Network Admin, on
 * a single site on Maintenance › Tools.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class ProPreviewTest extends IntegrationTestCase {

	use CoverAdminSupport;

	private const ARGS = array(
		'where'  => 'design/login/shapes',
		'screen' => 'diluxone-users-design',
		'title'  => 'Two more shapes',
		'text'   => 'A picture beside the form, or behind it.',
	);

	protected function tearDown(): void {
		diluxone_users_delete_option( 'diluxone_users_pro_previews' );
		$this->cover_admin_reset();
		parent::tearDown();
	}

	/** A picture made with the screen's own pieces, a control with a name in it. */
	public function picture(): void {
		diluxone_users_ui_choices(
			array(
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_made_up',
					'value'   => '1',
					'checked' => true,
					'title'   => 'Made up for the picture',
				),
			)
		);
		echo '<input type="text" name="diluxone_users_other" value="x" form="elsewhere">';
	}

	private function preview(): string {
		return $this->draw( 'diluxone_users_ui_pro', self::ARGS, array( $this, 'picture' ) );
	}

	public function test_the_picture_is_out_of_reach_and_sends_nothing(): void {
		$this->the_admin();

		$html = $this->preview();

		$this->assertStringContainsString( '<div class="du-pro__picture" inert aria-hidden="true">', $html );
		$this->assertStringContainsString( 'Made up for the picture', $html, 'the picture is drawn' );
		$this->assertStringNotContainsString( 'name=', $html, 'no control in it is posted with the form around it' );
		$this->assertStringNotContainsString( 'form="elsewhere"', $html );
		$this->assertStringContainsString( 'diluxone-users-state--pro', $html, 'the badge' );
		$this->assertStringContainsString( 'A picture beside the form, or behind it.', $html );
	}

	public function test_the_way_out_is_escaped_and_says_where_it_came_from(): void {
		$this->the_admin();

		$html = $this->preview();

		$this->assertStringContainsString( 'href="https://diluxone.com/users-pro/?utm_source=diluxone-users&#038;utm_medium=diluxone-users-design&#038;utm_campaign=free"', $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'Learn about Users+ Pro', $html );

		$this->assertSame(
			'https://diluxone.com/users-pro/?utm_source=diluxone-users&utm_medium=scriptalertxscript&utm_campaign=free',
			diluxone_users_pro_url( '"><script>alert(x)</script>' ),
			'a screen name is a word in the address and nothing else'
		);
	}

	public function test_nothing_when_the_setting_is_off(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_pro_previews', 0 );

		$this->assertSame( '', $this->preview() );
		$this->assertSame( '', $this->draw( 'diluxone_users_ui_pro_card', self::ARGS ) );
	}

	public function test_nothing_where_a_filter_says_no_and_the_rest_stays(): void {
		$this->the_admin();
		$this->hook( 'diluxone_users_upsell', static fn( bool $show, string $where ): bool => 'design/login/shapes' !== $where && $show, 10, 2 );

		$this->assertSame( '', $this->preview(), 'Pro draws this one for real' );
		$this->assertNotSame( '', $this->draw( 'diluxone_users_ui_pro_card', array( 'where' => 'membership/policy' ) + self::ARGS ), 'another place still says it' );
	}

	public function test_nothing_on_the_front_end(): void {
		$this->the_admin();
		$this->as_front_end();

		$this->assertSame( '', $this->preview() );
		$this->assertSame( '', $this->draw( 'diluxone_users_ui_pro_card', self::ARGS ) );
	}

	public function test_nothing_for_somebody_who_does_not_administer_the_screens(): void {
		wp_set_current_user( $this->make_user( 'editor' ) );

		$this->assertSame( '', $this->preview() );
	}

	public function test_the_card_after_the_real_answers_has_no_control(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_ui_pro_card', self::ARGS );

		$this->assertStringContainsString( 'class="du-pro-card"', $html );
		$this->assertStringContainsString( 'Two more shapes', $html );
		$this->assertStringNotContainsString( '<input', $html, 'a card to read, not an answer to pick' );
		$this->assertStringContainsString( 'utm_campaign=free', $html );
	}

	public function test_the_markup_the_plugin_prints_keeps_inert(): void {
		$this->assertArrayHasKey( 'inert', diluxone_users_allowed_html()['div'] );
		$this->assertSame( 'pro', array_key_last( diluxone_users_states() ) );
	}

	/* ── The setting ───────────────────────────────────────────────── */

	public function test_the_setting_is_the_networks_and_on_by_default(): void {
		$this->assertSame( 'network', diluxone_users_option_scope( 'diluxone_users_pro_previews' ) );
		$this->assertSame( 1, diluxone_users_option( 'diluxone_users_pro_previews' ) );
	}

	public function test_a_single_site_turns_it_off_and_on_from_tools(): void {
		$this->single_only( 'test_a_network_turns_it_off_and_on_from_network_admin' );
		$this->the_admin();

		$this->assertStringContainsString( 'name="previews"', $this->draw( 'diluxone_users_screen_tools_boxes' ) );

		$this->postAs( get_current_user_id(), array( 'tool' => 'previews', '_wpnonce' => wp_create_nonce( 'diluxone_users_tools' ) ) );
		$this->ended( 'diluxone_users_tools_action' );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_pro_previews' ) );

		$this->postAs( get_current_user_id(), array( 'tool' => 'previews', 'previews' => '1', '_wpnonce' => wp_create_nonce( 'diluxone_users_tools' ) ) );
		$this->ended( 'diluxone_users_tools_action' );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_pro_previews' ) );
	}

	public function test_a_network_turns_it_off_and_on_from_network_admin(): void {
		$this->network_only( 'test_a_single_site_turns_it_off_and_on_from_tools' );
		$this->the_admin();

		// A site's Tools does not offer the network's setting.
		$this->assertStringNotContainsString( 'name="previews"', $this->draw( 'diluxone_users_screen_tools_boxes' ) );

		$this->where_network_screens_are();
		$this->assertSame( 'network', diluxone_users_panel_scope( DILUXONE_USERS_MENU, 'previews' ) );

		$this->postPanel( DILUXONE_USERS_MENU, array() );
		$this->ended( 'diluxone_users_network_previews_save' );
		$this->assertSame( 0, (int) get_site_option( 'diluxone_users_pro_previews' ) );

		$this->postPanel( DILUXONE_USERS_MENU, array( 'diluxone_users_pro_previews' => '1' ) );
		$this->ended( 'diluxone_users_network_previews_save' );
		$this->assertSame( 1, (int) get_site_option( 'diluxone_users_pro_previews' ) );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_pro_previews"\s+value="1"\s+checked/', $this->draw( 'diluxone_users_screen_network_previews' ) );
	}
}
