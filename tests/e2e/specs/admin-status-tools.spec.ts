import { readFileSync, writeFileSync } from 'node:fs';
import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, navigated } from '../support/ui';
import { Ops, personSignedIn } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Status › Tools, past the plain path `admin-tools.spec.ts` walks.
 *
 * Closing every session on the site (the administrator who pressed it
 * included), the file that comes in and what it is allowed to write, the
 * answers to an address nobody has, the switch for what deleting the plugin
 * takes with it, a tool nobody has, a code that cannot be sent, and the
 * result notice shown once. Each is pressed on the screen and checked in the
 * accounts, the settings, the mailbox and the notice.
 */

test.use({ storageState: ADMIN_STATE });

const TOOLS = adminUrl('diluxone-users-status', 'tools');
const VERSION = readFileSync('diluxone-users.php', 'utf8').match(/define\(\s*'DILUXONE_USERS_VERSION',\s*'([^']+)'/)![1];

/** One of the tool boxes, by the tool it runs. */
function tool(page: Page, name: string): Locator {
	return page.locator('form').filter({ has: page.locator(`input[name="tool"][value="${name}"]`) });
}

/** Presses a tool's button and waits for Tools to come back with the answer. */
async function run(page: Page, form: Locator): Promise<void> {
	await navigated(page, () => form.locator('input[type="submit"], button[type="submit"]').first().click());
}

/** Restores a file through the import box. */
async function restore(page: Page, path: string): Promise<void> {
	await page.goto(TOOLS);
	await tool(page, 'import').locator('input[type="file"]').setInputFiles(path);
	await run(page, tool(page, 'import'));
}

