import type { Browser, Page } from '@playwright/test';
import { test, expect, signInFrom, toTheHub, whoOn, SiteHandle } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, adminError, askForLink, challengeScreen, needsOne, savePanel, submitPanelWithoutScript } from '../support/ui';
import { rail, sessionValueDays, siteScreen, stateIn, networkScreen } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * Security in Network Admin, driven through its own controls, and its effect
 * on whoever signs in from a site of the network.
 *
 * On a network the second step, sessions and the proxy are one
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

test.describe('Network Admin › Security › Two-step verification', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.keep(['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods', 'diluxone_users_2fa_link']);
		await hub.set({ diluxone_users_login_method: 'both' });
	});

	test('“required” saved in Network Admin is asked of everybody, from /alpha/ and from /beta/, and Users+ Pro’s cards sit on the tab', async ({
		page,
		browser,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'] });

		await page.goto(tab('2fa'));

		for (const where of ['security/2fa/roles', 'security/2fa/app', 'security/2fa/remember']) {
			await expect(page.locator(`[data-diluxone-users-pro="${where}"]`), where).toBeVisible();
		}

		await page.locator('input[name="diluxone_users_2fa_mode"][value="required"]').check();
		await savePanel(page);

		for (const one of [alpha, beta]) {
			expect((await one.site.getOptions(['diluxone_users_2fa_mode'])).diluxone_users_2fa_mode, `/${one.slug}/ reads it`).toBe('required');
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
			await expect(challengeScreen(subscriber.page)).toBeVisible();
			expect(await whoOn(subscriber.page, beta.url)).toBeNull();
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

	test('somebody who came by link from /beta/ is asked, even where “work it out” was saved, and “Let it decide” is a Pro preview', async ({
		page,
		guest,
		hub,
		beta,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_link: 'auto' });

		const email = freshEmail('nlink-asked');

		await beta.site.makeUser({ email, meta: { diluxone_users_2fa_on: 1 } });

		await page.goto(tab('2fa'));
		await expect(page.locator('input[name="diluxone_users_2fa_link"]'), 'no choice of its own').toHaveCount(0);
		await expect(page.locator('[data-diluxone-users-pro="security/2fa/link"] input')).toBeDisabled();
		expect(await stateIn(rail(page))).toBe('active');

		const before = Date.now() / 1000;

		await guest.context().clearCookies();
		await toTheHub(guest, beta, hub);
		await askForLink(guest, guest.url(), email);
		await guest.goto(linkIn(await waitForMail(hub.site, email, { after: before })));
		await guest.waitForLoadState('domcontentloaded');
		await expect(challengeScreen(guest), 'asked after the link').toBeVisible();
	});

	test('the Summary in Network Admin reads the network’s settings, and its rail stays on Network Admin', async ({ page, hub }) => {
		await hub.set({ diluxone_users_2fa_mode: 'off' });
		await page.goto(tab('summary'));

		const row = page.locator('table.diluxone-users-summary tbody tr').filter({ has: page.locator(`a[href*="page=${SECURITY}&tab=2fa"]`) }).first();

		expect(await stateIn(row)).toBe('off');
		await expect(row.locator('a')).toHaveAttribute('href', /\/wp-admin\/network\//);

		await hub.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'] });
		await page.reload();
		expect(await stateIn(row)).toBe('active');
	});
});

/*
 * Passkeys are Users+ Pro's: no tab of their own in Network Admin, and under
 * the Summary's table a picture of what it adds, which the network's own
 * previews setting takes away.
 */
test.describe('Network Admin › Security › Summary: what Users+ Pro adds', () => {
	test('no Passkeys tab, and a picture of them under the table while the previews are on', async ({ page, hub }) => {
		await hub.keep(['diluxone_users_pro_previews']);
		await hub.set({ diluxone_users_pro_previews: 1 });

		await page.goto(tab('summary'));

		await expect(page.locator('.nav-tab[href*="tab=passkeys"]')).toHaveCount(0);

		const pro = page.locator('[data-diluxone-users-pro="security/summary/more"]');

		await expect(pro).toHaveCount(1);
		await expect(pro, 'a row of the one table').toHaveClass(/du-pro--row/);
		await expect(page.locator('table.diluxone-users-summary tr').last(), 'after the real rows').toHaveAttribute('data-diluxone-users-pro', 'security/summary/more');
		await expect(pro.locator('[name]'), 'nothing in it is sent').toHaveCount(0);
		await expect(page.locator('table.diluxone-users-summary'), 'one table').toHaveCount(1);

		await hub.set({ diluxone_users_pro_previews: 0 });
		await page.reload();
		await expect(page.locator('[data-diluxone-users-pro]')).toHaveCount(0);
		await expect(page.locator('#wpbody-content')).not.toContainText(/passkey/i);
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
		await expect(rail(page).locator('.du-state').first().locator('code')).toHaveText('198.51.100.11');
	});
});
