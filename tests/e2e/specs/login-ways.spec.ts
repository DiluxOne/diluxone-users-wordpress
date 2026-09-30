import { test, expect } from '../support/fixtures';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';
import type { Locator, Page } from '@playwright/test';

/**
 * The ways into the site: how many of them fit, which one opens, and in what
 * order they are drawn.
 *
 * The complaint this suite comes from was one sentence — "no entra todo, hay
 * mucho scroll" — and it was true on a laptop with all four ways in switched
 * on, because the screen stacked them and nobody had decided it would. So the
 * first test is a measurement and not a look: with the four doors and the tabs
 * the page fits the window, and with the same four stacked it does not. Both
 * halves are asserted. A "it fits" that would also pass on the broken
 * arrangement proves nothing at all, and the second assertion is what stops
 * this one quietly becoming that.
 *
 * Everything is found by an id, a class or a data attribute. The site runs in
 * Spanish and the plugin ships eight locales: a locator that reads "Password"
 * is a locator that passes here and fails on the next machine.
 */

/** The laptops the complaint came from, and the floor under them. */
const LAPTOPS = [
	{ width: 1366, height: 768 },
	{ width: 1280, height: 720 },
] as const;

/** Every way in this plugin has, switched on at once. */
const ALL_FOUR = {
	diluxone_users_login_method: 'both',
	diluxone_users_passkey_enabled: 1,
	diluxone_users_sso_login: 1,
	diluxone_e2e_sso: 1,
	diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
	// A shape that takes the page over, because that is the page this is
	// about: a screen whose only job is to be signed in on.
	diluxone_users_login_template: 'card',
};

/* ── Where things are ──────────────────────────────────────────────── */

/** The block holding every way in. */
function ways(page: Page): Locator {
	return page.locator('[data-diluxone-users-ways]');
}

/** The tab strip, which only exists once a script has shown it. */
function strip(page: Page): Locator {
	return page.locator('[data-diluxone-users-ways-strip]');
}

/** One tab, by the id of the way in it opens. */
function tab(page: Page, way: string): Locator {
	return page.locator(`[data-diluxone-users-way-tab="${way}"]`);
}

/** One way in's own block. */
function panel(page: Page, way: string): Locator {
	return page.locator(`[data-diluxone-users-way="${way}"]`);
}

/** The ids of the tabs, in the order the strip draws them. */
function tabOrder(page: Page): Promise<string[]> {
	return page.$$eval('[data-diluxone-users-way-tab]', (nodes) =>
		nodes.map((node) => node.getAttribute('data-diluxone-users-way-tab') ?? '')
	);
}

/** The ids of the blocks, in the order the page draws them. */
function panelOrder(page: Page): Promise<string[]> {
	return page.$$eval('[data-diluxone-users-way]', (nodes) =>
		nodes.map((node) => node.getAttribute('data-diluxone-users-way') ?? '')
	);
}

/** Which tab is open, asked of the ARIA rather than of a class. */
async function openTab(page: Page): Promise<string> {
	return (
		(await page
			.locator('[data-diluxone-users-way-tab][aria-selected="true"]')
			.getAttribute('data-diluxone-users-way-tab')) ?? ''
	);
}

/**
 * The card the plugin draws, and the window it is read in.
 *
 * The card and not `document.scrollHeight`, and the difference is the point:
 * this theme opens every page with 280 pixels of its own header and closes it
 * with a footer, and neither is this plugin's to shorten. What the complaint
 * was about — and all this can answer for — is whether the thing somebody
 * came to use is on screen when they arrive.
 */
function heights(page: Page) {
	return page.evaluate(() => {
		const card = document.querySelector('.diluxone-users-login-frame');
		const box = card ? card.getBoundingClientRect() : null;

		return {
			card: box ? Math.round(box.bottom) : null,
			scrolled: Math.round(window.scrollY),
			window: window.innerHeight,
			sideways: document.documentElement.scrollWidth - document.documentElement.clientWidth,
		};
	});
}

/* ── The height, which is what started this ────────────────────────── */

