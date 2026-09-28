# Architecture

How DiluxOne Users+ is built, the rules its code follows, and what a review of it looks for. This is the single source: people read it, coding agents read it (through [`AGENTS.md`](../AGENTS.md)), and the automated Claude review reads it on every pull request. When the code changes one of these facts, this file changes in the same pull request.

When in doubt, prefer the project's existing patterns over textbook WordPress patterns.

## What this repo is

A WordPress plugin that owns everything about the people who use a site: the fields they are asked for, how they sign in, the account area they see on the front end, their second factor, their passkeys, their sessions and their data. It depends on no other plugin.

**The distinguishing decision** is that the plugin knows nothing about the site it runs on. It does not know what a course is, or a membership, or a forum. Anything that belongs to another domain enters through a filter (`diluxone_users_sections`, `diluxone_users_summaries`, `diluxone_users_notification_prefs`) or through a shortcode pasted into a section. A pull request that teaches the plugin about LifterLMS, WooCommerce or bbPress is going the wrong way; the only exception is a `function_exists()`-guarded bridge, and there is exactly one today (`bbp_get_user_profile_url()` in `includes/handle.php`). The seams an add-on uses are public API, listed in [`extending.md`](extending.md).

The second decision worth knowing: **what an administrator turns off disappears from the front end, with no second switch to remember.** Sections declare an `available` callback; with no social provider enabled there is no "Linked accounts" section at all. A feature added without that wiring will show an empty screen to somebody.

Paid subscriptions are not part of this plugin. They drag in a payment gateway, recurring billing and invoicing, and will arrive as a separate add-on, so a free site does not carry billing code it never runs.

---

## Architecture quick-reference

Procedural, no classes, no namespace, everything prefixed `diluxone_users_` / `DILUXONE_USERS_`. Every file in `includes/` is independent and only registers hooks; they are loaded in alphabetical order by a `glob()` in the main plugin file, so **nothing may depend on load order**: if a file needs another to have run, that is a hook, not an ordering assumption.

| Area | Files |
|---|---|
| Main file: constants, the loader, activation | `diluxone-users.php` |
| Options and their defaults | `options.php` |
| User fields (definition, values, edit policy) | `fields.php`, `fields-forms.php` |
| Account area, sections registry | `account.php`, `account-sections.php`, `account-security.php` |
| Sign-in: link, password, passwordless mode, the ways in | `login.php`, `login-ways.php`, `login-messages.php`, `passwordless.php` |
| Registration and password reset | `register.php`, `reset.php` |
| Two-step verification | `auth.php`, `auth-email.php`, `auth-totp.php` |
| Passkeys (WebAuthn) | `auth-passkeys.php` |
| Social login | `sso*.php` |
| Sessions, the client's address | `sessions.php`, `client-ip.php` |
| Activity log | `log.php`, `log-events.php`, `log-privacy.php` |
| Networks | `multisite.php` |
| Privacy (export and erasure): confirmed signed in, carried out on confirmation, its e-mails | `privacy.php`, `account-confirm.php`, `account-export.php`, `account-closing.php`, `account-mail.php` |
| Notifications and mail | `notify.php`, `mail.php`, `mail-templates.php` |
| Admin screens | `admin*.php` |
| Removal of everything the plugin stored | `uninstall.php` |

