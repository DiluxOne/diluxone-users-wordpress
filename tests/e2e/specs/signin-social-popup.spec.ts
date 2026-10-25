import { BrowserContext, Locator, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { codeIn, freshEmail, waitForMail } from '../support/api';
import { accountSection, challengeScreen, navigated, openAllPanels, registerScreen, signInWithPassword, ssoButton } from '../support/ui';
import { MOCK_ON, answerChallenge } from '../support/signin';

/**
 * Signing in with a social account in a popup (includes/sso-popup.php).
 *
 * The button opens the provider in a window of its own and the page stays
 * where it was; the popup's return tells that page it is over, and closes;
 * the page reloads in place, or goes on through the resume address to the
 * destination, the second step, the required fields or the error. With the
 * popup blocked, the whole window makes the trip as it always did.
 *
 * `sso.spec.ts` and `signin-social.spec.ts` walk what the trip decides; this
 * is how it travels.
 */

const PASSWORD = 'e2e-Social-Popup-1!';

/** The trip's first request, from a button: `/sso/mock/?diluxone_users_go=1…`. */
const TRIP_START = /\/sso\/mock\/\?(.*&)?diluxone_users_go=1/;

/**
 * Holds the popup's first request until released, so the test can look at
 * both windows while the provider would be on screen.
 */
async function holdTheTrip(context: BrowserContext): Promise<{ seen: string[]; release: () => void }> {
	const seen: string[] = [];
	let release!: () => void;
	const gate = new Promise<void>((resolve) => (release = resolve));

	await context.route(TRIP_START, async (route) => {
		seen.push(route.request().url());
		await gate;
		await route.continue();
	});

	return { seen, release };
}

/**
 * The popup a click opens, closed. Playwright hands the popup over once its
 * first request is answered, so with the trip held this is only asked after
 * releasing it.
 */
async function closedPopup(opened: Promise<Page>): Promise<Page> {
	const popup = await opened;

	if (!popup.isClosed()) {
		await popup.waitForEvent('close');
	}

	return popup;
}

/** Every address the page itself goes to from now on, redirects included. */
function openerTrail(page: Page): string[] {
	const visited: string[] = [];

	page.on('request', (request) => {
		if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
			visited.push(request.url());
		}
	});

	return visited;
}

/**
 * Presses a social button and waits for the whole of it: the popup opens,
 * makes the trip, closes, and the page that opened it lands where it goes.
 */
async function pressInPopup(page: Page, button: Locator): Promise<Page> {
	const moved = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
	const opened = page.waitForEvent('popup');

	await button.click();

	const popup = await closedPopup(opened);
	await moved;
	await page.waitForLoadState('domcontentloaded');

	return popup;
}

