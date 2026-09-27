# AI in this project

How AI is used to build and review the plugin, and the rules for anyone who contributes with AI. Coding agents read the short, enforceable version in [`AGENTS.md`](../AGENTS.md).

## How the plugin is built

The maintainer writes most changes with Claude Code, reads every diff, runs the tests and signs the commit. The rules an agent could break by mistake are enforced by CI and branch protection, so `AGENTS.md` is guidance and the checks are the guarantee. Every pull request carries one sober line saying how it was made, for example `🤖 AI-assisted · Claude Opus 5.5 (Anthropic)`; that line lands in the commit on `main`.

## The review on every pull request

Once the repository lives in the DiluxOne organisation, every pull request from a branch of this repository is reviewed by Claude, through the shared [`claude-review`](https://github.com/DiluxOne/.github/blob/main/.github/workflows/claude-review.yml) workflow in `DiluxOne/.github`. It runs after the conventions and the fast quality gates pass, so it never reviews code that does not build.

- **What it reads:** the organisation's review profiles ([`general.md`](https://github.com/DiluxOne/.github/blob/main/review-profiles/general.md) and [`plugin-wp.md`](https://github.com/DiluxOne/.github/blob/main/review-profiles/plugin-wp.md), with the lessons of the wordpress.org review), this repository's [`docs/architecture.md`](architecture.md) and [`AGENTS.md`](../AGENTS.md), and the diff.
- **What it does:** leaves one inline comment per blocker or major problem (minor ones stay in the summary), labels the pull request `risk:*`, `complexity:*` and `type:*`, and writes one summary comment with what the run cost. The check fails when it finds a blocking problem.
- **When it runs:** the whole change on the first push; on later pushes only what changed since its last look. Editing the title or description does not trigger a review. After five automatic reviews the last verdict stands until the `review:full` label asks for another.
- **What it cannot do:** lower the risk that [`.github/review-policy.yml`](../.github/review-policy.yml) sets from the changed paths (sign-in, two-step, passkeys, social login, registration, reset, sessions, the client's address, networks, privacy, the activity log, fields, the templates, the main file and `uninstall.php` are always high risk), push code, or merge.
- **Who posts:** the `dilux-bot` GitHub App. Mention `@dilux-bot` in a thread or in the conversation and it answers there.
- **Model:** chosen by risk, from the organisation's default policy and this repository's `.github/review-policy.yml`, read from `main` so a change cannot pick its own reviewer.

Pull requests from forks are not reviewed automatically: the review runs with the organisation's keys, and no code from outside the repository ever runs with them.

## What merges on its own

A pull request merges without a human only when all of these hold: the policy has `auto-merge` on, the changed paths are all low risk (docs, tests, translations and similar), the review rated it low risk and low complexity without blocking, and the author is trusted. Everything else is merged by the maintainer.

## Issues

When an issue opens, Claude classifies it from the README, `readme.txt` and the docs: a bug to reproduce, a report missing information, something that works as documented, a feature request, a usage question, a duplicate or a vulnerability posted in public. It applies one label and posts one reply; it never closes an issue, never promises a fix and never gives a date. A bug report with enough detail gets a reproduction attempt as a unit test under `tests/Unit/Repro/`, run on a runner with no secrets.

## What is never automated

- **Submitting and releasing.** The first submission to wordpress.org is a zip the maintainer uploads; every release after it is a deployment only the maintainer approves ([`release.md`](release.md)). No agent tags, touches SVN or uploads anything.
- **Merging anything that is not low risk.** A human decides.
- **Changing the review rules.** Every change goes through a pull request the maintainer approves.
- **Closing a bug or shipping a fix.** The triage labels and asks; a person decides.

## Rules for contributing with AI

Use any tool you like. These rules apply the moment you open a pull request, whether or not you read them.

1. **You sign the commit, you own the code.** Whoever or whatever wrote it, a regression traced to your commit is yours to fix. "The AI wrote it" is not a defence.
2. **You read what you commit**, line by line. If you do not understand a generated chunk, do not push it.
3. **The tests pass because the test runner says so**, not because the model said so. Run `make check` and the suites for the layers you touched before pushing.
4. **No secrets into AI services.** No `wp-config.php`, OAuth client secrets, SVN passwords, database dumps or anybody's personal data. If you slip, rotate the secret at once.
5. **No prompt injection** in commit messages, comments, code or docs: nothing that tries to steer a reviewer's tooling. A pull request that does this is rejected on sight.
6. **Verify what the model invents.** A WordPress function, a WebAuthn field or an OAuth endpoint that does not exist usually fails the build; the rest reaches production. Check the upstream docs.
7. **Same standards as hand-written code.** The linters do not care who typed it. If CI fails, fix the code, not the rule.
8. **Say that AI was involved**, in one line at the end of the description: `🤖 AI-assisted · <model> (<maker>)`, or `AI-generated` when the model wrote it and a person reviewed it. No apologies, no narration, no product advertising: CI rejects "Generated with …" footers, and rejects `Claude-Session:` trailers, which point at private conversations.
9. **One good pull request beats twenty speculative ones.** Pick a real bug, fix it well.

When an AI-assisted change causes a regression or a wordpress.org review failure: roll forward with a fix, open an issue saying why the checks did not catch it, and strengthen the gate that should have.
