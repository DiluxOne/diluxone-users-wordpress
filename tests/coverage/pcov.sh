#!/usr/bin/env bash
# Puts PCOV in the tests-cli container of the wp-env project in the directory
# given (the checkout for the network tests site, build/integration-single for
# the single site), once: a container rebuilt by wp-env loses it, and the next
# coverage run puts it back.
#
# It is installed switched off (pcov.enabled=0), so `make test-integration`
# runs exactly as fast as before; the coverage targets switch it on for their
# own run with `php -d pcov.enabled=1`.
#
# Usage: tests/coverage/pcov.sh <wp-env project directory>
set -euo pipefail

dir="${1:?the wp-env project directory}"
install_path=$(cd "$dir" && npx @wordpress/env status 2>/dev/null | sed -n 's/.*install path: //p' | head -1)
if [ -z "$install_path" ]; then
  echo "✗ no wp-env running in $dir" >&2
  exit 1
fi
container="$(basename "$install_path")-tests-cli-1"

if docker exec "$container" php -m 2>/dev/null | grep -qx pcov; then
  exit 0
fi

echo "Installing PCOV in $container…"
docker exec -u root "$container" sh -c '
  set -e
  pecl install pcov >/dev/null
  docker-php-ext-enable pcov
  echo "pcov.enabled=0" >> "$(php -r "echo PHP_CONFIG_FILE_SCAN_DIR;")/docker-php-ext-pcov.ini"
'
docker exec "$container" php -m | grep -qx pcov
