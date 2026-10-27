<?php
/**
 * Issue #172: the Users+ Pro link leads to a page that does not exist.
 *
 * The expectation: "Learn about Users+ Pro" goes to the plugin's page on
 * diluxone.com in the language of the admin (any `es_*` locale to the Spanish
 * page, every other to the English one), keeping its utm parameters.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue172Test extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/admin-ui.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The link as the admin sees it, in a locale. */
	private function link_in( string $locale ): string {
		Functions\when( 'get_user_locale' )->justReturn( $locale );

		return diluxone_users_pro_url( 'access' );
	}

	public function test_the_pro_link_of_a_spanish_admin_goes_to_the_spanish_page_of_the_plugin(): void {
		foreach ( array( 'es_AR', 'es_ES', 'es_MX' ) as $locale ) {
			$url = $this->link_in( $locale );

			$this->assertStringStartsWith( 'https://diluxone.com/es/wordpress/users/?', $url, "{$locale}: the Spanish page" );
			$this->assertStringContainsString( 'utm_source=diluxone-users', $url, "{$locale}: keeps its utm parameters" );
			$this->assertStringContainsString( 'utm_medium=access', $url );
			$this->assertStringContainsString( 'utm_campaign=free', $url );
		}
	}

	public function test_the_pro_link_of_any_other_admin_goes_to_the_english_page_of_the_plugin(): void {
		foreach ( array( 'en_US', 'de_DE', 'fr_FR' ) as $locale ) {
			$url = $this->link_in( $locale );

			$this->assertStringStartsWith( 'https://diluxone.com/wordpress-plugins/users/?', $url, "{$locale}: the English page" );
			$this->assertStringContainsString( 'utm_source=diluxone-users', $url, "{$locale}: keeps its utm parameters" );
			$this->assertStringContainsString( 'utm_medium=access', $url );
			$this->assertStringContainsString( 'utm_campaign=free', $url );
		}
	}
}
