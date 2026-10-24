import { test, expect } from '../support/fixtures';
import { devWp } from '../support/admin-ops';
import { ASTRA, activateTheme, astraDark, cardEdge } from '../support/themes';

/**
 * The centred card on the sign-in page, on someone else's theme.
 *
 * The card stands on the page's own ground, so the edge is what separates
 * the two: at 3:1 or more against the ground painted behind the frame, with
 * the plugin's colours left to the theme and no site CSS.
 *
 * Activating a theme changes it for the whole site, so these run one at a
 * time and put the theme back when they end.
 */

test.describe.configure({ mode: 'serial' });

/** The card, with the plugin's styles on and its colours the theme's. */
const CARD = { diluxone_users_login_template: 'card', diluxone_users_styles: 1, diluxone_users_colors: 'theme' };

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
		await guest.emulateMedia({ colorScheme: 'dark' });
		await guest.goto(pages.login.url);

		await expect.poll(async () => (await cardEdge(guest)).ratio, { message: 'the edge against the page' }).toBeGreaterThanOrEqual(3);
	});
});
