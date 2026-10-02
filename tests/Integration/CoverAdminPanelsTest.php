<?php
/**
 * The panel registry's screen, its previews and its trial runs.
 *
 * A screen built from panels saves its tab only behind the screen's nonce and
 * the screen's capability, and says "Saved." only when the save did not
 * refuse. A live preview is redrawn by the server from what is on the form,
 * through the panel's own save, and writes nothing; a trial run keeps the
 * values for one administrator for one minute, and the page it opens wears
 * them only for that administrator. Each of those doors stops on a bad nonce
 * or a missing capability.
 *
 * Every case runs on both topologies. The test panels live on a screen of
 * their own, which no menu draws.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminPanelsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	private const SCREEN = 'diluxone-users-cover-panels';

	/** @var array<int, string> Callbacks on diluxone_users_option at 999 before the test. */
	private array $option_filters = array();

	protected function setUp(): void {
		parent::setUp();

		$this->option_filters = array_keys( $GLOBALS['wp_filter']['diluxone_users_option']->callbacks[999] ?? array() );

		$this->register();
	}

	protected function tearDown(): void {
		// What a trial run hung on the request, taken off again.
		foreach ( array_keys( $GLOBALS['wp_filter']['diluxone_users_option']->callbacks[999] ?? array() ) as $id ) {
			if ( ! in_array( $id, $this->option_filters, true ) ) {
				$callback = $GLOBALS['wp_filter']['diluxone_users_option']->callbacks[999][ $id ]['function'];
				remove_filter( 'diluxone_users_option', $callback, 999 );
			}
		}

		remove_action( 'wp_enqueue_scripts', 'diluxone_users_preview_try_style' );
		remove_action( 'login_enqueue_scripts', 'diluxone_users_preview_try_style' );
		remove_action( 'wp_footer', 'diluxone_users_preview_try_mark' );
		remove_action( 'login_footer', 'diluxone_users_preview_try_mark' );
		wp_dequeue_style( 'diluxone-users-trial' );
		wp_deregister_style( 'diluxone-users-trial' );

		// What a preview asked for and nobody printed.
		diluxone_users_preview_scripts( null, true );

		$this->cover_admin_reset();

		parent::tearDown();
	}

	/** Four tabs: one that saves, one that refuses, one with a live preview, one that previews a page. */
	private function register(): void {
		$save = static function (): void {
			check_admin_referer( 'diluxone_users_panel_' . self::SCREEN, 'diluxone_users_panel_nonce' );
			diluxone_users_panel_allowed();
			diluxone_users_update_option( 'diluxone_users_style_radius', absint( wp_unslash( $_POST['diluxone_users_style_radius'] ?? 0 ) ) );
		};

		diluxone_users_register_panel(
			self::SCREEN,
			'saves',
			array(
				'label'    => 'Saves',
				'position' => 1,
				'render'   => static function (): void {
					printf( '<p class="radius">%d</p>', (int) diluxone_users_option( 'diluxone_users_style_radius' ) );
				},
				'save'     => $save,
			)
		);

		diluxone_users_register_panel(
			self::SCREEN,
			'refuses',
			array(
				'label'  => 'Refuses',
				'render' => static function (): void {
					echo '<p>refusing</p>';
				},
				'save'   => static fn(): bool => false,
			)
		);

		diluxone_users_register_panel(
			self::SCREEN,
			'live',
			array(
				'label'   => 'Live',
				'render'  => static function (): void {
					echo '<p>live</p>';
				},
				'save'    => $save,
				'preview' => static function (): void {
					printf( '<p class="previewed">radius %s</p>', esc_html( (string) diluxone_users_option( 'diluxone_users_style_radius' ) ) );
				},
				'note'    => 'A line under the preview.',
			)
		);

		diluxone_users_register_panel(
			self::SCREEN,
			'loose',
			array(
				'label'   => 'Loose',
				'render'  => '__return_null',
				'form'    => false,
				'preview' => static function (): void {
					printf( '<p class="loose">%s</p>', esc_html( (string) diluxone_users_option( 'diluxone_users_style_border' ) ) );
				},
			)
		);

		diluxone_users_register_panel(
			self::SCREEN,
			'page',
			array(
				'label'       => 'Page',
				'render'      => '__return_null',
				'save'        => $save,
				'preview_src' => home_url( '/' ),
			)
		);
	}

	/** Draws the screen on one tab. */
	private function screen( string $tab ): string {
		$_GET['tab'] = $tab;

		return $this->draw( 'diluxone_users_screen_panels', self::SCREEN, 'Cover' );
	}

	/* ── The screen ────────────────────────────────────────────────── */

	public function test_a_screen_without_panels_draws_nothing(): void {
		$this->the_admin();

		$this->assertSame( '', $this->draw( 'diluxone_users_screen_panels', 'diluxone-users-nothing-here', 'Nothing' ) );
	}

	public function test_the_moment_to_register_fires_for_everybody(): void {
		// On admin_init, early, and not admin_menu: an AJAX request (the live
		// preview) runs admin_init and never admin_menu.
		$this->assertSame( 1, has_action( 'admin_init', 'diluxone_users_panels_ready' ) );
		$this->assertFalse( has_action( 'admin_menu', 'diluxone_users_panels_ready' ) );

		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};

		add_action( 'diluxone_users_register_panels', $count );
		diluxone_users_panels_ready();
		remove_action( 'diluxone_users_register_panels', $count );

		$this->assertSame( 1, $fired );
	}

	public function test_a_tab_saves_behind_the_screens_nonce_and_says_so(): void {
		$this->the_admin();
		$this->postPanel( self::SCREEN, array( 'diluxone_users_style_radius' => '12' ) );

		$html = $this->screen( 'saves' );

		$this->assertStringContainsString( 'Saved.', $html );
		$this->assertSame( 12, (int) diluxone_users_raw_get( 'diluxone_users_style_radius' ) );
		$this->assertStringContainsString( '<p class="radius">12</p>', $html, 'drawn with what was just written' );
	}

	public function test_a_tab_whose_save_refused_does_not_say_saved(): void {
		$this->the_admin();
		$this->postPanel( self::SCREEN, array() );

		$html = $this->screen( 'refuses' );

		$this->assertStringNotContainsString( 'Saved.', $html );
		$this->assertStringContainsString( 'refusing', $html );
	}

	public function test_a_tab_sent_with_another_screens_nonce_or_by_somebody_else_saves_nothing(): void {
		$this->the_admin();
		$this->postAs( get_current_user_id(), array( 'diluxone_users_style_radius' => '30', 'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_diluxone-users-status' ) ) );

		$html = $this->screen( 'saves' );
		$this->assertStringNotContainsString( 'Saved.', $html );

		$editor = $this->make_user( 'editor' );
		wp_set_current_user( $editor );
		$this->postAs( $editor, array( 'diluxone_users_style_radius' => '30', 'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . self::SCREEN ) ) );

		$html = $this->screen( 'saves' );
		$this->assertStringNotContainsString( 'Saved.', $html );

		$this->assertNotSame( 30, (int) diluxone_users_raw_get( 'diluxone_users_style_radius' ) );
	}

	public function test_a_tab_with_a_live_preview_draws_the_stage_and_its_note(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_style_radius', 7 );

		$html = $this->screen( 'live' );

		$this->assertStringContainsString( 'data-diluxone-users-live="live"', $html );
		$this->assertStringContainsString( 'data-diluxone-users-live-screen="' . self::SCREEN . '"', $html );
		$this->assertStringContainsString( 'srcdoc=', $html );
		$this->assertStringContainsString( 'radius 7', $html, 'the preview drawn with what is saved' );
		$this->assertStringContainsString( 'A line under the preview.', $html );
	}

	public function test_a_tab_that_previews_a_page_offers_a_trial_run_instead(): void {
		$this->the_admin();

		$html = $this->screen( 'page' );

		$this->assertStringNotContainsString( 'data-diluxone-users-live=', $html );
		$this->assertStringContainsString( 'data-diluxone-users-try', $html );
		$this->assertStringContainsString( 'action=diluxone_users_preview_try', $html );
		$this->assertStringContainsString( 'formtarget="' . DILUXONE_USERS_PANEL_FRAME . '"', $html );
	}

	/* ── The document a preview is shown in ───────────────────────── */

	public function test_the_preview_document_carries_the_scripts_and_the_theme_properties_asked_for(): void {
		$palette = static fn(): array => array(
			'brand' => array(
				'name'  => 'Brand',
				'color' => 'var(--site-brand)',
			),
		);
		add_filter( 'diluxone_users_theme_palette', $palette );

		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option( 'diluxone_users_color_map', array( 'accent' => 'brand' ) );
		diluxone_users_update_option( 'diluxone_users_account_row_w', 640 );

		diluxone_users_preview_scripts( 'diluxone-users-ways' );
		diluxone_users_preview_scripts( 'not-a-known-script' );

		$vars     = diluxone_users_preview_theme_vars();
		$document = diluxone_users_preview_document( '<p>body</p>' );

		remove_filter( 'diluxone_users_theme_palette', $palette );

		$this->assertSame( array( '--site-brand' ), $vars, 'the plugin’s own properties are not asked about' );
		$this->assertStringContainsString( 'diluxone-users-vars=1', $document, 'the theme’s properties are fetched' );
		$this->assertStringContainsString( 'assets/diluxone-users-ways.js', $document );
		$this->assertStringNotContainsString( 'not-a-known-script', $document );
		$this->assertStringContainsString( '<p>body</p>', $document );
		$this->assertSame( array(), diluxone_users_preview_scripts(), 'printed once, and forgotten' );
	}

	public function test_the_theme_properties_request_is_for_an_administrator_with_its_nonce(): void {
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_preview_vars_request' )[0], 'not asked, nothing happens' );

		$_GET = array( 'diluxone-users-vars' => '1' );
		$this->the_admin();
		$this->expectDie( 'diluxone_users_preview_vars_request', '', 403 ); // No nonce.

		wp_set_current_user( $this->make_user( 'editor' ) );
		$_GET['_wpnonce'] = wp_create_nonce( 'diluxone_users_preview' );
		$this->expectDie( 'diluxone_users_preview_vars_request', '', 403 ); // Not an administrator.
	}

	public function test_the_theme_properties_answer_holds_only_what_the_preview_asked_about(): void {
		$palette = static fn(): array => array(
			'brand' => array(
				'name'  => 'Brand',
				'color' => 'var(--x)',
			),
		);
		$this->hook( 'diluxone_users_theme_palette', $palette );
		$this->hook(
			'wp_head',
			static function (): void {
				echo '<style>:root{--x:#123456;--y:#abcdef}</style>';
			}
		);

		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option( 'diluxone_users_color_map', array( 'accent' => 'brand' ) );

		$body = diluxone_users_preview_vars_body();

		$this->assertStringContainsString( '--x:#123456', $body );
		$this->assertStringNotContainsString( '--y', $body, 'a property nobody asked about stays on the page' );
		$this->assertStringNotContainsString( '<', $body );
	}

	/* ── The tabs ──────────────────────────────────────────────────── */

	public function test_the_tabs_are_drawn_in_their_positions_order(): void {
		foreach ( array( 'third' => 30, 'first' => 10, 'second' => 20 ) as $tab => $position ) {
			diluxone_users_register_panel(
				'diluxone-users-cover-order',
				$tab,
				array(
					'label'    => ucfirst( $tab ),
					'position' => $position,
					'render'   => '__return_null',
				)
			);
		}

		$this->assertSame( array( 'first', 'second', 'third' ), array_keys( diluxone_users_panels( 'diluxone-users-cover-order' ) ) );

		$_GET['tab'] = 'nope';
		$html        = $this->draw( 'diluxone_users_screen_panels', 'diluxone-users-cover-order', 'Order' );
		$this->assertLessThan( strpos( $html, '>Second<' ), strpos( $html, '>First<' ) );
		$this->assertLessThan( strpos( $html, '>Third<' ), strpos( $html, '>Second<' ) );
	}

	public function test_a_tab_asked_for_as_a_list_or_unknown_is_the_first(): void {
		$tabs = array(
			'one' => 'One',
			'two' => 'Two',
		);

		$_GET['tab'] = array( 'two' );
		$this->assertSame( 'one', diluxone_users_tab( $tabs ) );

		$_GET['tab'] = 'three';
		$this->assertSame( 'one', diluxone_users_tab( $tabs ) );

		$_GET['tab'] = 'two';
		$this->assertSame( 'two', diluxone_users_tab( $tabs ) );
	}

	public function test_a_save_label_given_as_a_function_is_asked_when_the_button_is_drawn(): void {
		$asked = 0;
		diluxone_users_register_panel(
			'diluxone-users-cover-label',
			'only',
			array(
				'label'      => 'Only',
				'render'     => '__return_null',
				'save'       => '__return_null',
				'save_label' => static function () use ( &$asked ): string {
					++$asked;

					return 'Apply it';
				},
			)
		);

		$_GET['tab'] = 'only';
		$html        = $this->draw( 'diluxone_users_screen_panels', 'diluxone-users-cover-label', 'Label' );

		$this->assertStringContainsString( 'Apply it', $html );
		$this->assertGreaterThan( 0, $asked );
	}

	/* ── The live redraw ───────────────────────────────────────────── */

	/**
	 * Sends the live redraw and returns what it answered.
	 *
	 * @param array<string, mixed> $post
	 * @return array{0: string, 1: string, 2: int} How it ended, what it printed, and the status it died with.
	 */
	private function live( array $post ): array {
		$this->postAs( get_current_user_id(), $post );
		ob_start();
		$status = 0;

		try {
			diluxone_users_preview_request();
			$ended = 'returned';
		} catch ( \WPAjaxDieContinueException $e ) {
			$ended  = 'died';
			$status = $e->status;
		}

		return array( $ended, (string) ob_get_clean(), $status );
	}

	public function test_the_live_redraw_runs_the_panels_save_and_writes_nothing(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_style_radius', 4 );

		$answer = $this->live(
			array(
				'nonce'  => wp_create_nonce( 'diluxone_users_preview' ),
				'screen' => self::SCREEN,
				'panel'  => 'live',
				'values' => array(
					'diluxone_users_style_radius' => '19',
					'diluxone_users_panel_nonce'  => wp_create_nonce( 'diluxone_users_panel_' . self::SCREEN ),
				),
			)
		);

		$json = json_decode( $answer[1], true );

		$this->assertTrue( $json['success'] );
		$this->assertStringContainsString( 'radius 19', $json['data'], 'drawn with what is on the form' );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $json['data'] );
		$this->assertSame( 4, (int) diluxone_users_raw_get( 'diluxone_users_style_radius' ), 'nothing was saved' );
		$this->assertSame( '', (string) ( $_POST['diluxone_users_style_radius'] ?? '' ), 'the request is put back' );
	}

	public function test_a_panel_without_a_save_is_previewed_with_the_fields_as_sent(): void {
		$this->the_admin();

		$answer = $this->live(
			array(
				'nonce'  => wp_create_nonce( 'diluxone_users_preview' ),
				'screen' => self::SCREEN,
				'panel'  => 'loose',
				'values' => array(
					'diluxone_users_style_border' => '<b>2</b>',
					'not_ours'                    => 'x',
					'diluxone_users_list'         => array( 'a', '<i>b</i>' ),
				),
			)
		);

		$json = json_decode( $answer[1], true );

		$this->assertTrue( $json['success'] );
		$this->assertStringContainsString( '<p class="loose">2</p>', $json['data'] );

		$this->assertSame(
			array(
				'diluxone_users_style_border' => '2',
				'diluxone_users_list'         => array( 'a', 'b' ),
			),
			diluxone_users_preview_values(
				array(
					'diluxone_users_style_border' => '<b>2</b>',
					'not_ours'                    => 'x',
					'diluxone_users_list'         => array( 'a', '<i>b</i>' ),
				)
			),
			'only the plugin’s keys, as text'
		);
	}

	public function test_the_live_redraw_stops_on_a_bad_nonce_a_missing_capability_or_no_preview(): void {
		$this->the_admin();

		$this->assertSame( array( 'died', '', 403 ), $this->live( array( 'nonce' => 'nope', 'screen' => self::SCREEN, 'panel' => 'live' ) ) );

		$answer = $this->live( array( 'nonce' => wp_create_nonce( 'diluxone_users_preview' ), 'screen' => self::SCREEN, 'panel' => 'saves' ) );
		$this->assertSame( 'died', $answer[0] );
		$this->assertFalse( json_decode( $answer[1], true )['success'], 'a tab with no preview has none to draw' );

		wp_set_current_user( $this->make_user( 'editor' ) );
		$answer = $this->live( array( 'nonce' => wp_create_nonce( 'diluxone_users_preview' ), 'screen' => self::SCREEN, 'panel' => 'live' ) );
		$this->assertSame( 'died', $answer[0] );
		$this->assertFalse( json_decode( $answer[1], true )['success'] );
	}

	/* ── The trial run ─────────────────────────────────────────────── */

	public function test_a_trial_run_stops_for_somebody_else_and_for_a_tab_with_no_page(): void {
		$editor = $this->make_user( 'editor' );
		wp_set_current_user( $editor );
		$this->postAs( $editor, array(), array( 'diluxone_users_try_nonce' => wp_create_nonce( 'diluxone_users_preview_try' ), 'screen' => self::SCREEN, 'panel' => 'page' ) );
		$this->expectDie( 'diluxone_users_preview_try', '', 403 );

		$admin = $this->the_admin();
		$this->postAs( $admin, array(), array( 'diluxone_users_try_nonce' => wp_create_nonce( 'diluxone_users_preview_try' ), 'screen' => self::SCREEN, 'panel' => 'live' ) );
		$this->expectDie( 'diluxone_users_preview_try', '', 404 ); // A live preview is not a page.
	}

	public function test_a_trial_run_dresses_the_page_for_its_administrator_and_nobody_else(): void {
		$admin = $this->the_admin();
		diluxone_users_update_option( 'diluxone_users_style_radius', 3 );

		$this->postAs(
			$admin,
			array(
				'diluxone_users_style_radius' => '21',
				'diluxone_users_panel_nonce'  => wp_create_nonce( 'diluxone_users_panel_' . self::SCREEN ),
			),
			array(
				'diluxone_users_try_nonce' => wp_create_nonce( 'diluxone_users_preview_try' ),
				'screen'                   => self::SCREEN,
				'panel'                    => 'page',
			)
		);

		$ended = $this->ended( 'diluxone_users_preview_try' );
		$this->assertSame( 'redirect', $ended[0] );

		$token = $this->queryArg( $ended[1], 'diluxone-users-try' );
		$this->assertSame( 3, (int) diluxone_users_raw_get( 'diluxone_users_style_radius' ), 'nothing saved' );

		// Somebody else with the address: the page is what is saved.
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET = array( 'diluxone-users-try' => $token );
		diluxone_users_preview_try_apply();
		$this->assertSame( 3, (int) diluxone_users_option( 'diluxone_users_style_radius' ) );
		$this->assertFalse( has_action( 'wp_footer', 'diluxone_users_preview_try_mark' ) );

		// A key that was never handed out.
		wp_set_current_user( $admin );
		$_GET = array( 'diluxone-users-try' => 'nothing' );
		diluxone_users_preview_try_apply();
		$this->assertSame( 3, (int) diluxone_users_option( 'diluxone_users_style_radius' ) );

		// The administrator who pressed the button sees what they chose.
		$_GET = array( 'diluxone-users-try' => $token );
		diluxone_users_preview_try_apply();
		$this->assertSame( '21', (string) diluxone_users_option( 'diluxone_users_style_radius' ) );
		$this->assertSame( 10, has_action( 'wp_footer', 'diluxone_users_preview_try_mark' ) );
		$this->assertSame( 3, (int) diluxone_users_raw_get( 'diluxone_users_style_radius' ), 'and it is still not saved' );

		// The strip that says so, and its look.
		$this->assertStringContainsString( 'data-diluxone-users-trial', $this->draw( 'diluxone_users_preview_try_mark' ) );
		diluxone_users_preview_try_style();
		$this->assertTrue( wp_style_is( 'diluxone-users-trial', 'enqueued' ) );
	}
}
