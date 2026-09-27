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

		update_option( 'diluxone_users_avatar_gravatar', 0 );
	}

	public function test_a_member_with_nothing_is_not_sent_to_gravatar(): void {
		update_option( 'diluxone_users_avatar_initials', 0 );

		$url = (string) get_avatar_url( $this->make_user() );

		$this->assertStringNotContainsString( 'gravatar.com', $url );
		$this->assertStringStartsWith( 'data:image/svg+xml', $url );
	}

	public function test_somebody_with_no_account_is_not_sent_to_gravatar(): void {
		$this->assertStringNotContainsString( 'gravatar.com', (string) get_avatar_url( 'nobody-here@example.test' ) );
		$this->assertStringNotContainsString( 'gravatar.com', (string) get_avatar( 'nobody-here@example.test' ) );
	}

	public function test_with_gravatar_on_wordpress_asks_it_as_always(): void {
		update_option( 'diluxone_users_avatar_gravatar', 1 );

		$this->assertStringContainsString( 'gravatar.com', (string) get_avatar_url( 'nobody-here@example.test' ) );
	}
}
