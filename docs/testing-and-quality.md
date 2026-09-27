# Testing & quality

What every quality gate enforces, why, and how to run each one locally.

## Quality stack at a glance

| Layer | Tool | Catches | CI workflow | Make target |
| --- | --- | --- | --- | --- |
| Unit tests | PHPUnit + brain/monkey + mockery | Logic regressions in pure-PHP units. | `pr-checks.yml` | `make test` |
| Integration tests | PHPUnit + wp-env | Behaviour against a real WordPress runtime + DB. | `pr-checks.yml` | `make test-integration` |
| End-to-end tests | Playwright + wp-env | Whole flows in a real browser: sign-in, registration, 2FA, passkeys, saving a settings screen. | `tests-e2e.yml` | `make test-e2e` |
| End-to-end tests on a network | Playwright + wp-env (tests site as a multisite) | Two sites of one network: membership, the network's registration setting, settings per site, reports per site, the photo, two-step and social identities across sites, a new site, deactivation. | `tests-e2e-network.yml` | `make test-e2e-network` |
| Layout invariants | Playwright (measurements) | Geometry: blocks overlapping, anything past the right edge, blocks with no space between them, bordered boxes with nothing in them, something still on screen with `hidden` on it, the rail falling underneath. | `tests-e2e.yml` | `make test-layout` |
| Visual regression | Playwright (`toHaveScreenshot`) | Everything else about how a screen looks — a line nobody asked for, a heading that grew, a ground that went grey. | (local, on purpose — see below) | `make test-visual` |
| Coding style | PHP_CodeSniffer + WordPress Coding Standards | Style, naming, escaping, sanitisation, prepared statements, deprecated APIs. | `pr-checks.yml` | `make lint` |
| Static analysis | PHPStan level 8 + szepeviktor/phpstan-wordpress | Type safety, unreachable code, undefined methods/properties, missing return types. **No baseline.** | `tests-stan.yml` | `make stan` |
| Security taint analysis | Psalm + humanmade/psalm-plugin-wordpress (taint-only mode) | XSS, SQL injection, command injection, file-system traversal — user input flowing into dangerous sinks. | `psalm-taint.yml` | `make psalm` |
| i18n | `wp i18n make-pot` + `msgfmt` | Missing translator comments on placeholders, dynamic text domains, conflicting translator hints, concat'd translatable strings — and whether the eight shipped locales are complete. | `i18n-validate.yml` | `make i18n`, `make i18n-check` |
| Plugin Check (wp.org) | wordpress/plugin-check | The same checks the wp.org plugin team runs at submission/review time. | `pr-checks.yml` | `make plugin-check` |
| Security supply chain | CodeQL (JS) | Common JS vulnerability patterns. | `codeql.yml` | (no Make target — runs on PR) |

Every layer must pass before a PR can land on `main` (branch protection enforces it).

## Unit tests

Located in [`tests/Unit/`](../tests/Unit/). They run in pure PHP without WordPress — `brain/monkey` stubs out `__()`, `apply_filters`, etc., so a unit test can exercise a class method without booting WordPress.

```bash
make test           # default target → unit tests only
make test-unit      # explicit
```

When you add a new unit test:

- Mirror the source path: a class at `includes/Foo/Bar.php` is tested by `tests/Unit/Foo/BarTest.php`.
- Extend the project's base unit test class, not PHPUnit's directly — it sets up the brain/monkey lifecycle.
- Don't touch `$_GET`, `$_POST`, the database, the filesystem, or `define()` plugin constants. Move that to integration tests instead.

## Integration tests

Located in [`tests/Integration/`](../tests/Integration/). They run inside the `wp-env` Docker stack, against a real WordPress + MySQL.

```bash
make env                # boot wp-env first
make test-integration   # run the integration suite
```

Use these for code paths that genuinely depend on WordPress core: hooks, options, transients, custom tables, AJAX handlers, REST routes. Anything that boils down to "I need `wpdb`" or "I need `apply_filters` to actually apply".

CI runs the same suite (`pr-checks.yml`, the **Integration tests (wp-env)** job) so a pure-Docker contributor can develop against the exact same environment.

## PHPCS / WordPress Coding Standards

Configuration: [`phpcs.xml.dist`](../phpcs.xml.dist).

```bash
make lint           # report violations
make lint-fix       # auto-fix what can be auto-fixed (PHPCBF)
```

The ruleset enforces the WordPress Coding Standards plus a small project-specific overlay:

- **DTOs and Enums** (`includes/DTOs/`, `includes/Enums/`) use modern PSR-12 / PascalCase, not WPCS naming. The rules that conflict with that style are excluded for those paths only.
- **Yoda conditions**, **trailing-comma-in-array**, **base64 encoding** (legitimate for crypto), and a few comment-formatting nits are globally relaxed; everything else is on.
- The version-alignment script tolerates `-dev` / `-alpha` / `-beta` / `-rc` pre-release suffixes by stripping them before comparing the PHP `Version:` header to the readme `Stable tag:` (see [`release.md`](release.md)).

