<?php
/**
 * A stand-in for WP-CLI, enough to load includes/cli.php and run its commands.
 *
 * WP-CLI is not loaded inside PHPUnit, and includes/cli.php declares nothing
 * without it. A test that runs in a process of its own defines the WP_CLI
 * constant, requires this file and then includes/cli.php: the commands are
 * declared against this class, which records what they would print and turns
 * WP_CLI::error() — an exit in WP-CLI — into an exception.
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, PEAR.NamingConventions.ValidClassName -- the class stands in for WP-CLI's own, under its own name.

if ( ! class_exists( 'WP_CLI' ) ) {
	/** What the commands under test call. */
	class WP_CLI {

		/** @var array<string, callable|string> The commands added, by name. */
		public static array $commands = array();

		/** @var array<int, array{0: string, 1: string}> Every line printed, with its kind. */
		public static array $lines = array();

		/** @param callable|string $callable */
		public static function add_command( string $name, $callable ): void {
			self::$commands[ $name ] = $callable;
		}

		public static function log( string $message ): void {
			self::$lines[] = array( 'log', $message );
		}

		public static function success( string $message ): void {
			self::$lines[] = array( 'success', $message );
		}

		/** WP-CLI prints the error and exits; here it is thrown. */
		public static function error( string $message ): void {
			self::$lines[] = array( 'error', $message );

			throw new \Tests\Integration\Support\CoverSignInStop( $message );
		}
	}
}
