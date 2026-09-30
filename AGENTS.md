# AGENTS.md

Instructions for any coding agent working in this repository (Claude Code,
Codex, Cursor, …). Humans: the same rules live in
[`CONTRIBUTING.md`](CONTRIBUTING.md) and [`docs/ai.md`](docs/ai.md);
this file is the short version an agent must follow without exception.

## What this is

DiluxOne Users+, a WordPress plugin with the slug `diluxone-users`: custom
user fields, a front-end account area, passwordless sign-in, social login,
two-step verification, passkeys and session control, in one plugin that
depends on no other. It is at 1.0.0 and **not yet published** on
wordpress.org. Architecture, hard rules and review priorities:
[`docs/architecture.md`](docs/architecture.md). The public API (hooks an
add-on or a theme builds on): [`docs/extending.md`](docs/extending.md).

The repository is `diluxone-users-wordpress`; the slug and text domain are
`diluxone-users`. Before touching a path or a workflow, check which of the
two it needs ([`docs/development.md`](docs/development.md#the-repository-name-is-not-the-plugin-slug)).

## How work reaches `main`

Only through a pull request, squash-merged. Nobody pushes to `main`, admins
included. Once the repository lives in the DiluxOne organisation, CI enforces
every rule in this section (the shared `conventions` workflow from
`DiluxOne/.github`) and a PR that breaks one cannot merge; follow them from
now on.

- **Branch:** `<type>/<kebab-case>`, e.g. `fix/totp-replay-window`.
- **PR title:** a Conventional Commit header, `type(scope): subject`, at most
  100 characters, no trailing period. It becomes the commit on `main`.
- **Every commit on the branch:** the same header format.
- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`,
  `build`, `ci`, `chore`, `revert`.
- **Trailers:** `Co-Authored-By:` is fine. `Claude-Session:` and other
  session links are rejected.
- **Messages** say what the change does and why, not who or what wrote it.
  The body is plain paragraphs, one line each, never hard-wrapped.
- **PR description:** fill the template's "📝 What changes" and "💡 Why"
  sections; CI fails when either is empty. It becomes the commit body.
- **AI line:** end every PR, issue or comment you write with
  `🤖 AI-generated · <model> (Anthropic)` (`AI-assisted` when a person wrote
  it with your help). Never "Generated with …": CI rejects it.

## Before you push

```bash
make check               # PHPCS, PHPStan level 8, Psalm taint, unit tests
make test-unit-min       # the unit suite on PHP 8.0, the minimum
make env-multisite       # after make env: the tests site as a network
make test-integration    # the integration suite, on that network
make test-e2e            # Playwright, single site (dev site, port 8892)
make test-e2e-network    # Playwright, subdirectory network (tests site, 8893)
make plugin-check        # wordpress.org's Plugin Check on the built dist
```

A change carries its tests at every layer it touches, in the same pull
request: unit, integration on a network, end-to-end on a single site and on a
network, and the listing screenshots (`make screenshots`) when a screen
changes, with the visual baselines retaken on purpose (`make
test-visual-update`)
([`docs/testing-and-quality.md`](docs/testing-and-quality.md)). A new tab or
screen goes into `tests/e2e/support/screens.ts`, and a new feature or state
into [`tests/e2e/COVERAGE.md`](tests/e2e/COVERAGE.md).

## How a change becomes a release

The plugin has not been published yet; the first version goes to wordpress.org
as a zip for review, by the maintainer. After the move and the approval, the
organisation's release pipeline takes over. The whole flow, and what applies
when: [`docs/release.md`](docs/release.md). What you must do, and never do, in
a change:

- **The version is never typed.** `main` keeps the last released version in
  its three markers (`Version:` and `DILUXONE_USERS_VERSION` in
  `diluxone-users.php`, `Stable tag:` in `readme.txt`); today that is `1.0.0`.
- **Write the changelog in the same pull request.** A change a user notices
  adds one bullet under the newest `= X.Y.Z =` entry of `readme.txt`, written
  for users. Once the release pipeline is in place, that entry starts with the
  line `Unreleased.`, and that line is the maintainer's release switch: never
  remove it as part of another change.

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
| How AI is used here and the rules for AI-assisted work | [`docs/ai.md`](docs/ai.md) |
| The shared workflows, policy and review profiles | [DiluxOne/.github](https://github.com/DiluxOne/.github) |
