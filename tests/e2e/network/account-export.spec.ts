import { test, expect } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, signInWithPassword } from '../support/ui';

/**
 * "Download your data" on a network.
 *
 * The account area is the main site's — the hub's — so the copy is asked for
 * there, confirmed there, and the file is kept in the main site's uploads and
 * handed over by it, whichever site of the network the person came from.
 */

const PASSWORD = 'e2e-Network-1!';

test('a member of /alpha/ confirming a copy on the account area finds it ready there', async ({ page, hub, alpha }) => {
	await hub.set({ diluxone_users_privacy_export: 1, diluxone_users_2fa_mode: 'off' });

	const email = freshEmail('net-copy');

	await alpha.site.makeUser({ email, password: PASSWORD });
	await page.goto(alpha.pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await page.waitForLoadState('domcontentloaded');

	await page.goto(accountSection(hub.pages.account.url, 'privacy'));

	const button = page.locator('form:has(input[name="diluxone_users_request"][value="export"]) button[type="submit"]');
	const folded = page.locator('details:not([open])').filter({ has: button });

	if ((await folded.count()) > 0) {
		await folded.first().locator('> summary').click();
	}

	await Promise.all([page.waitForURL(/diluxone-users=requested/), button.click()]);
	await page.goto(linkIn(await waitForMail(hub.site, email)));
	await expect(page).toHaveURL(/diluxone-users=ready/);
	expect(page.url(), 'back on the account area').toContain(new URL(hub.pages.account.url).pathname);

	const download = page.locator('.diluxone-users-requests a', { hasText: 'Download' }).first();
	const href = (await download.getAttribute('href')) as string;

	expect(href, 'handed over by the main site').not.toContain('/alpha/');

	const file = await page.request.get(href);

	expect(file.status()).toBe(200);
	expect((await file.body()).subarray(0, 2).toString()).toBe('PK');
});
