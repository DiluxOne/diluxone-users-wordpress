# Extending the plugin

Everything on this page is **public API**. It does not change shape without a
major version, and the plugin's own features use these same seams — a feature
that ships inside the plugin and one that arrives from somewhere else are
built the same way.

If something you need is not here, that is the bug: say so instead of copying
a template or editing `includes/`.

## The rule

Add on top; do not reach in. Nothing in here asks you to replace a file or
copy one. If you find yourself copying a template to change a sentence or a
class, there is a seam missing.

---

## Screens and tabs

A tab is registered, not written down. Register on `diluxone_users_register_panels`,
which fires on `admin_init` — early enough for the menu and for the preview's
AJAX request, and by then every plugin has loaded, so nothing depends on which
file came first.

```php
add_action( 'diluxone_users_register_panels', function () {
    diluxone_users_register_panel(
        'diluxone-users-security',       // the screen
        'sms',                           // the tab slug, which ends up in the URL
        array(
            'label'    => __( 'By SMS', 'my-addon' ),
            'position' => 25,            // 10, 20, 30… the plugin's own leave gaps
            'render'   => 'my_addon_sms_fields',
            'save'     => 'my_addon_sms_save',   // omit for a tab with nothing to save
            'preview'  => 'my_addon_sms_preview', // optional: shown beside the fields
            // optional: what the button says instead of "Save changes"; a
            // closure is asked when the tab is drawn, after its own save.
            'save_label' => static fn(): string => __( 'Save the SMS settings', 'my-addon' ),
        )
    );
} );
```

The screen supplies the form, the nonce, the button that saves it and the
"Saved." notice. `render` prints the fields and nothing else. `save` checks
the nonce and the capability itself before it reads anything, in its first
two lines — the screen checks them too before calling it, and a save is a
function anybody can call:

```php
function my_addon_sms_save(): void {
    check_admin_referer( 'diluxone_users_panel_diluxone-users-security', 'diluxone_users_panel_nonce' );
    diluxone_users_panel_allowed();

    diluxone_users_save_options(
        array( 'diluxone_users_sms_sender' => sanitize_text_field( wp_unslash( $_POST['diluxone_users_sms_sender'] ?? '' ) ) )
    );
}
```