Templates live in `templates/` and are overridable from the active theme at `wp-content/themes/<theme>/diluxone-users/<path>.php`, resolved by `diluxone_users_template()`. Styling is driven by `--diluxone-users-*` custom properties so a site can restyle the plugin by redefining values, without copying its stylesheet ([`extending.md`](extending.md#styling-it-from-a-site)).

---

## Hard rules

### Security (highest priority)

- **All AJAX endpoints** verify a nonce (`check_ajax_referer`) and, when the action is not part of signing in, that there is a session. The two passkey login steps deliberately do not require a session: they exist to open one.
- **All `$_POST` / `$_GET` input** is unslashed and sanitized: `sanitize_text_field( wp_unslash( $_POST['x'] ?? '' ) )`, `sanitize_key`, `sanitize_email`, `wp_kses_post` for HTML. Raw superglobals are a defect.
- **All output** is escaped: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`. A template that echoes a variable unescaped is a defect.
- **All SQL** uses `$wpdb->prepare()`, with no exceptions. Where a table name has to be interpolated it comes from `$wpdb->prefix` and nowhere else.
- **Secrets are never logged and never stored in the clear.** Two-step codes, backup codes and the sign-in token are stored hashed (`wp_hash`). A pull request that stores any of them readable is a defect, not a simplification.
- **A single-use thing must be single use.** Sign-in links, two-step codes, backup codes and WebAuthn challenges are consumed on first use. Removing the delete-after-use is a security regression even when it "fixes" a retry.
- **A social identity is handed an existing account only on the provider's explicit "verified".** Silence is not a yes there, whatever the settings say; for a new account an explicit "no" closes the door and the "verified only" setting decides what silence means (`diluxone_users_sso_email_trusted()`).

### Coherence: the rule that is specific to this plugin

- Anything shown to a person must be **true at the moment it is shown**. If a notice says the second step is not being asked for, that has to hold for every way into the site the administrator left open, not just the one the author had in mind. `diluxone_users_2fa_ways()` exists because that notice was wrong.
- A new section declares `available` and `why` if it can ever have nothing to show.
- A new setting that hides something on the front end actually hides it. Half-applied settings are the failure mode this plugin is trying to avoid.

### WordPress conventions

- All user-facing strings go through translation functions with the text domain `diluxone-users`, with a `/* translators: */` comment on the line right before any placeholder. Eight locales are kept complete in `languages/`.
- Multisite-aware: configuration is per site. Users are network-wide, so anything that gives access calls `diluxone_users_join_site()`.
- HTTP calls use `wp_remote_*` with an explicit `timeout`. Never raw cURL.
- Every `.php` file starts with `defined( 'ABSPATH' ) || exit;`.
- **PHP 8.0 and WordPress 6.2** are the minimums. No Composer dependencies at runtime.

### Data

- Renaming an option or a user meta key **requires a migration**, in a file of its own, run once and marked as done. There is none in the tree: 1.0.0 is the first version, so there is no earlier shape to come from, and a migration for a state no site can be in is dead code.
- Field keys are user-visible configuration: once a field exists, its key does not change, because the key is also the meta key holding everybody's answer.
- `uninstall.php` wipes only when an administrator asked for it beforehand (Maintenance → Tools); otherwise deleting the plugin keeps people's data. On a network it runs per site, and the shared user meta goes only when every site that used the plugin asked.

---

## Deliberate style choices

Do not comment on these in a review.

- **Yoda conditions** in comparisons against literals (`'' === $value`), following WPCS.
- **Procedural code with a `diluxone_users_` prefix** is the convention. Do not suggest wrapping it in classes.
- **Comments explain decisions, not mechanics.** A comment that says what the next line does is noise; one that says why the obvious alternative was rejected stays.
- **Native types instead of `@param` lines.** The signatures are typed; the docblock explains why the function exists and documents array shapes in `@return`. The PHPCS configuration relaxes that rule on purpose.

---

## What a review looks for

- **Missing escaping on output**, especially in `templates/`.
- **Missing unslash + sanitize on input**, especially in new admin handlers.
- **A single-use token that stops being single use**, or a secret stored or logged in the clear.
- **A new screen or panel that shows something the settings say is off.**
- **A new section without `available` / `why`** that can render empty.
- **A new option that no screen exposes**, or a screen control that saves nothing.
- **Renaming a stored key without a migration.**
- **Anything that makes the plugin depend on another plugin** without a `function_exists()` guard.
- **New strings not wrapped in a translation function.**
- **`$wpdb` queries inside loops**: suggest batching.
- **A change without its tests** at the layers it touches ([`testing-and-quality.md`](testing-and-quality.md)).

The paths where a mistake locks people out, lets the wrong person in or leaks their data are listed in [`.github/review-policy.yml`](../.github/review-policy.yml): a change there is always high risk and always merged by a person.

## Where the other rules live

| Rules | File |
| --- | --- |
| Branches, titles, pull requests | [`CONTRIBUTING.md`](../CONTRIBUTING.md) |
| The short list an agent must follow | [`AGENTS.md`](../AGENTS.md) |
| Quality gates and tests | [`testing-and-quality.md`](testing-and-quality.md) |
| Public hooks and CSS properties | [`extending.md`](extending.md) |
