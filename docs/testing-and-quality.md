# Testing & quality

What every quality gate enforces, why, and how to run each one locally.

## Quality stack at a glance

Today the checks run from this repository's own workflows in [`.github/workflows/`](../.github/workflows/). After the move to the DiluxOne organisation they run from the shared workflows in [`DiluxOne/.github`](https://github.com/DiluxOne/.github) (conventions, the fast suite, the slow suite on wp-env and the Claude review), called from one `pull-request.yml`; the layers and the Make targets below stay the same.

| Layer | Tool | Catches | Make target |
| --- | --- | --- | --- |
| Conventions (after the move) | shared `conventions` workflow, lychee | Branch name, PR title and commit headers; the description's What changes and Why, and no "Generated with …" footer; broken relative doc links; retired product names. | (runs on PR) |
| Syntax | `php -l` on PHP 8.0 to 8.5 | Syntax the minimum PHP can't parse. | (runs on PR) |
| Unit tests | PHPUnit + brain/monkey + mockery, same PHP matrix | Logic regressions in pure-PHP units: TOTP, passkeys, QR, social identity, the two-step policy, the client's address. | `make test`, `make test-unit-min` |
| Coding style | PHP_CodeSniffer + WordPress Coding Standards + PHPCompatibilityWP | Style, naming, prefixes, escaping, sanitisation, prepared statements, syntax above PHP 8.0. | `make lint` |
| Static analysis | PHPStan level 8 + szepeviktor/phpstan-wordpress | Type safety, unreachable code, undefined functions, missing return types. **No baseline.** | `make stan` |
| Security taint analysis | Psalm + humanmade/psalm-plugin-wordpress (taint-only mode) | XSS, SQL injection, command injection, file-system traversal: user input flowing into dangerous sinks. | `make psalm` |
| i18n | `wp i18n make-pot` + `msgfmt` | Missing translator comments, dynamic text domains, conflicting translator hints, concatenated strings; and whether the eight shipped locales are complete. | `make i18n`, `make i18n-check` |
| Plugin Check (wp.org) | wordpress/plugin-check | The checks the wp.org plugin team runs at submission and review. | `make plugin-check` |
| Readme and versions | shell | Required readme headers; `Stable tag`, `Version:` and `DILUXONE_USERS_VERSION` in line. | `make release` |
| Integration tests | PHPUnit + wp-env, **on a multisite network and on a single site** | Behaviour against a real WordPress and database, including what must not leak between the sites of a network, and what a single site does on its own. | `make test-integration-all` (or `make env-multisite && make test-integration`, and `make test-integration-single`) |
| End-to-end tests | Playwright + wp-env, single site (dev site, 8892) | Whole flows in a real browser: sign-in, registration, 2FA, passkeys, social login, every settings screen and its effect on the public page. | `make test-e2e` |
| End-to-end tests on a network | Playwright + wp-env (tests site as a subdirectory network, 8893) | Two sites of one network: every door leading to the hub and back (password, e-mail link, social, passkey, second step, registration, wp-login.php, the menu), a site on a domain of its own, membership (the three policies, new accounts and sites, removals, "Join this site", the queue and its sync), the network's registration setting, settings and reports per site, the network's activity log and its move, the photo, two-step and social identities across sites, a new site, deactivation, WP-CLI. | `make test-e2e-network` |
| Layout invariants | Playwright (measurements) | Blocks overlapping, anything past the right edge, blocks touching, bordered boxes with nothing in them, something with `hidden` still on screen, the rail falling underneath. Part of `make test-e2e`. | `make test-layout` |
| Visual regression | Playwright (`toHaveScreenshot`) | Everything else about how a screen looks. | `make test-visual` (local only, see below) |
| Listing screenshots | Playwright (`listing` project) | Not a check: retakes the pictures wordpress.org shows. | `make screenshots` |
| Coverage | PCOV + phpcov (unit, integration on both topologies, merged); a script for the browser suites | Lines, functions and classes per file of `includes/` and `templates/` no test runs; a screen, tab, shortcode, action, address, command or template no spec walks. | `make coverage`, `make coverage-e2e-map` |
| JS supply chain | CodeQL (JS) | Common JS vulnerability patterns. | (runs when JS changes) |
| Claude review (after the move) | shared `claude-review` workflow | Everything in [`architecture.md`](architecture.md) and the organisation's WordPress review profile; rates risk and complexity. | (runs on PR) |

