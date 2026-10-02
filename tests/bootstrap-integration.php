<?php
/**
 * PHPUnit bootstrap for integration tests.
 *
 * Loads WordPress core with the plugin already activated and ensures
 * the plugin's custom DB table exists. Runs inside the wp-env "tests"
 * Docker container, where:
 *
 * - WordPress core lives at  /var/www/html/
 * - This plugin is mounted at /var/www/html/wp-content/plugins/diluxone-users/
 * - Composer vendor/ ships from the host repo via the same mount.
 * - The MySQL database for tests is named `tests-wordpress` (wp-env default).
 *
 * Outside of the container, this file MUST NOT be required — the
 * /var/www/html paths do not exist on the host. The CI workflow
 * (the shared plugin-tests-wp workflow) invokes phpunit via `npx wp-env run tests-cli`
 * specifically for that reason.
 */

// 0. Prevent WordPress from sending headers / loading themes during bootstrap.
define('WP_USE_THEMES', false);

// 1. Simulate admin AJAX context so handlers register and wp_die is interceptable.
if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
$_SERVER['PHP_SELF'] = '/wp-admin/admin-ajax.php';

// 2. Set HTTP_HOST etc. to prevent "Undefined array key" warnings under CLI.
//
//    On a multisite network the host MUST be one WordPress knows: for an
//    unknown domain ms-settings.php redirects to the signup page and calls
//    exit(0) before PHPUnit ever starts — no banner, no error, exit code 0,
//    which is the worst possible way for a suite to fail. wp-config.php
//    carries the network domain as DOMAIN_CURRENT_SITE; read it from the
//    file (constants are not available before WordPress loads) and fall
//    back to a placeholder on single-site installs, where any host works.
if (empty($_SERVER['HTTP_HOST'])) {
    $host = getenv('HTTP_HOST') ?: '';
    if ($host === '' && is_readable('/var/www/html/wp-config.php')) {
        if (preg_match(
            "/define\\(\\s*'DOMAIN_CURRENT_SITE'\\s*,\\s*'([^']+)'/",
            (string) file_get_contents('/var/www/html/wp-config.php'),
            $m
        )) {
            $host = $m[1];
        }
    }
    $_SERVER['HTTP_HOST'] = $host !== '' ? $host : 'tests.local';
}
if (empty($_SERVER['SERVER_NAME'])) {
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
}
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

// 3. Resolve the plugin directory dynamically. This bootstrap file lives at
//    <plugin-root>/tests/bootstrap-integration.php — inside the wp-env tests
//    container that resolves to /var/www/html/wp-content/plugins/<slug>/tests/,
//    so dirname(__DIR__) gives the plugin root regardless of the slug. This
//    keeps the bootstrap working if the plugin folder is ever renamed.
$plugin_dir_in_container = dirname(__DIR__);

// 4. Load WordPress (this loads the plugin since it's activated).
$wp_load = '/var/www/html/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "ERROR: wp-load.php not found at {$wp_load}\n");
    fwrite(STDERR, "Integration tests must run inside the wp-env tests container.\n");
    fwrite(STDERR, "Use: npx wp-env run tests-cli ./vendor/bin/phpunit -c phpunit-integration.xml\n");
    exit(1);
}

// 4a. Coverage of what runs while the plugin loads. PHPUnit measures from the
//     first test on, so every add_action() and add_filter() at the top of a
//     file under includes/ — run once, here, when WordPress loads the plugin —
//     would count as never run. `make coverage-integration` names a file in
//     DU_COVERAGE_BOOTSTRAP and runs with PCOV switched on; the load is then
//     measured on its own and written there, and `phpcov merge` adds it to the
//     suite's report. Without the variable nothing here happens.
$du_coverage_bootstrap = (string) getenv('DU_COVERAGE_BOOTSTRAP');
$du_coverage_driver    = null;
if ($du_coverage_bootstrap !== '' && extension_loaded('pcov') && ini_get('pcov.enabled')) {
    $du_coverage_filter = new \SebastianBergmann\CodeCoverage\Filter();
    $du_coverage_filter->includeDirectory($plugin_dir_in_container . '/includes');
    $du_coverage_filter->includeDirectory($plugin_dir_in_container . '/templates');
    $du_coverage_driver = new \SebastianBergmann\CodeCoverage\Driver\PcovDriver($du_coverage_filter);
    $du_coverage_driver->start();
}

