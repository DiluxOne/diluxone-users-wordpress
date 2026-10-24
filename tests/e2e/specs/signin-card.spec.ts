import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { devWp } from '../support/admin-ops';
import { uploadImage } from '../support/signin';
import { ASTRA, activateTheme, astraDark, cardEdge, cardPieces } from '../support/themes';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The centred card on the sign-in page, on someone else's theme.
 *
 * The card stands on the page's own ground, so the edge is what separates
 * the two: at 3:1 or more against the ground painted behind the frame. The
 * logo, the heading, the introduction and the form share one alignment, the
 * centre. All of it with the plugin's colours left to the theme and no site
 * CSS.
 *
 * Activating a theme changes it for the whole site, so these run one at a
 * time and put the theme back when they end.
 */

test.describe.configure({ mode: 'serial' });

/** The card, with the plugin's styles on, its colours the theme's, and every piece it can draw. */
const CARD = {
	diluxone_users_login_template: 'card',
	diluxone_users_styles: 1,
	diluxone_users_colors: 'theme',
	diluxone_users_login_method: 'link',
	diluxone_users_login_title: 'E2E card heading',
	diluxone_users_login_intro: 'E2E card introduction, long enough to run onto a second line inside the box',
	diluxone_users_login_legal: 'E2E card small print',
	diluxone_users_login_logo_show: 1,
};

async function opened(page: Page, url: string): Promise<void> {
	await page.emulateMedia({ colorScheme: 'dark' });
	await page.goto(url);
	await expect(page.locator('.diluxone-users-login-frame--card .diluxone-users-login')).toBeVisible();
}

test.describe('The centred card on Astra, in dark mode', () => {
	const undo: Array<() => void> = [];

	test.beforeAll(() => {
		undo.push(activateTheme(devWp, ASTRA));
		undo.push(astraDark(devWp));
	});

	test.afterAll(() => {
		for (const step of undo.reverse()) {
			step();
		}
	});

	test('C1: the card’s edge is at 3:1 or more against the page', async ({ guest, pages, options }) => {
		await options.set(CARD);
		await opened(guest, pages.login.url);

		await expect.poll(async () => (await cardEdge(guest)).ratio, { message: 'the edge against the page' }).toBeGreaterThanOrEqual(3);
	});

	test('C2: the logo, the heading, the introduction and the form share one alignment, the centre', async ({ browser, baseURL, guest, pages, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({ ...CARD, diluxone_users_login_logo: image.id });
			await opened(guest, pages.login.url);

			const pieces = await cardPieces(guest);

			expect(pieces.filter((piece) => piece.offset > 1).map((piece) => `${piece.what} is ${piece.offset.toFixed(1)}px off the centre`), 'centred in the box').toEqual([]);
			expect(pieces.filter((piece) => piece.what !== 'form' && piece.align !== 'center').map((piece) => `${piece.what}: ${piece.align}`), 'text-align: center').toEqual([]);
		} finally {
			await image.forget();
		}
	});
});
