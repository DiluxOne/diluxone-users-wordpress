# End-to-end tests

The suites below this one prove that the functions behave. These prove that a
person can get in — which is a different claim, and the only one that covers
the parts nothing else can reach: a form that posts to `admin-post.php` and
comes back as a redirect, a credential that arrives by e-mail, a cookie that
only exists once a real browser has been handed one, and a `required` field
that stops a button from ever submitting.

## Running them

```bash
make env        # once: the wp-env stack (WordPress at http://localhost:8892)
make test-e2e   # the suite
make test-e2e-ui  # the same, in Playwright's own window, for writing one
```

The first run on a machine also needs the browser:

```bash
npx playwright install chromium
```

Nothing else. `npm install` brings `@playwright/test`; `make env` brings
WordPress; the rest of what the suite needs lives in the repo.

Point it somewhere else with `WP_BASE_URL`, and give it another
administrator with `WP_USER` / `WP_PASS`:

```bash
WP_BASE_URL=http://localhost:8892 WP_USER=admin WP_PASS=password make test-e2e
```

Run one file, or one test:

```bash
npx playwright test magic-link
npx playwright test -g "the second time it is spent"
```

### On a network

```bash
make env               # once, as above: it also brings the tests site (8893)
make test-e2e-network  # converts the tests site into a subdirectory network, then runs network/
```

`make test-e2e-network` is `make env-multisite` followed by
`npx playwright test -c playwright.network.config.ts`. The conversion is
idempotent and puts the network's `.htaccess` back every time, since anything
that flushes permalinks writes the single-site one. Point it at another network
with `WP_NETWORK_URL`. Traces land in `build/e2e-network-results/`.

A failed run leaves a trace in `build/e2e-results/`. Open it with
`npx playwright show-trace build/e2e-results/<...>/trace.zip` and you get the
browser back, frame by frame.

## What has to be up

- **wp-env**, on port 8892 (`make env`). The dev site mounts the repo root, so
  what runs is the working tree.
- **The e2e mu-plugin**, which `.wp-env.json` maps into `wp-content/mu-plugins/`
  from `tests/e2e/mu-plugin/diluxone-e2e.php`. If you change `.wp-env.json` you
  have to `npx @wordpress/env start` again for the mapping to take. The mapping
  is a single-file bind mount, so an editor that saves by writing a new file
  and renaming it over the old one leaves the container looking at the old
  file: `npx wp-env stop && npx wp-env start` after editing it.
- For the network suite, the tests site on port 8893 as a network
  (`make env-multisite`, which `make test-e2e-network` runs), and WP-CLI
  through `npx wp-env run tests-cli` — the setup makes its sites with it.
- Nothing else. No mail server: the mu-plugin catches the mail. No OAuth
  credentials: the mu-plugin answers as the provider. No HTTPS: `localhost`
  counts as a secure context, which is what passkeys need.

## How it is put together

```
tests/e2e/
  global.setup.ts      seeds the pages, writes down every setting it changes,
                       and keeps one administrator session for the specs that
                       need the dashboard
  global.teardown.ts   puts those settings back and deletes every account the
                       run made
  mu-plugin/           the three things a browser cannot do on its own
  support/             the REST side door, the locators, a TOTP app, the
                       registry of screens, the layout measurements, the
                       baseline settings and WP-CLI for the network suite
  specs/               the flows, and the two suites that look at the screens
  network/             the network suite: its setup and teardown, its fixtures
                       (one handle per site) and its specs
  COVERAGE.md          every feature and state, and the test that covers it
  snapshots/           one picture per tab, the baseline the pictures compare
                       against
```

### The three kinds of spec

- **The flows** — sign-in, registration, the second step, passkeys, social,
  the settings that change the public page. A person doing something, and
  what the site does about it.
- **The measurements** (`specs/admin-layout.spec.ts`, with `support/layout.ts`)
  — every tab of every screen, at 1600, 1280, 960 and 782 pixels: nothing
  overlapping, nothing past the edge, no two blocks touching, no bordered box
  with nothing in it, and the rail beside the settings rather than under them.
  They need no baseline and run with everything else.
- **The pictures** (`specs/admin-snapshots.spec.ts`) — one photograph per tab,
  compared with the one in `snapshots/`. Its own Playwright project and its
  own target, `make test-visual`, because a baseline image belongs to the
  machine that took it. `make test-visual-update` is how you accept a change
  you meant to make.

