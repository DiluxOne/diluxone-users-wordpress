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
| The save box: changed back is clean, discard, leaving asks | `admin-settings` › The box that saves |
| The tabs on a phone stay in one row | `admin-settings` › The tabs on a phone |
| Security › Summary, Two-step, Sessions, Proxy | `security-summary`, `admin-settings`, `admin-effects` |
| Social › Providers: turn off (asks first), delete its settings (asks first) | `admin-effects` › Social › Providers |
| Design › Brand and previews | `design-brand`, `preview` |
| Design › Photo, Registration, Account, WordPress's screens | `admin-effects` |
| Notifications › Rules / E-mails | `admin-effects` › Notifications › Rules; `admin-settings` › the site's own words |
| Reports › Sessions: list and close | `admin-tools` › Reports |
| Reports › Activity: logging groups, event filter | `activity-log`, `admin-tools` › Reports |
| Reports › Logging: empty the log (asks first) | `activity-log` › emptying it asks first |
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
| The main site's sign-in settings reach /alpha/ and /beta/ alike | `isolation` › The main site’s sign-in settings are every site’s |
| Reports › Sessions on /alpha/ lists only its members | `isolation` |
| Add New User on a subsite | `isolation` |
| Photo uploaded on /alpha/ is the same on /beta/ (the photo piece on a page of each site) | `isolation` |
| Photo removed on /beta/ deletes no /beta/ file | `isolation` |
| 2FA required of administrators: an administrator of /alpha/ signing in on /beta/ as a subscriber is asked | `isolation` › The second step is the network’s |
| Social identity linked on /alpha/ reaches the same account on /beta/ | `social` |
| A new site used from its public pages first, nothing in debug.log | `lifecycle` |
| Deactivate/activate network-wide and per site, nothing in debug.log | `lifecycle` |
| Account deleted from the main site's account area: member of the main site only, deleted from the network | `account-closing` |
| Account deleted: member of another site too, anonymised there | `account-closing` |
| Copy asked for by a member of /alpha/ on the account area, handed over by the main site | `account-export` |
| Uninstall | not covered in a browser: deleting the plugin removes the code the suite runs against. Integration: `UninstallNetworkTest` (network), `UninstallSiteTest` (single site) |

## Network settings, and the network's screens

Every case of the move of the rules about people to the network, at every
layer. Unit tests run without WordPress (`make test-unit`, `make
test-unit-min`); integration tests on the tests site as a network (`make
test-integration`), skipping loudly on a single site; e2e single site is the
proof nothing changed there.

