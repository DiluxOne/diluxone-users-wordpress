<?php
/**
 * How the plugin's items look and sit in the site's own menu.
 *
 * The person's item says it opens, the way themes read it. "Sign in" can be
 * a button, in the theme's colours or the site's own, and the plugin's items
 * can stand apart at the far end of the bar with room of their own; left at
 * their defaults, everything is drawn exactly as before.
 */

namespace Tests\Integration;

class SiteMenuLookTest extends IntegrationTestCase {

	/** @var array<int, int> Menus the test made. */
	private array $menus = array();

	/** @var mixed The menu locations before the test. */
	private $locations;

	protected function setUp(): void {
		parent::setUp();
		$this->locations = get_theme_mod( 'nav_menu_locations' );
	}

	protected function tearDown(): void {
		foreach ( $this->menus as $menu ) {
			wp_delete_nav_menu( $menu );
		}

		if ( false === $this->locations ) {
			remove_theme_mod( 'nav_menu_locations' );
		} else {
			set_theme_mod( 'nav_menu_locations', $this->locations );
		}

		wp_dequeue_style( 'diluxone-users-menu' );
		wp_deregister_style( 'diluxone-users-menu' );

		parent::tearDown();
	}

	/** A menu with one link of the site's, in a location chosen for the person. */
	private function chosen_menu(): \WP_Term {
		$menu = wp_create_nav_menu( 'Look menu ' . wp_generate_password( 6, false ) );
		$this->assertIsInt( $menu );
		$this->menus[] = $menu;

		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Site link',
				'menu-item-url'    => home_url( '/about/' ),
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);

		register_nav_menu( 'look-menu', 'Look menu' );
		set_theme_mod( 'nav_menu_locations', array( 'look-menu' => $menu ) );
		diluxone_users_update_option( 'diluxone_users_menu_location', 'look-menu' );

		return get_term( $menu, 'nav_menu' );
	}

	/** The menu as the theme's header draws it. */
	private function drawn(): string {
		return (string) wp_nav_menu(
			array(
				'theme_location' => 'look-menu',
				'echo'           => false,
				'fallback_cb'    => false,
			)
		);
	}

	/** The `<li>` of one of the plugin's items, by its kind. */
	private function li( string $html, string $kind ): \DOMElement {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		$found = ( new \DOMXPath( $doc ) )->query( '//li[contains(concat(" ", @class, " "), " diluxone-users-menu--' . $kind . ' ")]' );
		$this->assertNotFalse( $found );
		$this->assertSame( 1, $found->length, "the {$kind} item is drawn once" );

		$li = $found->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $li );

		return $li;
	}

	/** @return array<int, string> */
	private function classes( \DOMElement $li ): array {
		return preg_split( '/\s+/', trim( $li->getAttribute( 'class' ) ) ) ?: array();
	}

	/* ── The person's sub-menu (C2) ────────────────────────────────── */

	public function test_the_persons_item_says_it_has_children_and_holds_its_sub_menu(): void {
		$this->chosen_menu();
		wp_set_current_user( $this->make_user() );

		$html   = $this->drawn();
		$person = $this->li( $html, 'person' );

		$this->assertContains( 'menu-item-has-children', $this->classes( $person ) );

		$sub = $person->getElementsByTagName( 'ul' )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $sub, 'the sub-menu hangs from it' );
		$this->assertSame( 'sub-menu', $sub->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'diluxone-users-menu--sign-out', (string) $person->ownerDocument?->saveHTML( $sub ), 'with the way out in it' );
	}

	public function test_an_item_nothing_hangs_from_does_not_say_it_has_children(): void {
		$this->chosen_menu();
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'menu-item-has-children', $this->drawn() );
	}
}
