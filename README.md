# DiluxOne Users+

WordPress plugin for everything about the people who use a site: custom fields, a front-end account area, passwordless sign-in, social login, two-step verification, passkeys and session control, in one plugin that depends on no other.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

## Who it is for

Sites where people have accounts: memberships, courses, communities, client areas. In most of them this gets solved again every time, with four plugins that do not talk to each other: one for custom fields, one for social login, one for two-factor, one for the front-end profile. Here it lives once, in a single admin section and a single set of user-meta keys.

## What it does

- **User fields** defined from the dashboard: name, type, whether it is required, where it goes, and who can change it and how many times. The ones WordPress already has (first and last name) are in the same list and follow the same rules.
- **An account area on the front end**: Home, Your details, Linked accounts, Security, Privacy and Notifications, with tabs on top or a menu down the side. Sections can be renamed, reordered, turned off and added; one of your own is a name, an address and a shortcode.
- **How people get in**: a link sent to their e-mail with no password at all, username and password, or both, with control over what happens to WordPress's own registration and profile screens.
- **Social login** with twelve providers, a step-by-step guide for each console, buttons with the real brand marks, and a live test before you turn one on.
- **Two-step verification**: a code by e-mail, an authenticator app with a QR code, and backup codes, with a policy per role and per way in.
- **Passkeys** (WebAuthn), each one with a name of its own.
- **Sessions**: how long they last, where they are open and how to close them.
- **Privacy**: the export and erasure requests WordPress already knows how to handle, put where people look for them.

What belongs to someone else (a course, a membership, a forum) comes in through a filter or a shortcode; the hooks are in [`docs/extending.md`](docs/extending.md).

## Principles

1. **What an administrator turns off disappears from the front end.** With no social provider enabled there is no "Linked accounts" section at all; with neither data download nor account deletion allowed there is no "Privacy" section.
2. **The plugin does not know what a course is.** Nor a membership, nor a forum. What is not its own is added from outside and can be removed without touching it.
3. **It works with any theme.** It ships its own styles, its templates are overridable from the theme, and its colours and measurements are CSS custom properties a site can redefine.
4. **Nothing it shows is a lie.** If a notice says the second factor is not being asked for, it is because no door the site has open is asking for it.

## Install

Not published yet: the first submission to wordpress.org is pending ([`docs/release.md`](docs/release.md)). Once it is approved it will be at [wordpress.org/plugins/diluxone-users](https://wordpress.org/plugins/diluxone-users/). Until then, `make zip` builds the installable `build/diluxone-users.zip` from a checkout. Requirements, FAQ, privacy and the external services it talks to are in [`readme.txt`](readme.txt), the text wordpress.org will show.

## How it is built

This plugin is developed with AI coding agents (Claude, through Claude Code) under human review. The maintainer reads, runs and signs every change, and every change must pass the whole quality gate (coding standards, static analysis, taint analysis, unit, integration and end-to-end tests on a single site and on a network, WordPress Plugin Check) before it can merge. How AI is used here and the rules for contributing with AI: [`docs/ai.md`](docs/ai.md).

## Run it from source

```bash
git clone https://github.com/DiluxOne/diluxone-users-wordpress.git
cd diluxone-users-wordpress
make install     # dev tooling into vendor/ (Docker; no PHP needed on the host)
npm install      # wp-env and Playwright
make env         # WordPress at http://localhost:8892, admin / password
make check       # PHPCS, PHPStan level 8, Psalm taint analysis, unit tests
```

The checkout is mounted as `wp-content/plugins/diluxone-users-wordpress/`; activate it from **Plugins**. `make help` lists everything else.

## Documentation

| Read this | For |
| --- | --- |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Issues, branches, pull requests, what CI enforces |
| [`docs/development.md`](docs/development.md) | Local setup, Make targets, repository name vs plugin slug |
| [`docs/testing-and-quality.md`](docs/testing-and-quality.md) | Every quality gate, the testing rule, how to run each |
| [`tests/e2e/README.md`](tests/e2e/README.md) | The end-to-end suites: what has to be up, how they are built |
| [`docs/architecture.md`](docs/architecture.md) | How the plugin is built and the rules its code follows |
| [`docs/extending.md`](docs/extending.md) | Public API: the hooks and CSS properties an add-on, a theme or a site builds on |
| [`docs/ai.md`](docs/ai.md) | How AI is used here, and the rules for AI-assisted contributions |
| [`docs/release.md`](docs/release.md) | Versions, the first submission, and the release flow after it |
| [`AGENTS.md`](AGENTS.md) | The short rules any coding agent must follow |
| [`SECURITY.md`](SECURITY.md) | Private vulnerability reporting |

## About

Built by [DiluxOne](https://diluxone.com) and maintained by Pablo Di Loreto ([@soydiloreto](https://github.com/soydiloreto)). Free software under the GPL-2.0-or-later, see [LICENSE](LICENSE). Issues, forks and pull requests are welcome.
