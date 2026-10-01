# diluxone-users — developer task runner.
#
# All PHP-based commands run inside the official `composer:2` Docker
# image by default. That keeps the host clean of plugin-specific PHP
# extensions (dom, mbstring, xml, xmlwriter, etc.) which the standard
# WSL `php-cli` build tends to lack. The Composer + dev-tooling
# versions still come from composer.lock either way, so the runtime
# difference vs CI is just the PHP-extension surface area.
#
# Usage:
#   make            # = make help
#   make install    # composer install
#   make check      # everything CI runs
#   make release    # check + version-alignment dry-run
#
# Override DOCKER=0 to invoke local binaries instead. Only viable on
# hosts that already have a full PHP CLI with the required extensions
# (dom, mbstring, xml, xmlwriter, libxml, openssl, json, fileinfo,
# tokenizer) and `wp` and `npx` available on $PATH.

SHELL := /bin/bash

# -- Docker plumbing ---------------------------------------------------
# Mount the project read/write at /app, run as the host user so
# composer doesn't leave root-owned files in vendor/.
DOCKER ?= 1
DOCKER_USER := $(shell id -u):$(shell id -g)
DOCKER_RUN  := docker run --rm -u $(DOCKER_USER) -v $(CURDIR):/app -w /app

# Pin floating tags via env override for reproducibility:
#   make stan COMPOSER_IMAGE=composer:2.7
COMPOSER_IMAGE ?= composer:2
WP_CLI_IMAGE   ?= wordpress:cli
PHP_IMAGE      ?= php:8.3-cli

# `--network host` lets the wordpress:cli container reach the wp-env
# MySQL on the same host. It is unsupported on Docker Desktop for
# macOS/Windows; on those hosts override DOCKER_NET= to drop it (and
# arrange WP-CLI / integration tests another way, e.g. via wp-env).
DOCKER_NET ?= --network host

ifeq ($(DOCKER),1)
COMPOSER  := $(DOCKER_RUN) $(COMPOSER_IMAGE) composer
VENDOR    := $(DOCKER_RUN) $(COMPOSER_IMAGE)
PSALM_CMD := $(DOCKER_RUN) $(PHP_IMAGE) ./vendor/bin/psalm
WP_CLI    := $(DOCKER_RUN) $(DOCKER_NET) $(WP_CLI_IMAGE)
INTEG     := $(DOCKER_RUN) $(DOCKER_NET) $(COMPOSER_IMAGE)
else
COMPOSER  := composer
VENDOR    :=
PSALM_CMD := ./vendor/bin/psalm
WP_CLI    := wp
INTEG     :=
endif

# -- Default target ----------------------------------------------------
.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help.
	@awk 'BEGIN {FS = ":.*##"; printf "\nTargets:\n"} \
	  /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[1;32m%-18s\033[0m %s\n", $$1, $$2}' \
	  $(MAKEFILE_LIST)
	@echo
	@echo "Override the Docker mode with DOCKER=0 to use local binaries."

# -- Setup -------------------------------------------------------------
.PHONY: install
install: ## Install dev dependencies (composer install).
	$(COMPOSER) install --no-interaction --prefer-dist --no-progress

.PHONY: update
update: ## Update dev dependencies (composer update).
	$(COMPOSER) update --no-interaction --prefer-dist --no-progress

# -- Linting / static analysis ----------------------------------------
.PHONY: lint
lint: ## PHPCS + WordPress Coding Standards.
	# --no-cache on purpose: PHPCS caches per file and, when a file goes back
	# to a content it already analysed, answers with the stale result. That is
	# green locally while CI — which always starts clean — is red, and half an
	# hour goes into looking for the difference.
	$(VENDOR) ./vendor/bin/phpcs --no-cache

.PHONY: lint-fix
lint-fix: ## Auto-fix PHPCS violations where possible.
	$(VENDOR) ./vendor/bin/phpcbf --no-cache

.PHONY: stan
stan: ## PHPStan level 8 (no baseline).
	$(VENDOR) ./vendor/bin/phpstan analyse --memory-limit=2G --no-progress

.PHONY: psalm
psalm: ## Psalm taint analysis (XSS / SQLi / RCE).
	$(PSALM_CMD) --taint-analysis --no-cache --no-progress

.PHONY: i18n
i18n: ## Refresh languages/diluxone-users.pot from the source strings.
	$(WP_CLI) i18n make-pot . languages/diluxone-users.pot \
	    --slug=diluxone-users \
	    --domain=diluxone-users \
	    --exclude=tests,vendor,node_modules,.wordpress-org,assets,docs,build

