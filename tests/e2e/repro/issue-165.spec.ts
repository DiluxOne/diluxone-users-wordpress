import { test, expect } from '../support/fixtures';
import { accessTab, control } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #165: on Access › The sign-in page, with the e-mail link as the only
 * way in and a sign-in page chosen, the section "If somebody forgets their
 * password" says there is no password so what is chosen waits for one, yet its
 * three choices (WordPress's screen, This site's screen, Nowhere) can be
 * picked. The reporter expects them drawn disabled, as every waiting setting is.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Issue 165: the lost-password choices on a site with no password', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_login_method', 'diluxone_users_login_page', 'diluxone_users_lost_password']);
	});

	test('the three lost-password choices are disabled while the e-mail link is the only way in', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'link',
			diluxone_users_login_page: pages.login.id,
			diluxone_users_lost_password: 'site',
		});

		await page.goto(accessTab('page'));

		const choices = control(page, 'diluxone_users_lost_password');

		await expect(choices).toHaveCount(3);

		for (const value of ['wp', 'site', 'link']) {
			await expect(control(page, 'diluxone_users_lost_password', value), `the choice "${value}" waits for a password`).toBeDisabled();
		}
	});
});
