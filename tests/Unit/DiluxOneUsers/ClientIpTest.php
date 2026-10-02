<?php
/**
 * The client IP is what the per-machine limits and the sessions screen rest
 * on. Behind a proxy it always stored the proxy's, which made the screen
 * useless; and if it believes any header, anybody can claim to be whoever
 * they like — and get around the limits. These tests pin the safe answer to
 * each case: no header is read unless the site named one, and then only the
 * one it named, and then only on a connection from a proxy it trusts.
 *
 * Almost every test below calls `behind_a_proxy()` first, and that is the
 * point of it: reading a header is what a site opts into, so a test about
 * reading one has to opt in the way a site does.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/client-ip.php';

		$GLOBALS['_test_wp_options'] = array();
	}

	/** The site says it is behind something that writes X-Forwarded-For. */
	private function behind_a_proxy(): void {
		update_option( 'diluxone_users_ip_header', 'HTTP_X_FORWARDED_FOR' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_with_no_proxy_remote_addr_wins(): void {
		$this->assertSame( '190.15.219.128', diluxone_users_client_ip( array(
			'REMOTE_ADDR' => '190.15.219.128',
		) ) );
	}

	public function test_a_public_ip_does_not_trust_the_headers(): void {
		// The server is exposed straight to the internet: the header was written
		// by the client and could say anything.
		$this->assertSame( '190.15.219.128', diluxone_users_client_ip( array(
			'REMOTE_ADDR'           => '190.15.219.128',
			'HTTP_X_FORWARDED_FOR'  => '8.8.8.8',
			'HTTP_CF_CONNECTING_IP' => '8.8.4.4',
		) ) );
	}

	public function test_behind_an_internal_proxy_it_uses_the_header(): void {
		$this->behind_a_proxy();

		$this->assertSame( '181.45.184.48', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '172.20.0.1',
			'HTTP_X_FORWARDED_FOR' => '181.45.184.48',
		) ) );
	}

	public function test_from_a_chain_of_proxies_it_keeps_the_client(): void {
		$this->behind_a_proxy();

		// X-Forwarded-For is "client, proxy1, proxy2": walking from the right
		// past our own proxies, the first address that is not ours is the client.
		$this->assertSame( '181.45.184.48', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '181.45.184.48, 10.0.0.9, 10.0.0.5',
		) ) );
	}

	public function test_what_the_client_wrote_before_the_first_proxy_is_ignored(): void {
		$this->behind_a_proxy();

		// The client sent its own X-Forwarded-For with a made-up address; the
		// proxy appended what it saw. The made-up one is on the left and the
		// real one is the last address that is not one of ours.
		$this->assertSame( '2.2.2.2', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 2.2.2.2, 10.0.0.9',
		) ) );
	}

	public function test_an_injected_cloudflare_header_is_ignored_behind_a_proxy_that_does_not_write_it(): void {
		$this->behind_a_proxy();

		// nginx writes X-Forwarded-For and passes everything else through: a
		// client can send CF-Connecting-IP and, before, it won over the real
		// header. Only the one header the site's proxy writes is read.
		$this->assertSame( '181.45.184.48', diluxone_users_client_ip( array(
			'REMOTE_ADDR'           => '172.16.0.1',
			'HTTP_CF_CONNECTING_IP' => '200.1.2.3',
			'HTTP_TRUE_CLIENT_IP'   => '200.1.2.4',
			'HTTP_X_REAL_IP'        => '200.1.2.5',
			'HTTP_X_FORWARDED_FOR'  => '181.45.184.48',
		) ) );
	}

	public function test_the_site_can_name_the_header_its_proxy_writes(): void {
		update_option( 'diluxone_users_ip_header', 'HTTP_CF_CONNECTING_IP' );

		$this->assertSame( '200.1.2.3', diluxone_users_client_ip( array(
			'REMOTE_ADDR'           => '172.16.0.1',
			'HTTP_CF_CONNECTING_IP' => '200.1.2.3',
			'HTTP_X_FORWARDED_FOR'  => '8.8.8.8',
		) ) );
	}

	public function test_a_named_header_that_is_missing_falls_back_to_the_proxy(): void {
		update_option( 'diluxone_users_ip_header', 'HTTP_X_REAL_IP' );

		$this->assertSame( '172.16.0.1', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '172.16.0.1',
			'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
		) ) );
	}

	public function test_a_header_name_that_is_not_one_of_ours_means_none(): void {
		update_option( 'diluxone_users_ip_header', 'REMOTE_ADDR' );

		$this->assertSame( '', diluxone_users_ip_header() );
	}

	public function test_with_nothing_chosen_no_header_is_read_at_all(): void {
		// The case this plugin used to get wrong, and it is the common one:
		// in Docker, in Kubernetes, behind a local nginx or on a laptop,
		// REMOTE_ADDR is private. Falling back to X-Forwarded-For there meant
		// the visitor chose their own address, and with it a fresh key for
		// every per-machine limit in the plugin.
		$this->assertSame( '172.20.0.1', diluxone_users_client_ip( array(
			'REMOTE_ADDR'           => '172.20.0.1',
			'HTTP_X_FORWARDED_FOR'  => '8.8.8.8',
			'HTTP_CF_CONNECTING_IP' => '8.8.4.4',
		) ) );
	}

	public function test_listing_a_proxy_is_not_on_its_own_a_licence_to_read_headers(): void {
		// Two settings and two questions: which header is written, and who is
		// allowed to write it. Neither answers the other.
		update_option( 'diluxone_users_trusted_proxies', '203.0.113.0/24' );

		$this->assertSame( '203.0.113.7', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '203.0.113.7',
			'HTTP_X_FORWARDED_FOR' => '181.45.184.48',
		) ) );
	}

	public function test_a_public_proxy_is_believed_only_once_the_site_lists_it(): void {
		$this->behind_a_proxy();

		// Cloudflare's edge has a public address: without the list its
		// headers are the client's, with the list they are Cloudflare's.
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.7',
			'HTTP_X_FORWARDED_FOR' => '181.45.184.48',
		);

		$this->assertSame( '203.0.113.7', diluxone_users_client_ip( $server ) );

		update_option( 'diluxone_users_trusted_proxies', "203.0.113.0/24\n198.51.100.1" );

		$this->assertSame( '181.45.184.48', diluxone_users_client_ip( $server ) );
	}

	public function test_listed_proxies_are_skipped_inside_the_chain_too(): void {
		$this->behind_a_proxy();

		update_option( 'diluxone_users_trusted_proxies', '203.0.113.0/24' );

		$this->assertSame( '181.45.184.48', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 181.45.184.48, 203.0.113.9, 10.0.0.9',
		) ) );
	}

	public function test_a_client_on_the_internal_network_is_reported_as_such(): void {
		$this->behind_a_proxy();

		$this->assertSame( '10.0.0.9', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '10.0.0.9',
		) ) );
	}

	public function test_it_strips_the_port_azure_appends(): void {
		$this->behind_a_proxy();

		// App Service writes "ip:port" and that is not an IP.
		$this->assertSame( '79.159.158.249', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '127.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '79.159.158.249:49391',
		) ) );
	}

	public function test_a_junk_header_breaks_nothing(): void {
		$this->behind_a_proxy();

		$this->assertSame( '10.0.0.5', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => 'not-an-ip-at-all',
		) ) );
	}

	public function test_ipv6_with_brackets_and_port(): void {
		$this->behind_a_proxy();

		$this->assertSame( '2803:9800:a::1', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '::1',
			'HTTP_X_FORWARDED_FOR' => '[2803:9800:a::1]:51234',
		) ) );
	}

	/** @dataProvider ranges */
	public function test_an_address_is_or_is_not_inside_a_range( string $ip, string $range, bool $inside ): void {
		$this->assertSame( $inside, diluxone_users_ip_in( $ip, $range ) );
	}

	/** @return array<string, array{string, string, bool}> */
	public static function ranges(): array {
		return array(
			'a bare address is itself'    => array( '203.0.113.7', '203.0.113.7', true ),
			'and nothing else'            => array( '203.0.113.8', '203.0.113.7', false ),
			'inside a /24'                => array( '203.0.113.200', '203.0.113.0/24', true ),
			'outside a /24'               => array( '203.0.114.1', '203.0.113.0/24', false ),
			'a /25 splits the last byte'  => array( '203.0.113.200', '203.0.113.0/25', false ),
			'ipv6 inside'                 => array( '2001:db8::1', '2001:db8::/32', true ),
			'ipv6 outside'                => array( '2001:db9::1', '2001:db8::/32', false ),
			'families do not mix'         => array( '2001:db8::1', '203.0.113.0/24', false ),
			'junk is outside everything'  => array( '203.0.113.7', 'not-a-range', false ),
			// A prefix that is not a number is no range: read as /0 it would
			// trust every address of the family, and anybody could then say
			// who they are in a forwarded header.
			'a prefix of letters'         => array( '198.51.100.1', '203.0.113.0/abc', false ),
			'an empty prefix'             => array( '198.51.100.1', '203.0.113.0/', false ),
			'a prefix with a sign'        => array( '198.51.100.1', '203.0.113.0/+0', false ),
			'a prefix past the family'    => array( '203.0.113.7', '203.0.113.0/33', false ),
			'a negative prefix'           => array( '203.0.113.7', '203.0.113.0/-1', false ),
			'an ipv6 prefix past 128'     => array( '2001:db8::1', '2001:db8::/129', false ),
			'an ipv6 prefix of letters'   => array( '2001:db9::1', '2001:db8::/x', false ),
			// Written down on purpose, /0 is what it says: every address.
			'a deliberate /0'             => array( '198.51.100.1', '0.0.0.0/0', true ),
			// An IPv4 address written as IPv6 is another family.
			'an ipv4-mapped address'      => array( '::ffff:203.0.113.7', '203.0.113.0/24', false ),
		);
	}

	public function test_an_ipv6_chain_keeps_the_client(): void {
		$this->behind_a_proxy();
		update_option( 'diluxone_users_trusted_proxies', '2001:db8:1::/48' );

		$this->assertSame( '2001:db8:ff::9', diluxone_users_client_ip( array(
			'REMOTE_ADDR'          => '2001:db8:1::2',
			'HTTP_X_FORWARDED_FOR' => '2001:db8:ff::9, 2001:db8:1::3',
		) ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function candidates(): array {
		return array(
			'an unclosed bracket'    => array( '[::1', '' ),
			'a port with nothing'    => array( '1.2.3.4:', '1.2.3.4' ),
			'a port'                 => array( '1.2.3.4:8080', '1.2.3.4' ),
			'ipv6 with a port'       => array( '[2001:db8::1]:443', '2001:db8::1' ),
			'not an address at all'  => array( 'unknown', '' ),
		);
	}

	/** @dataProvider candidates */
	public function test_a_candidate_is_cleaned_to_an_address_or_nothing( string $candidate, string $clean ): void {
		$this->assertSame( $clean, diluxone_users_ip_clean( $candidate ) );
	}

	public function test_an_old_session_without_our_ip_uses_the_wordpress_one(): void {
		$this->assertSame( '79.159.158.249', diluxone_users_session_ip_of( array( 'ip' => '79.159.158.249:49391' ) ) );
	}

	public function test_a_session_carrying_our_ip_is_preferred(): void {
		$this->assertSame( '181.45.184.48', diluxone_users_session_ip_of( array(
			'ip'                => '172.20.0.1',
			'diluxone_users_ip' => '181.45.184.48',
		) ) );
	}
}
