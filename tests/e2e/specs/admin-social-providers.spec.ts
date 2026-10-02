import type { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, answeringDialog, navigated, savePanel, ssoButton } from '../support/ui';
import { closeTheTest, notNow, rail, stateIn } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Social login › a provider, set up through its own screen.
 *
 * The fake network the mu-plugin answers as (`mock`) is a provider like the
 * twelve real ones: the same grid card, the same three tabs, the same live
 * test. So everything an administrator does to bring one up is done here
 * through the screen — the client ID and the secret pasted and saved, the
 * secret never printed back, an empty box keeping it, changed credentials
 * asking for the test again and taking the button down, the live test in its
 * window with the round trip and its three ways of failing, turning the
 * button on and off from the grid and from the provider's screen — and each
 * is checked in the stored credentials and on the sign-in page a stranger
 * sees.
 */

test.use({ storageState: ADMIN_STATE });

const SOCIAL = 'diluxone-users-social';
const ID = 'e2e-client-id';
const SECRET = 'e2e-client-secret-ui';

type Stored = { active?: number; id?: string; secret?: string; tested?: number };

const providerUrl = (tab = '') => `/wp-admin/admin.php?page=${SOCIAL}&provider=mock${tab ? `&tab=${tab}` : ''}`;

async function stored(site: { getOptions(keys: string[]): Promise<Record<string, unknown>> }): Promise<Stored | undefined> {
	return ((await site.getOptions(['diluxone_users_sso'])).diluxone_users_sso as Record<string, Stored> | null)?.mock;
}

/** The mock's card on the grid. */
function card(page: Page) {
	return page.locator('.diluxone-users-card').filter({ has: page.locator('a[href*="provider=mock"]') });
}

/** The provider screen's live-test link. */
function testLink(page: Page) {
	return page.locator('a[data-diluxone-users-popup][target="diluxone-users-test"]');
}

