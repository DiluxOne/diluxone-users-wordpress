import { Browser, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { codeIn, freshEmail, waitForMail } from '../support/api';
import { accountSection, challengeScreen, navigated, notice, openAllPanels, registerScreen, signInWithPassword, ssoButton } from '../support/ui';
import { MOCK_OFF, MOCK_ON, answerChallenge, catchCallback, dropSession } from '../support/signin';

/**
 * Signing in with a social account, off the plain path.
 *
 * `sso.spec.ts` walks the round trip that works and the H-01/H-02 refusals.
 * These are the ways the trip can be tampered with or end somewhere else: a
 * return replayed, a return opened in another browser, a network switched off
 * while the person was away, an identity another account already holds, a
 * door the site closed by its rules — and what a social sign-in sets off
 * afterwards: the second step, the security mail, the new-device notice.
 */

const PASSWORD = 'e2e-Social-Ways-1!';

/** Starts the trip from the sign-in page's button. */
async function pressMock(page: Page, url: string): Promise<void> {
	await page.goto(url);
	await expect(ssoButton(page, 'mock')).toBeVisible();
	await ssoButton(page, 'mock').click();
}

test.describe('A social return, tampered with', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('the same return played twice opens one session: the second lands on the social error', async ({ page, site, pages }) => {
		const email = freshEmail('sso-replay');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		let callback = '';
		page.on('request', (request) => {
			if (/\/sso\/mock\/\?(.*&)?code=/.test(request.url())) {
				callback = request.url();
			}
		});

		await pressMock(page, pages.login.url);
		await expectSignedIn(page, email);
		expect(callback, 'the provider came back with a code and a state').toMatch(/state=/);
		expect(await site.wpLogins(email)).toBe(1);

		// Signed out, but the browser keeps the trip's own cookie: what is
		// refused is the state having been spent, not a stranger's browser.
		await dropSession(page.context());
		await expectSignedOut(page);

		await page.goto(callback);

		expect(stateOf(page.url()), 'the replay lands on the social error').toBe('social');
		expect(page.url().startsWith(pages.login.url)).toBe(true);
		await expect(page.locator('[data-diluxone-users-message="login_social"]')).toHaveClass(/diluxone-users-notice--error/);
		await expectSignedOut(page);
		expect(await site.wpLogins(email), 'no second sign-in was announced').toBe(1);
	});

	test('a return opened in another browser, which never left, signs nobody in and makes no account', async ({ page, guest, site, pages }) => {
		const email = freshEmail('sso-otherbrowser');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		// The person's browser leaves and its return is caught on the way in:
		// the address is what a link in a message would hand to somebody else.
		await page.goto(pages.login.url);
		const callback = await catchCallback(page);

		await guest.goto(callback);

		expect(stateOf(guest.url())).toBe('social');
		await expect(guest.locator('[data-diluxone-users-message="login_social"]')).toBeVisible();
		await expectSignedOut(guest);
		await expectSignedOut(page);
		expect((await site.user(email)).exists, 'no account was made from a return nobody can vouch for').toBe(false);
	});

	test('a network switched off while the person was at the provider signs nobody in on the way back', async ({ page, site, pages, options }) => {
		const email = freshEmail('sso-offmidway');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		await page.goto(pages.login.url);
		const callback = await catchCallback(page);

		await options.set({ diluxone_users_sso: MOCK_OFF });
		await page.goto(callback);

		expect(stateOf(page.url())).toBe('social');
		await expectSignedOut(page);
		expect((await site.user(email)).exists).toBe(false);
	});

	test('an address under /sso/ for a network that is not there, or not set up, lands on the social error', async ({ page, pages }) => {
		for (const route of ['/sso/nonexistent/', '/sso/google/?diluxone_users_go=1']) {
			await page.goto(route);

			expect(stateOf(page.url()), route).toBe('social');
			expect(page.url().startsWith(pages.login.url), `${route} lands on the sign-in page`).toBe(true);
		}
	});

	test('a network with credentials but switched off sends nobody to the provider', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_sso: MOCK_OFF });

		const left: string[] = [];
		page.on('request', (request) => {
			if (request.url().includes('/oauth/authorize')) {
				left.push(request.url());
			}
		});

		await page.goto(pages.login.url);
		await expect(ssoButton(page, 'mock'), 'no button for a network that is off').toHaveCount(0);

		await page.goto('/sso/mock/?diluxone_users_go=1');

		expect(stateOf(page.url())).toBe('social');
		expect(left, 'the browser never went to the provider').toEqual([]);
	});

	test('one machine starting trip after trip is stopped before the provider, once over the ceiling', async ({ page, options }) => {
		await options.set({ diluxone_e2e_login_burst: 2 });

		const starts: string[] = [];

		for (let n = 0; n < 3; n++) {
			const response = await page.request.get('/sso/mock/?diluxone_users_go=1', { maxRedirects: 0 });

			expect(response.status(), `trip ${n + 1} is a redirect`).toBe(302);
			starts.push(response.headers()['location'] ?? '');
		}

		expect(starts[0], 'the first trip goes to the provider').toContain('/oauth/authorize');
		expect(starts[1], 'and the second').toContain('/oauth/authorize');
		expect(stateOf(starts[2]), 'the third is told no, and goes nowhere').toBe('social');
	});
});

