=== DiluxOne Users+ – Accounts & Login ===
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

Everything about the people who use your site: the details you ask them for,
how they sign in, what they see of their own, and what they can do with it.
In most sites that gets solved again every time, with four plugins that do not
talk to each other. Here it lives once.

* **User fields** defined from the dashboard: name, type, whether it is
  required, where it goes, and who can change it and how many times. The ones
  WordPress already has — first and last name — are in the same list and follow
  the same rules.
* **An account area on the front end**: Home, Your details, Linked accounts,
  Security, Your data and Notifications, with tabs on top or a menu down the
  side. Sections can be renamed, reordered, turned off and added; one of your
  own is a name, an address and a shortcode.
* **How people get in**: a link sent to their email with no password at all,
  username and password, or both. With control over what happens to the
  WordPress registration and to its profile screen.
* **Social login** with twelve providers, a step-by-step guide for each
  console, buttons with the real brand marks, and a live test before you turn
  one on.
* **Two-step verification**: a code by email, an authenticator app with a QR
  code, and backup codes. With a policy per role and per way in.
* **Passkeys** (WebAuthn), each one with a name of its own.
* **Sessions**: how long they last, where they are open and how to close them.
* **An activity log** of its own: who signed in, who was refused, and what
  changed about an account. It records IP addresses — see the Privacy section
  below, which says exactly what is kept and for how long.
* **Privacy**: the export and erasure requests WordPress already knows how to
  handle, answering for everything this plugin stores.

None of this depends on another plugin. What belongs to someone else — a
course, a membership, a forum — comes in through a filter or a shortcode.

= What it will not do =

Whatever an administrator turns off disappears from the front end, with no
second switch to remember. With no social provider enabled there is no
"Linked accounts" section at all; with neither data download nor account
deletion allowed there is no "Your data" section.

== External services ==

This plugin talks to a third-party service only when an administrator has
pasted that provider's credentials and turned it on, and only when somebody
clicks its button on the sign-in page (or when an administrator runs the live
test on its settings screen). With no provider enabled, the plugin makes no
outbound request at all.

What is sent to a provider, in every case, is the same: the client ID and
client secret you registered with them, the authorisation code the browser came
back with, and the redirect URL of your site. What comes back is the person's
identifier at that provider, their e-mail address and their name. Nothing else
about your site or its visitors is transmitted.

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