test.describe('Social login › a provider’s own screen', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: {},
			diluxone_users_sso_login: 1,
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('Getting started: the redirect URL to paste, the console, the steps, and the way on to Settings', async ({ page, baseURL }) => {
		await page.goto(providerUrl());

		const redirect = page.locator('input[name="diluxone_users_redirect_uri"]');

		await expect(redirect).toHaveAttribute('readonly', /.*/);
		expect(await redirect.inputValue()).toBe(`${baseURL}/sso/mock/`);

		const console = page.locator('a[target="_blank"]').filter({ has: page.locator('.dashicons-external') });

		await expect(console).toHaveAttribute('href', 'https://provider.e2e.test/');

		// Nothing to test, turn on or delete before there is an app: no actions.
		await expect(testLink(page)).toHaveCount(0);
		await expect(page.locator('a[href*="diluxone_users_action="]')).toHaveCount(0);
		expect(await stateIn(rail(page))).toBe('pending');
		await expect(rail(page).locator('.du-notice')).not.toHaveCount(0);

		await navigated(page, () => page.locator(`a.button-primary[href*="provider=mock"][href*="tab=settings"]`).click());
		expect(new URL(page.url()).searchParams.get('tab')).toBe('settings');

		// And back to the grid.
		await navigated(page, () => page.locator(`a[href$="page=${SOCIAL}"]`).first().click());
		await expect(card(page)).toHaveCount(1);
	});

	test('Settings: the client ID and the secret are saved, the secret is never printed back, and an empty box keeps it', async ({
		page,
		site,
	}) => {
		await page.goto(providerUrl('settings'));
		await expect(notNow(page), 'not tested: the button cannot go up yet').toHaveCount(1);

		await page.locator('input[name="diluxone_users_client_id"]').fill(ID);
		await page.locator('input[name="diluxone_users_client_secret"]').fill(SECRET);
		// Ticked before any test: it applies the day the test passes, not now.
		await page.locator('input[name="diluxone_users_active"]').check();
		await savePanel(page);

		expect(await stored(site)).toEqual({ active: 0, id: ID, secret: SECRET, tested: 0 });

		// The screen it comes back to, and the same screen loaded again.
		for (const round of ['after the save', 'after a reload']) {
			if (round === 'after a reload') {
				await page.goto(providerUrl('settings'));
			}

			await expect(page.locator('input[name="diluxone_users_client_id"]'), round).toHaveValue(ID);
			await expect(page.locator('input[name="diluxone_users_client_secret"]'), round).toHaveValue('');
			await expect(page.locator('input[name="diluxone_users_client_secret"]'), round).toHaveAttribute('placeholder', /•+/);
			expect(await page.content(), `${round}: the secret is nowhere in the page`).not.toContain(SECRET);
		}

		// Saved again with the box left empty: the stored secret stays.
		await savePanel(page);
		expect((await stored(site))?.secret).toBe(SECRET);
		expect((await stored(site))?.id).toBe(ID);

		// The grid says it has credentials and has not been tested.
		await page.goto(adminUrl(SOCIAL));
		expect(await stateIn(card(page))).toBe('pending');
		await expect(card(page).locator('a.button-primary')).toHaveAttribute('href', /provider=mock/);
	});

	test('changed credentials ask for the test again and take a working button off the sign-in page', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		for (const change of [
			{ field: 'diluxone_users_client_id', value: 'another-client-id' },
			{ field: 'diluxone_users_client_secret', value: 'another-secret' },
		]) {
			await options.set({ diluxone_users_sso: { mock: { active: 1, id: ID, secret: SECRET, tested: 1 } } });

			await guest.goto(pages.login.url);
			await expect(ssoButton(guest, 'mock')).toBeVisible();

			await page.goto(providerUrl('settings'));
			await expect(page.locator('input[name="diluxone_users_active"]')).toBeChecked();
			await page.locator(`input[name="${change.field}"]`).fill(change.value);
			await savePanel(page);

			const now = await stored(site);

			expect(now?.tested, `${change.field}: tested again`).toBe(0);
			expect(now?.active, `${change.field}: and off`).toBe(0);

			await guest.goto(pages.login.url);
			await expect(ssoButton(guest, 'mock'), `${change.field}: the button is gone`).toHaveCount(0);
		}
	});

	test('the live test: its window, the round trip, “It works”, and Close reloads the screen behind it', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_sso: { mock: { active: 0, id: ID, secret: SECRET, tested: 0 } } });
		const handed = freshEmail('sso-live-test');

		await site.setIdentity({ sub: 'mock|admin-test', email: handed, email_verified: true });

		await page.goto(providerUrl());

		const link = testLink(page);

		await expect(link).toHaveAttribute('data-diluxone-users-popup', /^\d+x\d+$/);

		const [popup] = await Promise.all([page.waitForEvent('popup'), link.click()]);

		await expect(popup.locator('.diluxone-users-sso-test.is-ok')).toBeVisible();
		expect((await stored(site))?.tested, 'recorded as tested').toBe(1);
		// It says what the provider handed over, and makes nobody out of it.
		await expect(popup.locator('.diluxone-users-sso-test__detail')).toContainText(handed);
		expect((await site.user(handed)).exists, 'the test creates no account').toBe(false);

		await Promise.all([
			popup.waitForEvent('close'),
			page.waitForEvent('load'),
			closeTheTest(popup),
		]);

		// The screen behind it, reloaded: tested, not on yet, so "Turn it on".
		expect(await stateIn(rail(page))).toBe('off');
		await expect(page.locator('a.button-primary[href*="diluxone_users_action=on"]')).toHaveCount(1);
		await expect(testLink(page)).toHaveClass(/button-secondary/);

		// Now the box saves: the button goes up.
		await page.goto(providerUrl('settings'));
		await expect(notNow(page)).toHaveCount(0);
		await page.locator('input[name="diluxone_users_active"]').check();
		await savePanel(page);
		expect(await stored(site)).toMatchObject({ active: 1, tested: 1 });

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toBeVisible();
	});

	for (const failure of [
		{ name: 'the person cancels at the provider', identity: { deny: true } },
		{ name: 'the provider hands over no token', identity: { no_token: true } },
		{ name: 'the profile comes back empty', identity: { email: 'nobody@e2e.test' } },
	]) {
		test(`the live test fails, says so, and records nothing: ${failure.name}`, async ({ page, site, options }) => {
			await options.set({ diluxone_users_sso: { mock: { active: 0, id: ID, secret: SECRET, tested: 0 } } });
			await site.setIdentity(failure.identity);

			await page.goto(providerUrl());

			const [popup] = await Promise.all([page.waitForEvent('popup'), testLink(page).click()]);

			await expect(popup.locator('.diluxone-users-sso-test.is-failed')).toBeVisible();
			await expect(popup.locator('.diluxone-users-sso-test.is-ok')).toHaveCount(0);
			expect((await stored(site))?.tested, 'not tested').toBe(0);

			await Promise.all([popup.waitForEvent('close'), closeTheTest(popup)]);
		});
	}

	test('Usage: the shortcodes that draw the buttons, and the direct link to sign in with it', async ({ page, baseURL, options }) => {
		await options.set({ diluxone_users_sso: { mock: { active: 1, id: ID, secret: SECRET, tested: 1 } } });
		await page.goto(providerUrl('usage'));

		const codes = page.locator('.du-note code');

		await expect(codes.filter({ hasText: '[diluxone_users_login]' })).toHaveCount(1);
		await expect(codes.filter({ hasText: '[diluxone_users_accounts]' })).toHaveCount(1);

		const direct = codes.filter({ hasText: '/sso/mock/' });

		await expect(direct).toHaveCount(1);
		expect(await direct.innerText()).toContain(`${baseURL}/sso/mock/`);
	});
});