## The rule: tests at every layer a change touches

A change carries its tests **at every layer it touches**, in the same pull request:

- a **unit** test for logic that stands alone;
- an **integration** test for behaviour against WordPress and the database, run on a network **and** on a single site (`make test-integration-all`): CI runs both, a per-site setting can leak into another site only on a network, and a single site's own paths are only walked on a single site. A test that only makes sense on one topology skips itself loudly on the other, and has a counterpart there asserting what that topology does;
- an **end-to-end** test for what a person does in a browser, on a single site (`make test-e2e`) **and**, when it involves more than one site, users joining a site, or anything stored per site, on a network (`make test-e2e-network`);
- when a screen changes: the screen in [`tests/e2e/support/screens.ts`](../tests/e2e/support/screens.ts) if it is new, the visual baselines retaken on purpose (`make test-visual-update`, and read the diff), and the listing screenshots retaken (`make screenshots`) when that screen is one of them;
- a line in [`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md) for a new feature or state, naming the test that walks it.

A feature that only its integration test has seen is not done. A test that fails on a product bug stays red and says so; it is not loosened.

**Every job that runs on a pull request is a required status check on `main`**, except CodeQL, which runs only when JavaScript changes (path filter) and so cannot be required; its alerts land in the Security tab.

**What runs when (after the move).** The shared workflows skip the slow suites (integration, end-to-end) and Plugin Check on a pull request that changes no code, where "code" is the `code` list of the organisation's policy plus whatever [`.github/review-policy.yml`](../.github/review-policy.yml) adds; docs and translations then cost seconds. A push to `main` runs whatever its tree has not already passed, and a weekly run everything. Today every workflow runs on every pull request.

## Unit tests

Located in [`tests/Unit/DiluxOneUsers/`](../tests/Unit/DiluxOneUsers/). They run in pure PHP without WordPress: `brain/monkey` stubs `__()`, `apply_filters` and the rest, and each test `require`s the one file of `includes/` it exercises.

```bash
make test            # unit tests, on the newest PHP
make test-unit-min   # the same on PHP 8.0, the minimum; CI runs every version in between
```

When you add one:

- Name it after what it covers (`TotpTest.php` for `includes/auth-totp.php`) and keep it in `tests/Unit/DiluxOneUsers/`.
- Set brain/monkey up in `setUp()` and tear it down in `tearDown()`, as the existing tests do.
- Don't touch `$_GET`, `$_POST`, the database or the filesystem. Move that to integration tests.

## Integration tests

Located in [`tests/Integration/`](../tests/Integration/). They run inside a `wp-env` tests container, against a real WordPress and MySQL, **twice**: on a multisite network and on a single site. Every case is tested on both.

```bash
make env                        # boot wp-env
make env-multisite              # the tests site as a network, the plugin network-activated
make test-integration           # the suite on the network
make test-integration-single    # the suite on a single site (throwaway wp-env, 8886/8887)
make test-integration-all       # both, one after the other
make test-integration-single-down   # stop the single-site environment when done
```

**Why a second environment.** Once `make env-multisite` has run, the main environment's tests site is a network, and the dev site's database is not a test database (the bootstrap refuses any database whose name lacks `test`, and that safety stays). So `make test-integration-single` brings up a throwaway `wp-env` of its own under `build/integration-single/`, the way Plugin Check has one: it mounts this checkout under its directory name, like the main environment, with the same mu-plugin and debug settings, on ports 8886/8887, and never converts its tests site. It refuses to run if that tests site somehow became a network. `make test-integration-single-clean` destroys it.

**Which tests run where.** A test that only makes sense on one topology skips itself, loudly, on the other: `MultisiteTest`, `NetworkSettingsTest`, `NetworkLogTest`, `UninstallNetworkTest` and the network cases of `OptionScopeTest`, `SettingsFileTest`, `SsoToggleTest` and `AccountClosingTest` on a single site; `SingleSiteTest`, `UninstallSiteTest` and the single-site cases of `SettingsFileTest` and `SsoToggleTest` on a network. `NetworkHubTest` holds both halves of every case of the hub's doors in one class, `MembershipTest` of every case of the membership policy (its confirmation included), and `SafeModeTest` of the emergency switch (a network's sites keep their own wp-login.php; a single site's is its own); the network case of `SuggestedFieldsTest` (a site administrator adds nothing) skips on a single site: each network case skips on a single site and each single-site counterpart on a network. Each has a counterpart on the other topology that asserts what that one does, and [`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md) names both. Everything else runs and passes on both.

