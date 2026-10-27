import { Browser, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { Site, codeIn, waitForMail } from '../support/api';
import { accountSection, challengeScreen, notice, openAllPanels, signInWithPassword } from '../support/ui';
import { PASSWORD, anotherBrowser, formOf, nonceOf, postByHand, reveal, send, signedIn } from '../support/my-account';

/**
 * Notifications, the list of sessions and the second step, as
 * the person whose account it is — and, where it matters, as somebody else
 * trying to reach into it.
 *
 * Every change is made with the account's own button and then asked of the
 * site: the meta, the sessions left, the mail that went (or did not), the
 * state in the address. Mail bodies are in Spanish here, so they are told
 * apart by what they carry — a device name, an address — or by being
 * different from each other, never by a sentence.
 */

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: [{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }],
		diluxone_users_login_method: 'both',
		diluxone_users_2fa_mode: 'optional',
		diluxone_users_2fa_methods: ['email'],
		diluxone_users_notice_rules: {},
		diluxone_users_sessions_show: 1,
	});
});

/** The messages that are neither a sign-in link nor a code: the notices. */
async function noticesFor(site: Site, email: string) {
	return (await site.mail(email)).filter((one) => !one.body.includes('diluxone_users_token') && !/\b\d{6}\b/.test(one.body));
}

/** A browser that looks like another device. */
async function device(browser: Browser, baseURL: string | undefined, agent: string): Promise<Page> {
	return (await browser.newContext({ baseURL, userAgent: agent, storageState: undefined })).newPage();
}

const securityForm = (page: Page) => formOf(page, 'diluxone_users_security');
const twoStep = (page: Page, value: string) => page.locator(`button[name="diluxone_users_security"][value="${value}"]`);

test.describe('Notifications', () => {
	const notifyForm = (page: Page) => formOf(page, 'diluxone_users_notifications');

	test('“off by default”: unticked, no device mail until the person ticks it — and then the next device is announced', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_notice_rules: { diluxone_users_notify_login: 'default_off' } });

		const { email } = await signedIn(page, site, pages.login.url, 'n-off');
		const second = await anotherBrowser(browser, baseURL, email, pages.login.url, 'E2E-Two Chrome/2 (X11; Linux x86_64)');

		expect(await noticesFor(site, email), 'a second device, and nothing said').toEqual([]);

		await page.goto(accountSection(pages.account.url, 'notifications'));
		await reveal(page, 'input[name="diluxone_users_notify_login"]');

		const box = notifyForm(page).locator('input[name="diluxone_users_notify_login"]');

		await expect(box).not.toBeChecked();
		await box.check();
		expect(await send(page, notifyForm(page).locator('button[type="submit"]'))).toBe('saved');
		await expect(notice(page, 'ok').first(), 'the account says it saved').toBeVisible();

		await device(browser, baseURL, 'E2E-Three Firefox/3 (Windows NT 10.0)').then(async (third) => {
			await third.goto(pages.login.url);
			await signInWithPassword(third, email, PASSWORD);
			await expectSignedIn(third, email);
			await third.context().close();
		});

		const announced = await noticesFor(site, email);

		expect(announced.length, 'the third device is announced').toBe(1);
		expect(announced[0].body, 'it names the device').toContain('Firefox');
		expect(announced[0].body, 'and sends them to their security').toContain(accountSection(pages.account.url, 'security'));
		await second.context().close();
	});

	test('security changes switched off by the person are not mailed', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'n-sec');

		await page.goto(accountSection(pages.account.url, 'notifications'));
		await reveal(page, 'input[name="diluxone_users_notify_security"]');
		await notifyForm(page).locator('input[name="diluxone_users_notify_security"]').uncheck();
		expect(await send(page, notifyForm(page).locator('button[type="submit"]'))).toBe('saved');
		expect((await site.user(email, ['diluxone_users_notify_security'])).fields.diluxone_users_notify_security).toBe('0');

		await site.clearMail();
		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, twoStep(page, 'on'))).toBe('on');

		expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('1');
		expect(await site.mail(email), 'two-step turned on, and nothing mailed').toEqual([]);
	});

	test('the e-mails nobody can turn off are listed ticked and locked; with nothing to choose there is no form', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });
		await signedIn(page, site, pages.login.url, 'n-locked');

		await page.goto(accountSection(pages.account.url, 'notifications'));

		for (const key of ['diluxone_users_notify_link', 'diluxone_users_notify_2fa']) {
			const box = page.locator(`input[name="${key}"]`);

			await expect(box, key).toBeChecked();
			await expect(box, key).toBeDisabled();
			await expect(page.locator(`label:has(input[name="${key}"]) .diluxone-users-pill`), key).toHaveCount(1);
		}

		await options.set({
			diluxone_users_notice_rules: { diluxone_users_notify_login: 'always', diluxone_users_notify_security: 'always' },
		});
		await page.goto(accountSection(pages.account.url, 'notifications'));
		await expect(formOf(page, 'diluxone_users_notifications')).toHaveCount(0);
		await expect(page.locator('input[name="diluxone_users_notify_login"]')).toHaveCount(0);
		await expect(page.locator('input[name="diluxone_users_notify_link"]')).toBeDisabled();
	});
});

