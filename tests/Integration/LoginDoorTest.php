<?php
/**
 * The sign-in page of its own: the shape "A page of its own".
 *
 * The plugin answers an address of the site's choosing with a document it
 * draws whole, and every way to the sign-in screen leads there. Every case
 * runs on both topologies; on a network the test site is the hub, where the
 * page lives.
 */

namespace Tests\Integration;

class LoginDoorTest extends IntegrationTestCase {

	/** @var string The permalink structure before the test. */
	private string $permalinks = '';

	protected function setUp(): void {
		parent::setUp();

		$this->permalinks = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );

		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
	}

	protected function tearDown(): void {
		update_option( 'permalink_structure', $this->permalinks );

		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		parent::tearDown();
	}

	/** The main query as the door's rule leaves it. */
	private function on_door( string $view ): void {
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		set_query_var( DILUXONE_USERS_DOOR_VAR, $view );
	}

	/** @return array<string, string> The rules the plugin added at the top. */
	private function rules(): array {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();
		diluxone_users_door_rules();

		return (array) $wp_rewrite->extra_rules_top;
	}

	public function test_c1_the_shape_is_offered_and_answers_its_address(): void {
		$this->assertArrayHasKey( 'own', diluxone_users_login_templates() );
		$this->assertSame( 'sign-in', diluxone_users_login_slug(), 'the default slug' );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^sign\\-in/?$'] ?? '' );
		$this->assertSame( 'index.php?diluxone_users_door=register', $rules['^sign\\-in/register/?$'] ?? '' );
		$this->assertStringContainsString( 'door:sign-in', diluxone_users_rewrite_stamp(), 'a changed slug flushes the rules' );
	}

	public function test_c1_another_shape_adds_no_rule_and_no_page(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );

		$this->assertSame( array(), $this->rules() );
		$this->assertStringNotContainsString( 'door:', diluxone_users_rewrite_stamp() );

		$this->on_door( 'login' );
		$this->assertSame( '', diluxone_users_door_view() );
		$this->assertSame( '/theme/index.php', diluxone_users_door_template( '/theme/index.php' ) );
	}

	/**
	 * Defines DONOTCACHEPAGE for good: in a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_c1_the_page_is_the_plugins_whole_document(): void {
		$this->as_front_end();
		$this->on_door( 'login' );

		$this->assertSame( DILUXONE_USERS_DIR . 'templates/login-page.php', diluxone_users_door_template( '/theme/index.php' ), 'the plugin’s document, not the theme’s template' );

		$GLOBALS['wp_query']->is_404 = true;
		$this->stays( 'diluxone_users_door_serve', 'a signed-out person stays on the page' );
		$this->assertFalse( is_404(), 'answered 200' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ), 'kept out of every cache' );
		$this->assertStringStartsWith( 'Sign in', diluxone_users_door_title( 'Home' ) );

		ob_start();
		include DILUXONE_USERS_DIR . 'templates/login-page.php';
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<!doctype html>', ltrim( $html ) );
		$this->assertStringContainsString( 'diluxone-users-door', $html );
		$this->assertStringContainsString( 'diluxone-users-login-frame--own', $html, 'the form, in its frame' );
		$this->assertStringContainsString( esc_html( get_bloginfo( 'name' ) ), $html, 'the site’s name' );
		$this->assertStringNotContainsString( 'wp-block-template-part', $html, 'no header or footer of the theme' );
	}

	public function test_c1_somebody_signed_in_is_sent_to_their_account(): void {
		$this->as_front_end();
		$this->on_door( 'login' );
		wp_set_current_user( $this->make_user() );

		$this->assertSame( home_url( '/' ), $this->expectRedirect( 'diluxone_users_door_serve' ), 'with no account page, the home page' );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'My account',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $page );

		$this->assertSame( get_permalink( $page ), $this->expectRedirect( 'diluxone_users_door_serve' ) );
	}

	public function test_c1_a_view_nobody_offered_is_not_the_door(): void {
		$this->on_door( '../../wp-config' );

		$this->assertSame( '', diluxone_users_door_view() );
		$this->assertSame( '/theme/index.php', diluxone_users_door_template( '/theme/index.php' ) );
	}
}