When PHPCS reports a violation, the rule code is in the right column. Search for it in the config or in [WPCS docs](https://github.com/WordPress/WordPress-Coding-Standards/wiki) before suppressing — most warnings are real bugs (missing escaping, missing nonce, missing prepare).

## PHPStan

Configuration: [`phpstan.neon`](../phpstan.neon). Bootstrap stubs: [`phpstan-bootstrap.php`](../phpstan-bootstrap.php).

```bash
make stan
```

We run **level 8 (max strictness) with no baseline.** Every type error must be fixed in code, not suppressed. The `szepeviktor/phpstan-wordpress` extension teaches PHPStan about the WordPress API surface so e.g. `wp_remote_get()` returns `array|WP_Error` and `$wpdb->update()` returns `int|false`.

A couple of constants are declared `dynamicConstantNames` (`WP_DEBUG`, `COOKIEHASH`) so PHPStan does not collapse `if ( WP_DEBUG )` into "always false" on the bootstrap stub default. Their runtime values come from `wp-config.php` and change per install.

If you find a real type error PHPStan can't see (e.g. PHP extension stubs are missing in CI), use `// @phpstan-ignore-next-line <identifier>` with a comment explaining why. Don't add to a baseline — the project deliberately doesn't have one.

## Psalm taint analysis

Configuration: [`psalm.xml`](../psalm.xml).

```bash
make psalm
```

Psalm here runs in **taint-analysis mode only**. The `humanmade/psalm-plugin-wordpress` plugin teaches it that `esc_html()`, `esc_attr()`, `esc_url()`, `wpdb->prepare()`, `sanitize_*()` are sanitisation barriers, so user-controlled values from `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_FILES` / `$_SERVER` only become findings if they reach a dangerous sink (`echo`, `eval`, `exec`, `$wpdb->query()`, `file_put_contents`, `header`, …) without passing through one.

General static type-checking is suppressed in `psalm.xml` — that's PHPStan's job. Running both as type-checkers would just duplicate failures and obscure real taint findings.

If Psalm flags a path you believe is safe, the right fix is almost always to pipe the value through the appropriate WordPress escaper. Suppressing should be a last resort and must be justified inline.

## i18n validation

Configuration: [`.github/workflows/i18n-validate.yml`](../.github/workflows/i18n-validate.yml).

```bash
make i18n
```

The Makefile target runs `wp i18n make-pot` and writes the result to `languages/diluxone-users.pot`, the file that ships. The CI workflow does the same and additionally fails the build if any `Warning:` / `Error:` line appears in the output (WP-CLI prints them to stderr but exits 0 even when present, so we capture the output and grep ourselves).

The workflow catches three real classes of bug:

- **Missing translator comments** on `sprintf()` placeholders. WordPress requires a `/* translators: %s: ... */` comment **on the line immediately preceding** the translation function call — separating it with a blank line silently makes it invisible to gettext. We learned this the hard way fixing six of these on the first run.
- **Conflicting translator comments** on the same msgid. If `Paused (%s)` appears in three places, all three must agree on what the placeholder means; gettext merges identical msgids.
- **Concat of translatable strings** like `__('Hello ') . __(' world')`, **dynamic text domains** like `__($string, $variable)`, and other hard-to-translate patterns.

Plugin Check (the wp.org-side validator) catches a partly overlapping but distinct subset, so both run on every PR.

## Plugin Check

CI step in [`pr-checks.yml`](../.github/workflows/pr-checks.yml#L60). Runs the [official WordPress Plugin Check](https://github.com/WordPress/plugin-check-action) action with all categories enabled (`plugin_repo`, `security`, `performance`, `accessibility`, `general`) plus experimental checks. Some codes are explicitly ignored (`hidden_files`, `github_directory`, `unexpected_markdown_file`, `stable_tag_mismatch`) because they false-positive on the GitHub-flat repo layout or on the `-dev` suffix workflow.

If you ever submit a new version of the plugin to wp.org, the same checks run there. CI catches them earlier so a wp.org reviewer never has to.

## End-to-end tests

Located in [`tests/e2e/`](../tests/e2e/). They drive a real Chromium against the wp-env dev site on port 8892, which mounts the working tree — so what is tested is what is checked out. See [`tests/e2e/README.md`](../tests/e2e/README.md) for what has to be running.

```bash
make env        # once
make test-e2e   # every spec, including the layout measurements
```

The same plugin network-activated has a suite of its own, in [`tests/e2e/network/`](../tests/e2e/network/) with [`playwright.network.config.ts`](../playwright.network.config.ts). It drives the wp-env tests site (port 8893) converted into a subdirectory network, makes `/alpha/` and `/beta/` with WP-CLI and deletes them afterwards. `make test-e2e-network` does the conversion and the run. [`tests/e2e/COVERAGE.md`](../tests/e2e/COVERAGE.md) maps every feature to the test that covers it, in both suites.

## Layout invariants

Located in [`tests/e2e/specs/admin-layout.spec.ts`](../tests/e2e/specs/admin-layout.spec.ts), with the measuring in [`tests/e2e/support/layout.ts`](../tests/e2e/support/layout.ts).

Why there is a fourth layer at all: the three above answer *does the code behave*, and they answer it well. Every visual bug this plugin has shipped got past all three of them green — a block drawn on top of the card above it, half a screen of nothing beside a column of settings, a bordered box with nothing inside it, a rail that fell underneath the form it belongs beside. None of those is a wrong value or a missing hook. They are geometry, and only a browser can see geometry.

So this measures it, on **every tab of every screen**, at **four widths** — 1600, 1280, and WordPress's own two breakpoints, 960 (the menu folds to icons) and 782 (the phone layout, where the second column has to give up and go underneath). Six rules, none of which is an opinion about the design:

| Rule | What it means |
| --- | --- |
| `overlap` | No two sibling blocks share pixels. This is the one that matters: a block drawn on top of another is two rectangles intersecting. |
| `overflow` | Nothing reaches past the right-hand edge of the plugin's own block, and the page never scrolls sideways. |
| `air` | Two blocks of a screen never touch. Asked only between the blocks of a screen — options inside a group touch on purpose. |
| `blank` | No box with a border or a ground and nothing inside it. The stylesheet already hides the ones that are `:empty`; this catches the ones whose contents came out blank. |
| `rail` | Where a screen declares a second column, it is beside the settings above 960px and underneath below it. |
| `hidden` | Nothing carrying the `hidden` attribute still has a box. The browser's own rule for it has the weight of a bare tag, so any component that gives itself a `display` outranks it and what a script hid stays on the screen. |

They need no baseline image, they mean the same thing on every machine, they say which element is wrong — so they run with everything else, in `make test-e2e` and in CI.

**Adding a screen or a tab.** The list lives in [`tests/e2e/support/screens.ts`](../tests/e2e/support/screens.ts) and three suites walk it: the behaviour spec, the layout spec and the picture spec. Add the slug to `SCREENS` and all three cover it. You will not forget: `admin-layout.spec.ts` reads the tab strip each screen draws and fails on a tab that is not in the registry. A screen whose content moves on its own — a report — gets an entry in `PINNED` saying which address makes it reproducible.

**Proving the measuring still works.** `The measuring itself can fail` breaks a real screen six ways, one per rule, and requires each break to be seen. A suite that has never been seen to fail is a suite nobody has a reason to believe.

## Visual regression (the pictures)

Located in [`tests/e2e/specs/admin-snapshots.spec.ts`](../tests/e2e/specs/admin-snapshots.spec.ts); the baselines are in [`tests/e2e/snapshots/`](../tests/e2e/snapshots/).

```bash
make test-visual          # compare every screen with the picture committed
make test-visual-update   # take the pictures again and accept them
```

The measurements know the rules a layout must not break. They do not know what a screen is *supposed to look like*, and they never will: a blue line nobody asked for keeps every rule and is still wrong. The only thing that catches that is the picture, and the only thing that makes a picture an assertion is having last week's to compare it with. One per tab, plus the sign-in page a stranger sees.

Three decisions keep it from crying wolf:

- **What is photographed is the plugin's own block** (`.wrap.diluxone-users-admin`), not the window. The admin bar counts how long the page took to build, the menu carries update badges, the footer prints the WordPress version — none of that is this plugin's and all of it changes on its own.
- **What moves by itself inside that block is masked** — the dates and session counts in the reports, the environment table, an avatar. A mask keeps the element's box and fills it, so a block that changes *size* is still a difference. Only the content is forgiven, never the geometry.
- **The window, the pixel ratio, the motion and the caret are pinned** in the `visual` project in [`playwright.config.ts`](../playwright.config.ts): 1280×900, device scale 1, `reducedMotion`, `animations: 'disabled'`, `caret: 'hide'`, and a 0.2% tolerance for antialiasing.

**Updating a picture when the change IS what you wanted.** `make test-visual-update` — Playwright's `--update-snapshots` — rewrites the baselines. Then look at `git diff --stat tests/e2e/snapshots` **before committing**: that diff is the review of the redesign, and accepting it without looking is how a bug becomes the baseline.

**Why it is not in CI.** A baseline image is a picture of one machine's font rendering, its sub-pixel smoothing and its scrollbars. Committing those and asking a runner to match them is a job that is red for reasons nobody can act on, and a gate nobody can act on is a gate that gets switched off. So the `visual` project only exists when `DU_SNAPSHOTS=1` is set, which `make test-visual` does, and the baselines carry the platform in their filename. The layout measurements — which are portable — carry the load in CI.

## Running everything at once

```bash
make check     # lint + stan + psalm + tests
make release   # make check + version-alignment dry-run
```

`make release` is what you should run before pushing a release tag — it's the closest you can get to "what CI will say" without actually pushing.
