=== DiluxOne Users+ ===
Contributors: pablodiloreto
Tags: users, login, passwordless, two-factor, passkeys
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom user fields, a front-end account area, passwordless sign-in, social login, 2FA, passkeys and session control.

== Description ==

Everything about the people who use your site, in one place.

* **User fields** defined from the dashboard: type, whether it is required,
  where it shows and who can change it, and how many times. WordPress's own
  first and last name follow the same rules.
* **An account area on the front end**: Home, Your details, Linked accounts,
  Security, Your data and Notifications, as tabs or a side menu. Sections can
  be renamed, reordered, turned off and added.
* **How people get in**: a link sent to their e-mail with no password, username
  and password, or both, plus control over WordPress's own registration and
  profile screens.
* **Social login** with twelve providers, a step-by-step guide for each console
  and a live test before one is turned on.
* **Two-step verification**: a code by e-mail, an authenticator app with a QR
  code, and backup codes, with a policy per role and per way in.
* **Passkeys** (WebAuthn), each one with a name of its own.
* **Sessions**: how long they last, where they are open, and how to close them.
* **An activity log** of its own: who signed in, who was refused and what
  changed. It records IP addresses; see Privacy below.
* **Privacy**: WordPress's own export and erasure requests answer for
  everything this plugin stores, and people can delete their own account.

== External services ==

The plugin talks to a third-party service only when an administrator has
entered that provider's credentials and turned it on, and only when somebody
clicks its button on the sign-in page (or an administrator runs its live test).
With no provider on, it makes no outbound request at all.

What is sent: the client ID and secret you registered with the provider, the
authorisation code the browser came back with and your site's redirect URL
(and, for X, a PKCE verifier); then the access token the provider issued, to
read the person's profile. What comes back: the person's identifier at that
provider, their name and their e-mail address (X does not provide one).
Nothing else about your site or its visitors is sent.

