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
* **How people get in**: a link sent to their e-mail with no password, username
  and password, or both, plus control over WordPress's own registration and
  profile screens.
* **Social login** with Google and Microsoft, a step-by-step guide for each
  console and a live test before one is turned on. DiluxOne Users+ Pro, a
  separate add-on, adds ten more providers.
* **Two-step verification**: a code by e-mail and backup codes, optional or
  required, with a rule of its own for sign-ins by e-mail link. DiluxOne
  Users+ Pro adds an authenticator app, asking it of some roles only, and
  not asking again on a browser that passed it.
* **Design**: the sign-in page left in your page or in a centred card, a
  simple account area, the colours from your theme or from a short palette
  that always reads, and the sizes and spacing — or no stylesheet at all, for
  a site that styles everything itself.
* **Sessions**: how long they last (WordPress's length until you choose one), where they are open, how to close them, and, if you want it, a new password that closes every one of them.
* **An activity log** of its own, off until you tick what to record: who
  signed in, who was refused and what changed. It records IP addresses; see
  Privacy below.
* **Privacy**: WordPress's own export and erasure requests answer for
  everything this plugin stores, and people can delete their own account.

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
2. Go to **DiluxOne Users+ → Access** and pick (or create) the sign-in page.
3. Go to **DiluxOne Users+ → Account area** and pick (or create) the account page.

Nothing changes on the site until you turn something on: WordPress's own
sign-in and registration keep working as they did, and the plugin's ways in
(the e-mail link, social accounts, its own registration form, two-step
verification) all start off. Turn each one on where it lives, on
**Access**, **Security** and **Social login**.

== Privacy ==

= What it stores =

In each person's profile: the answers to your fields, their public name and
picture, which social accounts are linked, whether two-step verification is
on, a hash of each browser they signed in from (so "a new
device signed in" is said once), their notification choices and, on a
network, the sites an administrator removed them from.

In its own table, `{prefix}diluxone_users_log` (one for the whole network, on
a network): one row per event with the date, the account, the **IP address**
and the browser's user-agent. When a sign-in is refused, the name typed in the
username box is kept with it; when an account changes, what changed (an old
and a new e-mail address or public name). Out of the box it records nothing
and the table does not exist: it is made when you tick the first group to
record, on **DiluxOne Users+ → Reports → Log settings**, where how long rows
are kept (90 days to start) is set too.

Cookies: short-lived ones while somebody signs in (or signs in to confirm a
request), one for a browser that need not be asked the second step again, and
one for the way in used last.

The plugin itself sends nothing anywhere: no telemetry, no licence check.

= Export and erasure =

**Tools → Export / Erase Personal Data** answer for all of the above. The
export leaves out credentials (backup-code hashes, an add-on's keys); the erasure removes them, and everything an add-on kept under
the plugin's prefix with them. Refused sign-ins that typed a name are
exported, and kept on erasure as security evidence until the log removes them.
On a network, the sites an administrator removed somebody from are exported
and kept on erasure: that list is what keeps them from being added back.

Once you turn it on (**Account area → Your data**), people ask on their
account. The e-mail's link has them sign in, deleting asks
once more, and then it is done (or waits in **Tools**, if the site says so).
The file is downloaded from the account, signed in. Erasing deletes the account
too, or, if they published something or belong to another site of the network,
leaves it with no name, e-mail, password or role. Administrators cannot ask.
Each step can be set to WordPress's own way.

= Deleting the plugin =

By default deleting the plugin leaves everything in place, so a plugin deleted
by accident does not lose anybody's account. To remove it all, tick **Remove
everything this plugin wrote** on **DiluxOne Users+ → Maintenance → Tools**
first. On a network where the plugin is on for every site it is one box, in
**Network Admin → DiluxOne Users+ → Overview → Deleting the plugin**, and
ticked it takes everything, on every site. The pages it created for you
(sign-in, registration, account) are your content and stay either way.

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
network, `[diluxone_users_join]` tells somebody signed in who is not a member
that the site is by invitation; it draws nothing on a single site.

= Does it work on multisite? =

Yes. Activated for the whole network, the plugin applies one set of rules to
every site: the second step, sessions, the proxy, the social sign-in
apps and their rules, the user fields and what the activity log keeps are set
once, in **Network Admin → DiluxOne Users+**, and a site administrator cannot
change them for their own site. "Only some roles" means a role on any of the
person's sites; the roles that may use social sign-in are asked of every one of
them, so a role left unticked on any site keeps that person out. The sign-in, registration and account pages, and how they look
and what they say, are set on the main site, and every site uses them. Each
site keeps its own menus, admin bar, reports and maintenance. The activity
log is one table for the whole network: each site's Reports › Activity shows
and empties that site's rows, and **Network Admin → DiluxOne Users+ → Activity
log** shows every site's, with the site each row happened on, and empties
them all.
Once you tick **Network Admin → DiluxOne Users+ → Overview → Signing in
across the network** (until then every site keeps its own wp-login.php),
people sign in, register and keep their account on the main site. On every
other site the doors lead there — the "Sign in" in its menu, the plugin's
shortcodes, its wp-login.php, WordPress's own sign-in links — and, whichever
way the person gets in (e-mail link, password, social account, second
step), they are sent back to the page they started from. The way back
is only ever an address on a site of the network, kept for twenty minutes in
a cookie of the main site and used once; a sign-in link opened on another
device lands on the main site, signed in. Social sign-in works on the main
site only: register one redirect address per network in the
providers' consoles. Whether new accounts may be created at all is the
network's **Allow new registrations**. Which sites an account is a member of
is one decision for the network, on **Network Admin → DiluxOne Users+ →
Membership**, and nothing is added until you confirm it there (Network Admin
says so; until then WordPress's own memberships stand): every live site (the default: a new account joins every site, a
new site gets every account, big networks in the background, and "Sync
everyone now" or `wp diluxone-users network membership sync` for what was
there before). DiluxOne Users+ Pro adds two more answers: whoever asks (a "Join
this site" button) and by invitation (only administrators add people). The role is each site's own New
User Default Role, and on the main site the role new accounts get. Somebody an
administrator removed from a site is not added back until an administrator
does. Each site's Reports › Activity also lists the sign-ins on the main site
that came from it. On a network that used
the plugin before, the main site's settings become the network's, and Network
Admin lists what the other sites had set differently. Subdirectory and
subdomain networks are supported. On a subdomain network WordPress sets the
session cookie for the network's domain, which is what lets a sign-in on the
main site reach every subdomain: do not pin `COOKIE_DOMAIN` to one host in
`wp-config.php`. A site on a domain of its own (not the network's or one of its
subdomains) does not share that cookie, so people must sign in again there;
that is not supported in this version, and Network Admin and that site's
dashboard say so.
On a network the plugin is activated for the whole network or not at all:
WordPress offers only **Network Activate**, and a site it was left on for
alone does nothing and asks the network's administrator to activate it for
the whole network.

= I'm locked out. How do I get back in? =

Add `define( 'DILUXONE_USERS_SAFE_MODE', true );` to `wp-config.php`. While it
is there the plugin steps aside: nothing redirects, wp-login.php signs people
in the way WordPress does on every site, nobody is asked for the second step, and
social sign-in and every add-on's door are off, so your username and password get you in.
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
First public release.

* Activating the plugin changes nothing on the site: every feature starts off, the activity log records nothing until you choose what to record, and the plugin writes no settings, tables, cron events or rewrite rules of its own until you turn something on.