test.describe('Status › Tools', () => {
	test('“close every session” signs everybody out, the administrator who pressed it included', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		request,
	}) => {
		const ops = new Ops(request);

		// Every session the site has now — the one the other specs share —
		// is put back at the end: only the ones opened here stay closed.
		await ops.keepSessions();

		try {
			const one = await personSignedIn(browser, baseURL!, site, pages.login.url, 'close-all-one');
			const two = await personSignedIn(browser, baseURL!, site, pages.login.url, 'close-all-two');
			const admin = await personSignedIn(browser, baseURL!, site, pages.login.url, 'close-all-admin', { role: 'administrator' });

			await admin.page.goto(TOOLS);

			const form = tool(admin.page, 'close');

			await form.locator('input[name="scope"][value="all"]').check();
			await run(admin.page, form);

			// The screen it would have come back to now asks who you are.
			expect(new URL(admin.page.url()).pathname).toMatch(/wp-login\.php$/);

			for (const who of [one, two, admin]) {
				expect((await site.user(who.email)).sessions, `${who.email} is signed out`).toBe(0);
				await who.context.close();
			}
		} finally {
			await ops.restoreSessions();
		}

		// And the shared administrator is back, as the next spec expects.
		await page.goto(TOOLS);
		expect(new URL(page.url()).pathname).toBe('/wp-admin/admin.php');
	});

	test('an address nobody has: “no account” for a fresh code and for closing one person’s sessions, and no mail', async ({
		page,
		site,
	}) => {
		const nobody = freshEmail('tools-nobody');

		await site.clearMail();

		await page.goto(TOOLS);
		await tool(page, 'code').locator('input[name="email"]').fill(nobody);
		await run(page, tool(page, 'code'));
		await expect(page.locator('.notice-error')).toHaveCount(1);
		await expect(page.locator('.notice-success')).toHaveCount(0);

		await tool(page, 'close').locator('input[name="scope"][value="one"]').check();
		await tool(page, 'close').locator('input[name="close_email"]').fill(nobody);
		await run(page, tool(page, 'close'));
		await expect(page.locator('.notice-error')).toHaveCount(1);

		expect(await site.mail(nobody)).toEqual([]);
	});

	test('a fresh code that cannot be sent says so instead of saying it went', async ({ page, site, options }) => {
		await options.set({ diluxone_e2e_mail_outcome: 'fail' });

		const email = freshEmail('tools-code-fails');

		await site.makeUser({ email });
		await page.goto(TOOLS);
		await tool(page, 'code').locator('input[name="email"]').fill(email);
		await run(page, tool(page, 'code'));

		await expect(page.locator('.notice-error')).toHaveCount(1);
		await expect(page.locator('.notice-success')).toHaveCount(0);
	});

	test('the result is said once: the screen loaded again carries no notice', async ({ page }) => {
		await page.goto(TOOLS);
		await run(page, tool(page, 'flush'));
		await expect(page.locator('.notice-success')).toHaveCount(1);

		await page.reload();
		await expect(page.locator('.notice-success')).toHaveCount(0);
		await expect(page.locator('.notice-error')).toHaveCount(0);
	});

	test('a tool nobody has is “nothing to do”', async ({ page }) => {
		await page.goto(TOOLS);

		// The rebuild box, with its nonce, asking for a tool that does not exist.
		await tool(page, 'flush').locator('input[name="tool"]').evaluate((input: HTMLInputElement) => {
			input.value = 'bogus';
		});
		await navigated(page, () => page.locator('form').filter({ has: page.locator('input[name="tool"][value="bogus"]') }).locator('input[type="submit"]').click());

		await expect(page.locator('.notice-error')).toHaveCount(1);
	});

	test('what deleting the plugin takes with it: the box saves on and off, and says which', async ({ page, site, options }) => {
		await options.keep(['diluxone_users_uninstall_wipe']);
		await options.set({ diluxone_users_uninstall_wipe: 0 });

		const box = (p: Page) => tool(p, 'wipe').locator('input[name="wipe"]');

		await page.goto(TOOLS);
		await box(page).check();
		await run(page, tool(page, 'wipe'));
		await expect(page.locator('.notice-success')).toHaveCount(1);
		expect(Number((await site.getOptions(['diluxone_users_uninstall_wipe'])).diluxone_users_uninstall_wipe)).toBe(1);

		await page.reload();
		await expect(box(page)).toBeChecked();

		await box(page).uncheck();
		await run(page, tool(page, 'wipe'));
		await expect(page.locator('.notice-success')).toHaveCount(1);
		expect(Number((await site.getOptions(['diluxone_users_uninstall_wipe'])).diluxone_users_uninstall_wipe)).toBe(0);
		await expect(box(page)).not.toBeChecked();
	});

	test('the exported file is this version’s, carries the site’s own settings and fields, and no credential or uninstall switch', async ({
		page,
		options,
	}) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-export-secret', tested: 1 } },
			diluxone_users_uninstall_wipe: 0,
			diluxone_users_login_expiry: 17,
		});

		await page.goto(TOOLS);

		const [download] = await Promise.all([page.waitForEvent('download'), tool(page, 'export').locator('input[type="submit"]').click()]);
		const path = test.info().outputPath('export.json');

		await download.saveAs(path);

		const text = readFileSync(path, 'utf8');
		const file = JSON.parse(text);

		expect(file.plugin).toBe('diluxone-users');
		expect(file.version).toBe(VERSION);
		expect(Number(file.settings.diluxone_users_login_expiry)).toBe(17);
		expect(Array.isArray(file.settings.diluxone_users_fields)).toBe(true);
		expect(file.settings).not.toHaveProperty('diluxone_users_sso');
		expect(file.settings).not.toHaveProperty('diluxone_users_uninstall_wipe');
		expect(text).not.toContain('e2e-export-secret');
	});

	test('a file that is not an export, one too big, and none at all are refused, and nothing is written', async ({ page, site }) => {
		const before = await site.getOptions(['diluxone_users_login_expiry']);

		// Not an export: JSON, but no settings in it.
		const empty = test.info().outputPath('not-an-export.json');

		writeFileSync(empty, JSON.stringify({ hello: 'world' }));
		await restore(page, empty);
		await expect(page.locator('.notice-error')).toHaveCount(1);

		// Over a megabyte: refused before it is read.
		const big = test.info().outputPath('too-big.json');

		writeFileSync(big, JSON.stringify({ settings: { diluxone_users_login_expiry: 5 }, padding: 'x'.repeat(1_100_000) }));
		await restore(page, big);
		await expect(page.locator('.notice-error')).toHaveCount(1);

		// No file: the box is `required` in the browser; sent anyway, the server says so.
		await page.goto(TOOLS);
		await navigated(page, () =>
			tool(page, 'import').evaluate((form: HTMLFormElement) => {
				HTMLFormElement.prototype.submit.call(form);
			})
		);
		await expect(page.locator('.notice-error')).toHaveCount(1);

		expect(await site.getOptions(['diluxone_users_login_expiry'])).toEqual(before);
	});

	test('a file that carries more than this plugin’s settings writes only this plugin’s, and says how many', async ({
		page,
		site,
		options,
	}) => {
		await options.keep(['diluxone_users_login_expiry', 'diluxone_users_fields', 'diluxone_users_sso', 'diluxone_users_uninstall_wipe']);
		await options.set({
			diluxone_users_login_expiry: 15,
			diluxone_users_uninstall_wipe: 0,
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'kept-id', secret: 'kept-secret', tested: 1 } },
		});

		const register = (await site.getOptions(['users_can_register'])).users_can_register;
		const path = test.info().outputPath('foreign.json');

		writeFileSync(
			path,
			JSON.stringify({
				plugin: 'diluxone-users',
				settings: {
					// Not this plugin's at all.
					siteurl: 'https://elsewhere.example',
					users_can_register: Number(register) ? 0 : 1,
					diluxone_users_not_a_setting: 'x',
					// This plugin's, but never carried by a file.
					diluxone_users_sso: { mock: { active: 1, id: 'stolen-id', secret: 'stolen-secret', tested: 1 } },
					diluxone_users_uninstall_wipe: 1,
					// This plugin's, and carried: two writes.
					diluxone_users_login_expiry: 33,
					diluxone_users_fields: [
						{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
						{ key: 'diluxone_users_e2e_imported', label: 'Imported', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
						{ key: 'billing_phone', label: 'Another plugin’s key', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
					],
				},
			})
		);

		await restore(page, path);
		await expect(page.locator('.notice-success')).toHaveCount(1);
		await expect(page.locator('.notice-success')).toContainText('2');

		const after = await site.getOptions(['diluxone_users_login_expiry', 'diluxone_users_sso', 'diluxone_users_uninstall_wipe', 'diluxone_users_fields', 'users_can_register', 'diluxone_users_not_a_setting']);
		const keys = (after.diluxone_users_fields as Array<{ key: string }>).map((one) => one.key);

		expect(Number(after.diluxone_users_login_expiry)).toBe(33);
		expect(keys).toContain('diluxone_users_e2e_imported');
		expect(keys, 'a key another plugin writes is not a field').not.toContain('billing_phone');
		expect((after.diluxone_users_sso as Record<string, { id: string }>).mock.id, 'credentials are not imported').toBe('kept-id');
		expect(Number(after.diluxone_users_uninstall_wipe), 'nor the uninstall switch').toBe(0);
		expect(after.diluxone_users_not_a_setting, 'an unknown key is not written').toBeNull();
		expect(after.users_can_register, 'nor one of WordPress’s').toEqual(register);

		// And the site's own address is the site's own.
		await page.goto('/wp-admin/options-general.php');
		await expect(page.locator('input#siteurl')).not.toHaveValue('https://elsewhere.example');
	});
});
