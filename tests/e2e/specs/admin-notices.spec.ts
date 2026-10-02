import { Browser, Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { Mail, Site, codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, adminError, adminSaved, adminUrl, askForLink, challengeCode, challengeScreen, navigated, saveButton, savePanel, signInWithPassword } from '../support/ui';
import { MEMBER_PASSWORD, railNotice } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * E-mail notices: the rules that decide whether a notice goes out, and the
 * words each e-mail goes out in — saved on the screen, and read back from the
 * mailbox the e2e mu-plugin keeps.
 */

test.use({ storageState: ADMIN_STATE });

const LOGIN_RULE = 'select[name="diluxone_users_notice_rules[diluxone_users_notify_login]"]';
const SECURITY_RULE = 'select[name="diluxone_users_notice_rules[diluxone_users_notify_security]"]';

/** A browser that is another device, and somebody signing in on it with a password. */
async function signInOn(browser: Browser, baseURL: string, loginUrl: string, email: string, agent: string): Promise<Page> {
	const context = await browser.newContext({ baseURL, userAgent: agent, storageState: { cookies: [], origins: [] } });
	const page = await context.newPage();

	await page.goto(loginUrl);
	await signInWithPassword(page, email, MEMBER_PASSWORD);
	await expectSignedIn(page, email);

	return page;
}

/** Everything in the mailbox that is not a sign-in link or a code. */
async function notices(site: Site, email: string): Promise<Mail[]> {
	return (await site.mail(email)).filter((one) => !one.body.includes('diluxone_users_token') && !/\b\d{6}\b/.test(one.body));
}

/** A new account, signed in on two devices one after the other. */
async function twoDevices(browser: Browser, baseURL: string, site: Site, loginUrl: string, prefix: string, meta: Record<string, unknown> = {}) {
	const email = freshEmail(prefix);

	await site.makeUser({ email, password: MEMBER_PASSWORD, meta });

	const first = await signInOn(browser, baseURL, loginUrl, email, 'E2E-Device-One Chrome/1');

	await signInOn(browser, baseURL, loginUrl, email, 'E2E-Device-Two Firefox/2');

	return { email, first };
}

/** Opens one e-mail's fold on the templates tab. */
async function openMail(page: Page, key: string): Promise<void> {
	const fold = page.locator(`details:has(#diluxone_users_mail_${key}_subject)`);

	if (!(await fold.evaluate((element: HTMLDetailsElement) => element.open))) {
		await fold.locator('> summary').click();
	}

	await expect(page.locator(`#diluxone_users_mail_${key}_subject`)).toBeVisible();
}

async function stored(site: Site): Promise<Record<string, Record<string, { subject: string; body: string }>>> {
	const all = (await site.getOptions(['diluxone_users_mail_templates'])).diluxone_users_mail_templates;

	// PHP's empty array comes back as [] — no rewrite in any language either way.
	return (Array.isArray(all) || !all ? {} : all) as Record<string, Record<string, { subject: string; body: string }>>;
}

/** Asks for a link as a stranger and hands back the mail it brought. */
async function linkMail(page: Page, site: Site, loginUrl: string, email: string): Promise<Mail> {
	await site.setOptions({}, { forgetTransients: true });

	const asked = Date.now() / 1000;

	await page.context().clearCookies();
	await askForLink(page, loginUrl, email);

	return waitForMail(site, email, { after: asked - 1 });
}

test.beforeEach(async ({ options }) => {
	await options.keep(['diluxone_users_notice_rules', 'diluxone_users_mail_templates']);
	await options.set({ diluxone_users_notice_rules: {}, diluxone_users_mail_templates: {} });
});

test.describe('E-mail notices › the rules', () => {
	test('“never sent”: a sign-in from a new device mails nobody', async ({ page, browser, baseURL, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await page.locator(LOGIN_RULE).selectOption('never');
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_notice_rules'])).diluxone_users_notice_rules).toMatchObject({ diluxone_users_notify_login: 'never' });

		const { email } = await twoDevices(browser, baseURL!, site, pages.login.url, 'rule-never');

		expect(await notices(site, email), 'no new-device mail').toEqual([]);

		// And the same two devices with the rule back on: the control works both ways.
		await page.locator(LOGIN_RULE).selectOption('default_on');
		await savePanel(page);

		const again = await twoDevices(browser, baseURL!, site, pages.login.url, 'rule-on');

		expect(await notices(site, again.email)).toHaveLength(1);
	});

	test('“off by default”: the switch comes unticked and nothing is sent until the person turns it on', async ({ page, browser, baseURL, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await page.locator(LOGIN_RULE).selectOption('default_off');
		await savePanel(page);

		const { email, first } = await twoDevices(browser, baseURL!, site, pages.login.url, 'rule-off');

		expect(await notices(site, email), 'off by default: nothing').toEqual([]);

		await first.goto(accountSection(pages.account.url, 'notifications'));

		const form = first.locator('form').filter({ has: first.locator('input[name="action"][value="diluxone_users_notifications"]') });
		const box = form.locator('input[name="diluxone_users_notify_login"]');
		const closed = first.locator('details:not([open])').filter({ has: box });

		if ((await closed.count()) > 0) {
			await closed.first().locator('> summary').click();
		}

		await expect(box).not.toBeChecked();
		await box.check();
		await navigated(first, () => form.locator('button[type="submit"]').first().click());
		expect((await site.user(email, ['diluxone_users_notify_login'])).fields.diluxone_users_notify_login).toBe('1');

		await signInOn(browser, baseURL!, pages.login.url, email, 'E2E-Device-Three Safari/3');
		expect(await notices(site, email), 'turned on, the next new device is announced').toHaveLength(1);
	});

	test('“always sent”: no switch on the account, and a person who had turned it off is told anyway', async ({ page, browser, baseURL, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await page.locator(LOGIN_RULE).selectOption('always');
		await savePanel(page);
		await expect(page.locator(LOGIN_RULE)).toHaveValue('always');

		const { email, first } = await twoDevices(browser, baseURL!, site, pages.login.url, 'rule-always', { diluxone_users_notify_login: '0' });

		expect(await notices(site, email)).toHaveLength(1);

		await first.goto(accountSection(pages.account.url, 'notifications'));
		await expect(first.locator('input[name="diluxone_users_notify_login"]')).toHaveCount(0);
	});

	test('the security rule: “never” leaves turning on the second step unannounced; “always” announces it', async ({ page, browser, baseURL, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_mode: 'optional' });

		const turnOn = async (prefix: string): Promise<string> => {
			const email = freshEmail(prefix);

			await site.makeUser({ email, password: MEMBER_PASSWORD });

			const person = await signInOn(browser, baseURL!, pages.login.url, email, 'E2E-Device-One Chrome/1');

			await person.goto(accountSection(pages.account.url, 'security'));

			const on = person.locator('button[name="diluxone_users_security"][value="on"]');
			const closed = person.locator('details:not([open])').filter({ has: on });

			if ((await closed.count()) > 0) {
				await closed.first().locator('> summary').click();
			}

			await navigated(person, () => on.click());
			expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('1');

			return email;
		};

		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await page.locator(SECURITY_RULE).selectOption('never');
		await savePanel(page);

		const quiet = await turnOn('sec-never');

		expect(await notices(site, quiet), 'never: nothing about the change').toEqual([]);

		await page.locator(SECURITY_RULE).selectOption('always');
		await savePanel(page);

		const told = await turnOn('sec-always');
		const mail = await waitForMail(site, told);

		expect(mail.body, 'it points at the account’s security section').toContain(accountSection(pages.account.url, 'security'));
	});

	test('a policy that is not on the list is not stored, and a rule an add-on keeps is left alone', async ({ page, site, options }) => {
		await options.set({ diluxone_users_notice_rules: { diluxone_users_notify_login: 'default_on', e2e_addon_notice: 'never' } });

		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await page.locator(LOGIN_RULE).evaluate((select: HTMLSelectElement) => {
			select.add(new Option('sometimes', 'sometimes'));
			select.value = 'sometimes';
		});
		await savePanel(page);

		const rules = (await site.getOptions(['diluxone_users_notice_rules'])).diluxone_users_notice_rules as Record<string, string>;

		expect(rules.diluxone_users_notify_login, 'the forged policy was dropped; the one there was stays').toBe('default_on');
		expect(rules.e2e_addon_notice, 'a rule for a notice this screen does not draw is kept').toBe('never');
		expect(Object.values(rules)).not.toContain('sometimes');
	});
});

test.describe('E-mail notices › the summary', () => {
	test('each notice’s row follows its rule, and the link and code rows follow the ways in and the second step', async ({ page, options }) => {
		// The mail pinned as working: a site whose last e-mail failed has the
		// link and code rows saying they cannot vouch, which is the next test.
		await options.set({
			diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000), error: '' },
			diluxone_users_notice_rules: { diluxone_users_notify_login: 'never', diluxone_users_notify_security: 'always' },
			diluxone_users_login_method: 'password',
			diluxone_users_2fa_methods: ['totp'],
			diluxone_users_privacy_export: 0,
			diluxone_users_privacy_delete: 0,
		});

		await page.goto(adminUrl('diluxone-users-notices', 'summary'));

		const rows = page.locator('table.diluxone-users-summary tbody tr');
		const states = await rows.locator('.diluxone-users-summary__state .diluxone-users-state').evaluateAll((all) => all.map((one) => one.className.replace(/.*diluxone-users-state--(\w+).*/, '$1')));

		// The notices first, in the order they are registered, then the link, the code and the data.
		expect(states.slice(-3), 'the link, the code and their data: none of them in use').toEqual(['off', 'off', 'off']);
		expect(states, 'one notice off by its rule, one sent').toEqual(expect.arrayContaining(['off', 'active']));

		await expect(rows.nth((await rows.count()) - 3).locator('.diluxone-users-summary__change a'), 'the link row goes to the ways in').toHaveAttribute('href', /page=diluxone-users-login&tab=ways/);
		await expect(rows.nth((await rows.count()) - 2).locator('.diluxone-users-summary__change a'), 'the code row goes to the second step').toHaveAttribute('href', /tab=2fa/);

		await options.set({ diluxone_users_login_method: 'both', diluxone_users_2fa_methods: ['totp', 'email'], diluxone_users_privacy_export: 1 });
		await page.reload();

		const after = await rows.locator('.diluxone-users-summary__state .diluxone-users-state').evaluateAll((all) => all.map((one) => one.className.replace(/.*diluxone-users-state--(\w+).*/, '$1')));

		expect(after.slice(-3)).toEqual(['active', 'active', 'unknown']);
	});

	test('when the last e-mail did not go out, the rail says so and the link and code rows cannot vouch for delivery', async ({ page, options }) => {
		await options.set({
			diluxone_users_mail_last: { ok: 0, time: Math.floor(Date.now() / 1000), error: 'e2e: the mailer said no' },
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_methods: ['totp', 'email'],
		});

		await page.goto(adminUrl('diluxone-users-notices', 'summary'));
		await expect(railNotice(page, 'warning')).toHaveCount(1);

		const rows = page.locator('table.diluxone-users-summary tbody tr');
		const count = await rows.count();

		for (const n of [count - 3, count - 2]) {
			await expect(rows.nth(n).locator('.diluxone-users-state')).toHaveClass(/--unknown/);
			await expect(rows.nth(n).locator('.diluxone-users-summary__change a')).toHaveAttribute('href', /page=diluxone-users-status/);
		}

		await options.set({ diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000), error: '' } });
		await page.reload();
		await expect(railNotice(page, 'warning')).toHaveCount(0);
	});
});

