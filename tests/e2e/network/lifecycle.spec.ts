import { test, expect, whoOn } from './support';
import { Site, freshEmail, linkIn, waitForMail } from '../support/api';
import { PLUGIN_DIR, debugLogLines, debugLogSince, wp } from '../support/cli';
import { askForLink, emailField } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * A site that is born after the plugin, and the plugin going off and on.
 *
 * Network activation runs the activation hook once, on the main site. Every
 * site made after that meets the plugin for the first time on some request —
 * and the first request is not always an administrator opening the
 * dashboard, where `admin_init` does the catching up. It can be a stranger on
 * the sign-in page. Each test here counts the lines of debug.log before and
 * asks for the ones added after: a notice nobody sees is still a notice.
 */

test.describe('A site made after the plugin was switched on for the network', () => {
	test('works from its public pages before anybody opens its dashboard, and logs nothing', async ({ page, hub }) => {
		const slug = `e2e-new-${Date.now().toString(36)}`;
		const before = debugLogLines();

		wp(['site', 'create', `--slug=${slug}`, `--title=Newborn`, '--porcelain']);

		try {
			const url = `${NETWORK_URL}/${slug}/`;
			const site = await Site.open(url);

			// A page with the sign-in shortcode on it, as a site owner would
			// publish one. Nothing else: the rules are the network's and the
			// account area is the main site's, and a new site has them the
			// moment it exists.
			const seeded = await site.seed();

			await site.setOptions({}, { flush: true });
			const email = freshEmail('newborn');

			await askForLink(page, seeded.pages.login.url, email);
			await page.goto(linkIn(await waitForMail(site, email)));

			expect(await whoOn(page, url)).toBe(email);

			// The account area — the main site's — with the network's fields,
			// for somebody who signed in on a site that never opened its
			// dashboard.
			await page.goto(hub.pages.account.url);
			await expect(page.locator('.diluxone-users-account__nav')).toBeVisible();
			await page.goto(`${hub.pages.account.url.replace(/\/?$/, '/')}details/`);
			await expect(page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_fields_save"]') })).toBeVisible();

			expect(debugLogSince(before), 'the newborn site wrote to debug.log').toEqual([]);
		} finally {
			wp(['site', 'delete', `--slug=${slug}`, '--yes']);
		}
	});
});

test.describe('Switching the plugin off and on', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	const row = (page: import('@playwright/test').Page) => page.locator('tr[data-plugin$="/diluxone-users.php"]');

	test('only for the whole network: a site has no Activate, a site it was left on for alone does nothing and asks for the network — with nothing in debug.log', async ({
		page,
		guest,
		alpha,
		beta,
	}) => {
		const before = debugLogLines();
		const basename = `${PLUGIN_DIR}/diluxone-users.php`;
		const alphaPlugins = wp(['option', 'get', 'active_plugins', '--format=json'], alpha.url);

		try {
			// Off for the network, from the network's own Plugins screen.
			await page.goto(`${NETWORK_URL}/wp-admin/network/plugins.php`);
			await Promise.all([page.waitForLoadState('domcontentloaded'), row(page).locator('.deactivate a').click()]);
			await expect(row(page).locator('.activate a'), 'the network’s screen offers Network Activate').toBeVisible();

			// Off, the shortcodes are text and no form is drawn anywhere.
			await guest.goto(alpha.pages.login.url);
			await expect(emailField(guest)).toHaveCount(0);

			// A site's own Plugins screen has no Activate for it: there is no
			// switching it on for one site alone.
			await page.goto(alpha.admin('plugins.php'));
			await expect(row(page).locator('.activate a'), 'no Activate on /alpha/').toHaveCount(0);

			// Left on for /alpha/ alone — an activation from before the rule,
			// written straight into /alpha/'s list: it does nothing there…
			wp(['option', 'update', 'active_plugins', JSON.stringify([...JSON.parse(alphaPlugins), basename]), '--format=json'], alpha.url);

			await guest.goto(alpha.pages.login.url);
			await expect(emailField(guest), 'no sign-in on /alpha/').toHaveCount(0);

			const screen = await page.goto(alpha.admin('admin.php?page=diluxone-users-login'));
			expect(screen?.status(), 'no screens on /alpha/').not.toBe(200);

			// …but tell whoever runs the network to activate it for all of it.
			await page.goto(alpha.admin('index.php'));
			const notice = page.locator('[data-diluxone-users-asleep]');

			await expect(notice).toHaveCount(1);
			await expect(notice.locator('a')).toHaveAttribute('href', `${NETWORK_URL}/wp-admin/network/plugins.php`);

			await page.goto(beta.admin('index.php'));
			await expect(page.locator('[data-diluxone-users-asleep]'), 'not on a site it is not on for').toHaveCount(0);

			// Put back, and on for the whole network from the network's screen.
			wp(['option', 'update', 'active_plugins', alphaPlugins, '--format=json'], alpha.url);

			await page.goto(`${NETWORK_URL}/wp-admin/network/plugins.php`);
			await Promise.all([page.waitForLoadState('domcontentloaded'), row(page).locator('.activate a').click()]);
			await expect(row(page).locator('.deactivate a')).toBeVisible();

			for (const one of [alpha, beta]) {
				await guest.goto(one.pages.login.url);
				await expect(emailField(guest), `back on ${one.slug}`).toBeVisible();
			}

			// WP-CLI, which this test uses to write /alpha/'s list, writes its
			// own deprecations to the same log; those are the tool's, not the
			// plugin's.
			const plugin = debugLogSince(before).filter((line) => !line.includes('phar:///usr/local/bin/wp'));

			expect(plugin, 'switching the plugin off and on wrote to debug.log').toEqual([]);
		} finally {
			// Whatever happened above, the rest of the suite needs the plugin
			// on for the network, and /alpha/'s own list as it was.
			wp(['option', 'update', 'active_plugins', alphaPlugins, '--format=json'], alpha.url);

			if (!wp(['plugin', 'list', '--status=active-network', '--field=name']).split('\n').includes(PLUGIN_DIR)) {
				wp(['plugin', 'activate', PLUGIN_DIR, '--network']);
			}
		}
	});
});

test.describe('WP-CLI', () => {
	test('`wp diluxone-users login` on a site of the network prints a link that opens a session there', async ({
		page,
		alpha,
	}) => {
		const email = freshEmail('net-cli');

		await alpha.site.makeUser({ email });

		const printed = wp(['diluxone-users', 'login', email], alpha.url);
		const link = printed.match(/https?:\/\/\S+/)?.[0];

		expect(link, `a link in: ${printed}`).toBeTruthy();
		expect(link).toContain(alpha.url);

		await page.goto(link!);
		expect(await whoOn(page, alpha.url)).toBe(email);
	});
});
