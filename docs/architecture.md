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
| Options, their defaults and where each is stored on a network | `options.php`, `options-scope.php` |
| User fields (definition, values, edit policy) | `fields.php`, `fields-forms.php` |
| Account area, sections registry | `account.php`, `account-sections.php`, `account-security.php` |
| Sign-in: link, password, passwordless mode, the ways in | `login.php`, `login-ways.php`, `login-messages.php`, `passwordless.php` |
| Registration and password reset | `register.php`, `reset.php` |
| Two-step verification | `auth.php`, `auth-email.php`, `auth-totp.php` |
| Passkeys (WebAuthn) | `auth-passkeys.php` |
| Social login | `sso*.php` |
| Sessions, the client's address | `sessions.php`, `client-ip.php` |
| Activity log | `log.php`, `log-events.php`, `log-privacy.php`, `admin-log.php` |
| Networks: membership, the network's screens, the move of a network's settings and of each site's log | `multisite.php`, `admin-network.php`, `migrate.php`, `migrate-log.php` |
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
- **On a network the plugin is activated for the whole network, or it does nothing.** The header says `Network: true`, so WordPress offers only "Network Activate" and activating it from a site activates it for the network. The main file reads `includes/network-gate.php` first: `diluxone_users_awake()` is true on a single site and, on a network, only when the plugin is in the network's active plugins. A site it was left on for alone (an activation from before this rule, a copy dropped in by hand) loads nothing — no screens, no shortcodes, no sign-in — and shows whoever can `manage_network_plugins` a notice to activate it for the whole network, in Network Admin when the main site is one of those sites and on the dashboard of each of them. The activation hook is registered before the gate and loads the plugin itself, because WordPress runs it before it records the network activation. There is no per-site way of working on a network: each setting lives where its scope says (see Data below), the network's settings are set in Network Admin and nowhere else, and the main site is the hub whose sign-in, registration and account pages the network uses. Users are network-wide, so anything that gives access calls `diluxone_users_join_site()`.
- HTTP calls use `wp_remote_*` with an explicit `timeout`. Never raw cURL.
- Every `.php` file starts with `defined( 'ABSPATH' ) || exit;`.
- **PHP 8.0 and WordPress 6.2** are the minimums. No Composer dependencies at runtime.

### Data

