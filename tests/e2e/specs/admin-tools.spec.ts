import { readFileSync, writeFileSync } from 'node:fs';
import { Browser, Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { Site, codeIn, freshEmail, waitForMail } from '../support/api';
import { accountSection, adminUrl, signInWithPassword } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The screens an administrator works with rather than configures: Status ›
 * Tools, the two reports, WordPress's own Users screens and what the plugin
 * adds to them, the field list and the account's sections.
 *
 * Each test does what the screen offers and checks the result where it lands
 * — in the options, on an account, in the inbox, or on the public page.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Tools-1!';

/** Somebody signed in with a password in a browser of their own. */
async function signedInElsewhere(browser: Browser, baseURL: string, site: Site, loginUrl: string, prefix: string) {
	const email = freshEmail(prefix);

	await site.makeUser({ email, password: PASSWORD });

	const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
	const page = await context.newPage();

	await page.goto(loginUrl);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);

	return { email, page };
}

/** One of the tool boxes on Status › Tools, by the tool it runs. */
function tool(page: Page, name: string) {
	return page.locator('form').filter({ has: page.locator(`input[name="tool"][value="${name}"]`) });
}

/** Presses a tool's button and waits for the screen to come back with its answer. */
async function run(page: Page, form: ReturnType<typeof tool>): Promise<void> {
	await Promise.all([
		page.waitForURL(/tab=tools/, { waitUntil: 'domcontentloaded' }),
		form.locator('input[type="submit"], button[type="submit"]').first().click(),
	]);
}

test.describe('Status › Tools', () => {
	test('the settings go out as a file and come back in: a changed value is the value the site then has', async ({
		page,
		site,
		options,
	}) => {
		await page.goto(adminUrl('diluxone-users-status', 'tools'));

		const [download] = await Promise.all([page.waitForEvent('download'), tool(page, 'export').locator('input[type="submit"]').click()]);

		expect(download.suggestedFilename()).toMatch(/^diluxone-users-\d{4}-\d{2}-\d{2}\.json$/);

		const path = test.info().outputPath('settings.json');

		await download.saveAs(path);

		const exported = JSON.parse(readFileSync(path, 'utf8'));

		expect(exported.settings, 'the file carries the settings').toHaveProperty('diluxone_users_login_expiry');
		expect(JSON.stringify(exported), 'and no client secret').not.toContain('e2e-client-secret');

		await options.keep(Object.keys(exported.settings));

		exported.settings.diluxone_users_login_expiry = 42;
		writeFileSync(path, JSON.stringify(exported));

		await tool(page, 'import').locator('input[type="file"]').setInputFiles(path);
		await run(page, tool(page, 'import'));

		await expect(page.locator('.notice-success')).toBeVisible();
		expect(Number((await site.getOptions(['diluxone_users_login_expiry'])).diluxone_users_login_expiry)).toBe(42);
	});

	test('a file exported and imported unchanged leaves every setting as it was, nested ones included', async ({
		page,
		site,
		options,
	}) => {
		// Two settings that hold lists of lists: the sections of the account
		// area and the colour map.
		await options.set({
			diluxone_users_account_sections: {
				privacy: { enabled: 1, label: 'Your data, e2e', slug: 'privacy', position: 70 },
			},
		});

		const nested = ['diluxone_users_account_sections', 'diluxone_users_color_map'];
		const before = await site.getOptions(nested);

		await page.goto(adminUrl('diluxone-users-status', 'tools'));

		const [download] = await Promise.all([page.waitForEvent('download'), tool(page, 'export').locator('input[type="submit"]').click()]);
		const path = test.info().outputPath('unchanged.json');

		await download.saveAs(path);
		await options.keep(Object.keys(JSON.parse(readFileSync(path, 'utf8')).settings));

		await tool(page, 'import').locator('input[type="file"]').setInputFiles(path);
		await run(page, tool(page, 'import'));

		expect(await site.getOptions(nested), 'a round trip through the file changed the settings').toEqual(before);
	});

	test('“close their sessions” signs one person out everywhere and nobody else', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const one = await signedInElsewhere(browser, baseURL!, site, pages.login.url, 'close-one');
		const other = await signedInElsewhere(browser, baseURL!, site, pages.login.url, 'close-other');

		await page.goto(adminUrl('diluxone-users-status', 'tools'));

		const form = tool(page, 'close');

		await form.locator('input[name="scope"][value="one"]').check();
		await form.locator('input[name="close_email"]').fill(one.email);
		await run(page, form);

		expect((await site.user(one.email)).sessions).toBe(0);
		expect((await site.user(other.email)).sessions).toBe(1);
	});

	test('“send someone a fresh code” reaches their inbox with six digits', async ({ page, site }) => {
		const email = freshEmail('fresh-code');

		await site.makeUser({ email, password: PASSWORD });

		await page.goto(adminUrl('diluxone-users-status', 'tools'));

		const form = tool(page, 'code');

		await form.locator('input[name="email"]').fill(email);
		await run(page, form);

		expect(codeIn(await waitForMail(site, email))).toMatch(/^\d{6}$/);
	});

	test('“rebuild the addresses” says it did, and the account sections still answer', async ({ page, pages }) => {
		await page.goto(adminUrl('diluxone-users-status', 'tools'));
		await run(page, tool(page, 'flush'));
		await expect(page.locator('.notice-success')).toBeVisible();

		const response = await page.request.get(accountSection(pages.account.url, 'security'));

		expect(response.status()).toBe(200);
	});

	test('the test message goes to the administrator’s own address', async ({ page, site }) => {
		await site.clearMail();
		await page.goto(adminUrl('diluxone-users-status', 'tools'));

		const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_mail_test"]') });

		await run(page, form);
		await expect(page.locator('.notice-success')).toBeVisible();

		const all = await site.mail('');

		expect(all.length, 'one message went out').toBe(1);
		expect(page.locator('.notice-success')).toContainText(all[0].to);
	});
});

