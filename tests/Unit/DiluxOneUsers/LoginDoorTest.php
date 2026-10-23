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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
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
}
