import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { freshEmail, linkIn, Site, waitForMail } from '../support/api';
import {
	accountSection,
	adminUrl,
	askForLink,
	challengeScreen,
	navigated,
	savePanel,
	signInWithPassword,
} from '../support/ui';
import { notNow, rail, saveSaying, stateIn, summaryRow } from '../support/admin-ops';
import { waitingBlocks, waits } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Security › Two-step verification, driven through its own controls.
 *
 * The behaviour behind each of these settings is proven elsewhere with the
 * value written by the side door. What is proven here is the other half: that
 * the control on the screen writes that value, reads it back after a reload,
 * and that what it wrote is what the sign-in then does — for "with what" and
 * "somebody who came by link". And the screen's own words about itself: the
 * summary rows, the "not now" lines and the rail's links. "To whom", the
 * authenticator app and "remembering a browser" are Users+ Pro's: here they
 * are its three cards, where they would be.
 */

test.use({ storageState: ADMIN_STATE });

const SECURITY = 'diluxone-users-security';

/**
 * Why ticking a method that is off today is refused: the save keeps only the
 * methods `diluxone_users_2fa_methods()` returns, and that registry already
 * drops the ones the site has switched off — so a method can be switched off
 * from the screen and never back on.
 */
const METHOD_BUG =
	'bug: includes/admin-2fa.php:76 intersects the ticked methods with diluxone_users_2fa_methods(), which only lists the methods already on, so a method that is off can never be ticked back on';
const PASSWORD = 'e2e-Twostep-Ui-1!';

/** Every setting this tab writes, so each test puts back what it found. */
const TWO_STEP_KEYS = [
	'diluxone_users_2fa_mode',
	'diluxone_users_2fa_methods',
];

const mode = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_mode"][value="${value}"]`);
const method = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_methods[]"][value="${value}"]`);

async function openTab(page: Page): Promise<void> {
	await page.goto(adminUrl(SECURITY, '2fa'));
}

/** Somebody new signs in with a link mailed to them, in `page`. */
async function followLink(page: Page, site: Site, loginUrl: string, email: string): Promise<void> {
	const before = Date.now() / 1000;

	await askForLink(page, loginUrl, email);
	await page.goto(linkIn(await waitForMail(site, email, { after: before })));
	await page.waitForLoadState('domcontentloaded');
}

test.describe('Security › Two-step verification: each control writes what the sign-in does', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(TWO_STEP_KEYS);
		await options.set({ diluxone_users_login_method: 'both' });
	});

	test('with what: the methods ticked are the boxes a person gets on their account, and the summary names them', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: [] });

		const email = freshEmail('2fa-methods');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);

		// The code by e-mail, the plugin's one method, ticked on the screen;
		// the app is Users+ Pro's box after it, disabled and nameless.
		await openTab(page);
		await expect(method(page, 'totp')).toHaveCount(0);
		await expect(page.locator('[data-diluxone-users-pro="security/2fa/app"]')).toBeVisible();
		await expect(page.locator('[data-diluxone-users-pro="security/2fa/app"] input'), 'a box, but not one to tick').toBeDisabled();
		await method(page, 'email').check();
		await saveSaying(page, METHOD_BUG);

		await page.reload();
		await expect(method(page, 'email')).toBeChecked();
		expect((await site.getOptions(['diluxone_users_2fa_methods'])).diluxone_users_2fa_methods).toEqual(['email']);

		// The person's security section names it, ready.
		await guest.goto(accountSection(pages.account.url, 'security'));
		await expect(guest.locator('button[name="diluxone_users_security"][value="on"]')).toBeAttached();
		await expect(guest.locator('.diluxone-users-totp__key')).toHaveCount(0);

		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, '2fa', 1)), 'how the code arrives: one method on').toBe('active');

		// Off: the boxes wait for the step, disabled, so the save leaves them
		// as they were, and the summary row says the code is carried while
		// the second step is off.
		await openTab(page);
		await mode(page, 'off').check();
		await expect(method(page, 'email')).toBeDisabled();
		await expect(method(page, 'email')).not.toBeChecked();
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods'])).toEqual({
			diluxone_users_2fa_mode: 'off',
			diluxone_users_2fa_methods: ['email'],
		});
		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, '2fa', 1))).toBe('off');
	});

	test('somebody who came by link is asked the second step, even where “work it out” was saved, and the summary agrees', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		// A site that had "Let it decide" saved before it became Users+ Pro's.
		await options.set({
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_link: 'auto',
		});

		// Somebody who turned the code by e-mail on.
		const email = freshEmail('2fa-link');

		await site.makeUser({ email, meta: { diluxone_users_2fa_on: 1 } });

		// The screen says it, and offers no choice of its own.
		await openTab(page);
		await expect(page.getByText('The second step is asked of somebody who came in by link, as on every other way in')).toBeVisible();
		await expect(page.locator('input[name="diluxone_users_2fa_link"]')).toHaveCount(0);
		expect(await stateIn(rail(page)), 'the rail').toBe('active');

		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, '2fa', 2)), 'the summary row').toBe('active');

		// Even a code to the same inbox is asked.
		await followLink(guest, site, pages.login.url, email);
		await expect(challengeScreen(guest), 'the second step is asked after the link').toBeVisible();
		await expectSignedOut(guest);
	});
});

