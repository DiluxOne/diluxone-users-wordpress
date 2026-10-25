<?php
/**
 * The address of the sign-in page of its own: its shape, and what it cannot be.
 *
 * Refusing an address is the whole of what stands between an administrator
 * and a sign-in page that shadows wp-admin or the REST API. Which addresses
 * are reserved is a function of what WordPress and the site register, and
 * this asks it every way round; whether a post has the address is the
 * integration suite's half, against a real database.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class LoginDoorTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-door.php';

		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}

		// What a site registers: a shop, a genre, and the REST API moved.
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'api' );
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'    => (object) array( 'rewrite' => false ),
				'product' => (object) array( 'rewrite' => array( 'slug' => 'products/item' ) ),
			)
		);
		Functions\when( 'get_taxonomies' )->justReturn(
			array( 'genre' => (object) array( 'rewrite' => array( 'slug' => '/genre/' ) ) )
		);
		Functions\when( 'sanitize_title' )->alias(
			static fn( string $title ): string => trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( str_replace( array( '/', '.' ), array( '', '-' ), $title ) ) ), '-' )
		);
		Functions\when( 'get_page_by_path' )->justReturn( null );

		// An English site, looked at in English: the default is not translated.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( '_x' )->returnArg( 1 );
		Functions\when( 'get_post' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_c3_an_english_site_with_nothing_chosen_is_at_sign_in(): void {
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_login_slug' ), 'nothing typed is the stored default' );
		$this->assertSame( 'en_US', diluxone_users_login_slug_locale(), 'a site with no language set is in English' );
		$this->assertSame( 'sign-in', diluxone_users_login_slug_default() );
		$this->assertSame( 'sign-in', diluxone_users_login_slug() );

		update_option( 'WPLANG', '' );
		$this->assertSame( 'sign-in', diluxone_users_login_slug(), 'nor with English chosen' );
	}

	public function test_c3_a_translation_that_comes_out_empty_is_still_sign_in(): void {
		update_option( 'WPLANG', 'xx_XX' );
		Functions\when( 'determine_locale' )->justReturn( 'xx_XX' );
		Functions\when( '_x' )->justReturn( '¡!' );

		$this->assertSame( 'sign-in', diluxone_users_login_slug(), 'never no address' );
	}

	public function test_c4_an_address_typed_wins_over_both_defaults(): void {
		// A site in Spanish, with a sign-in page chosen.
		update_option( 'WPLANG', 'es_ES' );
		update_option( 'diluxone_users_login_page', 12 );
		Functions\when( 'determine_locale' )->justReturn( 'es_ES' );
		Functions\when( '_x' )->justReturn( 'acceder' );
		Functions\expect( 'get_post' )->never();

		update_option( 'diluxone_users_login_slug', 'Puerta' );

		$this->assertSame( 'puerta', diluxone_users_login_slug() );
	}

	public function test_the_address_is_sign_in_until_another_is_chosen(): void {
		$this->assertSame( 'sign-in', diluxone_users_login_slug() );

		update_option( 'diluxone_users_login_slug', 'Entrar' );
		$this->assertSame( 'entrar', diluxone_users_login_slug() );

		update_option( 'diluxone_users_login_slug', '' );
		$this->assertSame( 'sign-in', diluxone_users_login_slug(), 'empty is the default, never no address' );
	}

	public function test_what_wordpress_and_the_site_answer_is_reserved(): void {
		update_option( 'category_base', '/topics' );

		$reserved = diluxone_users_login_slug_reserved();

		foreach ( array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-json', 'feed', 'comments', 'page', 'search', 'author', 'embed', 'tag' ) as $wordpress ) {
			$this->assertContains( $wordpress, $reserved, $wordpress );
		}

		$this->assertContains( 'api', $reserved, 'the REST API where the site moved it' );
		$this->assertContains( 'topics', $reserved, 'the category base' );
		$this->assertContains( 'products', $reserved, 'a type’s address, its first segment' );
		$this->assertContains( 'genre', $reserved, 'a taxonomy’s address' );
		$this->assertNotContains( '', $reserved );
		$this->assertNotContains( 'blog', $reserved, 'only on the main site of a subdirectory network' );
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_an_address_that_cannot_be_the_pages_is_refused( string $typed, string $says ): void {
		$this->assertStringContainsString( $says, diluxone_users_login_slug_problem( $typed ) );
	}

	/** @return array<string, array{string, string}> */
	public static function refusals(): array {
		return array(
			'empty'            => array( '', 'needs an address' ),
			'only slashes'     => array( '///', 'needs an address' ),
			'two segments'     => array( 'members/sign-in', 'one word, with no slashes' ),
			'wp-admin'         => array( 'wp-admin', '/wp-admin/ is one WordPress or the site already answers' ),
			'wp-login.php'     => array( 'WP-Login.php', 'already answers' ),
			'the REST API'     => array( 'api', 'already answers' ),
			'a type'           => array( 'products', 'already answers' ),
			'with its slashes' => array( '/feed/', '/feed/ is one WordPress' ),
		);
	}

	public function test_a_free_address_is_no_problem(): void {
		$this->assertSame( '', diluxone_users_login_slug_problem( 'entrar' ) );
		$this->assertSame( '', diluxone_users_login_slug_problem( '/sign-in/' ), 'the slashes around it are not segments' );
	}

	public function test_the_query_goes_along_field_by_field(): void {
		$_GET = array(
			'redirect_to'           => 'https://example.test/course/?a=1&b=2',
			'Action'                => 'rp',
			'key'                   => '<b>abc</b>',
			'list'                  => array( 'one', 'two' ),
			DILUXONE_USERS_DOOR_VAR => 'moved',
		);

		$this->assertSame(
			array(
				'redirect_to' => 'https://example.test/course/?a=1&b=2',
				'action'      => 'rp',
				'key'         => 'abc',
			),
			diluxone_users_door_query( array( DILUXONE_USERS_DOOR_VAR ) ),
			'each name a key and each value plain text; an array stays behind, and so does what is left out'
		);

		$_GET = array();
	}
}
