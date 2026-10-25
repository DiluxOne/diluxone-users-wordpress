<?php
/**
 * Issue #116: the sign-in page of its own defaults to /sign-in/ on every site.
 *
 * With "Its address" empty, the address of the page of its own should be the
 * one the site already had for signing in — the slug of the page chosen as the
 * sign-in page — and not the English constant, which sends `/entrar/` to
 * `/sign-in/` on a Spanish site.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue116Test extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-door.php';

		Functions\when( 'sanitize_title' )->alias(
			static fn( string $title ): string => trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( str_replace( array( '/', '.' ), array( '', '-' ), $title ) ) ), '-' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_with_its_address_empty_the_page_of_its_own_keeps_the_chosen_sign_in_pages_address(): void {
		// The page /entrar/ is chosen as the sign-in page (id 42), and nothing
		// is typed in "Its address".
		update_option( 'diluxone_users_login_page', 42 );
		update_option( 'diluxone_users_login_slug', '' );

		// However the fix reads the page's path, it is 'entrar'.
		Functions\when( 'get_page_uri' )->justReturn( 'entrar' );
		Functions\when( 'get_post_field' )->justReturn( 'entrar' );
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_name' => 'entrar' ) );

		$this->assertSame(
			'entrar',
			diluxone_users_login_slug(),
			'the page of its own answers at the chosen sign-in page’s address, not at /sign-in/'
		);
	}
}
