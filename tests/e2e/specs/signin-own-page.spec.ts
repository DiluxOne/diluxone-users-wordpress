import { test, expect } from '../support/fixtures';
import { adminUrl, savePanel } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The shape "A page of its own": the plugin answers an address of the site's
 * choosing and draws the whole document, with no header or footer of the
 * theme's. The address, its old ones, and the screens drawn on it.
 */

// The dashboard in `page`; everything a visitor sees in `guest`.
test.use({ storageState: ADMIN_STATE });

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

	test('C2: the account area and the sign-in page chosen before both lead to /sign-in/', async ({ guest, pages, baseURL }) => {
		const door = `${baseURL}/sign-in/`;

		await guest.goto(pages.account.url);
		await expect(guest.locator(`a[href="${door}"]`).first(), 'the account area, to a stranger, links to the page of its own').toBeVisible();

		await guest.goto(pages.login.url);
		expect(guest.url(), 'the page still chosen in Sign in › The page sends people there').toBe(door);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
	});

	test('C3: the address changed from sign-in to entrar on the tab, /sign-in/ redirects to /entrar/', async ({ page, guest, baseURL }) => {
		await page.goto(adminUrl('diluxone-users-design', 'login'));
		await page.locator('input[name="diluxone_users_login_template"][value="own"]').check({ force: true });
		await page.locator('input[name="diluxone_users_login_slug"]').fill('entrar');
		await savePanel(page);
		await expect(page.locator('input[name="diluxone_users_login_slug"]')).toHaveValue('entrar');

		const moved = await guest.request.get(`${baseURL}/sign-in/?diluxone-users=expired`, { maxRedirects: 0 });

		expect(moved.status(), 'moved for good').toBe(301);
		expect(moved.headers().location, 'with what the address carried').toBe(`${baseURL}/entrar/?diluxone-users=expired`);

		await guest.goto('/sign-in/');
		expect(guest.url()).toBe(`${baseURL}/entrar/`);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
	});
});
