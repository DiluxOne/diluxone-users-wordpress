import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { avoidWindowEdge, totp } from '../support/totp';
import { adminUrl, askForLink, challengeCode, challengeScreen, fillCredentials, saveButton, signInWithPassword, ssoButton } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Living with WordPress and with the rest of the site, on a single site.
 *
 * The defaults and the seams that decide whether the plugin gets in the way of
 * anything it was not asked about: the second step answered on wp-login.php
 * when there is no sign-in page, `wp_login` fired by the plugin's own doors,
 * a registration from somewhere else left alone, a fresh site asking for a
 * name and nothing more, sessions as long as WordPress makes them, the
 * emergency switch, and the pages a cache must not keep. The network suite
 * walks the same cases on a network (`network/coexistence.spec.ts`).
 */

const PASSWORD = 'e2e-Coexist-1!';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Signs in on WordPress's own wp-login.php, the way somebody with no sign-in page does. */
async function signInOnWpLogin(page: Page, email: string, remember = false): Promise<void> {
	await page.goto('/wp-login.php');
	await fillCredentials(page, email, PASSWORD);

	if (remember) {
		await page.locator('#rememberme').check();
	}

	await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wp-submit').click()]);
}

/** Answers wp-login.php's second step and waits for the session. */
async function answerOnWpLogin(page: Page, code: string): Promise<void> {
	await challengeCode(page).fill(code);
	await Promise.all([
		page.waitForURL((url) => !url.searchParams.has('diluxone_users_2fa'), { waitUntil: 'domcontentloaded' }),
		challengeScreen(page).locator('input[type="submit"]').click(),
	]);
}

test.describe('The second step with no sign-in page: wp-login.php draws it', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_page: null,
			diluxone_users_login_method: 'both',
			diluxone_users_wp_screens: 'auto',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['totp', 'email'],
			diluxone_users_2fa_remember_days: 0,
		});
	});

	test('a password and the code by e-mail finish signing in, on wp-login.php', async ({ page, site }) => {
		const email = freshEmail('wplogin-mail');
		await site.makeUser({ email, password: PASSWORD });

		await signInOnWpLogin(page, email);

		// Halfway, on wp-login.php itself, with WordPress's own markup.
		await expect(challengeScreen(page)).toBeVisible();
		expect(new URL(page.url()).pathname).toMatch(/\/wp-login\.php$/);
		expect(new URL(page.url()).searchParams.get('action')).toBe('diluxone_users_2fa');
		await expectSignedOut(page);

		await answerOnWpLogin(page, codeIn(await waitForMail(site, email)));

		await expectSignedIn(page, email);
	});

	test('a wrong code comes back to wp-login.php and says so', async ({ page, site }) => {
		const email = freshEmail('wplogin-wrong');
		await site.makeUser({ email, password: PASSWORD });

		await signInOnWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();

		await challengeCode(page).fill('000000');
		await Promise.all([page.waitForURL(/diluxone-users=code/), challengeScreen(page).locator('input[type="submit"]').click()]);

		await expect(challengeScreen(page)).toBeVisible();
		await expect(page.locator('#login_error')).toBeVisible();
		await expectSignedOut(page);
	});

	test('a password and the authenticator app finish signing in, on wp-login.php', async ({ page, site }) => {
		const email = freshEmail('wplogin-app');
		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });

		await signInOnWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();
		expect(new URL(page.url()).searchParams.get('diluxone_users_method'), 'the app first').toBe('totp');

		await avoidWindowEdge();
		await answerOnWpLogin(page, totp(SECRET));

		await expectSignedIn(page, email);
	});

	test('with a sign-in page the second step is on the page, not on wp-login.php', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_login_page: pages.login.id });

		const email = freshEmail('wplogin-page');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);

		await expect(challengeScreen(page)).toBeVisible();
		expect(page.url().startsWith(pages.login.url), 'the page draws it').toBe(true);
		expect(new URL(page.url()).searchParams.get('action')).toBeNull();
	});
});

