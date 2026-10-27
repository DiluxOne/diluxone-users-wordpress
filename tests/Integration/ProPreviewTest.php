<?php
/**
 * Where a Users+ Pro feature would go: the control it would be, switched off,
 * and nothing more.
 *
 * Drawn only to whoever administers the plugin's screens, only on them, only
 * while the setting is on, and only while nothing — Pro itself, or a site —
 * said no for that place. Whatever shape it takes it is disabled and has no
 * name, so it sends nothing with the form it is drawn in; it carries the
 * badge and one way out, an escaped link to diluxone.com that says which
 * screen it came from.
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

	/** The pattern's one choice, drawn in a group after a real answer. */
	private function choices(): string {
		return $this->draw(
			'diluxone_users_ui_choices',
			diluxone_users_ui_pro_choices(
				array(
					array(
						'name'  => 'diluxone_users_real',
						'value' => 'yes',
						'title' => 'A real answer',
					),
				),
				array( self::ARGS )
			)
		);
	}

	/** Every shape the pattern takes, drawn. */
	private function shapes(): array {
		return array(
			'choice'  => $this->choices(),
			'button'  => $this->draw( 'diluxone_users_ui_pro_button', self::ARGS ),
			'card'    => $this->draw( 'diluxone_users_ui_pro_card', self::ARGS + array( 'icon' => 'dashicons-admin-site' ) ),
			'shape'   => $this->draw( 'diluxone_users_ui_pro_shape', self::ARGS + array( 'art' => 'login-split' ) ),
			'row'     => $this->draw( 'diluxone_users_summary_table', array_filter( array( diluxone_users_ui_pro_row( self::ARGS ) ) ) ),
			'control' => $this->draw(
				'diluxone_users_ui_pro_control',
				self::ARGS,
				static function (): void {
					echo '<select disabled><option>Made up</option></select>';
				}
			),
		);
	}

	public function test_every_shape_is_the_control_switched_off_with_the_badge_and_one_link(): void {
		$this->the_admin();

		// Printed through wp_kses() in some shapes, which writes the ampersand
		// its own way; the address is the same.
		$link = '/href="https:\/\/diluxone\.com\/wordpress-plugins\/users\/\?utm_source=diluxone-users(&#038;|&amp;)utm_medium=diluxone-users-design(&#038;|&amp;)utm_campaign=free"/';

		foreach ( $this->shapes() as $shape => $html ) {
			$this->assertStringContainsString( 'data-diluxone-users-pro="design/login/shapes"', $html, "$shape: marked for the filter" );
			$this->assertStringContainsString( 'diluxone-users-state--pro', $html, "$shape: the badge" );
			$this->assertSame( 1, substr_count( $html, 'Learn about Users+ Pro' ), "$shape: one way out" );
			$this->assertMatchesRegularExpression( $link, $html, "$shape: the escaped link, with the screen as its medium" );
			$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html, "$shape: leaves the dashboard and says so" );
			$this->assertStringContainsString( '(opens in a new tab)', $html, "$shape: for a screen reader too" );
			$this->assertStringContainsString( 'A picture beside the form, or behind it.', $html, "$shape: what Pro adds" );
			$this->assertStringNotContainsString( 'inert', $html, "$shape: nothing is a picture" );
		}
	}

	public function test_a_control_in_it_is_disabled_and_has_no_name(): void {
		$this->the_admin();

		$shapes = $this->shapes();

		foreach ( array( 'choice', 'button', 'shape', 'control' ) as $shape ) {
			$pro = substr( $shapes[ $shape ], (int) strpos( $shapes[ $shape ], 'data-diluxone-users-pro=' ) );

			$this->assertMatchesRegularExpression( '/<(input|button|select)[^>]*\sdisabled/', $pro, "$shape: the control it would be, switched off" );
			$this->assertStringNotContainsString( 'name="', $pro, "$shape: nothing in it is posted with the form around it" );
		}

		$this->assertStringNotContainsString( 'diluxone_users_panel_drawn', substr( $shapes['choice'], (int) strpos( $shapes['choice'], 'data-diluxone-users-pro=' ) ), 'and no line for the save either' );
		$this->assertStringNotContainsString( '<input', $shapes['card'], 'the card has nothing to open' );
		$this->assertStringNotContainsString( '<button', $shapes['card'] );
		$this->assertStringNotContainsString( '<input', $shapes['row'] );
	}

	public function test_the_choice_comes_after_the_real_answers_as_one_of_them(): void {
		$this->the_admin();

		$html = $this->choices();
		$real = strpos( $html, 'name="diluxone_users_real"' );
		$pro  = strpos( $html, 'data-diluxone-users-pro="design/login/shapes"' );

		$this->assertNotFalse( $real );
		$this->assertNotFalse( $pro );
		$this->assertGreaterThan( $real, $pro, 'after the real answer' );
		$this->assertStringContainsString( 'class="du-choice is-locked du-pro du-pro--choice"', $html, 'drawn as the real ones are, locked' );
		$this->assertSame( 2, substr_count( $html, '<label class="du-choice' ), 'in the same group' );
		$this->assertStringContainsString( 'Two more shapes', $html );
	}

	public function test_a_wait_on_the_group_does_not_reach_the_pro_choice(): void {
		$this->the_admin();

		$html = $this->draw(
			'diluxone_users_ui_choices',
			diluxone_users_ui_pro_choices(
				array(
					array(
						'type'    => 'checkbox',
						'name'    => 'diluxone_users_real[]',
						'value'   => 'yes',
						'checked' => true,
						'title'   => 'A real answer',
					),
				),
				array( array( 'type' => 'checkbox' ) + self::ARGS )
			),
			'',
			array(),
			array(
				'why'     => 'Waiting for another answer.',
				'name'    => 'diluxone_users_elsewhere',
				'is'      => array( 'yes' ),
				'waiting' => true,
			)
		);

		$real = substr( $html, (int) strpos( $html, 'name="diluxone_users_real[]"' ), 400 );
		$pro  = substr( $html, (int) strpos( $html, 'data-diluxone-users-pro="design/login/shapes"' ) );

		$this->assertStringContainsString( 'data-diluxone-users-waited', $real, 'the real box waits: disabled, unticked, marked for the script to let go' );
		$this->assertStringNotContainsString( 'checked', $real );
		$this->assertStringNotContainsString( 'data-diluxone-users-waited', $pro, 'the wait never reaches the Pro box' );
		$this->assertMatchesRegularExpression( '/<input\s+type="checkbox"[^>]*\sdisabled/', $pro, 'disabled for a reason of its own' );
		$this->assertStringContainsString( 'diluxone-users-state--pro', $pro, 'and its badge stays while the group waits' );
	}

	public function test_a_checkbox_is_a_checkbox_and_a_shape_a_radio_in_a_grid(): void {
		$this->the_admin();

		$box = diluxone_users_ui_pro_choice( array( 'type' => 'checkbox' ) + self::ARGS );

		$this->assertSame( 'checkbox', $box['type'] );
		$this->assertSame( '', $box['name'] );
		$this->assertTrue( $box['disabled'] );
		$this->assertSame( 'pro', $box['state'] );

		$shape = $this->draw( 'diluxone_users_ui_pro_shape', self::ARGS + array( 'art' => 'login-split' ) );

		$this->assertStringContainsString( 'class="diluxone-users-templates__one du-pro du-pro--shape"', $shape, 'one more card of the grid' );
		$this->assertStringContainsString( '<input type="radio" disabled>', $shape );
		$this->assertStringContainsString( 'diluxone-users-templates__art--login-split', $shape );
	}

	public function test_the_row_is_a_row_of_the_table_with_the_badge_where_the_state_goes(): void {
		$this->the_admin();

		$row = diluxone_users_ui_pro_row( self::ARGS );

		$this->assertSame( 'pro', $row['state'] );
		$this->assertSame( 'design/login/shapes', $row['pro'] );
		$this->assertSame( 'Learn about Users+ Pro', $row['change'] );

		$html = $this->draw( 'diluxone_users_summary_table', array( array( 'label' => 'Real', 'state' => 'active' ), $row ) );

		$this->assertSame( 2, substr_count( $html, '<tr' ) );
		$this->assertStringContainsString( '<tr class="du-pro du-pro--row" data-diluxone-users-pro="design/login/shapes">', $html );
		$this->assertGreaterThan( strpos( $html, 'Real' ), strpos( $html, 'du-pro--row' ), 'after the real rows' );
	}

	public function test_the_address_is_escaped_and_says_where_it_came_from(): void {
		$this->assertSame(
			'https://diluxone.com/wordpress-plugins/users/?utm_source=diluxone-users&utm_medium=scriptalertxscript&utm_campaign=free',
			diluxone_users_pro_url( '"><script>alert(x)</script>' ),
			'a screen name is a word in the address and nothing else'
		);
	}

	public function test_nothing_when_the_setting_is_off(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_pro_previews', 0 );

		$this->assertSame( array(), diluxone_users_ui_pro_choice( self::ARGS ) );
		$this->assertSame( array(), diluxone_users_ui_pro_row( self::ARGS ) );

		foreach ( $this->shapes() as $shape => $html ) {
			$this->assertStringNotContainsString( 'du-pro', $html, $shape );
		}
	}

	public function test_nothing_where_a_filter_says_no_and_the_rest_stays(): void {
		$this->the_admin();
		$this->hook( 'diluxone_users_upsell', static fn( bool $show, string $where ): bool => 'design/login/shapes' !== $where && $show, 10, 2 );

		$this->assertSame( '', $this->draw( 'diluxone_users_ui_pro_button', self::ARGS ), 'Pro draws this one for real' );
		$this->assertSame( array(), diluxone_users_ui_pro_choice( self::ARGS ) );
		$this->assertNotSame( '', $this->draw( 'diluxone_users_ui_pro_button', array( 'where' => 'membership/policy' ) + self::ARGS ), 'another place still says it' );
	}

	public function test_nothing_on_the_front_end(): void {
		$this->the_admin();
		$this->as_front_end();

		$this->assertSame( '', $this->draw( 'diluxone_users_ui_pro_button', self::ARGS ) );
		$this->assertSame( '', $this->draw( 'diluxone_users_ui_pro_card', self::ARGS ) );
		$this->assertSame( array(), diluxone_users_ui_pro_choice( self::ARGS ) );
	}

	public function test_nothing_for_somebody_who_does_not_administer_the_screens(): void {
		wp_set_current_user( $this->make_user( 'editor' ) );

		$this->assertSame( '', $this->draw( 'diluxone_users_ui_pro_button', self::ARGS ) );
		$this->assertSame( array(), diluxone_users_ui_pro_choice( self::ARGS ) );
	}

	public function test_the_pro_state_is_the_last_one(): void {
		$this->assertSame( 'pro', array_key_last( diluxone_users_states() ) );
		$this->assertArrayNotHasKey( 'inert', diluxone_users_allowed_html()['div'], 'nothing the plugin prints is a picture any more' );
	}

	/* ── The settings that became Users+ Pro's ──────────────────────── */

	/**
	 * Each setting that left the plugin for Users+ Pro, as the preview drawn
	 * where its control was: the place, the screen that draws it, and the
	 * words it carries.
	 *
	 * @return array<string, array{0: callable, 1: string}>
	 */
	private function moved(): array {
		return array(
			'security/2fa/link'       => array( 'diluxone_users_screen_login_2fa', 'Let it decide' ),
			'security/sessions/reset' => array( 'diluxone_users_screen_sessions', 'A new password closes every session the account had open' ),
			'social/general/roles'    => array( 'diluxone_users_screen_social_general', 'Only some roles' ),
			'account/handle/cooldown' => array( 'diluxone_users_screen_account_handle', 'How often it can change' ),
			'account/handle/locked'   => array( 'diluxone_users_screen_account_handle', 'Once chosen, it cannot be changed' ),
			'fields/missing/ask'      => array( 'diluxone_users_screen_fields_missing', 'ask for the required fields the account has no answer for' ),
			'fields/missing/skip'     => array( 'diluxone_users_screen_fields_missing', 'Let them skip it' ),
			'design/brand/theme'      => array( 'diluxone_users_screen_design_brand', 'From your theme' ),
		);
	}

	public function test_each_setting_that_became_users_pros_is_a_disabled_preview_where_it_was(): void {
		$this->the_admin();

		foreach ( $this->moved() as $where => list( $screen, $title ) ) {
			$html = $this->draw( $screen );
			$at   = strpos( $html, 'data-diluxone-users-pro="' . $where . '"' );

			$this->assertNotFalse( $at, "$where: drawn" );

			$pro = substr( $html, (int) $at, 3000 );
			$this->assertStringContainsString( esc_html( $title ), $pro, "$where: its words" );
			$this->assertMatchesRegularExpression( '/<(input|button|select)[^>]*\sdisabled/', $pro, "$where: switched off" );
			$this->assertStringContainsString( 'diluxone-users-state--pro', $pro, "$where: the badge" );
			$this->assertStringContainsString( 'utm_campaign=free', $pro, "$where: the way out" );
		}
	}

	/** #188 C7: with the setting off, none of them is drawn, and the screens still are. */
	public function test_with_the_setting_off_none_of_the_settings_that_became_users_pros_is_drawn(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_pro_previews', 0 );

		foreach ( $this->moved() as $where => list( $screen ) ) {
			$html = $this->draw( $screen );

			$this->assertNotSame( '', $html, "$where: the screen is drawn" );
			$this->assertStringNotContainsString( 'data-diluxone-users-pro="' . $where . '"', $html, "$where: not drawn" );
			$this->assertStringNotContainsString( 'utm_campaign=free', $html, "$where: no way out to Pro either" );
		}
	}

	/** And one answer of `diluxone_users_upsell` turns one of them off, the rest staying. */
	public function test_an_add_on_turns_off_each_one_on_its_own(): void {
		$this->the_admin();

		foreach ( array_keys( $this->moved() ) as $off ) {
			$hide = static fn( bool $show, string $where ): bool => $where !== $off && $show;
			add_filter( 'diluxone_users_upsell', $hide, 10, 2 );

			foreach ( $this->moved() as $where => list( $screen ) ) {
				$drawn = str_contains( $this->draw( $screen ), 'data-diluxone-users-pro="' . $where . '"' );
				$this->assertSame( $where !== $off, $drawn, "$off off: $where" );
			}

			remove_filter( 'diluxone_users_upsell', $hide, 10 );
		}
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
