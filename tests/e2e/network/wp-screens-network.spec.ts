import { randomBytes } from 'node:crypto';
import type { Page } from '@playwright/test';
import { test, expect, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { navigated, savePanel } from '../support/ui';
import { networkAdmin, siteScreen } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * WordPress's own screens on a network, as the plugin changes them.
 *
 * A site's Users list carries the Access column; somebody's profile carries
 * the plugin's block on a site and in Network Admin, where a super admin can
 * take a way in off the account — it is the network's account, so it is gone
 * from every site; and the toolbar is each site's own decision, its "Edit
 * profile" leading to the hub's account area.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Net-Screens-1!';
const SECRET = 'JBSWY3DPEHPK3PXP';

function passkey(label: string) {
	return { id: `e2e-${randomBytes(6).toString('hex')}`, label, created: Math.floor(Date.now() / 1000), rp: '' };
}

function accessCell(page: Page, email: string) {
	return page.locator('table.users tbody tr').filter({ hasText: email }).locator('td.column-diluxone_users');
}

test.describe('Users on a site of the network', () => {
	test('/alpha/’s list has the Access column with each member’s pills', async ({ page, alpha }) => {
		const tag = `nac${Date.now().toString(36)}`;
		const plain = freshEmail(`${tag}-p`);
		const keys = freshEmail(`${tag}-k`);

		await alpha.site.makeUser({ email: plain });
		await alpha.site.makeUser({ email: keys, meta: { diluxone_users_2fa_on: 1, diluxone_users_totp: SECRET, diluxone_users_passkeys: [passkey('one')] } });

		await page.goto(`${alpha.url}wp-admin/users.php?s=${encodeURIComponent(tag)}`);

		await expect(page.locator('th#diluxone_users, th.column-diluxone_users').first()).toBeVisible();
		await expect(accessCell(page, plain).locator('.diluxone-users-pill--off')).toHaveCount(1);
		await expect(accessCell(page, keys).locator('.diluxone-users-pill--on')).toHaveCount(3);
	});

	test('on /alpha/, somebody’s profile carries the block with what the network’s account has', async ({ page, alpha }) => {
		const email = freshEmail('nprofile');
		const person = await alpha.site.makeUser({ email, meta: { diluxone_users_2fa_on: 1, diluxone_users_totp: SECRET, diluxone_users_sso_github: 'github|e2e-net' } });

		await page.goto(`${alpha.url}wp-admin/user-edit.php?user_id=${person.id}`);

		const states = await page
			.locator('table.diluxone-users-summary tbody > tr')
			.evaluateAll((all: Element[]) => all.map((row) => (row.querySelector('.diluxone-users-state')?.className.match(/--([a-z]+)/) ?? [])[1]));

		// Second step, passkeys, networks.
		expect(states.slice(2)).toEqual(['active', 'off', 'active']);

		// Its "every session" goes to /alpha/'s own report.
		await expect(page.locator('table.diluxone-users-summary a[href*="tab=sessions"]').first()).toHaveAttribute('href', new RegExp(`^${alpha.url}wp-admin/`));
	});
});

test.describe('Network Admin › Users › somebody’s profile', () => {
	test('the app, a passkey and a network taken off there are gone from the account on every site', async ({ page, alpha, beta }) => {
		const email = freshEmail('nnetedit');
		const gone = passkey('Lost');
		const person = await alpha.site.makeUser({
			email,
			meta: { diluxone_users_2fa_on: 1, diluxone_users_totp: SECRET, diluxone_users_passkeys: [gone], diluxone_users_sso_github: 'github|e2e-netedit' },
		});

		await page.goto(networkAdmin(NETWORK_URL, `user-edit.php?user_id=${person.id}`));
		await expect(page.locator('table.diluxone-users-summary')).toHaveCount(1);

		await page.locator('input[name="diluxone_users_forget_totp"]').check();
		await page.locator(`input[name="diluxone_users_forget_passkey[]"][value="${gone.id}"]`).check();
		await page.locator('input[name="diluxone_users_unlink[]"][value="github"]').check();
		await navigated(page, () => page.locator('#submit').click());

		for (const one of [alpha, beta]) {
			const after = await one.site.user(email, ['diluxone_users_sso_github']);

			expect(after.meta.diluxone_users_totp, `/${one.slug}/: the app`).toBe('');
			expect(after.fields.diluxone_users_sso_github, `/${one.slug}/: the network`).toBe('');
		}

		// Nothing left to take off: the passkey is no longer on the account.
		await page.goto(networkAdmin(NETWORK_URL, `user-edit.php?user_id=${person.id}`));
		await expect(page.locator('input[name="diluxone_users_forget_totp"], input[name="diluxone_users_forget_passkey[]"], input[name="diluxone_users_unlink[]"]')).toHaveCount(0);

		// And /alpha/'s list, where they are a member, keeps the second step
		// they turned on and nothing else: no app, no passkey, no network.
		await page.goto(`${alpha.url}wp-admin/users.php?s=${encodeURIComponent(email)}`);
		await expect(accessCell(page, email).locator('.diluxone-users-pill--on')).toHaveCount(1);
		await expect(accessCell(page, email).locator('.diluxone-users-pill--blank')).toHaveCount(0);
	});
});

test.describe('The toolbar is each site’s', () => {
	const KEYS = ['diluxone_users_admin_bar', 'diluxone_users_admin_bar_scope', 'diluxone_users_admin_bar_roles', 'diluxone_users_admin_bar_keep_admins', 'diluxone_users_bar_account'];

	test('hidden on /alpha/ for everybody: gone on /alpha/ and still there on /beta/ for the same member', async ({ page, browser, hub, alpha, beta }) => {
		await alpha.keep(KEYS);
		await alpha.set({ diluxone_users_admin_bar: 'wp' });
		await beta.set({ diluxone_users_admin_bar: 'wp' });
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });

		await page.goto(siteScreen(alpha.url, 'diluxone-users-account', 'dashboard'));
		await page.locator('[name="diluxone_users_admin_bar"][value="hide-all"]').check({ force: true });
		await savePanel(page);
		expect((await alpha.site.getOptions(['diluxone_users_admin_bar'])).diluxone_users_admin_bar).toBe('hide');
		expect((await beta.site.getOptions(['diluxone_users_admin_bar'])).diluxone_users_admin_bar).toBe('wp');

		const email = freshEmail('nbar');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await beta.site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();

		try {
			await signInFrom(person, alpha, hub, email, PASSWORD);
			await person.goto(alpha.url);
			await expect(person.locator('#wpadminbar'), 'on /alpha/').toHaveCount(0);
			await person.goto(beta.url);
			await expect(person.locator('#wpadminbar'), 'on /beta/').toHaveCount(1);
		} finally {
			await context.close();
		}
	});

	test('its user menu sent to the account area on /beta/ leads to the hub’s account', async ({ page, browser, hub, beta }) => {
		await beta.keep(KEYS);
		await beta.set({ diluxone_users_admin_bar: 'wp', diluxone_users_bar_account: 0 });
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });

		await page.goto(siteScreen(beta.url, 'diluxone-users-account', 'dashboard'));
		await page.locator('input[name="diluxone_users_bar_account"]').check({ force: true });
		await savePanel(page);

		const email = freshEmail('nbaracc');

		await beta.site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();

		try {
			await signInFrom(person, beta, hub, email, PASSWORD);
			await person.goto(beta.url);

			const href = await person.locator('#wp-admin-bar-user-info a').first().getAttribute('href');

			expect(new URL(href!).href.replace(/\/$/, '')).toBe(hub.pages.account.url.replace(/\/$/, ''));
		} finally {
			await context.close();
		}
	});
});