Every one of those three walks the same list of screens,
`support/screens.ts`. Add a tab there and all three cover it — and if you
forget, the measurements read the tab strip each screen draws and fail on a
tab that is not in the list.

See [docs/testing-and-quality.md](../../docs/testing-and-quality.md) for what
each rule means and why the pictures are not in CI.

### The mu-plugin

`tests/e2e/mu-plugin/diluxone-e2e.php` loads only when
`wp_get_environment_type()` is `local`, and every route but one asks for the
`X-Diluxone-E2E` header. It gives the suite four things:

- **A mailbox.** `pre_wp_mail` keeps each message instead of sending it, and
  `GET /wp-json/diluxone-e2e/v1/mail?to=…` hands them back. That is how a test
  reads a sign-in link or a six-digit code.
- **A social network.** A provider called `mock` is added to the plugin's
  table, its authorize screen is a REST route that bounces straight back with
  a code, and its token and profile endpoints are answered in-process through
  `pre_http_request`. A test says what the provider claims — including
  `email_verified: false` — with `POST /identity`.
- **Settings, accounts and the clock.** `POST /options` writes settings *and
  answers with what they were*, which is the whole restore contract;
  `POST /user` makes somebody; `POST /expire` moves a deadline into the past so
  expiry is tested in a second rather than in fifteen minutes.
- **The pages.** `POST /seed` makes the three pages the shortcodes live on —
  `e2e-login`, `e2e-register`, `e2e-account` — once, and reuses them after
  that. `POST /menu` makes a classic menu in a location of the mu-plugin's own
  and a page, `e2e-menu`, that draws it with `wp_nav_menu()`: the wp-env theme
  is a block theme, and the plugin's menu items only reach classic menus.
- **Networks.** Every route answers on a site of a network at that site's own
  address (`/alpha/wp-json/…`), with that site's options and mailbox. The user
  read says whether the person is a member of this site (`member`) and of
  which ones (`sites`); deleting uses `wpmu_delete_user()`, because on a
  network `wp_delete_user()` only takes somebody off one site.

The one open route is `/oauth/authorize`: the browser walks into it following
a plain redirect and can carry no header there. It signs nobody in; it
redirects back with a code.

### Leaving the site as it was found

This runs against somebody's development site, not a scratch one, so nothing
is ever wiped. Two mechanisms, and between them a run leaves no trace:

- `global.setup.ts` writes a baseline (which pages, which sign-in method, and
  so on) and saves the previous values to `build/e2e-baseline.json`;
  `global.teardown.ts` writes them back. Playwright runs a project's teardown
  even when the project failed, which is exactly when you would least like to
  be left on "only a link, no passwords".
- Inside a test, the `options` fixture is the only way to change a setting:
  `options.set({…})` writes and schedules the old value to be written back when
  the test ends, pass or fail. For the tests that change a setting by pressing
  **Save** on a dashboard screen — where nothing can intercept the write —
  `options.keep([…])` writes the current values down beforehand.

Every account the suite makes carries the `@e2e.test` domain, and the teardown
deletes all of them. Nothing else on the site has that domain.

### Writing a test here

- **Locators point at markup, never at sentences.** The site this runs against
  is in Spanish and the plugin ships eight locales. `support/ui.ts` holds the
  selectors; where a test does have to prove that a particular message
  appeared, it checks the class the template gives it (`--error`, `--ok`) and
  the `diluxone-users` state in the address, which is the plugin's own name for
  what happened and does not translate.
- **No fixed waits.** `submitPluginForm()` waits for the answer's address;
  `waitForMail()` polls the mailbox; everything else is `expect`.
- **Use `guest` for the public page** in a spec that has an administrator
  session. The sign-in and registration shortcodes draw nothing for somebody
  already signed in, so the same browser cannot do both.
- **Pin what the test depends on.** `register.spec.ts` pins the whole field set
  rather than inheriting whatever the development site has configured; a
  required field it did not expect stops the browser submitting the form and
  the test hangs on a navigation that never comes.

## What is covered

