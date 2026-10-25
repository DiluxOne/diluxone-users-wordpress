<?php
/**
 * Dark mode follows: the visitor's device, or the site's own switch.
 *
 * With the device, the two scripts that paint the theme's colours are what
 * they were before the setting existed, to the byte. With the site's switch,
 * both are told the signal; and a signal that is not a class or an attribute
 * is refused on save, with a notice, keeping what was stored.
 */

namespace Tests\Integration;

class DarkSignalSettingsTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		// A theme with no palette: every part is measured, so both scripts run.
		$this->hook( 'diluxone_users_theme_palette', '__return_empty_array', 100 );
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		parent::tearDown();
	}

	/** What the early script prints, inline, in the head. */
	private function early(): string {
		$this->as_front_end();
		diluxone_users_measure_early();

		return implode( "\n", array_filter( (array) wp_scripts()->get_data( 'diluxone-users-theme-colors-early', 'after' ) ) );
	}

	/** What the measurer is told, as printed before it. */
	private function measurer(): string {
		$this->as_front_end();
		wp_register_style( 'diluxone-users', false, array(), '1' );
		wp_enqueue_style( 'diluxone-users' );
		diluxone_users_measure();

		return (string) wp_scripts()->get_data( 'diluxone-users-theme-colors', 'data' );
	}

	public function test_c1_the_device_is_the_default_and_tells_no_signal(): void {
		$this->assertSame( 'device', diluxone_users_option( 'diluxone_users_dark_follows' ) );
		$this->assertSame( array(), diluxone_users_dark_signal() );

		$this->assertSame(
			'(function(k){try{var d=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;var v=JSON.parse(localStorage.getItem(k+(d?":dark":":light"))||"null");if(!v)return;var s=document.documentElement.style;for(var r in v){s.setProperty("--diluxone-users-"+r,v[r]);}}catch(e){}})(' . wp_json_encode( diluxone_users_measure_key() ) . ');',
			$this->early(),
			'the early script, byte for byte as it was'
		);

		$config = $this->measurer();

		$this->assertStringContainsString( '"roles"', $config );
		$this->assertStringNotContainsString( 'signal', $config );
		$this->assertStringNotContainsString( 'door', $config );
	}

	public function test_c1_a_signal_stored_while_the_device_decides_changes_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html data-theme="dark"' );

		$this->assertSame( array(), diluxone_users_dark_signal() );
		$this->assertStringNotContainsString( 'data-theme', $this->early() );
		$this->assertStringNotContainsString( 'signal', $this->measurer() );
	}

	public function test_c1_the_plugins_own_colours_have_no_dark_scheme_to_follow(): void {
		diluxone_users_update_option( 'diluxone_users_colors', 'own' );
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html data-theme="dark"' );

		$this->assertSame( array(), diluxone_users_dark_signal() );
		$this->assertSame( '', $this->early(), 'nothing measured, nothing printed' );
	}

	public function test_c1_a_stored_signal_that_no_longer_parses_leaves_the_device(): void {
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html onclick="x"' );

		$this->assertSame( array(), diluxone_users_dark_signal() );
		$this->assertStringContainsString( 'prefers-color-scheme', $this->early() );
		$this->assertStringNotContainsString( 'onclick', $this->early() );
	}

	public function test_the_sites_switch_tells_both_scripts_the_signal(): void {
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html data-theme="dark"' );
		diluxone_users_update_option( 'diluxone_users_light_signal', 'body .is-light' );

		$this->assertSame(
			array(
				'dark'  => array(
					'on'    => 'html',
					'kind'  => 'attr',
					'name'  => 'data-theme',
					'value' => 'dark',
				),
				'light' => array(
					'on'    => 'body',
					'kind'  => 'class',
					'name'  => 'is-light',
					'value' => null,
				),
			),
			diluxone_users_dark_signal()
		);

		$this->assertStringContainsString( '"name":"data-theme","value":"dark"', $this->early() );
		$this->assertStringContainsString( '"signal":{"dark":{"on":"html","kind":"attr","name":"data-theme","value":"dark"}', $this->measurer() );
	}
}
