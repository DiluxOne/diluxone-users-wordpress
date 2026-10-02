import { test as setup, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { Site } from '../support/api';
import { BASELINE } from '../support/baseline';
import { shell, wp, PLUGIN_DIR } from '../support/cli';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';
import { NETWORK_BASELINE_FILE, NETWORK_PAGES_FILE, SUBSITES, subsiteUrl } from './support';
import { fillCredentials } from '../support/ui';

/**
 * Makes the network the specs are written against, and remembers what it was.
 *
 * The two sites are created here and deleted by the teardown, so what is on
 * them needs no restoring. The settings the specs start from do: on a network
 * they are the network's and the main site's — the hub, where people sign in
 * and keep their account — and both outlive the run. So they are written
 * through the main site, with the main site's own pages, and what was there
 * before is written down with WordPress's registration setting and WP_DEBUG.
 */

setup('the tests site is a network, with the plugin on for all of it', async () => {
	// `wp core is-installed --network` exits non-zero on a single site, which
	// execFileSync turns into a throw with the reason in it.
	expect(
		() => wp(['core', 'is-installed', '--network']),
		'the tests site is not a network: run `make env-multisite` first'
	).not.toThrow();

	const active = wp(['plugin', 'list', '--status=active-network', '--field=name']);

	expect(active.split('\n').map((line) => line.trim()), 'this checkout is the plugin on for the whole network').toContain(PLUGIN_DIR);
});

setup('two sites, seeded, and the network settings written down', async () => {
	mkdirSync('build', { recursive: true });

	// What the teardown puts back. WP_DEBUG is off on the wp-env tests site,
	// and with it off PHP's notices and warnings never reach debug.log — so a
	// spec asking "did that add anything to the log" would be asking a log
	// that only hears about fatals.
	const previous: Record<string, unknown> = {
		registration: wp(['site', 'option', 'get', 'registration']) || 'none',
		wpDebug: wp(['config', 'get', 'WP_DEBUG', '--type=constant', '--format=json']),
	};

	wp(['config', 'set', 'WP_DEBUG', 'true', '--raw', '--type=constant']);
	wp(['site', 'option', 'update', 'registration', 'user']);

	const existing = wp(['site', 'list', '--field=path']).split('\n');
	const pages: Record<string, unknown> = {};

	// The hub: its pages are the network's sign-in, registration and account
	// pages, and the baseline — the network's settings and the hub's — is
	// written through it.
	const root = await Site.open(`${NETWORK_URL}/`);
	const hub = await root.seed();

	previous.options = await root.setOptions(
		BASELINE({
			login: hub.pages.login.id,
			register: hub.pages.register.id,
			account: hub.pages.account.id,
		}),
		{ flush: true, forgetTransients: true }
	);
	await root.clearMail();

	pages.root = hub.pages;

	writeFileSync(NETWORK_BASELINE_FILE, JSON.stringify(previous, null, 2));

	for (const slug of SUBSITES) {
		if (!existing.includes(`/${slug}/`)) {
			wp(['site', 'create', `--slug=${slug}`, `--title=${slug[0].toUpperCase()}${slug.slice(1)}`, '--porcelain']);
		}

		// Pages of its own all the same, each carrying its shortcode, the
		// way a site owner publishes them: on a site that is not the hub each
		// one draws a door to the hub's page instead of a form.
		const site = await Site.open(subsiteUrl(slug));
		const seeded = await site.seed();

		await site.setOptions({}, { flush: true, forgetTransients: true });
		await site.clearMail();

		expect(seeded.pages.login.url, `${slug}'s sign-in page lives under /${slug}/`).toContain(`/${slug}/e2e-login`);

		pages[slug] = seeded.pages;
	}

	writeFileSync(NETWORK_PAGES_FILE, JSON.stringify(pages, null, 2));

	// Whatever a previous run left in the log is not this run's business.
	shell('touch /var/www/html/wp-content/debug.log');
});

setup('keep a super admin session, valid on every site of the network', async ({ page, context }) => {
	const user = process.env.WP_USER ?? 'admin';
	const pass = process.env.WP_PASS ?? 'password';

	// On the main site: a subdirectory network sets its cookies on `/`, so one
	// session is a session on every site of it — which is exactly the fact
	// the two-step spec in this suite is about.
	await page.goto(`${NETWORK_URL}/wp-login.php?diluxone-users-admin=1`);
	// Typed and read back, as the single-site setup does: wp-login.php
	// focuses the username box on a timer of its own.
	await fillCredentials(page, user, pass);
	await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);

	await context.storageState({ path: NETWORK_ADMIN_STATE });
});
