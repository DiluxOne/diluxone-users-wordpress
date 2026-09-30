import { Page } from '@playwright/test';
import { test, expect, SiteHandle } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, signInWithPassword } from '../support/ui';
import { wp } from '../support/cli';

/**
 * "Delete my account" on a network.
 *
 * The account is the network's, and the account area where it is closed is
 * the main site's. What happens to it depends on where else it belongs: a
 * person who is a member of the main site only, with nothing published, is
 * deleted from the whole network; a person who is also a member of another
 * site keeps the account there, emptied and anonymised, because that site was
 * never asked.
 */

const PASSWORD = 'e2e-Network-1!';

/** Signs in on the main site, asks from the account area, and confirms from the e-mail. */
async function deleteFrom(page: Page, one: SiteHandle, email: string): Promise<void> {
	await page.goto(one.pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await page.waitForLoadState('domcontentloaded');

	await page.goto(accountSection(one.pages.account.url, 'privacy'));

	const button = page.locator('form:has(input[name="diluxone_users_request"][value="erase"]) button[type="submit"]');

	// The panel may be folded: open whichever panel holds the button.
	const folded = page.locator('details:not([open])').filter({ has: button });

	if ((await folded.count()) > 0) {
		await folded.first().locator('> summary').click();
	}

	// Asked in the page's own question, then confirmed from the e-mail and
	// once more on the account, signed in on this site.
	await button.click();
	await Promise.all([
		page.waitForURL(/diluxone-users=requested/),
		page.locator('dialog#diluxone-users-ask-erase [data-diluxone-users-dialog-ok]').click(),
	]);
	await page.goto(linkIn(await waitForMail(one.site, email)));

	await Promise.all([page.waitForURL(/diluxone-users=closed/), page.getByRole('button', { name: 'Yes, delete my account' }).click()]);
	await expect(page.locator('[data-diluxone-users-message="login_closed"]')).toBeVisible();
}

test.describe('Deleting an account on a network', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.set({ diluxone_users_privacy_delete: 1, diluxone_users_2fa_mode: 'off' });
	});

	test('a member of the main site only, with nothing published, is deleted from the network', async ({ page, hub, beta }) => {
		const email = freshEmail('net-close-one');

		await hub.site.makeUser({ email, password: PASSWORD });
		await deleteFrom(page, hub, email);

		expect((await hub.site.user(email)).exists, 'gone from the site').toBe(false);
		expect((await beta.site.user(email)).exists, 'and from the network').toBe(false);
	});

	test('a member of another site too keeps the account there, emptied and without a name', async ({
		page,
		hub,
		beta,
	}) => {
		const email = freshEmail('net-close-two');

		const person = await hub.site.makeUser({ email, password: PASSWORD });
		await beta.site.makeUser({ email, password: PASSWORD });

		await deleteFrom(page, hub, email);

		// The address is not the account's any more: nobody answers to it.
		expect((await beta.site.user(email)).exists, 'the address no longer names anybody').toBe(false);

		// Signing in with what used to work does not.
		await page.context().clearCookies();
		await page.goto(beta.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('.diluxone-users-notice--error, #login_error').first()).toBeVisible();

		// The account is still there under its id, as a shell: what points at
		// it does not break.
		expect(wp(['user', 'get', String(person.id), '--field=user_login']), 'the same id, emptied').toBe(`deleted-${person.id}`);
	});
});