test.describe('The sign-in page with every way in switched on', () => {
	test.beforeEach(async ({ options }) => {
		await options.set(ALL_FOUR);
	});

	/**
	 * The one the complaint was about, and its own control.
	 *
	 * Stacked first, on purpose: if the four doors one under the other fitted
	 * a laptop there would be nothing here to fix, and this test would be
	 * green for the wrong reason for ever. It has to be too tall before "in
	 * tabs it fits" is worth asserting.
	 */
	test('in tabs it fits the laptop it is read on; stacked it does not', async ({ guest, pages, options }) => {
		for (const size of LAPTOPS) {
			const where = `${size.width}×${size.height}`;

			await guest.setViewportSize(size);

			await options.set({ diluxone_users_login_layout: 'stack' });
			await guest.goto(pages.login.url);

			const stacked = await heights(guest);

			expect(stacked.card, `${where}: the takeover card was not drawn at all`).not.toBeNull();
			expect(stacked.scrolled, `${where}: measured from the top, where somebody who has just arrived is`).toBe(0);

			expect(
				stacked.card!,
				`${where}: stacked, the four ways in already fit — there is nothing for tabs to fix and this test proves nothing`
			).toBeGreaterThan(stacked.window);

			await options.set({ diluxone_users_login_layout: 'tabs' });
			await guest.goto(pages.login.url);

			const tabbed = await heights(guest);

			await expect(strip(guest), `${where}: the tab strip never appeared`).toBeVisible();

			// A pixel of slack for a browser that rounds a fractional layout up.
			expect(
				tabbed.card!,
				`${where}: the sign-in card still does not fit without scrolling`
			).toBeLessThanOrEqual(tabbed.window + 1);
			expect(tabbed.sideways, `${where}: and nothing on it may scroll sideways`).toBeLessThanOrEqual(1);
		}
	});

	/**
	 * The passkey is above the tabs, not one of them.
	 *
	 * It is the fastest way in there is; behind a tab it costs a click, and
	 * the browser never gets to offer it as the address field is focused.
	 */
	test('the passkey stays outside the strip and on screen', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_login_layout: 'tabs' });
		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		await expect(panel(guest, 'passkey')).toBeVisible();
		await expect(tab(guest, 'passkey'), 'the passkey was given a tab of its own').toHaveCount(0);

		const strips = await strip(guest).boundingBox();
		const key = await panel(guest, 'passkey').boundingBox();

		expect(key!.y, 'the passkey belongs above the strip').toBeLessThan(strips!.y);
	});
});

/* ── Which tab opens ───────────────────────────────────────────────── */

test.describe('The tab that opens', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ ...ALL_FOUR, diluxone_users_login_layout: 'tabs' });
	});

	/**
	 * The first time, the site decides; after that, the person does.
	 *
	 * The second half is the whole reason for the cookie, and it is asked the
	 * long way round: press the tab, then load the page again. Reading the
	 * cookie back would only prove that a script can write one.
	 */
	test('is the site’s choice for a stranger and the last one used after that', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_login_open: 'email' });

		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		expect(await openTab(guest), 'a browser that has never been here opens the tab the site chose').toBe('email');

		await tab(guest, 'password').click();
		expect(await openTab(guest), 'pressing a tab opens it').toBe('password');

		await guest.goto(pages.login.url);
		expect(await openTab(guest), 'coming back, the tab that opens is the one used last').toBe('password');

		// And the site's choice does not overrule it: that setting is for a
		// first visit, and this browser has had one.
		await guest.reload();
		expect(await openTab(guest), 'the site’s first-visit choice came back over the person’s own').toBe('password');
	});

	/**
	 * A screen answering a failed attempt shows the form that failed.
	 *
	 * A message about an address, read over a password form, explains nothing.
	 * So this one case overrules both the cookie and the setting.
	 */
	test('is the one that just failed, whatever the cookie says', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_login_open: 'password' });

		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);
		await tab(guest, 'password').click();

		await guest.goto(`${pages.login.url}?diluxone-users=email`);

		expect(await openTab(guest), 'the address form has to be the one showing').toBe('email');
	});
});

/* ── The order ─────────────────────────────────────────────────────── */

test.describe('The order the site chose', () => {
	const REVERSED = ['password', 'email', 'social'];

	test.beforeEach(async ({ options }) => {
		await options.set({ ...ALL_FOUR, diluxone_users_login_order: REVERSED });
	});

	test('is the order of the tabs', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_login_layout: 'tabs' });

		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		expect(await tabOrder(guest)).toEqual(REVERSED);
	});

	/**
	 * And stacked it is the order down the page, which is the half that is
	 * easy to forget: with nothing behind a tab, the order IS what somebody
	 * sees first.
	 */
	test('is the order down the page when they are stacked', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_login_layout: 'stack' });

		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		// The passkey is always first and never in the order: it is outside
		// the tabs by design, so the order applies to the three under it.
		expect(await panelOrder(guest)).toEqual(['passkey', ...REVERSED]);
	});
});

