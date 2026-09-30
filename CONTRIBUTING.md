# Contributing to DiluxOne Users+

Thanks for helping. This page covers issues, pull requests and what CI enforces. The organisation's [contributing guide](https://github.com/DiluxOne/.github/blob/main/CONTRIBUTING.md) has the general rules; this one adds what is specific to the plugin.

## Bugs, ideas and questions

- **A bug or a feature request:** open a [new issue](https://github.com/DiluxOne/diluxone-users-wordpress/issues/new/choose) with the matching template. Say which WordPress and PHP, single site or network, and which way in (e-mail link, password, a social provider, a passkey, a second factor): most sign-in bugs depend on the combination.
- **Using the plugin** (how do I…?, my sign-in link does not arrive): the [wordpress.org support forum](https://wordpress.org/support/plugin/diluxone-users/), once the plugin is published, where answers stay public for the next person.
- **A security vulnerability:** [SECURITY.md](SECURITY.md), never a public issue. A plugin that decides who gets into a site is worth reporting privately even when you are not sure.

## Pull requests

1. Branch from `main`: in your fork if you are an outside contributor, in the repository if you are a maintainer. Name it `<type>/<kebab-case>`, for example `fix/totp-replay-window`.
2. Make the change with its tests at every layer it touches: unit, integration on a single site and on a network, end-to-end on a single site and on a network, and the listing screenshots when a screen changes (see [`docs/testing-and-quality.md`](docs/testing-and-quality.md#the-rule-tests-at-every-layer-a-change-touches)). Update any doc that describes what you changed, and the eight translations when you add or change a string. A change a user notices adds one bullet to the newest `= X.Y.Z =` entry of `readme.txt` ([`docs/release.md`](docs/release.md)).
3. Run `make check` (PHPCS, PHPStan, Psalm, unit tests). Integration, end-to-end, i18n and Plugin Check have their own targets ([`docs/development.md`](docs/development.md)); CI runs all of them.
4. Open the pull request and fill in the template: 📝 What changes and 💡 Why are required, 🧪 How I tested it and 📸 Screenshots help the review. The description becomes the commit body on `main`, word for word, so write it for the person who reads the history in a year: plain words, short paragraphs.
5. If AI took part, end the description with one line: `🤖 AI-assisted · <model> (<maker>)`. The rules for contributing with AI are in [`docs/ai.md`](docs/ai.md).

Pull requests are squash-merged: the title becomes the commit title on `main`, the description its body, and the branch's `Co-authored-by` trailers are kept. Nothing reaches `main` without a green pull request, maintainers included.

### Titles and commits

[Conventional Commits](https://www.conventionalcommits.org/): `<type>(<optional-scope>): <subject>`, with type one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`, at most 100 characters, no trailing period. The same format applies to the pull request title and to every commit on the branch. Bodies are plain paragraphs, one line each, never hard-wrapped.

```
feat(2fa): let a site offer the authenticator app without the e-mail code
fix(sso): refuse a link trip that carries no nonce
```

### What CI enforces

Once the repository lives in the DiluxOne organisation, the shared [`conventions`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/conventions.yml) workflow fails a pull request when the branch name, the title or a commit breaks the format above, when a commit carries a `Claude-Session:` trailer, when "📝 What changes" or "💡 Why" is empty, when the description ends with a "Generated with …" footer, when a relative link in the docs is broken, or when a retired product name comes back.

Then the quality gates: syntax and unit tests on PHP 8.0 to 8.5, PHPCS with the WordPress Coding Standards, PHPStan level 8, Psalm taint analysis, i18n extraction and locale completeness, WordPress Plugin Check, readme and version alignment, integration tests on a wp-env single site and network, and Playwright end-to-end tests on a single site and on a network. What each one catches, and how to run it: [`docs/testing-and-quality.md`](docs/testing-and-quality.md).

Every job that runs on a pull request is a required check on `main`, except CodeQL, which runs only when JavaScript changes.

### The review

After the move, Claude reviews every pull request from a branch of this repository, guided by [`docs/architecture.md`](docs/architecture.md), [`AGENTS.md`](AGENTS.md) and the organisation's WordPress review profile. It comments inline on blockers and majors, lists minor findings in its summary, labels the risk, the complexity and the type of the change (`type:*`, read from the diff; a `type:*` label a person sets wins), and checks that the description matches the code. Fix the code and push, or answer in the thread mentioning `@dilux-bot`; every conversation must be resolved before merging. Changes to sign-in, two-step, passkeys, social login, sessions, privacy, the templates and the other paths in [`.github/review-policy.yml`](.github/review-policy.yml) are always high risk and always merged by a person. Pull requests from forks are not reviewed automatically; the maintainer reviews them. Details: [`docs/ai.md`](docs/ai.md).

## Coding rules the linters cannot express

- **PHP 8.0 and WordPress 6.2** are the minimums. No syntax or function from later versions without a fallback.
- **No Composer dependencies at runtime.** `composer install` brings dev tooling only; `vendor/` never ships.
- **The plugin depends on no other plugin.** What belongs to another domain enters through a filter or a shortcode ([`docs/extending.md`](docs/extending.md)).
- **Every user-facing string** goes through a translation function with the text domain `diluxone-users`, with a `/* translators: */` comment on the line right before any `sprintf()` placeholder.
- **Input unslashed and sanitised, output escaped, SQL prepared.** Psalm and PHPCS catch the obvious cases; you catch the rest.
- **Codes, backup codes and sign-in tokens are stored hashed, used once, and never logged**, even with debug logging on.
- **What an administrator turns off disappears from the front end**, and a notice is true for every way in the site left open.

The full list, with the architecture and the review priorities, is in [`docs/architecture.md`](docs/architecture.md).

## Versions and releases

Versions follow [Semantic Versioning](https://semver.org/) and nobody types them in a pull request. The plugin is at 1.0.0 and not yet published: the first version goes to wordpress.org as a zip for review. After the move and the approval, the next version is computed from the `type:*` labels of merged pull requests, every push to `main` publishes a development build, and the maintainer decides when a version is ready and approves its publication. Never bump the version in your pull request. The whole flow, and what applies when: [`docs/release.md`](docs/release.md).

## Code of Conduct and licence

By participating you agree to the organisation's [Code of Conduct](https://github.com/DiluxOne/.github/blob/main/CODE_OF_CONDUCT.md). Your contributions are licensed under the [GPL-2.0-or-later](LICENSE).
