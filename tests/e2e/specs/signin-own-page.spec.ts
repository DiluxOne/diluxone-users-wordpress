import { test, expect } from '../support/fixtures';

/**
 * The shape "A page of its own": the plugin answers an address of the site's
 * choosing and draws the whole document, with no header or footer of the
 * theme's. The address, its old ones, and the screens drawn on it.
 */

const SHAPE_KEYS = ['diluxone_users_login_template', 'diluxone_users_login_slug', 'diluxone_users_login_slug_old'];

test.describe('Sign in › A page of its own', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(SHAPE_KEYS);
		await options.set({
			diluxone_users_login_template: 'own',
			diluxone_users_login_slug: 'sign-in',
			diluxone_users_login_slug_old: [],
		});
	});

	test('C1: /sign-in/ draws the form on a page with no theme header or footer', async ({ guest }) => {
		const response = await guest.goto('/sign-in/');

		expect(response?.status(), 'answered, not a 404').toBe(200);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(guest.locator('.diluxone-users-login-frame--own .diluxone-users-login')).toBeVisible();
		await expect(guest.locator('.diluxone-users-door__site'), 'the site’s name, where there is no mark').toBeVisible();
		await expect(guest.locator('header, footer, .wp-block-template-part'), 'nothing of the theme’s').toHaveCount(0);
		await expect(guest).toHaveTitle(/^Sign in/);
		expect(response?.headers()['cache-control'] ?? '', 'kept out of every cache').toContain('no-store');
	});
});
