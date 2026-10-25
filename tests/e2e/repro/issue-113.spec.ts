import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { DARK_SITE, readings, unreadable } from '../support/dark-mode';

/**
 * Issue #113: on the shape "A page of its own", with Colours › the theme's, on
 * a theme whose own stylesheet flips its colours under
 * `prefers-color-scheme: dark`, a visitor with nothing measured before who
 * opens the sign-in page in dark mode gets the ground and the card dark and
 * the text tokens at their light-scheme defaults: dark text on a dark card.
 *
 * The stand-in theme is a stylesheet added to the page's head as the page is
 * served: its colour variables flip under the media query, it paints `html`
 * and `body` with them, and the roles that name a ground (surface, second
 * surface) are the theme's variables, as the plugin prints them for a theme
 * whose palette says which colour is the ground. The text roles are not named
 * by any palette, so they are left to what the page measures for itself. The
 * context is a fresh one, so there is no measurement kept from another page.
 */

const OWN_PAGE = {
	diluxone_users_login_template: 'own',
	diluxone_users_login_slug: 'sign-in',
	diluxone_users_login_slug_old: [],
	diluxone_users_login_method: 'both',
	diluxone_users_login_layout: 'stack',
	diluxone_users_sso_login: 0,
	diluxone_e2e_sso: 0,
	diluxone_users_login_intro: 'Welcome back, sign in to carry on.',
	diluxone_users_login_legal: 'By signing in you accept the terms.',
};

const STAND_IN_THEME = `<style id="stand-in-theme">
:root{--cst-fondo:#ffffff;--cst-fondo-alt:#f5f6f8;--cst-texto:#1b1d22;--cst-texto-tenue:#4a505c}
@media (prefers-color-scheme: dark){:root{--cst-fondo:#15171c;--cst-fondo-alt:#1c1f26;--cst-texto:#e6e8ec;--cst-texto-tenue:#aeb4c0}}
html,body{background:var(--cst-fondo);color:var(--cst-texto)}
:root{--diluxone-users-surface:var(--cst-fondo);--diluxone-users-surface-alt:var(--cst-fondo-alt)}
</style>`;

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

test.describe('Issue 113: the sign-in page of its own on a theme that flips under prefers-color-scheme', () => {
	test('opened in dark mode with nothing measured before, every piece of text keeps 4.5:1 against what is behind it', async ({ page, options }) => {
		// DARK_SITE gives a theme with no palette of its own to read (the
		// e2e mu-plugin's), so every role but the two grounds above is measured.
		await options.set({ ...DARK_SITE, ...OWN_PAGE });
		await page.emulateMedia({ colorScheme: 'dark' });

		await page.route(
			(url) => url.pathname === '/sign-in/',
			async (route) => {
				const response = await route.fetch();

				await route.fulfill({ response, body: (await response.text()).replace('</head>', `${STAND_IN_THEME}</head>`) });
			}
		);

		await page.goto('/sign-in/');

		await expect(page.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(page.locator('#stand-in-theme')).toHaveCount(1);
		await expect(page.locator('.diluxone-users-login-frame__box')).toBeVisible();

		await expect.poll(() => trouble(page), { message: 'every piece at 4.5:1 or more' }).toEqual([]);
	});
});