| Spec | The flow |
|---|---|
| `magic-link.spec.ts` | Ask for the link, read the mail, open it, be signed in; the second time it is spent; an expired one; a token pointed at another account; an unknown address with registration open and with it closed; the site's own wording; the throttle. |
| `password-login.spec.ts` | The three answers to "how do people get in" — `both`, `password`, `link` — plus **H-05**: with the screens taken over and a password still a way in, the POST to `wp-login.php` has to go through. The escape hatch, and signing out. |
| `register.spec.ts` | The site's own form with a required field, the link, the answers already saved; an address already taken; registration closed; the six-an-hour burst. |
| `password-reset.spec.ts` | The three answers to "where is the new password typed" — `wp`, `site`, `link`. For `site`, the whole chain: WordPress's link, the hand-off, the key into a cookie and out of the address, the mismatch, the change, the new password working. |
| `two-factor.spec.ts` | The code by e-mail and the authenticator app. The five-try limit, the sixty-second resend wait, the expired attempt, "do not ask again on this browser", the backup codes, and the e-mail link *not* asking for a code that goes to the same inbox. |
| `sso.spec.ts` | A new social account, one that matches an existing address, and **H-01**: an unverified address must not be handed an existing account. Silence treated as silence. "Verified only". Cancelling at the provider. Linking and unlinking. **H-02**: a link trip with no nonce. A forged `state`. |
| `admin-settings.spec.ts` | Every tab of every settings screen renders with no PHP notice and no footer riding up into the layout; and eight settings changed by pressing Save, each checked on the public page afterwards — including **M-10**, the legal line keeping its link. |
| `passkeys.spec.ts` | Register a passkey from the account screen and sign in with it, against Chrome's virtual authenticator; and a key the account has removed no longer opening it. |
| `account-area.spec.ts` | The account area as its owner: the guest screen, the menu, the sections that exist only while offered, details and a required field, the photo and its attachment, the public name, the new-device notice and its switch, export and erasure requests, two-step on and off, the app removed with a backup code, new backup codes, closing browsers. |
| `site-menu.spec.ts` | The person in the site's own menu, on the mu-plugin's classic menu: the stranger's sign-in item, the person's sections and sign-out, the three styles, and the tab that saves them. |
| `admin-effects.spec.ts` | The rest of the dashboard's saves, each checked on the public side: wp-login.php branding, photos, initials, the registration heading, the account menu down the side, the toolbar, profile.php, the sessions list, notice rules, a provider switched off, and what happens to wp-login.php. |
| `admin-tools.spec.ts` | Status › Tools (export, import, close sessions, fresh code, rebuild, test message), Reports › Sessions and Activity, WordPress's Users screens and Add New User, a field added and deleted, a section of the site's own. |
| `network/*.spec.ts` | Two sites of one network: each site's door, membership only by using a link, the network's registration setting, settings per site, the sessions report per site, Add New User, the photo across sites, two-step per site, a social identity across sites, a site born after activation, deactivating and activating, WP-CLI. |
| `login-ways.spec.ts` | The four ways in as one screen: with all of them on, the sign-in card fits a 1366×768 laptop in tabs and does not stacked — both halves, so the measurement cannot pass on the broken arrangement. The passkey staying above the strip. The tab that opens: the site's choice for a stranger, the cookie after that, and the way in that just failed over both. The site's order, in tabs and stacked. Every way in visible with JavaScript off. And the dashboard end: the order saved from the drag list and read back off the public page. |

A test that fails today on a product bug says so in its assertion message and
is listed as **bug** in [COVERAGE.md](COVERAGE.md). It stays red until the
product is fixed; it is not loosened and not marked `fixme`.

### Skipped, and why

- `passkeys.spec.ts` skips itself on anything that is not Chromium: the virtual
  authenticator is a DevTools-protocol feature and Firefox and WebKit have no
  equivalent. On Chromium it runs in full — `http://localhost` is a secure
  context, so no HTTPS is needed.

### What this suite already changed

It was written to describe the plugin, and describing it found three things it
did not do and one it did by halves. The fourth is worth naming here because
the test came first: a registration form submitted with `novalidate` used to
create the account with the required field empty —
`diluxone_users_register_request()` called `diluxone_users_save()`, which
reports what was missing, and ignored the answer. It is now asked before the
account exists, by `diluxone_users_register_missing()`, and the test asserts
the refusal instead of recording the hole.