test.describe('Reports', () => {
	test('Sessions: somebody signed in is on the list, and closing theirs signs them out', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const person = await signedInElsewhere(browser, baseURL!, site, pages.login.url, 'report-session');

		await page.goto(adminUrl('diluxone-users-reports', 'sessions', { s: person.email }));

		const row = page.locator('tr').filter({ has: page.locator('.diluxone-users-list__mail', { hasText: person.email }) });

		await expect(row).toHaveCount(1);
		await expect(row.locator('.diluxone-users-pill--on')).toBeVisible();

		await Promise.all([page.waitForLoadState('domcontentloaded'), row.locator('button[type="submit"], input[type="submit"]').first().click()]);

		expect((await site.user(person.email)).sessions).toBe(0);
	});

	/** A refused password and then the right one, for somebody new, in the guest browser. */
	async function refusedThenIn(guest: Page, site: Site, loginUrl: string): Promise<string> {
		const email = freshEmail('activity');

		await site.makeUser({ email, password: PASSWORD });

		await guest.goto(loginUrl);
		await signInWithPassword(guest, email, 'not-the-password');
		await guest.waitForLoadState('domcontentloaded');
		await guest.goto(loginUrl);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);

		return email;
	}

	test('Activity: the event filter tells a refused password from a sign-in', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_log_levels: ['access', 'account', 'security'] });

		const email = await refusedThenIn(guest, site, pages.login.url);

		// Refused: newest first, with what was typed on it.
		await page.goto(adminUrl('diluxone-users-reports', 'activity', { event: 'sign_in_failed' }));
		await expect(page.locator('tr[data-diluxone-users-event]:not([data-diluxone-users-event="sign_in_failed"])')).toHaveCount(0);
		await expect(page.locator('tr[data-diluxone-users-event="sign_in_failed"]').first()).toContainText(email);

		// Signed in: found by the person.
		await page.goto(adminUrl('diluxone-users-reports', 'activity', { s: email, event: 'signed_in' }));
		await expect(page.locator('tr[data-diluxone-users-event="signed_in"]')).toHaveCount(1);
	});

	test('Activity: searching for an address finds the refused attempts on it too', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_log_levels: ['access', 'account', 'security'] });

		const email = await refusedThenIn(guest, site, pages.login.url);

		// "Is somebody guessing at this account?" is asked by typing the
		// account's address into the search. The refused rows carry that
		// address as what was typed, filed under no account on purpose.
		await page.goto(adminUrl('diluxone-users-reports', 'activity', { s: email, event: 'sign_in_failed' }));
		await expect(
			page.locator('tr[data-diluxone-users-event="sign_in_failed"]'),
			'a search by the address does not find the attempts made on it'
		).not.toHaveCount(0);
	});
});

test.describe('WordPress’s own Users screens', () => {
	test('the list gains the Access column', async ({ page }) => {
		await page.goto('/wp-admin/users.php');
		await expect(page.locator('th#diluxone_users, th.column-diluxone_users').first()).toBeVisible();
	});

	test('on somebody’s profile: forgetting their authenticator app and unlinking a network', async ({ page, site }) => {
		const email = freshEmail('people');
		const person = await site.makeUser({
			email,
			password: PASSWORD,
			meta: {
				diluxone_users_totp: 'JBSWY3DPEHPK3PXP',
				diluxone_users_2fa_on: 1,
				diluxone_users_sso_github: 'github|e2e',
			},
		});

		await page.goto(`/wp-admin/user-edit.php?user_id=${person.id}`);

		await page.locator('input[name="diluxone_users_forget_totp"]').check();
		await page.locator('input[name="diluxone_users_unlink[]"][value="github"]').check();
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#submit').click()]);

		const after = await site.user(email, ['diluxone_users_sso_github']);

		expect(after.meta.diluxone_users_totp, 'the app is forgotten').toBe('');
		expect(after.fields.diluxone_users_sso_github, 'the network is unlinked').toBe('');
	});

	test('Add New User: the e-mail is the username', async ({ page, site }) => {
		const email = freshEmail('new-user');

		await page.goto('/wp-admin/user-new.php');

		const form = page.locator('form#createuser');

		await expect(form.locator('#user_login').locator('xpath=ancestor::tr[1]'), 'the username row is hidden').toBeHidden();
		await form.locator('input[name="email"]').fill(email);
		await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('#createusersub').click()]);

		const made = await site.user(email);

		expect(made.exists).toBe(true);
		expect(made.login).toBe(email);
	});
});

