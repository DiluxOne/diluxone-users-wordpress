<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads WordPress stubs so plugin code can be exercised without booting
 * WordPress. Nothing here touches the database or the running dev stack.
 */

// Composer autoload (PHPUnit, Brain Monkey, Mockery)
require_once __DIR__ . '/../vendor/autoload.php';

// Define WordPress constants that plugins expect
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/../');
}

// WordPress time constants. Any code working out an expiry uses them, and
// they do not depend on WordPress being loaded.
foreach ([
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
] as $stub_const => $stub_value) {
	if (!defined($stub_const)) {
		define($stub_const, $stub_value);
	}
}

if (!defined('DILUXONE_USERS_DIR')) {
	define('DILUXONE_USERS_DIR', __DIR__ . '/../');
}

if (!defined('DILUXONE_USERS_FILE')) {
	define('DILUXONE_USERS_FILE', DILUXONE_USERS_DIR . 'diluxone-users.php');
}

if (!defined('DILUXONE_USERS_URL')) {
	define('DILUXONE_USERS_URL', 'https://example.test/wp-content/plugins/diluxone-users/');
}

if (!defined('DILUXONE_USERS_VERSION')) {
	define('DILUXONE_USERS_VERSION', '0.0.0-test');
}

// Load WordPress function stubs
require_once __DIR__ . '/stubs/wordpress-stubs.php';

// The database object, as far as code without a database reads it: the table
// prefixes. Anything that queries still needs the integration suite.
if (!isset($GLOBALS['wpdb'])) {
	$GLOBALS['wpdb'] = (object) array(
		'prefix'      => 'wp_',
		'base_prefix' => 'wp_',
	);
}
