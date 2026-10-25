import { Locator, Page } from '@playwright/test';
import { test, expect, whoOn, toTheHub } from './support';
import { codeIn, freshEmail, waitForMail } from '../support/api';
import { challengeScreen, ssoButton } from '../support/ui';
import { answerChallenge } from '../support/signin';

/**
 * Social sign-in in a popup, on a network (includes/sso-popup.php).
 *
 * The round trip is the hub's, and so is the popup: somebody on /alpha/ goes
 * to the hub's sign-in, presses the button there, and the popup makes the trip
 * while the hub's page stays where it was. When the popup closes, that page
 * goes on through the hub's resume address to where the whole window would
 * have gone: back to /alpha/, signed in, or to the second step, or to the
 * error.
 */

const PASSWORD = 'e2e-Network-Popup-1!';
const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

/** The popup a click opens, closed. */
async function closedPopup(opened: Promise<Page>): Promise<Page> {
	const popup = await opened;

	if (!popup.isClosed()) {
		await popup.waitForEvent('close');
	}

	return popup;
}

/** Presses a social button and waits for the popup to close and the page to move. */
async function pressInPopup(page: Page, button: Locator): Promise<Page> {
	const moved = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
	const opened = page.waitForEvent('popup');

	await button.click();

	const popup = await closedPopup(opened);
	await moved;
	await page.waitForLoadState('domcontentloaded');

	return popup;
}

test.describe('Social sign-in in a popup, on a network', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_login_register: 1,
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('from /alpha/, the hub’s button opens the popup, the hub’s page stays, and it ends back on /alpha/ signed in', async ({ page, hub, alpha }) => {
		const email = freshEmail('net-popup');

		await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		const from = await toTheHub(page, alpha, hub);
		const atHub = page.url();

		// The trip held at its first request, to look at both windows.
		const seen: string[] = [];
		let release!: () => void;
		const gate = new Promise<void>((resolve) => (release = resolve));

		await page.context().route(/\/sso\/mock\/\?(.*&)?diluxone_users_go=1/, async (route) => {
			seen.push(route.request().url());
			await gate;
			await route.continue();
		});

		const opened = page.waitForEvent('popup');
		await ssoButton(page, 'mock').click();

		await expect.poll(() => seen.length, { message: 'the popup set off on the trip' }).toBe(1);
		expect(seen[0].startsWith(hub.url), 'the trip is the hub’s').toBe(true);
		expect(seen[0]).toContain('diluxone_users_popup=');
		expect(page.url(), 'the hub’s page did not move').toBe(atHub);

		const back = page.waitForURL((url) => url.href.startsWith(alpha.url), { waitUntil: 'domcontentloaded' });
		release();
		const popup = await closedPopup(opened);
		await back;

		expect(popup.isClosed(), 'the popup closed itself').toBe(true);
		expect(page.url().startsWith(alpha.url), `back on /alpha/, at ${page.url()} (from ${from})`).toBe(true);
		expect(await whoOn(page, alpha.url), 'signed in there').toBe(email);
	});

	test('from /alpha/ with the second step on, the hub’s page shows the code step, and the code ends on /alpha/', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('net-popup-2fa');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		await toTheHub(page, alpha, hub);
		await pressInPopup(page, ssoButton(page, 'mock'));

		await expect(challengeScreen(page), 'the code step, on the page that opened the popup').toBeVisible();
		expect(await whoOn(page, alpha.url), 'nobody in before the code').toBeNull();

		await answerChallenge(page, codeIn(await waitForMail(hub.site, email)));
		await page.waitForURL((url) => url.href.startsWith(alpha.url), { waitUntil: 'domcontentloaded' });

		expect(await whoOn(page, alpha.url)).toBe(email);
	});

	test('from /alpha/, a cancel at the provider closes the popup and the hub’s page shows the error, signed out', async ({ page, hub, alpha }) => {
		const email = freshEmail('net-popup-cancel');

		await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true, deny: true });

		await toTheHub(page, alpha, hub);

		const popup = await pressInPopup(page, ssoButton(page, 'mock'));

		expect(popup.isClosed()).toBe(true);
		expect(page.url().startsWith(hub.pages.login.url), `on the hub’s sign-in, at ${page.url()}`).toBe(true);
		expect(new URL(page.url()).searchParams.get('diluxone-users')).toBe('social');
		await expect(page.locator('[data-diluxone-users-message="login_social"]')).toBeVisible();
		expect(await whoOn(page, alpha.url)).toBeNull();
	});
});
