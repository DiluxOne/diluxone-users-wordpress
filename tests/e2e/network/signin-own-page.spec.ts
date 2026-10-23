import { test, expect } from './support';

/**
 * The shape "A page of its own" on a network: the page lives on the hub, where
 * the network signs in, and the other sites send people there.
 */

const SHAPE = { diluxone_users_login_template: 'own', diluxone_users_login_slug: 'sign-in', diluxone_users_login_slug_old: [] };

test.describe('A page of its own, on a network', () => {
	test('C1: the hub’s /sign-in/ draws the form with no theme header or footer; another site has no such page', async ({ hub, alpha, guest }) => {
		await hub.set(SHAPE);

		const response = await guest.goto(hub.path('sign-in/'));

		expect(response?.status(), 'answered on the hub').toBe(200);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(guest.locator('.diluxone-users-login-frame--own .diluxone-users-login')).toBeVisible();
		await expect(guest.locator('header, footer, .wp-block-template-part'), 'nothing of the theme’s').toHaveCount(0);

		await guest.goto(alpha.path('sign-in/'));
		await expect(guest.locator('body.diluxone-users-door'), 'only the hub answers it').toHaveCount(0);
	});
});
