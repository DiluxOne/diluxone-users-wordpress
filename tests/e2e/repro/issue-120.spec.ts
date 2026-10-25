import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { DARK_SITE, unreadableOn, wearStandIn } from '../support/dark-mode';
import { uploadImage } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Issue #120: on the shape "A page of its own", with the mark in the box, the
 * link back to the site sits under the card, on the door's ground, but took
 * the muted colour, which is worked out against the card. In the dark scheme
 * that pair fell under 4.5:1. The link now takes the text's colour, as the
 * site's name in its place does.
 *
 * The stand-in theme is the one of `repro/issue-113` (`STAND_IN_THEME`),
 * added to the page's head as the page is served. Every case runs in a fresh
 * context, so nothing is kept from a measurement before.
 */

const OWN_PAGE = {
	diluxone_users_login_template: 'own',
	diluxone_users_login_slug: 'sign-in',
	diluxone_users_login_slug_old: [],
	diluxone_users_login_method: 'both',
	diluxone_users_login_layout: 'stack',
	diluxone_users_sso_login: 0,
	diluxone_e2e_sso: 0,
	diluxone_users_login_logo_show: 1,
};

const BACK = '.diluxone-users-door__back a';

async function drawn(page: Page, standIn: boolean): Promise<void> {
	await page.goto('/sign-in/');

	await expect(page.locator('body.diluxone-users-door')).toHaveCount(1);
	await expect(page.locator('#stand-in-theme')).toHaveCount(standIn ? 1 : 0);
	await expect(page.locator('.diluxone-users-login__logo img')).toBeVisible();
	await expect(page.locator(BACK)).toBeVisible();
}

test.describe('Issue 120: the back link of the sign-in page of its own', () => {
	for (const scheme of ['dark'] as const) {
		test(`Colours › the theme’s, on a theme that flips with the system, ${scheme}: the back link keeps 4.5:1 against the ground`, async ({ browser, baseURL, page, options }) => {
			const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

			try {
				await options.set({ ...DARK_SITE, ...OWN_PAGE, diluxone_users_login_logo: image.id });
				await page.emulateMedia({ colorScheme: scheme });
				await wearStandIn(page, '/sign-in/');
				await drawn(page, true);

				await expect.poll(() => unreadableOn(page, 'the back link', BACK), { message: 'the back link at 4.5:1 or more' }).toEqual([]);
			} finally {
				await image.forget();
			}
		});
	}

	test('Colours › your own, dark: the back link keeps 4.5:1 against the ground', async ({ browser, baseURL, page, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({ ...DARK_SITE, diluxone_users_colors: 'own', ...OWN_PAGE, diluxone_users_login_logo: image.id });
			await page.emulateMedia({ colorScheme: 'dark' });
			await drawn(page, false);

			await expect.poll(() => unreadableOn(page, 'the back link', BACK), { message: 'the back link at 4.5:1 or more' }).toEqual([]);
		} finally {
			await image.forget();
		}
	});
});