test.describe('Sessions', () => {
	test('a session of another account cannot be closed, even with a good nonce', async ({ page, browser, baseURL, site, pages }) => {
		const { email: owner } = await signedIn(page, site, pages.login.url, 's-owner');
		const ownerSecond = await anotherBrowser(browser, baseURL, owner, pages.login.url);

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-sessions__list');

		const theirs = await page.locator('.diluxone-users-session:not(.diluxone-users-session--current) input[name="diluxone_users_session"]').inputValue();

		expect((await site.user(owner)).sessions).toBe(2);

		// Somebody else, signed in twice so their own list has a Close to take the nonce from.
		const context = await browser.newContext({ baseURL, storageState: undefined });
		const stranger = await context.newPage();
		const { email: other } = await signedIn(stranger, site, pages.login.url, 's-stranger');
		const strangerSecond = await anotherBrowser(browser, baseURL, other, pages.login.url);

		await stranger.goto(accountSection(pages.account.url, 'security'));

		const answer = await postByHand(stranger, {
			action: 'diluxone_users_sessions',
			_wpnonce: await nonceOf(stranger, 'diluxone_users_sessions'),
			diluxone_users_session: theirs,
		});

		expect(answer.status).toBe(302);
		expect((await site.user(owner)).sessions, 'the owner keeps both sessions').toBe(2);
		expect((await site.user(other)).sessions, 'and the stranger keeps theirs').toBe(2);
		await expectSignedIn(ownerSecond, owner);

		for (const one of [ownerSecond, strangerSecond, stranger]) {
			await one.context().close();
		}
	});

	test('each row says which browser and system it is', async ({ page, browser, baseURL, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 's-rows');
		const firefox = await anotherBrowser(browser, baseURL, email, pages.login.url, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:2.0) Gecko/20100101 Firefox/2.0');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-sessions__list');

		const row = page.locator('.diluxone-users-session:not(.diluxone-users-session--current)');

		await expect(row).toContainText('Firefox');
		await expect(row).toContainText('Windows');
		await firefox.context().close();
	});

	test('where sessions are not kept one by one, there is no Close per row and “close the others” still works', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_e2e_session_manager: 1 });

		const { email } = await signedIn(page, site, pages.login.url, 's-store');
		const other = await anotherBrowser(browser, baseURL, email, pages.login.url);

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-sessions__list');
		await expect(page.locator('.diluxone-users-session')).toHaveCount(2);
		await expect(page.locator('.diluxone-users-session input[name="diluxone_users_session"]'), 'no Close per row').toHaveCount(0);

		await reveal(page, 'form.diluxone-users-sessions__all');
		expect(await send(page, page.locator('form.diluxone-users-sessions__all button[type="submit"]'))).toBe('sessions');
		expect((await site.user(email)).sessions).toBe(1);
		await expectSignedOut(other);
		await other.context().close();
	});
});

