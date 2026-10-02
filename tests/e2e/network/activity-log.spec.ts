import type { Browser, Page } from '@playwright/test';
import { test, expect, whoOn, SiteHandle } from './support';
import { wp } from '../support/cli';
import { adminUrl, answeringDialog, fillCredentials, navigated } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * A network's activity log, end to end: one table, each row with its site.
 *
 * Somebody signs in on /alpha/, somebody else on /beta/. /alpha/'s report has
 * the first and not the second; Network Admin's report has both, says which
 * site each was on, and narrows to one site. Emptying it on /alpha/ asks first
 * and takes /alpha/'s rows and nobody else's; emptying it in Network Admin
 * asks first and takes everybody's.
 *
 * Every hold is an id, a name or a data attribute: the sites run in Spanish.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Network-1!';

/** The rows a report drew, all of them or one site's. */
function rows(page: Page, site?: string) {
	return page.locator(`table[data-diluxone-users-log] tbody tr[data-diluxone-users-event]${site ? `[data-diluxone-users-site="${site}"]` : ''}`);
}

/** A site's own report, pinned to what this test did. */
function siteReport(one: SiteHandle, s: string): string {
	return `${one.url.replace(/\/$/, '')}${adminUrl('diluxone-users-reports', 'activity', { s })}`;
}

/** Network Admin's report, pinned the same way. */
function networkReport(s: string, site?: string): string {
	return `${NETWORK_URL}${adminUrl('diluxone-users-reports', 'network-activity', site ? { s, site } : { s }).replace('/wp-admin/', '/wp-admin/network/')}`;
}

/** A site's id, as WordPress numbers it. */
function idOf(one: SiteHandle): string {
	return wp(['eval', 'echo get_current_blog_id();'], one.url).trim();
}

/**
 * Somebody signs in on one site, in a browser of their own.
 *
 * Through the site's own wp-login.php, the emergency door each site keeps: a
 * sign-in through the hub is the hub's, and is in the hub's rows. What this
 * file is about is a row that belongs to /alpha/ or to /beta/.
 */
async function signInOn(browser: Browser, one: SiteHandle, email: string): Promise<void> {
	// Explicitly nobody: a new context in this file would inherit the
	// network administrator's session.
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
	const person = await context.newPage();

	await person.goto(`${one.url}wp-login.php?diluxone-users-admin=1`);
	await fillCredentials(person, email, PASSWORD);
	await navigated(person, () => person.locator('#wp-submit').click());
	await person.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
	expect(await whoOn(person, one.url)).toBe(email);
	await context.close();
}

/** One person signed in on /alpha/ and another on /beta/, both under one tag. */
async function twoSignIns(browser: Browser, hub: SiteHandle, alpha: SiteHandle, beta: SiteHandle, tag: string) {
	await hub.set({ diluxone_users_log_levels: ['access'], diluxone_users_login_method: 'both' });

	const here = `${tag}-alpha@e2e.test`;
	const there = `${tag}-beta@e2e.test`;

	await alpha.site.makeUser({ email: here, password: PASSWORD });
	await beta.site.makeUser({ email: there, password: PASSWORD });

	await signInOn(browser, alpha, here);
	await signInOn(browser, beta, there);

	return { here, there, alphaId: idOf(alpha), betaId: idOf(beta) };
}

test.describe('The network’s activity log', () => {
	test('a site’s report has its own rows; Network Admin’s has every site’s, says which, and narrows to one', async ({
		page,
		browser,
		hub,
		alpha,
		beta,
	}) => {
		const tag = `log-${Date.now().toString(36)}`;
		const { here, there, alphaId, betaId } = await twoSignIns(browser, hub, alpha, beta, tag);

		// /alpha/'s report: the sign-in made there, and not the one on /beta/.
		await page.goto(siteReport(alpha, tag));
		await expect(rows(page)).toHaveCount(1);
		await expect(rows(page, alphaId)).toContainText(here);
		await expect(page.locator('select[name="site"], input[name="site"]'), 'a site has no site filter').toHaveCount(0);

		await page.goto(siteReport(beta, here));
		await expect(rows(page), 'somebody who signed in on /alpha/, on /beta/’s report').toHaveCount(0);

		// Network Admin: both, each with its site.
		await page.goto(networkReport(tag));
		await expect(rows(page)).toHaveCount(2);
		await expect(rows(page, alphaId)).toContainText(here);
		await expect(rows(page, betaId)).toContainText(there);

		// Narrowed to /beta/, through the filter a person uses.
		await page.locator('#diluxone-users-log-site').selectOption(betaId);
		await Promise.all([page.waitForURL(/[?&]site=/), page.locator('form.diluxone-users-search [type="submit"]').click()]);
		await expect(rows(page)).toHaveCount(1);
		await expect(rows(page, betaId)).toHaveCount(1);

		// And the Site cell of a row is the same filter, one click away.
		await page.goto(networkReport(tag));
		await Promise.all([page.waitForURL(new RegExp(`[?&]site=${alphaId}`)), rows(page, alphaId).locator('td').nth(1).locator('a').click()]);
		await expect(rows(page)).toHaveCount(1);
		await expect(rows(page, alphaId)).toHaveCount(1);
	});

	test('emptying it on a site asks first and takes that site’s rows only; in Network Admin, everybody’s', async ({
		page,
		browser,
		hub,
		alpha,
		beta,
	}) => {
		const tag = `empty-${Date.now().toString(36)}`;
		const { alphaId, betaId } = await twoSignIns(browser, hub, alpha, beta, tag);

		// On /alpha/, with its rows.
		await page.goto(siteReport(alpha, ''));
		const siteEmpty = page.locator('a[href*="action=diluxone_users_log_empty"]');

		await answeringDialog(page, 'dismiss', () => siteEmpty.click());
		await page.goto(networkReport(tag));
		await expect(rows(page), '“No” deletes nothing').toHaveCount(2);

		await page.goto(siteReport(alpha, ''));
		await answeringDialog(page, 'accept', () => Promise.all([page.waitForURL(/diluxone-users-emptied=/), siteEmpty.click()]));

		await page.goto(networkReport(tag));
		await expect(rows(page, alphaId), '/alpha/’s rows went').toHaveCount(0);
		await expect(rows(page, betaId), '/beta/’s stayed').toHaveCount(1);

		// In Network Admin, beside every site's rows.
		const networkEmpty = page.locator('a[href*="action=diluxone_users_log_empty_network"]');

		await answeringDialog(page, 'dismiss', () => networkEmpty.click());
		await page.goto(networkReport(tag));
		await expect(rows(page), '“No” deletes nothing').toHaveCount(1);

		await answeringDialog(page, 'accept', () => Promise.all([page.waitForURL(/diluxone-users-emptied=/), networkEmpty.click()]));
		expect(page.url(), 'back in Network Admin').toContain('/wp-admin/network/');

		await page.goto(networkReport(''));
		await expect(rows(page)).toHaveCount(0);
	});

	test('a site’s report carries the button for its own rows, never the network’s', async ({ page, alpha }) => {
		await page.goto(siteReport(alpha, ''));

		await expect(page.locator('a[href*="action=diluxone_users_log_empty_network"]')).toHaveCount(0);
		await expect(page.locator('.nav-tab[href*="tab=network-activity"]'), 'nor the network’s report').toHaveCount(0);
	});
});
