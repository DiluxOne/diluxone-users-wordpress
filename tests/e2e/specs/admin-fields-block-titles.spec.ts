import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { accountSection, adminUrl, savePanel } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * User fields › Fields › Titles of the boxes: what the site types is what the
 * boxes of "Your details" are called, as text; left empty, they keep the
 * plugin's titles.
 */

test.use({ storageState: ADMIN_STATE });

const TITLES = 'diluxone_users_field_block_titles';

const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_hobby', label: 'Hobby', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
];

/** The titles of the boxes on the member's "Your details", in order. */
async function boxTitles(guest: Page, accountUrl: string): Promise<string[]> {
	await guest.goto(accountSection(accountUrl, 'details'));

	return guest.locator('.diluxone-users-fields__block > h3').allTextContents();
}

/** Types both titles on User fields › Fields and saves the tab. */
async function typeTitles(page: Page, main: string, extra: string): Promise<void> {
	await page.goto(adminUrl('diluxone-users-fields'));
	await page.locator(`[name="${TITLES}[main]"]`).fill(main);
	await page.locator(`[name="${TITLES}[extra]"]`).fill(extra);
	await savePanel(page);
}

test.beforeEach(async ({ options }) => {
	await options.keep(['diluxone_users_fields', TITLES]);
	await options.set({ diluxone_users_fields: FIELDS, diluxone_users_2fa_mode: 'off' });
});

test('a title typed for the main block titles its box on the member’s details', async ({ page, guest, site, pages }) => {
	await signInMember(guest, site, pages.login.url, 'block-titles');

	await typeTitles(page, 'Datos del curso', '');

	await expect(page.locator(`[name="${TITLES}[main]"]`), 'the screen shows what was saved').toHaveValue('Datos del curso');
	expect((await site.getOptions([TITLES]))[TITLES]).toMatchObject({ main: 'Datos del curso' });

	const titles = await boxTitles(guest, pages.account.url);

	expect(titles[0]).toBe('Datos del curso');
	expect(titles).toHaveLength(2);
});
