import { test, expect } from '../support/fixtures';
import { adminUrl } from '../support/ui';
import { masks, pictureOf, settled } from '../support/pictures';
import { pinVisualState } from '../support/visual-state';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The dashboard's screens whose stylesheet changes on a phone, photographed on one.
 *
 * Below 782px the dashboard goes to its phone layout and the plugin's own
 * rules for it take over: the tab strip scrolls instead of wrapping, the rail
 * goes underneath, the roles and the choices drop their indent, the steps on
 * the overview and the environment table restack, the dialog that adds a
 * field fills the screen. The measurements check those screens hold together
 * at 782; these are what they look like at 390, one per kind of rule, in the
 * `visual-mobile` project only.
 */

test.use({ storageState: ADMIN_STATE });

/** One tab per rule the phone layout has: [screen, tab]. */
const ON_A_PHONE: Array<[string, string]> = [
	['diluxone-users', 'usage'], // the steps
	['diluxone-users-login', 'ways'], // the tab strip, the choices and their children
	['diluxone-users-security', '2fa'], // the roles
	['diluxone-users-fields', 'list'], // the list of fields
	['diluxone-users-design', 'brand'], // the rail underneath
	['diluxone-users-reports', 'sessions'], // the report's table
	['diluxone-users-status', 'status'], // the environment table
];

test.beforeEach(async ({ options, site }) => {
	await options.set({ WPLANG: '' });
	await pinVisualState(site, options.set);
});

test.describe('The dashboard on a phone looks like it did', () => {
	for (const [screen, tab] of ON_A_PHONE) {
		test(`${screen} › ${tab}`, async ({ page }) => {
			await page.goto(adminUrl(screen, tab, screen === 'diluxone-users-reports' ? { s: 'ana@example.com' } : {}));
			await settled(page);

			await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(pictureOf(`${screen} › ${tab}`), {
				mask: masks(page),
			});
		});
	}

	test('the dialog that adds a field', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-fields', 'list'));
		// The first link that opens it is "New field"; the form is lifted in
		// from the screen it belongs to, so the picture waits for the form.
		await page.locator('[data-diluxone-users-field-dialog]').first().click();
		await expect(page.locator('dialog.diluxone-users-dialog[open] [data-diluxone-users-dialog-body] form')).toBeVisible();
		await settled(page);

		await expect(page.locator('dialog.diluxone-users-dialog[open]')).toHaveScreenshot('diluxone-users-fields-add.png', { mask: masks(page) });
	});
});
