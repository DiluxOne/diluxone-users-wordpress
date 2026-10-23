<?php
/**
 * Issue 77: the account area's ground stays light in the theme's dark mode.
 *
 * With Colours › the theme's, the text follows the theme into dark mode while a
 * ground picked from the palette is printed as a fixed light hex, so the
 * headings vanish on it. Whatever the fix chooses (a tint mixed from the
 * theme's own surface and text), a palette ground must not print one of the
 * four fixed light colours when the colours are the theme's.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class Issue77Test extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/palette.php';
		require_once DILUXONE_USERS_DIR . 'includes/theme-colors.php';
		require_once DILUXONE_USERS_DIR . 'includes/templates.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_palette_ground_on_the_themes_colours_is_not_a_fixed_light_colour(): void {
		update_option( 'diluxone_users_account_ground', '#f5f6f8' );

		// The control: with the plugin's own colours the chosen shade is printed.
		update_option( 'diluxone_users_colors', 'own' );
		$this->assertStringContainsString( '#f5f6f8', diluxone_users_account_css(), 'the control: the shade chosen is the rule' );

		// The report: the theme's colours, and the site goes dark.
		update_option( 'diluxone_users_colors', 'theme' );
		$this->assertTrue( diluxone_users_colors_from_theme() );

		$css = diluxone_users_account_css();

		foreach ( diluxone_users_ground_palette() as $swatch ) {
			$this->assertStringNotContainsString(
				$swatch['color'],
				$css,
				'with the theme\'s colours the ground must follow the theme into dark mode, not stay a fixed light ' . $swatch['color']
			);
		}
	}
}
