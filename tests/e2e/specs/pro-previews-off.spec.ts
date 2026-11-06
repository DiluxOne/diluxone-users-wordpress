import { test, expect } from '../support/fixtures';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The six settings that became Users+ Pro's (#188), each drawn as the control
 * it would be where it was: with `diluxone_users_pro_previews` off, none of
 * them is drawn, and the screens they were on still are.
 */

test.use({ storageState: ADMIN_STATE });

/** Each preview, and the screen and tab it is drawn on. */
const MOVED: Array<[string, string, string]> = [
	['security/2fa/link', 'diluxone-users-security', '2fa'],
	['social/general/roles', 'diluxone-users-social', 'general'],
	['account/handle/cooldown', 'diluxone-users-account', 'handle'],
	['account/handle/locked', 'diluxone-users-account', 'handle'],
	['fields/missing/ask', 'diluxone-users-fields', 'missing'],
	['fields/missing/skip', 'diluxone-users-fields', 'missing'],
	['design/brand/theme', 'diluxone-users-design', 'brand'],
];

test.describe('The settings that became Users+ Pro’s, with the previews off', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_pro_previews']);
	});

	test('on, each one is drawn where its setting was', async ({ page, options }) => {
		await options.set({ diluxone_users_pro_previews: 1 });

		for (const [where, screen, tab] of MOVED) {
			await page.goto(adminUrl(screen, tab));
			await expect(page.locator(`[data-diluxone-users-pro="${where}"]`), where).toHaveCount(1);
		}
	});

	test('off, none of them is drawn, and the screens still are', async ({ page, options }) => {
		await options.set({ diluxone_users_pro_previews: 0 });

		for (const [where, screen, tab] of MOVED) {
			await page.goto(adminUrl(screen, tab));
			await expect(page.locator('.wrap.diluxone-users-admin'), `${where}: the screen`).toBeVisible();
			await expect(page.locator(`[data-diluxone-users-pro="${where}"]`), where).toHaveCount(0);
			await expect(page.locator('a[href*="utm_campaign=free"]'), `${where}: no way out to Pro`).toHaveCount(0);
		}
	});
});

/** Every Summary that carries Users+ Pro rows (#241). */
const SUMMARIES = [
	'diluxone-users-login',
	'diluxone-users-security',
	'diluxone-users-account',
	'diluxone-users-fields',
	'diluxone-users-social',
	'diluxone-users-design',
];

test.describe('Every Summary, with the previews off', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_pro_previews']);
		await options.set({ diluxone_users_pro_previews: 0 });
	});

	for (const screen of SUMMARIES) {
		test(`${screen} › summary: no Pro row, and no row mentions Users+ Pro`, async ({ page }) => {
			await page.goto(adminUrl(screen, 'summary'));

			const table = page.locator('table.diluxone-users-summary');

			await expect(table.first(), 'the summary itself').toBeVisible();
			await expect(table.locator('tr.du-pro--row, .diluxone-users-state--pro'), 'no Pro row').toHaveCount(0);
			await expect(table.locator('a[href*="utm_campaign=free"], a[href*="diluxone.com"]'), 'no way out to Pro').toHaveCount(0);
		});
	}
});
