<?php
/**
 * The two things the preview was getting wrong, in a test that takes no browser.
 *
 * Both of them are the same mistake seen twice: a preview that is confidently
 * showing something the site has never looked like. One is a palette the frame
 * could not resolve, the other a tick box the frame never heard was unticked.
 * A picture can catch either of them once somebody looks at it; these catch
 * them on the way past.
 */

namespace Tests\Integration;

class PreviewPaletteTest extends IntegrationTestCase {

	/** A head like the one a theme prints, with its palette inside it. */
	private const HEAD = '<link rel="stylesheet" href="/theme.css">'
		. '<style id="global-styles-inline-css">:root{--wp--preset--color--brand: var(--site-brand);}</style>'
		. '<style id="theme-inline-css">/* the site\'s own */'
		. ':root{--site-brand:#046bd2;--site-ink:var(--site-paper);--site-paper:#ffffff;--site-gap:24px;}'
		. '.header{color:var(--site-brand)}'
		. '@media (prefers-color-scheme:dark){:root{--site-brand:#0b1020;--site-gap:40px;}}'
		. '</style>';

	/**
	 * The plugin taking its colours from a theme that publishes properties.
	 *
	 * Which is the case the preview fell over on: Astra and its like hand over
	 * `var(--ast-global-color-0)`, and the frame has no theme stylesheet in it.
	 */
	private function colours_from_a_theme_of_variables(): void {
		add_filter(
			'diluxone_users_theme_palette',
			static function (): array {
				return array(
					'brand' => array(
						'name'  => 'Brand',
						'color' => 'var(--site-brand)',
					),
					'paper' => array(
						'name'  => 'Paper',
						'color' => 'var(--site-ink)',
					),
				);
			}
		);

		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option(
			'diluxone_users_color_map',
			array(
				'accent'     => 'brand',
				'accent-ink' => 'paper',
			)
		);
	}

	/** The properties the plugin's own colours are standing on are asked for. */
	public function test_a_palette_of_variables_is_asked_about(): void {
		$this->colours_from_a_theme_of_variables();

		$asked = diluxone_users_preview_theme_vars();

		$this->assertContains( '--site-brand', $asked );
		$this->assertContains( '--site-ink', $asked );

		// The plugin's own are declared by the plugin's own sheet, which the
		// frame already has.
		foreach ( $asked as $name ) {
			$this->assertStringStartsNotWith( '--diluxone-users-', $name );
		}

		$this->assertNotSame( '', diluxone_users_preview_theme_url() );
	}

	/** A palette of hexes needs nothing, and then nothing is fetched. */
	public function test_a_palette_of_hexes_asks_for_nothing(): void {
		add_filter(
			'diluxone_users_theme_palette',
			static function (): array {
				return array(
					'brand' => array(
						'name'  => 'Brand',
						'color' => '#046bd2',
					),
				);
			}
		);

		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option( 'diluxone_users_color_map', array( 'accent' => 'brand' ) );

		$this->assertSame( array(), diluxone_users_preview_theme_vars() );
		$this->assertSame( '', diluxone_users_preview_theme_url() );
	}

	/** What the theme printed comes back as the colours it stands for. */
	public function test_the_theme_s_own_properties_are_read_back(): void {
		$css = diluxone_users_preview_vars_css( self::HEAD, array( '--site-brand' ) );

		$this->assertSame( ':root{--site-brand:#046bd2;}', $css );
	}

	/** A property pointing at another one brings that one with it. */
	public function test_a_property_that_points_at_another_brings_it_along(): void {
		$css = diluxone_users_preview_vars_css( self::HEAD, array( '--site-ink' ) );

		$this->assertStringContainsString( '--site-ink:var(--site-paper);', $css );
		$this->assertStringContainsString( '--site-paper:#ffffff;', $css );
	}

	/**
	 * And nothing else of the theme's comes with them.
	 *
	 * The frame is being handed a palette, not the theme's design: a rule that
	 * is not a colour on the document itself has no business in there.
	 */
	public function test_nothing_but_what_was_asked_for_comes_across(): void {
		$css = diluxone_users_preview_vars_css( self::HEAD, array( '--site-brand' ) );

		$this->assertStringNotContainsString( '--site-gap', $css );
		$this->assertStringNotContainsString( '.header', $css );
		$this->assertStringNotContainsString( '--wp--preset', $css );
	}

	/**
	 * A palette with a second answer under an at-rule keeps the plain one.
	 *
	 * The frame is showing one page in daylight. A theme's dark colours taken
	 * because they were written last would be the same confident lie the whole
	 * of this is about.
	 */
	public function test_an_answer_under_an_at_rule_does_not_win(): void {
		$css = diluxone_users_preview_vars_css( self::HEAD, array( '--site-brand' ) );

		$this->assertSame( ':root{--site-brand:#046bd2;}', $css );
	}

	/** Asked about a property no page declares, it says nothing at all. */
	public function test_a_property_nobody_declares_is_left_out(): void {
		$this->assertSame( '', diluxone_users_preview_vars_css( self::HEAD, array( '--nobody' ) ) );
		$this->assertSame( '', diluxone_users_preview_vars_css( self::HEAD, array() ) );
	}

	/**
	 * A trial run reads the form the way saving would, and saves none of it.
	 *
	 * The unticked box is the whole point: it sends nothing at all, so a trial
	 * built from what arrived would show the setting still on — which is the
	 * complaint that started this. The panel's own save is the only thing that
	 * knows the box was there.
	 */
	public function test_a_trial_run_sees_an_unticked_box_and_writes_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_wp_login_brand', 1 );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_wp_login_bg' => '#123456' ) );

		$would = diluxone_users_preview_would_save(
			array( 'save' => 'diluxone_users_design_wp_save' )
		);

		$this->assertSame( 0, $would['diluxone_users_wp_login_brand'] );
		$this->assertSame( '#123456', $would['diluxone_users_wp_login_bg'] );

		// And the site is exactly as it was.
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_wp_login_brand' ) );
		$this->assertSame( '', (string) diluxone_users_raw_get( 'diluxone_users_wp_login_bg', '' ) );
	}

	/** Those values are what the previewed page then reads. */
	public function test_the_previewed_page_reads_what_was_chosen(): void {
		diluxone_users_update_option( 'diluxone_users_wp_login_brand', 1 );

		$this->assertTrue( diluxone_users_wp_login_branded() );

		add_filter(
			'diluxone_users_option',
			diluxone_users_preview_override( array( 'diluxone_users_wp_login_brand' => 0 ) ),
			999,
			2
		);

		$this->assertFalse( diluxone_users_wp_login_branded() );
	}

	/** A panel with nothing to save has nothing to try. */
	public function test_a_panel_that_saves_nothing_offers_nothing(): void {
		$this->assertSame( array(), diluxone_users_preview_would_save( array( 'save' => '' ) ) );
	}
}
