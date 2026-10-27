import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { accountSection } from '../support/ui';

/**
 * Issue #166: on the account area's "Your details" tab, the photo and the
 * public name are each in a box with a title, but the custom fields below are
 * drawn loose. The reporter expects each block that has fields (main, extra)
 * in its own box, like the public name's, under a title.
 */

const MAIN = 'e2e_main_166';
const EXTRA = 'e2e_extra_166';

test.describe('Issue 166: the account form’s field blocks', () => {
	test('each block with fields is drawn in its own box, under a title', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_2fa_mode: 'off',
			diluxone_users_fields: [
				{ key: MAIN, label: 'Main block field', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: EXTRA, label: 'Extra block field', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
			],
		});

		await signInMember(page, site, pages.login.url, 'repro-166');

		await page.goto(accountSection(pages.account.url, 'details'));

		// The page is drawn, and both fields are on it.
		await expect(page.locator(`#${MAIN}`)).toHaveCount(1);
		await expect(page.locator(`#${EXTRA}`)).toHaveCount(1);

		const mainBox = page.locator('.diluxone-users-panel').filter({ has: page.locator(`#${MAIN}`) });
		const extraBox = page.locator('.diluxone-users-panel').filter({ has: page.locator(`#${EXTRA}`) });

		// Each field is inside a box…
		await expect(mainBox, 'the main block is in a box').toHaveCount(1);
		await expect(extraBox, 'the extra block is in a box').toHaveCount(1);

		// …of its own: neither holds the other's field.
		await expect(mainBox.locator(`#${EXTRA}`), 'the main box holds only the main block').toHaveCount(0);
		await expect(extraBox.locator(`#${MAIN}`), 'the extra box holds only the extra block').toHaveCount(0);

		// …under a title, as the public name's is.
		for (const box of [mainBox, extraBox]) {
			const title = box.locator('.diluxone-users-panel__title, h2, h3').first();

			await expect(title, 'the box has a title').not.toHaveText('');
		}
	});
});