test.describe('With nowhere to answer the second step', () => {
	test.use({ storageState: ADMIN_STATE });

	test('“required” cannot be chosen or saved, and the screen says why', async ({ page, options }) => {
		await options.set({
			diluxone_users_login_page: null,
			diluxone_e2e_no_wp_login_2fa: 1,
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
		});

		await page.goto(adminUrl('diluxone-users-security', '2fa'));

		const required = page.locator('input[name="diluxone_users_2fa_mode"][value="required"]');

		await expect(required).toBeDisabled();
		await expect(page.locator('.diluxone-users-not-now')).toBeVisible();

		// A form sent anyway, with the attribute taken off in the inspector.
		await required.evaluate((input: HTMLInputElement) => {
			input.disabled = false;
			input.checked = true;
		});
		await Promise.all([page.waitForLoadState('domcontentloaded'), saveButton(page).click()]);

		await expect(page.locator('.notice-error')).toBeVisible();
		await expect(page.locator('input[name="diluxone_users_2fa_mode"][value="optional"]')).toBeChecked();
	});
});

test.describe('The plugin’s own doors fire wp_login, once', () => {
	test('the e-mail link', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_e2e_wp_login: null });

		const email = freshEmail('wplogin-link');
		await site.makeUser({ email, password: PASSWORD });

		await askForLink(page, pages.login.url, email);
		await page.goto(linkIn(await waitForMail(site, email)));

		await expectSignedIn(page, email);
		expect(await site.wpLogins(email)).toBe(1);
	});

	test('a social account', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_2fa_mode: 'off',
			diluxone_e2e_wp_login: null,
		});

		const email = freshEmail('wplogin-sso');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedIn(page, email);
		expect(await site.wpLogins(email)).toBe(1);
	});

	test('a passkey', async ({ page, site, pages, options, browserName }) => {
		test.skip(browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

		await options.set({
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
			diluxone_users_2fa_mode: 'off',
		});

		const email = freshEmail('wplogin-passkey');
		await site.makeUser({ email, password: PASSWORD });

		const cdp = await page.context().newCDPSession(page);
		await cdp.send('WebAuthn.enable', { enableUI: false });
		const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
			options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
		});

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(`${pages.account.url.replace(/\/?$/, '/')}security/`);
		await page.locator('details.diluxone-users-panel').filter({ has: page.locator('[data-diluxone-users-passkey="register"]') }).first().evaluate((d: HTMLDetailsElement) => (d.open = true));
		await page.locator('[data-diluxone-users-passkey-label]').fill('e2e');
		await page.locator('[data-diluxone-users-passkey="register"]').click();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveValue('e2e', { timeout: 20_000 });

		await page.context().clearCookies();
		await options.set({ diluxone_e2e_wp_login: null });

		await page.goto(pages.login.url);
		await page.locator('[data-diluxone-users-passkey="login"]').click();
		await page.waitForURL((url) => !url.href.startsWith(pages.login.url), { timeout: 20_000 });

		await expectSignedIn(page, email);
		expect(await site.wpLogins(email)).toBe(1);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('a link that asks for the second step: asked once, and wp_login once it is answered', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_link: 'always',
			diluxone_users_2fa_remember_days: 0,
			diluxone_e2e_wp_login: null,
		});

		const email = freshEmail('wplogin-link2fa');
		await site.makeUser({ email, password: PASSWORD });

		await askForLink(page, pages.login.url, email);
		const sent = Date.now() / 1000;

		await page.goto(linkIn(await waitForMail(site, email)));
		await expect(challengeScreen(page)).toBeVisible();
		expect(await site.wpLogins(email), 'nobody is in yet').toBe(0);

		await challengeCode(page).fill(codeIn(await waitForMail(site, email, { subject: /c(o|ó)digo|code/i, after: sent })));
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();

		await expectSignedIn(page, email);
		await expect(challengeScreen(page), 'not a second challenge').toHaveCount(0);
		expect(await site.wpLogins(email)).toBe(1);
	});
});

