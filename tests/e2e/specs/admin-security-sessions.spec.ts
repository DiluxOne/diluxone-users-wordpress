import type { BrowserContext, Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, fillCredentials, navigated, savePanel, submitPanelWithoutScript } from '../support/ui';
import { personSignedIn, rail, stateIn, summaryRow } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Security › Sessions and Security › Behind a proxy, driven through their
 * own controls.
 *
 * A session's length is read where it is kept: the expiry WordPress writes
 * into the session cookie's own value, which is the session's even when the
 * cookie itself only lives as long as the browser. The proxy is read where
 * the site uses it: the address the report of open sessions and the activity
 * log write down for somebody whose browser came through "a proxy" — a header
 * the test's browser sends — on a connection Docker makes from a private
 * address, which the site always trusts.
 */

test.use({ storageState: ADMIN_STATE });

const SECURITY = 'diluxone-users-security';
const PASSWORD = 'e2e-Sessions-Ui-1!';

/** The expiry WordPress wrote into the session cookie's value, in days from now. */
async function sessionValueDays(context: BrowserContext): Promise<number> {
	const cookie = (await context.cookies()).find((one) => one.name.startsWith('wordpress_logged_in_'));

	expect(cookie, 'a session cookie').toBeTruthy();

	// login|expiration|token|hmac — the expiration is the session's own.
	const expiration = Number(decodeURIComponent(cookie!.value).split('|')[1]);

	return (expiration - Date.now() / 1000) / 86_400;
}

/** The address the report of open sessions shows for somebody. */
async function reportedIp(page: Page, email: string): Promise<string> {
	await page.goto(adminUrl('diluxone-users-reports', 'sessions', { s: email }));

	const row = page.locator('table.diluxone-users-list tbody tr').filter({ has: page.locator('.diluxone-users-list__mail', { hasText: email }) });

	await expect(row).toHaveCount(1);

	return (await row.locator('td code').innerText()).trim();
}

/** The address the activity log wrote on somebody's sign-in. */
async function loggedIp(page: Page, email: string): Promise<string> {
	await page.goto(adminUrl('diluxone-users-reports', 'activity', { s: email, event: 'signed_in' }));

	const row = page.locator('tr[data-diluxone-users-event="signed_in"]').first();

	await expect(row).toBeVisible();

	return (await row.locator('td code').innerText()).trim();
}

