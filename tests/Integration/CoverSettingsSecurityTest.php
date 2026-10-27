<?php
/**
 * The Security screen's second step, and where Users+ Pro's passkeys would
 * go.
 *
 * It is the network's on a network, saved and drawn from Network Admin;
 * on a single site they are the site's. Every case runs on both topologies.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class CoverSettingsSecurityTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	protected function setUp(): void {
		parent::setUp();

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		remove_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );

		parent::tearDown();
	}

	/* ── Both saves refuse a bad request ───────────────────────────── */

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
	public static function saves(): array {
		return array(
			'the second step' => array(
				'diluxone_users_2fa_save',
				array(
					'diluxone_users_2fa_mode'    => 'required',
					'diluxone_users_2fa_methods' => array( 'email' ),
				),
				'diluxone_users_2fa_mode',
			),
		);
	}

	/**
	 * On a network the one refused is a site's administrator in Network
	 * Admin; on a single site, a subscriber.
	 *
	 * @dataProvider saves
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_save_stops_on_a_bad_nonce_or_a_missing_capability( string $save, array $post, string $option ): void {
		$this->where_the_network_is_set();
		$before = diluxone_users_option( $option );

		$this->the_admin();
		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'a bad nonce' );

		$this->send_panel( 'diluxone-users-login', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'another screen’s nonce' );

		$this->not_allowed( true );
		$this->send_panel( DILUXONE_USERS_SECURITY, $post );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( $save ) );

		$this->assertSame( $before, diluxone_users_option( $option ) );
	}

	/**
	 * Every answer of the second step's form is one the form offers. A mode
	 * of `bogus` was kept and read back as optional while the screen showed
	 * no mode chosen; a method nothing registered was kept as one the site
	 * had; a mode sent as a list was no mode at all.
	 */
	public function test_the_second_step_keeps_only_answers_it_offers(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'bogus',
				'diluxone_users_2fa_link'    => 'never',
				'diluxone_users_2fa_methods' => array( 'email', 'sms_unknown', array( 'totp' ) ),
			)
		);
		$this->assertNotFalse( $this->ended( 'diluxone_users_2fa_save' ) );

		$this->assertSame( 'optional', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_option( 'diluxone_users_2fa_link' ), 'the rule for the link is Users+ Pro’s: a forged one is not written' );
		$this->assertFalse( get_site_option( 'diluxone_users_2fa_link' ) );
		$this->assertSame( array( 'email' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ) );

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => array( 'required' ),
				'diluxone_users_2fa_methods' => array( 'email' ),
			)
		);
		$this->ended( 'diluxone_users_2fa_save' );

		$this->assertSame( 'optional', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ), 'a list is no mode' );
	}

	public function test_a_method_turned_off_can_be_turned_back_on(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		// An add-on's method, as Users+ Pro's app: the tab lists it, off.
		$this->hook(
			'diluxone_users_2fa_methods',
			static function ( array $methods ): array {
				$methods['device'] = array(
					'label' => 'A device',
					'help'  => '',
				);

				return $methods;
			}
		);
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_2fa_methods' => array( 'email', 'device' ),
			)
		);
		$this->assertNotFalse( $this->ended( 'diluxone_users_2fa_save' ) );

		$this->assertSame( array( 'email', 'device' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ), 'the add-on’s, off until now, is on' );
	}

	public function test_from_the_hubs_own_dashboard_the_save_writes_nothing(): void {
		$this->network_only( 'CoverSettingsSecurityTest::test_off_needs_no_method_and_is_saved' );

		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode' => 'off',
			)
		);
		diluxone_users_2fa_save();

		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
	}

	/* ── The second step, saved ────────────────────────────────────── */

	public function test_a_second_step_with_no_way_to_send_the_code_is_refused(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		foreach ( array( 'optional', 'required' ) as $mode ) {
			$this->send_panel(
				DILUXONE_USERS_SECURITY,
				array(
					'diluxone_users_2fa_mode'    => $mode,
					'diluxone_users_2fa_methods' => array(),
				)
			);

			ob_start();
			$saved = diluxone_users_2fa_save();
			$said  = (string) ob_get_clean();

			$this->assertFalse( $saved, $mode );
			$this->assertStringContainsString( esc_html( diluxone_users_2fa_needs_a_method() ), $said, $mode );
		}

		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ), 'nothing written' );
		$this->assertSame( array( 'email' ), diluxone_users_option( 'diluxone_users_2fa_methods' ) );
	}

	public function test_off_needs_no_method_and_is_saved(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode' => 'off',
			)
		);

		$this->assertTrue( diluxone_users_2fa_save() );
		$this->assertSame( 'off', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		// The boxes under "off" wait for it, disabled, so none was drawn:
		// the methods are left as they were rather than emptied.
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_2fa_methods' ), 'not asked, not written' );
	}

	/* ── The second step, drawn ────────────────────────────────────── */

	public function test_the_tab_says_off_makes_every_question_moot(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertStringContainsString( 'The second step is off, so nothing below is asked of anybody.', $html );
		$this->assertStringContainsString( 'Nothing is asked: the second step is off for the whole site', $html );
	}

	/**
	 * The link asks it: said as a sentence and not offered as a choice, with
	 * "Let it decide" after it as a Users+ Pro preview, disabled and
	 * nameless. A rule saved before is not read.
	 */
	public function test_the_link_always_asks_and_let_it_decide_is_a_pro_preview(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		update_option( 'diluxone_users_2fa_link', 'auto' );
		update_site_option( 'diluxone_users_2fa_link', 'auto' );

		$this->assertSame( 'active', diluxone_users_2fa_link_today()['state'] );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertStringContainsString( 'The second step is asked of somebody who came in by link, as on every other way in', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_2fa_link"', $html, 'no choice to make' );

		$pro = substr( $html, (int) strpos( $html, 'data-diluxone-users-pro="security/2fa/link"' ) );
		$this->assertNotSame( $html, $pro, 'the preview is drawn' );
		$this->assertStringContainsString( 'Let it decide', substr( $pro, 0, 2000 ) );
		$this->assertMatchesRegularExpression( '/^[^>]*>\s*<input[^>]*\sdisabled/', $pro, 'switched off' );

		// An add-on that lets the link alone in is said as such.
		$this->hook( 'diluxone_users_2fa_on_link', '__return_false' );
		$this->assertSame( 'off', diluxone_users_2fa_link_today()['state'] );
		$this->assertStringContainsString( 'Nothing is asked of somebody who came in by link: following it is the whole of it.', $this->draw( 'diluxone_users_screen_login_2fa' ) );

		delete_option( 'diluxone_users_2fa_link' );
		delete_site_option( 'diluxone_users_2fa_link' );
	}

	public function test_with_nowhere_to_answer_it_required_cannot_be_picked(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		add_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertStringContainsString( esc_html( diluxone_users_2fa_needs_a_surface() ), $html );
		$this->assertStringContainsString( 'The sign-in page →', $html );
		$this->assertStringContainsString( 'needs a sign-in page', $html );
		$this->assertMatchesRegularExpression( '/value="required"[^>]*disabled/', $html );
	}

	public function test_without_the_email_link_the_link_question_waits(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );

		$this->assertStringContainsString( 'The e-mail link is not one of the ways in on this site, so nobody follows one.', $this->draw( 'diluxone_users_screen_login_2fa' ) );
	}

	/* ── Where Users+ Pro's second-step rules would go ─────────────── */

	/**
	 * No roles, no app, no days on the tab: three Users+ Pro cards where they
	 * were, and three places an add-on draws them in, in the tab's order.
	 */
	public function test_the_tab_has_three_pro_cards_and_three_places_in_order(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->hook(
			'diluxone_users_2fa_screen',
			static function ( string $place ): void {
				echo '<p data-place="' . esc_attr( $place ) . '"></p>';
			}
		);

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertStringNotContainsString( 'name="diluxone_users_2fa_scope"', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_2fa_remember_days"', $html );
		$this->assertStringNotContainsString( 'value="totp"', $html );

		// Where a piece starts; a control's name and value sit on lines of their own.
		$at = static function ( string $pattern ) use ( $html ): int {
			return preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) ? (int) $m[0][1] : 0;
		};

		$order = array(
			$at( '/name="diluxone_users_2fa_mode"\s+value="off"/' ),
			$at( '/data-diluxone-users-pro="security\/2fa\/roles"/' ),
			$at( '/data-place="who"/' ),
			$at( '/name="diluxone_users_2fa_methods\[\]"/' ),
			$at( '/data-diluxone-users-pro="security\/2fa\/app"/' ),
			$at( '/data-place="methods"/' ),
			$at( '/data-diluxone-users-pro="security\/2fa\/link"/' ),
			$at( '/data-diluxone-users-pro="security\/2fa\/remember"/' ),
			$at( '/data-place="end"/' ),
		);

		foreach ( $order as $n => $where ) {
			$this->assertGreaterThan( 0, $where, "piece $n is drawn" );
		}

		$sorted = $order;
		sort( $sorted );
		$this->assertSame( $sorted, $order, 'in the tab’s order' );

		$this->hook( 'diluxone_users_upsell', static fn( bool $show, string $where ): bool => 0 !== strpos( $where, 'security/2fa/' ) && $show, 10, 2 );

		$this->assertStringNotContainsString( 'data-diluxone-users-pro="security/2fa/', $this->draw( 'diluxone_users_screen_login_2fa' ) );
	}

	/** A method an add-on registered, kept after it went: ticked, switched off, a Pro choice, kept on save. */
	public function test_a_method_nothing_registers_is_an_inactive_pro_choice_kept_on_save(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp', 'email' ) );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertMatchesRegularExpression( '/name="diluxone_users_2fa_methods\[\]"\s+value="totp"[^>]*disabled/', $html, 'switched off' );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_2fa_methods\[\]"\s+value="totp"[^>]*checked/', $html, 'and ticked' );
		$this->assertStringContainsString( 'Your Pro choice (inactive)', $html );

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_2fa_methods' => array( 'email' ),
			)
		);
		$this->assertNotFalse( $this->ended( 'diluxone_users_2fa_save' ) );

		$this->assertSame( array( 'email', 'totp' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ), 'kept' );
	}

	/* ── Where passkeys would go ─────────────────────────────────── */

	public function test_there_is_no_passkeys_tab_and_the_summary_ends_with_their_row(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->assertArrayNotHasKey( 'passkeys', diluxone_users_panel_registry( DILUXONE_USERS_SECURITY ), 'a tab holding only an upsell would be a screen of its own' );

		$html = $this->draw( 'diluxone_users_screen_security_summary' );
		$row  = substr( $html, (int) strpos( $html, 'data-diluxone-users-pro="security/summary/more"' ) );

		$this->assertStringContainsString( 'data-diluxone-users-pro="security/summary/more"', $html );
		$this->assertStringContainsString( 'diluxone-users-state--pro', $row, 'the badge where the state goes' );
		$this->assertStringContainsString( 'Learn about Users+ Pro', $row );
		$this->assertStringNotContainsString( 'name="', $row, 'and nothing in it sends' );
		$this->assertStringNotContainsString( '<tr', $row, 'the last row' );
	}
}
