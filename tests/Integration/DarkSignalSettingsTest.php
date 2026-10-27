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
		$this->theme_colors( true );
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
		$this->theme_colors( false );
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

	public function test_c6_the_page_of_its_own_prints_the_sites_wp_head_and_puts_the_signal_before_it(): void {
		$permalinks = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );
		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html data-theme="dark"' );

		$this->as_front_end();
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		set_query_var( DILUXONE_USERS_DOOR_VAR, 'login' );

		// The site's switch, as a theme prints it: inline, on wp_head.
		$this->hook(
			'wp_head',
			static function (): void {
				echo '<script>document.documentElement.setAttribute("data-theme","dark");/* the-sites-switch */</script>';
			}
		);

		try {
			$this->assertTrue( diluxone_users_door_drawn() );

			ob_start();
			include DILUXONE_USERS_DIR . 'templates/login-page.php';
			$html = (string) ob_get_clean();
		} finally {
			update_option( 'permalink_structure', $permalinks );
			$GLOBALS['wp_query']     = new \WP_Query();
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		}

		$head  = substr( $html, 0, (int) strpos( $html, '</head>' ) );
		$early = strpos( $head, 'diluxone-users-theme-colors-early' );

		$this->assertStringContainsString( 'the-sites-switch', $head, 'the site’s wp_head, in the head of the page of its own' );
		$this->assertNotFalse( $early, 'the early script, in the head' );
		$this->assertStringContainsString( '"name":"data-theme","value":"dark"}},1);', $head, 'told the signal, and that this is the page of its own' );
		$this->assertLessThan( strpos( $head, 'the-sites-switch' ), $early, 'before the site’s switch, so the switch has the last word' );
	}

	/**
	 * Your brand saved with these answers, and what the save printed.
	 *
	 * @param array<string, string> $post
	 */
	private function save( array $post ): string {
		$this->postPanel( DILUXONE_USERS_DESIGN, $post );

		ob_start();
		diluxone_users_design_brand_save();

		return (string) ob_get_clean();
	}

	public function test_c7_a_signal_that_is_not_a_class_or_an_attribute_is_refused_and_the_previous_one_stays(): void {
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html .is-dark' );
		diluxone_users_update_option( 'diluxone_users_light_signal', 'html .is-light' );

		foreach ( array( 'dark mode', '.', 'onclick="x"', 'data-x="<b>"', 'html[x]' ) as $typed ) {
			foreach ( array( 'diluxone_users_dark_signal', 'diluxone_users_light_signal' ) as $key ) {
				$said = $this->save(
					array(
						'diluxone_users_dark_follows' => 'site',
						$key . '_on'                  => 'body',
						$key                          => $typed,
					)
				);

				$this->assertStringContainsString( 'notice-error', $said, $key . ': ' . $typed );
				$this->assertStringContainsString( 'has to be a class, as .is-dark, or an attribute', $said );
				$this->assertStringNotContainsString( '<b>', $said, 'what was typed is never printed as markup' );
			}

			$this->assertSame( 'html .is-dark', diluxone_users_option( 'diluxone_users_dark_signal' ), $typed );
			$this->assertSame( 'html .is-light', diluxone_users_option( 'diluxone_users_light_signal' ), $typed );
			$this->assertSame( 'site', diluxone_users_option( 'diluxone_users_dark_follows' ) );
		}
	}

	public function test_c7_a_refused_dark_signal_keeps_the_answer_too(): void {
		$said = $this->save(
			array(
				'diluxone_users_dark_follows'    => 'site',
				'diluxone_users_dark_signal_on'  => 'html',
				'diluxone_users_dark_signal'     => 'onclick="x"',
				'diluxone_users_light_signal_on' => 'html',
				'diluxone_users_light_signal'    => '',
			)
		);

		$this->assertStringContainsString( 'notice-error', $said );
		$this->assertSame( 'device', diluxone_users_option( 'diluxone_users_dark_follows' ), 'a switch the plugin cannot read is no switch' );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_dark_signal' ) );
	}

	public function test_c7_the_sites_switch_with_no_dark_signal_is_refused(): void {
		$said = $this->save(
			array(
				'diluxone_users_dark_follows' => 'site',
				'diluxone_users_dark_signal'  => '   ',
			)
		);

		$this->assertStringContainsString( 'notice-error', $said );
		$this->assertStringContainsString( 'needs what the page has when the site is dark', $said );
		$this->assertSame( 'device', diluxone_users_option( 'diluxone_users_dark_follows' ) );
	}

	public function test_a_class_or_an_attribute_is_saved_as_it_is_read(): void {
		$said = $this->save(
			array(
				'diluxone_users_dark_follows'    => 'site',
				'diluxone_users_dark_signal_on'  => 'html',
				'diluxone_users_dark_signal'     => " data-tema='oscuro' ",
				'diluxone_users_light_signal_on' => 'body',
				'diluxone_users_light_signal'    => '.is-light',
			)
		);

		$this->assertStringNotContainsString( 'notice-error', $said );
		$this->assertSame( 'site', diluxone_users_option( 'diluxone_users_dark_follows' ) );
		$this->assertSame( 'html data-tema="oscuro"', diluxone_users_option( 'diluxone_users_dark_signal' ) );
		$this->assertSame( 'body .is-light', diluxone_users_option( 'diluxone_users_light_signal' ) );

		// Back to the device, the light one emptied: both written, nothing refused.
		$said = $this->save(
			array(
				'diluxone_users_dark_follows'    => 'device',
				'diluxone_users_dark_signal_on'  => 'html',
				'diluxone_users_dark_signal'     => 'data-tema="oscuro"',
				'diluxone_users_light_signal_on' => 'html',
				'diluxone_users_light_signal'    => '',
			)
		);

		$this->assertStringNotContainsString( 'notice-error', $said );
		$this->assertSame( 'device', diluxone_users_option( 'diluxone_users_dark_follows' ) );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_light_signal' ) );
	}

	public function test_another_tab_of_the_screen_saved_leaves_it_alone(): void {
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'html .is-dark' );

		$this->save( array( 'diluxone_users_login_logo' => '0' ) );

		$this->assertSame( 'site', diluxone_users_option( 'diluxone_users_dark_follows' ) );
		$this->assertSame( 'html .is-dark', diluxone_users_option( 'diluxone_users_dark_signal' ) );
	}

	public function test_the_section_is_drawn_on_your_brand_whatever_the_look(): void {
		diluxone_users_update_option( 'diluxone_users_dark_follows', 'site' );
		diluxone_users_update_option( 'diluxone_users_dark_signal', 'body data-theme="dark"' );

		foreach ( array( 'theme', 'own' ) as $look ) {
			$this->theme_colors( 'theme' === $look );

			ob_start();
			diluxone_users_brand_dark_follows();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'Dark mode follows', $html, $look );
			$this->assertMatchesRegularExpression( '/value="site"[^>]*checked/', $html );
			$this->assertStringContainsString( 'value="data-theme=&quot;dark&quot;"', $html, 'what is stored, escaped' );
			$this->assertMatchesRegularExpression( '/<option value="body"\s+selected/', $html );
		}
	}
}
