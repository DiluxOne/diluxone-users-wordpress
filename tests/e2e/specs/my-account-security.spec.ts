import { Browser, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { Site, codeIn, freshEmail, waitForMail } from '../support/api';
import { accountSection, challengeCode, challengeScreen, notice, openAllPanels, openPanel, signInWithPassword } from '../support/ui';
import { avoidWindowEdge, totp } from '../support/totp';
import { PASSWORD, anotherBrowser, formOf, nonceOf, postByHand, reveal, send, signedIn } from '../support/my-account';

/**
 * Notifications, the list of sessions, the second step and the passkeys, as
 * the person whose account it is — and, where it matters, as somebody else
 * trying to reach into it.
 *
 * Every change is made with the account's own button and then asked of the
 * site: the meta, the sessions left, the mail that went (or did not), the
 * state in the address. Mail bodies are in Spanish here, so they are told
 * apart by what they carry — a device name, an address — or by being
 * different from each other, never by a sentence.
 */

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: [{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }],
		diluxone_users_login_method: 'both',
		diluxone_users_2fa_mode: 'optional',
		diluxone_users_2fa_methods: ['email', 'totp'],
		diluxone_users_2fa_scope: 'all',
		diluxone_users_2fa_remember_days: 30,
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

	test('“close the others” also forgets the browsers trusted to skip the second step', async ({ page, browser, baseURL, site, pages }) => {
		const email = freshEmail('s-trust');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });

		// One browser, trusted.
		const trusted = await device(browser, baseURL, 'E2E-Trusted Chrome/1');

		await trusted.goto(pages.login.url);
		await signInWithPassword(trusted, email, PASSWORD);
		await expect(challengeScreen(trusted)).toBeVisible();
		await avoidWindowEdge();
		await challengeCode(trusted).fill(totp(SECRET));
		await trusted.locator('input[name="diluxone_users_2fa_trust"]').check();
		await trusted.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(trusted, email);

		// Another one, signed in with the next code, closes the others.
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		await challengeCode(page).fill(totp(SECRET, Date.now() + 30_000));
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'form.diluxone-users-sessions__all');
		expect(await send(page, page.locator('form.diluxone-users-sessions__all button[type="submit"]'))).toBe('sessions');
		await expectSignedOut(trusted);

		// The trusted browser, back with its cookie: asked again.
		await trusted.goto(pages.login.url);
		await signInWithPassword(trusted, email, PASSWORD);
		await expect(challengeScreen(trusted), 'the trust went with the sessions').toBeVisible();
		await trusted.context().close();
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

	test('a wrong app code does not set the app up', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 't-bad');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '#diluxone-users-totp-code');
		await page.locator('#diluxone-users-totp-code').fill('000000');
		expect(await send(page, page.locator('form:has(input[name="diluxone_users_security"][value="totp"]) button[type="submit"]'))).toBe('badcode');
		await expect(notice(page, 'error').first()).toBeVisible();

		const after = await site.user(email);

		expect(after.meta.diluxone_users_totp).toBe('');
		expect(after.meta.diluxone_users_2fa_on).toBe('');
	});

	test('the app set up and removed with a live code; each change is mailed, and the backup codes are shown only once', async ({
		page,
		site,
		pages,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 't-app');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-totp__key');

		const secret = (await page.locator('.diluxone-users-totp__key').innerText()).replace(/\s+/g, '');

		await site.clearMail();
		await avoidWindowEdge();
		await page.locator('#diluxone-users-totp-code').fill(totp(secret));
		expect(await send(page, page.locator('form:has(input[name="diluxone_users_security"][value="totp"]) button[type="submit"]'))).toBe('totp');

		const codes = page.locator('.diluxone-users-backup__list code');

		await expect(codes.first()).toBeVisible();

		const handed = await codes.count();

		// The button that renews them says how many are left: all of them.
		await reveal(page, 'button[name="diluxone_users_security"][value="backup"]');
		await expect(twoStep(page, 'backup')).toContainText(String(handed));

		const setUp = await noticesFor(site, email);

		expect(setUp.length, 'setting the app up is mailed').toBe(1);

		await page.reload();
		await expect(codes, 'shown once: gone on reload').toHaveCount(0);

		// Removed with the next code the app shows, not a backup code.
		await reveal(page, '#diluxone-users-totp-off-code');
		await page.locator('#diluxone-users-totp-off-code').fill(totp(secret, Date.now() + 30_000));
		expect(await send(page, twoStep(page, 'totp_off'))).toBe('totpoff');
		expect((await site.user(email)).meta.diluxone_users_totp).toBe('');

		const removed = await noticesFor(site, email);

		expect(removed.length, 'removing it is mailed too').toBe(2);
		expect(removed[1].body, 'a different message from the set-up one').not.toBe(removed[0].body);

		// And off, with a code by e-mail: a third message, different again.
		await reveal(page, 'button[name="diluxone_users_security"][value="code"]');

		const asked = Date.now() / 1000;

		expect(await send(page, twoStep(page, 'code'))).toBe('codesent');

		const code = codeIn(await waitForMail(site, email, { after: asked - 1 }));

		await reveal(page, '#diluxone-users-reauth-code');
		await page.locator('#diluxone-users-reauth-code').fill(code);
		expect(await send(page, twoStep(page, 'off'))).toBe('off');

		const off = await noticesFor(site, email);

		expect(off.length).toBe(3);
		expect(new Set(off.map((one) => one.body)).size, 'three changes, three different messages').toBe(3);
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

	test('not offered — the site switched it off, or the person’s role is left out — no panel, and an “on” posted anyway is refused', async ({
		page,
		site,
		pages,
		options,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 't-notoffered');

		await page.goto(accountSection(pages.account.url, 'security'));

		const nonce = await nonceOf(page, 'diluxone_users_security');

		for (const rule of [{ diluxone_users_2fa_mode: 'off' }, { diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_scope: 'some', diluxone_users_2fa_roles: ['editor'] }]) {
			await options.set(rule);
			await page.goto(accountSection(pages.account.url, 'security'));
			await expect(twoStep(page, 'on'), JSON.stringify(rule)).toHaveCount(0);
			await expect(page.locator('#diluxone-users-totp-code'), JSON.stringify(rule)).toHaveCount(0);

			for (const action of ['on', 'totp']) {
				const answer = await postByHand(page, { action: 'diluxone_users_security', _wpnonce: nonce, diluxone_users_security: action, diluxone_users_code: '123456' });

				expect(answer.state, `${action} under ${JSON.stringify(rule)}`).toBe('notoffered');
			}

			expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('');
		}
	});

	test('with only the app offered and no app set up, turning it on says to set one up first', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['totp'] });

		const { email } = await signedIn(page, site, pages.login.url, 't-nomethod');

		await page.goto(accountSection(pages.account.url, 'security'));

		const answer = await postByHand(page, {
			action: 'diluxone_users_security',
			_wpnonce: await nonceOf(page, 'diluxone_users_security'),
			diluxone_users_security: 'on',
		});

		expect(answer.state).toBe('nomethod');
		expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('');
	});

	test('turning it off forgets the browsers trusted to skip it', async ({ page, site, pages }) => {
		const email = freshEmail('t-forget');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_2fa_on: 1 } });

		// In, trusting this browser, with a code by e-mail.
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		await challengeCode(page).fill(codeIn(await waitForMail(site, email)));
		await page.locator('input[name="diluxone_users_2fa_trust"]').check();
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);

		// Off, with a code, and on again.
		await site.clearMail();
		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="code"]');
		expect(await send(page, twoStep(page, 'code'))).toBe('codesent');
		await reveal(page, '#diluxone-users-reauth-code');
		await page.locator('#diluxone-users-reauth-code').fill(codeIn(await waitForMail(site, email)));
		expect(await send(page, twoStep(page, 'off'))).toBe('off');
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, twoStep(page, 'on'))).toBe('on');

		// Out, keeping the trust cookie, and back: asked again.
		await page.context().clearCookies({ name: /^wordpress/ });
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page), 'the trusted browser was forgotten').toBeVisible();
	});

	test('with the e-mail link a way in, the panel says where the second step is asked', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });
		await signedIn(page, site, pages.login.url, 't-info');

		const info = page.locator('.diluxone-users-notice--info');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, twoStep(page, 'on'))).toBe('on');
		await openAllPanels(page);
		await expect(info).toBeVisible();

		const withPassword = await info.innerText();

		await options.set({ diluxone_users_login_method: 'link' });
		await page.goto(accountSection(pages.account.url, 'security'));
		await openAllPanels(page);
		await expect(info).toBeVisible();
		expect(await info.innerText(), 'link only: it is asked nowhere, which is another sentence').not.toBe(withPassword);

		// The password only: no link, so nothing to explain.
		await options.set({ diluxone_users_login_method: 'password' });
		await page.goto(accountSection(pages.account.url, 'security'));
		await expect(info).toHaveCount(0);
	});
});

