<?php
/**
 * The short palette: the colours the plugin offers, and what a stored colour
 * nothing offers any more becomes. Pure arithmetic, so it is pinned here and
 * not in a browser.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class PaletteTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/palette.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_every_accent_reads_white_on_it_and_reads_on_white(): void {
		foreach ( diluxone_users_accent_palette() as $slug => $swatch ) {
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $swatch['color'], $slug );
			// AA for body text, both ways round: white letters on a filled
			// button, and the colour itself as a link on a white panel.
			$this->assertGreaterThanOrEqual( 4.5, diluxone_users_contrast( $swatch['color'], '#ffffff' ), $slug );
		}
	}

	public function test_the_palette_is_short_and_has_a_dark_neutral(): void {
		$palette = diluxone_users_accent_palette();

		$this->assertCount( 8, $palette );
		$this->assertArrayHasKey( 'graphite', $palette );
		$this->assertSame( diluxone_users_accent_default(), $palette['blue']['color'] );
	}

	public function test_contrast_is_the_wcag_ratio(): void {
		$this->assertEqualsWithDelta( 21.0, diluxone_users_contrast( '#000000', '#ffffff' ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, diluxone_users_contrast( '#777777', '#777777' ), 0.01 );
		$this->assertEqualsWithDelta( 4.48, diluxone_users_contrast( '#777777', '#ffffff' ), 0.01 );
	}

	public function test_a_colour_of_the_palette_is_itself_whatever_its_case(): void {
		$this->assertSame( '#15803d', diluxone_users_palette_color( '#15803D', diluxone_users_accent_palette(), '#2b59d6', true ) );
	}

	public function test_a_colour_nothing_offers_becomes_the_nearest_one_measured_in_oklab(): void {
		$palette = diluxone_users_accent_palette();

		$this->assertSame( '#2b59d6', diluxone_users_palette_color( '#2a5bd8', $palette, '#2b59d6', true ) );
		$this->assertSame( '#be185d', diluxone_users_palette_color( '#e11d48', $palette, '#2b59d6', true ) );
		$this->assertSame( '#b91c1c', diluxone_users_palette_color( '#a51d1d', $palette, '#2b59d6', true ) );
		$this->assertSame( '#15803d', diluxone_users_palette_color( '#22c55e', $palette, '#2b59d6', true ) );
		$this->assertSame( '#334155', diluxone_users_palette_color( '#000000', $palette, '#2b59d6', true ) );
		$this->assertSame( '#0f766e', diluxone_users_palette_color( '#008671', $palette, '#2b59d6', true ) );
	}

	public function test_a_nearest_colour_that_does_not_read_gives_way_to_the_default(): void {
		// A palette somebody filtered down to a pale colour: the nearest one is
		// still unreadable under white letters, so the default stands in.
		$pale = array( 'butter' => array( 'name' => 'Butter', 'color' => '#fde68a' ) );

		$this->assertSame( '#2b59d6', diluxone_users_palette_color( '#facc15', $pale, '#2b59d6', true ) );
		// Where nothing is written on it, being pale is no reason to refuse it.
		$this->assertSame( '#fde68a', diluxone_users_palette_color( '#facc15', $pale, '#2b59d6', false ) );
	}

	public function test_nothing_stored_and_nonsense_are_the_default(): void {
		$palette = diluxone_users_accent_palette();

		$this->assertSame( '#2b59d6', diluxone_users_palette_color( '', $palette, '#2b59d6', true ) );
		$this->assertSame( '#2b59d6', diluxone_users_palette_color( 'red', $palette, '#2b59d6', true ) );
	}

	public function test_the_grounds_are_light_enough_for_the_plugins_text(): void {
		foreach ( diluxone_users_ground_palette() as $slug => $swatch ) {
			$this->assertGreaterThanOrEqual( 7.0, diluxone_users_contrast( $swatch['color'], '#16181d' ), $slug );
		}
	}

	public function test_a_ground_with_the_themes_colours_is_the_surface_two_percent_text_and_four_percent_hue(): void {
		$mix = static function ( string $hue ): string {
			return 'color-mix(in srgb,' . $hue . ' 4%,color-mix(in srgb,var(--diluxone-users-text) 2%,var(--diluxone-users-surface)))';
		};

		$this->assertSame( $mix( '#758dbc' ), diluxone_users_ground_tint( '#f5f6f8' ), 'mist' );
		$this->assertSame( $mix( '#0042ff' ), diluxone_users_ground_tint( '#EEF3FB' ), 'sky' );
		$this->assertSame( $mix( '#008d0d' ), diluxone_users_ground_tint( '#eef6f1' ), 'sage' );
		$this->assertSame( $mix( '#a77400' ), diluxone_users_ground_tint( '#f7f5f0' ), 'paper' );
		$this->assertSame( '', diluxone_users_ground_tint( 'red;}body{display:none' ) );
	}

	public function test_a_ground_with_the_themes_colours_turns_with_the_theme_and_its_text_still_reads(): void {
		foreach ( diluxone_users_ground_palette() as $slug => $swatch ) {
			// On the plugin's own white and text it is the swatch, within a shade.
			$light = $this->tinted( $swatch['color'], '#ffffff', '#16181d' );

			foreach ( array( 0, 1, 2 ) as $i ) {
				$this->assertEqualsWithDelta( hexdec( substr( $swatch['color'], 1 + 2 * $i, 2 ) ), hexdec( substr( $light, 1 + 2 * $i, 2 ) ), 2, $slug );
			}

			// Dark grounds and light text, and themes whose own text reads at little more than 5:1.
			foreach ( array( array( '#15171c', '#e6e8ec' ), array( '#000000', '#ffffff' ), array( '#1e1e1e', '#999999' ), array( '#ffffff', '#6b6b6b' ) ) as $theme ) {
				list( $surface, $text ) = $theme;
				$ground                 = $this->tinted( $swatch['color'], $surface, $text );

				$this->assertGreaterThanOrEqual( 4.5, diluxone_users_contrast( $ground, $text ), "$slug on $surface" );
			}
		}
	}

	/** The colour a browser draws for the tint, `color-mix()` in sRGB, on these colours. */
	private function tinted( string $swatch, string $surface, string $text ): string {
		preg_match( '/^color-mix\(in srgb,(#[0-9a-f]{6}) 4%/', diluxone_users_ground_tint( $swatch ), $hue );
		$out = '#';

		foreach ( array( 0, 1, 2 ) as $i ) {
			$channel = static function ( string $hex ) use ( $i ): float {
				return (float) hexdec( substr( $hex, 1 + 2 * $i, 2 ) );
			};
			$out    .= sprintf( '%02x', (int) round( 0.04 * $channel( $hue[1] ) + 0.96 * ( 0.02 * $channel( $text ) + 0.98 * $channel( $surface ) ) ) );
		}

		return $out;
	}

	public function test_an_answer_from_a_form_is_a_swatch_the_stored_colour_or_nothing(): void {
		$palette = diluxone_users_accent_palette();

		$this->assertSame( '#6d28d9', diluxone_users_color_answer( '#6D28D9', '', $palette, false ) );
		// The stored colour nothing offers is kept while it is the answer sent.
		$this->assertSame( '#123456', diluxone_users_color_answer( '#123456', '#123456', $palette, false ) );
		// Any other colour, sent by hand, is no answer at all.
		$this->assertSame( '', diluxone_users_color_answer( '#123456', '', $palette, false ) );
		$this->assertSame( '', diluxone_users_color_answer( 'javascript:', '', $palette, false ) );
		// Unless any colour is an answer here.
		$this->assertSame( '#123456', diluxone_users_color_answer( '#123456', '', $palette, true ) );
		$this->assertSame( '', diluxone_users_color_answer( '#12345', '', $palette, true ) );
	}
}