require_once $wp_load;

if ($du_coverage_driver !== null) {
    $du_coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage($du_coverage_driver, $du_coverage_filter);
    $du_coverage->excludeUncoveredFiles();
    $du_coverage->append($du_coverage_driver->stop(), 'bootstrap: WordPress loads the plugin');
    (new \SebastianBergmann\CodeCoverage\Report\PHP())->process($du_coverage, $du_coverage_bootstrap);
    unset($du_coverage, $du_coverage_filter);
}
unset($du_coverage_bootstrap, $du_coverage_driver);

// 5. Verify we're talking to the test database. wp-env names it
//    "tests-wordpress" by default. The substring check is the safety net
//    against accidentally running integration tests against a real database.
global $wpdb;
$db_name = $wpdb->dbname;
if (strpos($db_name, 'test') === false) {
    fwrite(STDERR, "SAFETY CHECK FAILED: database '{$db_name}' does not contain 'test'.\n");
    fwrite(STDERR, "Integration tests MUST run on a test database. Aborting.\n");
    exit(1);
}

// 6. Composer autoload (PHPUnit, Brain Monkey, Mockery, Tests\ namespace).
//    The vendor/ tree comes from the host's `composer install`, mounted
//    into the container via the plugin's volume.
$composer_autoload = $plugin_dir_in_container . '/vendor/autoload.php';
if (!file_exists($composer_autoload)) {
    fwrite(STDERR, "ERROR: composer autoload not found at {$composer_autoload}.\n");
    fwrite(STDERR, "Run `composer install` on the host before invoking integration tests.\n");
    exit(1);
}
require_once $composer_autoload;

// 7. Verify the plugin loaded.
if (!defined('DILUXONE_USERS_DIR')) {
    fwrite(STDERR, "ERROR: diluxone-users is not activated in the tests environment.\n");
    fwrite(STDERR, "Run: npx wp-env run tests-cli wp plugin activate diluxone-users\n");
    exit(1);
}

// 8. Mark integration test context. Most of the plugin's data lives in
//    options and user meta, which WordPress already creates. The one table of
//    its own — the activity log — is created by its installer, which the suite
//    that needs it calls in setUp(): the plugin is already active by the time
//    this file runs, so its activation hook never fires here.
if (!defined('DILUXONE_USERS_INTEGRATION_TESTS')) {
    define('DILUXONE_USERS_INTEGRATION_TESTS', true);
}

// 11. Override wp_die handlers globally so AJAX handlers throw an
//     exception instead of terminating the PHPUnit process. Tests that
//     expect wp_die catch WPAjaxDieContinueException, which carries what the
//     handler was told — the status and the code — and not only the words:
//     a refusal answered 200 is not a refusal, and a test that cannot tell
//     the two apart passes on both.
class WPAjaxDieContinueException extends \Exception {

    /** @var int The HTTP status wp_die() was asked for (500 when it was not). */
    public int $status = 500;

    /** @var string The error code wp_die() was given ('wp_die' when it was not). */
    public string $die_code = 'wp_die';
}

$_diluxone_users_wp_die_test_handler = function ($message, $title = '', $args = []) {
    [$message, , $args] = _wp_die_process_input($message, $title, $args);

    $e           = new WPAjaxDieContinueException(is_scalar($message) ? (string) $message : '');
    $e->status   = (int) $args['response'];
    $e->die_code = (string) $args['code'];

    throw $e;
};

add_filter('wp_die_ajax_handler', function () use ($_diluxone_users_wp_die_test_handler) {
    return $_diluxone_users_wp_die_test_handler;
}, 999);

add_filter('wp_die_handler', function () use ($_diluxone_users_wp_die_test_handler) {
    return $_diluxone_users_wp_die_test_handler;
}, 999);

// 12. Confirmation banner (shows in CI logs).
echo "Integration test bootstrap loaded.\n";
echo "  Database:    {$db_name}\n";
echo "  Prefix:      {$wpdb->prefix}\n";
echo "  WP version:  " . get_bloginfo('version') . "\n";
echo "  Plugin ver:  " . DILUXONE_USERS_VERSION . "\n";
