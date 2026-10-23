import { Page } from '@playwright/test';
import { test, expect, whoOn, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { signInWithPassword } from '../support/ui';
import { DARK_GROUND, DARK_SITE, groundBehind, readings, themeSwitch, unreadable } from '../support/dark-mode';

/**
 * The hub's account area on a network with a dark mode, with Colours › the
 * theme's: the same claims as the single site's suite, on the one site of the
 * network that draws the area. The site is the e2e mu-plugin's stand-in
 * (`diluxone_e2e_dark`); readable is 4.5:1 against the ground painted behind.
 */

const PASSWORD = 'e2e-NetDark-1!';
const AREA = '.diluxone-users-account';
const HEADINGS = `${AREA} :is(h1, h2, h3, h4, h5, h6)`;

async function member(page: Page, hub: SiteHandle, prefix: string): Promise<void> {
	const email = freshEmail(prefix);

	await hub.site.makeUser({ email, password: PASSWORD });
	await page.goto(hub.pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await page.waitForLoadState('domcontentloaded');
	expect(await whoOn(page, hub.url)).toBe(email);
}

async function headingsRead(page: Page): Promise<void> {
	const all = await readings(page, HEADINGS);

	expect(all.length, 'the area has headings to read').toBeGreaterThan(0);
	expect(unreadable(all), 'every heading at 4.5:1 or more').toEqual([]);
}

test.describe('The hub’s account area in dark mode › the ground “The theme’s”', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.set({ ...DARK_SITE, diluxone_users_account_ground: '' });
	});

	test('in the system’s dark mode the area sits on the page’s own ground, every heading reads, and the next page opens dark', async ({ guest, hub }) => {
		await member(guest, hub, 'nd-open');

		await guest.emulateMedia({ colorScheme: 'light' });
		await guest.goto(hub.pages.account.url);
		await headingsRead(guest);

		await guest.emulateMedia({ colorScheme: 'dark' });
		await expect.poll(() => groundBehind(guest, AREA), { message: 'the page’s own ground' }).toBe(DARK_GROUND);
		expect(await guest.locator(AREA).evaluate((element) => getComputedStyle(element).backgroundColor), 'no ground of its own').toBe('rgba(0, 0, 0, 0)');
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS))).toEqual([]);

		await guest.route('**/diluxone-users-theme-measure.js*', (route) => route.abort());
		await guest.goto(hub.pages.account.url);
		await headingsRead(guest);
	});

	test('the theme’s own switch (a class on <html>) is measured again', async ({ guest, hub }) => {
		await guest.emulateMedia({ colorScheme: 'light' });
		await member(guest, hub, 'nd-class');

		await guest.goto(hub.pages.account.url);
		await headingsRead(guest);

		await themeSwitch(guest, true);
		await expect.poll(() => groundBehind(guest, AREA)).toBe(DARK_GROUND);
		await expect.poll(async () => unreadable(await readings(guest, HEADINGS)), { message: 'every heading at 4.5:1 or more' }).toEqual([]);
	});
});
