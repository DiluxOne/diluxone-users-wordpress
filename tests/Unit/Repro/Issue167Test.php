<?php
/**
 * Issue #167: country names are shown in English on a translated site.
 *
 * The names in diluxone_users_countries() are plain English strings. Every
 * name goes through translation with the plugin's text domain, so the Spanish
 * catalogue has to hold each one; the unit stubs' __() returns its input, so
 * what is proved here is that the catalogue the site loads has the names.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class Issue167Test extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/data-countries.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_site_in_spanish_has_the_names_of_brazil_peru_mexico_spain_and_czechia_in_spanish(): void {
		$po = (string) file_get_contents( DILUXONE_USERS_DIR . 'languages/diluxone-users-es_ES.po' );

		$this->assertNotSame( '', $po, 'the es_ES catalogue is shipped' );

		$translations = array();

		if ( preg_match_all( '/^msgid "([^"\n]+)"\nmsgstr "([^"\n]*)"$/m', $po, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $pair ) {
				$translations[ $pair[1] ] = $pair[2];
			}
		}

		$countries = diluxone_users_countries();
		$expected  = array(
			'BR' => 'Brasil',
			'PE' => 'Perú',
			'MX' => 'México',
			'ES' => 'España',
			'CZ' => 'Chequia',
		);

		foreach ( $expected as $iso => $spanish ) {
			$english = $countries[ $iso ][0];

			$this->assertSame( $spanish, $translations[ $english ] ?? null, "{$iso}: \"{$english}\" is translated to \"{$spanish}\" in es_ES" );
		}
	}
}
