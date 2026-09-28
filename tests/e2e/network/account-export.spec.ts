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
	await expect(page.getByText('your file is ready')).toBeVisible();

	const mailed = linkIn(await waitForMail(alpha.site, email, { subject: /Personal Data Export/ }), /https?:\/\/\S+\.zip/);

	expect(mailed, 'served from this site’s own uploads').toContain('/sites/');

	await page.goto(accountSection(alpha.pages.account.url, 'privacy'));
	await expect(page.locator(`.diluxone-users-requests a[href="${mailed}"]`)).toBeVisible();
	expect((await page.request.get(mailed)).status()).toBe(200);
});
