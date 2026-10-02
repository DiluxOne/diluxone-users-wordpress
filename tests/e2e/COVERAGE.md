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
| Nobody's form for somebody signed in; `title="yes"`; the site's heading, intro, small print, logo; backdrop picture | `signin-page` › What the sign-in page draws, and for whom |
| Every `?diluxone-users=` state on the sign-in, second-step, reset and register screens, with its tone and the site's rewrite (all 17 messages); an unknown state draws nothing | `signin-page` › each state draws its message with its tone… · a state nobody knows… |
| `login_email` from a real submit; the sent screen keeps the address out of the URL, "Use a different address", the site's sent texts and round icon | `signin-page` › a real non-address… · The "sent" screen |
| Layout `auto`, the arrow keys on the tabs (remembered), the "or" hidden between tabs | `signin-page` › The arrangement of the ways in |
| Link request: password-only site answers `error`; per-inbox wait ignores capitals; per-machine burst; registration burst; role fallback; unknown public name | `signin-page` › The link request, from the outside |
| Second step: account lock (`locked`, the wait doubling, cleared by a right code), e-mail code expiry, forged attempt key | `signin-second-step` › The account's own lock, across attempts |
| App codes: replay refused, activation code spent, "Or use:", app the only method | `signin-second-step` › The authenticator app's codes |
| Link rule `never` / `auto` with the app, 2FA off, chosen roles, trust box with no days, rewritten code mail | `signin-second-step` › Who is asked, and on which door |
| Application passwords and XML-RPC refused once 2FA applies | `signin-second-step` › The doors with no screen… |
| wp-login.php's second step: resend + `sent`, `locked`, trust box, "Or use:", `retry`, no attempt, a spent link with no page | `signin-wp-login` › wp-login.php's own second step, past the plain code |
| Takeover: `action=register`, `lostpassword` per setting, interim-login, password-protected page, no page chosen, WordPress registration shut on link-only, profile guard `block` and roles, the "I forgot" target, the hatch on a reset | `signin-wp-login` › wp-login.php under the takeover |
| Safe mode closes the social trip, the passkey dialogue, the site reset and the profile guard | `signin-wp-login` › The emergency switch closes the rest |
| wp-login.php brand colour, radius, logo; no-cache on a loose shortcode page | `signin-wp-login` › wp-login.php in the site's colours… |
| Reset: forged and spent key `expired`, cookie lost before saving, the optional-password note, a new password ends other sessions | `signin-reset-register` › Choosing a new password on the site's page |
| Passkeys: switched off (button, way, script, ajax 400), 61st challenge 429, verify/where reach the dialogue, a key another account holds, add and remove mails, another account's key neither renamed nor removed, never asked 2FA, new-device notice | `signin-passkeys` › Passkeys, off the first path |
| Passkeys in a browser with no WebAuthn | `signin-passkeys` › A browser with no passkeys at all |
| Social: replayed return, return in another browser, network off mid-trip, unknown/unconfigured route, configured-but-off, trip burst | `signin-social` › A social return, tampered with |
| Social: link-by-email off, linked identity with another address, scope some with a disallowed role, social buttons on the registration form | `signin-social` › Who a social identity opens |
| Social linking: `taken`, `linked` + security mails on link/unlink, unlink without its nonce | `signin-social` › Linking from the account, off the plain path |
| Social then 2FA; new device after social | `signin-social` › What a social sign-in sets off |
| Social button skin/shape/contents/rows/words on the real page | `signin-social` › The buttons on the real page |
| The ways in at 390px: split stacks, tab labels fold, social one column, register / 2FA / reset fit | `signin-mobile` |
| WP-CLI on a single site: `login` link opens once, `--send` mails it, bad and unknown addresses refused, the network commands say it is not a network; "Join this site" posted on a single site has no handler | `my-account-entry` › Join, templates and WP-CLI on a single site |

## Registration

| Feature / state | Covered by |
|---|---|
| The site's form: required field, link, answers saved (`registered`) | `register` |
| `missing` with the browser's check off, `taken`, `closed`, `slow`, `email` | `register` |
| A field added in the dashboard shows on the form | `admin-tools` › User fields |
| Role a stranger becomes: no role that edits the site | `admin-settings` › the role a stranger becomes |
| The done screen's words, the by-link screen, a POST after closing (`closed`), WordPress refusing the account (`error`), the heading/intro/small print | `signin-reset-register` › The registration form, off the plain path |

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
| The pieces on a page of their own (menu, fields, sessions, social networks, photo, public name and its availability check, notifications): nothing to a stranger, each one working | `account-pieces` |
| Sections: turned off, custom section added and deleted | `admin-settings` › a section turned off; `admin-tools` › Account area › Sections |
| Sections: own content with a shortcode is the section (placement `replace`) | `admin-account-sections` › its own content is the section |
| Sections: rename keeps id and address | `admin-account-sections` › renamed, it keeps its id |
| Sections: own address served; a taken address, a plugin section's address and no name refused (`error`) | `admin-account-sections` › its address is the one written |
| Sections: only some roles (editor sees, subscriber not, roles kept on “everybody”) | `admin-account-sections` › “only some roles” |
| Sections: content before / after / instead of a code section | `admin-account-sections` › content “before”… |
| Sections: a code section has no Remove; a hand-built delete removes nothing | `admin-account-sections` › a section from code has no Remove |
| Sections: toggle off and back on | `admin-account-sections` › switched off and on again |
| Sections: “on but not showing” warning | `admin-account-sections` › on, but with nothing to show |
| Front page cards: one off, all off (`cards_shown`), kept by another section's save | `admin-account-sections` › Account area › the front page’s cards |
| Order: drag (saves at once), “Save the order” without script | `admin-account-sections` › Account area › the order |
| Your data: delete switch alone, both off | `admin-account-sections` › Account area › Your data |
| Section and data save boxes: dirty, undo, leaving asks | `admin-account-sections` › the box that saves a section |
| Public name: switch on/off, a hand-made POST with it off saves nothing | `admin-account-handle` › “let people choose” |
| Public name: length, spaces dash/refuse, cooldown, the site's reserved names | `admin-account-handle` |
| Fields on details: every type drawn, a good answer stored in its shape, a bad one refused | `admin-fields-types` › every type, added from the screen |
| Fields: phone halves read back, required closed list, date in another shape, yes/no unticked | `admin-fields-types` |
| Fields: only a few times (counter, same answer free, closed, forced value refused, admin spends nothing) | `admin-fields-rules` › “only a few times” |
| Fields: never (read-only, forced value refused, not on registration; admin box) | `admin-fields-rules` › “never” |
| Fields: required / active set on the screen, answers kept | `admin-fields-rules` › User fields › required and active |
| `[diluxone_users_fields group="main"|"extra"]` draws and saves one block | `admin-fields-rules` › How to use them |
| Section by `?section=`; unknown slug opens the first; a section off or role-limited is not drawn at its own address; every section off draws nothing; Notifications absent when nothing is sent or offered | `my-account-sections` › Which section opens |
| Home: "Hello, <first name>", cards (details x/y, sessions, networks linked) linking to their sections, a card hidden by the site, every card hidden | `my-account-sections` › Home |
| Header pieces (photo, member since, Edit profile, no header `--bare`), cover colour/picture/dim, width, nav style and alignment (unknown value → default), nav shortcode `--column` | `my-account-sections` › The header and the look |
| Site-written section content before/after/replace with shortcodes; custom section for some roles (none ticked → nobody); section renamed, moved first, own slug | `my-account-sections` › What the site writes into a section |
| Every field type drawn, cleaned and drawn back (textarea, email, phone dial+number, country, url, number, date, select, datalist, checkbox) | `my-account-details` › every type is drawn… |
| Forged closed-list, country, address, impossible date and infinite number not stored | `my-account-details` › a value off a closed list… |
| A ticked tick box cannot be unticked from the account | `my-account-details` › a tick box ticked can be unticked… |
| Edit `never` (readonly, note, server refuses) and `limited` (counted, locks, server refuses) | `my-account-details` › a field nobody may change…; a field that may change once… |
| A field keyed `wp_capabilities` / `diluxone_users_closed` never written | `my-account-details` › a field named after a key… |
| `[diluxone_users_fields group="extra"]` draws and saves that block only; no active field draws nothing | `my-account-details` › [diluxone_users_fields group="extra"]…; with no active field… |
| Photo: too heavy (limit in the note), over 6000 px, no file, replace deletes the old attachment, uploads off refused by hand, `[diluxone_users_avatar]` lands on details | `my-account-details` › Photo |
| Public name: site's reserved list, taken by another account, min/max (box and server, capped 50), spaces dash/reject with the note, e-mail and empty refused, cooldown (locked, no Save, POST refused), switched off (POST writes nothing), handle login off sends no link, `[diluxone_users_handle]` lands on details | `my-account-details` › Public name |
| Public name box: preview of the address, check link asks at once, own-name and too-short reasons, back to the saved value asks nothing, "could not check" | `my-account-details` › Public name: the box as it is typed in |
| Notifications: `default_off` (unticked, quiet until ticked, then announced with the device and the account link), security notices off, locked "Always sent" rows, nothing to choose → no form | `my-account-security` › Notifications |
| Sessions: another account's session cannot be closed with a good nonce; row names browser and system; a store that cannot address one session has no per-row Close; "close the others" forgets trusted browsers | `my-account-security` › Sessions |
| Two-step: "How you get in" without a password; wrong app code (`badcode`); app removed with a live code; one distinct mail per change; backup codes shown once and counted; required (no off, POST `required`); not offered — off or role left out — (`notoffered`); `nomethod`; off forgets trusted browsers; the link notice | `my-account-security` › Two-step verification |
| Passkeys: panel absent when off; add and remove mailed, `passkeyoff` notice; the same authenticator refused on the page; another account's key neither renamed nor removed, nobody mailed | `my-account-security` › Passkeys (Chromium) |
| Your data: dialog closes on backdrop and Esc; duplicate request `error`; copies off refused by hand; section off refused by hand; button label and pills (pending → ok); no-mail warning for administrators only | `my-account-data` › Asking |
| Confirm links: copy link with no session → `ready`; tampered key (mail and account address) and reused key → `expired`; last step with a wrong key `expired`, by another account `other`; a Tools request keeps WordPress's flow; a tampered held cookie is ignored | `my-account-data` › The links in the e-mails |
| Closing with published content: `deleted-<id>` shell, no role, post kept, every plugin meta erased, "account deleted" mail, password, social identity and passkey do not reopen it | `my-account-data` › Closing an account |
| Copy zip holds the plugin's groups (details with field and public name, access); a vanished file answers 404 and the Download goes | `my-account-data` › The file |
| Tools › Export/Erase Personal Data run the plugin's exporters and erasers; Privacy policy guide text | `my-account-data` › WordPress’s own tools |
| A data e-mail rewritten on Notifications › The e-mails reaches the inbox, link intact | `my-account-data` › The e-mails |
| Linked accounts: notice and link/unlink mails; identity of another account → `taken`; forged provider unlinks nothing; `only="linked"`/`"available"` with empty words, the link back to the shortcode page, no provider | `my-account-entry` › Linked accounts |
| On a phone (390 px): tabs scroll or wrap, side menu back across the top with the underline as a bottom border, cover header wraps, session row Close under the text, passkey edit inset | `my-account-mobile` |