| Case | Unit | Integration | E2E single site | E2E network |
|---|---|---|---|---|
| Each setting's scope; menus, admin bar and dashboard profile are each site's | `OptionScopeTest` › what fits into a site's own theme… | `OptionScopeTest` › each scope is stored where it says | — | `network-admin` › another site keeps its menus… |
| Routed only when network-activated; site by site, every setting is the site's | `OptionScopeTest` › switched on site by site…, …goes to the network | `OptionScopeTest` › switched off a setting is the site's own | — | `lifecycle` › network-wide, then on one site only |
| A hub setting read from another site follows every write (memo) | — | `OptionScopeTest` › the hub's copy…follows every write | — | `isolation` › main site's sign-in settings… |
| Hub pages are the hub's: not drawn or routed on another site, links go to the hub | — | `NetworkSettingsTest` › the pages are the hub's | — | `lifecycle` › a site made after…; `account-export`; `account-closing` |
| Who writes what, from where (single / network / hub / site) | `OptionScopeTest` › each place writes only its own scope; `NetworkPlacesTest` | `NetworkSettingsTest` › a site's screen cannot change or loosen…; another site writes only its own | `single-site` › the doors…are this site's to switch | `network-admin` › the network's doors on the main site's Access are drawn, not saved |
| A setting saved in Network Admin is read on every site | — | `NetworkSettingsTest` › …read on every site | — | `network-admin` › What is saved in Network Admin…; Every tab that moved saves… (sessions, proxy, passkeys, social sign-in, deleting the plugin) |
| Passkeys and social sign-in switched on their own tabs in Network Admin | — | `NetworkSettingsTest` › the network's doors are switched on their own tabs | `single-site` › …do not repeat the switches Access has | `network-admin` › Every tab that moved saves… |
| User fields are the network's: added and deleted in Network Admin | — | `SettingsFileTest` › a field keyed like… (from Network Admin) | `admin-tools` › User fields | `network-admin` › a user field added in Network Admin… |
| Log settings are the network's; the rows and "Empty it now" stay with each site | — | `NetworkSettingsTest` › each place draws only its own tabs | `single-site` › Reports › Log settings keeps the way to empty… | `network-admin` › the log's settings…; another site keeps… |
| Network Admin menu and every tab (answers, tab strip, layout) | `NetworkPlacesTest` | `NetworkSettingsTest` › each place draws only its own tabs | — | `network-admin` › Network Admin has the network's screens (one test per tab) |
| Network screens leave the sites' menus; refused by address on a site | `NetworkPlacesTest` | `NetworkSettingsTest` › the network's screens leave the sites' menus | `single-site` › the menu carries every screen | `network-admin` › A site's menu keeps only what is its own; refused |
| A link to a screen goes where the screen is | — | `NetworkSettingsTest` › a link to a screen goes where the screen is | — | `network-admin` › the cards' links |
| A site's Overview names each moved area ("Managed by the network", "Set on …"), with the way there only for whoever can go | — | `NetworkSettingsTest` › a site administrator is told where things went… | `single-site` › the Overview has no network tabs… | `network-admin` › another site…says where the rest went |
| Network Overview: hub, addressing, mapped-domain warning, conflicts table | — | — | — | `network-admin` › network › diluxone-users › network; `migration` |
| Social provider switched only from Network Admin | — | `SsoToggleTest` › on a network a site cannot switch a provider | `admin-effects` › a provider switched off | `network-admin` (providers tab) |
| Settings file: a site restores and exports only its own | — | `SettingsFileTest` › a file restored on a site of a network… | `admin-tools` › Settings as a file | — |
| Deleting the plugin: one network box; a site's Tools say so | — | `NetworkSettingsTest`; `UninstallNetworkTest` | `single-site` › Maintenance › Tools still asks | `network-admin` › …deleting the plugin; another site keeps… |
| "Show me what I chose" writes nothing of the network's | — | `NetworkSettingsTest` › a trial run writes nothing of the network's | `preview` | — |
| 2FA: one network rule; a chosen role on any site; super admin | — | `MultisiteTest` › the second step is asked on every site alike; a chosen role on any site; a super admin… | `two-factor` | `isolation` › The second step is the network's; `network-admin` › the second step required there… |
| Move: fresh network (nothing to take, marker written) | — | `NetworkSettingsTest` › on a fresh network… | — | `lifecycle` › a site made after… |
| Move: the main site's values, the rest written down | `NetworkPlacesTest` › same setting; written down short | `NetworkSettingsTest` › the move takes the main site's values… | — | `migration` |
| Move: credentials only noted as different | — | `NetworkSettingsTest` › credentials are only noted… | — | — |
| Move: user fields are every site's, main site winning | — | `NetworkSettingsTest` › the fields are every site's… | — | — |
| Move: the wipe is not carried over | `NetworkPlacesTest` › every network setting moves but… | `NetworkSettingsTest` › the wipe is not carried over | — | — |
| Move: idempotent; batches, cron, marker last | — | `NetworkSettingsTest` › idempotent; the version is written last | — | `migration` › running it again does nothing |
| Move: a notice once, dismissed for good; the table stays | — | `NetworkSettingsTest` › the differences are announced once | — | `migration` |
| WP-CLI `wp diluxone-users network migrate` | — | — | — | `migration` |
| Uninstall on a network (network options, old per-site copies, log tables, user meta) | — | `UninstallNetworkTest` | — | — |
| Uninstall on a single site | — | `UninstallSiteTest` (skipped on a network) | — | — |
| The pictures of the network's screens | — | — | — | `network-snapshots` (`make test-visual-network`) |

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
