import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { accountSection, openAllPanels, openPanel } from '../support/ui';
import { anotherBrowser, reveal, signedIn } from '../support/my-account';

/**
 * The account area on a phone, 390 pixels wide: what the stylesheet's
 * narrow-screen rules do to the menu, the side layout, the cover and the rows
 * of the lists. Behaviour, not pictures — the computed style the rule sets,
 * and where things land on the screen.
 */

test.use({ viewport: { width: 390, height: 844 } });

/** Five sections of the site's own on top of the plugin's: more than a phone's width of tabs. */
const MANY = Object.fromEntries(
	['alpha', 'bravo', 'charlie', 'delta', 'echo'].map((id, n) => [
		`e2e${id}`,
		{ custom: 1, label: `Section ${id}`, content: `<p>${id}</p>`, position: 80 + n },
	])
);

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: [{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }],
		diluxone_users_2fa_mode: 'off',
		diluxone_users_account_layout: 'tabs',
		diluxone_users_account_template: 'plain',
		diluxone_users_account_nav_style: 'pills',
		diluxone_users_account_sections: MANY,
		diluxone_users_sessions_show: 1,
	});
});

/**
 * Nothing the plugin draws runs past the right edge of the phone.
 *
 * Asked of the account area's own elements and not of the whole document:
 * WordPress's toolbar carries the person's name in a menu of its own, and a
 * long address there widens the page whatever the plugin does. What sits
 * inside a strip that scrolls on purpose is out of reach by design.
 */
async function expectNoSideScroll(page: Page): Promise<void> {
	const past = await page.evaluate(() =>
		[...document.querySelectorAll('.diluxone-users-account *')]
			.filter((element) => {
				const scroller = element.parentElement?.closest('.diluxone-users-account__nav--row');

				return !(scroller && getComputedStyle(scroller).overflowX === 'auto') && element.getBoundingClientRect().right > 391;
			})
			.map((element) => `${element.tagName.toLowerCase()}.${String(element.className).split(' ')[0]}`)
	);

	expect(past, 'the account area runs past the edge of the phone').toEqual([]);
}

test('too many tabs: “scroll” keeps them on one line that scrolls, “wrap” lays them out in a grid that fits', async ({ page, site, pages, options }) => {
	await signedIn(page, site, pages.login.url, 'mob-nav');

	const nav = page.locator('.diluxone-users-account .diluxone-users-account__nav--row');

	await options.set({ diluxone_users_account_nav_small: 'scroll' });
	await page.goto(pages.account.url);
	await expect(page.locator('.diluxone-users-account')).toHaveClass(/diluxone-users-account--nav-scroll/);
	expect(await nav.evaluate((el) => getComputedStyle(el).overflowX)).toBe('auto');
	expect(await nav.evaluate((el) => getComputedStyle(el).flexWrap)).toBe('nowrap');
	expect(await nav.evaluate((el) => el.scrollWidth > el.clientWidth), 'more tabs than room: it scrolls').toBe(true);
	await expectNoSideScroll(page);

	await options.set({ diluxone_users_account_nav_small: 'wrap' });
	await page.goto(pages.account.url);
	await expect(page.locator('.diluxone-users-account')).toHaveClass(/diluxone-users-account--nav-wrap/);
	expect(await nav.evaluate((el) => getComputedStyle(el).display)).toBe('grid');

	for (const box of await nav.locator('a.diluxone-users-account__tab').evaluateAll((all) => all.map((a) => a.getBoundingClientRect().right))) {
		expect(box, 'every tab inside the screen').toBeLessThanOrEqual(390);
	}

	await expectNoSideScroll(page);
});

