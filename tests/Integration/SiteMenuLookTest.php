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

use Tests\Integration\Support\CoverSettingsSupport;

class SiteMenuLookTest extends IntegrationTestCase {

	use CoverSettingsSupport;

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
		$this->cover_settings_reset();

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

	/* ── "Sign in" as a button, in the theme's colours (C3) ───────── */

	/** @return array<int, array{0: string}> */
	public function button_styles(): array {
		return array(
			'filled'   => array( 'filled' ),
			'outlined' => array( 'outlined' ),
		);
	}

	/** @dataProvider button_styles */
	public function test_a_button_carries_the_themes_button_class_and_still_leads_to_the_sign_in_page( string $style ): void {
		$this->chosen_menu();
		wp_set_current_user( 0 );
		diluxone_users_update_option( 'diluxone_users_menu_signin_style', $style );

		$li = $this->li( $this->drawn(), 'sign-in' );

		$this->assertContains( 'diluxone-users-menu--button', $this->classes( $li ) );
		$this->assertContains( 'diluxone-users-menu--' . $style, $this->classes( $li ) );

		$link = $li->getElementsByTagName( 'a' )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $link );
		$this->assertContains( 'wp-element-button', preg_split( '/\s+/', $link->getAttribute( 'class' ) ) ?: array() );
		$this->assertSame( diluxone_users_login_url(), $link->getAttribute( 'href' ) );

