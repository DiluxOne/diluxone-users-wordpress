# Development

How to run the plugin from source, what tools you need, and the day-to-day commands you'll use.

For contribution rules (branch naming, commit conventions, PR workflow), see [`CONTRIBUTING.md`](../CONTRIBUTING.md). For the test-and-quality stack, see [`testing-and-quality.md`](testing-and-quality.md). For versions, the first submission and releases, see [`release.md`](release.md).

## What you need

| Tool | Why |
| --- | --- |
| **Docker** (Docker Desktop on macOS/Windows or Docker Engine on Linux) | Runs `wp-env` (the local WordPress stack) and the PHP toolchain (PHPCS, PHPStan, Psalm, PHPUnit, WP-CLI) without installing matching PHP extensions on the host. |
| **Node.js 18+** and **npm** | `wp-env` and Playwright (`npm install`, then `npx playwright install chromium` once for the end-to-end suites). |
| **`make`** | Wraps every common task behind a short target. `make help` lists them. |
| **`msgfmt`** (gettext) | Only for `make i18n-check`. |
| **`gh`** (GitHub CLI) | Optional, for issues, pull requests and CI logs. |

You do **not** need PHP installed on the host. Every PHP-based command runs inside an official Docker image, mounted as your host UID so `vendor/` doesn't end up root-owned. With a full PHP CLI locally (`dom`, `mbstring`, `xml`, `xmlwriter`, `libxml`, `openssl`, `json`, `fileinfo`, `tokenizer`), `make DOCKER=0 …` skips the images.

## First run

```bash
git clone https://github.com/DiluxOne/diluxone-users-wordpress.git
cd diluxone-users-wordpress
make install     # composer install: dev tooling into vendor/
npm install      # wp-env and Playwright
make env         # wp-env: the dev site at http://localhost:8892, the tests site at 8893
```

When `make env` finishes, open <http://localhost:8892> and log in with `admin` / `password`. The checkout is mounted as `wp-content/plugins/diluxone-users-wordpress/`; activate it from **Plugins**. (`make dist` builds the folder wordpress.org receives, named after the slug.) The end-to-end mu-plugin in [`tests/e2e/mu-plugin/`](../tests/e2e/mu-plugin/) is mapped in too; it does nothing unless the environment type is `local` and a request carries its header ([`tests/e2e/README.md`](../tests/e2e/README.md#the-mu-plugin)).

## Day-to-day commands

