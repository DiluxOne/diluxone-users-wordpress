import { test, expect } from '../support/fixtures';
import { DARK_SITE, readings, unreadable } from '../support/dark-mode';
import { uploadImage } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #120: on the shape "A page of its own", with Colours › the theme's,
 * on a theme that turns dark with the system, the back link under the card
 * ("← Back to <site>") is drawn in the muted colour worked out against the
 * card, but it sits on the page's ground around it, and reads under 4.5:1.
 *
 * The back link only draws while the site's logo is set and shown (without
 * one the site's name stands above the card instead), so a logo is uploaded.
 */

const OWN_PAGE = {
	diluxone_users_login_template: 'own',
	diluxone_users_login_slug: 'sign-in',
	diluxone_users_login_slug_old: [],
	diluxone_users_login_method: 'both',
	diluxone_users_login_layout: 'stack',
	diluxone_users_sso_login: 0,
	diluxone_e2e_sso: 0,
};

test.describe('Issue 120: the back link of the sign-in page of its own', () => {
	test('in the dark scheme the back link keeps 4.5:1 against the ground behind it', async ({ browser, baseURL, page, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({ ...DARK_SITE, ...OWN_PAGE, diluxone_users_login_logo: image.id, diluxone_users_login_logo_show: 1 });
			await page.emulateMedia({ colorScheme: 'dark' });
			await page.goto('/sign-in/');

			await expect(page.locator('body.diluxone-users-door')).toHaveCount(1);
			await expect(page.locator('.diluxone-users-login-frame__box')).toBeVisible();
			await expect(page.locator('.diluxone-users-door__back a')).toBeVisible();

			await expect
				.poll(async () => unreadable(await readings(page, '.diluxone-users-door__back a')), {
					message: 'the back link at 4.5:1 or more',
				})
				.toEqual([]);
		} finally {
			await image.forget();
		}
	});
});
