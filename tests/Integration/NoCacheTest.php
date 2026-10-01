<?php
/**
 * The sign-in, registration and account pages are kept out of every cache.
 *
 * Each carries a nonce, a second step's key or one person's data, and a page
 * cache that keeps one hands it to the next visitor. They send WordPress's
 * no-cache headers and define DONOTCACHEPAGE: on `template_redirect` for the
 * plugin's chosen pages and any page whose content carries one of its
 * shortcodes, and as a shortcode renders anywhere else.
 *
 * The headers cannot be read from the command line (the browser suite reads
 * them); what is asserted here is the decision, and the constant — once in a
 * process of its own, because a constant cannot be taken back. Every case runs
 * on both topologies; on a network the test site is the hub.
 */

namespace Tests\Integration;

class NoCacheTest extends IntegrationTestCase {

	/** @var array<int, string> The shortcodes this test had to put where has_shortcode() looks. */
	private array $registered = array();

	protected function setUp(): void {
		parent::setUp();

		// The suite's bootstrap loads WordPress inside a function, so the
		// shortcodes the plugin registers are not in the registry
		// has_shortcode() reads here (NetworkHubTest says the same). They are
		// put there for the test, by name, and taken out after it.
		foreach ( diluxone_users_private_shortcodes() as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, '__return_empty_string' );
				$this->registered[] = $tag;
			}
		}
	}

	protected function tearDown(): void {
		foreach ( $this->registered as $tag ) {
			remove_shortcode( $tag );
		}
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		remove_all_filters( 'diluxone_users_no_cache' );

		parent::tearDown();
	}

	private function page( string $content ): int {
		return (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'A page',
				'post_content' => $content,
			)
		);
	}

	/** The main query resolved to this page, as on a front-end request. */
	private function on( int $id ): void {
		$GLOBALS['wp_query']     = new \WP_Query( array( 'page_id' => $id ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	}

	public function test_a_page_with_a_form_or_an_account_is_not_cached(): void {
		foreach ( array( '[diluxone_users_login]', '[diluxone_users_register]', '[diluxone_users_account]', 'Before <!-- wp:shortcode -->[diluxone_users_fields group="main"]<!-- /wp:shortcode -->' ) as $content ) {
			$this->on( $this->page( $content ) );

			$this->assertTrue( diluxone_users_no_cache_wanted(), $content );
		}
	}

	public function test_a_chosen_page_is_not_cached_even_without_its_shortcode(): void {
		foreach ( array( 'diluxone_users_login_page', 'diluxone_users_register_page', 'diluxone_users_account_page' ) as $option ) {
			$id = $this->page( 'Words only: the page draws its part by itself.' );
			diluxone_users_update_option( $option, $id );

			$this->on( $id );

			$this->assertTrue( diluxone_users_no_cache_wanted(), $option );
		}
	}

	public function test_a_list_of_posts_is_left_to_the_cache(): void {
		// The blog's front page, last showing a post that holds a form: it is
		// a list, not that page.
		$GLOBALS['wp_query']     = new \WP_Query( array( 'post_type' => 'post' ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']         = get_post( $this->page( '[diluxone_users_login]' ) );

		$this->assertFalse( diluxone_users_no_cache_wanted() );

		unset( $GLOBALS['post'] );
	}

	public function test_any_other_page_is_left_to_the_cache(): void {
		$this->on( $this->page( 'Opening hours, and a map. [gallery]' ) );

		$this->assertFalse( diluxone_users_no_cache_wanted() );

		add_filter( 'diluxone_users_no_cache', '__return_true' );

		$this->assertTrue( diluxone_users_no_cache_wanted(), 'unless the site says it draws a shortcode from its template' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_page_defines_donotcachepage_before_it_is_drawn(): void {
		$this->on( $this->page( 'Opening hours.' ) );
		diluxone_users_no_cache_pages();
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'An ordinary page says nothing' );

		$this->on( $this->page( '[diluxone_users_login]' ) );
		diluxone_users_no_cache_pages();
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$this->assertNotFalse( has_action( 'template_redirect', 'diluxone_users_no_cache_pages' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_shortcode_drawn_from_a_template_says_it_as_it_renders(): void {
		$this->assertFalse( apply_filters( 'pre_do_shortcode_tag', false, 'gallery', array(), array() ) );
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ), 'Somebody else\'s shortcode says nothing' );

		$this->assertFalse( apply_filters( 'pre_do_shortcode_tag', false, 'diluxone_users_account', array(), array() ), 'What it draws is untouched' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}
}
