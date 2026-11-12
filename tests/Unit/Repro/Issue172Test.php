<?php
/**
 * Issue #172, and #241 after it: where the Users+ Pro link leads.
 *
 * The expectation: "Learn about Users+ Pro" goes to one address,
 * DILUXONE_USERS_PRO_URL, whatever the admin's language (the Spanish page
 * #172 sent `es_*` admins to does not present Users+ Pro), keeping its utm
 * parameters.
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

	public function test_the_address_is_the_plugin_page(): void {
		$this->assertSame( 'https://diluxone.com/wordpress-plugins/users/', DILUXONE_USERS_PRO_URL );
	}

	public function test_every_admin_whatever_the_language_gets_the_same_address(): void {
		foreach ( array( 'es_ES', 'es_MX', 'es_AR', 'en_US', 'de_DE' ) as $locale ) {
			$url = $this->link_in( $locale );

			$this->assertSame(
				DILUXONE_USERS_PRO_URL . '?utm_source=diluxone-users&utm_medium=access&utm_campaign=free',
				$url,
				"{$locale}: the plugin's page, with its utm parameters"
			);
			$this->assertStringNotContainsString( '/es/', $url, "{$locale}: never a page by language" );
		}
	}
}