test.describe('Social login › turning a provider’s button on and off', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_e2e_sso: 1, diluxone_users_sso_login: 1, diluxone_users_2fa_mode: 'off' });
	});

	test('the grid’s card shows each of the four states with the pill and the actions it should', async ({ page, options }) => {
		const rows: Array<{ seed: Stored | null; pill: string; primary: RegExp; toggle: string | null }> = [
			{ seed: null, pill: 'pending', primary: /provider=mock/, toggle: null },
			{ seed: { id: ID, secret: SECRET, tested: 0, active: 0 }, pill: 'pending', primary: /provider=mock/, toggle: null },
			{ seed: { id: ID, secret: SECRET, tested: 1, active: 0 }, pill: 'off', primary: /provider=mock/, toggle: 'on' },
			{ seed: { id: ID, secret: SECRET, tested: 1, active: 1 }, pill: 'active', primary: /provider=mock/, toggle: 'off' },
		];

		for (const row of rows) {
			await options.set({ diluxone_users_sso: row.seed ? { mock: row.seed } : {} });
			await page.goto(adminUrl(SOCIAL));

			const label = JSON.stringify(row.seed);

			expect(await stateIn(card(page)), label).toBe(row.pill);
			await expect(card(page).locator('a.button-primary'), label).toHaveAttribute('href', row.primary);
			await expect(card(page).locator('a[href*="diluxone_users_action="]'), label).toHaveCount(row.toggle ? 1 : 0);

			if (row.toggle) {
				await expect(card(page).locator(`a[href*="diluxone_users_action=${row.toggle}"]`), label).toHaveCount(1);
			}
		}

		// "Get started" / "Settings" opens the provider's screen.
		await navigated(page, () => card(page).locator('a.button-primary').click());
		expect(new URL(page.url()).searchParams.get('provider')).toBe('mock');
	});

	test('“Turn it on” on the card asks nothing and puts the button up; on the provider’s screen “Turn it off” asks first, and “Turn it on” brings it back', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_sso: { mock: { active: 0, id: ID, secret: SECRET, tested: 1 } } });

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toHaveCount(0);

		await page.goto(adminUrl(SOCIAL));

		const on = card(page).locator('a[href*="diluxone_users_action=on"]');

		await expect(on).not.toHaveAttribute('data-diluxone-users-confirm', /.*/);
		await navigated(page, () => on.click());
		expect((await stored(site))?.active).toBe(1);
		// Back on the grid it came from.
		expect(new URL(page.url()).searchParams.get('provider')).toBeNull();
		expect(await stateIn(card(page))).toBe('active');

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toBeVisible();

		// The provider's screen: "No" keeps it.
		await page.goto(providerUrl());

		const off = page.locator('a[href*="diluxone_users_action=off"][data-diluxone-users-confirm]');

		await answeringDialog(page, 'dismiss', () => off.click());
		expect((await stored(site))?.active, 'answered no').toBe(1);

		await answeringDialog(page, 'accept', () => navigated(page, () => off.click()));
		expect((await stored(site))?.active, 'answered yes').toBe(0);
		// Back on the provider's screen, not the grid.
		expect(new URL(page.url()).searchParams.get('provider')).toBe('mock');

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toHaveCount(0);

		await navigated(page, () => page.locator('a.button-primary[href*="diluxone_users_action=on"]').click());
		expect((await stored(site))?.active).toBe(1);

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toBeVisible();
	});

	test('a provider that has not passed the test is not turned on, even by its own link', async ({ page, site, options }) => {
		// A real "Turn it on" link, while the provider is tested…
		await options.set({ diluxone_users_sso: { mock: { active: 0, id: ID, secret: SECRET, tested: 1 } } });
		await page.goto(adminUrl(SOCIAL));

		const href = (await card(page).locator('a[href*="diluxone_users_action=on"]').getAttribute('href'))!;

		// …followed once it no longer is.
		await options.set({ diluxone_users_sso: { mock: { active: 0, id: ID, secret: SECRET, tested: 0 } } });
		await page.goto(href);

		expect(await stored(site)).toMatchObject({ active: 0, tested: 0 });
		expect(await stateIn(card(page))).toBe('pending');
	});
});