test.describe('E-mail notices › the e-mails', () => {
	test('a sign-in link without {link}, or a code without {code}, is refused: nothing written, and the link still arrives', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'login_link');
		await page.locator('#diluxone_users_mail_login_link_body').fill('Hola, entrá desde la página.');
		await openMail(page, 'second_step');
		await page.locator('#diluxone_users_mail_second_step_body').fill('Tu código llega por otro lado.');

		await navigated(page, () => saveButton(page).click());
		await expect(adminError(page)).toHaveCount(2);
		expect(await stored(site), 'neither e-mail was written').toEqual({});

		const email = freshEmail('tpl-kept');

		await site.makeUser({ email });

		const mail = await linkMail(guest, site, pages.login.url, email);

		await guest.goto(linkIn(mail));
		await expectSignedIn(guest, email);
	});

	test('“put the plugin’s words back” drops the rewrite, and the next mail is the plugin’s again', async ({ page, guest, site, pages }) => {
		const email = freshEmail('tpl-back');

		await site.makeUser({ email });

		const shipped = await linkMail(guest, site, pages.login.url, email);

		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'login_link');
		await expect(page.locator('input[name="diluxone_users_mail[login_link][shipped]"]'), 'nothing to put back yet').toHaveCount(0);
		await page.locator('#diluxone_users_mail_login_link_subject').fill('E2E rewritten subject');
		await savePanel(page);

		expect((await linkMail(guest, site, pages.login.url, email)).subject).toBe('E2E rewritten subject');

		await openMail(page, 'login_link');
		await page.locator('input[name="diluxone_users_mail[login_link][shipped]"]').setChecked(true, { force: true });
		await savePanel(page);

		expect(await stored(site), 'the rewrite is gone').toEqual({});
		await expect(page.locator('input[name="diluxone_users_mail[login_link][shipped]"]')).toHaveCount(0);
		expect((await linkMail(guest, site, pages.login.url, email)).subject, 'the plugin’s own subject again').toBe(shipped.subject);
	});

	test('emptied boxes are the plugin’s words, not an empty e-mail, and the link in it works', async ({ page, guest, site, pages }) => {
		const email = freshEmail('tpl-empty');

		await site.makeUser({ email });

		const shipped = await linkMail(guest, site, pages.login.url, email);

		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'login_link');
		await page.locator('#diluxone_users_mail_login_link_subject').fill('');
		await page.locator('#diluxone_users_mail_login_link_body').fill('');
		await savePanel(page);

		expect(await stored(site)).toEqual({});

		const mail = await linkMail(guest, site, pages.login.url, email);

		expect(mail.subject).toBe(shipped.subject);
		await guest.goto(linkIn(mail));
		await expectSignedIn(guest, email);
	});

	test('saved without a change, nothing is stored as a rewrite and no e-mail says “written by this site”', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'templates'));

		for (const key of ['login_link', 'second_step', 'new_device', 'security_changed']) {
			await openMail(page, key);
		}

		// Touch one box and put it back, so the save box has something to send.
		const subject = page.locator('#diluxone_users_mail_new_device_subject');
		const was = await subject.inputValue();

		await subject.fill(`${was} x`);
		await subject.fill(was);
		await navigated(page, () =>
			saveButton(page).evaluate((button: HTMLButtonElement) => {
				HTMLFormElement.prototype.submit.call(button.form as HTMLFormElement);
			})
		);
		await expect(adminSaved(page)).toBeVisible();

		expect(await stored(site)).toEqual({});
		await expect(page.locator('input[name$="[shipped]"]'), 'nothing to put back').toHaveCount(0);
	});

	test('the second-step code goes out in the words saved, and the code in it still signs in', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'], diluxone_users_login_method: 'both' });

		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'second_step');
		await page.locator('#diluxone_users_mail_second_step_subject').fill('E2E code for {site}');
		await page.locator('#diluxone_users_mail_second_step_body').fill('E2E code: {code} ({minutes} min)');
		await savePanel(page);

		const email = freshEmail('tpl-code');

		await site.makeUser({ email, password: MEMBER_PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, MEMBER_PASSWORD);
		await expect(challengeScreen(guest)).toBeVisible();

		const mail = await waitForMail(site, email, { subject: /^E2E code for / });

		expect(mail.subject).not.toContain('{site}');
		expect(mail.body).toMatch(/^E2E code: \d{6} \(\d+ min\)$/);

		await challengeCode(guest).fill(codeIn(mail));
		await guest.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(guest, email);
	});

	test('the new-device and security e-mails go out in the words saved, every placeholder filled', async ({ page, browser, baseURL, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_mode: 'optional' });

		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'new_device');
		await page.locator('#diluxone_users_mail_new_device_subject').fill('E2E device on {site}');
		await page.locator('#diluxone_users_mail_new_device_body').fill('Device={device}|When={when}|Via={via}|Account={account}|Name={name}');
		await openMail(page, 'security_changed');
		await page.locator('#diluxone_users_mail_security_changed_subject').fill('E2E security on {site}');
		await page.locator('#diluxone_users_mail_security_changed_body').fill('Event={event}|Account={account}');
		await savePanel(page);

		const { email, first } = await twoDevices(browser, baseURL!, site, pages.login.url, 'tpl-device');
		const device = await waitForMail(site, email, { subject: /^E2E device on / });

		expect(device.subject).not.toMatch(/[{}]/);
		expect(device.body).not.toMatch(/[{}]/);
		expect(device.body).toContain(`Account=${accountSection(pages.account.url, 'security')}`);
		expect(device.body).toMatch(/Device=\S.*\|When=\S.*\|Via=\S.*\|/);

		await first.goto(accountSection(pages.account.url, 'security'));

		const on = first.locator('button[name="diluxone_users_security"][value="on"]');
		const closed = first.locator('details:not([open])').filter({ has: on });

		if ((await closed.count()) > 0) {
			await closed.first().locator('> summary').click();
		}

		await navigated(first, () => on.click());

		const security = await waitForMail(site, email, { subject: /^E2E security on / });

		expect(security.body).toMatch(/^Event=\S.+\|Account=/);
		expect(security.body).toContain(`Account=${accountSection(pages.account.url, 'security')}`);
	});

	test('another language keeps its own words: the address carries it and the site’s language is left as it was', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'templates'));

		const other = page.locator('.du-note a[href*="lang="]').first();

		await expect(other, 'this site has a second language to write in').toHaveCount(1);

		const lang = new URL((await other.getAttribute('href'))!, page.url()).searchParams.get('lang')!;

		await navigated(page, () => other.click());
		expect(new URL(page.url()).searchParams.get('lang')).toBe(lang);

		await openMail(page, 'login_link');
		await page.locator('#diluxone_users_mail_login_link_subject').fill('E2E subject in another language');
		await savePanel(page);

		expect(new URL(page.url()).searchParams.get('lang'), 'the save stays in that language').toBe(lang);
		await expect(page.locator('#diluxone_users_mail_login_link_subject')).toHaveValue('E2E subject in another language');

		const all = await stored(site);

		expect(Object.keys(all), 'written under that language only').toEqual([lang]);
		expect(all[lang].login_link.subject).toBe('E2E subject in another language');

		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'login_link');
		await expect(page.locator('#diluxone_users_mail_login_link_subject'), 'the site’s own language is untouched').not.toHaveValue('E2E subject in another language');
	});
});