test.describe('A registration from somewhere else', () => {
	const CITY = { key: 'e2e_city', label: 'City', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' };

	test('register_new_user() with none of the plugin’s fields goes through', async ({ site, options }) => {
		await options.set({ diluxone_users_fields: [CITY] });

		const email = freshEmail('elsewhere');
		const login = `e2e${Date.now().toString(36)}`;
		const made = await site.register(login, email);

		expect(made.errors, 'no error from a field it never showed').toBeUndefined();
		expect(made.id).toBeGreaterThan(0);
		expect((await site.user(email)).exists).toBe(true);

		await site.deleteUser(email);
	});

	test('WordPress’s own form with the plugin’s fields and a wrong nonce is refused', async ({ page, site, options }) => {
		await options.set({ diluxone_users_fields: [CITY], users_can_register: 1, diluxone_users_login_method: 'both', diluxone_users_wp_screens: 'auto' });

		const email = freshEmail('badnonce');

		await page.goto('/wp-login.php?action=register');
		await page.locator('#user_login').fill(`e2e${Date.now().toString(36)}`);
		await page.locator('#user_email').fill(email);
		await page.locator('[name="e2e_city"]').fill('Mendoza');
		await page.locator('[name="diluxone_users_wp_register_nonce"]').evaluate((input: HTMLInputElement) => (input.value = 'not-the-nonce'));
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wp-submit').click()]);

		await expect(page.locator('#login_error')).toBeVisible();
		expect((await site.user(email)).exists, 'no account').toBe(false);
	});
});

test.describe('A fresh site asks for a name, and the rest is suggested', () => {
	test.use({ storageState: ADMIN_STATE });

	test('only the first and last name are seeded, neither required; the suggested fields are added from their tab', async ({ page, site, options }) => {
		await options.set({ diluxone_users_fields: null });

		await page.goto(adminUrl('diluxone-users-fields'));

		const seeded = (await site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; required: number | boolean }>;

		expect(seeded.map((one) => one.key)).toEqual(['first_name', 'last_name']);
		expect(seeded.every((one) => !one.required), 'neither is required').toBe(true);

		await page.goto(adminUrl('diluxone-users-fields', 'suggested'));
		await page.locator('input[name="diluxone_users_suggested[]"][value="diluxone_users_country"]').check({ force: true });
		await page.locator('input[name="diluxone_users_suggested[]"][value="diluxone_users_birthday"]').check({ force: true });
		await Promise.all([page.waitForLoadState('domcontentloaded'), saveButton(page).click()]);

		await expect(page.locator('.notice-success')).toBeVisible();

		const after = (await site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string }>;

		expect(after.map((one) => one.key)).toEqual(['first_name', 'last_name', 'diluxone_users_country', 'diluxone_users_birthday']);

		// What is on the list is not offered again.
		await page.goto(adminUrl('diluxone-users-fields', 'suggested'));
		await expect(page.locator('input[name="diluxone_users_suggested[]"][value="diluxone_users_country"]')).toHaveCount(0);
		await expect(page.locator('input[name="diluxone_users_suggested[]"][value="diluxone_users_phone"]')).toHaveCount(1);
	});
});

test.describe('How long a session lasts', () => {
	/** The expiry of the session cookie, in days from now; null for a cookie that ends with the browser. */
	async function sessionDays(page: Page): Promise<number | null> {
		const cookie = (await page.context().cookies()).find((one) => one.name.startsWith('wordpress_logged_in_'));

		expect(cookie, 'a session cookie').toBeTruthy();

		return cookie!.expires <= 0 ? null : Math.round((cookie!.expires - Date.now() / 1000) / 86400);
	}

	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_2fa_mode: 'off',
			diluxone_users_login_page: null,
			diluxone_users_session_long_days: null,
			diluxone_users_session_short_days: null,
		});
	});

	test('by default it is WordPress’s: 14 days with “remember me”', async ({ page, site }) => {
		const email = freshEmail('session-default');
		await site.makeUser({ email, password: PASSWORD });

		await signInOnWpLogin(page, email, true);
		await expectSignedIn(page, email);

		expect(await sessionDays(page)).toBe(14);
	});

	test('a length the site chose is the length', async ({ page, site, options }) => {
		await options.set({ diluxone_users_session_long_days: 21 });

		const email = freshEmail('session-chosen');
		await site.makeUser({ email, password: PASSWORD });

		await signInOnWpLogin(page, email, true);

		expect(await sessionDays(page)).toBe(21);
	});

	test.describe('the screen', () => {
		test.use({ storageState: ADMIN_STATE });

		test('shows 0 for what WordPress decides', async ({ page }) => {
			await page.goto(adminUrl('diluxone-users-security', 'sessions'));

			await expect(page.locator('input[name="diluxone_users_session_long_days"]')).toHaveValue('0');
			await expect(page.locator('input[name="diluxone_users_session_short_days"]')).toHaveValue('0');
		});
	});
});