test('the side menu goes back across the top, and an underline becomes a line under the open tab', async ({ page, site, pages, options }) => {
	await options.set({ diluxone_users_account_layout: 'side', diluxone_users_account_nav_style: 'underline', diluxone_users_account_sections: {} });
	await signedIn(page, site, pages.login.url, 'mob-side');
	await page.goto(pages.account.url);

	const body = page.locator('.diluxone-users-account__body');
	const nav = body.locator('.diluxone-users-account__nav--column');
	const section = body.locator('.diluxone-users-account__section');

	expect((await body.evaluate((el) => getComputedStyle(el).gridTemplateColumns)).trim().split(/\s+/).length, 'one column').toBe(1);

	const navBox = (await nav.boundingBox())!;
	const sectionBox = (await section.boundingBox())!;

	expect(navBox.y + navBox.height, 'the menu sits above the section').toBeLessThanOrEqual(sectionBox.y + 1);
	expect(await nav.evaluate((el) => getComputedStyle(el).flexDirection)).toBe('row');

	const currentTab = nav.locator('a.diluxone-users-account__tab.is-current');

	expect(await currentTab.evaluate((el) => getComputedStyle(el).borderBottomWidth)).toBe('3px');
	expect(await currentTab.evaluate((el) => getComputedStyle(el).borderLeftWidth)).toBe('0px');
	await expectNoSideScroll(page);
});

test('the cover’s header wraps on a phone, button and all, and nothing runs off the side', async ({ page, site, pages, options }) => {
	await options.set({
		diluxone_users_account_template: 'cover',
		diluxone_users_account_header: 1,
		diluxone_users_account_avatar: 1,
		diluxone_users_account_since: 1,
		diluxone_users_account_action: 1,
		diluxone_users_account_sections: {},
	});
	await signedIn(page, site, pages.login.url, 'mob-cover', { meta: { first_name: 'Maximiliano', last_name: 'Bartolomeo-Etchegaray' } });
	await page.goto(pages.account.url);

	const inner = page.locator('.diluxone-users-account__header-inner');

	expect(await inner.evaluate((el) => getComputedStyle(el).flexWrap)).toBe('wrap');

	const action = (await page.locator('.diluxone-users-account__action').boundingBox())!;

	expect(action.x + action.width, 'the button inside the screen').toBeLessThanOrEqual(390);
	await expectNoSideScroll(page);
});

test('a session row puts its Close under the text', async ({ page, browser, baseURL, site, pages, options }) => {
	await options.set({ diluxone_users_account_sections: {} });

	const { email } = await signedIn(page, site, pages.login.url, 'mob-rows');
	const other = await anotherBrowser(browser, baseURL, email, pages.login.url);

	await page.goto(accountSection(pages.account.url, 'security'));
	await reveal(page, '.diluxone-users-sessions__list');

	const row = page.locator('.diluxone-users-session:not(.diluxone-users-session--current)');
	const what = row.locator('.diluxone-users-session__what');
	const close = row.locator('button[type="submit"]');

	expect(await what.evaluate((el) => getComputedStyle(el).order)).toBe('2');

	const whatBox = (await what.boundingBox())!;
	const closeBox = (await close.boundingBox())!;

	expect(closeBox.y, 'the Close button below the text').toBeGreaterThanOrEqual(whatBox.y + whatBox.height - 1);
	await expectNoSideScroll(page);
	await other.context().close();
});

test.describe('A passkey on a phone', () => {
	test.skip(({ browserName }) => browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

	test('the open key’s form keeps a narrow inset', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_account_sections: {},
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
		});
		await signedIn(page, site, pages.login.url, 'mob-key');

		const cdp = await page.context().newCDPSession(page);

		await cdp.send('WebAuthn.enable', { enableUI: false });
		await cdp.send('WebAuthn.addVirtualAuthenticator', {
			options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
		});

		await page.goto(accountSection(pages.account.url, 'security'));
		await (await openPanel(page, '[data-diluxone-users-passkey="register"]')).locator('[data-diluxone-users-passkey="register"]').click();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveCount(1, { timeout: 20_000 });
		await openAllPanels(page);

		expect(await page.locator('.diluxone-users-key__edit').evaluate((el) => getComputedStyle(el).paddingLeft)).toBe('16px');
		await expectNoSideScroll(page);
	});
});
