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
"Saved." notice. `render` prints the fields and nothing else. The button is
not at the foot of the form: it heads the column beside the fields — the rail,
the preview, or a rail of its own when the tab has neither — so it stays in
view while the settings scroll, in the same place on every tab.

Every screen takes panels, with no exceptions left: `diluxone-users`
(Overview), `diluxone-users-login` (Access — sign-in page, ways in,
registration), `diluxone-users-security`, `diluxone-users-social`,
`diluxone-users-account`, `diluxone-users-fields`, `diluxone-users-design`,
`diluxone-users-notices`, `diluxone-users-status` (Maintenance — status,
tools, lockout). `diluxone-users-register` and `diluxone-users-tools` are not
screens any more; their slugs redirect to the tab they became.

A tab that draws its own markup — a list with its own actions, a form with a
nonce of its own — registers with `'form' => false` and the screen stays out
of its way. That is how the field list, the provider grid and the open
sessions live beside ordinary settings tabs. A form of its own that saves
settings gives itself an `id` and asks for its button with
`diluxone_users_ui_save( $form_id, $label )` before the column beside it is
drawn; the button reaches the form through its `form` attribute.

A tab exists because something registered it. Nothing registered means no tab
— not an empty one.

### The shape every screen has

The first tab of a screen with settings is a **Summary**: `form => false`,
`position => 0`, drawn with `diluxone_users_summary_table()`. It reads what
the other tabs write and edits nothing. A row is a label, a state, one line of
detail and a "Change it →" link to the tab that owns it.

There is one vocabulary of state, four words, on every screen:
`active`, `pending` (half set up, or waiting on something — always with a
reason), `off`, and `unknown` for what cannot be told from inside the site.
`diluxone_users_state_pill( $state, $why, $word )` draws one.

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

A control that does not apply right now stays on the screen and keeps saving —
what is chosen applies the day the reason goes away — but it is dimmed and
`diluxone_users_not_now( $why, $url )` says why, above it, with the way out.

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
an account.

While the e-mail link is the only way in, `users_can_register` reads as off
whatever is stored (`diluxone_users_block_registration()`), and both screens
say so.

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
  `templates/hub-door.php` (`$door` is `login`, `register` or `account`,
  `$url` the hub's address, `$hub` its name), which a theme can replace like
  any other template.
- `diluxone_users_off_hub()` says whether the current site is a site of a
  network other than the hub; `diluxone_users_sends_to_hub()` whether it sends
  people there (not a site on a domain of its own, see
  `diluxone_users_mapped_sites()`). Both are false on a single site.

### Membership on a network

Which sites an account is a member of is the network's policy
(`diluxone_users_membership()`: `all`, `click` or `invite`, set on Network
Admin › Membership; see [`architecture.md`](architecture.md)). What an add-on
or a theme builds on:

- `diluxone_users_member_added` (action) fires every time the plugin makes
  somebody a member of a site: `( int $user_id, int $site_id, string $role,
  string $how )`, where `$how` is `account` (a new account under "every
  site"), `site` (a new site), `sync`, `sign-in` or `click` ("Join this
  site"). Once per person and site in a request. Additions made by WordPress
  or by an administrator do not fire it.
- `[diluxone_users_join]` draws `templates/join.php` for somebody signed in
  who is not a member of the site: `$state` is `click` (a button that joins
  it), `invite` (the site is by invitation) or `joined`, `$site` and `$hub`
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
    $providers['twitch'] = array(
        'name'      => 'Twitch',
        'authorize' => 'https://id.twitch.tv/oauth2/authorize',
        'token'     => 'https://id.twitch.tv/oauth2/token',
        'userinfo'  => 'https://api.twitch.tv/helix/users',
        'scope'     => 'user:read:email',
        'brand'     => '#9146FF',
    );

    return $providers;
} );
```

Its icon goes through `diluxone_users_sso_icon_paths`, and the step-by-step
for its console through `diluxone_users_sso_guides`. It then appears on the
Social login screen with its card, its redirect URL and its live test, like
any other.

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
filter says what exists, the option says what is offered.

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

A new site starts with the first and last name only, neither required. The
fields offered on User fields › Suggested fields — a country, a date of birth,
a gender and a phone — come from `diluxone_users_suggested_fields` (filter):
an array of field definitions as the stored list holds them. A key already on
the list is not offered again; one ticked is added at the end of the list by
`diluxone_users_add_suggested_fields( $keys )`.

## Looks

```php
// Another shape for the sign-in page. The id becomes a class on the frame:
// .diluxone-users-login-frame--neon — bring your own CSS.
add_filter( 'diluxone_users_login_templates', function ( array $t ): array {
    $t['neon'] = array(
        'label'   => __( 'Neon', 'my-addon' ),
        'help'    => __( 'Dark, with a glow.', 'my-addon' ),
        'picture' => false,   // true gives it the picture field
    );
    return $t;
} );

