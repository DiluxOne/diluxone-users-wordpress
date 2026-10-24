import { test, expect } from '../support/fixtures';
import { uploadImage } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue 70: on the centred card, with a logo set, the logo is centred but the
 * icon-only social buttons sit at the left of the card.
 */

const CONFIGURED = { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 };

test.describe('Issue 70: the centred sign-in card', () => {
	test('the icon-only social buttons are centred on the card, like the logo', async ({ browser, baseURL, page, pages, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({
				diluxone_users_login_template: 'card',
				diluxone_users_login_layout: 'stack',
				diluxone_users_login_logo: image.id,
				diluxone_users_login_logo_show: 1,
				diluxone_users_sso: { google: CONFIGURED, microsoft: CONFIGURED },
				diluxone_users_sso_login: 1,
				diluxone_users_sso_button_show: 'icon',
			});

			await page.goto(pages.login.url);

			const card = page.locator('.diluxone-users-login-frame__box');
			const logo = card.locator('.diluxone-users-login__logo img');
			const buttons = card.locator('.diluxone-users-socials--icon .diluxone-users-social');

			await expect(logo).toBeVisible();
			await expect(buttons).toHaveCount(2);

			const cardBox = (await card.boundingBox())!;
			const logoBox = (await logo.boundingBox())!;
			const first = (await buttons.first().boundingBox())!;
			const last = (await buttons.last().boundingBox())!;

			const cardMiddle = cardBox.x + cardBox.width / 2;
			const logoMiddle = logoBox.x + logoBox.width / 2;
			const buttonsMiddle = (first.x + last.x + last.width) / 2;

			expect(Math.abs(logoMiddle - cardMiddle), 'the logo is centred on the card').toBeLessThanOrEqual(2);
			expect(Math.abs(buttonsMiddle - cardMiddle), 'the icon buttons are centred on the card too').toBeLessThanOrEqual(2);
		} finally {
			await image.forget();
		}
	});
});
