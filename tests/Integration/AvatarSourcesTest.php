<?php
/**
 * With Gravatar off, no picture comes from gravatar.com.
 *
 * The switch said so and the readme promised it, and two cases still went
 * there: a member with no photo when the initials were off too, and anybody
 * who is not a member at all — a commenter, whose address WordPress hashes
 * and sends. Both are drawn here now.
 */

namespace Tests\Integration;

class AvatarSourcesTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_avatar_gravatar', 0 );
	}

	public function test_a_member_with_nothing_is_not_sent_to_gravatar(): void {
		diluxone_users_update_option( 'diluxone_users_avatar_initials', 0 );

		$url = (string) get_avatar_url( $this->make_user() );

		$this->assertStringNotContainsString( 'gravatar.com', $url );
		$this->assertStringStartsWith( 'data:image/svg+xml', $url );
	}

	public function test_somebody_with_no_account_is_not_sent_to_gravatar(): void {
		$this->assertStringNotContainsString( 'gravatar.com', (string) get_avatar_url( 'nobody-here@example.test' ) );
		$this->assertStringNotContainsString( 'gravatar.com', (string) get_avatar( 'nobody-here@example.test' ) );
	}

	/**
	 * The photo in the site menu is the drawn one too, not an empty src.
	 *
	 * The drawing is a data: URL, and esc_url() drops a scheme it does not
	 * know and returns nothing: the menu showed a ring around no picture.
	 */
	public function test_the_menu_photo_is_drawn_when_gravatar_is_off(): void {
		$user = $this->make_user();
		$menu = wp_create_nav_menu( 'Avatar menu ' . wp_generate_password( 6, false ) );
		$this->assertIsInt( $menu );

		register_nav_menu( 'diluxone-avatar-test', 'Avatar test' );
		$locations = get_theme_mod( 'nav_menu_locations' );
		set_theme_mod( 'nav_menu_locations', array( 'diluxone-avatar-test' => $menu ) );
		diluxone_users_update_option( 'diluxone_users_menu_location', 'diluxone-avatar-test' );
		diluxone_users_update_option( 'diluxone_users_menu_style', 'avatar-name' );
		wp_set_current_user( $user );

		try {
			$items = diluxone_users_menu_items( array(), (object) array( 'menu' => get_term( $menu, 'nav_menu' ) ) );
			$title = (string) ( $items[0]->title ?? '' );

			$this->assertStringContainsString( 'diluxone-users-menu__avatar', $title );
			$this->assertStringContainsString( 'src="data:image/svg+xml', $title );
		} finally {
			wp_set_current_user( 0 );
			set_theme_mod( 'nav_menu_locations', $locations );
			wp_delete_nav_menu( $menu );
		}
	}

	public function test_with_gravatar_on_wordpress_asks_it_as_always(): void {
		diluxone_users_update_option( 'diluxone_users_avatar_gravatar', 1 );

		$this->assertStringContainsString( 'gravatar.com', (string) get_avatar_url( 'nobody-here@example.test' ) );
	}
}
