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
| Sessions | See them and close them | A limit on open sessions, a warning on a new device |
| Activity | The log as it is | Longer retention, export, alerts |
| Fields | The field types of 1.0.0 | Files and images, conditional and repeating fields |
| Account area | Its own sections: rename, reorder, hide | Sections of the site's own |
| Design | Two sign-in layouts (in the page, a card), the simple account area, a short palette and the theme's colours, or no stylesheet at all | The split and full-background layouts, the account area with a cover, any colour and the fine tuning |
| Multisite | Network activation, the hub, membership "every site" | Membership "with a click" and "by invitation" |
| Privacy | Everything | — |

In the free plugin a Pro feature shows only as a small badge next to the choice it belongs to, linking to diluxone.com: never a screen of its own, never a notice.

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
