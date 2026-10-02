import { readFileSync, writeFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';
import { test, expect, signInFrom, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { navigated } from '../support/ui';
import { Ops, siteScreen } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * Status › Tools and the lockout tab on the sites of a network.
 *
 * A site's settings file is that site's: exported, it carries only what the
 * site decides; restored, it writes only that, whatever the file says about
 * the network's settings. Somebody's sessions are the network's: a super
 * admin closes one person's from the hub's Tools, or everybody's on the whole
 * network. And the emergency door is each site's own.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Net-Tools-1!';
const VERSION = readFileSync('diluxone-users.php', 'utf8').match(/define\(\s*'DILUXONE_USERS_VERSION',\s*'([^']+)'/)![1];

/** What a site that is not the hub decides for itself (options-scope.php's `site`). */
const SITE_KEYS = [
	'diluxone_users_admin_bar',
	'diluxone_users_admin_bar_keep_admins',
	'diluxone_users_admin_bar_roles',
	'diluxone_users_admin_bar_scope',
	'diluxone_users_bar_account',
	'diluxone_users_menu_location',
	'diluxone_users_menu_style',
	'diluxone_users_wp_profile',
	'diluxone_users_wp_profile_roles',
	'diluxone_users_wp_profile_scope',
	'diluxone_users_mail_last',
	'diluxone_users_rewrite_version',
];

const tools = (one: SiteHandle) => siteScreen(one.url, 'diluxone-users-status', 'tools');

function tool(page: Page, name: string): Locator {
	return page.locator('form').filter({ has: page.locator(`input[name="tool"][value="${name}"]`) });
}

async function run(page: Page, form: Locator): Promise<void> {
	await navigated(page, () => form.locator('input[type="submit"], button[type="submit"]').first().click());
}

test.describe('Status › Tools on a site of the network', () => {
	test('Rebuild on /alpha/ rebuilds /alpha/’s addresses and says so', async ({ page, alpha }) => {
		await page.goto(tools(alpha));
		await run(page, tool(page, 'flush'));

		await expect(page.locator('.notice-success')).toHaveCount(1);
		expect(new URL(page.url()).pathname.startsWith(new URL(alpha.url).pathname)).toBe(true);
		expect((await alpha.site.getOptions(['diluxone_users_rewrite_version'])).diluxone_users_rewrite_version).toBe(VERSION);
	});

	test('/alpha/’s file carries only what /alpha/ decides, and restoring one writes only that', async ({ page, alpha, beta }) => {
		await alpha.keep(SITE_KEYS);
		await alpha.set({ diluxone_users_admin_bar: 'wp', diluxone_users_wp_profile: 'allow' });

		await page.goto(tools(alpha));

		const [download] = await Promise.all([page.waitForEvent('download'), tool(page, 'export').locator('input[type="submit"]').click()]);
		const path = test.info().outputPath('alpha.json');

		await download.saveAs(path);

		const file = JSON.parse(readFileSync(path, 'utf8'));

		expect(file.site).toBe(alpha.url.replace(/\/$/, ''));

		for (const key of Object.keys(file.settings)) {
			expect(SITE_KEYS, `${key} is not /alpha/’s to carry`).toContain(key);
		}

		// A file that says the network's things too: only /alpha/'s are written.
		const network = await beta.site.getOptions(['diluxone_users_2fa_mode', 'diluxone_users_login_method', 'diluxone_users_session_long_days']);

		file.settings = {
			...file.settings,
			diluxone_users_admin_bar: 'hide',
			diluxone_users_2fa_mode: network.diluxone_users_2fa_mode === 'off' ? 'required' : 'off',
			diluxone_users_login_method: network.diluxone_users_login_method === 'link' ? 'password' : 'link',
			diluxone_users_session_long_days: 99,
		};
		writeFileSync(path, JSON.stringify(file));

		await tool(page, 'import').locator('input[type="file"]').setInputFiles(path);
		await run(page, tool(page, 'import'));
		await expect(page.locator('.notice-success')).toHaveCount(1);

		expect((await alpha.site.getOptions(['diluxone_users_admin_bar'])).diluxone_users_admin_bar, 'the site’s own setting').toBe('hide');
		expect((await beta.site.getOptions(['diluxone_users_admin_bar'])).diluxone_users_admin_bar, 'and only this site’s').not.toBe('hide');
		expect(await beta.site.getOptions(['diluxone_users_2fa_mode', 'diluxone_users_login_method', 'diluxone_users_session_long_days']), 'the network’s untouched').toEqual(network);
	});

	test('a super admin on the hub’s Tools closes the sessions of somebody signed in from /beta/', async ({ page, browser, hub, beta }) => {
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });

		const email = freshEmail('net-close-one');

		await beta.site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });

		try {
			await signInFrom(await context.newPage(), beta, hub, email, PASSWORD);
			expect((await beta.site.user(email)).sessions).toBeGreaterThan(0);

			await page.goto(tools(hub));
			await tool(page, 'close').locator('input[name="scope"][value="one"]').check();
			await tool(page, 'close').locator('input[name="close_email"]').fill(email);
			await run(page, tool(page, 'close'));

			await expect(page.locator('.notice-success')).toHaveCount(1);
			expect((await beta.site.user(email)).sessions).toBe(0);
		} finally {
			await context.close();
		}
	});

	test('“everyone on the network” closes the sessions opened from every site — every session kept before is put back', async ({
		page,
		browser,
		request,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });

		const ops = new Ops(request, hub.url);
		// WordPress clears the cookies of the browser it sends back to sign
		// in; the session itself is what is kept and put back.
		const cookies = await page.context().cookies();

		await ops.keepSessions();

		try {
			const people: string[] = [];

			for (const one of [alpha, beta]) {
				const email = freshEmail(`net-close-all-${one.slug}`);
				const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });

				await one.site.makeUser({ email, password: PASSWORD });
				await signInFrom(await context.newPage(), one, hub, email, PASSWORD);
				await context.close();
				people.push(email);
			}

			await page.goto(tools(hub));
			await tool(page, 'close').locator('input[name="scope"][value="all"]').check();
			await run(page, tool(page, 'close'));

			// The one who pressed it is signed out too.
			expect(new URL(page.url()).pathname).toMatch(/wp-login\.php$/);

			for (const email of people) {
				expect((await hub.site.user(email)).sessions, email).toBe(0);
			}
		} finally {
			await ops.restoreSessions();
		}

		await page.context().addCookies(cookies);
		await page.goto(tools(hub));
		expect(new URL(page.url()).pathname).toMatch(/\/wp-admin\/admin\.php$/);
	});
});