test.describe('Who a social identity opens', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_sso_scope: 'all',
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('with linking by e-mail off, a verified address of an existing account does not open it', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_sso_link_by_email: 0 });

		const email = freshEmail('sso-nolink');
		const existing = await site.makeUser({ email, password: PASSWORD });

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await pressMock(page, pages.login.url);

		expect(stateOf(page.url())).toBe('social');
		await expectSignedOut(page);

		const after = await site.user(email);
		expect(after.id, 'the same account, untouched').toBe(existing.id);
		expect(after.meta.diluxone_users_sso_mock, 'nothing linked').toBe('');
	});

	test('an identity already linked opens its account, whatever address the network now gives', async ({ page, site, pages }) => {
		const email = freshEmail('sso-linked');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: 'mock|kept-identity' } });

		// The network now says another address, and does not vouch for it: the
		// identity is what was linked, not the address.
		await site.setIdentity({ sub: 'mock|kept-identity', email: freshEmail('elsewhere'), email_verified: false });
		await pressMock(page, pages.login.url);

		await expectSignedIn(page, email);
	});

	test('with social sign-in for some roles, a newcomer whose role would not be one of them gets no account', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_sso_scope: 'some',
			diluxone_users_sso_roles: ['editor'],
			diluxone_users_login_role: 'subscriber',
		});

		const email = freshEmail('sso-norole');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await pressMock(page, pages.login.url);

		expect(stateOf(page.url())).toBe('social');
		await expectSignedOut(page);
		expect((await site.user(email)).exists, 'no account it could not then use').toBe(false);
	});

	test('the registration form offers the social buttons, and one of them makes the account', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_register_form: 1, diluxone_users_login_register: 1 });

		const email = freshEmail('sso-register');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true, given_name: 'Ada' });

		await page.goto(pages.register.url);
		await expect(registerScreen(page)).toBeVisible();
		await expect(registerScreen(page).locator('a.diluxone-users-social--mock')).toBeVisible();

		await registerScreen(page).locator('a.diluxone-users-social--mock').click();

		await expectSignedIn(page, email);
		const made = await site.user(email);
		expect(made.meta.diluxone_users_sso_mock).toBe(`mock|${email}`);
		expect(made.meta.first_name).toBe('Ada');
	});
});