test.describe('Security › Two-step verification: what the screen says about itself', () => {
	test('Users+ Pro’s four answers sit inside their groups as the controls they would be: disabled, nameless, with the badge and the link', async ({ page }) => {
		await openTab(page);

		const shapes = {
			'security/2fa/roles': 'radio',
			'security/2fa/app': 'checkbox',
			'security/2fa/link': 'radio',
			'security/2fa/remember': 'checkbox',
		};

		for (const [where, type] of Object.entries(shapes)) {
			const pro = page.locator(`[data-diluxone-users-pro="${where}"]`);

			await expect(pro, where).toBeVisible();
			await expect(pro, `${where}: a choice like the real ones`).toHaveClass(/du-choice/);
			await expect(pro.locator(`input[type="${type}"]`), `${where}: the control it would be`).toBeDisabled();
			await expect(pro.locator('[name]'), `${where}: nothing in it posts`).toHaveCount(0);
			await expect(pro.locator('.diluxone-users-state--pro'), `${where}: the badge`).toHaveCount(1);
			await expect(pro.locator('a'), `${where}: the one link`).toHaveAttribute('href', /diluxone\.com\/wordpress-plugins\/users\/.*utm_medium=security-2fa/);
		}

		// Each inside the group it belongs to, after that group's real answers.
		const groupOf = (where: string) => page.locator(`[data-diluxone-users-pro="${where}"]`).locator('xpath=ancestor::*[contains(@class, "du-choices")][1]');

		await expect(groupOf('security/2fa/roles').locator('input[name="diluxone_users_2fa_mode"]'), 'with the three modes').toHaveCount(3);
		await expect(groupOf('security/2fa/app').locator('input[name="diluxone_users_2fa_methods[]"]'), 'with the methods').toHaveCount(1);
		await expect(groupOf('security/2fa/remember').locator('[data-diluxone-users-pro="security/2fa/link"]'), 'with “Let it decide”').toHaveCount(1);
		await expect(page.locator('input[name="diluxone_users_2fa_link"]'), 'the link rule is no choice').toHaveCount(0);
		await expect(page.locator('.du-pro-card, .du-pro__picture'), 'no dashed box of text, no picture').toHaveCount(0);

		await expect(page.locator('input[name="diluxone_users_2fa_scope"]')).toHaveCount(0);
		await expect(page.locator('input[name="diluxone_users_2fa_remember_days"]')).toHaveCount(0);
	});

	test('the groups wait while it is saved off, and the link rule is excused while the link is not a way in', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_login_method: 'both' });

		await openTab(page);
		await expect(notNow(page), 'nothing to excuse while it is on and the link is a way in').toHaveCount(0);
		await expect(waits(page)).toHaveCount(0);

		// Off: "with what" and the link rule wait for the step, disabled
		// with nothing chosen, each under its own line and with no pending
		// line of their own.
		await options.set({ diluxone_users_2fa_mode: 'off' });
		await openTab(page);
		await expect(notNow(page)).toHaveCount(0);
		await expect(waits(page)).toHaveCount(2);
		await expect(waitingBlocks(page)).toHaveCount(2);
		await expect(method(page, 'email')).toBeDisabled();

		// Turned on from the screen, both go on the spot, with nothing
		// chosen in them yet; off again, they wait again.
		await mode(page, 'optional').check();
		await expect(waits(page)).toHaveCount(0);
		await expect(method(page, 'email')).toBeEnabled();
		await expect(method(page, 'email')).not.toBeChecked();
		await mode(page, 'off').check();
		await expect(waits(page)).toHaveCount(2);

		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_login_method: 'password' });
		await openTab(page);
		await expect(notNow(page)).toHaveCount(1);
		await expect(notNow(page).locator('a')).toHaveAttribute('href', /page=diluxone-users-login&tab=ways/);

		// Both at once: the wait alone, since a second reason under a line
		// that says the group waits is noise.
		await options.set({ diluxone_users_2fa_mode: 'off' });
		await openTab(page);
		await expect(notNow(page)).toHaveCount(0);
		await expect(waits(page)).toHaveCount(2);
	});

	test('the rail is the button and the second step on a link sign-in, and nothing else', async ({ page }) => {
		await openTab(page);

		await expect(rail(page).locator('.du-state')).toHaveCount(1);
		await expect(rail(page).locator('.du-note, .du-links'), 'no manual beside the form').toHaveCount(0);
	});

	test('the Summary has no rail: the table is the whole of it', async ({ page }) => {
		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(rail(page)).toHaveCount(0);
	});
});
