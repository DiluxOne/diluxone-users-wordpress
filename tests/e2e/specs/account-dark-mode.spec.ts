import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { DARK_GROUND, DARK_SITE, groundBehind, readings, themeSwitch, unreadable } from '../support/dark-mode';

/**
 * The account area on a site with a dark mode, with Colours › the theme's.
 *
 * The site is the e2e mu-plugin's stand-in (`diluxone_e2e_dark`): a theme
 * with no palette, whose ground goes dark and text light when the system asks
 * for it and when its own switch puts `is-dark` on <html>. Readable is the
 * WCAG bar, 4.5:1, between every heading of the area and the ground actually
 * painted behind it.
 */

const AREA = '.diluxone-users-account';
const HEADINGS = `${AREA} :is(h1, h2, h3, h4, h5, h6)`;

async function headingsRead(page: Page): Promise<void> {
	const all = await readings(page, HEADINGS);

	expect(all.length, 'the area has headings to read').toBeGreaterThan(0);
	expect(unreadable(all), 'every heading at 4.5:1 or more').toEqual([]);
}

test.describe('The account area in dark mode › the ground “The theme’s”', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ ...DARK_SITE, diluxone_users_account_ground: '' });
	});

	test('opened in the system’s dark mode, the area sits on the page’s own ground and every heading reads', async ({ guest, site, pages }) => {
		await guest.emulateMedia({ colorScheme: 'dark' });
		await signInMember(guest, site, pages.login.url, 'dark-open');

		await guest.goto(pages.account.url);
		await expect.poll(() => groundBehind(guest, AREA), { message: 'the page’s own ground' }).toBe(DARK_GROUND);
		expect(await guest.locator(AREA).evaluate((element) => getComputedStyle(element).backgroundColor), 'no ground of its own').toBe('rgba(0, 0, 0, 0)');
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS))).toEqual([]);
		await headingsRead(guest);
	});

	test('each scheme opens with what was measured in it, before anything measures again', async ({ guest, site, pages }) => {
		await signInMember(guest, site, pages.login.url, 'dark-kept');

		await guest.emulateMedia({ colorScheme: 'light' });
		await guest.goto(pages.account.url);
		await headingsRead(guest);

		await guest.emulateMedia({ colorScheme: 'dark' });
		await guest.goto(pages.account.url);
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS))).toEqual([]);

		// From here on only what the browser kept paints the theme's colours.
		await guest.route('**/diluxone-users-theme-measure.js*', (route) => route.abort());

		await guest.emulateMedia({ colorScheme: 'light' });
		await guest.goto(pages.account.url);
		await headingsRead(guest);

		await guest.emulateMedia({ colorScheme: 'dark' });
		await guest.goto(pages.account.url);
		expect(await groundBehind(guest, AREA)).toBe(DARK_GROUND);
		await headingsRead(guest);
	});

	test('the system switching to dark mode on an open page is measured again', async ({ guest, site, pages }) => {
		await guest.emulateMedia({ colorScheme: 'light' });
		await signInMember(guest, site, pages.login.url, 'dark-switch');

		await guest.goto(pages.account.url);
		await headingsRead(guest);

		await guest.emulateMedia({ colorScheme: 'dark' });
		await expect.poll(() => groundBehind(guest, AREA)).toBe(DARK_GROUND);
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS)), { message: 'every heading at 4.5:1 or more' }).toEqual([]);

		// What the light page measured is still the light page's.
		await guest.emulateMedia({ colorScheme: 'light' });
		await expect.poll(() => groundBehind(guest, AREA)).not.toBe(DARK_GROUND);
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS))).toEqual([]);
	});

	test('the theme’s own switch (a class on <html>) is measured again too', async ({ guest, site, pages }) => {
		await guest.emulateMedia({ colorScheme: 'light' });
		await signInMember(guest, site, pages.login.url, 'dark-class');

		await guest.goto(pages.account.url);
		await headingsRead(guest);

		await themeSwitch(guest, true);
		await expect.poll(() => groundBehind(guest, AREA)).toBe(DARK_GROUND);
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS)), { message: 'every heading at 4.5:1 or more' }).toEqual([]);

		await themeSwitch(guest, false);
		await expect.poll(() => groundBehind(guest, AREA)).not.toBe(DARK_GROUND);
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS))).toEqual([]);
	});
});