// And for the account area: .diluxone-users-account--<id>
add_filter( 'diluxone_users_account_templates', function ( array $t ): array { … } );
```

Colours and corners are CSS custom properties — `--diluxone-users-accent`,
`--diluxone-users-radius` and the rest — so a site or an add-on can repoint
them from a stylesheet without any PHP at all.

### The split layout's panel

The half of the window the split shape gives to a picture can carry words
instead, or as well. They are written in **Design → The sign-in page → What
the panel says**: a mark, a heading, a paragraph, a list of what an account
gets, and the line that holds the bottom. Empty is the answer that was there
before — the picture on its own, hidden from screen readers.

The heading and the list are read a line at a time, because that is how both
are written: two deliberate lines of heading, one advantage per line. With a
picture underneath, a shade goes between the two so the words stay readable
(`--diluxone-users-login-panel-over`).

This is the seam a site was missing: before it, the only way to put a sentence
on that panel was to copy a page template, and a copied template does not
follow the plugin when the plugin changes.

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
| `--diluxone-users-bleed` | How far the cover breaks out of the theme's column | `calc(50% - 50vw)` |
| `--diluxone-users-cover-pad` | The air inside the cover band | `40px` |
| `--diluxone-users-header-gap` | Between the picture and the name | `18px` / `24px` |
| `--diluxone-users-avatar` | The picture | `64px` / `96px` |
| `--diluxone-users-bar-bg` | Behind the menu's strip | `transparent` |
| `--diluxone-users-bar-rule` | Its rule, whole: `1px solid #ddd` | none |
| `--diluxone-users-bar-pad` | Inside the strip, above and below the menu | `10px` |
| `--diluxone-users-bar-gap` | Under the strip | `28px` |
| `--diluxone-users-bare-top` | Above the area when it has no header | `24px` |
| `--diluxone-users-nav-gap` | Between menu items | by the shape |
| `--diluxone-users-tab-pad` | Inside one | by the shape |
| `--diluxone-users-side-w` | The side menu's column | `minmax(180px, 220px)` |
| `--diluxone-users-nav-min` | Narrowest item when the menu wraps on a phone | `140px` |
| `--diluxone-users-avatar-ring` | The ring around the picture on a cover | `3px solid rgba(255,255,255,.35)` |
| `--diluxone-users-cover-at` | Where the cover picture is anchored | `center` |
| `--diluxone-users-cover-over` | What is laid over it | the cover colour at 78% |
| `--diluxone-users-sent-icon` | The circle on "check your email" | `88px` |
| `--diluxone-users-dial-w` | The dial-code column of a phone field | `minmax(0, 9rem)` |
| `--diluxone-users-border-w` | A control's edge — inputs and buttons | `1px` |
| `--diluxone-users-accent-soft` | The accent with the volume down | the accent at 12% |
| `--diluxone-users-panel-gap` | Between the rows of a panel's body | `normal` |
| `--diluxone-users-body-end` | Under the content, on a cover | `64px` |
| `--diluxone-users-body-top` | Above it | none |
| `--diluxone-users-ground` | The colour the whole account area sits on | none |
| `--diluxone-users-row-w` | The width the area's three rows line up to | from the admin |
| `--diluxone-users-row-pad` | And the gutter inside them | from the admin |
| `--diluxone-users-pad` | The gutter a cover band keeps once the breakout is off | the row gutter |
| `--diluxone-users-display-font` | The face for the headings the plugin does size — the sign-in screens it takes over | inherit |
| `--diluxone-users-login-min` | How tall a frame that took over the page is, at least | `min(720px, 80vh)` |
| `--diluxone-users-login-w` | The column the sign-in block is held to | none |
| `--diluxone-users-login-box-w` | The box the form sits in, on a split | `520px` |
| `--diluxone-users-login-box-pad` | The air inside it | `48px` |
| `--diluxone-users-login-title` | The form's heading, inside a frame | `clamp(26px, 2.4vw, 34px)` |
| `--diluxone-users-login-title-weight` | And its weight | `800` |
| `--diluxone-users-login-panel-bg` | The split panel's colour | the accent |
| `--diluxone-users-login-panel-ink` | The words on it | `#fff` |
| `--diluxone-users-login-panel-pad` | The air inside it | `56px` / `36px 20px` |
| `--diluxone-users-login-panel-w` | The column the words are held to | `480px` |
| `--diluxone-users-login-panel-mark` | The mark at the top of it | `42px` |
| `--diluxone-users-login-panel-title` | Its heading | `clamp(30px, 3.4vw, 50px)` |
| `--diluxone-users-login-panel-title-weight` | And its weight | `800` |
| `--diluxone-users-login-panel-art` | A drawing over its colour, whole: `url(…) 0 0 / cover no-repeat` | none |
| `--diluxone-users-login-panel-art-opacity` | How loud that drawing is | `1` |
| `--diluxone-users-login-panel-over` | The shade between a picture and the words on it | `rgba(0,0,0,.45)` |
| `--diluxone-users-login-point-mark` | The mark before each advantage, as a mask | a tick |
| `--diluxone-users-note-bg` | Behind the note beside a drawing | transparent |
| `--diluxone-users-note-pad` | The air inside it, which turns it into a box | `0` |
| `--diluxone-users-note-gap` | Between the drawing and the words | `8px` |
| `--diluxone-users-note-mark` | The drawing's own colour | the note's |

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