| Command | What it does |
| --- | --- |
| `make help` | Every target with a one-line description (the default). |
| `make install` / `make update` | `composer install` / `composer update`: the dev tooling in `vendor/`. |
| `make env` / `make env-up` | Start `wp-env`: the dev site (8892) and the tests site (8893). |
| `make env-multisite` | Turn the tests site (8893) into a subdirectory network and network-activate the plugin. Idempotent; run it after every `make env`, since the integration suite and the network end-to-end suite need it. |
| `make env-down` | Stop `wp-env` (keeps the database). |
| `make env-clean` | Destroy `wp-env` and its volumes, when the dev install is in a bad state. |
| `make lint` / `make lint-fix` | PHPCS with the WordPress Coding Standards / PHPCBF for what it can repair. |
| `make stan` | PHPStan level 8, no baseline. |
| `make psalm` | Psalm taint analysis (XSS / SQLi / RCE). |
| `make test` / `make test-unit` | The unit suite, on the composer image's PHP (the newest). |
| `make test-unit-min` | The unit suite on PHP 8.0, the oldest the plugin supports. |
| `make check` | The fast gates: lint + stan + psalm + unit tests. |
| `make test-integration` | The integration suite, in the tests site's container. Run `make env-multisite` first: it runs on a network, as the shared CI does. |
| `make test-e2e` / `make test-e2e-ui` | Playwright on the dev site (8892), single site, including the layout measurements; `-ui` opens Playwright's own window. |
| `make test-e2e-network` | `make env-multisite`, then Playwright on the tests site as a network, with `/alpha/` and `/beta/`. |
| `make test-layout` | Only the layout measurements. |
| `make test-visual` / `make test-visual-update` | Compare every screen with its committed picture / retake and accept them. Local only ([`testing-and-quality.md`](testing-and-quality.md#visual-regression-the-pictures)). |
| `make screenshots` | Retake the listing screenshots in `.wordpress-org/`. |
| `make test-all` | Unit, integration and single-site end-to-end in one go. |
| `make i18n` | `wp i18n make-pot`: refresh `languages/diluxone-users.pot`. |
| `make i18n-update` | Merge the refreshed `.pot` into every `.po`, keeping the translations. |
| `make i18n-mo` | Compile every `languages/*.po` into its `.mo`. |
| `make i18n-check` | Fail if any `.po` is malformed, has untranslated strings or fuzzy ones. |
| `make dist` | Build `build/diluxone-users/`: the tree wordpress.org receives, under the slug, with `.distignore` applied. |
| `make zip` | Package `build/diluxone-users.zip`, the file uploaded to wordpress.org for the first review. |
| `make plugin-check` / `make plugin-check-all` | wordpress.org's Plugin Check on the built dist, in a throwaway `wp-env` on ports 8894/8895 (errors only / with warnings). `make plugin-check-down` stops it. |
| `make deploy-test` | Copy what ships (`.distignore` decides) into a real site's `wp-content/plugins/diluxone-users/`, plus the `.mo` files into `wp-content/languages/plugins/`. The site defaults to `~/repos/cst-website`; override with `SITE=/path/to/wordpress`. |
| `make release` | `make check` plus the version-alignment dry-run. |
| `make clean` | Wipe caches and build artefacts. |

## Configuration

The `wp-env` setup is in [`.wp-env.json`](../.wp-env.json):

- **WordPress core**: latest stable.
- **PHP**: 8.2.
- **Ports**: the dev site on 8892 and the tests site on 8893, so this stack can run beside another plugin's on the default 8888/8889. Plugin Check uses 8894/8895.
- **Plugin**: this repository, plus the end-to-end mu-plugin mapped into `wp-content/mu-plugins/`.
- **Debug mode**: `WP_DEBUG`, `WP_DEBUG_LOG` and `SCRIPT_DEBUG` on, `WP_DEBUG_DISPLAY` off, so errors go to `wp-content/debug.log` instead of the page.

To override any of these locally without committing, create `.wp-env.override.json` (git-ignored). See the [`@wordpress/env` docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

## The repository name is not the plugin slug

The repository is **`diluxone-users-wordpress`**. The plugin slug and text
domain are **`diluxone-users`**. Several tools guess one from the other, and
they guess differently, so every place that needs to know is told explicitly.
Before changing any of these, check which of the two names the tool actually
wants.

Where the **slug** has to be stated, because the tool would otherwise derive it
from the repository name:

| Where | What |
|---|---|
| `Makefile` (`dist`, `zip`, `plugin-check`) | Builds `build/diluxone-users/`: WordPress and Plugin Check compare the text domain with the folder name. |
| `Makefile` (`i18n`) | `--slug=diluxone-users --domain=diluxone-users`. |
| `Makefile` (`deploy-test`) | Copies into `wp-content/plugins/diluxone-users/`, and the `.mo` files into `wp-content/languages/plugins/`. |
| `.github/workflows/deploy.yml` | `SLUG: diluxone-users`, or the deploy action targets an SVN path that does not exist. After the move, the shared release workflow's `slug:` input. |
| `.github/workflows/pr-checks.yml` (Plugin Check); after the move, the shared pull-request caller | `slug: diluxone-users` for the plugin checks, which build the shipped tree under that name before Plugin Check and i18n read it. |

Where the **repository name** is correct and must be left alone, because
`wp-env` mounts the plugin under the checkout's directory name:

| Where | What |
|---|---|
| `Makefile` (`test-integration`, `env-multisite`) | `REPO_DIR`, the checkout's name, in the phpunit path and in `wp plugin activate`. |
| `.github/workflows/tests-integration.yml` | `wp plugin activate diluxone-users-wordpress --network` and the phpunit path. The shared `plugin-tests-wp` workflow does the same after the move. |

A clone into a folder with another name works for everything except those
paths; keep the repository name.

### Why the bundled `.mo` files need copying

The plugin does not call `load_plugin_textdomain()` (Plugin Check has
discouraged it since WordPress 4.6), and without that call WordPress reads
plugin translations only from `wp-content/languages/plugins/`. So
`languages/*.mo` inside the plugin folder is inert: the site renders in
English with eight complete locales on disk. `make deploy-test` puts them
where wordpress.org will install the language packs once the plugin is
published. The `.po` and `.mo` files do not ship (`.distignore`); they are
the source for translate.wordpress.org.

## Manual install (alternative)

To use your own WordPress instead of `wp-env`, put the checkout in `wp-content/plugins/diluxone-users/` of that install. The plugin has no build step; it runs straight from source. `make lint`, `make stan`, `make psalm` and `make test` work the same, because they do not need a running WordPress. For a copy of only what ships, use `make deploy-test SITE=/path/to/wordpress`.

## Docker image overrides

| Variable | Default | Where it's used |
| --- | --- | --- |
| `COMPOSER_IMAGE` | `composer:2` | `make install`, `make lint`, `make stan`, `make test*` |
| `WP_CLI_IMAGE` | `wordpress:cli` | `make i18n*` |
| `PHP_IMAGE` | `php:8.3-cli` | `make psalm` (Psalm needs PHP ≤ 8.3) |
| `DOCKER_NET` | `--network host` | `make i18n*` |

Pin any of them for byte-for-byte reproducibility, e.g. `make stan COMPOSER_IMAGE=composer:2.7.7`. The defaults float because pinning a digest in the repository would force a Makefile change every time the upstream image moves.

`--network host` is **not** supported on Docker Desktop for macOS or Windows. There, set `DOCKER_NET=` (empty).
