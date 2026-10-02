<?php
/**
 * On a network the plugin is on for the whole network, or it does nothing.
 *
 * The header asks WordPress for network activation only; the gate lets the
 * plugin load on a single site always, on a network only when it is on for
 * the whole network; and a site it was left on for alone gets a notice for
 * the network's administrator and nothing else. All of it without a database.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class NetworkGateTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/network-gate.php';
		$GLOBALS['_test_multisite'] = false;

		Monkey\Functions\when( 'plugin_basename' )->justReturn( 'diluxone-users/diluxone-users.php' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** What the network's list of plugins says. */
	private function network_list( bool $has_it ): void {
		Monkey\Functions\when( 'get_site_option' )->alias(
			static fn( string $key, $fallback = false ) => 'active_sitewide_plugins' === $key
				? ( $has_it ? array( 'diluxone-users/diluxone-users.php' => 1 ) : array() )
				: $fallback
		);
	}

	public function test_the_header_asks_for_network_activation_only(): void {
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Network:\s*true\s*$/m', (string) file_get_contents( DILUXONE_USERS_FILE ) );
	}

	public function test_a_single_site_always_runs_it(): void {
		$this->network_list( false );

		$this->assertTrue( diluxone_users_awake() );
		$this->assertFalse( diluxone_users_network_activated() );
	}

	public function test_a_network_runs_it_only_when_it_is_on_for_the_whole_network(): void {
		$GLOBALS['_test_multisite'] = true;

		$this->network_list( true );
		$this->assertTrue( diluxone_users_awake() );

		$this->network_list( false );
		$this->assertFalse( diluxone_users_awake(), 'On for one site alone: asleep' );
	}

	/** Asleep, it hooks the notice and nothing else. */
	public function test_asleep_it_only_says_so(): void {
		Monkey\Actions\expectAdded( 'network_admin_notices' )->once()->with( 'diluxone_users_asleep_notice' );
		Monkey\Actions\expectAdded( 'admin_notices' )->once()->with( 'diluxone_users_asleep_notice' );
		Monkey\Actions\expectAdded( 'init' )->never();
		Monkey\Actions\expectAdded( 'admin_init' )->never();
		Monkey\Filters\expectAdded( 'authenticate' )->never();

		diluxone_users_sleep();

		$this->addToAssertionCount( 1 );
	}

	/** The notice is for whoever can activate it for the network, and takes them there. */
	public function test_the_notice_is_for_whoever_manages_the_networks_plugins_and_points_at_the_networks_plugins(): void {
		Monkey\Functions\when( 'esc_html__' )->returnArg( 1 );
		Monkey\Functions\when( 'network_admin_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-admin/network/' . $path );

		Monkey\Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => false );
		ob_start();
		diluxone_users_asleep_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'A site administrator cannot do what it asks' );

		Monkey\Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'manage_network_plugins' === $cap );

		// Not on a screen that has nothing to do with the plugin.
		$GLOBALS['pagenow'] = 'users.php';
		ob_start();
		diluxone_users_asleep_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'quiet on the Users screen' );

		$GLOBALS['pagenow'] = 'plugins.php';
		ob_start();
		diluxone_users_asleep_notice();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['pagenow'] );

		$this->assertStringContainsString( 'href="https://example.test/wp-admin/network/plugins.php"', $html );
		$this->assertStringContainsString( 'Activate DiluxOne Users+ for the whole network', $html );
	}
}
