import { test, expect } from '../support/fixtures';
import { accessTab, rail } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #207, item 5: Access › Summary has an empty "Right now" rail since
 * Registration moved out, while Registration's has its own. The reporter
 * expects Access to keep a "Right now" of its own, with the state of signing
 * in, on any site.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Issue 207: the summary of Access', () => {
	test('it has a "Right now" rail of its own that says something', async ({ page }) => {
		await page.goto(accessTab('summary'));

		// The table of ways in is drawn, so this is the right screen.
		await expect(page.locator('table.diluxone-users-summary')).toBeVisible();

		// The rail exists and is not empty.
		await expect(rail(page), 'the summary has a rail').toHaveCount(1);
		await expect(rail(page).locator('.du-state'), 'the rail says the state of signing in').toHaveCount(1);
		await expect(rail(page).locator('.du-state__line'), 'and says it in words').not.toHaveText('');
	});
});
