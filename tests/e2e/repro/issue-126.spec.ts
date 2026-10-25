import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { DARK_SITE, STAND_IN_THEME, unreadableOn } from '../support/dark-mode';

/**
 * Issue #126: with Colours › the theme's, on a theme whose buttons keep a
 * mid-dark brand background in dark mode and whose links turn lighter, the
 * plugin takes the button's background as its accent and writes the links of
 * the account area's home cards in it, on a dark card: far under 4.5:1.
 *
 * The stand-in theme is the one of `repro/issue-113` (`STAND_IN_THEME`) plus
 * a button at #1839BE in both schemes and links at #93A6FF in dark, added to
 * the page's head as the page is served. The context is a fresh one, so
 * nothing measured before is kept.
 */

const THEME_BUTTONS_AND_LINKS = `<style id="stand-in-buttons">
.wp-block-button__link,.wp-element-button{background:#1839be;color:#ffffff}
a{color:#2b3fa0}
@media (prefers-color-scheme: dark){a{color:#93a6ff}}
</style>`;

const CTA = '.diluxone-users-card-summary__cta';

test.describe('Issue 126: the accent as a text colour on a dark ground', () => {
	test('Colours › the theme’s, in dark mode on a theme with a dark button and a light link: the home cards’ links keep 4.5:1 against the card', async ({ guest, site, pages, baseURL, options }) => {
		await options.set({ ...DARK_SITE, diluxone_users_home_cards_off: [] });
		await guest.emulateMedia({ colorScheme: 'dark' });
		await signInMember(guest, site, pages.login.url, 'accent-text');

		const pathname = new URL(pages.account.url, baseURL).pathname;

		await guest.route(
			(url) => url.pathname === pathname,
			async (route) => {
				const response = await route.fetch();

				await route.fulfill({ response, body: (await response.text()).replace('</head>', `${STAND_IN_THEME}${THEME_BUTTONS_AND_LINKS}</head>`) });
			}
		);

		await guest.goto(pages.account.url);

		await expect(guest.locator('#stand-in-buttons')).toHaveCount(1);
		await expect(guest.locator(CTA).first()).toBeVisible();

		await expect
			.poll(() => unreadableOn(guest, 'the cards’ links', CTA), { message: 'every card link at 4.5:1 or more against its card' })
			.toEqual([]);
	});
});
