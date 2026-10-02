import type { Browser, Page, Response } from '@playwright/test';
import { test, expect, signInFrom, toTheHub, whoOn, SiteHandle } from './support';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, adminError, askForLink, challengeCode, challengeScreen, navigated, needsOne, openPanel, savePanel, submitPanelWithoutScript } from '../support/ui';
import { rail, sessionValueDays, siteScreen, stateIn, networkScreen } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * Security in Network Admin, driven through its own controls, and its effect
 * on whoever signs in from a site of the network.
 *
 * On a network the second step, passkeys, sessions and the proxy are one
 * decision for every site, taken in Network Admin. Each test here presses the
 * control there, reads what was stored through a site's side door — the value
 * every site reads — and then signs somebody in the way a person on /alpha/
 * or /beta/ does: through the site's door to the hub and back.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const SECURITY = 'diluxone-users-security';
const PASSWORD = 'e2e-Net-Security-1!';

const tab = (name: string) => networkScreen(NETWORK_URL, SECURITY, name);

/** Somebody new, a member of `one`, signed in from there in a browser of their own. */
async function personFrom(
	browser: Browser,
	one: SiteHandle,
	hub: SiteHandle,
	prefix: string,
	options: { role?: string; headers?: Record<string, string>; meta?: Record<string, unknown> } = {}
) {
	const email = freshEmail(prefix);

	await one.site.makeUser({ email, password: PASSWORD, role: options.role, meta: options.meta });

	const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, extraHTTPHeaders: options.headers });
	const page = await context.newPage();

	await signInFrom(page, one, hub, email, PASSWORD);

	return { email, context, page };
}

/** One step of the passkey dialogue a page has with the site. */
function passkeyStep(step: string) {
	return (response: Response) => response.url().includes('admin-ajax.php') && (response.request().postData() ?? '').includes(`step=${step}`);
}

