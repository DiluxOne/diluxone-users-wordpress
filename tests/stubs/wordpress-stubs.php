<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * These stubs provide basic implementations of WordPress functions
 * that DTOs and helpers use. For hook functions (add_action, add_filter, etc.)
 * use Brain Monkey instead of stubs.
 */

// ── Sanitization functions ──────────────────────────────────────

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field(string $str): string {
		return trim(strip_tags($str));
	}
}

if (!function_exists('absint')) {
	function absint($maybeint): int {
		return abs((int) $maybeint);
	}
}

if (!function_exists('esc_url_raw')) {
	function esc_url_raw(string $url): string {
		return filter_var($url, FILTER_SANITIZE_URL) ?: '';
	}
}

// ── Site / URL functions ────────────────────────────────────────
// Backed by $GLOBALS['_test_wp_url_base'] so tests can override the host.

if (!function_exists('_test_wp_url_base')) {
	function _test_wp_url_base(): string {
		return $GLOBALS['_test_wp_url_base'] ?? 'http://localhost';
	}
}

if (!function_exists('site_url')) {
	function site_url(string $path = '', ?string $scheme = null): string {
		$base = _test_wp_url_base();
		return $path ? $base . '/' . ltrim($path, '/') : $base;
	}
}

if (!function_exists('home_url')) {
	function home_url(string $path = '', ?string $scheme = null): string {
		return site_url($path, $scheme);
	}
}

if (!function_exists('untrailingslashit')) {
	function untrailingslashit(string $s): string {
		return rtrim($s, '/');
	}
}

