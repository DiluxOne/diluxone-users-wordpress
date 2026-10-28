<?php
/**
 * #228: signing in by public name is gone. WordPress signs in by its
 * username, which here is the e-mail, and nothing in the plugin reads the
 * setting that offered a second way, however a site has it stored.
 */

namespace Tests\Unit\DiluxOneUsers;

use PHPUnit\Framework\TestCase;

class NoPublicNameSignInTest extends TestCase {

	public function test_nothing_the_plugin_ships_reads_the_old_setting(): void {
		$files = array_merge(
			glob( DILUXONE_USERS_DIR . 'includes/*.php' ) ?: array(),
			glob( DILUXONE_USERS_DIR . 'templates/*.php' ) ?: array(),
			glob( DILUXONE_USERS_DIR . 'templates/*/*.php' ) ?: array(),
			array( DILUXONE_USERS_DIR . 'diluxone-users.php' )
		);

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString( 'diluxone_users_handle_login', (string) file_get_contents( $file ), basename( $file ) );
		}
	}
}
