import type { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * What Users+ Pro would add to each screen's Summary (#241): one row a
 * feature, with the Pro badge and the one link, after the screen's real rows.
 *
 * Not a word is read: the site runs in Spanish, so a row is found by its
 * `data-diluxone-users-pro` key and the link by its campaign.
 */

test.use({ storageState: ADMIN_STATE });

/** Each Summary, the tab it is on, and the key of every Pro row it draws, in order. */
const SUMMARIES: Array<[string, string, string[]]> = [
	['diluxone-users-login', 'summary', ['login/summary/passkeys', 'login/summary/shapes']],
	['diluxone-users-account', 'summary', ['account/summary/sections', 'account/summary/handle']],
	[
		'diluxone-users-security',
		'summary',
		['security/summary/more', 'security/summary/app', 'security/summary/roles', 'security/summary/link', 'security/summary/remember'],
	],
];

/** The keys of the Pro rows of the Summary table, in order. */
async function proRows(page: Page): Promise<string[]> {
	return page
		.locator('table.diluxone-users-summary tr.du-pro--row')
		.evaluateAll((rows) => rows.map((row) => row.getAttribute('data-diluxone-users-pro') ?? ''));
}

test.describe('Every Summary says what Users+ Pro would add to it', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_pro_previews']);
		await options.set({ diluxone_users_pro_previews: 1 });
	});

	for (const [screen, tab, keys] of SUMMARIES) {
		test(`${screen} › ${tab}: a Pro row for each feature, with the badge and the link`, async ({ page }) => {
			await page.goto(adminUrl(screen, tab));

			expect(await proRows(page)).toEqual(keys);

			for (const key of keys) {
				const row = page.locator(`tr[data-diluxone-users-pro="${key}"]`);

				await expect(row.locator('.diluxone-users-state--pro'), `${key}: the badge`).toBeVisible();
				await expect(row.locator('a[href*="diluxone.com/wordpress-plugins/users/"][href*="utm_campaign=free"][target="_blank"]'), `${key}: the link`).toHaveCount(1);
			}
		});
	}
});