test.describe('Passkeys', () => {
	test.skip(({ browserName }) => browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

	const VIRTUAL_KEY = {
		protocol: 'ctap2' as const,
		transport: 'internal' as const,
		hasResidentKey: true,
		hasUserVerification: true,
		isUserVerified: true,
		automaticPresenceSimulation: true,
	};

	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
		});
	});

	/** A key in this browser, registered from the account. */
	async function register(page: Page, accountUrl: string, label: string): Promise<void> {
		const cdp = await page.context().newCDPSession(page);

		await cdp.send('WebAuthn.enable', { enableUI: false });
		await cdp.send('WebAuthn.addVirtualAuthenticator', { options: VIRTUAL_KEY });

		await page.goto(accountSection(accountUrl, 'security'));

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');

		await panel.locator('[data-diluxone-users-passkey-label]').fill(label);
		await panel.locator('[data-diluxone-users-passkey="register"]').click();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]').first()).toHaveValue(label, { timeout: 20_000 });
	}

	test('with passkeys off on the site, the account has no passkey panel', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_passkey_enabled: 0 });
		await signedIn(page, site, pages.login.url, 'pk-off');

		await page.goto(accountSection(pages.account.url, 'security'));
		await expect(page.locator('[data-diluxone-users-passkey="register"]')).toHaveCount(0);
	});

	test('adding and removing a key are each mailed, and the removal is said on the page', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'pk-mail');

		await site.clearMail();
		await register(page, pages.account.url, 'E2E laptop');

		expect((await noticesFor(site, email)).length, 'adding a key is mailed').toBe(1);

		await openAllPanels(page);
		expect(await send(page, page.locator('button[name="diluxone_users_passkey_do"][value="delete"]').first())).toBe('passkeyoff');
		await expect(notice(page, 'ok').first()).toBeVisible();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveCount(0);

		const mails = await noticesFor(site, email);

		expect(mails.length, 'removing it is mailed').toBe(2);
		expect(mails[1].body).not.toBe(mails[0].body);
	});

	test('the same authenticator a second time is refused on the page, without a reload', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'pk-twice');
		await register(page, pages.account.url, 'Only one');

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');
		const url = page.url();

		await panel.locator('[data-diluxone-users-passkey="register"]').click();

		const said = page.locator('[data-diluxone-users-passkey-notice]');

		await expect(said).toBeVisible({ timeout: 20_000 });
		await expect(said).toHaveClass(/diluxone-users-notice--error/);
		expect(page.url()).toBe(url);
		await expect(page.locator('input[name="diluxone_users_passkey_label"]'), 'still one key').toHaveCount(1);
	});

	test('a key of another account can be neither renamed nor removed, and nobody is mailed', async ({ page, browser, baseURL, site, pages }) => {
		const { email: owner } = await signedIn(page, site, pages.login.url, 'pk-owner');

		await register(page, pages.account.url, 'Owner key');

		const theirs = await page.locator('input[name="diluxone_users_passkey"]').first().inputValue();

		const context = await browser.newContext({ baseURL, storageState: undefined });
		const stranger = await context.newPage();
		const { email: other } = await signedIn(stranger, site, pages.login.url, 'pk-stranger');

		await register(stranger, pages.account.url, 'Stranger key');
		await site.clearMail();

		const nonce = await nonceOf(stranger, 'diluxone_users_passkey');

		await postByHand(stranger, { action: 'diluxone_users_passkey', _wpnonce: nonce, diluxone_users_passkey: theirs, diluxone_users_passkey_do: 'rename', diluxone_users_passkey_label: 'Stolen' });

		const removed = await postByHand(stranger, { action: 'diluxone_users_passkey', _wpnonce: nonce, diluxone_users_passkey: theirs, diluxone_users_passkey_do: 'delete' });

		expect(removed.state).not.toBe('passkeyoff');

		await page.reload();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]'), 'the owner keeps the key, with its name').toHaveValue('Owner key');
		await stranger.reload();
		await expect(stranger.locator('input[name="diluxone_users_passkey_label"]'), 'and the stranger keeps theirs').toHaveValue('Stranger key');

		expect(await site.mail(owner)).toEqual([]);
		expect(await site.mail(other)).toEqual([]);
		await context.close();
	});
});

