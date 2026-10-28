import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { readings, themeSwitch, unreadable } from '../support/dark-mode';
import { accountSection } from '../support/ui';

/**
 * Issue #213 (after #215 fixed the headings, labels and legends): with the
 * plugin's own colours, the account area still leaves text under 4.5:1 on a
 * site whose dark scheme is a class on <html>.
 *
 * - The links inside the plugin's white boxes take the theme's dark-scheme link
 *   colour (light blue on white).
 * - The account menu and "Member since" are drawn in the plugin's grey on the
 *   site's dark page, outside any box of the plugin's.
 *
 * The reporter expects every visible text of the account area, links included,
 * to read at 4.5:1 or more against what is behind it, in both schemes.
 *
 * The stand-in theme is a stylesheet added to the page's head as it is served:
 * under `html.is-dark` its ground goes dark, its text light, and its headings
 * and links light.
 */

const STAND_IN_THEME = `<style id="stand-in-theme">
html.is-dark,html.is-dark body{background:#15171c;color:#e6e8ec}
html.is-dark :is(h1,h2,h3,h4,h5,h6){color:#ffffff}
html.is-dark a{color:#8ab4f8}
</style>`;

const IN_BOXES = '.diluxone-users-account .diluxone-users-panel a:not(.diluxone-users-button)';
const OUTSIDE_BOXES = '.diluxone-users-account__tab, .diluxone-users-account__since';

test.describe('Issue 213: the account area’s links, menu and “Member since” on a theme with a dark scheme', () => {
	test.beforeEach(async ({ options, guest, site, pages }) => {
		await options.set({
			diluxone_users_styles: 1,
			diluxone_e2e_colors_from_theme: 0,
			diluxone_e2e_dark: 0,
			diluxone_users_account_ground: '',
			diluxone_users_account_since: 1,
			diluxone_users_handle_enabled: 1,
		});

		await signInMember(guest, site, pages.login.url, 'dark-links');

		const path = new URL(accountSection(pages.account.url, 'details'), 'http://x').pathname;

		await guest.route(
			(url) => url.pathname === path,
			async (route) => {
				const response = await route.fetch();

				await route.fulfill({ response, body: (await response.text()).replace('</head>', `${STAND_IN_THEME}</head>`) });
			}
		);
		await guest.goto(accountSection(pages.account.url, 'details'));
	});

	for (const [scheme, dark] of [
		['dark', true],
		['light', false],
	] as const) {
		test(`in the site’s ${scheme} scheme every link in the boxes, the menu and “Member since” read at 4.5:1 against what is behind them`, async ({ guest }) => {
			await themeSwitch(guest, dark);

			const links = await readings(guest, IN_BOXES);
			const around = await readings(guest, OUTSIDE_BOXES);

			expect(links.length, 'the boxes have links to read').toBeGreaterThan(0);
			expect(around.length, 'the menu and “Member since” are on the page').toBeGreaterThan(0);
			expect(unreadable([...links, ...around]), 'every link, menu entry and “Member since” at 4.5:1 or more').toEqual([]);
		});
	}
});
