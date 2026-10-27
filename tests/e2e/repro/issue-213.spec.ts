import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { readings, themeSwitch, unreadable } from '../support/dark-mode';
import { accountSection } from '../support/ui';

/**
 * Issue #213: with the plugin's own colours (the free plugin alone), the boxes
 * of the account area have a light ground but do not set the colour of the
 * headings and text inside them. On a theme whose dark scheme turns headings
 * (and text) light under a class on <html>, "Your public name" is white on the
 * box's white. The reporter expects every heading and text in the boxes to be
 * at least 4.5:1 against the box, whatever scheme the site is in.
 *
 * The stand-in theme is a stylesheet added to the page's head as it is served:
 * under `html.is-dark` its ground goes dark, and its headings and text light.
 */

const STAND_IN_THEME = `<style id="stand-in-theme">
html.is-dark,html.is-dark body{background:#15171c;color:#e6e8ec}
html.is-dark :is(h1,h2,h3,h4,h5,h6){color:#ffffff}
</style>`;

const BOXES_TEXT = '.diluxone-users-account .diluxone-users-panel :is(h1, h2, h3, h4, h5, h6, label, legend, p)';

test.describe('Issue 213: the plugin’s own colours on a theme with a dark scheme', () => {
	test.beforeEach(async ({ options, guest, site, pages }) => {
		await options.set({
			diluxone_users_styles: 1,
			diluxone_e2e_colors_from_theme: 0,
			diluxone_e2e_dark: 0,
			diluxone_users_account_ground: '',
		});

		await signInMember(guest, site, pages.login.url, 'dark-own');

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

	test('in the site’s dark scheme every heading and text in the plugin’s boxes reads at 4.5:1 against its box', async ({ guest }) => {
		await themeSwitch(guest, true);

		const all = await readings(guest, BOXES_TEXT);

		expect(all.length, 'the boxes have headings and text to read').toBeGreaterThan(0);
		expect(unreadable(all), 'every heading and text at 4.5:1 or more').toEqual([]);
	});

	test('in the site’s light scheme every heading and text in the plugin’s boxes reads at 4.5:1 against its box', async ({ guest }) => {
		await themeSwitch(guest, false);

		const all = await readings(guest, BOXES_TEXT);

		expect(all.length, 'the boxes have headings and text to read').toBeGreaterThan(0);
		expect(unreadable(all), 'every heading and text at 4.5:1 or more').toEqual([]);
	});
});