test.describe('Safe mode (DILUXONE_USERS_SAFE_MODE)', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_safe_mode: 1,
			diluxone_users_wp_screens: 'mine',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_passkey_enabled: 1,
		});
	});

	test('wp-login.php is WordPress’s, its password signs in with no second step', async ({ page, site }) => {
		const email = freshEmail('safe');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto('/wp-login.php');
		expect(new URL(page.url()).pathname, 'not sent to the sign-in page').toMatch(/\/wp-login\.php$/);

		await signInOnWpLogin(page, email);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);
	});

	test('the sign-in page has no social button and no passkey', async ({ page, pages }) => {
		await page.goto(pages.login.url);

		await expect(ssoButton(page, 'mock')).toHaveCount(0);
		await expect(page.locator('[data-diluxone-users-passkey="login"]')).toHaveCount(0);
	});

	test.describe('the dashboard', () => {
		test.use({ storageState: ADMIN_STATE });

		test('says so on every page', async ({ page }) => {
			for (const url of ['/wp-admin/', '/wp-admin/plugins.php', adminUrl('diluxone-users-security', '2fa')]) {
				await page.goto(url);
				await expect(page.locator('[data-diluxone-users-safe-mode]'), url).toBeVisible();
			}
		});
	});

	test('off, the same settings take wp-login.php over and the notice is gone', async ({ page, site, pages, options, browser, baseURL }) => {
		await options.set({ diluxone_e2e_safe_mode: null });

		await page.goto('/wp-login.php');
		expect(page.url().startsWith(pages.login.url), 'taken over').toBe(true);

		const admin = await browser.newContext({ baseURL, storageState: ADMIN_STATE });
		const dashboard = await admin.newPage();
		await dashboard.goto('/wp-admin/');
		await expect(dashboard.locator('[data-diluxone-users-safe-mode]')).toHaveCount(0);
		await admin.close();
	});
});

test.describe('The pages a cache must not keep', () => {
	test('the sign-in, registration and account pages send no-store and define DONOTCACHEPAGE; the front page does neither', async ({ request, pages }) => {
		for (const url of [pages.login.url, pages.register.url, pages.account.url]) {
			const response = await request.get(url);

			expect(response.headers()['cache-control'] ?? '', url).toContain('no-store');
			expect(response.headers()['x-diluxone-e2e-donotcachepage'], url).toBe('1');
		}

		const home = await request.get('/');

		expect(home.headers()['cache-control'] ?? '').not.toContain('no-store');
		expect(home.headers()['x-diluxone-e2e-donotcachepage']).toBeUndefined();
	});

	test('the second step on wp-login.php is not cached either', async ({ page, site, options }) => {
		await options.set({ diluxone_users_login_page: null, diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('nocache-2fa');
		await site.makeUser({ email, password: PASSWORD });

		const answered = page.waitForResponse((response) => new URL(response.url()).searchParams.get('action') === 'diluxone_users_2fa');

		await signInOnWpLogin(page, email);

		expect((await answered).headers()['cache-control'] ?? '').toContain('no-store');
	});
});
