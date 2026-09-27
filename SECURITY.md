# Security Policy

## Reporting a vulnerability

**Please do not open a public GitHub issue or wordpress.org forum thread to report a security vulnerability.** This plugin decides who gets into a site; public disclosure before a fix is available puts every site running it at risk.

Instead, report the issue privately using **GitHub Security Advisories**:

🔗 **[Open a private security advisory](https://github.com/DiluxOne/diluxone-users-wordpress/security/advisories/new)**

This creates a confidential workspace inside the repository where the maintainers and you can discuss the issue, coordinate a fix, and agree on a disclosure timeline. Nothing is public until we both decide it's ready.

## What to include in your report

- **A clear description** of the vulnerability and its impact: who can do what they should not.
- **Steps to reproduce**, the smaller the better, including which way in is involved (e-mail link, password, a social provider, a passkey, a second factor) and whether it is a single site or a network.
- **Affected versions** of the plugin, and the PHP and WordPress versions you tested on.
- **Any proof-of-concept** code or payloads you used.
- **Your proposed severity** (low / medium / high / critical) and reasoning.
- **Suggested mitigation**, if you have one.

## What to expect

| Stage | Target time |
|-------|-------------|
| Initial response (we acknowledge the report) | within 72 hours |
| Triage and severity assessment | within 7 days |
| Fix or mitigation timeline | depends on severity, communicated after triage |
| Public disclosure (advisory + release) | coordinated with the reporter |

We aim to credit reporters in the published advisory unless you prefer to remain anonymous.

## Supported versions

Security fixes are released against the **latest published version** of the plugin. Older versions are not patched separately; please update to the latest release before reporting. Before the first release on wordpress.org, report against `main`.

| Version | Supported |
|---------|-----------|
| Latest published on wordpress.org (before that, `main`) | ✅ |
| Older versions | ❌ |

## Out of scope

The following are **not** considered security vulnerabilities for the purposes of this policy (though we still welcome reports as regular issues):

- Issues that require physical access to the server.
- Issues that require an already-compromised WordPress administrator account.
- Vulnerabilities in third-party services (the social login providers, the site's mail transport, etc.): please report those to the respective vendor.
- Best-practice deviations without a concrete attack path.

Thanks for helping keep DiluxOne Users+ and its users safe.
