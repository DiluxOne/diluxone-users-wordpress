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
}