* **Google** — accounts.google.com, oauth2.googleapis.com, openidconnect.googleapis.com. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy)
* **Microsoft** — login.microsoftonline.com, graph.microsoft.com. [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)
* **LinkedIn** — www.linkedin.com, api.linkedin.com. [Terms](https://www.linkedin.com/legal/user-agreement), [Privacy](https://www.linkedin.com/legal/privacy-policy)
* **X (Twitter)** — twitter.com, api.twitter.com. [Terms](https://x.com/en/tos), [Privacy](https://x.com/en/privacy)
* **Facebook** — www.facebook.com, graph.facebook.com. [Terms](https://www.facebook.com/terms.php), [Privacy](https://www.facebook.com/privacy/policy)
* **GitHub** — github.com, api.github.com. [Terms](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service), [Privacy](https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement)
* **WordPress.com** — public-api.wordpress.com. [Terms](https://wordpress.com/tos/), [Privacy](https://automattic.com/privacy/)
* **Yahoo** — api.login.yahoo.com. [Terms](https://legal.yahoo.com/us/en/yahoo/terms/otos/index.html), [Privacy](https://legal.yahoo.com/us/en/yahoo/privacy/index.html)
* **Twitch** — id.twitch.tv. [Terms](https://www.twitch.tv/p/legal/terms-of-service/), [Privacy](https://www.twitch.tv/p/legal/privacy-notice/)
* **Discord** — discord.com. [Terms](https://discord.com/terms), [Privacy](https://discord.com/privacy)
* **GitLab** — gitlab.com. [Terms](https://handbook.gitlab.com/handbook/legal/subscription-agreement/), [Privacy](https://handbook.gitlab.com/handbook/legal/privacy/)
* **Amazon** — www.amazon.com, api.amazon.com. [Terms](https://www.amazon.com/gp/help/customer/display.html?nodeId=508088), [Privacy](https://www.amazon.com/gp/help/customer/display.html?nodeId=468496)

The settings screens link to each provider's console and docs (links, not requests).

**Gravatar** (Automattic) is WordPress's own avatar service; the plugin's switch
for it comes on. While on, browsers request avatars from gravatar.com, which
receives a hash of the e-mail address and the visitor's IP. Off, the plugin
draws them. Switch: **DiluxOne Users+ → Design → Profile photo**.
[Terms](https://automattic.com/terms/), [Privacy](https://automattic.com/privacy/)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Go to **DiluxOne Users+ → Access** and pick (or create) the sign-in page.
3. Go to **DiluxOne Users+ → Account area** and pick (or create) the account page.

Self-registration starts as your site had it (**Anyone can register**, or the
network's setting); change it on **Access → Registration**.

== Privacy ==

= What it stores =

In each person's profile: the answers to your fields, their public name and
picture, which social accounts are linked, their passkeys, whether two-step
verification is on, and a hash of each browser they signed in from (so "a new
device signed in" is said once).

In its own table, `{prefix}diluxone_users_log`: one row per event with the
date, the account, the **IP address** and the browser's user-agent. When a
sign-in is refused, the name typed in the username box is kept with it. Out of
the box it records signing in, signing out and refused sign-ins; other groups
and how long rows are kept (90 days to start) are set on
**DiluxOne Users+ → Reports → Log settings**.

Cookies: short-lived ones while somebody signs in (or signs in to confirm a
request), one for a browser that need not be asked the second step again, and
one for the way in used last.

The plugin itself sends nothing anywhere: no telemetry, no licence check.

= Export and erasure =

**Tools → Export / Erase Personal Data** answer for all of the above. The
export leaves out credentials (authenticator secret, backup-code hashes,
passkey keys); the erasure removes them. Refused sign-ins that typed a name are
exported, and kept on erasure as security evidence until the log removes them.

People ask on their account. The e-mail's link has them sign in, deleting asks
once more, and then it is done (or waits in **Tools**, if the site says so).
The file is downloaded from the account, signed in. Erasing deletes the account
too, or, if they published something or belong to another site of the network,
leaves it with no name, e-mail, password or role. Administrators cannot ask.
Each step can be set to WordPress's own way.

= Deleting the plugin =

By default deleting the plugin leaves everything in place, so a plugin deleted
by accident does not lose anybody's account. To remove it all, tick **Remove
everything this plugin wrote** on **DiluxOne Users+ → Maintenance → Tools**
first. On a network, people's profile data goes only when every site that uses
the plugin has ticked it.

== Third-party resources ==

The social buttons show each network's logo as inline SVG
(`includes/sso-icons.php`). The logos are trademarks of their owners, shown
only to identify the button that signs in with that service, as their brand
guidelines allow. Everything else is original work under GPLv2 or later; no
third-party library is bundled (the QR encoder, TOTP and WebAuthn verification
are written for this plugin).

== Frequently Asked Questions ==

= Which shortcodes are there? =

`[diluxone_users_login]` and `[diluxone_users_register]` for the sign-in and
registration pages, `[diluxone_users_account]` for the account area and
`[diluxone_users_account_nav]` for its menu alone. The account's pieces work on
their own too: `[diluxone_users_fields]`, `[diluxone_users_avatar]`,
`[diluxone_users_handle]`, `[diluxone_users_accounts]`,
`[diluxone_users_sessions]` and `[diluxone_users_notifications]`.

= Does it work on multisite? =

Yes. Settings are per site; accounts are the network's. Whether new accounts
may be created at all is the network's **Allow new registrations**. Somebody
becomes a member of a site when they sign in there through one of its doors,
if that site takes new people. The second step is asked wherever a person signs
in when any site they belong to asks it. Each site's reports count its own
members. For social login, use one app on every site: some providers give a
different account id per app.

= Is it behind a proxy or a CDN? =

Then say so on **DiluxOne Users+ → Security → Behind a proxy**: pick the
header your proxy writes and list its addresses. Until then the plugin ignores
every forwarding header, because a header nobody is writing is one a visitor
can write.

== Screenshots ==

1. The sign-in page: a passkey, a social account, a link by email, or the WordPress password — whichever ones the site turned on.
2. The account area on the front end, in the site's own theme.
3. Security: passkeys, two-step verification and every browser that is signed in.
4. The second step at sign-in, for whoever turned it on.
5. Overview: how many accounts, how they get in, and the first steps until there are none left.
6. Every way into the site in one table, read from the settings the other tabs write.
7. Two-step verification: when it is asked for, with what, and to whom.
8. Social login: twelve networks, each with its own credentials and a live test.
9. User fields: what is asked of a person, where it shows and who can change it.
10. How the account area looks, with a live preview of the real markup.
11. Open sessions across the site, with the button to close them.
12. The Access column WordPress's own Users list gains.
13. Maintenance: every check, including the ones that fail.

== Changelog ==

= 1.0.0 =
First public release.
