import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { DARK_SITE, readings, unreadable } from '../support/dark-mode';

/**
 * Issue #105: on the shape "A page of its own", on a site with a dark mode,
 * the site's name, the intro, the divider, the note under the button and the
 * legal line stay in their light-scheme text on the dark card, and all the
 * text is in the system's face where the shape "In the page" shows the
 * theme's.
 */

const OWN_PAGE = {
	diluxone_users_login_template: 'own',
	// "No page" on Access: the page of its own is at /sign-in/.
	diluxone_users_login_page: 0,
	diluxone_users_login_slug: 'sign-in',
	diluxone_users_login_slug_old: [],
	diluxone_users_login_method: 'both',
	diluxone_users_login_layout: 'stack',
	diluxone_users_sso_login: 0,
	diluxone_e2e_sso: 0,
	diluxone_users_login_intro: 'Welcome back, sign in to carry on.',
	diluxone_users_login_legal: 'By signing in you accept the terms.',
};

/** Each piece the report names, by the words it uses. */
const PIECES: Array<[string, string]> = [
	['the site’s name', '.diluxone-users-door__site a'],
	['the intro', '.diluxone-users-login__intro'],
	['the divider', '.diluxone-users-divider span'],
	['the note under the button', '.diluxone-users-note--icon span'],
	['the legal line', '.diluxone-users-login__legal'],
];

/** What is not readable on the page, one line per piece, or what is missing. */
async function trouble(page: Page): Promise<string[]> {
	const out: string[] = [];

	for (const [name, selector] of PIECES) {
		const all = await readings(page, selector);

		if (all.length === 0) {
			out.push(`${name}: not on the page`);
		}

		out.push(...unreadable(all).map((line) => `${name}: ${line}`));
	}

	return out;
}

test.describe('Issue 105: the sign-in page of its own', () => {
	test('in the dark scheme every piece of text keeps 4.5:1 against what is behind it', async ({ page, options }) => {
		await options.set({ ...DARK_SITE, ...OWN_PAGE });
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.goto('/sign-in/');

		await expect(page.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(page.locator('.diluxone-users-login-frame__box')).toBeVisible();

		await expect.poll(() => trouble(page), { message: 'every piece at 4.5:1 or more' }).toEqual([]);
	});

	test('the text is in the same face as on the shape “In the page”', async ({ page, pages, options }) => {
		const label = 'label[for="diluxone-users-email"]';

		await options.set({ ...OWN_PAGE, diluxone_users_styles: 1 });
		await page.goto('/sign-in/');
		await expect(page.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(page.locator(label)).toBeVisible();

		const own = await page.locator(label).evaluate((element) => getComputedStyle(element).fontFamily);

		await options.set({ diluxone_users_login_template: 'plain' });
		await page.goto(pages.login.url);
		await expect(page.locator('body.diluxone-users-door')).toHaveCount(0);
		await expect(page.locator(label)).toBeVisible();

		const inThePage = await page.locator(label).evaluate((element) => getComputedStyle(element).fontFamily);

		expect(own, 'the page of its own uses the theme’s face, as in the page does').toBe(inThePage);
	});
});
