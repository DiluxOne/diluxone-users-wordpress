<?php
/**
 * Security › Behind a proxy, and Reports › Sessions.
 *
 * The proxy header decides which address the site believes a person comes
 * from, so it is saved only behind the screen's nonce and the network's
 * capability on a network, and only as one of the headers the plugin knows;
 * anything else is "read the connection". The sessions report says when
 * somebody's sessions were just closed and is paged when it is long.
 *
 * Every case runs on both topologies.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminSessionsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	protected function setUp(): void {
		parent::setUp();

		// The report's search ends in WordPress's own submit_button().
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	protected function tearDown(): void {
		$this->cover_admin_reset();

		parent::tearDown();
	}

	/** @param array<string, mixed> $post */
	private function proxy( array $post ): string {
		$this->postPanel( DILUXONE_USERS_SECURITY, $post );

		return $this->ended( 'diluxone_users_proxy_save' )[0];
	}

	public function test_a_known_header_and_the_proxies_are_saved_cleaned(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->assertSame(
			'returned',
			$this->proxy(
				array(
					'diluxone_users_ip_header'       => 'http_cf_connecting_ip',
					'diluxone_users_trusted_proxies' => "10.0.0.1\n<b>10.0.0.2</b>",
				)
			)
		);

		$this->assertSame( 'HTTP_CF_CONNECTING_IP', diluxone_users_raw_get( 'diluxone_users_ip_header' ) );
		$this->assertSame( "10.0.0.1\n10.0.0.2", diluxone_users_raw_get( 'diluxone_users_trusted_proxies' ) );
	}

	public function test_a_header_the_plugin_does_not_know_is_reading_the_connection(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->proxy( array( 'diluxone_users_ip_header' => 'HTTP_X_EVIL' ) );

		$this->assertSame( '', diluxone_users_raw_get( 'diluxone_users_ip_header' ) );
	}

	public function test_the_proxy_is_not_saved_without_the_nonce_or_the_capability(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->postAs( get_current_user_id(), array( 'diluxone_users_ip_header' => 'HTTP_X_REAL_IP' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_proxy_save' ) );

		// A site's administrator in Network Admin, or an editor on a single site.
		$who = $this->make_user( is_multisite() ? 'administrator' : 'editor' );
		wp_set_current_user( $who );
		$this->postAs(
			$who,
			array(
				'diluxone_users_ip_header'   => 'HTTP_X_REAL_IP',
				'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_SECURITY ),
			)
		);
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( 'diluxone_users_proxy_save' ) );

		$this->assertNotSame( 'HTTP_X_REAL_IP', diluxone_users_raw_get( 'diluxone_users_ip_header' ) );
	}

	public function test_the_report_says_sessions_were_closed_and_pages_a_long_list(): void {
		$this->the_admin();

		for ( $i = 0; $i < 6; $i++ ) {
			\WP_Session_Tokens::get_instance( $this->make_user() )->create( time() + HOUR_IN_SECONDS );
		}

		$_GET = array(
			'diluxone_users_done' => 'closed',
			'per'                 => '5',
		);
		$html = $this->draw( 'diluxone_users_screen_sessions_list' );

		$this->assertStringContainsString( 'Their sessions are closed: they are signed out everywhere.', $html );
		$this->assertStringContainsString( 'tablenav-pages', $html );
		$this->assertStringContainsString( 'paged=2', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_sessions_admin"', $html );
	}

	public function test_the_lengths_and_the_box_are_saved_as_the_form_sent_them(): void {
		$this->where_network_screens_are();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_sessions_show', 1 );

		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_session_long_days'  => '-5',
				'diluxone_users_session_short_days' => array( '3' ),
			)
		);
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_sessions_save' )[0] );

		$this->assertSame( 5, (int) diluxone_users_raw_get( 'diluxone_users_session_long_days' ), 'the box takes no negative number: a minus sign is a slip' );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_session_short_days' ), 'a list is no number, and nothing is left to WordPress' );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_sessions_show' ), 'a box left unticked is off' );

		$this->postPanel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_session_long_days'  => '30',
				'diluxone_users_session_short_days' => '2',
				'diluxone_users_sessions_show'      => '1',
			)
		);
		$this->ended( 'diluxone_users_sessions_save' );

		$this->assertSame( 30, (int) diluxone_users_raw_get( 'diluxone_users_session_long_days' ) );
		$this->assertSame( 2, (int) diluxone_users_raw_get( 'diluxone_users_session_short_days' ) );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_sessions_show' ) );
	}

	public function test_a_proxy_list_sent_as_a_list_is_no_proxy(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->assertSame( 'returned', $this->proxy( array( 'diluxone_users_ip_header' => array( 'HTTP_X_FORWARDED_FOR' ), 'diluxone_users_trusted_proxies' => array( '10.0.0.1' ) ) ) );

		$this->assertSame( '', diluxone_users_raw_get( 'diluxone_users_ip_header' ) );
		$this->assertSame( '', diluxone_users_raw_get( 'diluxone_users_trusted_proxies' ) );
	}

	public function test_a_length_of_nothing_is_left_to_wordpress(): void {
		$this->assertSame( 'what WordPress decides', diluxone_users_session_days_words( 0 ) );
		$this->assertSame( 'what WordPress decides', diluxone_users_session_days_words( -3 ) );
		$this->assertSame( '14 days', diluxone_users_session_days_words( 14 ) );
	}
}