**Every test leaves the database as it found it.** The tests database outlives each run, so `IntegrationTestCase` notes the highest user, post (per site) and site IDs before every test and, after it, deletes every user (with its meta), every post of each site (privacy requests, pages, menu items) and every site above them, including the accounts the code under test made on its own; the administrator and anything seeded stay. `CleanupTest` fails if a test leaks any of them.

**A constant.** `NoCacheTest` asserts that `DONOTCACHEPAGE` gets defined, and a constant cannot be taken back: those two cases run in a process of their own (`@runInSeparateProcess`). The emergency switch is a constant too, which is why its tests turn it on through the `diluxone_users_safe_mode` filter it feeds. Shortcodes registered by the plugin are not in the registry `has_shortcode()` reads inside the suite (the bootstrap loads WordPress inside a function), so a test that needs them there registers them by name and takes them out after.

**What the base class gives a test.** `IntegrationTestCase` catches the ways a handler ends: `expectRedirect()` for a redirect, and `expectDie( $handler, $message, $status )` for a `wp_die()`, which checks what it said and the status it answered — a refusal is both, and the bootstrap's handler keeps the status and code `wp_die()` was given instead of only its words (`self::EXPIRED` is what a failed nonce says). `hook()` adds a filter or an action that is taken off in `tearDown()`, so an assertion that fails halfway leaves nothing hooked; `$_SERVER` is put back after every test, and so is the copy of the query social sign-in takes. The suite loads WordPress as the dashboard (`WP_ADMIN`, `DOING_AJAX`), so `is_admin()` is true everywhere: `as_front_end()` makes the rest of the test the front of the site. The copy of a person's data needs `ZipArchive`; `needs_zip()` fails, not skips, without it, because a skip there is silent in CI. In the unit suite, every test uses `Tests\Unit\ResetsWpStubs`: the stubs' options, transients, users and meta start empty and the request is put back, because the suite runs in random order.

**Uninstall.** `uninstall.php` declares its functions when it is loaded, so it can be loaded once per process: `UninstallSiteTest` and `UninstallNetworkTest` run each test in a process of its own (`@runTestsInSeparateProcesses`, `@preserveGlobalState disabled`) and put back what they took. Between them they cover the box ticked and unticked on a single site, and on a network — including one whose settings never moved, where the network's box still decides and a site's own old box never counts.

**The `Cover*Test` classes** were written against the coverage report (`make coverage`), one family per area: `CoverAdmin*` (the dashboard's own screens, tools and reports), `CoverSettings*` (the settings panels, design, social and e-mail templates), `CoverSignIn*` (passkeys, social sign-in, the e-mail link, the second step, reset, registration, WP-CLI), `CoverAccount*` (sessions, privacy export and erasure, account security and closing, pages, the menu, the hub and membership, the log) and `CoverFields*` (each field type saved and drawn, the public name, the photo, the account area, the front end's CSS). Each proves the refusals of what it covers — nonce, capability, owner, scope on a network — before the happy path, and follows the same rule as everything else: a case only one topology has skips on the other and names its counterpart. Three doubles live in `tests/Integration/Support/`: `CoverSignInWpLogin.php` stands in for wp-login.php's `login_header()`/`login_footer()`, `CoverSignInCli.php` is a minimal `WP_CLI`, and `CoverSignInStop.php` is what both throw where the code would `exit`; the tests that use them run in a process of their own.

