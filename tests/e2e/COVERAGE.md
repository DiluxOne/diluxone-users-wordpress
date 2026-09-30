# What the browser covers

Every feature, screen and state the plugin has, and the test that walks it in a
real browser. `specs/` is the single-site suite (`make test-e2e`, the dev site);
`network/` is the network suite (`make test-e2e-network`, the tests site turned
into a subdirectory network). A test marked **bug** fails today on a product
bug and says which in its assertion message; it is meant to go green when the
product is fixed, not to be loosened.

## Signing in

| Feature / state | Covered by |
|---|---|
| Link by e-mail: ask, receive, open, signed in (`sent`) | `magic-link` › ask for it, open it… |
| Link used twice, expired, pointed at another account (`expired`) | `magic-link` › the second time…, time ran out, somebody else |
| Unknown address, registration open / closed; the throttle | `magic-link` › nobody has seen before, self-registration off, asking twice |
| Password form: `both`, `password`, `link` | `password-login` › the three ways in |
| wp-login.php taken over (`auto`), POST allowed (H-05), escape hatch, logout | `password-login` |
| wp-login.php left to WordPress (`wp`) and sent to the site's page (`mine`) | `admin-effects` › Access › The sign-in page |
| No password: `wp` not offered, the third answer in force, wp-login.php sent to the page | `admin-effects` › with no password, wp-login.php is no second door |
| The four ways in as tabs or stacked, order, the tab that opens, no JS | `login-ways` |
| Split-screen layout, messages rewritten in the dashboard | `login-screen` |
| Sign in with the public name (handle login) | `account-area` › The public name |
| Reset: `wp`, `site` (whole chain, reused key), `link` | `password-reset` |
| Second step by e-mail: code, reuse, five tries, resend wait, timeout, link skip | `two-factor` › by e-mail |
| Authenticator app: set up from the account, sign in, trusted browser, backup codes | `two-factor` › with an authenticator app |
| Passkeys: register and sign in, a removed key refused | `passkeys` (Chromium only) |
| Social: new account, known verified address, H-01, silence, verified-only, social registration off, cancel, roles, link/unlink, H-02, forged state, buttons off | `sso` |
| WP-CLI `wp diluxone-users login` link | `network/lifecycle` › WP-CLI |

## Registration

| Feature / state | Covered by |
|---|---|
| The site's form: required field, link, answers saved (`registered`) | `register` |
| `missing` with the browser's check off, `taken`, `closed`, `slow`, `email` | `register` |
| A field added in the dashboard shows on the form | `admin-tools` › User fields |
| Role a stranger becomes: no role that edits the site | `admin-settings` › the role a stranger becomes |

## The account area