if (!function_exists('current_time')) {
	function current_time(string $type, $gmt = 0) {
		return $type === 'timestamp' || $type === 'U' ? time() : gmdate('Y-m-d H:i:s');
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url(string $url, int $component = -1) {
		return parse_url($url, $component);
	}
}

// ── Escaping functions ──────────────────────────────────────────

if (!function_exists('esc_html')) {
	function esc_html(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_attr')) {
	function esc_attr(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_url')) {
	function esc_url(string $url): string {
		return filter_var($url, FILTER_SANITIZE_URL) ?: '';
	}
}

// ── i18n functions ──────────────────────────────────────────────

if (!function_exists('__')) {
	function __(string $text, string $domain = 'default'): string {
		return $text;
	}
}

if (!function_exists('_e')) {
	function _e(string $text, string $domain = 'default'): void {
		echo $text;
	}
}

// ── Utility functions ───────────────────────────────────────────

if (!function_exists('wp_parse_args')) {
	function wp_parse_args($args, array $defaults = []): array {
		if (is_object($args)) {
			$parsed = get_object_vars($args);
		} elseif (is_array($args)) {
			$parsed = $args;
		} else {
			parse_str((string) $args, $parsed);
		}
		return array_merge($defaults, $parsed);
	}
}

if (!function_exists('did_action')) {
	function did_action(string $hook_name): int {
		return 0;
	}
}

if (!function_exists('apply_filters')) {
	function apply_filters(string $hook_name, $value, ...$args) {
		return $value;
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, int $options = 0, int $depth = 512) {
		return json_encode($data, $options, $depth);
	}
}

// ── Options API (backed by $GLOBALS so tests can set/reset state) ──

if (!function_exists('get_option')) {
	function get_option(string $option, $default = false) {
		$store = $GLOBALS['_test_wp_options'] ?? [];
		return array_key_exists($option, $store) ? $store[$option] : $default;
	}
}

if (!function_exists('update_option')) {
	function update_option(string $option, $value, $autoload = null): bool {
		if (!isset($GLOBALS['_test_wp_options'])) {
			$GLOBALS['_test_wp_options'] = [];
		}
		$GLOBALS['_test_wp_options'][$option] = $value;
		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option(string $option): bool {
		if (isset($GLOBALS['_test_wp_options'][$option])) {
			unset($GLOBALS['_test_wp_options'][$option]);
		}
		return true;
	}
}

// ── Sanitization (cont.) ────────────────────────────────────────
//
// The hooks (add_action / add_filter) are NOT stubbed: Brain Monkey provides
// them inside each test, and a stub here would cover them up — the tests
// checking which hook something registers on, and at which priority, would
// start failing every time.

if (!function_exists('sanitize_key')) {
	function sanitize_key(string $key): string {
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
	}
}

if (!function_exists('remove_accents')) {
	// Enough of it for a key built from a label: the accented Latin letters a
	// field name is likely to carry, folded to their plain form.
	function remove_accents(string $text): string {
		$from = ['á','à','ä','â','ã','å','é','è','ë','ê','í','ì','ï','î','ó','ò','ö','ô','õ','ú','ù','ü','û','ñ','ç',
		         'Á','À','Ä','Â','Ã','Å','É','È','Ë','Ê','Í','Ì','Ï','Î','Ó','Ò','Ö','Ô','Õ','Ú','Ù','Ü','Û','Ñ','Ç'];
		$to   = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','n','c',
		         'A','A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','N','C'];

		return str_replace($from, $to, $text);
	}
}

// ── User meta in memory ─────────────────────────────────────────
//
// Enough to exercise the sign-in token logic with no database.

if (!isset($GLOBALS['diluxone_users_test_user_meta'])) {
	$GLOBALS['diluxone_users_test_user_meta'] = [];
}

if (!function_exists('get_user_meta')) {
	function get_user_meta(int $user_id, string $key, bool $single = false) {
		return $GLOBALS['diluxone_users_test_user_meta'][$user_id][$key] ?? '';
	}
}

if (!function_exists('update_user_meta')) {
	function update_user_meta(int $user_id, string $key, $value): bool {
		$GLOBALS['diluxone_users_test_user_meta'][$user_id][$key] = $value;
		return true;
	}
}

if (!function_exists('delete_user_meta')) {
	function delete_user_meta(int $user_id, string $key): bool {
		unset($GLOBALS['diluxone_users_test_user_meta'][$user_id][$key]);
		return true;
	}
}

if (!function_exists('wp_hash')) {
	// The real wp_hash uses the site salts. For the test it is enough for it to
	// be deterministic and one-way.
	function wp_hash(string $data): string {
		return hash_hmac('md5', $data, 'clave-de-prueba');
	}
}

if (!function_exists('wp_generate_password')) {
	function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$output = '';
		for ($i = 0; $i < $length; $i++) {
			$output .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return $output;
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg(array $args, string $url): string {
		return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
	}
}

if (!function_exists('add_shortcode')) {
	function add_shortcode(string $tag, $callback): void {}
}

if (!function_exists('is_user_logged_in')) {
	function is_user_logged_in(): bool { return false; }
}

// ── Users and passwords ───────────────────────────────────────────────────
// The tests looking at the second-factor policy need a person with roles, and
// the backup codes need hashing and checking. They are settled with the bare
// minimum: an array of users and a real hash, not a fake one, so that the
// "it is not stored in the clear" test means something.

if (!isset($GLOBALS['_test_wp_users'])) {
	$GLOBALS['_test_wp_users'] = [];
}

if (!function_exists('get_userdata')) {
	function get_userdata(int $user_id) {
		return $GLOBALS['_test_wp_users'][$user_id] ?? false;
	}
}

if (!function_exists('wp_hash_password')) {
	function wp_hash_password(string $password): string {
		return password_hash($password, PASSWORD_DEFAULT);
	}
}

if (!function_exists('wp_check_password')) {
	function wp_check_password(string $password, string $hash, int $user_id = 0): bool {
		return password_verify($password, $hash);
	}
}

if (!function_exists('wp_rand')) {
	function wp_rand(int $min = 0, int $max = 0): int {
		return random_int($min, $max);
	}
}

// A minimal WP_User. The production code checks `instanceof WP_User` before
// reading roles, which is correct; without this class the tests would go down
// the "does not exist" path and prove nothing.
if (!class_exists('WP_User')) {
	class WP_User {
		public $ID = 0;
		public $roles = [];
		public $user_email = '';
		public $user_login = '';
		public $first_name = '';
		public $last_name = '';
		public $display_name = '';
		public $user_registered = '2020-01-01 00:00:00';

		public function __construct(int $id = 0, array $roles = []) {
			$this->ID = $id;
			$this->roles = $roles;
		}
	}
}

// ── Transients ────────────────────────────────────────────────────────────
// The passkey challenges live here: they are single-use and short-lived,
// which is exactly what a transient does.

if (!isset($GLOBALS['_test_wp_transients'])) {
	$GLOBALS['_test_wp_transients'] = [];
}

if (!function_exists('set_transient')) {
	function set_transient(string $key, $value, int $expiration = 0): bool {
		$GLOBALS['_test_wp_transients'][$key] = $value;
		return true;
	}
}

if (!function_exists('get_transient')) {
	function get_transient(string $key) {
		return $GLOBALS['_test_wp_transients'][$key] ?? false;
	}
}

if (!function_exists('delete_transient')) {
	function delete_transient(string $key): bool {
		// Like WordPress: true only when there was something to delete.
		$existed = array_key_exists($key, $GLOBALS['_test_wp_transients'] ?? array());
		unset($GLOBALS['_test_wp_transients'][$key]);
		return $existed;
	}
}

if (!function_exists('wp_list_pluck')) {
	function wp_list_pluck($list, string $field): array {
		$out = array();

		foreach ((array) $list as $key => $row) {
			$out[$key] = is_object($row) ? $row->$field : $row[$field];
		}

		return $out;
	}
}

if (!function_exists('wp_unslash')) {
	function wp_unslash($value) {
		return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
	}
}

// ── Errors ────────────────────────────────────────────────────────────────
// The gate on the doors with no screen answers with a WP_Error, and the
// social mappers ask is_wp_error() of an HTTP answer.

if (!class_exists('WP_Error')) {
	class WP_Error {
		public string $code;
		public string $message;

		public function __construct(string $code = '', string $message = '') {
			$this->code = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing): bool {
		return $thing instanceof WP_Error;
	}
}
