import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { accountSection } from '../support/ui';

/**
 * Issue #207, item 2: on the account area's "Your details" tab the phone
 * control takes the box's whole width, while the other fields (first name,
 * last name, country…) stop at about 390px. The reporter expects the phone
 * control to be no wider than the text fields beside it.
 */

const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_phone_207', label: 'Mobile', type: 'phone', options: ['AR'], required: 0, active: 1, group: 'main', edit: 'always' },
];

test.describe('Issue 207: the fields of "Your details"', () => {
	test('the phone control is no wider than the text fields beside it', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_users_fields: FIELDS });

		await signInMember(page, site, pages.login.url, 'repro-207');

		await page.goto(accountSection(pages.account.url, 'details'));

		const phone = page.locator('.diluxone-users-fields__block .diluxone-users-phone').first();
		const text = page.locator('.diluxone-users-fields__block input[name="first_name"]').first();

		await expect(phone, 'the phone control is drawn').toBeVisible();
		await expect(text, 'the first name is drawn').toBeVisible();

		const phoneBox = (await phone.boundingBox())!;
		const textBox = (await text.boundingBox())!;

		expect(phoneBox.width, 'the phone control is no wider than the first name beside it').toBeLessThanOrEqual(textBox.width + 1);
	});
});
