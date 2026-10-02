<?php
/**
 * The client's address, as the hooks a site has can change it.
 *
 * The unit suite proves the arithmetic of ranges and chains; there the stubs'
 * filters pass everything through, so what a site does with
 * `diluxone_users_ip_header` and `diluxone_users_trusted_proxies` is proven
 * here, with WordPress's own filters, and so is what is stamped on a session
 * and how many requests a machine may make.
 */

namespace Tests\Integration;

class ClientIpTest extends IntegrationTestCase {

	/** The header filter can name another header the site knows, and not invent one. */
	public function test_the_header_filter_names_a_known_header_and_nothing_else(): void {
		$this->hook( 'diluxone_users_ip_header', static fn(): string => 'HTTP_X_EVIL' );
		$this->assertSame( '', diluxone_users_ip_header(), 'a header nobody listed is not believed' );

		remove_all_filters( 'diluxone_users_ip_header' );
		$this->hook( 'diluxone_users_ip_header', static fn(): string => 'HTTP_CF_CONNECTING_IP' );

		$this->assertSame( 'HTTP_CF_CONNECTING_IP', diluxone_users_ip_header() );
		$this->assertSame(
			'198.51.100.4',
			diluxone_users_client_ip(
				array(
					'REMOTE_ADDR'           => '10.0.0.1',
					'HTTP_CF_CONNECTING_IP' => '198.51.100.4',
				)
			)
		);
	}

	/** The proxies filter adds a range the site trusts; without it, a public proxy's header is not believed. */
	public function test_the_proxies_filter_is_honoured(): void {
		diluxone_users_update_option( 'diluxone_users_ip_header', 'HTTP_X_FORWARDED_FOR' );
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.5',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
		);

		$this->assertSame( '203.0.113.5', diluxone_users_client_ip( $server ), 'not listed: the connection' );

		$this->hook( 'diluxone_users_trusted_proxies', static fn(): array => array( '203.0.113.0/24' ) );

		$this->assertSame( '198.51.100.9', diluxone_users_client_ip( $server ), 'listed: its word' );
	}

	/** A session carries the client's address, behind the proxy, and none when there is none. */
	public function test_a_session_is_stamped_with_the_clients_address(): void {
		diluxone_users_update_option( 'diluxone_users_ip_header', 'HTTP_X_FORWARDED_FOR' );
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.3';

		$this->assertSame( '198.51.100.3', apply_filters( 'attach_session_information', array(), 1 )['diluxone_users_ip'] ?? '' );

		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertArrayNotHasKey( 'diluxone_users_ip', apply_filters( 'attach_session_information', array(), 1 ) );
	}

	/**
	 * A machine gets so many tries a window: the one past the limit is
	 * refused and not counted, and a window that ran out starts over.
	 */
	public function test_a_machine_gets_so_many_tries_a_window(): void {
		$this->assertTrue( diluxone_users_ip_burst( 't', 2, 60 ) );
		$this->assertTrue( diluxone_users_ip_burst( 't', 2, 60 ) );
		$this->assertFalse( diluxone_users_ip_burst( 't', 2, 60 ) );

		$key = 'diluxone_users_burst_t_' . md5( diluxone_users_client_ip() );
		$this->assertSame( 2, (int) get_site_transient( $key )['n'], 'the refusal is not counted' );

		if ( is_multisite() ) {
			$other = (int) wp_insert_site(
				array(
					'domain' => (string) get_network()->domain,
					'path'   => '/burst-' . strtolower( wp_generate_password( 6, false ) ) . '/',
					'title'  => 'Burst',
				)
			);
			switch_to_blog( $other );
			$this->assertFalse( diluxone_users_ip_burst( 't', 2, 60 ), 'on a network, one count for every site' );
			restore_current_blog();
		}

		set_site_transient(
			$key,
			array(
				'n'     => 2,
				'until' => time() - 1,
			),
			60
		);

		$this->assertTrue( diluxone_users_ip_burst( 't', 2, 60 ), 'a window that ran out starts over' );
		$this->assertSame( 1, (int) get_site_transient( $key )['n'] );
	}
}
