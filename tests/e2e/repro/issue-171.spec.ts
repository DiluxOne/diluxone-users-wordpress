import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink } from '../support/ui';

/**
 * Issue #171: on the step that asks for the required fields after a sign-in,
 * "Skip for now" is drawn 16px in from the left edge of the button above it,
 * because it keeps a margin made for a row while the form is a column. The
 * reporter expects the link to start at the form's column, the button's edge.
 */

test.describe('Issue 171: the required-fields step', () => {
	test('"Skip for now" starts at the same left edge as the button above it', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 1, group: 'main', active: 1 },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, group: 'main', active: 1 },
			],
			diluxone_users_login_register: 1,
			diluxone_users_login_role: 'subscriber',
			diluxone_users_2fa_mode: 'off',
		});

		const email = freshEmail('repro-171');

		await askForLink(page, pages.login.url, email);
		await page.goto(linkIn(await waitForMail(site, email)));
		await expectSignedIn(page, email);

		const form = page.locator('.diluxone-users-complete form.diluxone-users-form');
		const button = form.locator('button[type="submit"]');
		const skip = form.locator('.diluxone-users-fields__skip');

		await expect(button).toBeVisible();
		await expect(skip).toBeVisible();

		const buttonBox = await button.boundingBox();
		const skipBox = await skip.boundingBox();

		expect(Math.abs(skipBox!.x - buttonBox!.x), `"Skip for now" at x=${skipBox!.x}, the button at x=${buttonBox!.x}`).toBeLessThanOrEqual(1);
	});
});