**What the integration suite cannot run**, and is covered in the browser instead: an `exit;` after a redirect or `wp_die()` (the harness throws on both, so the line after never runs); a real upload (`is_uploaded_file()` is false from the command line — the photo and the settings file are uploaded in `account-area` and `admin-tools`); a response that streams a file and exits (the settings export, a copy of a person's data).

Use them for what depends on WordPress core: hooks, options, user meta, the activity log's table, AJAX handlers, REST routes, the sign-in and registration requests.

**CI.** [`.github/workflows/tests-integration.yml`](../.github/workflows/tests-integration.yml) runs the suite as a matrix, `topology: [single, network]`; only the network job converts the tests site. After the move to the DiluxOne organisation the suite runs from the shared `plugin-tests-wp.yml` workflow, which today runs it on a network only: the single-site run has to be carried there (a topology input or matrix of its own) before this repository's workflow is retired, or half of every case stops being tested.

## PHPCS / WordPress Coding Standards

Configuration: [`phpcs.xml.dist`](../phpcs.xml.dist).

```bash
make lint           # report violations
make lint-fix       # auto-fix what can be auto-fixed (PHPCBF)
```

The full WordPress ruleset (Core, Extra, Docs), the `diluxone_users` / `DILUXONE_USERS` prefixes and PHP 8.0 compatibility. One relaxation, explained in the file: typed signatures replace `@param` lines. `make lint` runs with `--no-cache` on purpose: a cached result for a file that went back to earlier content is green locally while a clean CI run is red.

When PHPCS reports a violation, the rule code is in the right column. Look it up before suppressing: most warnings are real bugs (missing escaping, missing nonce, missing prepare).

## PHPStan

Configuration: [`phpstan.neon`](../phpstan.neon). Bootstrap stubs: [`phpstan-bootstrap.php`](../phpstan-bootstrap.php).

```bash
make stan
```

**Level 8 with no baseline.** Every type error is fixed in code, not suppressed. `szepeviktor/phpstan-wordpress` teaches PHPStan the WordPress API, so `wp_remote_get()` returns `array|WP_Error` and `$wpdb->update()` returns `int|false`.

`WP_DEBUG` and `COOKIEHASH` are declared `dynamicConstantNames`, so PHPStan does not collapse `if ( WP_DEBUG )` into "always false" on the stub's value; their real values come from `wp-config.php`.

If PHPStan cannot see a real type (a missing extension stub), use `// @phpstan-ignore-next-line <identifier>` with a comment saying why. Never a baseline.

## Psalm taint analysis

Configuration: [`psalm.xml`](../psalm.xml).

```bash
make psalm
```

Psalm runs in **taint-analysis mode only**. `humanmade/psalm-plugin-wordpress` teaches it that `esc_html()`, `esc_attr()`, `esc_url()`, `$wpdb->prepare()` and `sanitize_*()` are barriers, so a value from `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_FILES` / `$_SERVER` is a finding only if it reaches a sink (`echo`, `$wpdb->query()`, `header`, `file_put_contents`, …) without passing one. Type-checking is PHPStan's job and is suppressed here.

If Psalm flags a path you believe is safe, the fix is almost always the right WordPress escaper. Suppressing is a last resort, justified inline.

## i18n

```bash
make i18n          # refresh languages/diluxone-users.pot
make i18n-update   # merge it into every .po
make i18n-mo       # compile the .mo files
make i18n-check    # fail on an incomplete, fuzzy or malformed locale
```

CI extracts the strings and fails on any `Warning:` or `Error:` line (WP-CLI prints them but exits 0), then checks that the eight shipped locales are complete. The classes of bug it catches:

- **Missing translator comments** on placeholders. The `/* translators: */` comment must be **on the line immediately preceding** the translation call; a blank line in between makes it invisible to gettext.
- **Conflicting translator comments** on the same msgid, which gettext merges.
- **Concatenated strings**, **dynamic text domains** and other untranslatable patterns.
- **A locale that reads as English**: an untranslated or fuzzy entry. WordPress does not show fuzzy entries at all.

A pull request that adds or changes a string updates the `.pot`, the eight `.po` and their `.mo` in the same pull request.

## Plugin Check

`make plugin-check` builds the dist (`make dist`, under the slug, with `.distignore` applied) and runs the [official WordPress Plugin Check](https://github.com/WordPress/plugin-check) on it in a throwaway `wp-env` (ports 8894/8895); `make plugin-check-all` includes warnings. It checks what ships, so repository files never reach it. It is the tool the wordpress.org review team runs: it must pass before the zip is uploaded, and CI runs it on every pull request so a reviewer never has to find what it finds.

## End-to-end tests

Located in [`tests/e2e/`](../tests/e2e/). They drive a real Chromium against the wp-env dev site on port 8892, which mounts the working tree — so what is tested is what is checked out. See [`tests/e2e/README.md`](../tests/e2e/README.md) for what has to be running.

```bash
make env                # once
make test-e2e           # every single-site spec, including the layout measurements
make test-e2e-network   # the network suite
```

The same plugin network-activated has a suite of its own, in [`tests/e2e/network/`](../tests/e2e/network/) with [`playwright.network.config.ts`](../playwright.network.config.ts). It drives the wp-env tests site (port 8893) converted into a subdirectory network, makes `/alpha/` and `/beta/` with WP-CLI and deletes them afterwards, and writes the baseline settings through the main site — the hub — putting back what was there when it ends. `make test-e2e-network` does the conversion and the run, including the layout measurements of every Network Admin tab (`NETWORK_SCREENS` in [`tests/e2e/support/screens.ts`](../tests/e2e/support/screens.ts)). [`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md) maps every feature to the test that covers it, in both suites.

## Layout invariants

Located in [`tests/e2e/specs/admin-layout.spec.ts`](../tests/e2e/specs/admin-layout.spec.ts), with the measuring in [`tests/e2e/support/layout.ts`](../tests/e2e/support/layout.ts).

Why this layer exists at all: the unit, integration and end-to-end suites answer *does the code behave*, and they answer it well. Every visual bug this plugin has shipped got past all three of them green — a block drawn on top of the card above it, half a screen of nothing beside a column of settings, a bordered box with nothing inside it, a rail that fell underneath the form it belongs beside. None of those is a wrong value or a missing hook. They are geometry, and only a browser can see geometry.

So this measures it, on **every tab of every screen**, at **four widths** — 1600, 1280, and WordPress's own two breakpoints, 960 (the menu folds to icons) and 782 (the phone layout, where the second column has to give up and go underneath). The public pages — the sign-in page stacked and in tabs, the registration form, the account area to a stranger and every section of it on both its menus, and on a network the door to the hub — are measured at those four and at the three widths the front end's own stylesheet changes at, 640, 560 and 480, and at a phone's 390 (`FRONT_WIDTHS`). Six rules, none of which is an opinion about the design:

| Rule | What it means |
| --- | --- |
| `overlap` | No two sibling blocks share pixels. This is the one that matters: a block drawn on top of another is two rectangles intersecting. |
| `overflow` | Nothing reaches past the right-hand edge of the plugin's own block, and the page never scrolls sideways. |
| `air` | Two blocks of a screen never touch. Asked only between the blocks of a screen — options inside a group touch on purpose. |
| `blank` | No box with a border or a ground and nothing inside it. The stylesheet already hides the ones that are `:empty`; this catches the ones whose contents came out blank. |
| `rail` | Where a screen declares a second column, it is beside the settings above 960px and underneath below it. Inside it, the box that saves goes first and the state of the site right after it. |
| `hidden` | Nothing carrying the `hidden` attribute still has a box. The browser's own rule for it has the weight of a bare tag, so any component that gives itself a `display` outranks it and what a script hid stays on the screen. |

They need no baseline image, they mean the same thing on every machine, they say which element is wrong — so they run with everything else, in `make test-e2e` and in CI.

**Adding a screen or a tab.** The list lives in [`tests/e2e/support/screens.ts`](../tests/e2e/support/screens.ts) and three suites walk it: the behaviour spec, the layout spec and the picture spec. Add the slug to `SCREENS` and all three cover it. You will not forget: `admin-layout.spec.ts` reads the tab strip each screen draws and fails on a tab that is not in the registry. A screen whose content moves on its own — a report — gets an entry in `PINNED` saying which address makes it reproducible.

**Proving the measuring still works.** `The measuring itself can fail` breaks a real screen six ways, one per rule, and requires each break to be seen. A suite that has never been seen to fail is a suite nobody has a reason to believe.

## Visual regression (the pictures)

Located in [`tests/e2e/specs/admin-snapshots.spec.ts`](../tests/e2e/specs/admin-snapshots.spec.ts) (every tab, Your brand on each answer, Design on each shape of the sign-in page and the account), [`tests/e2e/specs/front-snapshots.spec.ts`](../tests/e2e/specs/front-snapshots.spec.ts) (the public pages: the sign-in page on every shape, stacked and in tabs, with one way in, with its words, the link on its way, a link that ran out; the second step; a new password; the registration form open and closed; the account to a stranger, on both shapes and both menus, and each section) and [`tests/e2e/specs/admin-mobile-snapshots.spec.ts`](../tests/e2e/specs/admin-mobile-snapshots.spec.ts); the baselines are in [`tests/e2e/snapshots/`](../tests/e2e/snapshots/).

```bash
make test-visual          # compare every screen with the picture committed
make test-visual-update   # take the pictures again and accept them
```

The measurements know the rules a layout must not break. They do not know what a screen is *supposed to look like*, and they never will: a blue line nobody asked for keeps every rule and is still wrong. The only thing that catches that is the picture, and the only thing that makes a picture an assertion is having last week's to compare it with. One per tab, plus the sign-in page a stranger sees.

Three decisions keep it from crying wolf:

- **What is photographed is the plugin's own block** (`.wrap.diluxone-users-admin`), not the window. The admin bar counts how long the page took to build, the menu carries update badges, the footer prints the WordPress version — none of that is this plugin's and all of it changes on its own.
- **What moves by itself inside that block is masked** — the dates and session counts in the reports, the environment table, an avatar. A mask keeps the element's box and fills it, so a block that changes *size* is still a difference. Only the content is forgiven, never the geometry.
- **The window, the pixel ratio, the motion and the caret are pinned** in the `visual` project in [`playwright.config.ts`](../playwright.config.ts): 1280×900, device scale 1, `reducedMotion`, `animations: 'disabled'`, `caret: 'hide'`, and a 0.2% tolerance for antialiasing.
- **What the screens are drawn from is pinned too**, by [`tests/e2e/support/visual-state.ts`](../tests/e2e/support/visual-state.ts) before every picture: every setting the pictures can show (fields, sections, colours, design, providers, rules), written through the `options` fixture so the site gets its own back, and the ten example people the overview and the reports count, made if missing, with their sessions counted from the moment of the picture. The development site's own configuration never reaches a baseline.

**On a phone.** The `visual-mobile` project runs the public pages and the dashboard's screens whose stylesheet changes below 782px at 390×844, device scale 1; its pictures carry `-mobile` in their name. `make test-visual` runs both projects.

**Updating a picture when the change IS what you wanted.** `make test-visual-update` — Playwright's `--update-snapshots` — rewrites the baselines. Then look at `git diff --stat tests/e2e/snapshots` **before committing**: that diff is the review of the redesign, and accepting it without looking is how a bug becomes the baseline.

**The network's pictures.** Network Admin's screens, and the three places a site of a network looks different (its Overview, the hub's Ways in, another site's Tools), are photographed by [`tests/e2e/network/network-snapshots.spec.ts`](../tests/e2e/network/network-snapshots.spec.ts) against the network, and the public pages only a network has — a site's three doors to the hub, the hub's sign-in and second step reached from another site, and "Join this site" offered, by invitation and welcomed — by [`tests/e2e/network/network-front-snapshots.spec.ts`](../tests/e2e/network/network-front-snapshots.spec.ts): `make test-visual-network` and `make test-visual-network-update`, same rules, pictures named `network-…`.

**Why it is not in CI.** A baseline image is a picture of one machine's font rendering, its sub-pixel smoothing and its scrollbars. Committing those and asking a runner to match them is a job that is red for reasons nobody can act on, and a gate nobody can act on is a gate that gets switched off. So the `visual` project only exists when `DU_SNAPSHOTS=1` is set, which `make test-visual` does, and the baselines carry the platform in their filename. The layout measurements — which are portable — carry the load in CI.

## Listing screenshots

```bash
make screenshots
```

The pictures the wordpress.org listing shows, written into [`.wordpress-org/`](../.wordpress-org/) by [`tests/e2e/specs/listing-screenshots.spec.ts`](../tests/e2e/specs/listing-screenshots.spec.ts), in a Playwright project of its own that exists only when `DU_LISTING=1` is set: nothing that writes the shop window should run as a side effect of `make test-e2e`. The captions under `== Screenshots ==` in `readme.txt` are what they answer to. Retake them in the pull request that changes one of those screens, and read the diff before committing.

## Coverage

How much of `includes/` and `templates/` the suites run, and which doors the browser walks. Nothing here is a gate in CI yet; it is how a gap is found before a reviewer finds it.

```bash
make coverage-unit          # the unit suite: build/coverage/unit/
make coverage-integration   # the integration suite on the network and on a single site, merged: build/coverage/integration/
make coverage               # both, merged into one: build/coverage/all/
make coverage-e2e-map       # every screen, tab, shortcode, action, address, command and template mapped to a spec
```

Each report directory has `report.txt` (lines, functions run and classes per file of `includes/` and `templates/`, the totals, the twenty files covered least and every function no test ran — the same text is printed at the end of the run), `clover.xml`, `html/` and `coverage.cov` (php-code-coverage's own format). The parts each run writes are in `build/coverage/parts/`.

**How it is measured.** PCOV. The unit suite runs in a PHP 8.5 image with PCOV built in (`diluxone-users-pcov:php8.5`, built on first use). The integration suite runs where it always does, in each wp-env's `tests-cli`, where [`tests/coverage/pcov.sh`](../tests/coverage/pcov.sh) installs PCOV switched off (`pcov.enabled=0`), so `make test-integration` is as fast as before; the coverage targets switch it on for their own run. Every run sees the checkout at the same path, `/var/www/html/wp-content/plugins/diluxone-users-wordpress/`, so `phpcov merge` can add the parts up.

**What runs while the plugin loads counts.** PHPUnit measures from the first test on, and every `add_action()` at the top of a file runs before that, once, when WordPress loads the plugin. So [`tests/bootstrap-integration.php`](../tests/bootstrap-integration.php) measures the load itself when `DU_COVERAGE_BOOTSTRAP` names a file, and the targets merge it in as one more part (`network-load.cov`, `single-load.cov`).

**A file no test loads** is in no part; [`tests/coverage/report.php`](../tests/coverage/report.php) adds it to `report.txt` at 0%, with its functions listed as never run. The HTML and Clover reports leave it out, so read `report.txt` for the totals. The runs leave such files out on purpose (`includeUncoveredFiles="false"`): php-code-coverage would otherwise add its parser's idea of a file's lines, which is not always PCOV's, and a line only the parser counts can never be run.

**`make coverage-e2e-map`** ([`tests/coverage/e2e-map.mjs`](../tests/coverage/e2e-map.mjs)) answers the question a coverage driver cannot for the browser suites, which drive a server in another container: is every door walked? It reads `includes/` for every panel (`diluxone_users_register_panel()`), shortcode, `admin_post_` and `wp_ajax_` action, `login_form_` action, rewrite rule, REST route and WP-CLI command, and lists `templates/`. A tab must be in [`tests/e2e/support/screens.ts`](../tests/e2e/support/screens.ts), the list the behaviour, layout and picture suites walk; everything else must have a row in the "Every door, by name" table of [`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md) naming a spec that exists (`specs/<name>` or `network/<name>`). A row or a tab for something the plugin no longer registers fails too. A door added without its row is a red run; adding the row is saying, in the same pull request, which spec walks it.

## Running everything at once

```bash
make check       # the fast gates: lint + stan + psalm + unit tests
make release     # make check + version-alignment dry-run
make test-all    # unit, integration (network and single site), single-site end-to-end
```

`make check` is the pre-push habit; it does not replace CI. Integration, the two end-to-end suites, i18n and Plugin Check have their own targets, listed above.