`diluxone_users_save_options()` writes only settings the plugin knows, so the
key is added to them first, with its default and its scope (see
[Options](#options)).

A save writes what its tab drew and nothing else. Read every answer you have a
name for and write "empty" for the ones that did not arrive, and the day part
of the tab is drawn by something else — or by nobody — the save wipes a value
it never showed. Pass what the save worked out through
`diluxone_users_panel_drawn( $screen, $values, $from )`: a value is kept when
its control arrived, or, for a tick box (which sends nothing unticked), when
the box said it was drawn — `diluxone_users_ui_choice()` sends its name in
`diluxone_users_panel_drawn[]` for that. `$from` names the control that
answers for a value whose own name is not posted (a colour of its own decided by a
tick box: `'diluxone_users_sms_color' => 'diluxone_users_sms_color_own'`).
A save that writes a list or a map merges with what is stored and keeps the
entries it does not recognise.

The button is
not at the foot of the form: it heads the column beside the fields — the rail,
the preview, or a rail of its own when the tab has neither — so it stays in
view while the settings scroll, in the same place on every tab.

Every screen takes panels, with no exceptions left: `diluxone-users`
(Overview), `diluxone-users-register` (Registration — summary, who can
register, messages), `diluxone-users-login` (Access — summary, sign-in page,
ways in, how they are arranged, messages), `diluxone-users-security`,
`diluxone-users-social`, `diluxone-users-account`, `diluxone-users-fields`,
`diluxone-users-design`, `diluxone-users-notices`, `diluxone-users-status`
(Maintenance — status, tools, lockout). `diluxone-users-register` is a screen
again, right above Access, and the old address of the tab it was,
`page=diluxone-users-login&tab=register`, redirects to it;
`diluxone-users-tools` is not a screen any more, and its slug redirects to the
tab it became.

A tab that draws its own markup — a list with its own actions, a form with a
nonce of its own — registers with `'form' => false` and the screen stays out
of its way. That is how the field list, the provider grid and the open
sessions live beside ordinary settings tabs. A form of its own that saves
settings gives itself an `id` and asks for its button with
`diluxone_users_ui_save( $form_id, $label )` before the column beside it is
drawn; the button reaches the form through its `form` attribute.

A tab of the plugin's own that an add-on draws part of — the settings a shape
brings, under the shapes — is saved with it: `diluxone_users_panel_saved`
(action, `( string $screen, string $tab )`) fires after a tab's save, unless
that save refused, and in the live preview's trial run as well, so what the
add-on would write is previewed with the rest. The listener checks the tab's
nonce and the capability itself, like any save, and writes through
`diluxone_users_save_options( diluxone_users_panel_drawn( … ) )`.

A tab exists because something registered it. Nothing registered means no tab
— not an empty one. `diluxone_users_panels` (filter) receives a screen's tabs
once they are in order, `( array $panels, string $screen )`: registering is the
way in, the filter is for taking one out or moving it.

What a tab prints is drawn into a buffer and printed through `wp_kses()` with
the plugin's list of allowed markup: the post editor's, plus form controls,
inline drawings and disclosure elements. No `<script>`, `<style>` or
`<iframe>`: enqueue them. A tab that prints an element the list lacks adds it
with `diluxone_users_allowed_html`:

```php
add_filter( 'diluxone_users_allowed_html', function ( array $allowed ): array {
    $allowed['meter'] = array_merge( $allowed['meter'] ?? array(), array( 'low' => true, 'high' => true ) );
    return $allowed;
} );
```

### Drawing a tab

A tab is drawn out of the same pieces as the plugin's, so it looks like the
rest of the admin and follows it when the admin changes:

- `diluxone_users_ui_aside_open()` and `diluxone_users_ui_aside_close( $rail )`
  — the column of settings and the rail beside it. `$rail` prints what goes
  there, and it is little: under the button that saves, one "Right now"
  (`diluxone_users_ui_aside_state( $line, $state )`, what is true on this site
  in a sentence, with its pill) and the notices that apply to this site
  (`diluxone_users_ui_notice()` under a condition, with the way to fix it).
  Not what the tab is for, and not where the rest of the subject lives: that
  is the intro line's or the documentation's, and a tab with nothing to
  report draws the button alone.
- `diluxone_users_intro( $text )` — the sentence under the tab's title;
  `diluxone_users_ui_section( $title, $help )` — a group's heading.
- `diluxone_users_ui_choices( $choices, $needs_one, $only_while, $waits )` —
  radios and tick boxes, each `type`, `name`, `value`, `checked`, `title`,
  `help` (and `state`, `note`, `disabled`, `children`); `$waits` for a group
  that waits for another answer.
- `diluxone_users_ui_note( $title, $text, $state )` and
  `diluxone_users_ui_links( $title, $links )` (each `url`, `label`, `help`) —
  a paragraph with nothing to choose, and a list of ways out, for a block
  that is about this site; `diluxone_users_ui_notice( $html, $kind )`,
  `diluxone_users_not_now( $why, $url, $go )` and
  `diluxone_users_ui_waits( $why, $url, $go )` — what a notice, a dimmed
  control and a group that waits say.
- `diluxone_users_ui_words( $key, $label, $fallback, $help )` and
  `diluxone_users_ui_words_area( … )` — a sentence the site may rewrite;
  `diluxone_users_ui_inline_choices( $label, $choices, $help )` — a short
  question in a row; `diluxone_users_ui_fold( $block, $inside )` — something
  folded away; `diluxone_users_image_field( $key, $help )` — a picture from
  the media library, stored as its id on the hub.
- `diluxone_users_ui_color( $field )` — any colour, with a tick box for "a
  colour of its own"; `diluxone_users_ui_swatches( $field )` — one of the
  plugin's short palettes (see [Colours](#colours)).
- `diluxone_users_summary_table( $rows )` — a summary.
- `diluxone_users_admin_url( $screen, $args )` — a link to a screen and tab,
  in the site's admin or Network Admin, wherever it is drawn;
  `diluxone_users_admin_context()` — `single`, `network` (Network Admin) or
  `site` (a site of a network).

### The shape every screen has

The first tab of a screen with settings is a **Summary**: `form => false`,
`position => 0`, drawn with `diluxone_users_summary_table()`. It reads what
the other tabs write and edits nothing. A row is a label, a state, one line of
detail and a "Change it →" link to the tab that owns it.

There is one vocabulary of state, five words, on every screen:
`active`, `pending` (half set up, or waiting on something — always with a
reason), `off`, `unknown` for what cannot be told from inside the site, and
`unset` ("Not configured") for a setting nobody has decided.
`diluxone_users_state_pill( $state, $why, $word )` draws one. A sixth pill,
`pro`, is not a state: it is the badge of a Users+ Pro placeholder (see
[Users+ Pro on these screens](#users-pro-on-these-screens)).

Activating the plugin changes nothing, and the screens say so. A setting is
decided when a value is stored for it — the default included: saving a tab
is the person's choice — or pinned from code through the `diluxone_users_option`
filter (`diluxone_users_option_is_set( $key )`, `diluxone_users_options_any_set( $keys )`).
A summary row that reads a setting goes through
`diluxone_users_summary_row( $keys, $row, $meanwhile )`: the row as written
once decided, and `unset` with `$meanwhile` — what WordPress does on its own —
in the detail until then. An add-on's rows on `diluxone_users_doors` and
`diluxone_users_security_summary` do the same, and the rail's "Right now"
(`diluxone_users_ui_aside_state( $line, $state, $why, $word )`) takes `unset`
like any other state.

The Security screen's summary is built from what each feature says about
itself: `diluxone_users_security_summary` (filter) receives the rows, an
array of `label`, `state` and optionally `why`, `word`, `detail`, `url` and
`change`, and a feature adds its own at the priority its tab sits at (an
add-on's at 20, the sessions at 30, the proxy at 50). A row without a label
or a state is dropped.

The state is not negotiable and the word is. Most rows are switches and read
as Active or Off in any language; a row that names something with no switch on
it — a page nobody chose, a toolbar, the mail going out, a count — reads "Off"
as *somebody turned this off*, which is not what it means. Pass `$word` and the
colour, the shape and the four states stay the one vocabulary while the word
says what that state is called there. A summary row carries it as `word`,
`diluxone_users_ui_note()` takes it as its fifth argument, and
`diluxone_users_check()` as its seventh. Give the word a `_x()` context naming
the row it belongs to whenever it is a single adjective: the same "None" has to
agree with a masculine name and a feminine key, and it cannot do both.

A control that does not apply right now stays on the screen and keeps its
saved answer — what is chosen applies the day the reason goes away — but it is
dimmed, and may be drawn disabled, with its save keeping the answer a
disabled control does not send, as "Which tab opens" and the lost-password
choices are. `diluxone_users_not_now( $why, $url )` says why, above it, with the way
out, under a "Not in use now" pill (the `notnow` state): not "Pending", since
nothing there is a task left to do.

A group that has no answer until another one is given — what becomes of
wp-login.php while no sign-in page is chosen, who the second step reaches
while it is off — waits for it instead: `diluxone_users_ui_choices()` takes
`$waits` (`why`, `name` of the control it waits on, `is` or `not` — the values
of that control that end the wait, or keep it — and `waiting`, whether it waits
as the page is drawn), and draws the group disabled with nothing chosen under
one line saying what for (`diluxone_users_ui_waits( $why, $url, $go )`, with
no pill). The admin script lets the group go the moment that control is given
an answer on the same tab, and takes it back if the answer goes away. A
disabled control sends nothing, so the tab's save skips a key that did not
arrive and the setting keeps what it had. `diluxone_users_scope_control()`
takes the same `$waits`.

### Doors into an account

Registration is a set of independent doors and not one exclusive answer: the
e-mail link creates the account (`diluxone_users_login_register`), a social
account does (`diluxone_users_sso_register`), the site's own form does
(`diluxone_users_register_form` + `diluxone_users_register_page`), and
WordPress's own form does — that last one is `users_can_register`, the same
switch as Settings → General, read and written directly rather than copied.
`diluxone_users_register_mode()` still answers with one word for whatever
wants one: `login`, `form`, `both` or `closed`. The link's door counts as open
only while the link is a way in (`diluxone_users_link_registers()`): on a
site that signs in with a password only, no link is mailed and none creates
an account. Asking for a link creates nothing: for an address nobody has, the
plugin keeps a pending sign-up (a network-wide transient holding the address,
the token's hash and its expiry, under a random key) and mails a link carrying
`diluxone_users_signup` and `diluxone_users_token`. The account is created when
that link is opened (`diluxone_users_signup_consume()`), once, and only while
the link still creates accounts and the network still takes them; it ends in
`diluxone_users_complete_login()` like every other way in. There the site's own form mails no sign-in link either: the new
account gets WordPress's "choose your password" mail
(`wp_new_user_notification()`), and the form says so.

While the e-mail link is the only way in, `users_can_register` reads as off
whatever is stored (`diluxone_users_block_registration()`), and both screens
say so.

### Ways in

The sign-in page draws the ways in from a registry, not from a list in the
template: each one registers itself, and the template asks what there is. The
plugin's own three — the networks, the e-mail link and the password — register
this way, and so does an add-on's (Users+ Pro's passkey is one).

```php
add_action( 'diluxone_users_register_ways', function () {
    diluxone_users_register_way(
        'sms',
        array(
            'label'     => __( 'SMS', 'my-addon' ),   // the tab's word
            'icon'      => 'mail',                     // mail, info, key, network or user; any other name draws no icon
            'render'    => 'my_addon_sms_way',         // prints the form
            'available' => 'my_addon_sms_offered',     // asked every time; false and it takes no room
            'over'      => __( 'or by SMS', 'my-addon' ), // the divider above it when they are stacked
            'states'    => array( 'sms' ),             // the diluxone-users= states it authors: the tab that opens after a failed attempt
            'outside'   => false,                      // true: above the tabs, always visible (a one-button way in)
            'strong'    => false,                      // true: it already proves two things, so no second step after it
            'position'  => 25,                         // the plugin's own: 10, 20, 30
        )
    );
} );
```

`diluxone_users_register_ways` fires on `init` (priority 5); register there
and not at load time. The site drags the ways into its own order on Access ›
Ways in; a way it has never seen follows in the order its `position` asks
for. `diluxone_users_ways` (filter) receives the ways this site offers, in
order, once `available` has been asked: it is for taking one out or moving
it. The id ends up in a cookie (`diluxone_users_way`, the last way used) and in
the saved order; it is a word and nothing else. A way that is not registered
any more — its add-on is off — is skipped wherever the order, the tab to open
or the cookie names it, and the next way opens.

A way registered with `strong` proves two things in one gesture — something
the person has and something they are or know — so a session opened through
it with `diluxone_users_open_session( $user_id, $id, $remember )` or
`diluxone_users_complete_login()` is not asked for the second step
(`diluxone_users_way_strong( $via )` answers it).

A way in is switched on with the others, on Access › Ways in.
`diluxone_users_way_switches` (filter) takes one box per way, keyed by the
setting it writes (1 or 0) — a key the add-on added through
`diluxone_users_option_defaults`, with a scope; any other key is left out:

```php
add_filter( 'diluxone_users_way_switches', function ( array $switches ): array {
    $switches['diluxone_users_sms_enabled'] = array(
        'title'    => __( 'A code by SMS', 'my-addon' ),
        'help'     => __( 'Sent to the number on the account.', 'my-addon' ),
        'state'    => '',          // 'pending' when it cannot work yet, with
        'note'     => '',          // the reason here
        'settings' => array(       // where the rest of it is set up, listed beside the boxes
            'url'   => diluxone_users_admin_url( 'diluxone-users-security', array( 'tab' => 'sms' ) ),
            'label' => __( 'By SMS', 'my-addon' ),
            'help'  => __( 'The sender and the message.', 'my-addon' ),
        ),
    );
    return $switches;
} );
```

The screen draws the box, ticked from the setting, and writes it on save —
only while the box is drawn, so an add-on that is off never has its setting
turned off by somebody saving the tab. On a network a box whose setting is
not editable from the hub is drawn as it stands, and switched where the
add-on's own tab is (in Network Admin). `diluxone_users_doors` (filter)
receives Access › Summary's rows; an add-on adds its way in there, as a
summary row. How an account is made is Registration › Summary's, not one of
these rows.

### Doors on a network

On a network every door is the hub's (the main site's), and people come back
to the site they started from. What an add-on or a theme builds on:

- `diluxone_users_login_url()` and `diluxone_users_register_url()` are the
  hub's pages; from any other site they already carry `redirect_to`, the page
  the person is on. WordPress's own `wp_login_url()`,
  `wp_registration_url()` and `wp_lostpassword_url()` are the hub's there too
  (filtered at priority 20). A link of your own to the hub takes
  `diluxone_users_with_return( $url, $back )`, which checks `$back` and falls
  back to where the person is.
- `diluxone_users_safe_return( $url )` answers whether an address is one to
  send somebody back to — an http(s) address on a site of this network — and
  returns it, or `''`. Use it for any address that arrives in a request.
  `allowed_redirect_hosts` already has this network's hosts and no others.
- `diluxone_users_login_redirect` receives the way back at priority 5 on the
  hub, spent the first time it is asked for a user. A filter at the default
  priority sees it as `$redirect` and may send the person elsewhere.
- On a site that is not the hub the plugin's shortcodes draw
  `templates/hub-door.php` (`$args['door']` is `login`, `register`,
  `account`, or `here` on a site on a domain of its own, whose sign-in door
  is its own wp-login.php; `$args['url']` the address it leads to,
  `$args['hub']` the hub's name),
  which a theme can replace like any other template.
- `diluxone_users_hub_posts` (filter) — the `admin-post.php` actions that
  belong to the hub. One posted to another site is sent to the hub before
  its handler writes anything, and one sent by somebody signed out to sign
  in. Add yours when your forms are on the account area or the sign-in page.
- `diluxone_users_off_hub()` says whether the current site is a site of a
  network other than the hub; `diluxone_users_sends_to_hub()` whether it sends
  people there (not a site on a domain of its own, see
  `diluxone_users_mapped_sites()`). Both are false on a single site.

### Membership on a network

Which sites an account is a member of is the network's policy
(`diluxone_users_membership()`, set on Network Admin › Membership; see
[`architecture.md`](architecture.md)). The plugin's is `all`; an add-on offers
more (Users+ Pro's `click`, "whoever asks", and `invite`, "by invitation"),
and under any policy but `all` the plugin adds nobody on its own. A stored
policy nothing offers — one an add-on offered and is not running to apply —
reads as `hub`: people are members of the hub only, nothing is queued or
synced, and a non-member is told the site is by invitation, until a policy is
chosen again. The screen shows it as an inactive Pro choice and keeps it on
save. What an add-on or a theme builds on:

- `diluxone_users_member_added` (action) fires every time the plugin makes
  somebody a member of a site: `( int $user_id, int $site_id, string $role,
  string $how )`, where `$how` is `account` (a new account under "every
  site"), `site` (a new site), `sync`, `sign-in` or `click` (a person who
  asked, an add-on's "Join this site"; the one addition made before the
  policy is confirmed). Once per person and site in a request. Additions made by WordPress
  or by an administrator do not fire it.
- `diluxone_users_membership_policies` (filter) receives the policies after
  `all`, `( array $policies )`: an add-on adds its own values.
  `diluxone_users_membership_choices` (filter) receives the screen's words,
  `( array $choices, string $hub )`, `policy => array( title, help, state )`:
  its card and the sentence the rail says while it is the network's.
- `diluxone_users_join_state` (filter) receives what somebody signed in, not
  a member and not taken off the site, can do about it under a policy that is
  not `all`: `( string $state, string $policy, int $user_id, int $site_id )`,
  `invite` from the plugin. An add-on answers a state of its own and draws it:
  `diluxone_users_join_box` (filter) `( string $box, string $state, bool $notice )`
  returns the box's markup, and `diluxone_users_join_link` (filter)
  `( array $link, string $state )` returns the menu's link as `label` and
  `url`. Left empty, the person is told the site is by invitation.
- `[diluxone_users_join]` draws `templates/join.php` for somebody signed in
  who is not a member of the site: `$state` is `invite` (the site is by
  invitation) or `joined`, or an add-on's box for its own state, `$site` and `$hub`
  the two sites' names, `$notice` whether it is the box at the top of the page
  the hub sent them back to. It draws nothing for nobody, for a member, and on
  a single site. The same box goes at the top of that page through
  `wp_body_open`.
- Nothing is added by the policy until the network confirms it
  (`diluxone_users_membership_confirmed()`, the network setting
  `diluxone_users_membership_confirmed`): the first save of Network Admin ›
  Membership is the confirmation, and under "every site" it adds everybody
  already there. Before it, `diluxone_users_member_added` never fires for an
  addition the policy would make, and WordPress's own memberships stand.
- `diluxone_users_membership_inline` (filter, default 50) is how many
  additions a new account or a new site makes on the spot; more are queued
  and made by WP-Cron. `diluxone_users_membership_batch` (filter, default 200)
  is how many additions one batch of the queue makes.

## Social login providers

`includes/sso.php` is a generic OAuth2 engine with no branch per provider:
everything comes from a table. Adding one is a filter and nothing else.

```php
add_filter( 'diluxone_users_sso_providers', function ( array $providers ): array {
    $providers['acme'] = array(
        'name'       => 'Acme ID',
        'color'      => '#0B5FFF',                                // its brand colour, for the card and the button
        'authorize'  => 'https://id.acme.example/oauth2/authorize', // where the person is sent
        'token'      => 'https://id.acme.example/oauth2/token',     // where the code becomes a token
        'profile'    => 'https://id.acme.example/oauth2/userinfo',  // where the profile is read
        'scope'      => 'openid email profile',
        'extra'      => array(),                                  // optional: odd parameters the provider wants
        'pkce'       => false,                                    // optional: true when it requires PKCE
        'pkce_support' => true,                                   // optional: true when it accepts PKCE (false if left out)
        'token_auth' => 'post',                                   // optional: 'basic' when it wants the secret in a Basic header
        'map'        => 'diluxone_users_sso_map_oidc',            // optional: reads the profile (below)
        'console'    => 'https://id.acme.example/developers',     // optional: where the application is made
        'guide'      => 'https://id.acme.example/docs/oidc',      // optional: its documentation
    );

    return $providers;
} );
```

Google and Microsoft are the plugin's own rows; every other provider is an
add-on's (DiluxOne Users+ Pro registers ten this way). `token_auth` says how
the code is exchanged: `post` (the default) sends the client ID and the secret
in the body, `basic` sends them in an HTTP Basic `Authorization` header and
leaves the secret out of the body, for a provider that accepts only that.

PKCE (S256) is per provider. A row with `pkce_support` true gets a "Use PKCE"
box on its Settings tab, ticked until an administrator unticks it, and its
trip sends `code_challenge` and the exchange `code_verifier` while it is
ticked; Google and Microsoft are such rows. A row that leaves it out is never
sent PKCE and its box is locked, saying the provider does not support it. A
row with `pkce` true requires it: it is always sent, and the box is ticked and
locked. `diluxone_users_sso_pkce( $id, $row )` says whether a provider's trip
uses it.

`map` is a callable that receives the profile (decoded JSON) and the access
token and returns `id`, `email`, `name` (the first name), `last_name` and
`verified` (true, false, or null when the provider does not say);
`diluxone_users_sso_map_oidc` reads a standard OpenID Connect `userinfo` and
is what a row without a `map` gets. A field left out is filled in with an
empty value of its type, so a provider missing one does not work rather than
breaking the page.

What `verified` decides: an existing account is handed over on a `true`
only, and with "Only verified addresses" on (the default) an address the
provider answered `null` or `false` for is confirmed by mail before anything
is created or linked (`includes/sso-pending.php`). The sign-in page then
shows its `social-pending` state, the mail goes through
`diluxone_users_sso_confirm_email` (see [E-mails](#e-mails)), and opening the
link, in the browser that went to the provider and nowhere else, ends in `diluxone_users_complete_login()` with `via` `sso`, like any
social sign-in. A provider that does vouch for its addresses never meets it.

Its icon goes through `diluxone_users_sso_icon_paths`, and the step-by-step
for its console through `diluxone_users_sso_guides`. It then appears on the
Social login screen with its card, its redirect URL and its live test, like
any other.

The callback lives at `/sso/<provider>/` on the site (on the hub, on a
network). `diluxone_users_sso_base` (filter, default `sso`) changes the first
part, for a site where that address is taken; the rewrite rules have to be
rebuilt after (Maintenance › Tools).

### The round trip in a popup

A provider an add-on registers gets the popup like the plugin's own
(`includes/sso-popup.php`): every live button (the sign-in form, the
registration form, the account's Link) is still a plain link to the round trip,
and carries `data-diluxone-users-sso-popup` (the same address plus
`diluxone_users_popup=1`, or `=door` on the plugin's own sign-in and
registration pages) and `data-diluxone-users-sso-resume`. The enqueued
`diluxone-users-sso-popup` script opens the first in a popup and keeps the page
where it is; when the browser gives no window (a blocker) the click goes ahead
and the whole window makes the trip as before. A theme that draws its own
social links gets the popup by carrying the same two attributes and enqueuing
the script with `diluxone_users_sso_enqueue_popup()`.

The popup trip is stored as such in the trip's state. Its return ends where
the whole window's would have ended, but the redirect that ends it is caught on
`wp_redirect` (priority 0, for that request only): the address is kept
server-side for two minutes under a random name in an HttpOnly cookie, and the
popup draws a bridge page instead (`diluxone-users-sso-bridge`) that sends the
page that opened it `{ type: 'diluxone-users-sso', kind: 'in' | 'next' }` with
`postMessage` to the site's own origin (the hub's on a network) and on a
`BroadcastChannel` of that origin, and closes. `in` is a plain sign-in to the
default destination begun away from a door: the page reloads in place. `next`
is everything else (a destination of its own, the second step, the required
fields, the account's notice, the error): the page goes to the resume address,
`/sso/<provider>/?diluxone_users_resume=1`, which spends what waits and
redirects there with `wp_safe_redirect()`, or to the sign-in page when nothing
waits. The message carries no address, code, state, key or nonce.

Because the catch is on `wp_redirect`, an add-on that redirects from
`wp_login` or `diluxone_users_logged_in` during a popup sign-in sends the page
that opened the popup there, through the resume address, instead of the popup.

A provider's stored row (`diluxone_users_sso`, keyed by provider id) keeps
whatever else is in it when the plugin saves the credentials, and a row of a
provider nothing registers today is left alone: nobody signs in with it, the
Social login screen shows it as an inactive Pro choice after the real
networks, and it works again the day its add-on is back. The same holds for
the accounts linked to it (`diluxone_users_sso_{id}` user meta): kept,
exported with their value withheld and erased with everything else of the
person, like any other key under the prefix.

A return from a provider that signs nobody in writes an `sso_failed` row
("Social sign-in refused") to the activity log (group `social`, also written
while `access` is ticked; account 0) whose detail carries `provider` (the
provider id) and `reason`: `cancelled` (the provider answered
`access_denied`), `state` (a state nobody issued, already spent or issued for
another provider), `browser` (another browser or session than the one that
left), `off` (the provider was turned off on the way), `token` (no access
token), one of the reasons `diluxone_users_sso_user( $id, $identity, &$why )`
or `diluxone_users_sso_target()` gives when it finds nobody — `role` (the account's role may not use social
sign-in), `no_email` (no valid address from the provider), `link_off` (an
account has the address and linking by e-mail is off), `email_unverified`
(the provider did not verify the address; under "Only verified addresses" the
return is held for the address to be proved by mail, and that hold writes this
row too, since nobody is signed in yet: the link in the mail may still sign the
person in), `register_off` (new accounts from social
sign-in are off), `register_role` (the role new accounts get may not use
social sign-in), `create_failed` (creating the account failed) — `account`
when nothing said why, or else the provider's own OAuth error code. The code,
the state and the provider's error description are never kept. The
administrator's live test writes no
row.

## Account area sections

```php
add_action( 'diluxone_users_register_sections', function () {
    diluxone_users_register_section(
        'orders',
        array(
            'label'    => __( 'My orders', 'my-addon' ),
            'position' => 60,
            'render'   => 'my_addon_orders',        // prints the section
            'summary'  => 'my_addon_orders_card',   // optional: its card on the front page
            'available' => 'my_addon_has_shop',     // optional: hide it when there is nothing to show
        )
    );
} );
```

`summary` returns `array( 'value' => …, 'note' => …, 'cta' => … )`. A card with
an empty `value` is not drawn, but it still appears in the admin as something
that can be switched off — the list of switches does not change shape
depending on who is looking at it.

To add a card without a section of your own, filter `diluxone_users_summaries`
and give each card an `id`: that is the key the admin remembers when somebody
turns one off. Without one, the plugin makes it from the label, and the label
changes.

### Sections of the site's own

A section the site makes from the admin — its own text, or another plugin's
shortcode — is an add-on's (DiluxOne Users+ Pro's). The add-on registers each
one like any other section, with `'custom' => true`, no `render` and its words
as `content`; the plugin draws that content as the whole section, and the
Sections tab edits its name, address, intro, who sees it and its content like
any other. What the add-on brings is the making and the removing, through
these:

- `diluxone_users_account_sections_head` (action) fires beside the heading of
  the list of sections: where the way to add one goes.
- `diluxone_users_account_details` (filter) receives `array( string $id => callable )`:
  a detail the tab draws beside the list instead of a section, when the
  address asks for `section=<id>`. The callable gets the account page's id.
  Users+ Pro's new-section form is `diluxone-users-new`.
- `diluxone_users_account_section_head` (action) fires in the head of a
  section's detail, after its switch, with `( string $id, array $section )`:
  where the way to remove one goes.
- `diluxone_users_account_notices` (filter) receives the tab's notices,
  `array( string $msg => array( string $type, string $text ) )`, by the
  `diluxone_users_msg` they answer to.
- `diluxone_users_section_slug_free( string $slug, string $id = '' )` answers
  whether an address is free for a section, counting the ones kept.

They live in `diluxone_users_account_sections`, keyed by their id, with
`custom` set. Without the add-on that registered them they are not drawn on the
front end, and the Sections tab lists them after the others with the "Pro"
badge, out of the order and with nothing to open; every save keeps them, and
they come back as they were when the add-on does.

## The account's Security section

The section people manage their own security on (`templates/account/security.php`)
has these seams for an add-on that keeps a way in on the account:

- `diluxone_users_account_security` (action, `( WP_User $user )`) fires
  between how somebody gets in and the second step. Draw a panel of your own
  there with `diluxone_users_panel_open( $title )` and
  `diluxone_users_panel_close()`, like the plugin's.
- `diluxone_users_account_security_2fa` (action, `( WP_User $user )`) fires
  after the second step's panel, while the site offers the second step to the
  person: where a method of yours is set up (Users+ Pro's authenticator app).
- `diluxone_users_security_notices` (filter, `( array $notices, WP_User $user )`)
  maps the `diluxone-users=` word an action comes back with to a notice:
  `array( 'ok' | 'error', $sentence )`.
- A form of yours posts to an `admin-post.php` action of its own, or to
  `diluxone_users_security` with a word the plugin does not know:
  `diluxone_users_security_action` (action, `( string $action, int $user_id, string $target )`)
  fires for it once the nonce (`diluxone_users_security`) and the session are
  checked, and you redirect; if you do not, the person is sent back to the
  section (`$target`). On a network add your own action to
  `diluxone_users_hub_posts` (below).

Front-end pieces an add-on draws with: the classes `diluxone-users-button`
(`--wide`, `--soft`), `diluxone-users-notice` (`--ok`, `--error`) and
`diluxone-users-note`, the stylesheet handle `diluxone-users` to depend on,
`diluxone_users_icon( $name )` and `diluxone_users_button_icon( $name )` (an
icon from the plugin's set, inside a button only when the site wants icons),
and `diluxone_users_allowed_html()` for `wp_kses()`.

## A door of your own

What an add-on's way in calls once it has proved who somebody is, so it
behaves like the plugin's own doors:

- `diluxone_users_join_site( $user_id )` — on a network, makes them a member
  of this site if the network's policy says so. Every door calls it.
- `diluxone_users_open_session( $user_id, $via, $remember )` — opens the
  session, fires `wp_login` and `diluxone_users_logged_in`, and records the
  sign-in. `$via` is the id of your way in. Or
  `diluxone_users_complete_login( $user_id, $via, $remember, $redirect )`,
  which also asks for the second step unless the way is `strong`.
- `diluxone_users_login_redirect` (filter) — where they go next.
  When the sign-in page was opened with a `redirect_to` that stays on the
  site (or the network, through `allowed_redirect_hosts`), that address is
  the value this filter receives, in place of the home page: the password
  form passes it as is, and the e-mail link keeps it on the server with its
  token. A filter still decides, as on a network, where the hub sends the
  person back to the site they came from.
- `diluxone_users_notify_security( $user_id, $event )` — the e-mail that tells
  somebody their security changed; `$event` is a callable that returns the
  sentence, called in their language.
- `diluxone_users_via_labels` (filter) names your door in the new-device
  e-mail ("a key on your phone"), `diluxone_users_log_doors` (filter) in the
  activity log ("Key").
- `diluxone_users_ip_burst( $what, $many, $window )` — false once this address
  asked for `$what` more than `$many` times in `$window` seconds: the ceiling
  for an endpoint somebody signed out can call.
- `diluxone_users_safe_mode()` — while it is true, your door is shut.
- `diluxone_users_login_url()`, `diluxone_users_account_url( $section )`,
  `diluxone_users_display_name( $user )`, `diluxone_users_off_hub()`,
  `diluxone_users_hub_site_id()` and `diluxone_users_hub_name()` — where people
  sign in and keep their account, and on a network which site that is: an
  add-on whose keys belong to a domain uses the hub's.

## A person in Users

The Access column of Users and the block on somebody's profile say what the
account has. An add-on that keeps a way in on it adds to both:

- `diluxone_users_person_chips` (filter, `( array $chips, int $user_id )`) —
  the column's chips: `array( 'on' | 'blank', $words )`.
- `diluxone_users_person_rows` (filter, `( array $rows, WP_User $user )`) —
  the block's facts, as summary rows, in order.
- `diluxone_users_person_take_off` (filter, `( array $choices, WP_User $user )`) —
  tick boxes an administrator ticks to take one of your things off the
  account, with names of your own.
- `diluxone_users_person_saved` (action, `( int $user_id )`) — the profile was
  saved; the profile's nonce and `edit_user` are checked. Read your boxes
  here, checking both again first.
- `diluxone_users_access_features_on` (filter, `( bool $on )`) — whether the
  column and the block are drawn at all when the plugin's own ways in (the
  second step, a social provider) are off. Answer true while a way in of
  yours is on; otherwise Users and the profile stay WordPress's.

## Reports

- `diluxone_users_stats` (filter) — the counts of this site's accounts; add
  yours under a key of your own. The plugin's own counts are kept.
- `diluxone_users_usage` (filter, `( array $rows, array $stats )`) — what
  people chose, as Overview › Usage and Maintenance › Status draw it: a
  `label`, a `detail` line and a `value`.
- `diluxone_users_checks` (filter) — the checks on Maintenance › Status, in
  the shape `diluxone_users_check()` returns; a check without a label or a
  state is dropped.

## The activity log

`diluxone_users_log_record( $event, $user_id, $detail )` writes a row when the
site records that event's group. An add-on's events are its own:

- `diluxone_users_log_events` (filter) — event => group (`access`, `account`,
  `security` or `social`; `access` also records `social`'s events). An event in no group that exists is never written; the
  plugin's own keep theirs.
- `diluxone_users_log_labels` (filter) — what each event is called.
- `diluxone_users_log_says` (filter, `( string $says, array $row, bool $network )`)
  — what a row of yours says beyond its name, from its detail. Plain text.

## Second-factor methods

```php
add_filter( 'diluxone_users_2fa_methods', function ( array $methods ): array {
    $methods['sms'] = array(
        'channel'  => 'sms',      // 'email' means "does not add anything to an e-mail link"
        'label'    => __( 'A code by SMS', 'my-addon' ),
        'help'     => __( 'We text the number on the account.', 'my-addon' ),
        'ready'    => 'my_addon_has_number',   // can this person use it?
        'send'     => 'my_addon_sms_send',
        'verify'   => 'my_addon_sms_verify',
        'position' => 30,
    );

    return $methods;
} );
```

Wherever the challenge is drawn, it is drawn from the registry: on the sign-in
page when there is one a stranger can open, and on wp-login.php
(`?action=diluxone_users_2fa`) when there is not. A site that hides
wp-login.php behind something that does not run its login actions says so
with `diluxone_users_2fa_on_wp_login` (filter, default true): with that false
and no sign-in page, there is nowhere to type the code, so the second step is
not asked of anybody and "required" cannot be saved
(`diluxone_users_2fa_surface()` answers `page`, `wp-login` or `''`).

A method registered this way appears on **Security → Two-step verification**
as one more box to tick, and it does **not** work until the site ticks it:
which methods a site offers is the site's decision, not the add-on's. The
option holding the ticked ones happens to share the filter's name — the
filter says what exists, the option says what is offered. A method ticked
while an add-on registered it and no longer registered (the add-on is off)
stays in the option: the screen shows it ticked and switched off as an
inactive Pro choice, the save keeps it, nobody is asked for it, and it does
not count as the one method the second step needs.

A site whose every ticked method is one nothing registers today — an
add-on's, the add-on off — gets the e-mail code in its place: the second step
that was asked is still asked, and nobody who had only the add-on's method is
locked out.

`diluxone_users_2fa_failed` (action) fires on every wrong code:
`( int $user_id, string $method )`, where `$method` is the method's id, or
`reauth` for the code asked again before something sensitive in the account.
The activity log listens to it.

### Who is asked, and what is remembered

The plugin asks the second step of everybody with an account and remembers
nothing: every sign-in that needs it is asked. An add-on narrows the first and
adds the second (Users+ Pro's "only some roles" and "do not ask again on this
browser"):

- `diluxone_users_2fa_in_scope` (filter, `( bool $in, int $user_id )`, true)
  decides whether the second step is for a person at all. The sign-in, the
  account's Security section and the summaries ask it through
  `diluxone_users_2fa_in_scope()`. `diluxone_users_2fa_who` (filter,
  `( string $who )`) says the result in the summary's line; an empty answer
  is a site that reaches nobody, and the summary says so.
- `diluxone_users_2fa_skip` (filter, `( bool $skip, int $user_id, string $via )`,
  false) lets a sign-in that needs the step skip it.
- `diluxone_users_2fa_on_link` (filter, `( bool $ask, int $user_id )`, true)
  decides whether the step is asked of somebody who came in by e-mail link.
  The plugin asks it on the link as on every other way in; Users+ Pro's
  "Let it decide" answers false when the only method is another e-mail.
- `diluxone_users_2fa_form` (action, `( int $user_id, string $screen )`) fires
  inside the second step's form, after the code box, on the sign-in page
  (`page`) and on wp-login.php (`wp-login`). Name your fields
  `diluxone_users_2fa_extra[...]`: `diluxone_users_2fa_passed` (action,
  `( int $user_id, array $extra, string $via )`) hands you what they sent,
  cleaned as text, once the code is right and the single-use attempt is
  spent, before the session opens.
- `diluxone_users_2fa_forget` (action, `( int $user_id )`) fires when whatever
  was remembered has to go: the step turned off, a method removed, the
  person's other sessions closed.

On **Security → Two-step verification**, `diluxone_users_2fa_screen` (action,
`( string $place )`) fires at `who` (under the three modes), `methods` (under
the methods) and `end` (after the e-mail link's line). What you draw there is
saved with the tab, on `diluxone_users_panel_saved`.

## Profile fields

Three seams, and you will usually want all three.

```php
// 1. The type shows up in the admin.
add_filter( 'diluxone_users_field_types', function ( array $types ): array {
    $types['signature'] = __( 'Signature', 'my-addon' );
    return $types;
} );

// 2. How it is drawn. Return '' to leave it to the plugin, which draws a text
//    box — the right answer for most of what anybody would add.
add_filter( 'diluxone_users_field_input', function ( string $html, array $field, string $value, string $id ): string {
    return 'signature' === $field['type'] ? my_addon_signature_pad( $field, $value, $id ) : $html;
}, 10, 4 );

// 3. How the value is cleaned. Return null to leave it to the plugin.
//    Whatever answers is responsible for the value being safe to store.
add_filter( 'diluxone_users_sanitize_value', function ( $clean, string $value, array $field ) {
    return 'signature' === $field['type'] ? my_addon_clean_signature( $value ) : $clean;
}, 10, 3 );
```

The markup from the second one goes through `wp_kses` with form tags and the
attributes they need: you can replace a text box, not turn it into a script
tag.

A field's key is the user meta key its answers are stored under. An add-on
names the keys it keeps about a person with
`diluxone_users_reserved_meta_keys` and, for keys it makes one per thing,
`diluxone_users_reserved_meta_prefixes`, and no field can be named after them.
Under the plugin's prefix a field may only have a key in the fields' own corner,
`diluxone_users_field_<name>` — where the screen makes every new one — or one
a field already had before that corner existed, or a suggested field's. The
rest of `diluxone_users_` is what the plugin, or an add-on of it, keeps about
a person, and no field can be named after it. A key outside the prefix
follows the older rule: anything but WordPress's own keys.

### The phone field

A field of type `phone` is drawn as one control: a country list
(`<key>_dial`, the ISO code) and the number (`<key>`), in one box with the
height, border and corners of the other inputs. The stored value is E.164,
`+` and digits. `assets/diluxone-users-phone.js`, loaded by the first phone a
page draws, turns the list into a picker that can be searched (a button over a
combobox and a listbox) and groups the number as it is typed; the hidden list
is still what is posted, and without the script, or with the plugin's
stylesheet off, the native list stays.

What is particular to each country is data, in `includes/data-phone.php`:
`diluxone_users_phone_rule( $iso )` gives its national number's shortest and
longest length, how it is grouped, its trunk prefix (the `0` dialled at
home, dropped when it is typed) and its mobile token. A grouping is one
pattern of group sizes per length, apart by `|`, and a pattern can name the
digits it is for before a colon, so an area code of two, three or four digits
is written apart (`11:2 4 4|221,341:3 3 4|4 2 4`; the one with no prefix is
for every other number of that length). The mobile token is the one written
at home between the area code and a mobile's number, with what the number
carries in front instead (`15:9`: "011 15 4123 4567" is stored as
`+5491141234567`). `diluxone_users_phone_check( $iso, $number, $mobile )`
answers `''`, `'short'` or `'long'`, `diluxone_users_phone_format( $iso,
$number )` writes it in groups, and `diluxone_users_phone_errors( $sent,
$fields )` gives the message for each phone of a form that is the wrong
length. Each `<option>` carries its rule as `data-dial`, `data-min`,
`data-max`, `data-group`, `data-trunk` and `data-mobile`; the server reads its own table.

A phone field marked "It is a mobile" on User fields has `mobile` set to `1`
in its array (`0` otherwise, and on every other type). For it, a bare
national number gets the lead of the country's mobile token in front when it
is a whole number without it and is still one with it: in Argentina "11 4123
4567" is stored as `+5491141234567`. A country whose row has no lead stores
the number as typed, and adding a country is adding its `token:lead` to its
row. `diluxone_users_phone_national( $iso, $number, $mobile )` and
`diluxone_users_phone_check()` take the flag as an optional last argument,
`false` by default; the field's box carries `data-mobile-field="1"`, and the
script shows the lead in the box once the number is whole. Numbers stored
before the box was ticked are not rewritten.

A number the wrong length refuses the whole form it was sent with, before
anything on it is saved. The plugin's forms come back with
`diluxone-users=phone`, `diluxone-users-field=<key>` and
`diluxone-users-code=short|long` (never the number), and draw the message
under the number in a `<p class="diluxone-users-field__error"
id="<id>-error">`, with `aria-invalid` and `aria-describedby` on the number.
A template of your own passes it on with the fifth argument of
`diluxone_users_field_input( $field, $value, $id, $user_id, $error )`, from
`diluxone_users_phone_refused( $fields )`. The registration form also shows
the rewritable message `register_phone`. WordPress's own registration, the
profile and Users → Add New refuse through `registration_errors` and
`user_profile_update_errors`.

A new site starts with the first and last name only, neither required. The
fields offered on User fields › Suggested fields — a country, a date of birth,
a gender and a phone — come from `diluxone_users_suggested_fields` (filter):
an array of field definitions as the stored list holds them. A key already on
the list is not offered again; one ticked is added at the end of the list by
`diluxone_users_add_suggested_fields( $keys )`.

### Required fields missing after a sign-in

An account made by the e-mail link, a social network or an add-on's way in
never saw the registration form, so it has nothing in the fields the site
marked as required. The plugin alone sends it straight on; asking is an
add-on's (Users+ Pro's), through the filter `diluxone_users_ask_missing`
(below). With one that asks, once it is signed in, it is sent first to the account
page with `?diluxone-users-complete=1&redirect_to=<where it was going>`,
which draws only those fields (`templates/fields.php`, the same form, checks
and save as Details) in place of the sections and their nav, with a "Skip
for now" link. The step is drawn in the account area's own frame (the
classes of `diluxone_users_account_classes` with the layout `none` and no
header, and the properties of `diluxone_users_account_style`), so it follows
the width setting and a shape's stylesheet; it prints its own title, then its
line, and asks `templates/fields.php` for no title. Saved with nothing required still missing, it goes on to
`redirect_to`, checked with `wp_validate_redirect()` against the site's (and,
on a network, the network's) hosts. Nothing is stored about the person: it is
decided again at every sign-in, and no other request is held up.

It is asked where a session is opened and the person sent on: the end of
`diluxone_users_complete_login()` (so an add-on's door calling it gets it
too), the end of the second step, and WordPress's `login_redirect` at
priority 99 for a password with no second step. What an add-on may call:

- `diluxone_users_missing_required( $user_id )` — the active, required
  fields the person may edit and has no answer for.
- `diluxone_users_ask_missing_url( $user_id, $redirect )` — the step's
  address carrying `$redirect`, or `$redirect` itself when nothing is owed.

It is asked only while `diluxone_users_ask_missing` answers true (false in the
plugin alone, so nothing is stored for it and no setting names it), some field
is required and an account page is chosen; the account page asked for the
step draws it only then. In safe mode it is never asked. User fields › After
sign-in says people go straight on and draws Users+ Pro's two settings as
previews (`fields/missing/ask`, `fields/missing/skip`).

## Looks

### Shapes

```php
// Another shape for the sign-in page. Every shape but `plain` takes the page
// over: the form goes in a box inside a frame that breaks out of the theme's
// column. The id becomes a class on the frame — bring your own CSS.
add_filter( 'diluxone_users_login_templates', function ( array $t ): array {
    $t['neon'] = array(
        'label'      => __( 'Neon', 'my-addon' ),
        'help'       => __( 'Dark, with a glow.', 'my-addon' ),
        // All three optional.
        'classes'    => fn(): array => array( 'my-addon-glow' ),          // more classes on the frame
        'properties' => fn(): array => array( '--my-addon-hue' => '200' ), // custom properties on it
        'inside'     => 'my_addon_neon_panel',                            // prints what goes before the form's box
    );
    return $t;
} );

// And for the account area: .diluxone-users-account--<id>. The rest of the
// row is what pressing its card fills in (layout, nav_style, header, avatar,
// since, action, width).
add_filter( 'diluxone_users_account_templates', function ( array $t ): array { … } );
```

What a shape adds to the account area goes through three filters, each given
the shape in force: `diluxone_users_account_classes` (the area's classes),
`diluxone_users_account_style` (custom properties, name => value, printed on
the area) and `diluxone_users_account_avatar_size` (the size the header's
picture is fetched at, 64 by default). Properties are printed only with a
`--` name and a value that cannot close the declaration — a frame's too.

A shape's settings go under the shapes on the Design screen:
`diluxone_users_design_login_shape` and `diluxone_users_design_account_shape`
(actions) fire there with the shape chosen — a kept one included — and the
add-on saves them on `diluxone_users_panel_saved`.

Its stylesheet: `diluxone_users_enqueue_styles` (action) fires whenever the
plugin's own sheet is enqueued on the front end, the moment to enqueue one with
`diluxone-users` as its dependency — never where the site turned the plugin's
off. The previews on the Design screen are documents of their own; an add-on's
sheet joins them through `diluxone_users_preview_stylesheets` (filter,
handle => URL). And `diluxone_users_style_css` (filter) is the CSS the admin's
answers become, printed after the sheet on the front end and in every preview:
a shape that reads one of the plugin's answers adds its rule there, printed
only when there is an answer.

A shape stored while an add-on offered it and no longer offered (the add-on is
off) is not thrown away: the sign-in page draws a card in its place and the
account area the simple one, and the Design screen shows the stored shape as
an inactive Pro choice that its save keeps until another shape is picked.

### Colours

Colours and corners are CSS custom properties — `--diluxone-users-accent`,
`--diluxone-users-radius` and the rest — so a site or an add-on can repoint
them from a stylesheet without any PHP at all.

Where the admin is asked for a colour — the accent, the ground of the account
area, wp-login.php's colour — the answer is one of a short palette, drawn with
`diluxone_users_ui_swatches()`. `diluxone_users_accent_palette()` is eight
accents, each one reading at WCAG AA (4.5:1) under white letters and as a link
on white, the graphite among them for a site whose brand is not a colour;
`diluxone_users_ground_palette()` is four near-whites the plugin's text reads on.
What is stored is the hex. With Colours › the theme's, a ground is not printed as
its hex but as `diluxone_users_ground_tint()`: a `color-mix()` of
`--diluxone-users-surface`, 2% of `--diluxone-users-text` and 4% of the swatch's
hue, so it turns with the theme's dark mode and its text still reads on it.
With a ground the area's text is `--diluxone-users-text`, printed with it;
with none, outside its boxes the area takes the page's text colour, and its
quieter text (“Member since”, a section's intro) is that colour toned down.

`diluxone_users_any_color` (filter, default false) makes any hex an answer, and
`diluxone_users_color_choices` (action, `( string $name, string $value )`)
fires at the end of each row of swatches while it is true: one more radio of
the same name, whose value is the colour picked, is all an add-on needs to
draw — the plugin's save stores it like a swatch. A hex stored while any colour
was an answer and kept once it is not is drawn as the palette's nearest,
measured in OKLab (`diluxone_users_palette_color()`), or the default when that
one would not read; the screen shows it chosen, as "Your Pro colour
(inactive)", and keeps it until another one is picked.

## Styling it from a site

**Set a value. Do not write a selector.** This is the one rule on this page
that is about CSS, and it is here because breaking it broke a real site.

A site had written, reasonably:

```css
.diluxone-users-account--cover .diluxone-users-account__nav { gap: 36px }
```

meaning *the tab bar under the cover* — at the time, a cover implied a row of
tabs. Then the menu learnt to go down the side. The rule was still true to the
letter and no longer true to the intention: 36px of gap on a stacked menu is a
hundred pixels between items, and nobody had touched either the site or that
rule.

A property applies in every state the plugin will ever have. A selector is a
claim about which states exist, and that claim expires the next time one is
added. So the measurements are properties too, not only the colours:

| Property | What it is | Default |
| --- | --- | --- |
| `--diluxone-users-read` | The reading column, when the content is held to one | `980px` |
| `--diluxone-users-bleed` | How far a frame that takes the page over breaks out of the theme's column | `calc(50% - 50vw)` |
| `--diluxone-users-header-gap` | Between the picture and the name | `18px` |
| `--diluxone-users-avatar` | The picture | `64px` |
| `--diluxone-users-bar-bg` | Behind the menu's strip | `transparent` |
| `--diluxone-users-bar-rule` | Its rule, whole: `1px solid #ddd` | none |
| `--diluxone-users-bar-pad` | Inside the strip, above and below the menu | `10px` |
| `--diluxone-users-bar-gap` | Under the strip | `28px` |
| `--diluxone-users-bare-top` | Above the area when it has no header | `24px` |
| `--diluxone-users-nav-gap` | Between menu items | by the shape |
| `--diluxone-users-tab-pad` | Inside one | by the shape |
| `--diluxone-users-side-w` | The side menu's column | `minmax(180px, 220px)` |
| `--diluxone-users-nav-min` | Narrowest item when the menu wraps on a phone | `140px` |
| `--diluxone-users-sent-icon` | The circle on "check your email" | `88px` |
| `--diluxone-users-dial-w` | The country column of a phone field, inside its box: the native list without the script, the picker's button with it | `minmax(0, 9rem)`; `auto` with the picker |
| `--diluxone-users-border-w` | A control's edge — inputs and buttons | `1px` |
| `--diluxone-users-accent-soft` | The accent with the volume down | the accent at 12% |
| `--diluxone-users-panel-gap` | Between the rows of a panel's body | `normal` |
| `--diluxone-users-body-top` | Above the account area's first row, the header or the menu | none; from the admin |
| `--diluxone-users-body-bottom` | Under the taller of the side menu and the content, to the end of the area | none; from the admin |
| `--diluxone-users-ground` | The colour the whole account area sits on | none; with Colours › the theme's, a tint of the surface and text |
| `--diluxone-users-row-w` | The width the area's three rows line up to | from the admin |
| `--diluxone-users-row-pad` | And the gutter inside them | from the admin |
| `--diluxone-users-display-font` | The face for the headings the plugin does size — the sign-in screens it takes over | inherit |
| `--diluxone-users-login-w` | The column the sign-in block is held to | none |
| `--diluxone-users-login-title` | The form's heading, inside a frame | `clamp(26px, 2.4vw, 34px)` |
| `--diluxone-users-login-title-weight` | And its weight | `800` |
| `--diluxone-users-note-bg` | Behind the note beside a drawing | transparent |
| `--diluxone-users-note-pad` | The air inside it, which turns it into a box | `0` |
| `--diluxone-users-note-gap` | Between the drawing and the words | `8px` |
| `--diluxone-users-note-mark` | The drawing's own colour | the note's |
| `--diluxone-users-card-edge` | The centred card's edge, kept at 3:1 or more against the page | the text at 65% over the surface |
| `--diluxone-users-field-gap` | Between two fields of a form, and the centred card's rhythm (the note under the e-mail form, the small print) | `18px` |
| `--diluxone-users-menu-btn-bg` | "Sign in" as a button in the site's own colours (Account › In the site menu): its ground | from the admin |
| `--diluxone-users-menu-btn-text` | Its text | from the admin |
| `--diluxone-users-menu-btn-border` | Its border | from the admin |
| `--diluxone-users-menu-btn-hover` | Its ground on hover and focus | from the admin |
| `--diluxone-users-menu-gap` | In the site's menu, before the plugin's first item; set apart, the least it keeps (600px up) | none |
| `--diluxone-users-menu-inner-gap` | Between two of the plugin's items side by side there (600px up) | none |

The plugin's items in the site's own menu carry `diluxone-users-menu` and one
of `diluxone-users-menu--sign-in`, `--join`, `--person` (with
`menu-item-has-children`), `--invite`, `--dashboard` or `--sign-out`. "Sign in"
drawn as a button adds `diluxone-users-menu--button` and `--filled` or
`--outlined`, and its link `wp-element-button` with the theme's colours or
`diluxone-users-menu--own` on the item with the site's own. With a position or
a spacing chosen, the first of the plugin's top-level items carries
`diluxone-users-menu--first` and each after it `diluxone-users-menu--next`.

The last two are also asked from the admin — Design → Your brand — so a site
that is not writing CSS at all still gets them. Everything on this page can be
set from a stylesheet as well; the admin is the same values with a screen in
front of them.

Two defaults in a row means the plugin's own differ by shape. Setting the
property wins in both: none of them are declared on `:root`, each is read
where it is used with the default for that place, so yours is never shadowed.
Set them wherever you like — `:root`, the page, the block:

```css
:root {
	--diluxone-users-bleed: 0px;   /* this site is already full width */
	--diluxone-users-nav-gap: 36px;
	--diluxone-users-avatar: 104px;
}
```

### The theme's colours

The plugin alone paints itself with its own palette and the accent chosen on
Design → Your brand, light and dark. Taking the palette from the active theme
instead is Users+ Pro's "From your theme", drawn there as a disabled preview
(`design/brand/theme`); an add-on turns it on through the filter
`diluxone_users_colors_from_theme` (below), and everything this section
describes, the measuring and the dark signal included, runs only then. It
reads what the theme publishes in its `theme.json`, through `wp_get_global_settings()`, and maps it
onto `--diluxone-users-accent` and the rest.

Which colour plays which part is asked and not assumed. A theme whose slugs
follow the names WordPress suggests — `base`, `contrast`, `primary`, `accent`
— is mapped without anybody being asked; a theme that numbers its colours is
not guessed at, and the screen shows the palette with a list per part.

Many themes publish variables rather than colours — Astra hands over
`var(--ast-global-color-0)`. Those are kept as they are rather than resolved,
so the plugin follows the theme live, including whatever the theme does in
dark mode.

```php
// A theme that keeps its colours somewhere of its own adds them here.
add_filter( 'diluxone_users_theme_palette', function ( array $palette ): array {
    $palette['brand'] = array( 'name' => 'Brand', 'color' => 'var(--my-brand)' );
    return $palette;
} );
```

Values are checked before they reach the stylesheet: a hex, an `rgb()/rgba()`,
an `hsl()/hsla()`, or a `var(--name)`. Anything else is dropped.

What the palette does not name is measured on the site, in the visitor's
browser (`assets/diluxone-users-theme-measure.js`): the colour of a link and a
button, the text and the ground, the edge of a field. It is measured again
when the scheme changes and kept once per scheme in the browser, so the next
page starts with it.

### Dark mode follows

Which scheme is in force is the visitor's device (`prefers-color-scheme`)
unless Design → Your brand → "Dark mode follows" says "The site's own switch".
Then the site says what its switch puts on the page, as a class or an
attribute on `<html>` or `<body>`:

| Typed | Means |
| --- | --- |
| `.is-dark` | the class `is-dark` |
| `data-theme="dark"` (or `data-theme=dark`) | the attribute with that value |
| `data-mode` | the attribute, with any value |

The dark one on the page means dark and the light one (optional) light; with
both configured and neither on the page, the device decides, and with only the
dark one, a page without it is light. An attribute named `on…`, `style` or
`class`, and a value with a quote or a bracket, are refused on save.

The measurer watches that attribute, so a theme's switch flipped on the page
re-measures at once, and it keeps the scheme it saw last. The sign-in page of
its own has no theme template for the switch to run in: when neither signal is
on it, the plugin puts the last scheme's signal there (the class added, the
attribute set), so the theme's own CSS keyed on it applies, and paints it with
what the site's pages measured for that scheme. A signal the site's own
`wp_head` script sets wins. Only with Colours → the theme's: with the plugin's
own colours there is no dark scheme to follow.

### Headings are the theme's

There is no property here for the size of a heading, and there will not be
one. The person's name is an `h1` and a section's heading is an `h2`, and the
plugin says nothing about how big they are — so they come out in the theme's
own typography and the page reads as one page.

It used to say. `font-size: 1.5em` on the name was the single thing that made
this block look pasted in: a site whose headings were a display face at 42px
got everything else on the page in it and this in one-and-a-half times the
body face. That is why the site was writing a rule — not to customise the
plugin, but to undo it.

The sign-in page is the one place this is the other way round, and the line is
worth saying out loud: **in `plain` the heading is the theme's, and inside the
frames that take the page over it is the plugin's.** A designed screen,
edge to edge, with a theme's `h2` dropped into it reads as a form that landed
in a blog post; a form the theme placed itself should look like everything
else the theme places, and does. Those sizes are properties —
`--diluxone-users-login-title` and a shape's own — so a
site with a scale of its own still says it once, as a value.

What the plugin does keep is the layout of its own header: the margins around
the name, the gap beside the picture, the air above the content. Those are
this block's, not the theme's.

### The one thing that is not a property

Where the area's three rows — the header's contents, the menu across the top,
the body — line up with the rest of the page. That is asked from Design →
Account area instead, and the rule is printed only when it has been answered.

It was a property for a day and it was wrong, in a way worth writing down.
The rule read `padding-inline: var(--diluxone-users-pad, 0px)`, and the
neutral default is not neutral: this sheet loads after the site's, so `0` won,
and a site that had been lining those rows up with its own grid lost the
alignment without anybody touching it. **A value that means "nothing" still
beats a value that means something.** The only declaration that cannot
overrule anybody is the one that is not printed.

So where the plugin has no opinion, it prints no rule — and where a site
wants one, it answers the question and the plugin prints exactly that.

What it prints is the number as the **fallback of a property**, not as the
value: `padding-inline: var(--diluxone-users-row-pad, 24px)`. The rule still
exists only because somebody answered — that is the part that mattered — and
the answer stays something a stylesheet can move, which is what a gutter that
is 24px on a wide screen and 20px on a phone needs. A screen of numbers cannot
ask a media query. The same goes for the width, for the air above and below
the content, and for the colour the area sits on.

When you do need a selector — your own typeface on the name, your own colour
on the open item — **style what the thing is, not what a template implies it
is**. Every axis is a class on the element it describes, so there is always
one that means exactly what you mean:

| Axis | Classes | On |
| --- | --- | --- |
| Which shape | `--plain`, or an add-on's | the area |
| Where the menu goes | `--tabs` `--side` | the area |
| How wide | `--contained` `--full` | the area |
| Which way the menu runs | `--row` `--column` | the menu |
| What the menu looks like | `--pills` `--underline` `--plain` | the menu |

`.diluxone-users-account__nav--row` is a row of items, in every template there
will ever be. `.diluxone-users-account--cover .diluxone-users-account__nav`
was a row of items *until Tuesday*.

## Templates

`diluxone_users_template` filters the path of any template before it is
loaded. A theme does not need it: dropping a file in
`wp-content/themes/<theme>/diluxone-users/` is enough, and the list of files
is on the Status screen.

A template receives what it draws in one array, `$args`, the way WordPress's
own `get_template_part()` passes it: `$args['state']`, `$args['user']`. Each
template's docblock names its keys and their types. A key added later is
optional and read with a default, so a copy made before it keeps working:
`templates/fields.php` takes `redirect` (where its save goes on to) and
`skip` (a "Skip for now" link), both used by the step that asks for the
required fields missing after a sign-in.
`templates/fields.php` draws its fields with `templates/fields-list.php`
(`user_id`, `fields`, and `refused`, the message under each phone the form
came back for), and so does the "Your details" tab
(`templates/account/details.php`): one fields form, the same action, nonce
and save as `[diluxone_users_fields]` with no group, holding a
`diluxone-users-panel` per block that has active fields, under the titles
`diluxone_users_field_blocks()` gives, each ending in a Save button that sends
the whole form. The titles are the site's, typed on User fields › Fields and
stored in the network-scoped `diluxone_users_field_block_titles` (a map
`main`/`extra` => text, read through `diluxone_users_field_block_titles()`); a
block left empty keeps the translated default, "About you" for main and "A bit
more about you" for extra. They are text: the template prints them with
`esc_html()`.
The tab no longer goes through `templates/fields.php`, so a theme's copy of
that file stops applying there: to change how the fields look everywhere,
copy `templates/fields-list.php`; to change the tab's boxes, copy
`templates/account/details.php`.
`templates/login.php` has a `social-pending` state, the holding screen of a
social sign-in waiting on its address: `$args['email']` is then that address
already masked (`a•••@e•••.com`), and the form posts `diluxone_users_sso_resend`
with a `diluxone_users_sso_resend` nonce in `diluxone_users_nonce`.

A template escapes what it prints, line by line, and a copy in a theme has to
do the same. To draw one of the plugin's templates from code,
`diluxone_users_template_part( 'account/home', $args )` prints it where it is
called; `diluxone_users_render()` returns it instead, for a shortcode. The
account area and its pieces print with `diluxone_users_account_area()`,
`diluxone_users_avatar_form()`, `diluxone_users_handle_form()`,
`diluxone_users_fields_form()`, `diluxone_users_sessions_list()`,
`diluxone_users_accounts_list()` and `diluxone_users_notifications_form()`;
their shortcodes return the same markup.

## E-mails

`diluxone_users_mail_templates` filters the e-mails a site can rewrite per
language on E-mail notices › Templates: an add-on that sends its own mail
registers it there (label, help, placeholders, the placeholders it cannot be
sent without, and a callable giving back the shipped subject and body) and gets
the screen, the per-language rewrite and the way back to its own words.
`diluxone_users_notification` filters a notice — the new-device and
security-change e-mails — right before it is sent, with the subject and body,
the user and the notice's key.
`diluxone_users_login_email` filters the sign-in link's message (subject and
body, the address, the link), and `diluxone_users_sso_confirm_email` the
message that confirms a social account's address by mail, with the same
three and the provider's name: both run after the site's rewrite, right
before the mail is sent.

A notice is written in the language of the person it goes to — their own
language on their profile, or the site's — whoever's request sent it: the
plugin's words, the site's rewrite for that language and the date are all
in it, and the request's language is put back afterwards.

## Options

`diluxone_users_option` filters any setting as it is read. A site that pins
one from code gets it pinned for good — and the admin says so, with the
function and file doing the pinning, rather than showing a control that does
nothing.

`diluxone_users_option_defaults` filters the settings the plugin knows, with
their defaults. An add-on that stores settings through the plugin's helpers
adds its keys here, and from then on each is a setting like the plugin's own:
read with its default by `diluxone_users_option()`, written by
`diluxone_users_save_options()`, carried by the settings file on Maintenance ›
Tools, and stored where its scope says. Only keys of the plugin's namespace
(`diluxone_users_`) that the plugin does not have are taken: a key outside it
would let a screen or a settings file write an option that is not the
plugin's, and the plugin's own defaults are not changed from outside.

```php
add_filter( 'diluxone_users_option_defaults', function ( array $defaults ): array {
    $defaults['diluxone_users_sms_sender'] = '';
    return $defaults;
} );
add_filter( 'diluxone_users_option_scope', function ( string $scope, string $key ): string {
    return 'diluxone_users_sms_sender' === $key ? 'network' : $scope;
}, 10, 2 );
```

`diluxone_users_option_scope` filters where a setting belongs on a network:
`network`, `hub` or `site`, as `includes/options-scope.php` has it for the
plugin's own. An add-on that stores settings through
`diluxone_users_update_option()` gives its keys a scope here; a key nobody
gave one is `site`. On a network where the plugin is on for every site each
scope is stored where it says — the network's options, the main site's, the
site's own — and on a single site everything is the site's.

`diluxone_users_panel_scope` does the same for a tab registered with
`diluxone_users_register_panel()`: it receives the scope, the screen and the
tab, and a tab that saves network settings answers `network` and is drawn in
Network Admin instead of on the sites. A save that writes through
`diluxone_users_save_options()` only writes the settings owned where it is
drawn, whatever the form sends.

`diluxone_users_options_with_markup` filters the settings whose value may hold
HTML. A key on the list is stored through `wp_kses_post()`, the tags a post may
have; any other is stored as plain text. The plugin's own list is
`diluxone_users_login_legal`, the line under the sign-in and registration
forms, so it can link the terms and the privacy policy. An add-on whose
setting needs a link adds its key; a key taken off the list is stored as plain
text from its next save.

## Privacy

The plugin owns the `diluxone_users_` namespace, and what an add-on keeps
about a person is under it too. The add-on may not be running when somebody
asks for their data or for it to be erased, so both work by the prefix: the
erasure takes every `diluxone_users_` and `_diluxone_users_` user meta key the
person has (but the sites an administrator removed them from and a closed
account's flag, which keep them off and closed), and the export lists every
key of the namespace nothing explained, by name, with its value withheld.
Deleting the plugin with the wipe ticked deletes the namespace by prefix as
well.

- `diluxone_users_privacy_export_items` (filter) receives the export's rows,
  `( array $items, int $user_id )`, in the shape WordPress's exporters return
  (`group_id`, `group_label`, `item_id`, `data`). An add-on adds its own here.
- `diluxone_users_privacy_keys` (filter) receives the keys the plugin accounts
  for, `( array $keys, int $user_id )`. Name the keys your rows explain, so the
  export does not list them again; a key outside the namespace that you keep
  about a person is erased only when it is on this list. WordPress's own keys
  are never erased, whatever the list says.

## Functions an add-on may call

The hooks above are the way in; these are the functions an add-on calls once it is in. They are public API like the hooks: their names and signatures stay, and a change to one is a breaking change of the plugin. Anything else in `includes/` is the plugin's own and may change in any version.

**Whether the plugin is on, and where a setting lives**

| Function | What it answers |
| --- | --- |
| `diluxone_users_awake(): bool` | Whether the plugin is working here: on a network, only when it is on for the whole network. Load nothing of yours while it is false. |
| `diluxone_users_admin_owns( string $scope, string $context = '' ): bool` | Whether a setting of that scope (`network`, `hub`, `site`) is set from where the admin is being looked at (the current context when left out). |
| `diluxone_users_hub_image_url( int $id, string $size ): string` | The address of a media-library picture stored by the hub, from any site of the network. |

**The admin's pieces** (the same ones the plugin's screens use, so a screen of yours looks like the rest)

| Function | What it draws |
| --- | --- |
| `diluxone_users_ui_text( array $field ): void` | A text box: `label`, `id`, `name`, `value`, optional `help`, `placeholder`, `required`, `code`. |
| `diluxone_users_ui_number( array $field ): void` | A number box: `label`, `name`, `value`, optional `id`, `suffix`, `help`, `min`, `max`, `step`. |
| `diluxone_users_ui_field_open( string $label, string $target = '' ): void` / `diluxone_users_ui_field_close( string $help = '' ): void` | The frame around a control of your own, with its label and help. |
| `diluxone_users_panel_allowed(): void` | Stops a panel's save that is not allowed here (capability, and the screen's scope); call it after the nonce, first thing. |
| `diluxone_users_scope_control( string $prefix, string $help = '', string $spared = '' ): void` | The "everybody / only some roles" picker of a setting pair `{prefix}_scope` and `{prefix}_roles`. |
| `diluxone_users_scope_posted( string $prefix, string $screen ): array` | That pair, read from the request the picker posted, ready for `diluxone_users_save_options()`. |

**Who a setting applies to**

| Function | What it answers |
| --- | --- |
| `diluxone_users_scope( string $prefix ): string` | `all` or `some`, for a `{prefix}_scope` / `{prefix}_roles` pair. |
| `diluxone_users_scope_includes( int $user_id, string $prefix ): bool` | Whether that person is in it, by any of their roles (on a network, on any of their sites). |
| `diluxone_users_scope_roles_of( int $user_id, string $prefix ): array` | Every role the person holds that the pair asks about: on a network, for a pair whose `{prefix}_roles` is the network's, every role on all their sites; otherwise their roles on this site. A super admin counts as `administrator`. For a rule that refuses when any one of them is outside the list, which `diluxone_users_scope_includes()` (one is enough) does not answer. |

**Signing in and the second step**

| Function | What it does |
| --- | --- |
| `diluxone_users_register_way( string $id, array $way ): void` | Registers a way in (see "Ways in"). |
| `diluxone_users_2fa_methods(): array` | The second-step methods there are, the plugin's and the add-ons', as ticked on the screen. |
| `diluxone_users_2fa_offered( int $user_id ): bool` | Whether the site offers that person a second step at all. |
| `diluxone_users_2fa_forget_browsers( int $user_id ): void` | Forgets every browser that person's second step trusted; call it when a factor of theirs is removed. |
| `diluxone_users_backup_left( int $user_id ): int` | How many backup codes that person has left. |
| `diluxone_users_backup_generate( int $user_id, int $many = 8 ): array` / `diluxone_users_backup_stash( int $user_id, array $codes ): void` | A fresh set of backup codes (stored hashed), and the one-minute window in which they can still be shown. |
| `diluxone_users_security_confirm( int $user_id, string $code, string $target ): void` | Stops an action that weakens an account unless a current second-step code came with it; sends the person back to `$target` and does not return when it did not. |
| `diluxone_users_cookie_set( string $name, string $value, int $expires ): void` | Sets a cookie with the plugin's path, domain and flags, the same on every site of a network. |

**The account area and the network**

| Function | What it does |
| --- | --- |
| `diluxone_users_register_section( string $id, array $args ): void` | Registers a section (see "Account area sections"). |
| `diluxone_users_sections( bool $all = false ): array` | The sections, sorted, with what the administrator decided; `$all` includes the ones turned off. |
| `diluxone_users_account_template(): string` / `diluxone_users_login_template(): string` | The account area's and the sign-in page's shape, as stored (see "Shapes"). |
| `diluxone_users_membership_may_join( int $user_id, int $site_id ): bool` | Whether that person may be added to that site: not a super admin, not closed, the site live, not removed by an administrator. |
| `diluxone_users_membership_add( int $user_id, int $site_id, string $how ): bool` | Adds them with the site's role, through the same checks; `$how` is what made it (`click` for a person who asked). |

**Colours**

| Function | What it answers |
| --- | --- |
| `diluxone_users_style_accent(): string` | The accent in use, as a hex colour. |
| `diluxone_users_theme_palette(): array` | The active theme's palette, `slug => hex`. |
| `diluxone_users_color_roles(): array` | The roles a colour plays (accent, text, ground…), `role => array( name, paints )`. |
| `diluxone_users_color_map(): array` | Which colour of the theme's palette plays each role, `role => slug`: guessed from the palette's names, or as an add-on set it through the filter. |
| `diluxone_users_color_own(): array` | The colours the plugin paints each role with when the site chose its own look, `role => hex`. |
| `diluxone_users_palette_has( array $palette, string $hex ): bool` | Whether a colour is one of a short palette's. |

## Users+ Pro on these screens

Where a feature of DiluxOne Users+ Pro would go, the plugin's screens draw
the control it would be, switched off: the radio or the tick box among the
real answers, the button where the real one goes, the network's card beside
Google and Microsoft, the shape in the row of shapes, the row of the summary.
Each carries the "Pro" badge, a line about what it adds and one "Learn about
Users+ Pro" link to the plugin's page on diluxone.com in the admin's language
(the Spanish page for any `es_*` locale, the English one for every other), the
same on every screen. Nothing in it has
a name, so nothing posts it; being disabled, a keyboard passes over it and a
screen reader announces it as unavailable, and the link is what the keyboard
lands on. Only administrators see them, only on the plugin's screens, never
on the front end, and the setting `diluxone_users_pro_previews` (Maintenance ›
Tools on a single site, Network Admin's Users+ Pro tab on a network) turns
them all off.

`diluxone_users_upsell` (filter) decides each one: `( bool $show, string $where )`,
where `$where` names the placeholder (`<screen>/<tab>/<feature>`). Users+ Pro
answers false for every feature it draws for real; a site that wants none of
them answers false for all. Each placeholder's outer element carries
`data-diluxone-users-pro="<where>"`.

Where a preview is not drawn, its place is the add-on's. A radio or a tick box
(`diluxone_users_ui_pro_choice()`) asks `diluxone_users_pro_choice` (filter,
`( array $choice, string $where, array $args )`, empty by default): the choice
it answers, as `diluxone_users_ui_choices()` takes one (`name`, `value`,
`checked`, `title`, `help`, `children`), goes into the same group as the free
answers, and an empty one draws nothing. A control of its own shape
(`diluxone_users_ui_pro_control()`) fires `diluxone_users_pro_control` (action,
`( string $where, array $args )`) there instead. Every screen that carries a
preview is a form and fires `diluxone_users_panel_saved` on its save, which is
where the add-on saves what it drew.

| `$where` | What it is |
| --- | --- |
| `social/providers/<network>` | One greyed card per network on Social login › Providers, after Google and Microsoft: `linkedin`, `twitter` (X), `facebook`, `github`, `wordpress`, `yahoo`, `twitch`, `discord`, `gitlab` and `amazon`, each with its logo and its name and nothing to open. Pro answers false for each one it brings. |
| `login/ways/more` | A disabled tick box after the ways in on Access › Ways in: signing in with a passkey. |
| `security/summary/more` | A row of the table on Security › Summary: passkeys. |
| `design/login/shapes` | Two disabled shapes after the real ones on Design › The sign-in page: split with a picture, and on a full background. One answer turns both off. |
| `design/account/shapes` | A disabled shape after the real ones on Design › Account area: the account area with a cover. |
| `design/brand/any-color` | A disabled colour picker after the accent's swatches on Design › Your brand: any colour. |
| `network/membership/rules` | Two disabled radios after the answers on Network Admin › Membership: "whoever asks" and "by invitation". One answer turns both off. |
| `social/general/roles` | A disabled radio under "Who can sign in with a network" on Social login › Rules: only some roles. |
| `security/2fa/roles` | A disabled radio among the three modes on Security › Two-step verification: asking only some roles. |
| `security/2fa/app` | A disabled tick box among the methods on Security › Two-step verification: the authenticator app. |
| `security/2fa/link` | A disabled radio under the e-mail link's line on Security › Two-step verification: "Let it decide", asking on the link only when the code proves something the link did not. |
| `security/2fa/remember` | A disabled tick box after it on Security › Two-step verification: not asking again on a browser. |
| `account/handle/cooldown` | The days between one change and the next, a disabled number box on Account area › Public name. |
| `account/handle/locked` | A disabled tick box after it on Account area › Public name: once chosen, the name cannot be changed. |
| `fields/missing/ask` | A disabled tick box on User fields › After sign-in: asking an account for the required fields it has no answer for, right after it signs in. |
| `fields/missing/skip` | A disabled tick box after it on User fields › After sign-in: letting them skip that step. |
| `account/sections/more` | A disabled "Add a section" button beside the heading on Account › Sections, where `diluxone_users_account_sections_head` draws Pro's real one. |
| `design/brand/theme` | A disabled radio after the two answers to where the look comes from on Design › Your brand: "From your theme", the plugin painted with the theme's colours. |
| `design/brand/color-map` | The rows right after "From your theme" on Design › Your brand, while the theme publishes a palette, their selects disabled: which colour of the theme's palette does what. `diluxone_users_design_theme_colors` ( `$palette` ) fires after them, whether they are drawn or not. |

### The behaviours Users+ Pro brings back

A few answers the plugin does not offer as settings: it has one behaviour,
fixed, and the setting that would change it is Users+ Pro's, drawn as its
preview where it would be. Each fixed behaviour is read through a filter
whose default is that behaviour, so an add-on that answers it is what the site
gets, and the plugin stores nothing for it and names no setting of Pro's.

| Filter | Arguments | The plugin's answer |
| --- | --- | --- |
| `diluxone_users_2fa_on_link` | `( bool $ask, int $user_id )` | true: the second step is asked after the e-mail link too. |
| `diluxone_users_handle_cooldown` | `( int $days, int $user_id )` | 0: a public name is changed whenever its owner likes; more days lock the box until then and refuse a change sent anyway. |
| `diluxone_users_handle_locked` | `( bool $locked, int $user_id )` | false: a public name can always change. True, once a name is chosen, disables the box, refuses a change sent anyway and says "Your public name cannot be changed."; somebody with no name yet can still choose one. |
| `diluxone_users_ask_missing` | `( bool $ask )` | false: a sign-in sends an account missing a required field straight to where it was going; true sends it first to the step on the account page (see Required fields missing after a sign-in). |
| `diluxone_users_ask_missing_skippable` | `( bool $skip, int $user_id )` | true: the step ends in a "Skip for now" link to where they were going. False draws a "Sign out" link in its place, so the step always has a way out; keeping the person on the step until it is done is the add-on's. |
| `diluxone_users_colors_from_theme` | `( bool $theme )` | false: the plugin paints itself with its own palette and the accent chosen on Design › Your brand, light and dark; true takes the theme's colours, measured where the palette cannot say them (see The theme's colours). |
| `diluxone_users_sso_role_allowed` | `( bool $allowed, int $user_id, string $context )` | true: every role may sign in with a social account. `$context` is `account` for an existing account, or `register` (with `$user_id` 0) for one about to be created with `diluxone_users_register_role()`; false there creates no account and logs `register_role`. |

## The emergency switch

`define( 'DILUXONE_USERS_SAFE_MODE', true );` in `wp-config.php` makes the
plugin step aside at every door it put up: nothing redirects, nobody is asked
for the second step, social sign-in is shut, and WordPress's own
wp-login.php is the way in, on every site of a network. Every dashboard page
says so while it lasts. The constant feeds `diluxone_users_safe_mode`
(filter), which is also how a must-use plugin turns it on.
`diluxone_users_safe_mode()` answers whether it is on: an add-on that adds a
redirect, a door or a step of its own asks it and stands aside too.

## Caching

The sign-in, registration and account pages — the pages chosen for them, and
any page whose content carries one of the plugin's shortcodes — send
WordPress's `nocache_headers()` and define `DONOTCACHEPAGE` on
`template_redirect`; a shortcode drawn from a widget or a template does it as
it renders. A site that draws the shortcodes from its own templates says so
early with `diluxone_users_no_cache` (filter, default false), so the headers
go before the page starts.

## Things that happen

- `wp_login` — WordPress's own action fires for every way in, not only the
  password: the e-mail link, a social account, an add-on's door and the end of
  the second step fire it once, with the login name and the `WP_User`, after the
  session is open. A password that went through the second step fires it once,
  when the password was right, as WordPress does.
- `diluxone_users_logged_in` — somebody got in. Receives the user and how.
- `diluxone_users_member_added` — on a network, the plugin made somebody a
  member of a site. Receives the user, the site, the role and what made it
  (see [Membership on a network](#membership-on-a-network)).
- `diluxone_users_fields_saved` — a person's fields were saved.
- `diluxone_users_2fa_failed` — a wrong second-step code. Receives the user
  and the method (see [Second-factor methods](#second-factor-methods)).
- `diluxone_users_register_sections`, `diluxone_users_register_panels`,
  `diluxone_users_register_ways` — the moments to register.
- `diluxone_users_account_security`, `diluxone_users_security_action`,
  `diluxone_users_person_saved` — see [The account's Security section](#the-accounts-security-section)
  and [A person in Users](#a-person-in-users).

## What the plugin will not promise

Anything not on this page. Function names, file layout and markup can change
between versions; these seams cannot.
