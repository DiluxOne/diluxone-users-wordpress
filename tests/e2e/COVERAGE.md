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
| Reports › Activity on a single site: every row the site's, no Site column, no site filter, no network report or button | `activity-log` › on a single site every row is the site’s… |
| Status › Tools: export, import, close sessions, fresh code, rebuild, test message | `admin-tools` › Status › Tools |
| Status › Tools: a file round trip keeps nested settings | `admin-tools` › …nested ones included |
| Status › Lockout | renders (`admin-settings`); what it describes is the escape hatch, covered in `password-login` |
| Status › Tools › wipe on uninstall | not in a browser: it only takes effect when the plugin is deleted. Integration: `UninstallSiteTest` (single site, ticked and not) |
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
| Activity log: a sign-in on /alpha/ is on /alpha/'s report and not /beta/'s; Network Admin › Activity log has both, with their site, narrowed by the filter and by the Site cell | `activity-log` › a site’s report has its own rows… |
| Activity log: “Empty it now” on /alpha/ asks first and takes /alpha/'s rows only; in Network Admin it asks first and takes every site's | `activity-log` › emptying it on a site… |
| Activity log: a site's report carries its own button, never the network's report or button | `activity-log` › a site’s report carries the button… |
| Activity log: a site's old table moved in by `wp diluxone-users network migrate`, with its site, old table dropped, second run does nothing | `migration` › each site’s old activity log moves… |
| Add New User on a subsite | `isolation` |
| Photo uploaded on /alpha/ is the same on /beta/ (the photo piece on a page of each site) | `isolation` |
| Photo removed on /beta/ deletes no /beta/ file | `isolation` |
| 2FA required of administrators: an administrator of /alpha/ signing in on /beta/ as a subscriber is asked | `isolation` › The second step is the network’s |
| Social identity linked on /alpha/ reaches the same account on /beta/ | `social` |
| A new site used from its public pages first, nothing in debug.log | `lifecycle` |
| Only for the whole network: a site's Plugins screen has no Activate; left on for one site alone it does nothing there and asks super admins to network-activate; back on for the network; nothing in debug.log | `lifecycle` › only for the whole network… |
| Account deleted from the main site's account area: member of the main site only, deleted from the network | `account-closing` |
| Account deleted: member of another site too, anonymised there | `account-closing` |
| Copy asked for by a member of /alpha/ on the account area, handed over by the main site | `account-export` |
| Uninstall | not covered in a browser: deleting the plugin removes the code the suite runs against. Integration: `UninstallNetworkTest` (network: ticked and not, and a network whose settings never moved), `UninstallSiteTest` (single site, ticked and not) |

## Network settings, and the network's screens

Every case of the move of the rules about people to the network, at every
layer and on both topologies. Unit tests run without WordPress (`make
test-unit`, `make test-unit-min`) and answer both by stubbing
`is_multisite()`. The integration suite runs twice, on the tests site as a
network (`make test-integration`) and on a throwaway single site (`make
test-integration-single`); CI runs both. In the Integration column **net:**
is a test that runs on the network and skips loudly on a single site,
**single:** the other way round, and **both:** one test that runs and passes
on each. The two end-to-end columns are the two topologies.

