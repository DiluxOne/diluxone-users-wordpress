=== DiluxOne Users+ ===
Contributors: pablodiloreto
Tags: users, login, passwordless, two-factor, account
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom user fields, a front-end account area, passwordless sign-in, social login, 2FA and session control.

== Description ==

Everything about the people who use your site, in one place.

**Activating it changes nothing.** Sign-in, registration, wp-login.php, the
e-mails WordPress sends, avatars, the toolbar, session lengths, the Users list
and everybody's profile stay exactly as WordPress has them, nothing is recorded
and nothing is sent anywhere, until an administrator turns each feature on.
Only the plugin's own screens appear, in the dashboard.

* **User fields** defined from the dashboard: type, whether it is required,
  where it shows and who can change it, and how many times. A new site asks
  for WordPress's own first and last name only; a country, a date of birth, a
  gender and a phone are suggested, one tick away.
* **An account area on the front end**: Home, Your details, Linked accounts,
  Security, Notifications and Your data, as tabs or a side menu. Sections can
  be renamed, reordered and turned off, and take the site's own words or
  another plugin's shortcode before, after or instead of what they show.
* **How people get in**: a sign-in link sent to the e-mail address they type
  (the box takes an address only), WordPress's own password form, or both;
  a registration form of the plugin's own; and control over WordPress's own
  registration and profile screens.
* **Social login** with Google and Microsoft, a step-by-step guide for each
  console and a live test before one is turned on. DiluxOne Users+ Pro, a
  separate add-on, adds ten more providers.
* **Two-step verification**: a code by e-mail, with backup codes for when the
  inbox is out of reach, optional or required, asked after the e-mail link as
  after a password. DiluxOne Users+ Pro adds an authenticator app, asking it
  of some roles only, and not asking again on a browser that passed it.
* **Sessions**: how long they last (WordPress's own length until you choose
  one), every open session on **Reports → Open sessions** with a button to
  close them, and, once you show it, each person's own list on their
  account's Security tab.
* **Design**: the sign-in page left in your page, in a centred card or on a
  screen of its own at an address you choose, and the colours from a short
  palette that reads in light and dark (DiluxOne Users+ Pro adds the colours
  from your theme), or no stylesheet at all, for a site that styles
  everything itself.
* **An activity log** of its own, off until you tick what to record: who
  signed in, who was refused and what changed. It records IP addresses; see
  Privacy below.
* **Privacy**: WordPress's own export and erasure requests answer for
  everything this plugin stores and, once you turn it on, people can ask for
  their data or delete their account from the account area.

== External services ==

= Social sign-in providers =

The plugin talks to a third-party service only when an administrator has
entered that provider's credentials and turned it on, and only when somebody
uses its button — on the sign-in or registration page, or to link it from
their account — or an administrator runs its live test. With no provider on,
it makes no outbound request at all.

What is sent: the client ID and secret you registered with the provider, the
authorisation code the browser came back with and your site's redirect URL;
then the access token the provider issued, to read the person's profile. What
comes back: the person's identifier at that provider, their name and their
e-mail address.
The requests carry WordPress's usual user-agent, which names your site's
address; nothing else about your site or its visitors is sent.

* **Google** — accounts.google.com, oauth2.googleapis.com, openidconnect.googleapis.com. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy)
* **Microsoft** — login.microsoftonline.com, graph.microsoft.com. [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)

The settings screens link to each provider's console and docs (links, not requests).

= Gravatar =