test.describe('Linking from the account, off the plain path', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_2fa_mode: 'off',
		});
	});

	/** Signs in with a password and opens the linked-networks section. */
	async function toAccounts(page: Page, pages: any, email: string): Promise<void> {
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);
		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);
	}

	test('an identity another account already holds is not linked: back with “taken”, both accounts as they were', async ({ page, site, pages }) => {
		const owner = freshEmail('sso-owner');
		const other = freshEmail('sso-taker');

		await site.makeUser({ email: owner, password: PASSWORD, meta: { diluxone_users_sso_mock: 'mock|shared' } });
		await site.makeUser({ email: other, password: PASSWORD });
		await site.setIdentity({ sub: 'mock|shared', email: owner, email_verified: true });

		await toAccounts(page, pages, other);

		const row = page.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' });
		await Promise.all([page.waitForURL(/diluxone-users=taken/), row.locator('a.diluxone-users-button').click()]);

		await expect(page.locator('.diluxone-users-accounts .diluxone-users-notice--error')).toBeVisible();
		await expectSignedIn(page, other);
		expect((await site.user(other)).meta.diluxone_users_sso_mock, 'nothing written on the second account').toBe('');
		expect((await site.user(owner)).meta.diluxone_users_sso_mock, 'the first keeps it').toBe('mock|shared');
		expect((await site.mail(other)).length, 'no security mail about a link that was not made').toBe(0);
	});

	test('linking says so on the account and mails the owner; unlinking mails them again', async ({ page, site, pages }) => {
		const email = freshEmail('sso-mails');

		await site.makeUser({ email, password: PASSWORD });
		await site.setIdentity({ sub: 'mock|mailed', email: freshEmail('mailed-elsewhere'), email_verified: true });

		await toAccounts(page, pages, email);
		await site.clearMail();

		const row = page.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' });
		await Promise.all([page.waitForURL(/diluxone-users=linked/), row.locator('a.diluxone-users-button').click()]);

		await expect(page.locator('.diluxone-users-accounts .diluxone-users-notice--ok')).toBeVisible();
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('mock|mailed');

		const linked = await waitForMail(site, email);
		expect(linked.body, 'the security notice names the network').toContain('Mock');

		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);
		const linkedRow = page.locator('.diluxone-users-linked__item.is-linked').filter({ hasText: 'Mock' });
		await navigated(page, () => linkedRow.locator('button[type="submit"]').click());

		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('');
		await expect.poll(async () => (await site.mail(email)).length, { message: 'a second notice, for the unlink' }).toBe(2);
		expect((await site.mail(email))[1].body).toContain('Mock');
	});

	test('an unlink posted without the form’s nonce leaves the network linked', async ({ page, site, pages }) => {
		const email = freshEmail('sso-unlink-forged');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: 'mock|stays' } });
		await toAccounts(page, pages, email);
		await site.clearMail();

		const response = await page.request.post('/wp-admin/admin-post.php', {
			form: { action: 'diluxone_users_sso_unlink', diluxone_users_provider: 'mock', _wpnonce: 'forged' },
			maxRedirects: 0,
			failOnStatusCode: false,
		});

		expect(response.status(), 'WordPress refuses a failed nonce').toBe(403);
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('mock|stays');
		expect(await site.mail(email)).toEqual([]);
	});
});

test.describe('What a social sign-in sets off', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_link_by_email: 1,
		});
	});

	test('with the second step required, a social sign-in stops at the code, and the code finishes it', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_remember_days: 0,
		});

		const email = freshEmail('sso-2fa');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: `mock|${email}` } });
		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		await pressMock(page, pages.login.url);

		await expect(challengeScreen(page)).toBeVisible();
		await expectSignedOut(page);
		expect(await site.wpLogins(email), 'nothing announced before the code').toBe(0);

		await answerChallenge(page, codeIn(await waitForMail(site, email)));

		await expectSignedIn(page, email);
		expect(await site.wpLogins(email), 'announced once, after the code').toBe(1);
	});

	test('a second device signing in with the network is announced, naming the browser', async ({ browser, baseURL, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('sso-device');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: `mock|${email}` } });
		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		const device = async (agent: string) => {
			const context = await (browser as Browser).newContext({ baseURL, userAgent: agent, storageState: undefined });
			const page = await context.newPage();

			await pressMock(page, pages.login.url);
			await expectSignedIn(page, email);
			await context.close();
		};

		await device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36');
		expect(await site.mail(email), 'the first device is not news').toEqual([]);

		await device('Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0');

		const mails = await site.mail(email);
		expect(mails.length, 'the second device is announced, once').toBe(1);
		expect(mails[0].body, 'naming the browser it came from').toContain('Firefox');
	});
});

test.describe('The buttons on the real page', () => {
	test('skin, shape, contents, rows and words reach the sign-in page’s buttons', async ({ page, pages, options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_button_skin: 'dark',
			diluxone_users_sso_button_shape: 'pill',
			diluxone_users_sso_button_show: 'icon-text',
			diluxone_users_sso_button_columns: 1,
			diluxone_users_sso_button_text: 'E2E in with %s',
		});

		await page.goto(pages.login.url);

		const row = page.locator('.diluxone-users-socials').filter({ has: ssoButton(page, 'mock') });
		await expect(row).toHaveClass(/diluxone-users-socials--dark/);
		await expect(row).toHaveClass(/diluxone-users-socials--pill/);
		await expect(row).toHaveClass(/diluxone-users-socials--icon-text/);
		await expect(row).toHaveClass(/diluxone-users-socials--cols-1/);
		await expect(ssoButton(page, 'mock').locator('.diluxone-users-social__text')).toHaveText('E2E in with Mock');

		await options.set({ diluxone_users_sso_button_show: 'icon' });
		await page.goto(pages.login.url);

		await expect(row).toHaveClass(/diluxone-users-socials--icon(\s|$)/);
		// Logo only, and still named for a screen reader.
		await expect(ssoButton(page, 'mock').locator('.diluxone-users-social__text')).toHaveText('E2E in with Mock');
		const width = await ssoButton(page, 'mock').evaluate((a) => a.getBoundingClientRect().width);
		expect(width, 'a little square, not a bar').toBeLessThan(80);
	});
});
