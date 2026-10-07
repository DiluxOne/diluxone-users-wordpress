# Roadmap

What comes after 1.0.0, and what the plugin will not do. Each version gets its plan before its code.

## Free and Pro

The plugin on wordpress.org is free and complete for a site that needs people to sign up, sign in and look after their account. **DiluxOne Users+ Pro** is a separate add-on, not on wordpress.org: it requires this plugin (`Requires Plugins: diluxone-users`) and adds to it through the hooks in [`extending.md`](extending.md). No Pro code ships in this plugin and nothing here waits for a licence. The add-on is installed and updated by the DiluxOne plugin, which connects the site to its DiluxOne account; without a paid plan the add-on keeps working and stops receiving updates.

The rule for drawing the line: when in doubt, Pro. Moving something from Pro to free later is easy; moving it the other way takes it away from people who had it.

| Area | Free | Pro |
| --- | --- | --- |
| Signing in | Password, a link by e-mail | — |
| Registration | The registration form | Approval by an administrator, invitations, allowed domains, spam checks |
| Social login | Google and Microsoft | The other ten providers and any OpenID Connect provider (below) |
| Two-step verification | A code by e-mail, backup codes | An authenticator app, required per role, remember this device, SMS |
| Passkeys | — | Everything |
| Sessions | See them and close them; an e-mail on a sign-in from a new device | A limit on open sessions, where a new device signed in from |
| Activity | The log as it is | Longer retention, export, alerts |
| Fields | The field types of 1.0.0 | Files and images, conditional and repeating fields |
| Account area | Its own sections: rename, reorder, hide, and the site's own words before, after or instead of one | Sections of the site's own |
| Design | Two sign-in layouts (in the page, a card), the simple account area, a short palette and the theme's colours, the sizes and spacing, or no stylesheet at all | The split and full-background layouts, the account area with a cover, any colour and the fine tuning of which colour does what |
| Multisite | Network activation, the hub, membership "every site" | Membership "with a click" and "by invitation" |
| Privacy | Everything | — |

How Pro shows in the free plugin:

- **Where it belongs, and nowhere else.** A part of a screen shows as a blurred, inert preview with a "Pro" badge and a link to diluxone.com. A Pro option sits as its own card after the real ones, never as a padlocked choice inside a working control. Only administrators see it, in the plugin's own screens: never a screen of its own, never a notice, never anything on the front end. One setting turns every preview off.
- **A picture of the feature, not the feature.** The preview holds no Pro code and no Pro value, and the free plugin names none of Pro's settings: it owns the whole `diluxone_users_*` namespace (scopes, reserved field names, export, erasure and uninstall work by the prefix), and Pro adds its own through the same hooks an add-on uses.
- **Buying happens outside.** The free plugin links out and stops there; it never downloads or installs anything. Buying from inside the admin belongs to the DiluxOne plugin, which installs and updates Pro.
- **Turning Pro off loses nothing.** A stored choice the free plugin does not know (a Pro layout, a provider, a method, a section of the site's own) is kept as it is: shown as an inactive Pro choice on its screen, kept when the screen is saved, and replaced on the front end by the closest free one (a card for a Pro layout, the simple account area for the cover, the default colour when a Pro colour does not read). A way in Pro brings (a passkey) is not drawn while Pro is off: its setting and people's keys are kept, the sign-in page opens on the next way in the site's order, and the account area says nothing about it. A section of the site's own is not drawn either, and is listed on the Sections tab with the badge until Pro is back. A network whose membership is a Pro rule adds people to the hub only and tells a non-member the site is by invitation, until the rule is chosen again.

## Next: sign-in through any OpenID Connect provider (Pro)

Driven by diluxone.com, where every customer signs in with DiluxOne ID (`id.diluxone.com`, a standard OIDC provider). Everything below is generic: DiluxOne ID is configured like any other provider, and nothing in the plugin knows about it.

- **A generic OIDC provider.** The admin enters an issuer URL; the plugin reads `/.well-known/openid-configuration` (endpoints, keys, `end_session_endpoint`) and uses the authorization code flow with PKCE. More than one can be configured, each with its own button label.
- **Identity is `iss` + `sub`.** Store the issuer next to the subject for every linked account (today only `sub` is stored, under `diluxone_users_sso_{provider}`) and look up by the pair. This also fixes Microsoft on `/common`, where the issuer depends on the tenant. Linking an existing account by e-mail stays a fallback, only with a verified e-mail.
- **Accounts only through SSO.** A setting so new accounts are created only by signing in through a provider: no registration form, and the e-mail link cannot create accounts.
- **SSO-only sign-in, per role.** The chosen roles (on diluxone.com: customers) can only sign in through a provider: no password and no e-mail link. Roles left out (administrators, the team) keep their local sign-in; `DILUXONE_USERS_SAFE_MODE` stays the break-glass.
- **Logout reaches the provider.** Signing out of WordPress redirects to the provider's `end_session_endpoint` (RP-initiated logout), so the provider's session ends too.
- **Later: Back-Channel Logout.** An endpoint that receives the provider's logout token and ends that user's WordPress sessions, so signing out anywhere signs out here.

## Will not do

- **Deprovisioning** (deleting or anonymising a WordPress user when the account is deleted at the provider: SCIM, RISC/SSF). The plugin does what the market does: the account stays in WordPress and cannot sign in once the provider refuses it. A site that needs more builds it on the plugin's hooks; diluxone.com does it in its own site plugin.
