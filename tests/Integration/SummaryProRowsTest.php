<?php
/**
 * What Users+ Pro would add to each screen's Summary: one row a feature.
 *
 * Every Pro feature of a screen is a row of the Summary of that screen,
 * drawn as Security's Passkeys row is (the name, the Pro badge, one sentence
 * and the one link), so an administrator who never opens the tab where its
 * disabled control sits still learns it exists. Each row has a `where` key
 * of its own, is gated by diluxone_users_pro_shown() like every other
 * preview, and passes through `diluxone_users_summary_pro_row`, so the
 * add-on that brings the feature draws its real row in the same place.
 *
 * The rows are found by their `where` key, never by their label: the labels
 * are translated, the keys are the plugin's own.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class SummaryProRowsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	protected function setUp(): void {
		parent::setUp();

		// The panels are registered on an admin request, which a test is not.
		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		diluxone_users_delete_option( 'diluxone_users_pro_previews' );
		$this->cover_admin_reset();
		parent::tearDown();
	}

	/**
	 * Each Summary that carries Pro rows: the renderer, and the `where` key
	 * of every Pro row it draws.
	 *
	 * @return array<string, array{0: callable, 1: array<int, string>}>
	 */
	private function summaries(): array {
		return array(
			'access'   => array( 'diluxone_users_screen_login_summary', array( 'login/summary/passkeys', 'login/summary/shapes' ) ),
			'account'  => array( 'diluxone_users_screen_account_summary', array( 'account/summary/sections', 'account/summary/handle' ) ),
			'security' => array( 'diluxone_users_screen_security_summary', array( 'security/summary/more', 'security/summary/app', 'security/summary/roles', 'security/summary/link', 'security/summary/remember' ) ),
			'fields'   => array( 'diluxone_users_screen_fields_summary', array( 'fields/summary/missing', 'fields/summary/skip' ) ),
			'social'   => array( 'diluxone_users_screen_social_summary', array( 'social/summary/providers', 'social/summary/roles' ) ),
			'design'   => array( 'diluxone_users_screen_design_summary', array( 'design/summary/account-cover', 'design/summary/any-color', 'design/summary/theme', 'design/summary/color-map' ) ),
		);
	}

	/** A Summary as the admin sees it. */
	private function summary( string $screen ): string {
		return $this->draw( $this->summaries()[ $screen ][0] );
	}

	/**
	 * The `where` key of every Pro row a Summary drew, in order.
	 *
	 * @return array<int, string>
	 */
	private function pro_rows( string $html ): array {
		preg_match_all( '/<tr class="du-pro du-pro--row" data-diluxone-users-pro="([^"]+)">/', $html, $found );

		return $found[1];
	}

	/** One row of a Summary, by its key. */
	private function row( string $html, string $where ): string {
		$this->assertSame( 1, preg_match( '/<tr[^>]*data-diluxone-users-pro="' . preg_quote( $where, '/' ) . '">.*?<\/tr>/s', $html, $found ), "{$where}: one row" );

		return $found[0];
	}

	/** A row is the Pro row: the badge, a sentence, the one link that leaves. */
	private function assert_pro_row( string $html, string $where, string $screen ): void {
		$row = $this->row( $html, $where );

		$this->assertStringContainsString( 'diluxone-users-state--pro', $row, "{$where}: the Pro badge" );
		$this->assertMatchesRegularExpression( '/<td class="diluxone-users-summary__detail">\s*Users\+ Pro [^<]+<\/td>/', $row, "{$where}: one sentence about what it adds" );
		$this->assertStringContainsString( 'href="' . esc_url( diluxone_users_pro_url( $screen ) ) . '" target="_blank" rel="noopener noreferrer"', $row, "{$where}: the link, as every Pro link" );
		$this->assertSame( 1, substr_count( $row, 'Learn about Users+ Pro' ), "{$where}: one way out" );
	}

	/* ── Access ────────────────────────────────────────────────────── */

	public function test_access_has_a_pro_row_for_passkeys_and_one_for_the_sign_in_shapes(): void {
		$this->the_admin();

		$html = $this->summary( 'access' );

		$this->assertSame( array( 'login/summary/passkeys', 'login/summary/shapes' ), $this->pro_rows( $html ) );
		$this->assert_pro_row( $html, 'login/summary/passkeys', 'login' );
		$this->assert_pro_row( $html, 'login/summary/shapes', 'login' );
		$this->assertStringContainsString( 'Passkeys', $this->row( $html, 'login/summary/passkeys' ) );
		$this->assertGreaterThan( strpos( $html, 'page=diluxone-users-login&#038;tab=ways' ), strpos( $html, 'du-pro--row' ), 'after the real rows' );
	}

	/* ── Security ──────────────────────────────────────────────────── */

	public function test_security_has_a_pro_row_for_passkeys_and_each_feature_of_the_second_step(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$html = $this->summary( 'security' );

		$this->assertSame( $this->summaries()['security'][1], $this->pro_rows( $html ), 'passkeys, the app, only some roles, the e-mail link, remembering a browser' );

		foreach ( $this->summaries()['security'][1] as $where ) {
			$this->assert_pro_row( $html, $where, 'security' );
		}

		$this->assertStringContainsString( 'Passkeys', $this->row( $html, 'security/summary/more' ) );
		$this->assertStringContainsString( 'An authenticator app', $this->row( $html, 'security/summary/app' ) );
		$this->assertStringContainsString( 'Remember a browser', $this->row( $html, 'security/summary/remember' ) );
	}

	/* ── Account area ──────────────────────────────────────────────── */

	public function test_account_has_a_pro_row_for_sections_of_its_own_and_one_for_how_the_public_name_changes(): void {
		$this->the_admin();

		$html = $this->summary( 'account' );

		$this->assertSame( array( 'account/summary/sections', 'account/summary/handle' ), $this->pro_rows( $html ) );
		$this->assert_pro_row( $html, 'account/summary/sections', 'account' );
		$this->assert_pro_row( $html, 'account/summary/handle', 'account' );
		$this->assertMatchesRegularExpression( '/days between one change.*keeps the first one/s', $this->row( $html, 'account/summary/handle' ), 'how often, and keeping it once chosen, in one row' );
		$this->assertGreaterThan( strpos( $html, 'section=privacy' ), strpos( $html, 'du-pro--row' ), 'after the real rows' );
	}

	/* ── User fields, Social login, Design: a Summary of their own ─────── */

	/**
	 * The screen, its Summary's key in summaries(), and whether its tabs are
	 * the network's.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function new_summaries(): array {
		return array(
			'fields' => array( 'diluxone-users-fields', 'fields', true ),
			'social' => array( 'diluxone-users-social', 'social', true ),
			'design' => array( DILUXONE_USERS_DESIGN, 'design', false ),
		);
	}

	/**
	 * Opened without a tab, the screen lands on Summary: the first of its
	 * tabs, with nothing to save, where the screen's other tabs are.
	 *
	 * @dataProvider new_summaries
	 */
	public function test_the_screen_opens_on_a_summary_with_nothing_to_save( string $screen, string $key, bool $network ): void {
		if ( $network ) {
			$this->where_network_screens_are();
		}

		$this->the_admin();

		$panels = diluxone_users_panels( $screen );

		$this->assertSame( 'summary', array_key_first( $panels ), 'Summary is the first tab, the one the screen opens on' );
		$this->assertFalse( $panels['summary']['form'], 'nothing is edited on it' );
		$this->assertSame( '', $panels['summary']['save'] );
		$this->assertSame( '', $panels['summary']['preview'], 'no stage beside it' );
		$this->assertSame( 'summary', diluxone_users_tab( array_map( static fn( array $p ): string => (string) $p['label'], $panels ) ), 'no tab asked for: Summary' );
	}

	/** @dataProvider new_summaries */
	public function test_its_summary_ends_with_a_pro_row_for_each_feature( string $screen, string $key, bool $network ): void {
		if ( $network ) {
			$this->where_network_screens_are();
		}

		$this->the_admin();

		$html = $this->summary( $key );

		$this->assertStringContainsString( 'Nothing is edited here.', $html, 'the intro of every Summary' );
		$this->assertSame( $this->summaries()[ $key ][1], $this->pro_rows( $html ) );

		foreach ( $this->summaries()[ $key ][1] as $where ) {
			$this->assert_pro_row( $html, $where, $key );
		}

		$this->assertGreaterThan( 0, strpos( $html, 'du-pro--row' ), 'after the real rows' );
		$this->assertGreaterThan( substr_count( $html, 'du-pro--row' ), substr_count( $html, '<tr' ), 'it reports the screen as it is, not only what Pro adds' );
	}

	public function test_social_reports_each_network_and_the_rules_and_names_the_ten_more(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		diluxone_users_update_option( 'diluxone_users_sso_verified_only', 0 );

		$html = $this->summary( 'social' );

		foreach ( diluxone_users_sso_providers() as $id => $provider ) {
			$this->assertStringContainsString( '>' . esc_html( (string) $provider['name'] ) . '</th>', $html, "{$id}: a row of its own" );
			$this->assertStringContainsString( esc_url( diluxone_users_admin_url( 'diluxone-users-social', array( 'provider' => (string) $id ) ) ), $html, "{$id}: a way to its screen" );
		}

		$this->assertMatchesRegularExpression( '/Only verified addresses<\/th>\s*<td[^>]*><span class="diluxone-users-state diluxone-users-state--off">/', $html, 'read from the setting the Rules tab saves' );

		$row = $this->row( $html, 'social/summary/providers' );

		foreach ( diluxone_users_sso_pro_networks() as $name ) {
			$this->assertStringContainsString( esc_html( $name ), $row, "the ten more: {$name}" );
		}

		$this->assertCount( 10, diluxone_users_sso_pro_networks() );
		$this->assertStringContainsString( 'Only some roles', $this->row( $html, 'social/summary/roles' ) );
	}

	public function test_fields_reports_how_many_there_are_and_what_a_sign_in_does(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => 1 ),
				array( 'key' => 'phone', 'label' => 'Phone', 'type' => 'text' ),
				array( 'key' => 'city', 'label' => 'City', 'type' => 'text', 'active' => 0 ),
			)
		);

		$html = $this->summary( 'fields' );

		$this->assertStringContainsString( '2 shown, 1 of them required; 1 hidden.', $html, 'read from the fields stored' );
		$this->assertStringContainsString( esc_url( diluxone_users_admin_url( 'diluxone-users-fields', array( 'tab' => 'list' ) ) ), $html, 'a way to the list' );
		$this->assertStringContainsString( 'People go straight to where they were going', $html, 'what the plugin alone does after sign-in' );
		$this->assertStringContainsString( 'missing required fields', $this->row( $html, 'fields/summary/missing' ) );
		$this->assertStringContainsString( 'skip', $this->row( $html, 'fields/summary/skip' ) );
	}

	public function test_design_reports_each_tab_from_the_setting_it_saves(): void {
		$this->the_admin();

		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );
		diluxone_users_update_option( 'diluxone_users_style_accent', '#15803d' );
		diluxone_users_update_option( 'diluxone_users_wp_login_brand', 0 );

		$html = $this->summary( 'design' );

		$this->assertStringContainsString( 'A centred card', $html, 'the sign-in page shape' );
		$this->assertStringContainsString( '>Green</span>', $html, 'the brand colour, by its name' );
		$this->assertMatchesRegularExpression( '/wp-login\.php<\/th>\s*<td[^>]*><span class="diluxone-users-state diluxone-users-state--off">/', $html, 'wp-login.php branding off' );

		foreach ( array( 'brand', 'login', 'account', 'social', 'photo', 'wp' ) as $tab ) {
			$this->assertStringContainsString( esc_url( diluxone_users_admin_url( DILUXONE_USERS_DESIGN, array( 'tab' => $tab ) ) ), $html, "a way to {$tab}" );
		}

		$this->assertStringContainsString( 'cover', $this->row( $html, 'design/summary/account-cover' ) );
		$this->assertStringContainsString( 'Which theme colour plays each part', $this->row( $html, 'design/summary/color-map' ) );
	}
}
