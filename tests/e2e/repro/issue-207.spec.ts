import { test, expect } from '../support/fixtures';
import { accessTab, control, notNow } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #207, item 7: on Access › The sign-in page, with the e-mail link as the
 * only way in and a page chosen, two sections say "Not in use now". The
 * lost-password one has its choices disabled (#192), but "What happens to
 * wp-login.php" leaves "Send everybody…" and "…only while the link…" enabled.
 * The reporter expects every section under "Not in use now" to have its
 * controls disabled.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Issue 207: a section that does not apply now', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_login_method', 'diluxone_users_login_page', 'diluxone_users_lost_password', 'diluxone_users_wp_screens']);
	});

	test('the wp-login.php choices are disabled while the e-mail link is the only way in', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'link',
			diluxone_users_login_page: pages.login.id,
			diluxone_users_lost_password: 'site',
		});

		await page.goto(accessTab('page'));

		// Both sections say they do not apply.
		await expect(notNow(page), 'the wp-login.php and lost-password sections both say so').toHaveCount(2);

		// The lost-password section waits, as #192 asked.
		await expect(control(page, 'diluxone_users_lost_password', 'site'), 'the lost-password choice waits').toBeDisabled();

		// So does the wp-login.php one.
		for (const value of ['mine', 'wp', 'auto']) {
			await expect(control(page, 'diluxone_users_wp_screens', value), `the wp-login.php choice "${value}" waits`).toBeDisabled();
		}
	});
});