The plugin can take its palette from the active theme instead of having one:
Design → Your brand → "Use my theme's colours". It reads what the theme
publishes in its `theme.json`, through `wp_get_global_settings()`, and maps it
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
three frames that take the page over it is the plugin's.** A designed screen,
edge to edge, with a theme's `h2` dropped into it reads as a form that landed
in a blog post; a form the theme placed itself should look like everything
else the theme places, and does. Those sizes are properties —
`--diluxone-users-login-title`, `--diluxone-users-login-panel-title` — so a
site with a scale of its own still says it once, as a value.

What the plugin does keep is the layout of its own header: the margins around
the name, the gap beside the picture, the air at the end of a cover. Those are
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
| Which shape | `--plain` `--cover` | the area |
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

## Options

`diluxone_users_option` filters any setting as it is read. A site that pins
one from code gets it pinned for good — and the admin says so, with the
function and file doing the pinning, rather than showing a control that does
nothing.

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

## The emergency switch

`define( 'DILUXONE_USERS_SAFE_MODE', true );` in `wp-config.php` makes the
plugin step aside at every door it put up: nothing redirects, nobody is asked
for the second step, passkeys and social sign-in are shut, and WordPress's own
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
  password: the e-mail link, a social account, a passkey and the end of the
  second step fire it once, with the login name and the `WP_User`, after the
  session is open. A password that went through the second step fires it once,
  when the password was right, as WordPress does.
- `diluxone_users_logged_in` — somebody got in. Receives the user and how.
- `diluxone_users_member_added` — on a network, the plugin made somebody a
  member of a site. Receives the user, the site, the role and what made it
  (see [Membership on a network](#membership-on-a-network)).
- `diluxone_users_fields_saved` — a person's fields were saved.
- `diluxone_users_register_sections`, `diluxone_users_register_panels` — the
  moments to register.

## What the plugin will not promise

Anything not on this page. Function names, file layout and markup can change
between versions; these seams cannot.