| Feature / state | Covered by |
|---|---|
| Guest view with the way in | `account-area` › a stranger is shown the way in |
| Menu reaches every section, current tab marked | `account-area` › the menu reaches every section |
| Your data / Linked accounts exist only while offered | `account-area` › The account page itself |
| Details: fields saved, display name rebuilt, `missing` from the server | `account-area` › Your details |
| Photo: upload, drawn, removed with its attachment, not-a-picture refused | `account-area` › Your photo |
| Public name: saved, reserved refused | `account-area` › The public name |
| Notifications: new device announced (link), switch off, rule `always` hides the switch | `account-area` › Notifications |
| New device announced after a password sign-in | `account-area` › …with the password, too |
| Your data: export and erase requests with WordPress's confirmation | `account-area` › Your data |
| Deleting asks in the page's own dialog; "Cancel" sends nothing | `account-area` › “Cancel” in the question… |
| Deleting: the e-mail's link asks once more, the button deletes, the sign-in page says so | `account-area` › deleting the account: the e-mail’s link asks once more… |
| "No, keep it" keeps the account, and the link still works | `account-area` › “No, keep it”… |
| The link opened with no session: sign in, and back to the question | `account-area` › opened with no session… |
| The link opened signed in as somebody else confirms nothing | `account-area` › signed in as somebody else… |
| Link set to confirm by itself (WordPress's way), for deleting and for a copy | `account-area` › a site whose link confirms by itself… |
| Deletion carried out from Tools when the site says so | `account-area` › a site that carries them out itself… |
| Copy: made on confirmation, e-mail points to the account, file only for its owner | `account-area` › a copy: confirmed from the e-mail… |
| Copy: the file's own link mailed, when the site says so | `account-area` › a site that mails the file… |
| Copy left for Tools when the site makes them itself | `account-area` › a site that makes the files itself… |
| Your data settings: when, the link, the file — every answer saved | `admin-tools` › how a copy and a deletion are confirmed… |
| Erasure switched off refused by the server | `account-area` › erasure switched off… |
| Administrator cannot request own erasure, even by hand (`admin`) | `account-area` › an administrator cannot… |
| Security: two-step by e-mail on/off with a code | `account-area` › Security |
| Security: authenticator app removed with a backup code; new backup codes | `account-area` › Security |
| Security: browsers list, close one, close the others | `account-area` › Security |
| Linked accounts: link and unlink | `sso` › linking from the account area |
| Passkeys: add and remove from the account | `passkeys` |
| Passkeys: rename | `passkeys` › a key can be given another name |
| Sections: turned off, custom section added and deleted | `admin-settings` › a section turned off; `admin-tools` › Account area › Sections |

## The site's own menu and WordPress's screens

| Feature / state | Covered by |
|---|---|
| Sign-in item for a stranger; the person with sections, sign-out; three styles; no location | `site-menu` (classic menu from the mu-plugin, `POST /menu`) |
| Account area › In the site menu tab saves location and style | `site-menu` › Account area › In the site menu |
| wp-login.php branding (Design › WordPress's screens) | `admin-effects` › Design › WordPress's own screens |
| Toolbar hidden, profile.php sent to the account area | `admin-effects` › Account area › The WordPress dashboard |
| Users list Access column; forget authenticator; unlink a network | `admin-tools` › WordPress's own Users screens |
| Add New User takes the e-mail as username | `admin-tools` › Add New User; `network/isolation` › Add New User |

## The dashboard

| Screen | Covered by |
|---|---|
| Every tab of every screen renders, no notice, no layout breakage | `admin-settings` › Every settings screen renders; `admin-layout` |
| Pictures of every tab | `admin-snapshots` (`make test-visual`, not in CI) |
| Access › Ways in / Registration / Messages / Arrangement saves | `admin-settings`, `login-ways`, `login-screen` |
| Every tab that saves has its button in the box beside it, first in the column, in view | `admin-layout` › Every tab saves from beside itself |
| Security › Summary, Two-step, Sessions, Proxy | `security-summary`, `admin-settings`, `admin-effects` |
| Social › Providers toggle | `admin-effects` › Social › Providers |
| Design › Brand and previews | `design-brand`, `preview` |
| Design › Photo, Registration, Account, WordPress's screens | `admin-effects` |
| Notifications › Rules / E-mails | `admin-effects` › Notifications › Rules; `admin-settings` › the site's own words |
| Reports › Sessions: list and close | `admin-tools` › Reports |
| Reports › Activity: logging groups, event filter | `activity-log`, `admin-tools` › Reports |
| Reports › Activity: search by address finds refused attempts | `admin-tools` › …finds the refused attempts |
| Status › Tools: export, import, close sessions, fresh code, rebuild, test message | `admin-tools` › Status › Tools |
| Status › Tools: a file round trip keeps nested settings | `admin-tools` › …nested ones included |
| Status › Lockout | renders (`admin-settings`); what it describes is the escape hatch, covered in `password-login` |
| Status › Tools › wipe on uninstall | not covered: it only takes effect when the plugin is deleted, and uninstalling is not done in a browser |
| User fields: add, show, delete | `admin-tools` › User fields |
| The wordpress.org listing screenshots | `listing-screenshots` (`make screenshots`, writes files) |

## On a network (`network/`)

| Scenario | Covered by |
|---|---|
| Each site draws its sign-in page and posts to itself | `doors` › every site draws its sign-in page |
| Link on /alpha/ opens a session there with /alpha/'s role | `doors` |
| Member of /alpha/ asking on /beta/ joins only by opening the link | `doors` |
| /beta/ closed: a member of /alpha/ does not join | `doors` |
| Network `registration=none`: no door creates anybody | `doors` |
| Network `registration=user`: the doors work | `doors` |
| A password is a network password | `doors` |
| Settings saved on /alpha/ do not reach /beta/ | `isolation` |
| Reports › Sessions on /alpha/ lists only its members | `isolation` |
| Add New User on a subsite | `isolation` |
| Photo uploaded on /alpha/ is the same on /beta/ | `isolation` |
| Photo removed on /beta/ deletes no /beta/ file | `isolation` |
| 2FA required on /alpha/: a password on /beta/ does not open /alpha/'s dashboard | `isolation` |
| Social identity linked on /alpha/ reaches the same account on /beta/ | `social` |
| A new site used from its public pages first, nothing in debug.log | `lifecycle` |
| Deactivate/activate network-wide and per site, nothing in debug.log | `lifecycle` |
| Account deleted: member of one site only, deleted from the network | `account-closing` |
| Account deleted: member of another site too, anonymised there | `account-closing` |
| Copy confirmed on a site of the network, handed over by that site | `account-export` |
| Uninstall | not covered: deleting the plugin removes the code the suite runs against, and it is not a browser flow |

## Not coverable in a browser

- **XML-RPC and application passwords** refusing a password-only sign-in when a
  second step is required: no browser speaks XML-RPC. Covered by the
  integration suite.
- **Cron clean-up of the activity log** after `diluxone_users_log_days`: time
  passing, not a page.
- **The e-mail actually leaving the server**: the mu-plugin catches every
  message on `pre_wp_mail`, which is the point — the suite reads the message
  the site composed, not a mail server's delivery.
- **Real OAuth providers** (the twelve networks): the suite uses the mu-plugin's
  `mock` provider, which exercises the same code path through
  `pre_http_request`; a real provider needs credentials and a consent screen.