| Case | Unit (both, stubbed) | Integration | E2E single site | E2E network |
|---|---|---|---|---|
| Each setting's scope; menus, admin bar and dashboard profile are each site's | `OptionScopeTest` › what fits into a site's own theme… | net: `OptionScopeTest` › each scope is stored where it says · single: `SingleSiteTest` › every scope is stored in the site's own table | — | `network-admin` › another site keeps its menus… |
| On a network the plugin is network-activated or asleep: `Network: true`, activated from a site it is the network's, asleep on a site it was left on alone with a notice only for who manages the network's plugins; a network is always routed by scope | `NetworkGateTest` › the header…; a single site always runs it; a network runs it only when on for the whole network; asleep it only says so; the notice…; `OptionScopeTest` › on any network settings are routed by scope | net: `NetworkGateTest` › …a network only plugin and activated from a site it is the network's; …it sleeps and says so to the network only; `OptionScopeTest` › a network is always routed by scope · single: `NetworkGateTest` › on a single site it always runs and is the site's; `SingleSiteTest` › every scope is stored in the site's own table | `activity-log` (single site unchanged) | `lifecycle` › only for the whole network… |
| A hub setting read from another site follows every write (memo) | — | net: `OptionScopeTest` › the hub's copy…follows every write · single: nothing to follow, a single site is its own hub (`SingleSiteTest` › every scope…) | — | `isolation` › main site's sign-in settings… |
| Hub pages are the hub's: not drawn or routed on another site, links go to the hub | — | net: `NetworkSettingsTest` › the pages are the hub's · single: `SingleSiteTest` › the pages are the site's | `account-area`, `register` | `lifecycle` › a site made after…; `account-export`; `account-closing` |
| Who writes what, from where (single / network / hub / site) | `OptionScopeTest` › each place writes only its own scope; `NetworkPlacesTest` | net: `NetworkSettingsTest` › a site's screen cannot change or loosen…; another site writes only its own · single: `SingleSiteTest` › the site's screen sets the second step | `single-site` › the doors…are this site's to switch | `network-admin` › the network's doors on the main site's Access are drawn, not saved |
| A setting saved in Network Admin is read on every site | — | net: `NetworkSettingsTest` › …read on every site · single: `SingleSiteTest` › the site's screen sets the second step | — | `network-admin` › What is saved in Network Admin…; Every tab that moved saves… (sessions, proxy, passkeys, social sign-in, deleting the plugin) |
| Passkeys and social sign-in switched on their own tabs in Network Admin | — | net: `NetworkSettingsTest` › the network's doors are switched on their own tabs · single: `SingleSiteTest` › the doors are switched on Access and not on their own tabs | `single-site` › …do not repeat the switches Access has | `network-admin` › Every tab that moved saves… |
| User fields are the network's: added and deleted in Network Admin | — | both: `SettingsFileTest` › a field keyed like… | `admin-tools` › User fields | `network-admin` › a user field added in Network Admin… |
| Log settings are the network's; each site's report and "Empty it now" are its own rows; Network Admin has every site's report | `NetworkLogTest` › every site's rows are a tab of the network's… | net: `NetworkSettingsTest` › each place draws only its own tabs · single: `SingleSiteTest` › every screen and tab is on the site's dashboard; the log is the site's own… | `single-site` › Reports › Log settings keeps the way to empty… | `network-admin` › the log's settings…; another site keeps…; network › diluxone-users-reports › network-activity |
| Log: which table (the base prefix: the network's on a network, the site's on a single site) | `NetworkLogTest` › on a single site…; on a network…one table; on a network no site keeps a table of its own | net: `NetworkLogTest` › the table is the network's and every row carries its site · single: `SingleSiteTest` › the log is the site's own and there is nothing to move | `activity-log` › on a single site every row is the site’s | `activity-log` › a site’s report has its own rows… |
| Log: every row stamped with its site | `NetworkLogTest` › a row is stamped with the site…both ways | both: `ActivityLogTest` › every row carries the site it happened on · net: `NetworkLogTest` › the table is the network's… | `activity-log` › on a single site every row is the site’s | `activity-log` › a site’s report has its own rows… |
| Log: a site's report shows its rows only; the network's shows every site's and narrows to one | — | both: `ActivityLogTest` › the site's report draws its own rows; a site asked for is in the query… · net: `NetworkLogTest` › a site's report shows its own rows…; the network's report shows every site… | `activity-log` › on a single site… | `activity-log` › a site’s report has its own rows… |
| Log: “Empty it now” on a site vs in Network Admin; the network's asks for the network | — | both: `ActivityLogTest` › emptying it takes every row…; asks for the right to · net: `NetworkLogTest` › a site's button empties that site's rows only; the network's button empties every site's; …asks for the network · single: `SingleSiteTest` › the log is the site's own… (no network button) | `activity-log` › emptying it asks first | `activity-log` › emptying it on a site… |
| Log: retention purge — the network's, once, from the main site over every site; a single site's by itself | `NetworkLogTest` › the purge runs once for the network…; a single site purges its own table | both: `ActivityLogTest` › the purge drops what is past the retention… · net: `NetworkLogTest` › the network's retention is one purge…; the daily event is the main site's alone | — (time passing) | — (time passing) |
| Log: erasure takes a person's rows from every site in one query; the export names each row's site | — | both: `ActivityLogTest` › the erasure takes that person… · net: `NetworkLogTest` › erasing a person takes their rows from every site in one query | `account-area` › Your data | `account-closing` |
| Log on a multi-network install: the network's report, "Empty it now" and purge touch its own sites' rows only; erasure reaches every network | `NetworkLogTest` › the network's sites are the current network's; …groups small enough for one in | net: `NetworkLogTest` › the network reads, empties and purges its own sites' rows only; erasing a person reaches every network (a second network made as rows of `wp_site` and `wp_blogs`) · single: no other network | — | — |
| Log: a table from before rows had a site is brought up to date on first read, its rows the site's | — | both: `ActivityLogTest` › a table from before rows had a site… | — | — |
| Log move: fresh, re-run, big and batched with cron, a failed batch copied once, counts that do not add up keep the old table, first request | — | net: `NetworkLogTest` › the sites' old tables move in…; a big table moves in batches…; a batch that fails…; an old table whose rows do not add up…; the first request starts the move… · single: `SingleSiteTest` › …nothing to move | — | `migration` › each site’s old activity log moves… |
| Network Admin menu and every tab (answers, tab strip, layout) | `NetworkPlacesTest` | net: `NetworkSettingsTest` › each place draws only its own tabs · single: `SingleSiteTest` › every screen and tab… (no network tabs) | — | `network-admin` › Network Admin has the network's screens (one test per tab) |
| Network screens leave the sites' menus; refused by address on a site | `NetworkPlacesTest` | net: `NetworkSettingsTest` › the network's screens leave the sites' menus · single: `SingleSiteTest` › every screen and tab is on the site's dashboard | `single-site` › the menu carries every screen | `network-admin` › A site's menu keeps only what is its own; refused |
| A link to a screen goes where the screen is | — | net: `NetworkSettingsTest` › a link to a screen goes where the screen is · single: `SingleSiteTest` › every screen and tab… (all on this dashboard) | — | `network-admin` › the cards' links |
| A site's Overview names each moved area ("Managed by the network", "Set on …"), with the way there only for whoever can go | — | net: `NetworkSettingsTest` › a site administrator is told where things went… · single: `SingleSiteTest` › nothing is managed elsewhere | `single-site` › the Overview has no network tabs… | `network-admin` › another site…says where the rest went |
| Network Overview: hub, addressing, mapped-domain warning, conflicts table | — | single: `SingleSiteTest` › there is nothing to move (no notice) | — | `network-admin` › network › diluxone-users › network; `migration` |
| Social provider switched only from Network Admin | — | both: `SsoToggleTest` › off hides the button…; forgetting it… · net: …a site cannot switch a provider · single: …the site's screen switches a provider | `admin-effects` › a provider switched off | `network-admin` (providers tab) |
| Settings file: a site restores and exports only its own | — | both: `SettingsFileTest` › round trip, wipe does not travel, default not written back · net: …restored on a site of a network… · single: …restored on a single site restores everything | `admin-tools` › Settings as a file | — |
| Deleting the plugin: one network box; a site's Tools say so | — | net: `NetworkSettingsTest`; `UninstallNetworkTest` · single: `UninstallSiteTest` | `single-site` › Maintenance › Tools still asks | `network-admin` › …deleting the plugin; another site keeps… |
| "Show me what I chose" writes nothing of the network's | — | net: `NetworkSettingsTest` › a trial run writes nothing of the network's · single: `SingleSiteTest` › a trial run writes nothing | `preview` | — |
| 2FA: one network rule; a chosen role on any site; super admin | — | net: `MultisiteTest` › the second step is asked on every site alike; a chosen role on any site; a super admin… · single: `SingleSiteTest` › a chosen role puts somebody in scope · both: `TwoFactorFlowTest`, `AccountSecurityTest` | `two-factor` | `isolation` › The second step is the network's; `network-admin` › the second step required there… |
| Who may create an account: the network's registration, then the site's doors | — | net: `MultisiteTest` › the network decides…; on a closed network the doors start closed · single: `SingleSiteTest` › the site's own switch decides…; the doors start where "Anyone can register" is | `magic-link`, `register` | `doors` |
| Setup: activation, a site born on the network (no log table of its own), a deleted site (its rows and any old table go) | `NetworkLogTest` › a deleted site drops its own table and never the network's | net: `MultisiteTest` › a site born…; a deleted site takes its rows and its old table… · single: `SingleSiteTest` › activation sets the site up in its own table | — | `lifecycle` |
| Deactivation leaves no purge event | — | net: `MultisiteTest` › network deactivation clears the event on every site · single: `SingleSiteTest` › deactivation clears the event | — | `lifecycle` |
| Membership: the click, not the request; a closed site; a super admin | — | net: `MultisiteTest` › membership comes with the click…; a site that takes nobody…; a super admin is not made a subscriber · single: `SingleSiteTest` › joining the site changes nobody | — | `doors` |
| Sessions report: whose sessions a site lists; closing them asks for the site's administrator and for rights over that person | — | net: `MultisiteTest` › the sessions report…lists only its members · single: `SingleSiteTest` › …lists everybody signed in · both: `ClosedDoorsTest` › closing an administrator's sessions needs rights…; an administrator closes another person's sessions | `admin-tools` › Reports | `isolation` |
| Photo: read and deleted where it lives | — | net: `MultisiteTest` › a picture is read and deleted on the site that holds it · single: `SingleSiteTest` › a picture is read and deleted here · both: `AvatarSourcesTest` | `account-area` › Your photo | `isolation` |
| Add New User and the username | — | net: `MultisiteTest` › Add New User is left alone · single: `SingleSiteTest` › Add New User uses the e-mail as username | `admin-tools` › Add New User | `isolation` |
| Throttle per machine | — | net: `MultisiteTest` › one machine has one allowance on the whole network · both: `LoginRequestTest` | `magic-link` › asking twice | — |
| Deleting an account empties the log; taken off one site, only that site's rows go | — | net: `MultisiteTest` › …from the network empties every log; taking somebody off one site… · both: `ActivityLogTest` › deleting an account takes its rows | — | `account-closing` |
| Move: fresh network (nothing to take, marker written) | — | net: `NetworkSettingsTest` › on a fresh network… · single: `SingleSiteTest` › there is nothing to move | — | `lifecycle` › a site made after… |
| Move: the main site's values, the rest written down | `NetworkPlacesTest` › same setting; written down short | net: `NetworkSettingsTest` › the move takes the main site's values… (single: no move, above) | — | `migration` |
| Move: credentials only noted as different | — | net: `NetworkSettingsTest` › credentials are only noted… | — | — |
| Move: user fields are every site's, main site winning | — | net: `NetworkSettingsTest` › the fields are every site's… | — | — |
| Move: the wipe is not carried over | `NetworkPlacesTest` › every network setting moves but… | net: `NetworkSettingsTest` › the wipe is not carried over | — | — |
| Move: idempotent; batches, cron, marker last | — | net: `NetworkSettingsTest` › idempotent; the version is written last | — | `migration` › running it again does nothing |
| Move: a notice once, dismissed for good; the table stays | — | net: `NetworkSettingsTest` › the differences are announced once · single: `SingleSiteTest` › there is nothing to move (no notice) | — | `migration` |
| WP-CLI `wp diluxone-users network migrate` | — | — | — | `migration` |
| Uninstall on a network: the box ticked takes everything (network options, old per-site copies, the network's log and any old per-site log table, user meta) | — | net: `UninstallNetworkTest` › the network's one box takes everything… | — | — |
| Uninstall on a network: the box unticked takes nothing (the network's log and old tables stay), a site's old box does not count | — | net: `UninstallNetworkTest` › the network's box unticked takes nothing | — | — |
| Uninstall, a network whose settings never moved: the sites' own old boxes take nothing; the network's box takes everything | — | net: `UninstallNetworkTest` › the sites' own boxes are not the network's decision; the network's box decides even before the settings moved | — | — |
| Uninstall on a single site: ticked takes everything, unticked nothing | — | single: `UninstallSiteTest` › the box ticked…; the box unticked takes nothing | — | — |
| The pictures of the network's screens (Activity log › Activity and Log settings included) | — | — | — | `network-snapshots` (`make test-visual-network`) |

Every integration test not named in this table runs on both topologies and
passes on each.

## Not coverable in a browser

- **XML-RPC and application passwords** refusing a password-only sign-in when a
  second step is required: no browser speaks XML-RPC. Covered by the
  integration suite.
- **Cron clean-up of the activity log** after `diluxone_users_log_days`: time
  passing, not a page. Nor the cron batches of the move of each site's log:
  the browser suite moves it with WP-CLI, and the integration suite runs the
  batches one by one.
- **The e-mail actually leaving the server**: the mu-plugin catches every
  message on `pre_wp_mail`, which is the point — the suite reads the message
  the site composed, not a mail server's delivery.
- **Real OAuth providers** (the twelve networks): the suite uses the mu-plugin's
  `mock` provider, which exercises the same code path through
  `pre_http_request`; a real provider needs credentials and a consent screen.
