# Releases: how a change becomes a version

For everyone who touches this repository: outside contributors, maintainers and coding agents. End users will get the plugin from wordpress.org and never need this page. The short, enforceable version of these rules is in [`AGENTS.md`](../AGENTS.md).

## Where it stands

DiluxOne Users+ is at **1.0.0 and has not been published**. The first submission to wordpress.org is pending, so there is no SVN repository, no release tag and no release yet. The three version markers are already at `1.0.0`: the `Version:` header and the `DILUXONE_USERS_VERSION` constant in `diluxone-users.php`, and `Stable tag:` in `readme.txt`, whose changelog has one entry, `= 1.0.0 =`.

Two things change how releases work, and this page says which rule applies when:

1. **The move** of the repository to the DiluxOne organisation, where it takes the shared workflows every DiluxOne plugin uses.
2. **The approval** of the plugin by the wordpress.org Plugin Review team, which creates the SVN repository a release is published to.

Until both have happened, only [The first submission](#the-first-submission) applies.

## Versions

[Semantic Versioning](https://semver.org/) for the plugin's public version: a fix is a patch (1.0.0 → 1.0.1), new functionality a minor (1.0.0 → 1.1.0), a breaking change a major (1.0.0 → 2.0.0). Changes that do not reach users (docs, tests, CI, tooling) release nothing on their own; `.distignore` keeps them out of what ships.

No pull request moves the version markers. Before the approval there is nothing to move them to; after the approval the version is computed from labels (below) and stamped by the release job.

## The first submission

The first version is not published by a tag. The maintainer uploads a zip, the Plugin Review team reads the code, and only their approval creates the plugin's SVN repository.

1. **Everything green on `main`**: `make check`, `make test-unit-min`, `make env-multisite && make test-integration`, `make test-e2e`, `make test-e2e-network`, `make i18n-check` and `make plugin-check` with no errors. Plugin Check is the tool the review team runs.
2. **Build the zip from a clean checkout of `main`**, not from a working tree with local changes, so what is reviewed is what is in the repository:
   ```bash
   make zip   # build/diluxone-users.zip, the dist under the slug
   ```
3. **Upload it** at <https://wordpress.org/plugins/developers/add/>. Only the maintainer does this, never an agent.
4. **Answer the review.** Every point the team raises is fixed in a pull request like any other change, and the next zip is built again from `main`. Their notes are not argued away: if a point does not reproduce, the answer explains what was checked.
5. **Do not push tags** while the review is open. There is no SVN to publish to, and a tag `X.Y.Z` is permanent.

### When the approval arrives

- **Check the slug wordpress.org assigned** before anything else. It is proposed from the plugin name, and the code assumes `diluxone-users`: the text domain must equal the slug or translate.wordpress.org's language packs never reach a site, and a slug cannot be changed once published. If it differs, stop and settle that first.
- **Publish 1.0.0** from the approved commit, with the markers already at `1.0.0`, by a tag `1.0.0` the maintainer pushes, which runs the shared release job ([`release.yml`](../.github/workflows/release.yml)); it accepts an administrator's tag only as the version it computes. The job starts as a rehearsal (`dry-run: true`): the maintainer turns it off, in a pull request of its own, after a rehearsal approved in the environment. The SVN credentials and the release App's key are already in the `wordpress-org` environment.
- **Open the next changelog entry** in the next change that deserves one, `= X.Y.Z =` with `Unreleased.` as its first line (below).

## After the approval

From then on the plugin follows the organisation's release flow, the same one [DiluxOne Offload](https://github.com/DiluxOne/diluxone-offload-wordpress/blob/main/docs/release.md) uses: the repository lives in DiluxOne, calls the shared workflows, and has the settings the organisation's [adoption guide](https://github.com/DiluxOne/.github#adopt-it-in-a-new-repository) lists.

### Who does what

| | Outside contributor | Maintainer | The pipeline |
| --- | --- | --- | --- |
| Writes the change and its changelog bullet | yes, in the pull request | yes | never |
| Decides the type of the change (`type:*` label) | no | can override with a label | the Claude review, from the diff |
| Computes the next version | no | no | yes, from the labels |
| Decides that a version is ready | no | yes, by removing one line in `readme.txt` | never |
| Approves the publication | no | yes, in the `wordpress-org` environment | never |
| Stamps, deploys, tags, creates the release | never | never | yes, after the approval |

### The flow

1. **A pull request merges into `main`**, squash-merged, labelled `type:*` by the review.
2. **The push to `main` runs the release workflow**, which computes the next version from the `type:*` labels of everything merged since the last release tag: `type:breaking` → major, `type:feat` → minor, `type:fix` or `type:perf` → patch; a maintainer's `version:major|minor|patch` label wins.
3. **A development build is published**, every time: the shipped tree stamped `<next>-dev.<N>`, as the one **Development build** pre-release in Releases (tag `dev`, replaced on every push, never "Latest"). `make dist` builds the same locally. Nobody creates, moves or deletes the tag `dev` by hand.
4. **The readme says whether the version is ready.** While the newest `= X.Y.Z =` entry of `readme.txt` starts with the line `Unreleased.`, the run ends green as **Not ready** and nothing waits for anyone.
5. **The maintainer removes the `Unreleased.` line** in a pull request that contains nothing else. That is the release decision.
6. **The maintainer approves the deployment** in the `wordpress-org` environment. Reject, and nothing happens.
7. **The job publishes**: stamps the markers in its checkout (never in the repository), commits to the wordpress.org SVN, creates the tag `X.Y.Z` and the GitHub release. `main` keeps the released version in its markers; nothing is bumped back.

### The changelog is the release switch

```
= 1.1.0 =
Unreleased.

* What changed, for the person using the site.
```

- **Write the notes as the changes merge.** A pull request that changes what a user sees adds its bullet under the `Unreleased.` line, in the same pull request.
- **Never remove the `Unreleased.` line as part of another change.** The pull request that removes it is the release decision, made by a maintainer.
- **After a release**, the next change that deserves a bullet opens the next entry above the released one.

### Secrets and approval

The publication's secrets live in the repository environment `wordpress-org`, never as repository or organisation secrets: `SVN_USERNAME`, `SVN_PASSWORD` (the SVN-specific password, not the login one) and the release App's key and client id. Its required reviewers are the approval. After rotating the SVN password, the **SVN credentials check** workflow proves it without committing anything.

## Release tags are permanent

A tag `X.Y.Z` is the record of what went to every WordPress site. Two rulesets let only an administrator or the release App create one, and nobody delete or move it. So:

- **A deploy that failed before SVN** (a secret, a network error): fix the cause and re-run the job.
- **A tag on the wrong commit, or with misaligned markers**: the tag stays. Fix it in a pull request and release the next patch.
- **Never push a tag shaped like a release** (`1.2.0-rc1`, `v1.2.0`) to try something.

## Rolling back

There is no undo on wordpress.org: once a version is on the SVN, it is there. To roll back, ship `X.Y.Z+1` with the previous version's code. On the GitHub side a release and its tag can be deleted only if the SVN tag did *not* go out.

Before any release, `make release` on `main` runs the fast gates and the marker alignment locally.
