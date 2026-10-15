# AGENTS.md

<!-- dx:org:start (kept in step by DiluxOne/.github, scripts/sync-repos.py; edit it there) -->
## The organisation's rules (the same in every DiluxOne repository)

You are working in a DiluxOne repository. Whoever you work for (the
maintainer, or someone contributing from a fork), these hold here, and the
step-by-step flow with its commands is in
[DiluxOne/.github, `docs/agents.md`](https://github.com/DiluxOne/.github/blob/main/docs/agents.md):

1. **Nothing starts without an accepted issue.** Find the issue, or open one
   with the form that fits (it sets the issue's Type: Bug, Feature, Docs or
   Task), and wait until a maintainer adds the `accepted` label. Never add it
   yourself. The pull request says `Closes #<number>`; CI fails it otherwise.
2. **The pull request's type fits the issue's Type**: `feat` (or any `!`)
   closes a Feature, `fix` a Bug; other types close any accepted issue.
3. **Branch** `<type>/<number>-<short-kebab>`; **commit and title** as a
   Conventional Commit of 72 characters or fewer (CI rejects more than 100);
   bodies are plain paragraphs, never hard-wrapped; no `Claude-Session:`.
4. **Check before the pull request** (`dx check`, or the repository's
   `make pre-pr`) and open it (`dx pr`) only when the person you work for
   says so.
5. **Never** push to `main`, create or move a tag, or approve a release.
6. **Say AI took part** with one line at the end of what you write on GitHub:
   `🤖 AI-generated · <model> (<maker>)` when you wrote it,
   `🤖 AI-assisted · <model> (<maker>)` when a person did with your help.
   Never "Generated with …".
7. **Say what waits on whom, with its link.** When a step waits on a
   person, tell the maintainer the action and the full URL, one per line
   ("Add `accepted` to https://github.com/DiluxOne/<repo>/issues/<n>",
   "Approve and merge https://github.com/DiluxOne/<repo>/pull/<n>"), never
   "the issue needs accepting" alone. Someone working from a fork or a
   clone cannot accept, approve or merge: never ask them for a
   maintainer's step; tell them a maintainer will review it and answer
   soon, with the link to where it waits.
<!-- dx:org:end -->

Instructions for any coding agent working in this repository (Claude Code,
Codex, Cursor, …). Humans: the same rules live in
[`CONTRIBUTING.md`](CONTRIBUTING.md) and [`docs/ai.md`](docs/ai.md);
this file is the short version an agent must follow without exception.

## What this is

DiluxOne Users+, a WordPress plugin with the slug `diluxone-users`: custom
user fields, a front-end account area, passwordless sign-in, social login,
two-step verification and session control, in one plugin that
depends on no other. It is at 1.0.0 and **not yet published** on
wordpress.org. Architecture, hard rules and review priorities:
[`docs/architecture.md`](docs/architecture.md). The public API (hooks an
add-on or a theme builds on): [`docs/extending.md`](docs/extending.md).

The repository is `diluxone-users-wordpress`; the slug and text domain are
`diluxone-users`. Before touching a path or a workflow, check which of the
two it needs ([`docs/development.md`](docs/development.md#the-repository-name-is-not-the-plugin-slug)).

## How work reaches `main`

The organisation's block above holds: an accepted issue first, then a pull
request, squash-merged. Nobody pushes to `main`, admins included. The
organisation's pipeline enforces it (`org-pull-request.yml`, required by its
ruleset) and this repository adds its own checks (`plugin-checks.yml`).

## Before you push

```bash
make check               # PHPCS, PHPStan level 8, Psalm taint, unit tests
make test-unit-min       # the unit suite on PHP 8.0, the minimum
make env-multisite       # after make env: the tests site as a network
make test-integration    # the integration suite, on that network
make test-integration-single  # the same suite on a single site (throwaway wp-env, 8886)
make test-e2e            # Playwright, single site (dev site, port 8892)
make test-e2e-network    # Playwright, subdirectory network (tests site, 8893)
make plugin-check        # wordpress.org's Plugin Check on the built dist
```

The organisation's rule engine (`checks / Review rules (wordpress-plugin)`)
fails on what wordpress.org's review flags and Plugin Check misses: an
`EscapeOutput` suppression, a menu position among WordPress's own, a handler
that reads the request before its nonce and capability, and any security
suppression not listed with its reason in
[`.github/review-suppressions.yml`](.github/review-suppressions.yml). Plugin
Check runs with `strict`: a warning fails.

A change carries its tests at every layer it touches, in the same pull
request: unit, integration on a single site and on a network, end-to-end on a
single site and on a network, and the listing screenshots (`make screenshots`) when a screen
changes, with the visual baselines retaken on purpose (`make
test-visual-update`)
([`docs/testing-and-quality.md`](docs/testing-and-quality.md)). A new tab or
screen goes into `tests/e2e/support/screens.ts`, and a new feature or state
into [`tests/e2e/COVERAGE.md`](tests/e2e/COVERAGE.md).

## How a change becomes a release

The plugin has not been published yet. wordpress.org approved it on 4 October
2026, from a zip the maintainer uploaded, and `readme.txt` holds 1.0.0 with
the line `Unreleased.` until the maintainer decides to publish; from there the
organisation's release pipeline takes over. The whole flow, and what applies
when: [`docs/release.md`](docs/release.md). What you must do, and never do, in
a change:

- **The version is never typed.** `main` keeps the last released version in
  its three markers (`Version:` and `DILUXONE_USERS_VERSION` in
  `diluxone-users.php`, `Stable tag:` in `readme.txt`); today that is `1.0.0`.
- **Write the changelog in the same pull request.** A change a user notices
  adds one bullet under the newest `= X.Y.Z =` entry of `readme.txt`, written
  for users. That entry starts with the line `Unreleased.`, the maintainer's
  release switch: while it is there the release workflow ends as "Not ready"
  and asks nobody for anything. Today it holds `1.0.0`. Never remove it as
  part of another change.

## Rules you must not break

- **Never** push to `main`, create or push a tag, create a GitHub release,
  approve a deployment, touch the wordpress.org SVN or submit the plugin to
  wordpress.org. A tag `X.Y.Z` publishes the plugin to every WordPress site
  and is permanent ([`docs/release.md`](docs/release.md)).
- **Never** bump the version markers.
- **Never** commit secrets: no `.env*`, no OAuth client secrets, no SVN
  password, no real person's data.
- **PHP 8.0 and WordPress 6.2** are the minimums; the runtime has no Composer
  dependencies and `vendor/` never ships.
- **The plugin knows nothing about other plugins.** What belongs to a course,
  a membership or a forum enters through a filter or a shortcode
  ([`docs/architecture.md`](docs/architecture.md)).
- **Its own Pro add-on is the one exception, and only as advertising.** The free plugin may show a Pro preview (the control it would be, disabled: `diluxone_users_ui_pro_choice()` and its kin, off with one setting) where a Pro feature belongs, but ships no Pro code and names no Pro setting; Pro enters through the public hooks ([`docs/extending.md`](docs/extending.md#users-pro-on-these-screens)).
- **What an administrator turns off disappears from the front end**, and
  anything shown to a person is true for every way in the site left open.
- **Every user-facing string** goes through a translation function with the
  `diluxone-users` text domain; input is unslashed and sanitized, output
  escaped, SQL prepared, secrets hashed and never logged, single-use tokens
  consumed on first use.
- **A stored option or user meta key is not renamed** without a migration.
- **A new stored setting gets a scope** (`network`, `hub` or `site`) in
  `includes/options-scope.php`, and is read and written through the plugin's
  helpers (`diluxone_users_option()`, `diluxone_users_raw_get()`,
  `diluxone_users_update_option()`, `diluxone_users_delete_option()`), never
  with `get_option()` directly ([`docs/architecture.md`](docs/architecture.md#data)).
- **Docs change in the same PR as the behaviour they describe.** That
  includes this file, `docs/architecture.md`, `docs/extending.md`,
  `readme.txt` and `docs/`. A doc that describes something the code no longer
  does is a bug.

## Where the details are

| Question | Read |
| --- | --- |
| How the plugin works, its hard rules, what the review looks for | [`docs/architecture.md`](docs/architecture.md) |
| The hooks an add-on, a theme or a site builds on | [`docs/extending.md`](docs/extending.md) |
| Local setup, Make targets, repository name vs plugin slug | [`docs/development.md`](docs/development.md) |
| Every quality gate, what runs when, how to run each | [`docs/testing-and-quality.md`](docs/testing-and-quality.md) |
| The end-to-end suites: what has to be up, how they are built | [`tests/e2e/README.md`](tests/e2e/README.md) |
| Which test covers which feature | [`tests/e2e/COVERAGE.md`](tests/e2e/COVERAGE.md) |
| Branches, titles, pull requests, forks, the review | [`CONTRIBUTING.md`](CONTRIBUTING.md) |
| Versions, the first submission, the release after it | [`docs/release.md`](docs/release.md) |
| What comes next and what the plugin will not do | [`docs/roadmap.md`](docs/roadmap.md) |
| How AI is used here and the rules for AI-assisted work | [`docs/ai.md`](docs/ai.md) |
| The shared workflows, policy and review profiles | [DiluxOne/.github](https://github.com/DiluxOne/.github) |
