import { test, expect } from './support';

/**
 * The shape "A page of its own" on a network: the page lives on the hub, where
 * the network signs in, and the other sites send people there.
 */

const SHAPE = { diluxone_users_login_template: 'own', diluxone_users_login_slug: 'sign-in', diluxone_users_login_slug_old: [], diluxone_users_login_slug_was: '' };

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

	test('C3: with nothing chosen on an English hub, the page of its own is at /sign-in/, and the other sites lead there', async ({ hub, alpha, guest }) => {
		await hub.set({ ...SHAPE, diluxone_users_login_slug: '', diluxone_users_login_page: 0, WPLANG: '' });

		const response = await guest.goto(hub.path('sign-in/'));

		expect(response?.status(), 'answered on the hub, as before').toBe(200);
		expect(new URL(guest.url()).pathname).toBe(new URL(hub.path('sign-in/')).pathname);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);

		await guest.goto(alpha.pages.login.url);
		const button = guest.locator('.diluxone-users-hub-door[data-diluxone-users-door="login"] a.diluxone-users-button');
		expect(((await button.getAttribute('href')) ?? '').startsWith(`${hub.path('sign-in/')}?redirect_to=`), 'the hub’s /sign-in/').toBe(true);
	});

	test('C2: another site’s door leads to the hub’s /sign-in/, with the way back', async ({ hub, alpha, guest }) => {
		await hub.set(SHAPE);

		await guest.goto(alpha.pages.login.url);

		const button = guest.locator('.diluxone-users-hub-door[data-diluxone-users-door="login"] a.diluxone-users-button');

		expect(((await button.getAttribute('href')) ?? '').startsWith(`${hub.path('sign-in/')}?redirect_to=`), 'the hub’s page of its own, carrying the way back').toBe(true);
		await Promise.all([guest.waitForURL((url) => url.pathname === new URL(hub.path('sign-in/')).pathname), button.click()]);
		expect(new URL(guest.url()).searchParams.get('redirect_to')).toBe(alpha.pages.login.url);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
	});
});