test.describe('Status on a site of the network', () => {
	test('/alpha/’s emergency door is /alpha/’s own wp-login.php, and it draws the password form', async ({ page, guest, alpha }) => {
		await page.goto(siteScreen(alpha.url, 'diluxone-users-status', 'lockout'));

		const door = (await page.locator('.wrap p > code').nth(1).innerText()).trim();

		expect(door).toBe(`${alpha.url}wp-login.php?diluxone-users-admin=1`);

		await guest.goto(door);
		await expect(guest.locator('form#loginform input[name="pwd"]')).toBeVisible();
	});

	test('the usage counts are /alpha/’s members: somebody of /beta/ only is not counted, a member of /alpha/ is', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_membership: 'invite' });

		const accounts = async () => {
			await page.goto(siteScreen(alpha.url, 'diluxone-users-status', 'status'));

			return Number((await page.locator('table.diluxone-users-summary').nth(1).locator('tbody > tr').first().locator('td').innerText()).replace(/\D/g, ''));
		};

		const before = await accounts();

		await beta.site.makeUser({ email: freshEmail('net-usage-beta') });
		expect(await accounts(), 'a member of /beta/ only').toBe(before);

		await alpha.site.makeUser({ email: freshEmail('net-usage-alpha') });
		expect(await accounts(), 'a member of /alpha/').toBe(before + 1);
	});
});
