import { test, expect } from '../support/fixtures';
import { accessTab, control, notNow } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #192: a section of Access that does not apply now still wears the
 * "Pending" pill, which reads as a task left to do, and "Which tab opens",
 * while the ways in are stacked, says there are no tabs yet leaves its select
 * enabled. The reporter expects a state of its own ("Not in use now") and the
 * controls disabled until the section applies.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Issue 192: a section that does not apply now', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep([
			'diluxone_users_login_method',
			'diluxone_users_login_page',
			'diluxone_users_lost_password',
			'diluxone_users_login_layout',
			'diluxone_users_login_open',
			'diluxone_users_sso_login',
			'diluxone_e2e_sso',
		]);
	});

	test('the lost-password section does not say Pending while the e-mail link is the only way in', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'link',
			diluxone_users_login_page: pages.login.id,
			diluxone_users_lost_password: 'site',
		});

		await page.goto(accessTab('page'));

		await expect(notNow(page).first(), 'the section says it does not apply').toBeVisible();
		await expect(page.locator('.diluxone-users-not-now .diluxone-users-state--pending'), 'a task left to do').toHaveCount(0);
	});

	test('the tab picker is disabled while the ways in are stacked', async ({ page, options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_sso_login: 0,
			diluxone_e2e_sso: 0,
			diluxone_users_login_layout: 'stack',
			diluxone_users_login_open: '',
		});

		await page.goto(accessTab('arrangement'));

		await expect(notNow(page), 'stacked: the section says no tab opens').toHaveCount(1);
		await expect(control(page, 'diluxone_users_login_open'), 'there are no tabs to choose between').toBeDisabled();
		await expect(page.locator('.diluxone-users-not-now .diluxone-users-state--pending'), 'a task left to do').toHaveCount(0);
	});
});