		diluxone_users_menu_style();
		$css = implode( '', (array) wp_styles()->get_data( 'diluxone-users-menu', 'after' ) );
		$this->assertStringContainsString( ':where(.diluxone-users-menu--button)', $css, 'a fallback that weighs nothing beside the theme' );
		if ( 'outlined' === $style ) {
			$this->assertStringContainsString( '.diluxone-users-menu--outlined > a.wp-element-button{background:transparent', $css );
		}
	}

	public function test_the_sites_own_links_never_get_the_button_class(): void {
		$this->chosen_menu();
		wp_set_current_user( 0 );
		diluxone_users_update_option( 'diluxone_users_menu_signin_style', 'filled' );

		$this->assertSame( 1, substr_count( $this->drawn(), 'wp-element-button' ) );
	}

	public function test_a_style_that_is_not_one_saves_as_the_link(): void {
		$this->the_admin();

		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_menu_signin_style' => 'outlined' ) );
		diluxone_users_account_menu_save();
		$this->assertSame( 'outlined', diluxone_users_option( 'diluxone_users_menu_signin_style' ) );

		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_menu_signin_style' => 'glowing' ) );
		diluxone_users_account_menu_save();

		$this->assertSame( 'link', diluxone_users_option( 'diluxone_users_menu_signin_style' ) );
	}

	/* ── "Sign in" in the site's own colours (C4, C5) ─────────────── */

	/** The menu's inline CSS, for whoever is looking. */
	private function menu_css(): string {
		diluxone_users_menu_style();
		return implode( '', (array) wp_styles()->get_data( 'diluxone-users-menu', 'after' ) );
	}

	/** @return array<string, string> */
	private function own_colours( string $bg, string $text, string $border, string $hover ): array {
		return array(
			'diluxone_users_menu_signin_bg'     => $bg,
			'diluxone_users_menu_signin_text'   => $text,
			'diluxone_users_menu_signin_border' => $border,
			'diluxone_users_menu_signin_hover'  => $hover,
		);
	}

	/** @return array<string, string> */
	private function stored_colours(): array {
		$stored = array();
		foreach ( array( 'bg', 'text', 'border', 'hover' ) as $part ) {
			$stored[ 'diluxone_users_menu_signin_' . $part ] = (string) diluxone_users_option( 'diluxone_users_menu_signin_' . $part );
		}
		return $stored;
	}

	public function test_own_colours_are_saved_and_painted_from_the_tokens_without_the_themes_button(): void {
		$this->the_admin();
		$colours = $this->own_colours( '#0b3d91', '#ffffff', '#072b66', '#062456' );

		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_menu_signin_style'  => 'filled',
				'diluxone_users_menu_signin_colors' => 'own',
			) + $colours
		);
		$this->draw( 'diluxone_users_account_menu_save' );

		$this->assertSame( 'own', diluxone_users_option( 'diluxone_users_menu_signin_colors' ) );
		$this->assertSame( $colours, $this->stored_colours() );

		$this->chosen_menu();
		wp_set_current_user( 0 );

		$li = $this->li( $this->drawn(), 'sign-in' );
		$this->assertContains( 'diluxone-users-menu--own', $this->classes( $li ) );
		$link = $li->getElementsByTagName( 'a' )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $link );
		$this->assertStringNotContainsString( 'wp-element-button', $link->getAttribute( 'class' ), 'the theme’s button would paint over them' );

		$css = $this->menu_css();
		$this->assertStringContainsString( '--diluxone-users-menu-btn-bg:#0b3d91;--diluxone-users-menu-btn-text:#ffffff;--diluxone-users-menu-btn-border:#072b66;--diluxone-users-menu-btn-hover:#062456', $css );
		$this->assertStringContainsString( 'a:hover', $css );
	}

	/** @return array<string, array{0: array<string, string>}> */
	public function unreadable_pairs(): array {
		return array(
			'grey text on white'           => array( $this->own_colours( '#ffffff', '#999999', '#ffffff', '#000000' ) ),
			'reads at rest, not on hover'  => array( $this->own_colours( '#000000', '#ffffff', '#000000', '#dddddd' ) ),
		);
	}

	/**
	 * @dataProvider unreadable_pairs
	 * @param array<string, string> $colours
	 */
	public function test_a_pair_below_four_and_a_half_to_one_is_refused_and_the_colours_before_stay( array $colours ): void {
		$this->the_admin();
		$before = $this->stored_colours();

		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_menu_signin_colors' => 'own' ) + $colours );
		$said = $this->draw( 'diluxone_users_account_menu_save' );

		$this->assertSame( $before, $this->stored_colours() );
		$this->assertStringContainsString( 'notice-error', $said );
		$this->assertTrue( diluxone_users_menu_signin_palette_reads( diluxone_users_menu_signin_palette() ), 'what is stored reads' );
	}

	/** @return array<string, array{0: string}> */
	public function not_colours(): array {
		return array(
			'a word'          => array( 'red' ),
			'css let in'      => array( '#fff;}body{display:none' ),
			'no hash'         => array( 'ffffff' ),
			'too many digits' => array( '#1234567' ),
			'empty'           => array( '' ),
		);
	}

	/** @dataProvider not_colours */
	public function test_a_colour_that_is_not_a_hex_colour_is_not_stored_and_keeps_its_value( string $sent ): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_menu_signin_bg', '#0b3d91' );

		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_menu_signin_colors' => 'own',
				'diluxone_users_menu_signin_bg'     => $sent,
				'diluxone_users_menu_signin_text'   => '#fefefe',
			)
		);
		$said = $this->draw( 'diluxone_users_account_menu_save' );

		$this->assertSame( '#0b3d91', diluxone_users_option( 'diluxone_users_menu_signin_bg' ) );
		$this->assertSame( '#fefefe', diluxone_users_option( 'diluxone_users_menu_signin_text' ), 'the good one beside it is kept' );
		$this->assertStringContainsString( 'notice-error', $said );

		$this->chosen_menu();
		wp_set_current_user( 0 );
		diluxone_users_update_option( 'diluxone_users_menu_signin_style', 'filled' );
		$css = $this->menu_css();
		$this->assertStringContainsString( '--diluxone-users-menu-btn-bg:#0b3d91;', $css, 'the menu draws the colour it had' );
		$this->assertStringNotContainsString( 'body{', $css );
	}
}
