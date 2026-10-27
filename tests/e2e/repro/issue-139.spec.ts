import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { accountSection } from '../support/ui';

/**
 * Issue #139: the "Complete your details" step after a sign-in
 * (`?diluxone-users-complete=1`) is drawn bare, straight inside the page's
 * content: no account frame, the form as wide as the page, and the line
 * ("Before you go on…") printed before the title.
 *
 * Expected: the title first and the line after it, and the form no wider than
 * the account's own details form.
 */

const FIRST_NAME_REQUIRED = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 1, group: 'main', active: 1 },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, group: 'main', active: 1 },
];

test.describe('Issue 139: the required-fields step after sign-in', () => {
	test('the step puts its title before its line and keeps its form no wider than the account’s details form', async ({ guest, site, pages, options }) => {
		await options.set({
			diluxone_users_fields: FIRST_NAME_REQUIRED,
			diluxone_users_2fa_mode: 'off',
			// An add-on that asks for them, as Users+ Pro does.
			diluxone_e2e_ask_missing: 1,
		});

		await signInMember(guest, site, pages.login.url, 'step-frame');

		// The account's own details form: the width the step's form should keep to.
		await guest.goto(accountSection(pages.account.url, 'details'));
		const details = guest.locator('.diluxone-users-account__section form.diluxone-users-form').first();
		await expect(details).toBeVisible();
		const detailsBox = await details.boundingBox();

		await guest.goto(`${pages.account.url}${pages.account.url.includes('?') ? '&' : '?'}diluxone-users-complete=1`);

		const step = guest.locator('.diluxone-users-complete');
		const title = step.locator('.diluxone-users-fields__title');
		const intro = step.locator('.diluxone-users-complete__intro');
		const form = step.locator('form.diluxone-users-form');

		await expect(form).toBeVisible();
		await expect(title).toBeVisible();
		await expect(intro).toBeVisible();

		const titleBox = await title.boundingBox();
		const introBox = await intro.boundingBox();
		const formBox = await form.boundingBox();

		expect(titleBox!.y, 'the title comes before the line').toBeLessThan(introBox!.y);
		expect(formBox!.width, 'the form is no wider than the account’s details form').toBeLessThanOrEqual(detailsBox!.width + 1);
	});
});
