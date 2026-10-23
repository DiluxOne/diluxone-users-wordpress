import { test, expect } from '../support/fixtures';
import { uploadImage } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #70: on the centred sign-in card with a logo, the icon-only social
 * buttons sit at the left while the logo is centred.
 */

const NETWORKS = {
	google: { active: 1, id: 'e2e-google-id', secret: 'e2e-google-secret', tested: 1 },
	microsoft: { active: 1, id: 'e2e-microsoft-id', secret: 'e2e-microsoft-secret', tested: 1 },
};

test.describe('The centred sign-in card', () => {
	test('centres the icon-only social buttons under the centred logo', async ({ browser, baseURL, page, pages, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({
				diluxone_users_login_template: 'card',
				diluxone_users_login_logo: image.id,
				diluxone_users_sso: NETWORKS,
				diluxone_users_sso_login: 1,
				diluxone_users_sso_button_show: 'icon',
			});

			await page.goto(pages.login.url);

			const box = page.locator('.diluxone-users-login-frame__box');
			const logo = box.locator('.diluxone-users-login__logo img');
			const google = page.locator('a.diluxone-users-social--google');
			const microsoft = page.locator('a.diluxone-users-social--microsoft');

			await expect(logo).toBeVisible();
			await expect(google).toBeVisible();
			await expect(microsoft).toBeVisible();

			const boxRect = (await box.boundingBox())!;
			const logoRect = (await logo.boundingBox())!;
			const first = (await google.boundingBox())!;
			const second = (await microsoft.boundingBox())!;

			const middle = (r: { x: number; width: number }) => r.x + r.width / 2;
			const buttonsMiddle = (Math.min(first.x, second.x) + Math.max(first.x + first.width, second.x + second.width)) / 2;

			expect(Math.abs(middle(logoRect) - middle(boxRect)), 'the logo is centred on the card').toBeLessThanOrEqual(2);
			expect(Math.abs(buttonsMiddle - middle(logoRect)), 'the icons are centred under the logo').toBeLessThanOrEqual(2);
		} finally {
			await image.forget();
		}
	});
});
