import { test, expect } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, signInWithPassword } from '../support/ui';

/**
 * "Download your data" on a network.
 *
 * Each site keeps its exports in its own uploads, so the file a site of the
 * network makes on confirmation is served from that site, and the account on
 * that site is the one that offers it.
 */

const PASSWORD = 'e2e-Network-1!';

test('confirming a copy on a site of the network leaves the file ready there', async ({ page, alpha }) => {
	await alpha.set({ diluxone_users_privacy_export: 1, diluxone_users_2fa_mode: 'off' });

	const email = freshEmail('net-copy');

	await alpha.site.makeUser({ email, password: PASSWORD });
	await page.goto(alpha.pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await page.waitForLoadState('domcontentloaded');

	await page.goto(accountSection(alpha.pages.account.url, 'privacy'));

	const button = page.locator('form:has(input[name="diluxone_users_request"][value="export"]) button[type="submit"]');
	const folded = page.locator('details:not([open])').filter({ has: button });

	if ((await folded.count()) > 0) {
		await folded.first().locator('> summary').click();
	}

	await Promise.all([page.waitForURL(/diluxone-users=requested/), button.click()]);
	await page.goto(linkIn(await waitForMail(alpha.site, email)));
	await expect(page).toHaveURL(/diluxone-users=ready/);
	expect(page.url(), 'back on this site’s account').toContain('/alpha/');

	const download = page.locator('.diluxone-users-requests a', { hasText: 'Download' }).first();
	const href = (await download.getAttribute('href')) as string;

	expect(href, 'handed over by this site').toContain('/alpha/');

	const file = await page.request.get(href);

	expect(file.status()).toBe(200);
	expect((await file.body()).subarray(0, 2).toString()).toBe('PK');
});
