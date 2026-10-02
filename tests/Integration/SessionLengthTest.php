<?php
/**
 * How long a session lasts is WordPress's to decide until the site says so.
 *
 * The plugin used to shorten every session to 30 days with "remember me" and
 * 2 without from the moment it was turned on, over WordPress's 14 and over
 * whatever another plugin had chosen. Now both lengths start at 0, which
 * hands back whatever arrives, and only a number an administrator saved is
 * applied.
 *
 * Every case runs on both topologies; on a network the lengths are the
 * network's.
 */

namespace Tests\Integration;

class SessionLengthTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		remove_all_filters( 'auth_cookie_expiration', 5 );

		parent::tearDown();
	}

	private function length( bool $remember ): int {
		return (int) apply_filters( 'auth_cookie_expiration', $remember ? 14 * DAY_IN_SECONDS : 2 * DAY_IN_SECONDS, $this->user, $remember );
	}

	public function test_by_default_the_length_is_wordpress_own(): void {
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_session_long_days' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_session_short_days' ) );

		$this->assertSame( 14 * DAY_IN_SECONDS, $this->length( true ) );
		$this->assertSame( 2 * DAY_IN_SECONDS, $this->length( false ) );
	}

	public function test_by_default_another_plugin_s_length_is_left_alone(): void {
		add_filter( 'auth_cookie_expiration', static fn(): int => 7 * DAY_IN_SECONDS, 5 );

		$this->assertSame( 7 * DAY_IN_SECONDS, $this->length( true ) );
		$this->assertSame( 7 * DAY_IN_SECONDS, $this->length( false ) );
	}

	public function test_a_length_the_site_saved_is_applied(): void {
		diluxone_users_update_option( 'diluxone_users_session_long_days', 21 );
		diluxone_users_update_option( 'diluxone_users_session_short_days', 3 );

		$this->assertSame( 21 * DAY_IN_SECONDS, $this->length( true ) );
		$this->assertSame( 3 * DAY_IN_SECONDS, $this->length( false ) );

		// One of the two set, the other left to WordPress.
		diluxone_users_update_option( 'diluxone_users_session_short_days', 0 );

		$this->assertSame( 2 * DAY_IN_SECONDS, $this->length( false ) );
	}

	public function test_the_screen_saves_0_and_the_summary_says_wordpress_decides(): void {
		$this->in_network_admin();
		wp_set_current_user( 1 );

		diluxone_users_update_option( 'diluxone_users_session_long_days', 21 );

		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_session_long_days'  => '0',
				'diluxone_users_session_short_days' => '0',
			)
		);
		diluxone_users_sessions_save();

		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_session_long_days' ) );
		$this->assertSame( 14 * DAY_IN_SECONDS, $this->length( true ) );

		$rows = array_values(
			array_filter(
				diluxone_users_security_rows(),
				static fn( array $row ): bool => __( 'How long a session lasts', 'diluxone-users' ) === $row['label']
			)
		);

		$this->assertStringContainsString( 'WordPress', $rows[0]['detail'] );
		$this->assertStringContainsString( '14', $rows[0]['detail'] );
	}
}
