import { test, expect } from './support';
import { DARK_SITE, unreadableOn, wearStandIn } from '../support/dark-mode';
import { uploadImage } from '../support/signin';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';

/**
 * The shape "A page of its own" on a network: the page lives on the hub, where
 * the network signs in, and the other sites send people there.
 */

// "No page" on Access, so the page of its own is at /sign-in/.
const SHAPE = { diluxone_users_login_template: 'own', diluxone_users_login_page: 0, diluxone_users_login_slug: 'sign-in', diluxone_users_login_slug_old: [] };

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

	test('C1: the page chosen on the hub draws the page of its own at its own address, with no redirect', async ({ hub, guest }) => {
		await hub.set({ ...SHAPE, diluxone_users_login_page: hub.pages.login.id });

		const direct = await guest.request.get(hub.pages.login.url, { maxRedirects: 0 });

		expect(direct.status(), 'answered there, not sent anywhere').toBe(200);

		const response = await guest.goto(hub.pages.login.url);

		expect(response?.status()).toBe(200);
		expect(response?.request().redirectedFrom(), 'nothing redirects').toBeNull();
		expect(guest.url()).toBe(hub.pages.login.url);
		await expect(guest.locator('body.diluxone-users-door'), 'the page of its own, drawn there').toHaveCount(1);
		await expect(guest.locator('.diluxone-users-login-frame--own .diluxone-users-login')).toBeVisible();
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

	for (const scheme of ['dark', 'light'] as const) {
		test(`the back link keeps 4.5:1 against the ground, ${scheme}, on a theme that flips with the system (#120)`, async ({ browser, hub, guest }) => {
			const image = await uploadImage(browser, hub.url, NETWORK_ADMIN_STATE);

			try {
				await hub.set({ ...DARK_SITE, ...SHAPE, diluxone_users_login_logo: image.id, diluxone_users_login_logo_show: 1 });
				await guest.emulateMedia({ colorScheme: scheme });
				await wearStandIn(guest, new URL(hub.path('sign-in/')).pathname);
				await guest.goto(hub.path('sign-in/'));

				await expect(guest.locator('#stand-in-theme')).toHaveCount(1);
				await expect(guest.locator('.diluxone-users-door__back a')).toBeVisible();
				await expect.poll(() => unreadableOn(guest, 'the back link', '.diluxone-users-door__back a'), { message: 'the back link at 4.5:1 or more' }).toEqual([]);
			} finally {
				await image.forget();
			}
		});
	}

	test('the back link keeps 4.5:1 against the ground with Colours › your own, dark (#120)', async ({ browser, hub, guest }) => {
		const image = await uploadImage(browser, hub.url, NETWORK_ADMIN_STATE);

		try {
			await hub.set({ ...DARK_SITE, diluxone_users_colors: 'own', ...SHAPE, diluxone_users_login_logo: image.id, diluxone_users_login_logo_show: 1 });
			await guest.emulateMedia({ colorScheme: 'dark' });
			await guest.goto(hub.path('sign-in/'));

			await expect(guest.locator('.diluxone-users-door__back a')).toBeVisible();
			await expect.poll(() => unreadableOn(guest, 'the back link', '.diluxone-users-door__back a'), { message: 'the back link at 4.5:1 or more' }).toEqual([]);
		} finally {
			await image.forget();
		}
	});
});