## The site's own menu and WordPress's screens

| Feature / state | Covered by |
|---|---|
| Sign-in item for a stranger; the person with sections, sign-out; three styles; no location | `site-menu` (classic menu from the mu-plugin, `POST /menu`) |
| Account area › In the site menu tab saves location and style | `site-menu` › Account area › In the site menu |
| wp-login.php branding (Design › WordPress's screens) | `admin-effects` › Design › WordPress's own screens |
| Toolbar hidden, profile.php sent to the account area | `admin-effects` › Account area › The WordPress dashboard |
| Users list Access column; forget authenticator; unlink a network | `admin-tools` › WordPress's own Users screens |
| Add New User takes the e-mail as username | `admin-tools` › Add New User; `network/isolation` › Add New User |
| Users list Access column pills | `wp-screens-users` › each person’s ways in, as pills |
| Profile block reads handle, last seen, 2FA, passkeys, networks; Every session → report | `wp-screens-users` › it reads the public name… |
| Profile block for somebody with nothing; none on own profile; public name none — | `wp-screens-users` › for somebody with none of it…, somebody who never chose a public name |
| Profile block removes a passkey | `wp-screens-users` › a passkey ticked… |
| Add New User note and sync; e-mail as username with the script off | `wp-screens-users` › the username row steps aside…, with the script switched off |
| Toolbar hidden for some roles; kept for whoever edits users; Edit profile → account area on the site | `wp-screens-users` › (The toolbar on the site) |
| Profile closed (`block`, 403), profile for some roles, toolbar for some roles, administrators keep it, toolbar user menu to the account | `admin-account-dashboard` › The WordPress dashboard |
| Account page chosen, Create the page, None (rail pending, toolbar keeps profile.php) | `admin-account-dashboard` › The page |
| In the site menu: place, look, forged look dropped, rail names the menu | `admin-account-dashboard` › In the site menu |
| Dashboard item for an editor only; sections under the name follow sections turned off or role-limited; the menu's style printed only signed in with a location | `my-account-entry` › The person in the site menu |
| A theme's own `diluxone-users/account-guest.php` replaces the plugin's | `my-account-entry` › a theme’s own copy of a template… |

## The dashboard

| Screen | Covered by |
|---|---|
| Every tab of every screen renders, no notice, no layout breakage | `admin-settings` › Every settings screen renders; `admin-layout` |
| Pictures of every tab, Your brand on each answer, Design on each sign-in and account shape | `admin-snapshots` (`make test-visual`, not in CI) |
| Pictures of the public pages: the sign-in page on every shape, stacked and in tabs, one way in, its words, sent, expired; the second step; a new password; registration open, by link, closed; the account to a stranger, on both shapes and both menus, and each section | `front-snapshots` (`make test-visual`) |
| The same public pages and the dashboard's phone-layout screens at 390px | `front-snapshots`, `admin-mobile-snapshots` (`visual-mobile`, `make test-visual`) |
| What the pictures are drawn from: every setting they show and the ten example people, pinned before each | `support/visual-state.ts` |
| The public pages measured at 640, 560, 480 and 390 as well (sign-in stacked and in tabs, registration, account to a stranger, every section on both menus) | `admin-layout` › The public pages hold together |
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
| “Create the page”: made, chosen, announced, drawing its form; refused without the nonce | `create-page` |
| User fields: add, show, delete | `admin-tools` › User fields |
| User fields › Suggested fields: seeded names only, the rest added from the tab | `coexistence` › A fresh site asks for a name… |
| The wordpress.org listing screenshots | `listing-screenshots` (`make screenshots`, writes files) |
| Every admin_post / wp_ajax handler, admin_init form, social switch, Tools button, preview and Try token refuses no nonce, a forged nonce, a role without the right (own valid nonce) and a stranger, and writes nothing | `refusals` › Every handler that writes refuses what it should |
| Every settings panel refuses a tampered real form (no nonce / forged), an editor and a subscriber with their own nonce, and a stranger | `refusals` › Every settings panel refuses a save it should not take |
| Another account's data export download, and "Yes, delete my account" with another's request and key | `refusals` › A data request is its owner’s alone |
| The network's WP-CLI commands refuse a single site | `refusals` › The network’s WP-CLI commands refuse a single site |
| Save box in the rail of every tab, button tied to a form; state line clean/dirty; leave with yes asks once; `beforeunload` on close, none after a save; two forms on one tab ask; a formtarget press keeps the warning armed | `admin-framework` › The box that saves |
| A save refused by the server keeps the stored answer and shows only the error | `admin-framework` › A save the server refuses |
| Choice groups open only while chosen; "only some roles" picker shows/hides roles and keeps them | `admin-framework` › What hangs off an answer |
| Copy box selects all; rows-per-page auto-submits; live provider test opens a pop-up; safe-mode notice hidden from an editor | `admin-framework` › The small behaviours |
| Template pieces filled from the template; "Back to the default"; a colour of its own (stored '' / colour, painted on the account page); media picker stored by id and drawn on the sign-in page, removed | `admin-framework-design` › Templates and defaults; A colour of its own; The media picker |
| Preview stage: phone/desktop widths, "See it big"; a late live answer never draws over a newer one | `admin-framework-design` › The stage |
| "Show me what I chose": for the presser only (stranger, other admin see the saved look), only while its key lives | `admin-framework-design` › The trial page |
| Field editor dialog: open, Cancel, save, fallback to the field screen when the fetch fails; type shows only its rows | `admin-framework-more` › The field editor |
| Account question: Escape and backdrop are "no" | `admin-framework-more` › The account area’s own question |
| Measured theme colours kept in localStorage and painted early on the next page | `admin-framework-more` › The theme’s colours, remembered |
| E-mail notices: subject per language, put back, the mail follows; summary reads rules and the link's delivery | `admin-framework-more` › E-mail notices: the languages and the summary |
| Overview: cards (accounts, sessions, fields, social), the 15-minute cache, card links | `admin-overview` › the cards |
| Overview: first steps (button only on the first undone, gone when done) | `admin-overview` › first steps |
| Overview: usage "N of M (x%)" and bar width; doors panel (method, mail failing, all doors); asked panel (active fields, none); rail links | `admin-overview` › what your people use; how your people get in; what is asked of them |
| Access › Summary: seven rows by pill and link for three door shapes; no Save; emergency URL and WP-CLI line | `admin-access-summary` |
| Access › The sign-in page: page selector (and None), `auto`, hand-sent `wp` without a password stored as `auto`, lost password `wp`/`site`/`link` through the tab, not-now notes and the wp-login look note | `admin-access-page` › Access › The sign-in page |
| Create the page: the sign-in page; the account page's sections answer at once; an unknown page role is refused | `admin-access-page` › Create the page |
| Access › Ways in: neither box refused (browser and server); throttle; public-name sign-in on/off; handles-off note; passkey switch on/off (sign-in and account); password off drops the second screen; a setting pinned from code | `admin-access-ways` |
| Access › Arrangement: `tabs`, `auto` threshold, first tab, junk posted by hand, passkey note, real drag saves on drop, live preview | `admin-access-arrangement` |
| Access › Registration: nobody registers with every door unticked (all doors shut, public effects); social door through the tab; WordPress's own form ↔ `users_can_register`; locked while link-only (Settings › General notice); door notes and rail; the at-least-one sentence | `admin-access-register` |
| Access › Messages: one wording per language, leaving with an edit asks, an emptied box is the plugin's words, the rail count | `admin-access-messages` |
| Security › 2FA “to whom”: some roles ticked, saved, an administrator asked and a subscriber not | `admin-security-twostep` › to whom: “only some roles” |
| Security › 2FA methods ticked on the screen are the account's boxes; off with none saved — (a method that is off cannot be ticked back on) | `admin-security-twostep` › with what |
| Security › 2FA link rule always / never / auto through its radios, rail and summary agree | `admin-security-twostep` › somebody who came by link |
| Security › 2FA remember days 0 (no trust box, asked again) and 7 (cookie of seven days) | `admin-security-twostep` › remembering a browser |
| Security › 2FA not-now lines; rail links; Summary rail links | `admin-security-twostep` › “not now”, the rail’s links, the Summary’s rail |
| Security › Passkeys “which are accepted” → `authenticatorAttachment` | `admin-security-passkeys` › “only the device being used” |
| Security › Passkeys fingerprint → `userVerification` on adding and signing in | `admin-security-passkeys` › “require the fingerprint” |
| Security › Passkeys tab never writes Access's switch; not-now; rail domain; summary count | `admin-security-passkeys` › saving this tab…, while they are off…, the summary counts |
| Security › Sessions “without it” days → session expiry | `admin-security-sessions` › “without it” |
| Security › Sessions summary rows (their own sessions, signed in now); rail links | `admin-security-sessions` › “each person sees…”, the rail leads |
| Security › Proxy header used for the visitor's IP (sessions report, activity), another header and None ignored | `admin-security-sessions` › the header chosen is the address |
| Security › Proxy trusted ranges walked past; summary active; forged header not saved; rail shows reader's IP | `admin-security-sessions` › a trusted proxy…, a header that is not on the list…, the rail says |
| Social › provider Getting started (redirect URL, console, steps, to Settings) | `admin-social-providers` › Getting started |
| Social › credentials saved, secret never printed back, empty box keeps it | `admin-social-providers` › Settings: the client ID and the secret |
| Social › changed credentials reset the test and take the button down | `admin-social-providers` › changed credentials |
| Social › live test round trip in its window, “It works”, Close reloads; then the button goes up | `admin-social-providers` › the live test: its window |
| Social › live test fails: cancelled, no token, empty profile | `admin-social-providers` › the live test fails |
| Social › Usage tab; grid's four card states; Turn it on / off from card and provider screen; untested not turned on | `admin-social-providers` › Usage, the grid’s card, “Turn it on” on the card…, a provider that has not passed the test |
| Social › Rules pressed on the screen: link by e-mail, verified only, some roles; rail follows registration | `admin-social-rules` › (all) |
| Status › page checks (none, gone, draft, right; a page without the shortcode draws its form) | `admin-status-checks` › the account page |
| Status › mail unknown / going out / failing; ways in off with link only and mail failing; headline | `admin-status-checks` › outgoing mail…, mail failing… |
| Status › rewrite rules heal; social saved but off; HTTPS; usage counts and version | `admin-status-checks` › rewrite rules…, a social provider…, HTTPS…, the usage counts |
| Status › lockout: WP-CLI line works; emergency door is this site's | `admin-status-checks` › the WP-CLI line…, the emergency address… |
| Tools › close every session (the admin too), sessions put back | `admin-status-tools` › “close every session” |
| Tools › unknown address, code not sent, notice once, unknown tool, uninstall box | `admin-status-tools` › an address nobody has…, a fresh code that cannot be sent…, the result is said once…, a tool nobody has…, what deleting the plugin takes |
| Tools › export content; import refusals (not an export, too big, none); foreign keys filtered | `admin-status-tools` › the exported file…, a file that is not an export…, a file that carries more… |
| Reports › Open sessions: search typed, Clear, Refresh, per page, pages, nobody matches, expired, name → profile | `admin-reports-sessions` › (first three) |
| Reports › closing sessions: done notice, signed out, trusted 2FA browsers asked again | `admin-reports-sessions` › closing somebody’s sessions |
| Reports › Activity: event list, dates, Clear, per page and pages, person cell | `admin-reports-activity` › (Activity describe) |
| Reports › Log settings: days, forged group dropped, account and security groups record only while ticked | `admin-reports-activity` › the days kept…, a group that is not one…, “changes to the account”… |
| Reports › Delete every row asks with the number and says how many (log kept and put back) | `admin-reports-activity` › “Delete every row” |
| Account area › Summary rows follow the settings | `admin-account-dashboard` › Summary |
| User fields: rename keeps key, reserved keys refused, “Avatar” gets a free key, unknown field, nolabel | `admin-fields-rules` › editing a field |
| User fields: WordPress's own keep their type and cannot be deleted | `admin-fields-rules` › WordPress’s own two |
| User fields: delete asks, keeps answers; arrows reorder; nobody-registers warning; dialog (Cancel, close, Escape, Edit, save; full width at 400px) | `admin-fields-rules` |
| User fields › Suggested: nothing ticked (`nosuggested`), all added | `admin-fields-rules` › suggested fields |
| User fields: the rows each type shows | `admin-fields-types` › the rows the form shows for each type |
| Design › Brand: site writes it, accent, corners + default, controls, button styles, icons, soft notices, logo via media library, theme palette map, live preview | `admin-design-brand` |
| Design › Sign-in: shapes, forged shape, picture and side, panel words, intro, legal sanitised, sent screen words and envelope, emptied box falls back, live preview | `admin-design-login` › The sign-in page |
| Design › Registration: intro and done, warning, live preview | `admin-design-login` › The registration page |
| Design › Account: cover preset, header pieces, cover colour/picture, menu style/align, forged values, spacing + defaults (row width), ground, width, phone wrap, live preview | `admin-design-account` |
| Design › Social: finish on the sign-in buttons | `admin-design-more` › Social buttons |
| Design › Photo: largest size | `admin-design-more` › Profile photo |
| Design › wp-login.php: exact background, own mark | `admin-design-more` › WordPress’s own screen |
| Design stage: phone/desk widths, zoom; trial only for its maker and gone after its key | `admin-design-more` › the stage |
| Design studio on a narrow window | `admin-design-more` › on a narrow window |
| E-mail notices › Rules: never, off by default, always, the security rule, forged policy | `admin-notices` › the rules |
| E-mail notices › Summary: rows, failed delivery | `admin-notices` › the summary |
| E-mail notices › E-mails: missing `{link}`/`{code}` refused, back to the plugin's words, emptied boxes, unchanged save, second-step / new-device / security templates, another language | `admin-notices` › the e-mails |

## On a network (`network/`)

| Scenario | Covered by |
|---|---|
| A site that is not the hub draws doors to the hub's sign-in, registration and account, with the way back | `doors` › the pages that held the forms… |
| From /beta/, through the hub, back on /beta/: password, e-mail link, social, passkey, second step, registration | `doors` › from /beta/: … (see “The hub” below) |
| Member of /alpha/ signing in for /beta/ joins, under “every site”, only by opening the link | `doors` › …not a member there until they open the link |
| Membership policies, removals, “Join this site”, sync, the log's From | `membership` (see “Membership on a network” below) |
| Network `registration=none`: no door of the hub creates anybody | `doors` › “Registration is currently turned off”… |
| Network `registration=user`: the link makes the account, and a member of the site it came from | `doors` › “User accounts may be registered”… |
| The main site's sign-in settings are the sign-in /alpha/ and /beta/ send to | `isolation` › The main site’s sign-in settings are every site’s |
| Reports › Sessions on /alpha/ lists only its members | `isolation` |
| Activity log: a sign-in on /alpha/ is on /alpha/'s report and not /beta/'s; Network Admin › Activity log has both, with their site, narrowed by the filter and by the Site cell | `activity-log` › a site’s report has its own rows… |
| Activity log: “Empty it now” on /alpha/ asks first and takes /alpha/'s rows only; in Network Admin it asks first and takes every site's | `activity-log` › emptying it on a site… |
| Activity log: a site's report carries its own button, never the network's report or button | `activity-log` › a site’s report carries the button… |
| Activity log: a site's old table moved in by `wp diluxone-users network migrate`, with its site, old table dropped, second run does nothing | `migration` › each site’s old activity log moves… |
| Add New User on a subsite | `isolation` |
| Photo uploaded on the hub's account is the same in /beta/'s menu | `isolation` |
| Photo removed on the hub's account deletes no /beta/ file with the same id | `isolation` |
| 2FA required of administrators: an administrator of /alpha/ signing in for /beta/ as a subscriber is asked, on the hub; a password posted to /beta/'s own wp-login.php is asked too | `isolation` › The second step is the network’s |
| Social identity linked on the hub's account reaches the same account from /beta/ | `social` |
| A new site used from its public pages first, nothing in debug.log | `lifecycle` |
| Only for the whole network: a site's Plugins screen has no Activate; left on for one site alone it does nothing there and asks super admins to network-activate; back on for the network; nothing in debug.log | `lifecycle` › only for the whole network… |
| The pieces of the account on a page of /beta/: a door to the account on the hub for each, nothing to a stranger; on the hub the forms | `account-pieces` |
| Account deleted from the main site's account area: member of the main site only, deleted from the network | `account-closing` |
| Account deleted: member of another site too, anonymised there | `account-closing` |
| Copy asked for by a member of /alpha/ on the account area, handed over by the main site | `account-export` |
| A site on a domain of its own: named on the network's Overview, warned on its own dashboard, its wp-login.php its own | `network-admin` › A site on a domain of its own |
| Uninstall | not covered in a browser: deleting the plugin removes the code the suite runs against. Integration: `UninstallNetworkTest` (network: ticked and not, and a network whose settings never moved), `UninstallSiteTest` (single site, ticked and not) |
| The hub's Access: every tab renders; refused on /alpha/ and /beta/; Ways in draws the network's doors locked with the notice; Registration shows WordPress's form as the network has it | `admin-access` › The hub’s Access |
| "Nobody can register" saved on the hub shuts the doors /alpha/ and /beta/ lead to | `admin-access` › “nobody can register”, saved on the hub… |
| The sign-in page and the wp-login.php answers chosen on the hub are what /beta/ gets | `admin-access` › the sign-in page chosen on the hub… |
| Create the page on the hub; refused on another site | `admin-access` › Create the page, on a network |
| The hub's live preview writes nothing | `admin-access` › The live preview, on the hub |
| Overview: hub's "Set somewhere else"; site admin sees no links they cannot follow; numbers per site; empty site | `admin-overview` › A site’s Overview on a network |
| Network Admin Overview: hub card, site count, network screens; uninstall rail state | `admin-overview` › Network Admin’s Overview |
| Field editor dialog in Network Admin; safe-mode notice for a site admin, not an editor | `admin-framework` |
| Signed in, the account page on /beta/ is a door to the hub's account, no section, no form | `my-account-hub` › signed in, the account page on /beta/… |
| Every account form posted to /beta/admin-post.php goes to the hub (account or sign-in page) before its handler, nothing written | `my-account-hub` › Forms posted to /beta/ › each goes to the hub… |
| The last step, the download and the confirmation link used on /beta/ close nothing and hand over nothing | `my-account-hub` › the last step, the download and the confirmation link… |
| An administrator of /beta/ (subscriber on the hub) closing from the hub: shell, no /beta/ role, deleted mail; a super admin offered no erasure and refused by hand; a social identity linked before closing does not reopen the shell | `my-account-hub` › Closing an account on a network |
| WP-CLI: `login --send`, its refusals, membership sync while unconfirmed or under another policy | `my-account-hub` › WP-CLI on the network |

## The hub: every door on a network leads to one site, and back

Every case at every layer, on both topologies: unit tests are pure and run
once; integration runs on the network (`make test-integration`) and on a
single site (`make test-integration-single`), each network case in
`NetworkHubTest` having a single-site counterpart in the same class that
asserts nothing is sent anywhere; the single-site browser suite runs unchanged
and green, which is its half of every row.

| Case | Unit | Integration | E2E single site | E2E network |
|---|---|---|---|---|
| A return address is an http(s) address on a site of this network: foreign host, protocol-relative, backslashes, encoded, user names, control characters dropped | `NetworkHubTest` › an address on a site of the network…; anything else is dropped | net: `NetworkHubTest` › a foreign or malformed return address is dropped · single: …an address elsewhere is not one either | — (unchanged) | `doors` › a way back to somewhere that is not the network is dropped |
| Every door on another site is the hub's with `redirect_to`: the plugin's sign-in and registration, `wp_login_url()` (+ `reauth`), `wp_registration_url()`, `wp_lostpassword_url()`, the account | — | net: `NetworkHubTest` › every door on another site leads to the hub… · single: …every door is the site's own | — (unchanged) | `doors` › the pages that held the forms… |
| The site's menu: “Sign in” to the hub and back; the person's item to the account on the hub | — | net: `NetworkHubTest` › the site's menu leads to the hub · single: …every door is the site's own (menu) | `site-menu` (unchanged) | `doors` › /beta/’s menu |
| Shortcodes on another site draw a door to the hub, never a form; the pieces draw nothing to a stranger | — | net: `NetworkHubTest` › the shortcodes on another site are doors… · single: …the shortcodes and forms are the site's | `account-area`, `register` (unchanged) | `doors` › the pages that held the forms…; `lifecycle` |
| A form posted to another site goes to the hub, writing nothing | — | net: `NetworkHubTest` › a form posted to another site goes to the hub · single: …the shortcodes and forms are the site's | — | — |
| Which wp-login.php requests go to the hub and which stay | `NetworkHubTest` › which wp-login requests stay on the site | net: `NetworkHubTest` › wp-login on another site goes to the hub with its action and return; what a site keeps for itself stays there · single: …wp-login is left alone | `password-login` (unchanged) | `doors` › /beta/wp-login.php…; /beta/wp-admin/…; what /beta/ keeps for itself… |
| The return cookie: HttpOnly, Lax, twenty minutes, read by the password form, spent by the first sign-in, then gone; a cookie written by hand is checked again | — | net: `NetworkHubTest` › the return address is held in a short-lived httponly cookie and spent once; a foreign… · single: …no return is held or used | — | `doors` (every way in) |
| Coming back makes a member of the site under “every site”; under “click” and “invite” only of the hub, and the page says so | — | net: `NetworkHubTest` › …spent once; under click or invite the way back joins only the hub and says so · single: …no return is held or used | — | `doors` › from /beta/: … a member; `membership` › Whoever asks; By invitation |
| Every way in comes back: e-mail link (another browser: the hub's front page), password, social (state still checked), passkey, second step, registration | — | net: `NetworkHubTest` › the e-mail link comes back…; a password comes back…; a social sign-in comes back and keeps its state check; the second step keeps the way back · single: …every way in lands where it always did | `magic-link`, `password-login`, `sso`, `passkeys`, `two-factor`, `register` (unchanged) | `doors` › from /beta/: by e-mail link…; a password…; by a social account…; by passkey…; the second step…; register from /beta/… |
| Social sign-in is the hub's: one redirect URI, the `/sso/` route on the hub only, an old address on another site starts nothing | — | net: `NetworkHubTest` › social sign-in and its route are the hub's · single: …social and passkeys are the site's own | `sso` (unchanged) | `doors` › by a social account…; `social` |
| Passkeys belong to the hub's domain (RP ID and origin), and another site's endpoint says where to go | — | net: `NetworkHubTest` › passkeys belong to the hub's domain · single: …social and passkeys are the site's own | `passkeys` (unchanged) | `doors` › by passkey… |
| The account's route is the hub's only | — | net: `NetworkHubTest` › the account route is the hub's · single: (the site is the hub) | `account-area` | `lifecycle` |
| The network's hosts, and only those, are safe redirects; the list follows sites added, changed and deleted | — | net: `NetworkHubTest` › the network's hosts and only those…; a site on a domain of its own is found and told · single: …an address elsewhere is not one either | — | `doors` › a way back… |
| A site on a domain of its own: detected, its wp-login.php left alone, warned on the Overview and on its dashboard | `NetworkHubTest` › a domain of its own is told from the network's | net: `NetworkHubTest` › a site on a domain of its own is found and told · single: …there is no domain of its own | — | `network-admin` › A site on a domain of its own |

In the browser, case by case:

| Feature / state | Covered by |
|---|---|
| The hub's 12 account actions posted to /beta/ go to the hub and write nothing (guest and signed in, valid nonce) | `refusals` › A form of the hub’s, posted to /beta/ |
| Forgotten password from /beta/: hub's form, mailed link, new password signs in from /beta/, old one refused | `hub-routing` › A forgotten password, from /beta/ |
| A reset link opened on /beta/ stays on /beta/ | `hub-routing` › a reset link opened on /beta/ is answered on /beta/ |
| action=register and reauth carried to the hub; interim-login stays | `hub-routing` › registration and a session to be confirmed again |
| wp_registration_url() on /beta/ open/closed | `hub-routing` › wp_registration_url() on /beta/ |
| Logout on /beta/ ends the network session | `hub-routing` › signing out on /beta/ ends the session |
| Already signed in + hub sign-in page with redirect_to goes straight back | `hub-routing` › somebody already signed in |
| [diluxone_users_account] for somebody signed in is an account door; login/register draw nothing; register door goes to sign-in when the form is closed | `hub-routing` › The shortcodes on /beta/ for somebody signed in |
| /beta/'s toolbar account item is the hub's account; the hub's menu Sign in has no way back elsewhere | `hub-routing` › The admin bar and the menu |
| wp-signup.php → wp-activate.php: member everywhere under "every site", no removal | `hub-routing` › WordPress’s own sign-up of a network |
| A domain-mapped site: door to its own wp-login.php, session there | `hub-routing` › A site on a domain of its own, from the front |
| The hub's account used by a person of another site: TOTP set up then asked from /beta/; details and public name read from /beta/; other sessions closed | `hub-account` › The account on the hub, used by a person of another site |
| Spent link and failed social trip said on the hub's sign-in page | `signin-doors` › Answers said on the hub's page |
| Passkey dialogue, plugin forms and unlink asked of /beta/ go to the hub and change nothing; /beta/ register and lostpassword are the hub's | `signin-doors` › The hub's doors asked of another site |
| Old /beta/sso/ address: by parameter; by path | `signin-doors` › an old social address on /beta/… |
| A reset asked from /beta/, mailed and chosen on the hub, signs in from /beta/ | `signin-doors` › A new password, on a network |
| Social sign-in then the second step, on the hub, back on /beta/ | `signin-doors` › The second step after a social sign-in, on a network |
| A site on a domain of its own: its door is its own wp-login.php | `signin-doors` › A site on a domain of its own |

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
| Network Overview: hub, addressing, mapped-domain warning, conflicts table | `NetworkHubTest` › a domain of its own… | net: `NetworkHubTest` › a site on a domain of its own… · single: `SingleSiteTest` › there is nothing to move (no notice) | — | `network-admin` › network › diluxone-users › network; A site on a domain of its own; `migration` |
| Social provider switched only from Network Admin | — | both: `SsoToggleTest` › off hides the button…; forgetting it… · net: …a site cannot switch a provider · single: …the site's screen switches a provider | `admin-effects` › a provider switched off | `network-admin` (providers tab) |
| Settings file: a site restores and exports only its own | — | both: `SettingsFileTest` › round trip, wipe does not travel, default not written back · net: …restored on a site of a network… · single: …restored on a single site restores everything | `admin-tools` › Settings as a file | — |
| Deleting the plugin: one network box; a site's Tools say so | — | net: `NetworkSettingsTest`; `UninstallNetworkTest` · single: `UninstallSiteTest` | `single-site` › Maintenance › Tools still asks | `network-admin` › …deleting the plugin; another site keeps… |
| "Show me what I chose" writes nothing of the network's | — | net: `NetworkSettingsTest` › a trial run writes nothing of the network's · single: `SingleSiteTest` › a trial run writes nothing | `preview` | — |
| 2FA: one network rule; a chosen role on any site; super admin | — | net: `MultisiteTest` › the second step is asked on every site alike; a chosen role on any site; a super admin… · single: `SingleSiteTest` › a chosen role puts somebody in scope · both: `TwoFactorFlowTest`, `AccountSecurityTest` | `two-factor` | `isolation` › The second step is the network's; `network-admin` › the second step required there… |
| Who may create an account: the network's registration, then the site's doors | — | net: `MultisiteTest` › the network decides…; on a closed network the doors start closed · single: `SingleSiteTest` › the site's own switch decides…; the doors start where "Anyone can register" is | `magic-link`, `register` | `doors` |
| Setup: activation, a site born on the network (no log table of its own), a deleted site (its rows and any old table go) | `NetworkLogTest` › a deleted site drops its own table and never the network's | net: `MultisiteTest` › a site born…; a deleted site takes its rows and its old table… · single: `SingleSiteTest` › activation sets the site up in its own table | — | `lifecycle` |
| Deactivation leaves no purge event | — | net: `MultisiteTest` › network deactivation clears the event on every site · single: `SingleSiteTest` › deactivation clears the event | — | `lifecycle` |
| Membership (see “Membership on a network” below) | `MembershipTest` | net/single: `MembershipTest` · single: `SingleSiteTest` › joining the site changes nobody | `single-site` › there is no membership… | `membership`, `doors` |
| Sessions report: whose sessions a site lists; closing them asks for the site's administrator and for rights over that person | — | net: `MultisiteTest` › the sessions report…lists only its members · single: `SingleSiteTest` › …lists everybody signed in · both: `ClosedDoorsTest` › closing an administrator's sessions needs rights…; an administrator closes another person's sessions | `admin-tools` › Reports | `isolation` |
| Tools that act on somebody else's sign-ins (close sessions, fresh code): on a network for whoever administers its users only, not drawn for a site's administrator | — | net: `ToolsPeopleTest` › a site administrator cannot sign the whole network out; …by address; …send somebody a code; …does not see the two tools; a super admin closes one person's · single: `ToolsPeopleTest` › the administrator keeps both tools · both: …an address sent as a list | `admin-tools` › Status › Tools | `network-admin` › a site's administrator gets the site's own tools… |
| Photo: read and deleted where it lives | — | net: `MultisiteTest` › a picture is read and deleted on the site that holds it · single: `SingleSiteTest` › a picture is read and deleted here · both: `AvatarSourcesTest` | `account-area` › Your photo; `site-menu` › …photo only… (drawn when Gravatar is off) | `isolation` |
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
| The pictures of what only a network draws in public: a site's three doors to the hub, the hub's sign-in and second step reached from a site, Join this site offered, by invitation and welcomed | — | — | — | `network-front-snapshots` (`make test-visual-network`) |

Every integration test not named in this table runs on both topologies and
passes on each.

In the browser, case by case:

| Feature / state | Covered by |
|---|---|
| Every network panel refuses a save with no nonce or a forged one (real form, in the browser), from a site administrator, and signed out | `refusals` › Every network panel refuses a save it should not take |
| Network Admin's own doors (sync, emptying the network log, conflicts dismiss, field delete, social forget) refused to a site administrator with a valid nonce of their own | `refusals` › Every network door that writes refuses what it should |
| The hub's doors sent to /alpha/ (Create page, sections, privacy, fields, social switch, provider form, network and hub panels) refused even to a super admin | `refusals` › Every network door that writes refuses what it should |
| People tools (close, code, sessions_admin, user-edit block) refused to a site administrator against a super admin | `refusals` › Tools › … from /alpha/ |
| The hub's Access › Ways: social sign-in forced off is not saved | `refusals` › social sign-in forced off and sent from the hub’s screen stays on |
| 2FA remember/link, passkey where/verify, short sessions/show, IP header, social rules saved through their controls and read on every site | `hub-scopes` › The network’s settings, saved through their controls, are every site’s |
| 2FA for chosen roles saved in Network Admin: an editor of /beta/ asked, a subscriber not | `hub-scopes` › the second step for chosen roles |
| Log groups saved in Network Admin: an unticked group records nothing | `hub-scopes` › the log’s groups, saved there |
| A field edited and moved in Network Admin is read by every site and asked by the registration | `hub-scopes` › a field edited and moved there |
| A provider set up in Network Admin: secret never printed, empty box keeps it, live test, switched on for every site | `hub-scopes` › A provider set up in Network Admin |
| A site's menu and dashboard rules stay that site's | `hub-scopes` › the menu and the dashboard rules saved on /alpha/ |
| A site's settings file exports and restores only that site's settings | `hub-scopes` › /alpha/’s settings file carries /alpha/’s settings only |
| Tools close pressed by a super admin on a site closes a session on another site | `hub-scopes` › the people tools pressed by a super admin on /alpha/ |
| Access, Notices, Fields on /alpha/; Fields, Security, Membership on the hub → 403 | `hub-scopes` › A screen asked for by its address where it does not belong is refused |
| A plain site administrator's Overview has no link into Network Admin | `hub-scopes` › a site’s plain administrator sees where the network’s screens are |
| Network Admin overview: hub card + its link, addressing and site count, rail links | `hub-admin` › names the hub with the way to its dashboard |
| Uninstall rail follows the box | `hub-admin` › the uninstall tab’s rail follows the box |
| Network log filters (search, site, event) through the form | `hub-admin` › the report’s filters narrow it as asked |
| A deleted site's rows leave the network log | `hub-admin` › a site deleted takes its rows out |
| Migration: the wipe is not carried; credentials/field differences recorded by kind with no secret printed; the move on a plain page load | `hub-admin` › The move from per-site settings |
| A site born leaves the hub's registration doors alone; network deactivation leaves no cron event | `hub-admin` › A site born, and the plugin switched off |
| Network Admin › Users › Delete; network Edit user saves the plugin's field | `hub-admin` › The network’s Users screens |
| Network Admin 2FA some roles, needs-one guard, link rule, remember 0, summary | `admin-security-network` › (Two-step describe) |
| Network Admin 2FA app ticked — | `admin-security-network` › the app ticked in Network Admin |
| Network Admin passkey rules → hub's browser options; rail names the hub | `admin-security-network` › the fingerprint required… |
| Network Admin session length, sessions box on the hub, proxy header and trusted range on /beta/, rail | `admin-security-network` › (Sessions and the proxy describe) |
| Network Admin social provider: redirect, credentials, live test via hub, turn on/off, forget | `admin-social-network` › (a provider describe) |
| Network Admin social rules: link by e-mail, verified only; some roles — | `admin-social-network` › (Rules describe) |
| A site's Tools: rebuild, its own export/import; hub Tools close one / everyone; lockout door; usage counts members | `admin-status-network` › (all) |
| Reports on a network: close from /alpha/'s report; network activity filters, pages; empty site and network (restored); account group | `admin-reports-network` › (all) |
| Users screens on a network: /alpha/'s Access column and profile block; Network Admin user-edit takes ways in off; toolbar per site | `wp-screens-network` › (all) |
| /alpha/'s Account screen draws only menu and dashboard | `admin-account-site` › /alpha/’s Account screen |
| Profile rule and toolbar per site; /beta/'s menu per site | `admin-account-site` |
| Fields reordered in Network Admin, read by every site; rename keeps answers | `admin-fields-network` |
| The hub's rules and templates govern mail from /alpha/'s door | `admin-notices-hub` |
| The hub's sign-in words and accent, and /alpha/'s door in the same accent | `admin-design-hub` |

## Membership on a network

Every case at every layer, on both topologies. Unit tests are pure (`make
test-unit`, `make test-unit-min`). Integration: every case is in
`MembershipTest`, **net:** running on the network and skipping loudly on a
single site, **single:** the other way round. Every guard was seen red (the
code broken on purpose, the test failing) and then green.

| Case | Unit | Integration | E2E single site | E2E network |
|---|---|---|---|---|
| The policy: one of three, every site by default, a network setting; saved from Network Admin only | `MembershipTest` › a stored policy is one of three…; the three policies…; on a network the policy is the stored one; on a single site…without asking | net: the policy is every site by default…; the membership screen saves one of the three in network admin only · single: on a single site there is no membership at all | `single-site` › there is no membership… | `membership` › saves each of the three answers…; a site’s menu has no Membership |
| A new account under “every site”: every live site, each site's role (hub: registration role; elsewhere `default_role`, subscriber when too big), announced once | `MembershipTest` › the role a site gives | net: a new account joins every live site with each site's role · single: a new account is what WordPress makes it | — (unchanged) | `membership` › an account made in Network Admin is a member of /alpha/ and /beta/… |
| Under “click” or “invite” a new account joins nothing more | `MembershipTest` › a stored policy… | net: under click or invite a new account joins nothing more · single: …nothing to join or to be invited to | — | `membership` › Whoever asks; By invitation |
| A new site gets every account; big ones queued, drained by cron under a lock, with progress on the screen | `MembershipTest` › small jobs…; how many people a batch takes; how far a job is | net: a new site gets every account through the queue and cron under a lock; a new account past the threshold is queued once; a queue left from every site is dropped… · single: a new site is not a thing | — | `membership` › a site made in Network Admin gets the network’s people |
| Super admins, closed accounts, archived / spam / deleted sites are never added | `MembershipTest` › which sites take people | net: a super admin is never added; a closed account is never added; a site that is not live is joined by nobody; a new account joins every live site… · single: an administrator is added to nothing | — | — |
| Removed by an administrator: not added back (policy, sign-in, sync, click) until an administrator adds them, which clears the record | `MembershipTest` › a removal list takes a site once… | net: somebody an administrator removed is not added back… · single: nothing is written down about removals | — | `membership` › taken off /beta/ in its Users screen, signing in again… |
| Removals nobody decided are not written down: a deleted site, an activated invitation, a deleted account, the plugin's own (closing an account) | `MembershipTest` › only an administrator's removal is written down | net: removals nobody decided are not written down; closing an account writes no removal · single: nothing is written down about removals | — | `account-closing` |
| “Sync everyone now”: the network's capability and nonce; everybody but the removed; nothing under another policy | `MembershipTest` › small jobs… | net: sync everyone now asks for the network… · single: there is nothing to sync | — | `membership` › saves each of the three answers… (the button under every site only) |
| WP-CLI `wp diluxone-users network membership sync`: to the end, refusing under another policy and while the lock is held | — | net: the command line sync runs to the end and waits for nobody · single: there is nothing to sync | — | `membership` › `wp diluxone-users network membership sync`… |
| “Join this site” under “click”: nonce, not a member, the site's role, `diluxone_users_member_added`; refused without the nonce, under another policy, on a site that takes nobody, signed out to the sign-in | — | net: under click a person joins a site with its role; join refuses without the nonce… · single: there is nothing to join or to be invited to | `single-site` › there is no membership… | `membership` › Whoever asks |
| “By invitation”: the shortcode, the menu and the notice on the way back say so, nothing to press | — | net: under invite the site says it is by invitation · single: there is nothing to join or to be invited to | `single-site` › there is no membership… | `membership` › By invitation |
| Signing in: the safety net under “every site” (the site and the hub); only the hub under “click” / “invite”; asking for a link adds nobody | — | net: under every site membership comes with the click, not with the request; under click or invite signing in joins only the hub; `NetworkHubTest` › under click or invite the way back… · single: signing in changes nobody's role | `magic-link`, `password-login` (unchanged) | `doors` › from /beta/…; …not a member there until they open the link |
| The log: a sign-in on the hub for /beta/ carries `from_site`, is on /beta/'s report and the hub's, not /alpha/'s; the network's report has a From column | — | net: a sign-in on the hub for a site is in that site's report and the hub's; a sign-in on the hub itself comes from nowhere · single: a sign-in comes from nowhere and the report is its rows (the query unchanged) | `activity-log` (unchanged) | `membership` › The activity log |
| The Membership screen's layout and picture | — | — | — | `network-admin` › network › diluxone-users-membership › policy; `network-snapshots` |

In the browser, case by case:

| Feature / state | Covered by |
|---|---|
| "Sync everyone now": Cancel adds nobody, OK adds the missing and keeps removals; `synced=done` | `hub-membership` › asks first: Cancel adds nobody |
| Queued sync: `synced=queued`, info notice, progress list, pending rail, worked to the end | `hub-membership` › on a network too big to do it at once it is queued |
| Sync refused without nonce / to a site administrator / signed out | `refusals` › Membership › "Sync everyone now" |
| A policy that is not one of the three is refused | `hub-membership` › an answer that is not one of the three is refused |
| The confirm button, the not-now box, the unconfirmed notice and its link | `hub-membership` › the button confirms the policy while it waits |
| Role fallback: editor/administrator default role gives subscriber | `hub-membership` › a site whose default role is editor or administrator |
| New site under "whoever asks" gets nobody; archived site gets nobody; closed account never added; no join box for a super admin | `hub-membership` › What the policy gives, and to whom |
| Removed under "whoever asks" sees invite (top, shortcode, menu); sync skips them | `hub-membership` › removed from /beta/ |
| Added back by an administrator clears the removal | `hub-membership` › an administrator adding them back clears the removal |
| A deleted site writes no removal | `hub-membership` › a site deleted is not a removal anybody decided |
| Join forged: no nonce, forged, by invitation, by a removed person, signed out | `refusals` › "Join this site" refuses what it should |
| join-refused draws the invite box, no welcome; the shortcode draws nothing for a stranger or member, once at most | `hub-membership` › “Join this site”, what is drawn |
| Join mark through the hub's wp-login.php | `hub-membership` › a password typed on the hub’s wp-login.php |
| CLI sync refused while unconfirmed or locked | `hub-membership` › `wp diluxone-users network membership sync` refuses what it should |

## Living with WordPress and with the rest of the site

The defaults and seams that decide whether the plugin gets in the way of
anything it was not asked about. Every case at every layer, on both
topologies: unit tests are pure (`make test`, `make test-unit-min`);
integration runs on the network (`make test-integration`) and on a single
site (`make test-integration-single`) — **both:** one test passing on each,
**net:** / **single:** a test that skips loudly on the other topology and
names its counterpart; the two browser columns are `specs/coexistence.spec.ts`
(`make test-e2e`) and `network/coexistence.spec.ts` (`make test-e2e-network`).
Every guard was seen red (the code broken on purpose, the test failing) and
then green at the integration layer; in both browser suites the second step
on wp-login.php, `wp_login`, the seeded fields, the membership confirmation
(network), safe mode and the cache were broken on purpose and seen red too.

| Case | Unit | Integration | E2E single site | E2E network |
|---|---|---|---|---|
| No sign-in page: the second step is drawn on wp-login.php (`?action=diluxone_users_2fa`), with a password and the code by e-mail | — | both: `TwoFactorSurfaceTest` › with no page the challenge is drawn by wp-login | `coexistence` › a password and the code by e-mail finish signing in, on wp-login.php | `coexistence` › from /beta/: a password and the code by e-mail on the hub's wp-login.php, back on /beta/ |
| …with the authenticator app | — | both: `TwoFactorSurfaceTest` (the same screen, any method) | `coexistence` › a password and the authenticator app… | `coexistence` › a password and the authenticator app, on the hub's wp-login.php |
| …a wrong code comes back to wp-login.php; an attempt that ran out restarts there and says so (`retry`) | — | both: `TwoFactorSurfaceTest` › a wrong code on wp-login…; an attempt that ran out… | `coexistence` › a wrong code comes back to wp-login.php and says so | (the same handler) |
| A chosen page that is a draft, private, in the bin or behind a password is no page | — | both: `TwoFactorSurfaceTest` › a page nobody can open is no page | — | — |
| With a sign-in page, the second step is on the page | — | both: `TwoFactorSurfaceTest` › with a page the challenge is drawn by the page; `TwoFactorFlowTest` | `coexistence` › with a sign-in page…; `two-factor` | `coexistence` › with a sign-in page on the hub…; `doors` › the second step… |
| Nowhere to answer it (no page, `diluxone_users_2fa_on_wp_login` false): not asked, not offered, not switched on from the account | `TwoFactorPolicyTest` (the policy) | both: `TwoFactorSurfaceTest` › with nowhere to answer it…; the account refuses to turn it on… | — | — |
| …"required" is disabled with the reason beside it, and refused when sent by hand | — | both: `TwoFactorSurfaceTest` › required cannot be saved with nowhere to answer it | `coexistence` › “required” cannot be chosen or saved… | `coexistence` › …in Network Admin |
| The e-mail link fires `wp_login` once, logged once | — | both: `OwnDoorsWpLoginTest` › a door of the plugin fires wp_login once (e-mail link) | `coexistence` › the e-mail link | `coexistence` › the hub's own doors… the e-mail link |
| A social account fires `wp_login` once | — | both: `OwnDoorsWpLoginTest` › … (social account) | `coexistence` › a social account | `coexistence` › …a social account |
| A passkey fires `wp_login` once | — | both: `OwnDoorsWpLoginTest` › a passkey fires wp_login once | `coexistence` › a passkey (Chromium) | `coexistence` › …a passkey (Chromium) |
| A door that asks for the second step: asked once, `wp_login` once it is answered; a password still fires it once | — | both: `OwnDoorsWpLoginTest` › the second step is asked once…; a password still fires wp_login once… | `coexistence` › a link that asks for the second step… | (the same code; `doors` › the second step…) |
| `register_new_user()` from somewhere else, with none of the plugin's fields, goes through | — | both: `WpRegisterFieldsTest` › a registration that never drew the fields goes through | `coexistence` › register_new_user() with none of the plugin's fields goes through | `coexistence` › …on the hub and on /beta/ |
| The plugin's fields posted with no nonce, a wrong nonce, or as a list are refused | — | both: `WpRegisterFieldsTest` › the fields with a wrong nonce…; a wrong nonce alone…; a field posted as a list…; the form without its nonce… | `coexistence` › WordPress's own form with the plugin's fields and a wrong nonce is refused | — (a network registers on wp-signup.php, which never draws the fields; the check is the integration's, on both) |
| A fresh install seeds the first and last name, neither required; an existing list is kept | — | both: `SuggestedFieldsTest` › a fresh install seeds only the two names…; an existing list is kept as it is | `coexistence` › only the first and last name are seeded… | `coexistence` › a fresh network seeds the first and last name… |
| Suggested fields: offered, added once behind a nonce, not offered again; a site of a network adds nothing | — | both: `SuggestedFieldsTest` › the suggested fields are offered and added once; the screen adds the ones ticked… · net: …a site administrator on a network adds nothing | `coexistence` › …the suggested fields are added from their tab | `coexistence` › …added in Network Admin and reach /beta/ |
| A session lasts what WordPress decides (14 days with "remember me") until a length is saved; another plugin's length is left alone; the screen and the summary say 0 is WordPress's | — | both: `SessionLengthTest` | `coexistence` › How long a session lasts | `coexistence` › How long a session lasts, on the network |
| Membership: unconfirmed, nothing is added (new account, new site, sign-in, queue, sync) and Network Admin says so; confirming "every site" adds everybody | `MembershipTest` › on a network the policy waits for its confirmation; on a single site there is nothing to confirm | net: `MembershipTest` › until the policy is confirmed it adds nobody; confirming every site adds everybody…; confirming another policy adds nobody · single: …on a single site there is nothing to confirm | `single-site` › there is no membership… | `coexistence` › Membership waits for the network to confirm it |
| Safe mode: wp-login.php is WordPress's (no takeover, no reset, privacy or lost-password hand-off), the dashboard profile stays, no second step | `TwoFactorPolicyTest` › safe mode never asks | both: `SafeModeTest` › wp-login is WordPress's again; the reset link stays…; forgetting a password…; the dashboard profile…; the privacy confirmation…; nobody is asked for the second step | `coexistence` › wp-login.php is WordPress's, its password signs in with no second step; off, the same settings take wp-login.php over… | `coexistence` › /beta/'s wp-login.php is its own… |
| Safe mode: passkeys and social sign-in shut, their settings kept | — | both: `SafeModeTest` › passkeys and social sign-in are shut… | `coexistence` › the sign-in page has no social button and no passkey | — (the same functions) |
| Safe mode: on a network every site keeps its own wp-login.php and forms | — | net: `SafeModeTest` › on a network every site keeps its own wp-login · single: …on a single site wp-login is the site's own | — | `coexistence` › /beta/'s wp-login.php is its own… |
| Safe mode: every dashboard page says so, only to whoever manages it | — | both: `SafeModeTest` › the dashboard says so on every page | `coexistence` › the dashboard says so on every page | `coexistence` › Network Admin and every site say so |
| The sign-in, registration and account pages send no-store and define `DONOTCACHEPAGE`; another page does neither | — | both: `NoCacheTest` (the decision; the constant in a process of its own) | `coexistence` › the sign-in, registration and account pages send no-store… | `coexistence` › the hub's pages a cache must not keep |
| A shortcode drawn from a template says it as it renders; a list of posts is left to the cache | — | both: `NoCacheTest` › a shortcode drawn from a template…; a list of posts… | — | — |
| The second step on wp-login.php is not cached | — | — | `coexistence` › the second step on wp-login.php is not cached either | (wp-login.php's own headers) |
| The Suggested fields tab's layout and picture | — | — | `admin-layout`, `admin-snapshots` (`SCREENS`) | `network-admin`, `network-snapshots` (`NETWORK_SCREENS`) |

## Every door, by name

Every way in the plugin registers, by the name it registers it under, and
the spec that walks it. `make coverage-e2e-map` reads `includes/` and
`templates/` and fails on one without a row here, on a row naming a spec that
does not exist, and on a row for something the plugin no longer registers. A
tab of a screen is not listed: it is in `support/screens.ts`, which the
behaviour, layout and picture suites walk, and the script holds that list
against the panels the plugin registers. A door added in a pull request gets
its row, and its spec, in the same pull request.

| Kind | Name | Covered by |
|---|---|---|
| shortcode | `diluxone_users_login` | `specs/magic-link`, `specs/password-login`, `specs/login-ways`; `network/doors` |
| shortcode | `diluxone_users_register` | `specs/register`; `network/doors` |
| shortcode | `diluxone_users_account` | `specs/account-area`; `network/lifecycle` |
| shortcode | `diluxone_users_account_nav` | `specs/account-pieces` › the menu alone…; `network/account-pieces` |
| shortcode | `diluxone_users_fields` | `specs/account-pieces` › the fields alone…; `network/account-pieces` |
| shortcode | `diluxone_users_sessions` | `specs/account-pieces` › the sessions alone…; `network/account-pieces` |
| shortcode | `diluxone_users_accounts` | `specs/account-pieces` › the social networks alone…; `network/account-pieces` |
| shortcode | `diluxone_users_avatar` | `specs/account-pieces` › the photo and the notifications alone…; `network/account-pieces` |
| shortcode | `diluxone_users_handle` | `specs/account-pieces` › the public name alone…; `network/account-pieces` |
| shortcode | `diluxone_users_notifications` | `specs/account-pieces` › the photo and the notifications alone…; `network/account-pieces` |
| shortcode | `diluxone_users_join` | `network/membership` › Whoever asks; By invitation; `specs/single-site` › there is no membership… |
| admin-post | `diluxone_users_link_request` | `specs/magic-link`; `network/doors`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_signup` | `specs/register`; `network/doors` › register from /beta/…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_reset` | `specs/password-reset`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_fields_save` | `specs/account-area` › Your details; `specs/account-pieces` › the fields alone…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_avatar` | `specs/account-area` › Your photo; `network/isolation`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_handle` | `specs/account-area` › The public name; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_notifications` | `specs/account-area` › Notifications; `specs/account-pieces`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_security` | `specs/account-area` › Security; `specs/two-factor`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_sessions` | `specs/account-area` › Security; `specs/account-pieces` › the sessions alone…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_sessions_admin` | `specs/admin-tools` › Reports › Sessions…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_data_request` | `specs/account-area` › Your data; `network/account-export`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_data_download` | `specs/account-area` › a copy: confirmed from the e-mail…; `network/account-export`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_confirm_close` | `specs/account-area` › deleting the account…; `network/account-closing`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_sso_unlink` | `specs/sso` › linking from the account area, and unlinking again; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_passkey` | `specs/passkeys` (remove, rename); `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_create_page` | `specs/create-page`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_preview_try` | `specs/preview` › what was chosen, without saving it; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_tools` | `specs/admin-tools` › Status › Tools; `network/network-admin`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_mail_test` | `specs/admin-tools` › the test message…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_log_empty` | `specs/activity-log` › emptying it asks first; `network/activity-log`; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_log_empty_network` | `network/activity-log` › emptying it on a site…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_membership_sync` | `network/membership` › saves each of the three answers…; `specs/refusals` (every refusal) |
| admin-post | `diluxone_users_join` | `network/membership` › Whoever asks; `specs/refusals` (every refusal) |
| ajax | `diluxone_users_passkeys` | `specs/passkeys`; `network/doors` › by passkey…; `specs/refusals` (every refusal) |
| ajax | `diluxone_users_handle_check` | `specs/account-pieces` › the public name alone: the availability check…; `specs/refusals` (every refusal) |
| ajax | `diluxone_users_preview` | `specs/preview` › asked of the server, which draws it and saves nothing; `specs/refusals` (every refusal) |
| wp-login | `diluxone_users_2fa` | `specs/coexistence` › …on wp-login.php; `network/coexistence` |
| wp-login | `confirmaction` | `specs/account-area` › deleting the account: the e-mail’s link asks once more…; `network/account-closing` |
| address | `diluxone_users_section` | `specs/account-area` › the menu reaches every section |
| address | `diluxone_users_sso` | `specs/sso`; `network/social` |
| wp-cli | `wp diluxone-users login` | `network/lifecycle` › WP-CLI |
| wp-cli | `wp diluxone-users network migrate` | `network/migration` |
| wp-cli | `wp diluxone-users network membership sync` | `network/membership` › `wp diluxone-users network membership sync`… |
| template | `templates/login.php` | `specs/magic-link`, `specs/password-login`; `specs/front-snapshots` (pictures) |
| template | `templates/login-2fa.php` | `specs/two-factor`; `specs/front-snapshots` (pictures) |
| template | `templates/login-reset.php` | `specs/password-reset` › `site`; `specs/front-snapshots` (pictures) |
| template | `templates/register.php` | `specs/register`; `specs/front-snapshots` (pictures) |
| template | `templates/hub-door.php` | `network/doors` › the pages that held the forms…; `network/network-front-snapshots` (pictures) |
| template | `templates/join.php` | `network/membership` › Whoever asks; By invitation; `network/network-front-snapshots` (pictures) |
| template | `templates/account.php` | `specs/account-area`; `specs/front-snapshots` (pictures) |
| template | `templates/account-guest.php` | `specs/account-area` › a stranger is shown the way in…; `specs/front-snapshots` (pictures) |
| template | `templates/account-nav.php` | `specs/account-area` › the menu reaches every section; `specs/account-pieces` › the menu alone… |
| template | `templates/account/home.php` | `specs/account-area` |
| template | `templates/account/details.php` | `specs/account-area` › Your details |
| template | `templates/account/avatar.php` | `specs/account-area` › Your photo; `specs/account-pieces` |
| template | `templates/account/handle.php` | `specs/account-pieces` › the public name alone… |
| template | `templates/account/handle-field.php` | `specs/account-area` › The public name; `specs/account-pieces` |
| template | `templates/account/notifications.php` | `specs/account-area` › Notifications; `specs/account-pieces` |
| template | `templates/account/privacy.php` | `specs/account-area` › Your data |
| template | `templates/account/security.php` | `specs/account-area` › Security; `specs/two-factor` |
| template | `templates/account/accounts.php` | `specs/sso` › linking from the account area… |
| template | `templates/accounts.php` | `specs/account-pieces` › the social networks alone… |
| template | `templates/fields.php` | `specs/account-pieces` › the fields alone… |
| template | `templates/sessions.php` | `specs/account-pieces` › the sessions alone… |

## Not coverable in a browser

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
- **The passkey "not on HTTPS" note** on Access › Ways in: it needs a non-`local` environment type, and the e2e mu-plugin only loads on `local`.
- **An empty field list** (`No fields yet.`): WordPress's own two are put back on every dashboard request (`diluxone_users_seed_native_fields`).
