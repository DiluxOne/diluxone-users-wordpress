import { test, expect, whoOn } from './support';
import { Site, freshEmail, linkIn, waitForMail } from '../support/api';
import { BASELINE } from '../support/baseline';
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
	test('works from its public pages before anybody opens its dashboard, and logs nothing', async ({ page }) => {
		const slug = `e2e-new-${Date.now().toString(36)}`;
		const before = debugLogLines();

		wp(['site', 'create', `--slug=${slug}`, `--title=Newborn`, '--porcelain']);

		try {
			const url = `${NETWORK_URL}/${slug}/`;
			const site = await Site.open(url);

			// The pages a site owner would publish with the shortcodes on them,
			// and the three settings that point at them. Written through the
			// side door, not the dashboard: the dashboard is exactly what this
			// site has not seen yet.
			const seeded = await site.seed();

			await site.setOptions(
				BASELINE({
					login: seeded.pages.login.id,
					register: seeded.pages.register.id,
					account: seeded.pages.account.id,
				}),
				{ flush: true }
			);

			const email = freshEmail('newborn');

			await askForLink(page, seeded.pages.login.url, email);
			await page.goto(linkIn(await waitForMail(site, email)));

			expect(await whoOn(page, url)).toBe(email);

			// The account area, with the log table and the fields this site
			// has never had a chance to make.
			await page.goto(seeded.pages.account.url);
			await expect(page.locator('.diluxone-users-account__nav')).toBeVisible();
			await page.goto(`${seeded.pages.account.url.replace(/\/?$/, '/')}details/`);
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

	test('network-wide, then on one site only, then back — with nothing in debug.log', async ({ page, guest, alpha, beta }) => {
		const before = debugLogLines();

		try {
			// Off for the network, from the network's own Plugins screen.
			await page.goto(`${NETWORK_URL}/wp-admin/network/plugins.php`);
			await Promise.all([page.waitForLoadState('domcontentloaded'), row(page).locator('.deactivate a').click()]);
			await expect(row(page).locator('.activate a')).toBeVisible();

			// Off, the shortcodes are text and no form is drawn anywhere.
			await guest.goto(alpha.pages.login.url);
			await expect(emailField(guest)).toHaveCount(0);

			// On for /alpha/ alone, from /alpha/'s Plugins screen.
			await page.goto(alpha.admin('plugins.php'));
			await Promise.all([page.waitForLoadState('domcontentloaded'), row(page).locator('.activate a').click()]);
			await expect(row(page).locator('.deactivate a')).toBeVisible();

			await guest.goto(alpha.pages.login.url);
			await expect(emailField(guest), 'on for /alpha/').toBeVisible();
			await guest.goto(beta.pages.login.url);
			await expect(emailField(guest), 'and still off for /beta/').toHaveCount(0);

			// A dashboard screen of the plugin, on the one site that has it.
			await page.goto(alpha.admin('admin.php?page=diluxone-users-login'));
			await expect(page.locator('.wrap')).toBeVisible();

			// Off again on /alpha/, then on for the whole network.
			await page.goto(alpha.admin('plugins.php'));
			await Promise.all([page.waitForLoadState('domcontentloaded'), row(page).locator('.deactivate a').click()]);

			await page.goto(`${NETWORK_URL}/wp-admin/network/plugins.php`);
			await Promise.all([page.waitForLoadState('domcontentloaded'), row(page).locator('.activate a').click()]);
			await expect(row(page).locator('.deactivate a')).toBeVisible();

			for (const one of [alpha, beta]) {
				await guest.goto(one.pages.login.url);
				await expect(emailField(guest), `back on ${one.slug}`).toBeVisible();
			}

			expect(debugLogSince(before), 'switching the plugin off and on wrote to debug.log').toEqual([]);
		} finally {
			// Whatever happened above, the rest of the suite needs the plugin
			// on for the network.
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