- Renaming an option or a user meta key **requires a migration**, in a file of its own, run once and marked as done. There are two in the tree, both for networks: `includes/migrate.php`, the move of each site's copy of the network's settings to the network, and `includes/migrate-log.php`, the move of each site's activity log into the network's table (see below).
- **Every stored setting has a scope** in `includes/options-scope.php`: `network` (who gets in and how safely: the second step, passkeys, sessions, the proxy, the social credentials and linking rules, the fields, the log's retention, the wipe), `hub` (the screens people sign in, register and keep their account on, and how they look and what they say) or `site` (the plugin's bookkeeping about the site it runs on). The activity log's own bookkeeping — the shape of its table, the move of the sites' old tables — is `network`, because on a network the table is the network's. The map is explicit, key by key, and a unit test fails when a stored setting has no line in it. Settings are read and written only through `diluxone_users_option()`, `diluxone_users_raw_get()`, `diluxone_users_update_option()`, `diluxone_users_delete_option()` and `diluxone_users_save_options()`, never with `get_option()` and company on the plugin's own keys (the exceptions are `migrate.php`, `migrate-log.php` and `uninstall.php`, whose job is to see both places). On a network (`diluxone_users_scoped_storage_active()`, which is `is_multisite()`), a `network` setting is in the network's options (`get_site_option()`), a `hub` setting is in the hub's options table and read from any other site once per request, and a `site` setting stays with its site; on a single site everything is in the site's table. What fits into each site's own theme — the menu the account link goes in, the admin bar, the dashboard profile — is `site`. A hub setting that holds an id (the sign-in, registration and account pages; the pictures) is an id on the hub: `diluxone_users_page_url()` and `diluxone_users_hub_image_url()` resolve it there, and `diluxone_users_page_here()` is 0 on any other site, so another site never draws or routes one of its own pages as if it were the hub's.
- **Who writes what, from where.** `diluxone_users_admin_context()` says where the admin is looked at from — `single`, `network` (Network Admin), `hub` or `site` — and `diluxone_users_save_options()` writes only the settings that context owns (`diluxone_users_option_editable_here()`): Network Admin writes the network's, the hub its own and the site's, another site only its own. Every screen's save goes through it, so a site administrator cannot change or loosen a network setting by sending a form by hand. The screens follow the same map (`admin-network.php`): each screen and tab has a scope, the network's are drawn in Network Admin with `manage_network_options` and leave every site's menu, and a site's Overview says where each area went.
- **The move to the network** (`includes/migrate.php`, marker `diluxone_users_network_version`). On the first request after the plugin routes by scope — or with `wp diluxone-users network migrate` — each network setting the network does not have yet is taken from the main site; the other sites are compared in batches of 100 (cron after the first), and what differs is written down in `diluxone_users_network_conflicts` (credentials only as "differs", never their value) and shown once as a Network Admin notice, and for good on the network's Overview. The user fields are the union of every site's, by key, the main site's definition winning. The wipe on uninstall is not carried over: it starts off. The old per-site copies stay until uninstall. Every step checks before it writes and the marker is written last, so it can run again.
- **The activity log on a network** (`includes/log.php`). On a network the log is one table, `{$wpdb->base_prefix}diluxone_users_log`, and every row carries `site_id` (indexed `site (site_id,id)` and `site_when (site_id,happened)`); its shape marker `diluxone_users_log_schema` is a network setting, and the table is made once for the network by the network's activation (and, as a safety net, by the first read or write after an update) — `diluxone_users_site_setup()` makes no table on a network. A single site has the same name — its prefix is the base prefix — and the same columns: one writer, one set of queries, the site being one more filter (`diluxone_users_log_search()`'s `site`, built into the WHERE only when set). Any read or write first checks the shape (`diluxone_users_log_current()`) and brings a table a shape behind up to date, so an update over FTP never queries a column that is not there yet. A site's report and its "Empty it now" are that site's rows; Network Admin › Activity log has every site's rows, a Site column and filter, and an "Empty it now" for every row (`manage_network_options`). The daily purge runs on the main site only, with the network's retention, over every row of the network's sites. Erasing a person is one query on the table; taking somebody off one site (`deleted_user` on a network) takes only that site's rows; a deleted site takes its rows (`wp_uninitialize_site`) and any old table of its own (`wpmu_drop_tables`, never the network's). One table serves every network of a multi-network install, so whatever a network does to its rows it does to its own sites' rows only (`diluxone_users_log_network_sites()`, the current network's site ids through `get_sites()`, used in groups of 500 for an `IN ( … )`): its report and its Site filter, its "Empty it now" and its purge never touch another network's rows. Erasing a person is the exception and stays installation-wide, because a person is.
- **The move of each site's log** (`includes/migrate-log.php`, marker `diluxone_users_log_moved`, state `diluxone_users_log_moving`). Run by the network's move on `init` (one batch) and then cron (`diluxone_users_log_move`, ten batches a run), or to the end by `wp diluxone-users network migrate`. Site by site in id order, 5,000 rows a batch, each batch copied with `INSERT … SELECT` and recorded as copied in one transaction, so a batch that fails is copied again and never twice. An old table is dropped only when its row count equals the rows copied; otherwise it stays and is written down in `diluxone_users_log_kept`. The first site's table needs no move — its prefix is the network's — and its rows from before `site_id` are stamped as its own.
- Field keys are user-visible configuration: once a field exists, its key does not change, because the key is also the meta key holding everybody's answer.
- `uninstall.php` wipes only when an administrator asked for it beforehand (Maintenance → Tools on a single site; Network Admin → Overview → Deleting the plugin on a network); otherwise deleting the plugin keeps people's data. On a network it is that one decision: ticked, the network's options and its activity log, every site's settings (the old per-site copies included), any old per-site log table left, and the plugin's user meta go. A network whose settings never moved (no `diluxone_users_network_version`) is decided by the same network box, and a box a site ticked for itself before the move never counts.

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