**Gravatar** (Automattic) is WordPress's own avatar service, not a call this
plugin makes — but this plugin has a switch for it, and it comes on. While it
is on, every visitor's browser requests each author's avatar from
gravatar.com, which receives a hash of that person's e-mail address and the
visitor's IP. Turn it off on **DiluxOne Users+ → Design → Profile photo**.
[Terms](https://automattic.com/terms/), [Privacy](https://automattic.com/privacy/)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Go to **DiluxOne Users+ → Account area** and pick the page that holds the
   `[diluxone_users_account]` shortcode.
4. Go to **DiluxOne Users+ → Access** and pick the page that holds the
   `[diluxone_users_login]` shortcode.

== Privacy ==

= What this plugin stores about a person =

In their WordPress profile: the answers to the fields you define, their public
name, their profile picture, which social accounts are linked, their passkeys,
whether two-step verification is on and which browsers have already been seen
(as a hash, so that "a new device signed in" is only said once).

In a table of its own, `{prefix}diluxone_users_log`: one row per event, with
the date, the account, the **IP address** and the browser's user-agent string.
Out of the box it records only signing in, signing out and sign-ins that were
refused. The other groups — changes to an account, changes to its security —
are ticked by hand on **DiluxOne Users+ → Reports → Log settings**, as is how
long a row is kept, which starts at 90 days. With no group ticked, the log
records nothing at all.

Nothing is ever sent anywhere by the plugin itself: there is no telemetry, no
usage reporting, no licence check and no call home of any kind.

= Export and erasure =

Both of WordPress's own tools, under **Tools → Export Personal Data** and
**Tools → Erase Personal Data**, answer for everything above. The export leaves
out anything that is a credential rather than a fact about somebody — the
authenticator secret, the backup-code hashes, a passkey's public key — because
those say nothing about a person and a copy of them travelling by e-mail is a
copy of the keys to the account. The erasure removes them all the same.

= Deleting the plugin =

By default, deleting the plugin leaves everything where it is: the settings,
the log, and everything in people's profiles. That is deliberate — a plugin
deleted by accident, or deleted in order to be installed again, should not be
what loses somebody their account. To have it all removed on delete, tick
**Remove everything this plugin wrote** on **DiluxOne Users+ → Maintenance →
Tools** first.

== Third-party resources ==

The social buttons carry each network's own logo, drawn as inline SVG in
`includes/sso-icons.php`. Those marks belong to their owners and are used for
the single purpose their brand guidelines allow without prior permission:
identifying the button you sign in to that service with. They are not covered
by this plugin's licence. A provider with no mark of its own is drawn with a
plain globe.

Everything else in the plugin is original work under GPLv2 or later. No
third-party library is bundled: the QR encoder, the TOTP implementation and
the WebAuthn verification are all written for this plugin.

== Frequently Asked Questions ==

= Does it work with any theme? =

Yes. It ships its own styles, its templates can be overridden from the theme
at `wp-content/themes/<theme>/diluxone-users/`, and its colours come
from CSS custom properties a site can redefine without copying a stylesheet.

= Does it work on multisite? =

Yes. Configuration is per site; users are network-wide, so anything that
grants access joins the person to the current site.

= Does it record IP addresses? =

Yes, in its own activity log, and only for the groups of events you have
ticked. A fresh install records signing in, signing out and refused sign-ins.
Rows are deleted after 90 days by default, and both the groups and the number
of days are settings. See the Privacy section above.

= Does it send anything to me, or to anyone? =

No. There is no telemetry, no usage reporting and no licence check. The only
outbound requests are to the social-login providers you configure yourself,
listed under External Services above.

= I deleted the plugin. Is the data still there? =

Yes, unless you asked for it to go. See "Deleting the plugin" above.

= Is it behind a proxy or a CDN? =

Then tell it so on **DiluxOne Users+ → Reports → Behind a proxy**: pick the
header your proxy writes and list its addresses. Until you do, the plugin reads
the connection and ignores every header, because a header nobody is writing is
a header the visitor can write.

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

* User fields with their own admin screen: text, email, phone, date, select, checkbox and country, plus WordPress's own first and last name.
* Per-field edit policy — read only, editable, or editable a fixed number of times — and a switch to allow or block access to WordPress's own profile screen.
* Front-end account area with default sections out of the box, in one of two templates — a panel in the page or a full-width cover with the person on it — with the header, the menu and the content width chosen piece by piece.
* Overridable templates and CSS custom properties, so a theme can restyle it without touching the plugin.
* Passwordless sign-in by e-mail link, optionally alongside or instead of the password form.
* Social login for twelve providers, with path-based callback URLs that every provider accepts.
* Two-step verification by e-mail code or authenticator app, with its own policy per sign-in method, and a lockout that counts wrong codes against the account rather than against one attempt.
* Passkeys with friendly names, which need no second step of their own.
* Session control: see where an account is signed in and close any session.
* An activity log with its own table, off for everything but signing in and out until you say otherwise, with a retention setting and a daily purge.
* Public names, with a live availability check against the same validation used on save.
* Avatars: uploaded photo, Gravatar or generated initials, each one switchable.
* Data export and account deletion from the front end, each one switchable.
* WordPress's own export and erasure requests answer for everything the plugin stores.
* Multisite aware.
* Translations included for es_AR, es_ES, es_MX, pt_BR, pt_PT, fr_FR, de_DE and it_IT.

== Upgrade Notice ==

= 1.0.0 =
First public release.