test.describe('Two-step verification', () => {
	test('“How you get in” says there is no password when the password is no way in', async ({ page, site, pages, options }) => {
		await signedIn(page, site, pages.login.url, 't-howin');

		const password = page.locator('.diluxone-users-data dd').nth(1);

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-data');

		const withPassword = await password.innerText();

		await options.set({ diluxone_users_login_method: 'link' });
		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-data');
		expect(await password.innerText()).not.toBe(withPassword);
	});

	test('turned on, renewed and turned off with an e-mailed code; each change is mailed, and the backup codes are shown only once', async ({
		page,
		site,
		pages,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 't-cycle');

		await site.clearMail();
		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, twoStep(page, 'on'))).toBe('on');

		const codes = page.locator('.diluxone-users-backup__list code');

		await expect(codes.first()).toBeVisible();

		const handed = await codes.count();

		// The button that renews them says how many are left: all of them.
		await reveal(page, 'button[name="diluxone_users_security"][value="backup"]');
		await expect(twoStep(page, 'backup')).toContainText(String(handed));

		expect((await noticesFor(site, email)).length, 'turning it on is mailed').toBe(1);

		await page.reload();
		await expect(codes, 'shown once: gone on reload').toHaveCount(0);

		// Off, with a code by e-mail: a second message, different from the first.
		await reveal(page, 'button[name="diluxone_users_security"][value="code"]');

		const asked = Date.now() / 1000;

		expect(await send(page, twoStep(page, 'code'))).toBe('codesent');

		const code = codeIn(await waitForMail(site, email, { after: asked - 1 }));

		await reveal(page, '#diluxone-users-reauth-code');
		await page.locator('#diluxone-users-reauth-code').fill(code);
		expect(await send(page, twoStep(page, 'off'))).toBe('off');

		const off = await noticesFor(site, email);

		expect(off.length).toBe(2);
		expect(new Set(off.map((one) => one.body)).size, 'two changes, two different messages').toBe(2);
		await expect(page.locator('.diluxone-users-totp__key'), 'and no app without Users+ Pro').toHaveCount(0);
	});

	test('the e-mail method that is ready is named, with turning on', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });
		await signedIn(page, site, pages.login.url, 't-ready');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');

		const panel = page.locator('details.diluxone-users-panel').filter({ has: page.locator('button[name="diluxone_users_security"][value="on"]') });

		await expect(panel.locator('.diluxone-users-chip')).toBeVisible();
		await expect(panel.locator('p.diluxone-users-note').first()).toBeVisible();
	});

	test('required by the site: no way to turn it off, and an “off” posted anyway is refused', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });

		const { email } = await signedIn(page, site, pages.login.url, 't-required');

		await page.goto(accountSection(pages.account.url, 'security'));

		const nonce = await nonceOf(page, 'diluxone_users_security');

		await options.set({ diluxone_users_2fa_mode: 'required' });
		await page.goto(accountSection(pages.account.url, 'security'));
		await openAllPanels(page);
		await expect(twoStep(page, 'off')).toHaveCount(0);
		await expect(twoStep(page, 'on')).toHaveCount(0);

		const answer = await postByHand(page, { action: 'diluxone_users_security', _wpnonce: nonce, diluxone_users_security: 'off' });

		expect(answer.state).toBe('required');

		// Still required: signing in again asks for the code.
		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
	});

	test('not offered — the site switched it off — no panel, and an “on” posted anyway is refused', async ({
		page,
		site,
		pages,
		options,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 't-notoffered');

		await page.goto(accountSection(pages.account.url, 'security'));

		const nonce = await nonceOf(page, 'diluxone_users_security');

		await options.set({ diluxone_users_2fa_mode: 'off' });
		await page.goto(accountSection(pages.account.url, 'security'));
		await expect(twoStep(page, 'on')).toHaveCount(0);

		const answer = await postByHand(page, { action: 'diluxone_users_security', _wpnonce: nonce, diluxone_users_security: 'on', diluxone_users_code: '123456' });

		expect(answer.state).toBe('notoffered');
		expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('');
	});

	test('with only Users+ Pro’s app stored and Pro off, turning it on works: the e-mail code stands in', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['totp'] });

		const { email } = await signedIn(page, site, pages.login.url, 't-appgone');

		await page.goto(accountSection(pages.account.url, 'security'));

		const answer = await postByHand(page, {
			action: 'diluxone_users_security',
			_wpnonce: await nonceOf(page, 'diluxone_users_security'),
			diluxone_users_security: 'on',
		});

		expect(answer.state).toBe('on');
		expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('1');
	});

	test('with the e-mail link a way in, the panel makes no exception for it: the second step is asked on the link too', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });
		await signedIn(page, site, pages.login.url, 't-info');

		const info = page.locator('.diluxone-users-notice--info');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, twoStep(page, 'on'))).toBe('on');

		// The free plugin always asks after the link (diluxone_users_2fa_on_link
		// is Users+ Pro's way to say otherwise), so no way in is left out.
		for (const method of ['both', 'link', 'password']) {
			await options.set({ diluxone_users_login_method: method });
			await page.goto(accountSection(pages.account.url, 'security'));
			await openAllPanels(page);
			await expect(info, `${method}: nothing says the link is spared`).toHaveCount(0);
		}
	});
});

/*
 * Passkeys are Users+ Pro's. Without it, the place an add-on draws its panel
 * in — between how somebody gets in and the second step — holds nothing,
 * and nothing on the section offers or names a passkey.
 */
test.describe('Without an add-on', () => {
	test('the second step comes right after “How you get in”, and there is no passkey anywhere on the section', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'no-addon');
		await page.goto(accountSection(pages.account.url, 'security'));

		const howIn = page.locator('details.diluxone-users-panel').filter({ has: page.locator('.diluxone-users-data') });

		await expect(howIn).toHaveCount(1);

		const next = howIn.locator('xpath=following-sibling::*[1]');

		await expect(next, 'the panel after it is the second step').toHaveClass(/diluxone-users-panel/);
		await expect(next.locator('input[name="action"][value="diluxone_users_security"]').first()).toBeAttached();

		await expect(page.locator('[data-diluxone-users-passkey]')).toHaveCount(0);
		await expect(page.locator('input[name^="diluxone_users_passkey"]')).toHaveCount(0);
		await openAllPanels(page);
		await expect(page.locator('.diluxone-users-account__section, main').first()).not.toContainText(/passkey/i);
	});
});
