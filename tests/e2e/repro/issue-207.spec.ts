import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { accountSection } from '../support/ui';

/**
 * Issue #207, item 1 (C1): on the account area's "Your details" tab, the
 * "Your photo" box has a smaller title than the other boxes and folds with an
 * arrow, while the boxes of the field blocks and "Your public name" have the
 * larger heading and do not fold. The reporter expects the four box headings
 * to have the same font size, and none to fold unless all do.
 */

const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_extra_207', label: 'Nickname', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
];

test.describe('Issue 207: the boxes of "Your details"', () => {
	test('the four boxes share one heading size and none folds unless all do', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_2fa_mode: 'off',
			diluxone_users_fields: FIELDS,
			diluxone_users_avatar_upload: 1,
			diluxone_users_handle_enabled: 1,
		});

		await signInMember(page, site, pages.login.url, 'repro-207');

		await page.goto(accountSection(pages.account.url, 'details'));

		const boxes = [
			page.locator('.diluxone-users-avatar').first(),
			page.locator('.diluxone-users-handle').first(),
			page.locator('.diluxone-users-fields__block--main').first(),
			page.locator('.diluxone-users-fields__block--extra').first(),
		];
		const titles = [
			page.locator('.diluxone-users-avatar .diluxone-users-panel__title').first(),
			page.locator('.diluxone-users-handle > h3').first(),
			page.locator('.diluxone-users-fields__block--main > h3').first(),
			page.locator('.diluxone-users-fields__block--extra > h3').first(),
		];

		for (const title of titles) {
			await expect(title, 'each box draws its heading').toBeVisible();
		}

		const sizes: string[] = [];
		for (const title of titles) {
			sizes.push(await title.evaluate((el) => getComputedStyle(el).fontSize));
		}

		const folds: boolean[] = [];
		for (const box of boxes) {
			folds.push(await box.evaluate((el) => el.tagName === 'DETAILS'));
		}

		expect(new Set(sizes).size, `the four headings have the same font size (photo, public name, main, extra: ${sizes.join(', ')})`).toBe(1);
		expect(new Set(folds).size, `no box folds unless all do (photo, public name, main, extra: ${folds.join(', ')})`).toBe(1);
	});
});