test.describe('Security › Sessions', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_session_long_days', 'diluxone_users_session_short_days', 'diluxone_users_sessions_show']);
		await options.set({ diluxone_users_2fa_mode: 'off' });
	});

	test('“without it”: three days typed in, and a sign-in without “remember me” is a session of three days', async ({
		page,
		browser,
		baseURL,
		site,
	}) => {
		await page.goto(adminUrl(SECURITY, 'sessions'));
		await page.locator('input[name="diluxone_users_session_short_days"]').fill('3');
		await savePanel(page);
		await page.reload();
		await expect(page.locator('input[name="diluxone_users_session_short_days"]')).toHaveValue('3');
		expect(Number((await site.getOptions(['diluxone_users_session_short_days'])).diluxone_users_session_short_days)).toBe(3);

		const email = freshEmail('short-days');

		await site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();

		try {
			await person.goto('/wp-login.php');
			await fillCredentials(person, email, PASSWORD);
			await expect(person.locator('#rememberme')).not.toBeChecked();
			await navigated(person, () => person.locator('#wp-submit').click());
			await expectSignedIn(person, email);

			const left = await sessionValueDays(context);

			expect(left, 'three days, not WordPress’s two').toBeGreaterThan(2.95);
			expect(left).toBeLessThan(3.05);
		} finally {
			await context.close();
		}
	});

	test('“each person sees where they are signed in”: the summary follows the box, and counts who is signed in', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const person = await personSignedIn(browser, baseURL!, site, pages.login.url, 'sessions-show');

		try {
			await page.goto(adminUrl(SECURITY, 'sessions'));
			await page.locator('input[name="diluxone_users_sessions_show"]').uncheck();
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_sessions_show'])).diluxone_users_sessions_show)).toBe(0);

			await page.goto(adminUrl(SECURITY, 'summary'));
			expect(await stateIn(summaryRow(page, SECURITY, 'sessions', 1)), 'their own sessions: off').toBe('off');

			await page.goto(adminUrl(SECURITY, 'sessions'));
			await page.locator('input[name="diluxone_users_sessions_show"]').check();
			await savePanel(page);

			await page.goto(adminUrl(SECURITY, 'summary'));
			expect(await stateIn(summaryRow(page, SECURITY, 'sessions', 1)), 'their own sessions: on').toBe('active');

			// Somebody is signed in (this person, at least): the row says so,
			// with a number, and leads to the report.
			const now = summaryRow(page, 'diluxone-users-reports', 'sessions');

			expect(await stateIn(now)).toBe('active');
			await expect(now.locator('.diluxone-users-summary__detail')).toContainText(/\d/);
			await navigated(page, () => now.locator('a').click());
			expect(new URL(page.url()).searchParams.get('tab')).toBe('sessions');
			await expect(page.locator('table.diluxone-users-list')).toBeVisible();
		} finally {
			await person.context.close();
		}
	});

	test('the rail leads to the report of open sessions and to the account’s sections', async ({ page }) => {
		await page.goto(adminUrl(SECURITY, 'sessions'));
		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-reports&tab=sessions"]').click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-reports');

		await page.goto(adminUrl(SECURITY, 'sessions'));
		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-account&tab=sections"]').click());
		expect(new URL(page.url()).searchParams.get('tab')).toBe('sections');
	});
});