test.describe('Network Admin › Security › Two-step verification', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.keep(['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods', 'diluxone_users_2fa_scope', 'diluxone_users_2fa_roles', 'diluxone_users_2fa_link', 'diluxone_users_2fa_remember_days']);
		await hub.set({ diluxone_users_login_method: 'both' });
	});

	test('“only some roles”, administrator ticked: an administrator of /alpha/ is asked, a subscriber of /beta/ is not', async ({
		page,
		browser,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_scope: 'all' });

		await page.goto(tab('2fa'));
		await page.locator('input[name="diluxone_users_2fa_mode"][value="required"]').check();
		await page.locator('input[name="diluxone_users_2fa_scope"][value="some"]').check();

		for (const box of await page.locator('input[name="diluxone_users_2fa_roles[]"]').all()) {
			await box.uncheck();
		}

		await page.locator('input[name="diluxone_users_2fa_roles[]"][value="administrator"]').check();
		await savePanel(page);
		await page.reload();
		await expect(page.locator('input[name="diluxone_users_2fa_scope"][value="some"]')).toBeChecked();

		for (const one of [alpha, beta]) {
			expect(await one.site.getOptions(['diluxone_users_2fa_scope', 'diluxone_users_2fa_roles']), `/${one.slug}/ reads it`).toEqual({
				diluxone_users_2fa_scope: 'some',
				diluxone_users_2fa_roles: ['administrator'],
			});
		}

		const admin = await personFrom(browser, alpha, hub, 'n2fa-admin', { role: 'administrator' });

		try {
			await expect(challengeScreen(admin.page)).toBeVisible();
			expect(await whoOn(admin.page, alpha.url)).toBeNull();
		} finally {
			await admin.context.close();
		}

		const subscriber = await personFrom(browser, beta, hub, 'n2fa-sub');

		try {
			await expect(challengeScreen(subscriber.page)).toHaveCount(0);
			expect(await whoOn(subscriber.page, beta.url)).toBe(subscriber.email);
		} finally {
			await subscriber.context.close();
		}
	});

	test('the methods: none ticked is refused by the server as well as the browser, and nothing is stored', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'] });

		const before = await alpha.site.getOptions(['diluxone_users_2fa_methods']);

		await page.goto(tab('2fa'));
		await page.locator('input[name="diluxone_users_2fa_methods[]"][value="email"]').uncheck();

		const group = needsOne(page, 'diluxone_users_2fa_methods[]');

		// The browser holds the form, and says why.
		await page.locator('[data-diluxone-users-save] .du-save__button').first().click();
		await expect(group).toHaveClass(/is-short/);

		// Sent anyway: the server refuses it.
		await submitPanelWithoutScript(page);
		await expect(adminError(page)).toBeVisible();
		expect(await alpha.site.getOptions(['diluxone_users_2fa_methods'])).toEqual(before);
	});

	test('the app ticked in Network Admin is the method every site offers', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'] });

		await page.goto(tab('2fa'));
		await page.locator('input[name="diluxone_users_2fa_methods[]"][value="totp"]').check();
		await savePanel(page);

		expect(
			((await alpha.site.getOptions(['diluxone_users_2fa_methods'])).diluxone_users_2fa_methods as string[]).sort(),
			'bug: includes/admin-2fa.php:76 intersects the ticked methods with diluxone_users_2fa_methods(), which only lists the methods already on, so the app ticked beside the e-mail is dropped without a word'
		).toEqual(['email', 'totp']);
	});

	test('somebody who came by link: “never” lets them in from /beta/, “always” asks', async ({ page, guest, hub, beta }) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_scope: 'all', diluxone_users_2fa_link: 'auto' });

		const email = freshEmail('nlink');

		await beta.site.makeUser({ email, meta: { diluxone_users_2fa_on: 1 } });

		const follow = async () => {
			const before = Date.now() / 1000;

			await guest.context().clearCookies();
			await toTheHub(guest, beta, hub);
			await askForLink(guest, guest.url(), email);
			await guest.goto(linkIn(await waitForMail(hub.site, email, { after: before })));
			await guest.waitForLoadState('domcontentloaded');
		};

		await page.goto(tab('2fa'));
		await page.locator('input[name="diluxone_users_2fa_link"][value="always"]').check();
		await savePanel(page);
		expect((await beta.site.getOptions(['diluxone_users_2fa_link'])).diluxone_users_2fa_link).toBe('always');
		expect(await stateIn(rail(page))).toBe('active');

		await follow();
		await expect(challengeScreen(guest), '“always” asks after the link').toBeVisible();

		await page.goto(tab('2fa'));
		await page.locator('input[name="diluxone_users_2fa_link"][value="never"]').check();
		await savePanel(page);
		expect((await beta.site.getOptions(['diluxone_users_2fa_link'])).diluxone_users_2fa_link).toBe('never');

		await follow();
		await expect(challengeScreen(guest)).toHaveCount(0);
		expect(await whoOn(guest, beta.url)).toBe(email);
	});

	test('remembering a browser: 0 saved in Network Admin offers no “do not ask again” on the hub’s second step', async ({
		page,
		browser,
		hub,
		alpha,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_scope: 'all', diluxone_users_2fa_remember_days: 30 });

		await page.goto(tab('2fa'));
		await page.locator('input[name="diluxone_users_2fa_remember_days"]').fill('0');
		await savePanel(page);
		expect(Number((await alpha.site.getOptions(['diluxone_users_2fa_remember_days'])).diluxone_users_2fa_remember_days)).toBe(0);

		const person = await personFrom(browser, alpha, hub, 'nremember', { meta: { diluxone_users_2fa_on: 1 } });

		try {
			await expect(challengeScreen(person.page)).toBeVisible();
			await expect(person.page.locator('input[name="diluxone_users_2fa_trust"]')).toHaveCount(0);

			await challengeCode(person.page).fill(codeIn(await waitForMail(hub.site, person.email)));
			await navigated(person.page, () => person.page.locator('form.diluxone-users-form button[type="submit"]').first().click());
			expect(await whoOn(person.page, alpha.url)).toBe(person.email);
			expect((await person.context.cookies()).filter((cookie) => cookie.name.startsWith('diluxone_users_2fa_'))).toHaveLength(0);
		} finally {
			await person.context.close();
		}
	});

	test('the Summary in Network Admin reads the network’s settings, and its rail stays on Network Admin', async ({ page, hub }) => {
		await hub.set({ diluxone_users_2fa_mode: 'off' });
		await page.goto(tab('summary'));

		const row = page.locator('table.diluxone-users-summary tbody tr').filter({ has: page.locator(`a[href*="page=${SECURITY}&tab=2fa"]`) }).first();

		expect(await stateIn(row)).toBe('off');
		await expect(row.locator('a')).toHaveAttribute('href', /\/wp-admin\/network\//);

		await hub.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_scope: 'all' });
		await page.reload();
		expect(await stateIn(row)).toBe('active');
	});
});

test.describe('Network Admin › Security › Passkeys', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.keep(['diluxone_users_passkey_enabled', 'diluxone_users_passkey_where', 'diluxone_users_passkey_verify']);
		await hub.set({ diluxone_users_passkey_enabled: 1, diluxone_users_login_method: 'both', diluxone_users_2fa_mode: 'optional' });
	});

	test('the fingerprint required and “only the device” saved there are what the hub tells a browser, and the rail names the hub', async ({
		page,
		guest,
		browser,
		hub,
		alpha,
	}) => {
		await hub.set({ diluxone_users_passkey_verify: 0, diluxone_users_passkey_where: 'any' });

		await page.goto(tab('passkeys'));
		await page.locator('input[name="diluxone_users_passkey_verify"]').check();
		await page.locator('input[name="diluxone_users_passkey_where"][value="device"]').check();
		await savePanel(page);
		const saved = await alpha.site.getOptions(['diluxone_users_passkey_verify', 'diluxone_users_passkey_where']);

		expect(Number(saved.diluxone_users_passkey_verify)).toBe(1);
		expect(saved.diluxone_users_passkey_where).toBe('device');
		expect(await stateIn(rail(page))).toBe('active');
		await expect(rail(page).locator('.du-note').first().locator('code')).toHaveText(new URL(NETWORK_URL).hostname);

		// A stranger on the hub's sign-in page.
		await toTheHub(guest, alpha, hub);

		const [login] = await Promise.all([guest.waitForResponse(passkeyStep('login-options')), guest.locator('[data-diluxone-users-passkey="login"]').click()]);

		expect((await login.json()).data.userVerification).toBe('required');

		// A member adding one on the hub's account.
		const person = await personFrom(browser, alpha, hub, 'npk');

		try {
			await person.page.goto(accountSection(hub.pages.account.url, 'security'));

			const panel = await openPanel(person.page, '[data-diluxone-users-passkey="register"]');
			const [register] = await Promise.all([
				person.page.waitForResponse(passkeyStep('register-options')),
				panel.locator('[data-diluxone-users-passkey="register"]').click(),
			]);
			const data = (await register.json()).data;

			expect(data.userVerification).toBe('required');
			expect(data.authenticatorAttachment).toBe('platform');
		} finally {
			await person.context.close();
		}
	});
});

