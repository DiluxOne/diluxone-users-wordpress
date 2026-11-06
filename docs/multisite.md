# DiluxOne Users+ on a network

The readme's multisite answer says what a site administrator decides. This page holds the rest: what happens behind those decisions, for the network's administrator and for whoever supports them. How it is built is in [`architecture.md`](architecture.md) (the hub's doors, the way back, membership, the activity log on a network).

## Activation and settings

The plugin is activated for the whole network or not at all: WordPress offers only **Network Activate**, and a site it was left on for alone does nothing and asks the network's administrator to activate it for the whole network.

The second step, sessions, the proxy, the social sign-in apps and their rules, the user fields and what the activity log keeps are set once, in **Network Admin → DiluxOne Users+**, and a site administrator cannot change them for their own site. The sign-in, registration and account pages, how they look and what they say, are set on the main site, and every site uses them. Each site keeps its own menus, admin bar, reports and maintenance.

A network that used the plugin before it had network settings takes the main site's settings as the network's, and Network Admin lists what the other sites had set differently.

## Signing in across the network

Until **Network Admin → DiluxOne Users+ → Overview → Signing in across the network** is ticked, every site keeps its own wp-login.php. Once it is, people sign in, register and keep their account on the main site. On every other site the doors lead there: the "Sign in" in its menu, the plugin's shortcodes, its wp-login.php and WordPress's own sign-in links.

Whichever way the person gets in (e-mail link, password, social account, second step), they are sent back to the page they started from. The way back is only ever an address on a site of the network, kept for twenty minutes in a cookie of the main site and used once. A sign-in link opened on another device lands on the main site, signed in.

Social sign-in works on the main site only: register one redirect address per network in the providers' consoles. Whether new accounts may be created at all is the network's **Allow new registrations**.

## Membership

Which sites an account is a member of is one decision for the network, on **Network Admin → DiluxOne Users+ → Membership**, and nothing is added until it is confirmed there (Network Admin says so; until then WordPress's own memberships stand).

The free plugin's answer is every live site: a new account joins every site, a new site gets every account, and what was there before joins with **Sync everyone now** or `wp diluxone-users network membership sync`. A big network is added in the background, a batch at a time, and the screen shows how far it got. DiluxOne Users+ Pro, a separate add-on, adds two more answers: whoever asks (a "Join this site" button) and by invitation (only administrators add people).

The role is each site's own New User Default Role, and on the main site the role new accounts get. Somebody an administrator removed from a site is not added back until an administrator does; `[diluxone_users_join]` tells them, signed in on that site, that they are not a member of it.

## The activity log

The activity log is one table for the whole network. Each site's **Reports › Activity** shows and empties that site's rows, and also lists the sign-ins on the main site that came from it. **Network Admin → DiluxOne Users+ → Activity log** shows every site's rows, with the site each row happened on, and empties them all.

## Subdomains and domains of their own

Subdirectory and subdomain networks are supported. On a subdomain network WordPress sets the session cookie for the network's domain, which is what lets a sign-in on the main site reach every subdomain: do not pin `COOKIE_DOMAIN` to one host in `wp-config.php`.

A site on a domain of its own (not the network's or one of its subdomains) does not share that cookie, so people must sign in again there. That is not supported in this version, and Network Admin and that site's dashboard say so.
