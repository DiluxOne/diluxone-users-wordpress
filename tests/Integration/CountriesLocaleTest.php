<?php
/**
 * #167: the country names follow the language the request is drawn in at
 * the moment they are asked for, not the one of the first call: a mail in
 * its recipient's language is written in the middle of a page in another.
 */

namespace Tests\Integration;

class CountriesLocaleTest extends IntegrationTestCase {

	public function test_the_names_follow_the_language_asked_in(): void {
		$spanish = static fn(): string => 'es_ES';
		$brasil  = static fn( string $translation, string $text, string $domain ): string => ( 'diluxone-users' === $domain && 'Brazil' === $text && 'es_ES' === get_locale() ) ? 'Brasil' : $translation;

		add_filter( 'gettext', $brasil, 10, 3 );

		$this->assertSame( 'Brazil', diluxone_users_countries()['BR'][0], 'in English first' );

		add_filter( 'locale', $spanish );
		$this->assertSame( 'Brasil', diluxone_users_countries()['BR'][0], 'then in Spanish, in the same request' );

		remove_filter( 'locale', $spanish );
		$this->assertSame( 'Brazil', diluxone_users_countries()['BR'][0], 'and back' );

		remove_filter( 'gettext', $brasil, 10 );
	}
}