.PHONY: i18n-update
i18n-update: i18n ## Merge the refreshed .pot into every shipped .po (keeps existing translations).
	@for po in languages/*.po; do \
	  $(WP_CLI) i18n update-po languages/diluxone-users.pot "$$po" >/dev/null && echo "  merged $$po"; \
	done

.PHONY: i18n-mo
i18n-mo: ## Compile every languages/*.po into the .mo WordPress actually reads.
	$(WP_CLI) i18n make-mo languages languages
	@echo "✔ $$(ls languages/*.mo | wc -l) .mo files built."

.PHONY: i18n-check
i18n-check: ## Fail if any shipped .po is malformed, untranslated or fuzzy.
	@# Fuzzy counts as incomplete. A fuzzy entry is msgmerge's guess carried
	@# over from a string that has since changed, and WordPress does not show
	@# it at all — so a locale full of them reads as English while the
	@# statistics line calls it translated. The same three words CI checks.
	@fail=0; \
	for po in languages/*.po; do \
	  out=$$(msgfmt --check --statistics -o /dev/null "$$po" 2>&1) || fail=1; \
	  printf "%-34s %s\n" "$$po" "$$out"; \
	  case "$$out" in *untranslated*|*fuzzy*) fail=1;; esac; \
	done; \
	if [ "$$fail" -ne 0 ]; then echo "✗ translations incomplete, fuzzy or malformed"; exit 1; fi; \
	echo "✔ every locale complete."

# -- Tests -------------------------------------------------------------
.PHONY: test
test: test-unit ## Run the unit-test suite (default — fast, no WP needed).

.PHONY: test-unit
test-unit: ## Run only the unit-test suite (no WordPress runtime).
	$(VENDOR) ./vendor/bin/phpunit --testsuite unit

# The oldest PHP the plugin supports (Requires PHP: 8.0). `make test-unit`
# runs on the composer image's PHP, which is the newest; CI runs every version
# in between.
.PHONY: test-unit-min
test-unit-min: ## Unit suite on the oldest PHP the plugin supports (8.0).
	$(DOCKER_RUN) php:8.0-cli ./vendor/bin/phpunit --testsuite unit

.PHONY: test-integration
test-integration: ## Integration suite on a network: the wp-env tests site (must be `make env` + `make env-multisite` first).
	npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(REPO_DIR) ./vendor/bin/phpunit -c phpunit-integration.xml --testsuite integration

# The same suite on a single site. The main environment's tests site is a
# network once `make env-multisite` has run, and the dev site's database is not
# a test database (the bootstrap refuses it), so a single site of its own is
# needed: a throwaway wp-env under build/integration-single, the way Plugin
# Check has one, on 8886/8887 (8888/8889, 8892–8901 belong to other stacks).
# It mounts this checkout under its own directory name, like the main
# environment, and its tests site is never converted.
INTEG_SINGLE_DIR := $(CURDIR)/build/integration-single

.PHONY: integration-single-env
integration-single-env:
	@mkdir -p "$(INTEG_SINGLE_DIR)"
	@printf '%s\n' \
	  '{' \
	  '  "core": null,' \
	  '  "phpVersion": "8.5",' \
	  '  "plugins": [ "../.." ],' \
	  '  "mappings": { "wp-content/mu-plugins/diluxone-e2e.php": "../../tests/e2e/mu-plugin/diluxone-e2e.php" },' \
	  '  "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "WP_DEBUG_DISPLAY": false },' \
	  '  "port": 8886,' \
	  '  "testsPort": 8887' \
	  '}' > "$(INTEG_SINGLE_DIR)/.wp-env.json"
	@cd "$(INTEG_SINGLE_DIR)" && npx @wordpress/env start >/dev/null
	@cd "$(INTEG_SINGLE_DIR)" && if npx @wordpress/env run tests-cli wp core is-installed --network >/dev/null 2>&1; then \
	  echo "✗ the single-site environment's tests site is a network. make test-integration-single-clean, then again."; exit 1; fi
	@cd "$(INTEG_SINGLE_DIR)" && npx @wordpress/env run tests-cli wp plugin activate $(REPO_DIR) >/dev/null

.PHONY: test-integration-single
test-integration-single: integration-single-env ## Integration suite on a single site, in a throwaway wp-env (8886/8887).
	@cd "$(INTEG_SINGLE_DIR)" && npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(REPO_DIR) ./vendor/bin/phpunit -c phpunit-integration.xml --testsuite integration

.PHONY: test-integration-single-down
test-integration-single-down: ## Stop the single-site integration environment.
	@cd "$(INTEG_SINGLE_DIR)" 2>/dev/null && npx @wordpress/env stop 2>/dev/null || true

.PHONY: test-integration-single-clean
test-integration-single-clean: ## Destroy the single-site integration environment and its volumes.
	@cd "$(INTEG_SINGLE_DIR)" 2>/dev/null && npx @wordpress/env destroy --force 2>/dev/null || true

.PHONY: test-integration-all
test-integration-all: ## The integration suite on both: the network (main env) and a single site.
	$(MAKE) env-multisite
	$(MAKE) test-integration
	$(MAKE) test-integration-single

# The end-to-end suite drives a real browser against the wp-env dev site, so
# it needs `make env` running and the Playwright browsers installed once
# (`npx playwright install chromium`). Everything else it needs — the mailbox,
# the fake social network, the seeded pages — comes from the mu-plugin mapped
# in .wp-env.json. See tests/e2e/README.md.
.PHONY: test-e2e
test-e2e: ## Run the Playwright end-to-end suite against the wp-env dev site (must be `make env` first).
	@mkdir -p build
	npx playwright test

.PHONY: test-e2e-ui
test-e2e-ui: ## The same suite in Playwright's own window, for writing and debugging one.
	@mkdir -p build
	npx playwright test --ui

# The same plugin, network-activated, on the wp-env TESTS site (8893) turned
# into a subdirectory network. Its own config and its own target: the default
# `npx playwright test` is what the shared CI runs against a single dev site,
# and it stays exactly that. The setup makes /alpha/ and /beta/ and the
# teardown deletes them. Override WP_NETWORK_URL to point it elsewhere.
.PHONY: test-e2e-network
test-e2e-network: env-multisite ## The network suite: two sites of one network and what must not leak between them.
	@mkdir -p build
	npx playwright test -c playwright.network.config.ts

# The layout invariants: the same browser, the same site, no baseline images.
# It is part of `make test-e2e` — this target is for running only that, which
# is what you want while moving a block around.
.PHONY: test-layout
test-layout: ## Only the layout measurements: overlap, overflow, air, blank boxes, the rail.
	@mkdir -p build
	npx playwright test admin-layout

# The pictures. Its own target and its own Playwright project because a
# baseline image belongs to the machine that took it: same fonts, same
# smoothing, same scrollbars. `make test-e2e` and CI do not compare them.
.PHONY: test-visual
test-visual: ## Compare every screen with the picture committed beside the specs.
	@mkdir -p build
	DU_SNAPSHOTS=1 npx playwright test --project=visual

# The thirteen pictures the wordpress.org listing shows. Not a comparison —
# it writes .wordpress-org/screenshot-1..13.png, and the captions under
# `== Screenshots ==` in readme.txt are what they answer to. Re-run it after
# anything that changes how a screen looks, and read the diff in git before
# committing: that diff is the shop window.
.PHONY: screenshots
screenshots: ## Retake the 13 listing screenshots (needs `make env` first).
	@mkdir -p build
	DU_LISTING=1 npx playwright test --project=listing
	@echo "✔ $$(ls .wordpress-org/screenshot-*.png 2>/dev/null | wc -l) pictures in .wordpress-org/"

# When the screen changed because you changed it. Look at what git shows you
# in tests/e2e/snapshots/ before committing: that diff IS the review of the
# redesign, and accepting it without looking is how a bug becomes the baseline.
.PHONY: test-visual-update
test-visual-update: ## Take the pictures again and accept them as the new baseline.
	@mkdir -p build
	DU_SNAPSHOTS=1 npx playwright test --project=visual --update-snapshots
	@echo "✔ Pictures rewritten. \`git diff --stat tests/e2e/snapshots\` is the change you are accepting."

# The network's pictures: Network Admin's screens and the places a site of a
# network looks different. The tests site has to be a network, so they are
# a pair of targets of their own rather than part of the two above.
.PHONY: test-visual-network
test-visual-network: env-multisite ## Compare the network's screens with their pictures.
	@mkdir -p build
	DU_SNAPSHOTS=1 npx playwright test -c playwright.network.config.ts --project=network-visual

.PHONY: test-visual-network-update
test-visual-network-update: env-multisite ## Take the network's pictures again and accept them.
	@mkdir -p build
	DU_SNAPSHOTS=1 npx playwright test -c playwright.network.config.ts --project=network-visual --update-snapshots
	@echo "✔ Pictures rewritten. \`git diff --stat tests/e2e/snapshots\` is the change you are accepting."

.PHONY: test-all
test-all: test-unit test-integration test-integration-single test-e2e ## Unit, integration on a network and on a single site, and single-site end-to-end.

# -- Distribution build ------------------------------------------------
# The repo directory is diluxone-users-wordpress (GitHub), but the plugin
# folder wordpress.org receives must be named after the slug, diluxone-users:
# WordPress derives the text-domain check from the folder name. `make dist`
# materialises exactly what ships, under the right name, applying .distignore
# so the tree has no tests, tooling or repo metadata in it.
REPO_DIR := $(notdir $(CURDIR))

DIST_DIR := build/diluxone-users

.PHONY: dist
dist: ## Build build/diluxone-users/ — exactly what gets published.
	@mkdir -p "$(DIST_DIR)"
	@# --delete, never `rm -rf` the directory itself: wp-env bind-mounts it, and
	@# replacing the inode leaves the container looking at a mount that is gone.
	@# --delete-excluded too: a file that became excluded must leave the dist,
	@# --delete alone keeps it there from an earlier build.
	@rsync -a --delete --delete-excluded --exclude-from=.distignore --exclude='build' ./ "$(DIST_DIR)/"
	@echo "✔ Built $(DIST_DIR) ($$(find "$(DIST_DIR)" -type f | wc -l) files)"

.PHONY: zip
zip: dist ## Package build/diluxone-users.zip — the file you upload to wordpress.org.
	@# The dist is copied from the working tree, so a file on disk that git does
	@# not know — a scratch script, a credentials file — would ship. The file
	@# that goes to wordpress.org carries only what the repository has.
	@untracked=$$(comm -13 <(git ls-files | sort) <(cd "$(DIST_DIR)" && find . -type f | sed 's|^\./||' | sort)); \
	if [ -n "$$untracked" ]; then echo "✗ the dist has files git does not track:"; echo "$$untracked"; exit 1; fi
	@cd build && rm -f diluxone-users.zip && zip -qr diluxone-users.zip diluxone-users
	@echo "✔ build/diluxone-users.zip ($$(du -h build/diluxone-users.zip | cut -f1))"

# -- Plugin Check (wordpress.org review gate) --------------------------
# This is the tool the plugin review team runs. PHPCS/WPCS overlaps with it
# but does not replace it: Plugin Check also enforces readme.txt structure,
# plugin headers, i18n and directory rules that WPCS knows nothing about.
#
# It runs in its own throwaway wp-env project under build/pcp, on ports
# 8894/8895 (8888-8891 belong to dev environments and 8896/8897 to the sibling
# DiluxOne plugin's own Plugin Check env),
# mounting only the built dist. Two reasons it cannot share the main
# environment: the plugin folder there is the repo name, and Plugin Check
# compares the text domain against the folder name; and wp-env activates every
# plugin it mounts, so mounting the repo and the dist together loads the plugin
# twice and fatals on redeclaration.
PCP_DIR := $(CURDIR)/build/pcp

.PHONY: pcp-env
pcp-env: dist
	@mkdir -p "$(PCP_DIR)"
	@printf '%s\n' \
	  '{' \
	  '  "core": null,' \
	  '  "phpVersion": "8.2",' \
	  '  "plugins": [ "../diluxone-users" ],' \
	  '  "port": 8894,' \
	  '  "testsPort": 8895' \
	  '}' > "$(PCP_DIR)/.wp-env.json"
	@cd "$(PCP_DIR)" && npx @wordpress/env start >/dev/null
	@cd "$(PCP_DIR)" && (npx @wordpress/env run cli wp plugin is-installed plugin-check >/dev/null 2>&1 \
	  || npx @wordpress/env run cli wp plugin install plugin-check --activate >/dev/null)

.PHONY: plugin-check
plugin-check: pcp-env ## Run wordpress.org's Plugin Check on the built dist.
	@cd "$(PCP_DIR)" && npx @wordpress/env run cli wp plugin check diluxone-users --format=table --severity=5

.PHONY: plugin-check-all
plugin-check-all: pcp-env ## Plugin Check on the built dist, including warnings and notices.
	@cd "$(PCP_DIR)" && npx @wordpress/env run cli wp plugin check diluxone-users --format=table

.PHONY: plugin-check-down
plugin-check-down: ## Stop the Plugin Check environment.
	@cd "$(PCP_DIR)" && npx @wordpress/env stop 2>/dev/null || true

# -- Aggregate ---------------------------------------------------------
.PHONY: check
check: lint stan psalm test ## Run every quality gate CI runs (lint, stan, psalm, unit tests).
	@echo "✔ All checks passed."

# -- Local dev environment (wp-env) ------------------------------------
.PHONY: env env-up
env: env-up ## Alias of env-up.
env-up: ## Start the local wp-env Docker stack.
	npx wp-env start

.PHONY: env-multisite
env-multisite: ## Convert the wp-env tests site (8893) into a multisite network (idempotent).
	@# The shared CI runs the integration suite on a network, the way a good
	@# part of real hosting runs WordPress. This is the same conversion, so a
	@# failure there can be reproduced here.
	@npx wp-env run tests-cli wp core is-installed --network >/dev/null 2>&1 \
	  || npx wp-env run tests-cli wp core multisite-convert --title="Tests network"
	@# The subdirectory rewrite rules WordPress asks for after the conversion:
	@# without them a site's /wp-admin/ is an Apache 404, and the network
	@# end-to-end suite drives the other sites of the network in a browser.
	@# Anything that flushes permalinks puts the single-site file back, so
	@# this runs again before every network run.
	@npx wp-env run tests-cli bash -c 'cp /var/www/html/wp-content/plugins/$(REPO_DIR)/tests/e2e/multisite.htaccess /var/www/html/.htaccess'
	@npx wp-env run tests-cli wp plugin activate $(REPO_DIR) --network

.PHONY: env-down
env-down: ## Stop the local wp-env Docker stack.
	npx wp-env stop

.PHONY: env-clean
env-clean: ## Destroy the local wp-env Docker stack and its volumes.
	npx wp-env destroy

# -- Deploy / release --------------------------------------------------
# The plugin is developed here and tried on a real site. `make deploy-test`
# copies the working tree into that site's plugins directory — only what
# ships, so no vendor/, no tests, no tooling — and leaves the site's own
# files alone. Override SITE= to try it somewhere else.
SITE ?= $(HOME)/repos/cst-website
SITE_PLUGIN := $(SITE)/wp-content/plugins/diluxone-users
SITE_LANGS  := $(SITE)/wp-content/languages/plugins

.PHONY: deploy-test
deploy-test: ## Copy the working tree into a real site for manual smoke-testing.
	@if [ ! -d "$(SITE)/wp-content/plugins" ]; then \
	  echo "no site at $(SITE). Override with SITE=/path/to/wordpress"; \
	  exit 1; \
	fi
	@mkdir -p "$(SITE_PLUGIN)"
	@# --delete-excluded as well: a file that became excluded — or that was
	@# there from an earlier layout — has to leave the site copy too, or the
	@# site ends up running something the repo no longer ships.
	rsync -a --delete --delete-excluded \
	  --exclude-from=.distignore \
	  --exclude='.git' \
	  ./ "$(SITE_PLUGIN)/"
	@# The bundled .mo files are inert on their own: with no
	@# load_plugin_textdomain() call — discouraged by Plugin Check since
	@# WordPress 4.6 — WordPress only reads plugin translations from
	@# wp-content/languages/plugins/, which is where wordpress.org installs
	@# its language packs. Until the plugin is published and those packs
	@# exist, this puts the same files in the same place by hand.
	@mkdir -p "$(SITE_LANGS)"
	@cp languages/*.mo "$(SITE_LANGS)/" 2>/dev/null || true
	@echo "✔ Copied to $(SITE_PLUGIN) (+ $$(ls languages/*.mo 2>/dev/null | wc -l) locales in $(SITE_LANGS))"

.PHONY: release
release: check ## Pre-release validation: full quality gate + version-alignment dry-run.
	@echo "── version alignment check ─────────────────────────────"
	@PHP_VERSION=$$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' diluxone-users.php | head -1 | sed -E 's/.*Version:[[:space:]]*//'); \
	 STABLE_TAG=$$(grep -E '^Stable tag:' readme.txt | sed -E 's/Stable tag:[[:space:]]*//'); \
	 PHP_BASE=$$(echo $$PHP_VERSION | sed -E 's/-(dev|alpha|beta|rc).*$$//'); \
	 echo "  PHP header Version : $$PHP_VERSION"; \
	 echo "  PHP base (no -dev) : $$PHP_BASE"; \
	 echo "  readme Stable tag  : $$STABLE_TAG"; \
	 if [ "$$PHP_BASE" = "$$STABLE_TAG" ]; then \
	   echo "  → match ✔"; \
	 else \
	   echo "  → MISMATCH ✗ (PHP base must equal readme Stable tag at tag time)"; exit 1; \
	 fi
	@echo "✔ Ready to tag."

# -- Cleanup -----------------------------------------------------------
.PHONY: clean
clean: plugin-check-down test-integration-single-down ## Remove caches, build artefacts, and temporary files.
	rm -rf build .phpunit.result.cache .phpunit.cache .phpcs-cache .phpstan .psalm
	@echo "✔ Cleaned."