**Gravatar** (Automattic) is WordPress's own avatar service. Activating the
plugin leaves it exactly as WordPress uses it (Settings → Discussion decides
whether avatars show at all): browsers request avatars from gravatar.com, which
receives a hash of the e-mail address and the visitor's IP. The plugin itself
makes no request to it. Turned off on **DiluxOne Users+ → Design → Profile
photo**, the plugin draws them instead and nobody's browser asks gravatar.com.
[Terms](https://automattic.com/terms/), [Privacy](https://automattic.com/privacy/)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. On **DiluxOne Users+ → Access**, pick (or create) the sign-in page, or
   choose the shape "A page of its own", which needs no page.
3. On **DiluxOne Users+ → Account area**, pick (or create) the account page.

Every way in the plugin adds starts off; turn each one on where it lives, on
**Access**, **Registration**, **Security** and **Social login**.

== Privacy ==

= What it stores =

In each person's profile: the answers to your fields, their public name and
picture, which social accounts are linked, whether two-step verification is
on, a hash of each browser they signed in from (so "a new device signed in"
is said once), their notification choices and, on a network, the sites an
administrator removed them from.

In its own table, `{prefix}diluxone_users_log`, once you tick what to record
on **Reports → Log settings**: one row per event with the date, the account,
the **IP address** and the browser's user-agent; for a refused sign-in, the
name typed; for a changed account, the old and new e-mail or public name.
Rows are kept 90 days unless you choose otherwise.

Cookies: short-lived ones while somebody signs in, links a social account,
chooses a new password or confirms a request; on a network, one that holds the
page to go back to for twenty minutes; and one for the way in used last.

The plugin itself sends nothing anywhere: no telemetry, no licence check.

= Export and erasure =

**Tools → Export / Erase Personal Data** answer for all of the above. The
export leaves out credentials such as backup-code hashes, which the erasure
removes. Refused sign-ins that typed a name are exported, and kept on erasure
as security evidence until the log removes them; so is, on a network, the list
of sites an administrator removed somebody from, which keeps them from being
added back. Turned on (**Account area → Your data**), people can also ask for
their data, or delete their account, from the account area.

= Deleting the plugin =

Deleting the plugin leaves everything in place unless **Remove everything this
plugin wrote** was ticked first, on **Maintenance → Tools** (on a network, on
**Network Admin → DiluxOne Users+ → Overview**). The pages it created are your
content and stay either way.

== Third-party resources ==

The social buttons show each network's logo as inline SVG
(`includes/sso-icons.php`). The logos are trademarks of their owners, shown
only to identify the button that signs in with that service, as their brand
guidelines allow. Everything else is original work under GPLv2 or later; no
third-party library is bundled.

== Frequently Asked Questions ==

= Which shortcodes are there? =

`[diluxone_users_login]` and `[diluxone_users_register]` for the sign-in and
registration pages, `[diluxone_users_account]` for the account area and
`[diluxone_users_account_nav]` for its menu alone. The account's pieces work on
their own too: `[diluxone_users_fields]`, `[diluxone_users_avatar]`,
`[diluxone_users_handle]`, `[diluxone_users_accounts]`,
`[diluxone_users_sessions]` and `[diluxone_users_notifications]`. On a
network they are the main site's: placed on another site, each one draws a
button to the main site's page, which brings people back afterwards. On a
network, `[diluxone_users_join]` tells somebody signed in whom an administrator
removed from that site that they are not a member of it; it draws nothing on a
single site.

= Does it work on multisite? =

Yes, activated for the whole network: WordPress offers only **Network
Activate**. The second step, sessions, the proxy, social sign-in, the user
fields and the activity log are set once, in **Network Admin → DiluxOne
Users+**; the sign-in, registration and account pages, and how they look, on
the main site. Each site keeps its own menus, reports and maintenance.

Ticking **Network Admin → DiluxOne Users+ → Overview → Signing in across the
network** has people sign in, register and keep their account on the main
site, and sends them back to the site they came from; until then every site
keeps its own wp-login.php. Social sign-in works on the main site only. Which
sites an account belongs to is decided on **Network Admin → DiluxOne Users+ →
Membership**; the free answer is every live site, and nothing is added until
you confirm it there.

Subdirectory and subdomain networks are supported; on a subdomain network, do
not pin `COOKIE_DOMAIN` to one host in `wp-config.php`. A site on a domain of
its own is not supported in this version. The rest is in
[docs/multisite.md](https://github.com/DiluxOne/diluxone-users-wordpress/blob/main/docs/multisite.md).

= I'm locked out. How do I get back in? =

Add `define( 'DILUXONE_USERS_SAFE_MODE', true );` to `wp-config.php`. While it
is there the plugin steps aside: nothing redirects, wp-login.php signs people
in the way WordPress does on every site, nobody is asked for the second step, and
social sign-in and every add-on's door are off, so your WordPress username (or
e-mail address) and password get you in.
Every dashboard page says safe mode is on; fix the cause and remove the line.
With no sign-in page chosen, the second step is asked on wp-login.php itself.

= Does it work with page caching? =

Yes. The sign-in, registration and account pages, and any page with one of the
plugin's shortcodes, send no-cache headers and define `DONOTCACHEPAGE`.

= Is it behind a proxy or a CDN? =

Then say so on **DiluxOne Users+ → Security → Behind a proxy** (on a network,
in Network Admin): pick the
header your proxy writes and list its addresses. Until then the plugin ignores
every forwarding header, because a header nobody is writing is one a visitor
can write.

== Screenshots ==

1. The sign-in page: a social account, a link by email, or the WordPress password — whichever ones the site turned on.
2. The account area on the front end, in the site's own theme.
3. Security: two-step verification and every browser that is signed in.
4. The second step at sign-in, for whoever turned it on.
5. Overview: how many accounts, how they get in, and the first steps until there are none left.
6. Every way into the site in one table, read from the settings the other tabs write.
7. Two-step verification: when it is asked for, with what, and to whom.
8. Social login: each network with its own credentials and a live test.
9. User fields: what is asked of a person, where it shows and who can change it.
10. How the account area looks, with a live preview of the real markup.
11. Open sessions across the site, with the button to close them.
12. The Access column WordPress's own Users list gains.
13. Maintenance: every check, including the ones that fail.

== Changelog ==

= 1.0.0 =
Unreleased.

First public release.

* User fields defined from the dashboard: type, whether it is required, where it shows and who can change it, with a country, a date of birth, a gender and a phone one tick away.
* An account area on the front end, with Home, Your details, Linked accounts, Security, Notifications and Your data, as tabs or a side menu.
* Ways in: a sign-in link sent by e-mail, a password, or both, on a sign-in page inside the site or on a page of its own, with the plugin's own registration form.
* Social login with Google and Microsoft, with a guide for each console and a live test.
* Two-step verification by e-mail code and backup codes, optional or required.
* Sessions: how long they last, and every open session, for administrators and, once shown, for each person on their account, with a button to close them.
* Design: the sign-in page and the account area in a short palette that reads in light and dark, or with no stylesheet at all.
* An activity log, off until you choose what to record; WordPress's export and erasure requests for everything the plugin stores; and multisite, one set of rules for the whole network.