test.describe('Social sign-in in a popup', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_2fa_mode: 'off',
			diluxone_users_register_form: 1,
			diluxone_users_login_register: 1,
		});
	});

	/* ── C1: the provider opens in a popup, the page stays ───────────── */

	test('the sign-in page’s button opens the provider in a popup, and the page stays where it was', async ({ page, site, pages }) => {
		const email = freshEmail('popup-open');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.login.url);

		const before = page.url();
		const trip = await holdTheTrip(page.context());
		const opened = page.waitForEvent('popup');
		await ssoButton(page, 'mock').click();

		await expect.poll(() => trip.seen.length, { message: 'the popup set off on the trip' }).toBe(1);
		expect(trip.seen[0], 'the same trip, saying it is a popup, from a door').toContain('diluxone_users_popup=door');
		expect(page.url(), 'the page that opened it did not move').toBe(before);
		await expectSignedOut(page);

		const moved = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
		trip.release();
		await closedPopup(opened);
		await moved;
	});

	test('the registration form’s button opens the popup too', async ({ page, site, pages }) => {
		const email = freshEmail('popup-register');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.register.url);

		const before = page.url();
		const trip = await holdTheTrip(page.context());
		const button = registerScreen(page).locator('a.diluxone-users-social--mock');
		const opened = page.waitForEvent('popup');
		await button.click();

		await expect.poll(() => trip.seen.length).toBe(1);
		expect(trip.seen[0]).toContain('diluxone_users_popup=');
		expect(page.url()).toBe(before);

		const moved = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
		trip.release();
		await closedPopup(opened);
		await moved;
		await expectSignedIn(page, email);
	});

	test('the account’s Link button opens the popup, and the account stays on screen', async ({ page, site, pages }) => {
		const email = freshEmail('popup-link');

		await site.makeUser({ email, password: PASSWORD });
		await site.setIdentity({ sub: `mock|popup-link-${Date.now()}`, email: freshEmail('popup-linked-elsewhere'), email_verified: true });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);
		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);

		const before = page.url();
		const trip = await holdTheTrip(page.context());
		const row = page.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' });
		const opened = page.waitForEvent('popup');
		await row.locator('a.diluxone-users-button').click();

		await expect.poll(() => trip.seen.length).toBe(1);
		expect(trip.seen[0], 'the link keeps its nonce').toContain('diluxone_users_nonce=');
		expect(trip.seen[0]).toContain('diluxone_users_popup=1');
		expect(page.url()).toBe(before);

		const moved = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
		trip.release();
		await closedPopup(opened);
		await moved;

		expect(stateOf(page.url()), 'the account says the link was made').toBe('linked');
		await expect(page.locator('.diluxone-users-accounts .diluxone-users-notice--ok')).toBeVisible();
	});

	/* ── C2: the popup closes, the page is signed in, in place or at the destination ── */

	test('from a page with the sign-in form of its own, the popup closes and the page reloads in place, signed in', async ({ page, site }) => {
		const email = freshEmail('popup-inplace');
		const here = await site.page('social-popup', '[diluxone_users_login]');

		try {
			await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
			await page.goto(here.url);

			const visited = openerTrail(page);
			const popup = await pressInPopup(page, ssoButton(page, 'mock'));

			expect(popup.isClosed(), 'the popup closed itself').toBe(true);
			expect(page.url(), 'reloaded where it was').toBe(here.url);
			expect(visited.filter((url) => url !== here.url), 'the page never left: no provider, no trip').toEqual([]);
			await expectSignedIn(page, email);
		} finally {
			await site.forgetPage('social-popup');
		}
	});

	test('from the sign-in page, the popup closes and the page goes on to the destination, signed in', async ({ page, site, pages, baseURL }) => {
		const email = freshEmail('popup-door');

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.login.url);

		const visited = openerTrail(page);
		const popup = await pressInPopup(page, ssoButton(page, 'mock'));

		expect(popup.isClosed()).toBe(true);
		expect(new URL(page.url()).pathname, 'at the destination: the front page').toBe(new URL(baseURL!).pathname.replace(/\/?$/, '/'));
		expect(visited.some((url) => url.includes('/oauth/authorize')), 'the whole window never went to the provider').toBe(false);
		await expectSignedIn(page, email);
	});

	/* ── C3: a blocked popup is the whole window, as before ──────────── */

	test('with the popup blocked, the whole window goes to the provider and back, signed in', async ({ page, site, pages }) => {
		const email = freshEmail('popup-blocked');

		// What a popup blocker does: window.open() hands back no window.
		await page.addInitScript(() => {
			window.open = () => null;
		});
		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.login.url);

		const visited = openerTrail(page);
		let opened = false;
		page.on('popup', () => (opened = true));

		await navigated(page, () => ssoButton(page, 'mock').click());

		expect(opened, 'no popup').toBe(false);
		expect(visited[0], 'the button’s own trip, in this window').toMatch(TRIP_START);
		expect(visited[0], 'not a popup trip').not.toContain('diluxone_users_popup');
		await expectSignedIn(page, email);
	});

	/* ── C4: the second step and the required fields, on the page that opened it ── */

	test('with the second step on, the popup closes and the page that opened it shows the code step', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('popup-2fa');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: `mock|${email}` } });
		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.login.url);

		await pressInPopup(page, ssoButton(page, 'mock'));

		await expect(challengeScreen(page), 'the code step, on the page that opened the popup').toBeVisible();
		await expectSignedOut(page);

		await answerChallenge(page, codeIn(await waitForMail(site, email)));
		await expectSignedIn(page, email);
	});

	test('with a required field missing, the popup closes and the page that opened it shows the fields step', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 1, group: 'main', active: 1 },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, group: 'main', active: 1 },
			],
			diluxone_users_login_role: 'subscriber',
		});

		const email = freshEmail('popup-fields');

		// No name from the network: the account comes in without its required field.
		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(pages.login.url);

		await pressInPopup(page, ssoButton(page, 'mock'));

		expect(page.url().startsWith(pages.account.url), `on the account page, at ${page.url()}`).toBe(true);
		expect(new URL(page.url()).searchParams.get('diluxone-users-complete'), 'the fields step').toBe('1');
		await expect(page.locator('.diluxone-users-complete form.diluxone-users-form input[name="first_name"]')).toBeVisible();
		await expectSignedIn(page, email);
	});
});

