#!/usr/bin/env bash
#
# Activating the plugin changes nothing on the site.
#
# A clean WordPress of its own (wp-env under build/noop, 8884/8885, no
# mu-plugin), fingerprinted with the plugin switched off and cleaned out, and
# again after activating it with nothing saved: the pages a visitor and a
# subscriber see, the dashboard's Users list and profile, every option, every
# table, every user meta key, the rewrite rules, the cron events, and the
# mails and outgoing HTTP requests of a registration, a password reset and a
# sign-in. The two have to be the same, except for the one option the plugin
# writes on its own account (ALLOWED below). Run with `make test-noop`.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SLUG="$(basename "$ROOT")"
ENV_DIR="$ROOT/build/noop"
OUT="$ENV_DIR/fingerprint"
URL="http://localhost:8884"

# What activation may write. The defaults version: which defaults the install
# settled on, today's or an older version's written down (includes/migrate.php).
ALLOWED_OPTIONS='^diluxone_users_defaults_version$'

mkdir -p "$ENV_DIR"
cat > "$ENV_DIR/.wp-env.json" <<JSON
{
  "core": null,
  "phpVersion": "8.3",
  "plugins": [ "../.." ],
  "testsEnvironment": false,
  "config": { "WP_DEBUG": true, "WP_DEBUG_DISPLAY": false, "DISABLE_WP_CRON": true },
  "port": 8884
}
JSON

cd "$ENV_DIR"
npx @wordpress/env start >/dev/null

wp() { npx @wordpress/env run cli wp "$@" 2>/dev/null; }
sql() { wp db query "$1" --skip-column-names; }

# The plugin off, and everything it ever wrote gone: the "before" is a site
# that never had it.
wp plugin deactivate "$SLUG" >/dev/null || true
sql "DELETE FROM wp_options WHERE option_name LIKE 'diluxone\\_users\\_%' OR option_name LIKE '\\_transient%diluxone\\_users%'"
sql "DELETE FROM wp_usermeta WHERE meta_key LIKE 'diluxone\\_users\\_%'"
sql "DROP TABLE IF EXISTS wp_diluxone_users_log"
wp rewrite structure '/%postname%/' >/dev/null
wp rewrite flush >/dev/null
wp option update users_can_register 1 >/dev/null
wp user get noop >/dev/null 2>&1 || wp user create noop noop@example.test --role=subscriber --user_pass=noop-pass >/dev/null

norm() {
	sed -E 's/data-pw="[^"]*"/data-pw="X"/g; s/(nonce|_wpnonce|ver|redirect_to|testcookie|_wp_http_referer|reauth)=[^"&'"'"' ]*/\1=X/g; s/"nonce":"[^"]*"/"nonce":"X"/g; s/[0-9a-f]{10,}/H/g; s/[0-9]{10}/T/g'
}

# The dashboard's menu is where the plugin's own screens are allowed to
# appear, so it is left out of the comparison, and so is the command palette
# that lists the same entries. Everything else on the page is compared.
admin_page() {
	norm | awk '/id="adminmenumain"/ { skip = 1 } /<div id="wpcontent">/ { skip = 0 } /initializeCommandPalette/ { next } !skip'
}

signin() { # $1 user, $2 password, $3 cookie jar
	curl -s -o /dev/null -c "$3" -b "wordpress_test_cookie=WP%20Cookie%20check" \
		--data-urlencode "log=$1" --data-urlencode "pwd=$2" -d "wp-submit=Log+In&testcookie=1" "$URL/wp-login.php"
}

fingerprint() { # $1 directory
	local d="$1" jar
	rm -rf "$d"; mkdir -p "$d"
	jar="$(mktemp)"

	# Every page is drawn for somebody with no other session open: how many
	# there are is on the profile screen.
	wp user session destroy noop --all >/dev/null || true
	wp user session destroy admin --all >/dev/null || true

	curl -s "$URL/" | norm > "$d/home.html"
	curl -s "$URL/wp-login.php" | norm > "$d/wp-login.html"
	curl -s "$URL/wp-login.php?action=register" | norm > "$d/register.html"
	curl -s "$URL/wp-login.php?action=lostpassword" | norm > "$d/lostpassword.html"
	curl -s "$URL/sso/google/" -o /dev/null -w '%{http_code}\n' > "$d/sso-route.txt"

	signin noop noop-pass "$jar"
	curl -s -b "$jar" "$URL/wp-admin/profile.php" | admin_page > "$d/profile-subscriber.html"
	signin admin password "$jar"
	curl -s -b "$jar" "$URL/wp-admin/profile.php" | admin_page > "$d/profile-admin.html"
	curl -s -b "$jar" "$URL/wp-admin/user-edit.php?user_id=$(wp user get noop --field=ID | tr -d '[:space:]')" | admin_page > "$d/user-edit.html"
	curl -s -b "$jar" "$URL/wp-admin/user-new.php" | admin_page > "$d/user-new.html"
	curl -s -b "$jar" "$URL/wp-admin/users.php" | admin_page > "$d/users.html"
	rm -f "$jar"

	# A registration, a reset and a sign-in, in PHP: the mails they send and
	# every HTTP request made on the way.
	wp eval '
		$seen = array();
		add_filter( "pre_wp_mail", function ( $r, $a ) use ( &$seen ) { $seen[] = "mail " . md5( wp_json_encode( array( $a["to"], $a["subject"], preg_replace( "/[A-Za-z0-9]{20}/", "K", $a["message"] ) ) ) ); return true; }, 10, 2 );
		add_filter( "pre_http_request", function ( $r, $args, $url ) use ( &$seen ) { $seen[] = "http " . wp_parse_url( $url, PHP_URL_HOST ); return new WP_Error( "noop", "blocked" ); }, 10, 3 );
		$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
		$id = register_new_user( "noopreg", "noopreg@example.test" );
		retrieve_password( "noop" );
		$u = wp_signon( array( "user_login" => "noop", "user_password" => "noop-pass" ) );
		echo get_avatar( "noop@example.test" ), "\n";
		if ( ! is_wp_error( $id ) ) { require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user( $id ); }
		echo implode( "\n", $seen ), "\n";
	' | norm > "$d/mail-http.txt"

	sql "SELECT option_name FROM wp_options WHERE option_name NOT LIKE '%transient%' AND option_name NOT IN ('cron') ORDER BY 1" > "$d/options.txt"
	sql "SHOW TABLES" > "$d/tables.txt"
	sql "SELECT DISTINCT meta_key FROM wp_usermeta ORDER BY 1" > "$d/usermeta.txt"
	sql "SELECT option_value FROM wp_options WHERE option_name='rewrite_rules'" | md5sum > "$d/rewrite.md5"
	wp cron event list --fields=hook --format=csv | sort -u > "$d/cron.txt"
}

fingerprint "$OUT/before"
wp plugin activate "$SLUG" >/dev/null
curl -s -o /dev/null "$URL/"   # the first request after activation
fingerprint "$OUT/after"

# The option activation is allowed to write, and nothing else.
grep -Ev "$ALLOWED_OPTIONS" "$OUT/after/options.txt" > "$OUT/after/options.txt.tmp" && mv "$OUT/after/options.txt.tmp" "$OUT/after/options.txt"

if diff -ru "$OUT/before" "$OUT/after"; then
	echo "✓ activating DiluxOne Users+ changed nothing (fingerprints in $OUT)"
	exit 0
fi

echo "✗ activating DiluxOne Users+ changed the site: the diff is above (fingerprints in $OUT)"
exit 1
