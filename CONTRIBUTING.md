# Contributing to DiluxOne Users+

The flow — an accepted issue first, the branch, the commits, the checks, the pull request and the review — is the organisation's, the same in every DiluxOne repository: [DiluxOne/.github, `CONTRIBUTING.md`](https://github.com/DiluxOne/.github/blob/main/CONTRIBUTING.md) for people and [`docs/agents.md`](https://github.com/DiluxOne/.github/blob/main/docs/agents.md) for an agent. This page adds only what is this plugin's own.

## Reporting a problem

Say which WordPress and PHP, single site or network, and which way in (e-mail link, password, a social provider, a second factor): most sign-in bugs depend on the combination. Questions about using the plugin go to the [wordpress.org support forum](https://wordpress.org/support/plugin/diluxone-users/), once the plugin is published.

## A change carries its tests at every layer it touches

Unit, integration on a single site and on a network, end-to-end on a single site and on a network, and the listing screenshots when a screen changes ([`docs/testing-and-quality.md`](docs/testing-and-quality.md#the-rule-tests-at-every-layer-a-change-touches)). Update any doc that describes what you changed, and the eight translations when you add or change a string. A change a user notices adds one bullet to the newest `= X.Y.Z =` entry of `readme.txt` ([`docs/release.md`](docs/release.md)). `make check` runs PHPCS, PHPStan, Psalm and the unit tests; the other suites have their own targets ([`docs/development.md`](docs/development.md)).

Changes to sign-in, two-step, social login, sessions, privacy, the templates and the other paths in [`.github/review-policy.yml`](.github/review-policy.yml) are always high risk and always merged by a person.

## Coding rules the linters cannot express

- **PHP 8.0 and WordPress 6.2** are the minimums. No syntax or function from later versions without a fallback.
- **No Composer dependencies at runtime.** `composer install` brings dev tooling only; `vendor/` never ships.
- **The plugin depends on no other plugin.** What belongs to another domain enters through a filter or a shortcode ([`docs/extending.md`](docs/extending.md)).
- **Every user-facing string** goes through a translation function with the text domain `diluxone-users`, with a `/* translators: */` comment on the line right before any `sprintf()` placeholder.
- **Input unslashed and sanitised, output escaped, SQL prepared.** Psalm and PHPCS catch the obvious cases; you catch the rest.
- **Codes, backup codes and sign-in tokens are stored hashed, used once, and never logged**, even with debug logging on.
- **What an administrator turns off disappears from the front end**, and a notice is true for every way in the site left open.

The full list, with the architecture and the review priorities, is in [`docs/architecture.md`](docs/architecture.md).

## Versions

Nobody types a version in a pull request. The plugin is at 1.0.0 and not yet published; the whole flow, and what applies when, is in [`docs/release.md`](docs/release.md).

## Licence

Your contributions are licensed under the [GPL-2.0-or-later](LICENSE).