test.describe('Network Admin › Security › Sessions and the proxy', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.keep(['diluxone_users_session_short_days', 'diluxone_users_sessions_show', 'diluxone_users_ip_header', 'diluxone_users_trusted_proxies']);
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });
	});

	test('“without it” saved there: a sign-in from /beta/ without “remember me” lasts those days', async ({ page, browser, hub, beta }) => {
		await page.goto(tab('sessions'));
		await page.locator('input[name="diluxone_users_session_short_days"]').fill('3');
		await savePanel(page);
		expect(Number((await beta.site.getOptions(['diluxone_users_session_short_days'])).diluxone_users_session_short_days)).toBe(3);

		const person = await personFrom(browser, beta, hub, 'nshort');

		try {
			const left = await sessionValueDays(person.context);

			expect(left).toBeGreaterThan(2.95);
			expect(left).toBeLessThan(3.05);
		} finally {
			await person.context.close();
		}
	});

	test('“each person sees where they are signed in” unticked there: the hub’s account has no list of sessions', async ({
		page,
		browser,
		hub,
		alpha,
	}) => {
		await hub.set({ diluxone_users_sessions_show: 1 });

		const person = await personFrom(browser, alpha, hub, 'nshow');

		try {
			await person.page.goto(accountSection(hub.pages.account.url, 'security'));
			await expect(person.page.locator('.diluxone-users-sessions')).not.toHaveCount(0);

			await page.goto(tab('sessions'));
			await page.locator('input[name="diluxone_users_sessions_show"]').uncheck();
			await savePanel(page);
			expect(Number((await alpha.site.getOptions(['diluxone_users_sessions_show'])).diluxone_users_sessions_show)).toBe(0);

			await person.page.reload();
			await expect(person.page.locator('.diluxone-users-sessions')).toHaveCount(0);
		} finally {
			await person.context.close();
		}
	});

	test('the header chosen there is the address /beta/’s report writes down; a trusted range is walked past', async ({
		page,
		browser,
		hub,
		beta,
	}) => {
		await hub.set({ diluxone_users_ip_header: '', diluxone_users_trusted_proxies: '' });

		await page.goto(tab('proxy'));
		await page.locator('select[name="diluxone_users_ip_header"]').selectOption('HTTP_CF_CONNECTING_IP');
		await savePanel(page);
		expect((await beta.site.getOptions(['diluxone_users_ip_header'])).diluxone_users_ip_header).toBe('HTTP_CF_CONNECTING_IP');

		const reported = async (email: string) => {
			await page.goto(siteScreen(beta.url, 'diluxone-users-reports', 'sessions', { s: email }));

			return (await page.locator('table.diluxone-users-list tbody tr td code').first().innerText()).trim();
		};

		const proxied = await personFrom(browser, beta, hub, 'nproxy', { headers: { 'CF-Connecting-IP': '203.0.113.8' } });

		await proxied.context.close();
		expect(await reported(proxied.email)).toBe('203.0.113.8');

		// X-Forwarded-For through an edge nobody trusts, then trusted.
		await page.goto(tab('proxy'));
		await page.locator('select[name="diluxone_users_ip_header"]').selectOption('HTTP_X_FORWARDED_FOR');
		await savePanel(page);

		const chain = { 'X-Forwarded-For': '198.51.100.10, 203.0.113.9' };
		const untrusted = await personFrom(browser, beta, hub, 'nedge', { headers: chain });

		await untrusted.context.close();
		expect(await reported(untrusted.email)).toBe('203.0.113.9');

		await page.goto(tab('proxy'));
		await page.locator('textarea[name="diluxone_users_trusted_proxies"]').fill('203.0.113.0/24');
		await savePanel(page);

		const trusted = await personFrom(browser, beta, hub, 'ntrust', { headers: chain });

		await trusted.context.close();
		expect(await reported(trusted.email)).toBe('198.51.100.10');

		// The rail of the tab says what the network sees for whoever reads it.
		await page.setExtraHTTPHeaders({ 'X-Forwarded-For': '198.51.100.11, 203.0.113.12' });
		await page.goto(tab('proxy'));
		await expect(rail(page).locator('.du-note').first().locator('code')).toHaveText('198.51.100.11');
	});
});
