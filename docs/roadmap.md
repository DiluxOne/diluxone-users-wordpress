# Roadmap

What comes after 1.0.0, and what the plugin will not do. Each version gets its plan before its code.

## The add-on

**DiluxOne Users+ Pro** is a separate, paid add-on, not on wordpress.org. It requires this plugin and adds to it through the hooks in [`extending.md`](extending.md); no Pro code ships here, and what Pro shows inside this plugin's screens is described there, under "Users+ Pro on these screens".

## Will not do

- **Deprovisioning** (deleting or anonymising a WordPress user when the account is deleted at the provider: SCIM, RISC/SSF). The plugin does what the market does: the account stays in WordPress and cannot sign in once the provider refuses it. A site that needs more builds it on the plugin's hooks; diluxone.com does it in its own site plugin.