/* ── With the script switched off ──────────────────────────────────── */

test.describe('With JavaScript off', () => {
	test.use({ javaScriptEnabled: false });

	test.beforeEach(async ({ options }) => {
		await options.set({ ...ALL_FOUR, diluxone_users_login_layout: 'tabs' });
	});

	/**
	 * Every way in is on the screen, and the strip is not.
	 *
	 * This is the rule the whole arrangement rests on: what a script would
	 * hide starts visible. A tab strip that shows up without the script that
	 * makes it work is three buttons that do nothing over one form.
	 */
	test('every way in is on the screen and nothing is behind a tab', async ({ guest, pages }) => {
		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		await expect(ways(guest)).toBeVisible();

		for (const way of ['passkey', 'social', 'email', 'password']) {
			await expect(panel(guest, way), `${way} is not on the screen`).toBeVisible();
		}

		await expect(strip(guest), 'a strip of tabs nothing can open').toBeHidden();
	});
});

/* ── The dashboard end of it ───────────────────────────────────────── */

/**
 * The settings screen, and the trip from it to the page.
 *
 * Reordering is a drag, and a drag is the one gesture a browser automated
 * from outside is worst at: the HTML5 drag events a real mouse fires are not
 * the ones a synthetic one does, and a test that fights that ends up asserting
 * the state of Playwright. So what is proved here is everything on either side
 * of the gesture — the list the drag moves, the form it travels in, the save
 * that reads it, and the page that comes out — with the rows moved the way the
 * script moves them. The drag itself is thirty lines shared with the account
 * sections, and it is the same thirty lines there.
 */
test.describe('The arrangement tab', () => {
	test.use({ storageState: ADMIN_STATE });

	test.beforeEach(async ({ options }) => {
		await options.set(ALL_FOUR);
		await options.keep(['diluxone_users_login_layout', 'diluxone_users_login_order']);
	});

	test('saves a new order, and the sign-in page draws it', async ({ page, guest, pages }) => {
		await page.goto(adminUrl('diluxone-users-login', 'arrangement'));

		const rows = page.locator('[data-diluxone-users-sortable] li');

		await expect(rows, 'the three ways in that can be tabs, and only those').toHaveCount(3);

		// What the drag does to the DOM, done to the DOM: the last row to the
		// top. The hidden input in each row is what travels, so moving the
		// rows is the whole of it.
		await page.locator('[data-diluxone-users-sortable]').evaluate((list: HTMLElement) => {
			list.insertBefore(list.lastElementChild!, list.firstElementChild);
		});

		const asked = await page.$$eval('[data-diluxone-users-sortable] input[type="hidden"]', (nodes) =>
			nodes.map((node) => (node as HTMLInputElement).value)
		);

		await page.locator('[data-diluxone-users-save] [form="diluxone-users-arrangement"]').click();
		await expect(page.locator('.notice, .updated').first(), 'the save said nothing').toBeVisible();

		// It came back written, and the screen shows what was written.
		expect(
			await page.$$eval('[data-diluxone-users-sortable] input[type="hidden"]', (nodes) =>
				nodes.map((node) => (node as HTMLInputElement).value)
			),
			'the list came back in the order it was sent in'
		).toEqual(asked);

		// And the only reading that counts: the page a stranger sees.
		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		expect(await tabOrder(guest), 'the sign-in page ignored the order that was saved').toEqual(asked);
	});

	test('saves the arrangement itself', async ({ page, guest, pages }) => {
		await page.goto(adminUrl('diluxone-users-login', 'arrangement'));

		await page.locator('input[name="diluxone_users_login_layout"][value="stack"]').check();
		await page.locator('[data-diluxone-users-save] [form="diluxone-users-arrangement"]').click();

		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		await expect(strip(guest), 'the page is still in tabs after being told to stack').toBeHidden();
		await expect(panel(guest, 'email')).toBeVisible();
		await expect(panel(guest, 'password')).toBeVisible();
	});
});