test.describe('Security › Behind a proxy', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_ip_header', 'diluxone_users_trusted_proxies']);
		await options.set({
			diluxone_users_ip_header: '',
			diluxone_users_trusted_proxies: '',
			diluxone_users_2fa_mode: 'off',
			diluxone_users_log_levels: ['access'],
		});
	});

	test('the header chosen is the address the site writes down, a header not chosen is not, and “None” reads the connection', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		// CF-Connecting-IP and not X-Forwarded-For: the container's Apache
		// already rewrites the connection's address from X-Forwarded-For
		// (mod_remoteip, trusting private ranges), so with that header
		// "None" and "X-Forwarded-For" would read the same address and the
		// test could not tell them apart. Nothing in front of this site
		// touches CF-Connecting-IP.
		await page.goto(adminUrl(SECURITY, 'proxy'));
		await page.locator('select[name="diluxone_users_ip_header"]').selectOption('HTTP_CF_CONNECTING_IP');
		await savePanel(page);
		await page.reload();
		await expect(page.locator('select[name="diluxone_users_ip_header"]')).toHaveValue('HTTP_CF_CONNECTING_IP');
		expect((await site.getOptions(['diluxone_users_ip_header'])).diluxone_users_ip_header).toBe('HTTP_CF_CONNECTING_IP');

		// Through "the proxy": the address it forwarded is the visitor's.
		const proxied = await personSignedIn(browser, baseURL!, site, pages.login.url, 'proxy-cf', {
			headers: { 'CF-Connecting-IP': '203.0.113.7' },
		});

		await proxied.context.close();
		expect(await reportedIp(page, proxied.email), 'the sessions report').toBe('203.0.113.7');
		expect(await loggedIp(page, proxied.email), 'the activity log').toBe('203.0.113.7');

		// Another header, which the site was not told to read, is somebody typing.
		const typed = await personSignedIn(browser, baseURL!, site, pages.login.url, 'proxy-other', {
			headers: { 'X-Real-IP': '198.51.100.5' },
		});

		await typed.context.close();

		const connection = await reportedIp(page, typed.email);

		expect(connection).not.toBe('198.51.100.5');
		expect(connection).not.toBe('');

		// None: the connection, whatever the header says.
		await page.goto(adminUrl(SECURITY, 'proxy'));
		await page.locator('select[name="diluxone_users_ip_header"]').selectOption('');
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_ip_header'])).diluxone_users_ip_header).toBe('');

		const ignored = await personSignedIn(browser, baseURL!, site, pages.login.url, 'proxy-none', {
			headers: { 'CF-Connecting-IP': '203.0.113.7' },
		});

		await ignored.context.close();
		expect(await reportedIp(page, ignored.email), 'the connection, as for anybody').toBe(connection);
	});

	test('a trusted proxy is walked past: the address before it is the visitor’s, and the list is kept as typed', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_ip_header: 'HTTP_X_FORWARDED_FOR' });

		// A visitor, then a CDN edge the site has not been told about. The
		// container's Apache hands the site that edge as the connection
		// (mod_remoteip takes the right end of X-Forwarded-For), so what is
		// proven here is the plugin's half: a connection from an address it
		// does not trust has its headers ignored, and the list is what makes
		// it trusted — at which point the hop before it is the visitor.
		const chain = { 'X-Forwarded-For': '198.51.100.9, 203.0.113.7' };

		const before = await personSignedIn(browser, baseURL!, site, pages.login.url, 'proxy-untrusted', { headers: chain });

		await before.context.close();
		expect(await reportedIp(page, before.email), 'an edge nobody trusts is the visitor').toBe('203.0.113.7');

		await page.goto(adminUrl(SECURITY, 'proxy'));
		await page.locator('textarea[name="diluxone_users_trusted_proxies"]').fill('10.0.0.0/8\n203.0.113.0/24');
		await savePanel(page);
		await page.reload();
		await expect(page.locator('textarea[name="diluxone_users_trusted_proxies"]')).toHaveValue('10.0.0.0/8\n203.0.113.0/24');

		const after = await personSignedIn(browser, baseURL!, site, pages.login.url, 'proxy-trusted', { headers: chain });

		await after.context.close();
		expect(await reportedIp(page, after.email), 'a trusted edge is walked past').toBe('198.51.100.9');

		// With the list alone and no header the summary still reads it as set up.
		await options.set({ diluxone_users_ip_header: '' });
		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, 'proxy'))).toBe('active');
	});

	test('a header that is not on the list is not saved, whatever the form sends', async ({ page, site, options }) => {
		await options.set({ diluxone_users_ip_header: 'HTTP_X_FORWARDED_FOR' });
		await page.goto(adminUrl(SECURITY, 'proxy'));

		await page.locator('select[name="diluxone_users_ip_header"]').evaluate((select: HTMLSelectElement) => {
			const forged = document.createElement('option');

			forged.value = 'HTTP_X_EVIL';
			forged.textContent = 'X-Evil';
			select.appendChild(forged);
			select.value = 'HTTP_X_EVIL';
		});
		await submitPanelWithoutScript(page);

		expect((await site.getOptions(['diluxone_users_ip_header'])).diluxone_users_ip_header).toBe('');
		await expect(page.locator('select[name="diluxone_users_ip_header"]')).toHaveValue('');
	});

	test('the rail says which address the site sees for whoever is reading it', async ({ page, options }) => {
		await options.set({ diluxone_users_ip_header: 'HTTP_X_FORWARDED_FOR' });

		await page.setExtraHTTPHeaders({ 'X-Forwarded-For': '198.51.100.23' });
		await page.goto(adminUrl(SECURITY, 'proxy'));
		await expect(rail(page).locator('.du-note').first().locator('code')).toHaveText('198.51.100.23');

		// And the summary row reads the same address and the header's name.
		await page.goto(adminUrl(SECURITY, 'summary'));

		const proxy = summaryRow(page, SECURITY, 'proxy');

		expect(await stateIn(proxy)).toBe('active');
		await expect(proxy.locator('code').filter({ hasText: '198.51.100.23' })).toHaveCount(1);
		await expect(proxy.locator('code').filter({ hasText: 'X-Forwarded-For' })).toHaveCount(1);
	});
});