test.describe('User fields', () => {
	test('a field added on the list is on the account’s details and on the registration form; deleted, it is gone', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_fields']);
		await options.set({
			diluxone_users_register_form: 1,
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const label = `Favourite colour ${Date.now().toString(36)}`;

		await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');
		await page.locator('[name="diluxone_users_field[label]"]').fill(label);
		await page.locator('[name="diluxone_users_field[type]"]').selectOption('text');
		// Required, because the registration form asks only for what is
		// required: everything else is asked for later, on the account.
		await page.locator('input[type="checkbox"][name="diluxone_users_field[required]"]').setChecked(true, { force: true });
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#submit').click()]);

		const fields = (await site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; label: string }>;
		const added = fields.find((one) => one.label === label);

		expect(added, 'the field is on the list').toBeTruthy();

		await guest.goto(pages.register.url);
		await expect(guest.locator(`[name="${added!.key}"]`)).toHaveCount(1);

		const email = freshEmail('fields');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto(pages.login.url);
		await guest.context().clearCookies();
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);
		await guest.goto(accountSection(pages.account.url, 'details'));
		await expect(guest.locator(`[name="${added!.key}"]`)).toHaveCount(1);

		// Deleted from the list. The link lives in the row actions WordPress
		// only shows on hover, behind a "are you sure"; following its address
		// is the same request the click makes once both are out of the way.
		await page.goto(adminUrl('diluxone-users-fields'));

		const remove = page.locator(`a[href*="diluxone_users_action=delete"][href*="field=${added!.key}"]`);

		await page.goto((await remove.getAttribute('href'))!.trim());

		await guest.goto(accountSection(pages.account.url, 'details'));
		await expect(guest.locator(`[name="${added!.key}"]`)).toHaveCount(0);
	});
});

test.describe('Account area › Sections', () => {
	test('a section of the site’s own: added, it is in the menu with its words; deleted, it is gone', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_account_sections']);

		const label = `Courses ${Date.now().toString(36)}`;
		const intro = `Everything you are enrolled in, ${label}.`;

		await page.goto(adminUrl('diluxone-users-account', 'sections') + '&section=diluxone-users-new');
		await page.locator('[name="diluxone_users_section_form[label]"]').fill(label);
		await page.locator('[name="diluxone_users_section_form[intro]"]').fill(intro);
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#submit').click()]);

		const email = freshEmail('sections-own');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);

		await guest.goto(pages.account.url);

		const tab = guest.locator('a.diluxone-users-account__tab', { hasText: label });

		await expect(tab).toHaveCount(1);
		await Promise.all([guest.waitForLoadState('domcontentloaded'), tab.click()]);
		await expect(guest.locator('.diluxone-users-account')).toContainText(intro);

		const sections = (await site.getOptions(['diluxone_users_account_sections'])).diluxone_users_account_sections as Record<
			string,
			{ label?: string }
		>;
		const id = Object.entries(sections).find(([, one]) => one.label === label)?.[0];

		expect(id, 'the section was saved').toBeTruthy();

		await page.goto(adminUrl('diluxone-users-account', 'sections') + `&section=${id}`);

		const remove = page.locator(`a[href*="diluxone_users_action=delete"][href*="section=${id}"]`);

		page.on('dialog', (dialog) => dialog.accept());
		await Promise.all([page.waitForLoadState('domcontentloaded'), remove.first().click()]);

		await guest.goto(pages.account.url);
		await expect(guest.locator('a.diluxone-users-account__tab', { hasText: label })).toHaveCount(0);
	});

	test('what people can do with their data: the switches decide whether “Your data” exists', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_privacy_export', 'diluxone_users_privacy_delete']);
		await options.set({ diluxone_users_privacy_export: 0, diluxone_users_privacy_delete: 0 });

		const email = freshEmail('sections-privacy');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);

		await page.goto(adminUrl('diluxone-users-account', 'sections') + '&section=privacy');
		await page.locator('input[name="diluxone_users_privacy_export"]').setChecked(true, { force: true });
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('[name="diluxone_users_privacy_submit"]').click()]);

		await guest.goto(pages.account.url);
		await expect(guest.locator('a.diluxone-users-account__tab[href*="/privacy/"]')).toBeVisible();

	});
});
