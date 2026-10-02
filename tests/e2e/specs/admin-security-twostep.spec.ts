import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { codeIn, freshEmail, linkIn, Site, waitForMail } from '../support/api';
import {
	accountSection,
	adminUrl,
	askForLink,
	challengeCode,
	challengeScreen,
	navigated,
	savePanel,
	signInWithPassword,
} from '../support/ui';
import { daysUntil, notNow, rail, saveSaying, stateIn, summaryRow } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Security › Two-step verification, driven through its own controls.
 *
 * The behaviour behind each of these settings is proven elsewhere with the
 * value written by the side door. What is proven here is the other half: that
 * the control on the screen writes that value, reads it back after a reload,
 * and that what it wrote is what the sign-in then does — for "to whom", "with
 * what", "somebody who came by link" and "remembering a browser". And the
 * screen's own words about itself: the summary rows, the "not now" lines and
 * the rail's links.
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
	'diluxone_users_2fa_scope',
	'diluxone_users_2fa_roles',
	'diluxone_users_2fa_link',
	'diluxone_users_2fa_remember_days',
];

const mode = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_mode"][value="${value}"]`);
const method = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_methods[]"][value="${value}"]`);
const linkRule = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_link"][value="${value}"]`);
const scope = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_scope"][value="${value}"]`);
const role = (page: Page, value: string) => page.locator(`input[name="diluxone_users_2fa_roles[]"][value="${value}"]`);
const days = (page: Page) => page.locator('input[name="diluxone_users_2fa_remember_days"]');

async function openTab(page: Page): Promise<void> {
	await page.goto(adminUrl(SECURITY, '2fa'));
}

/** Presses the submit button of the second-step screen and waits for where it lands. */
async function answerChallenge(page: Page, code: string): Promise<void> {
	await challengeCode(page).fill(code);
	await navigated(page, () => page.locator('form.diluxone-users-form button[type="submit"]').first().click());
}

/** A fresh browser's worth of cookies: everything WordPress keeps, nothing of the trust cookie unless asked. */
async function signOutOnly(page: Page): Promise<void> {
	await page.context().clearCookies({ name: /^wordpress/ });
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

	test('to whom: “only some roles”, administrator ticked, asks an administrator and lets a subscriber through', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_roles: ['editor'],
		});

		await openTab(page);
		await mode(page, 'required').check();
		await scope(page, 'some').check();

		// What is ticked once "some roles" is chosen is the whole answer.
		await expect(role(page, 'administrator')).toBeVisible();
		for (const box of await page.locator('input[name="diluxone_users_2fa_roles[]"]').all()) {
			await box.uncheck();
		}
		await role(page, 'administrator').check();
		await savePanel(page);

		// Read back from the screen after a reload, and from the side door.
		await page.reload();
		await expect(mode(page, 'required')).toBeChecked();
		await expect(scope(page, 'some')).toBeChecked();
		await expect(role(page, 'administrator')).toBeChecked();
		await expect(role(page, 'editor')).not.toBeChecked();
		expect(await site.getOptions(['diluxone_users_2fa_mode', 'diluxone_users_2fa_scope', 'diluxone_users_2fa_roles'])).toEqual({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_scope: 'some',
			diluxone_users_2fa_roles: ['administrator'],
		});

		// A subscriber is not among them: the password is the whole of it.
		const subscriber = freshEmail('2fa-scope-sub');

		await site.makeUser({ email: subscriber, password: PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, subscriber, PASSWORD);
		await expect(challengeScreen(guest)).toHaveCount(0);
		await expectSignedIn(guest, subscriber);

		// An administrator is: the second screen, and no session yet.
		const admin = freshEmail('2fa-scope-admin');

		await site.makeUser({ email: admin, password: PASSWORD, role: 'administrator' });
		await guest.context().clearCookies();
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, admin, PASSWORD);
		await expect(challengeScreen(guest)).toBeVisible();
		await expectSignedOut(guest);

		// And the summary says it reaches someone.
		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, '2fa', 0))).toBe('active');
	});

	test('with what: the methods ticked are the boxes a person gets on their account, and the summary names them', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_scope: 'all' });

		const email = freshEmail('2fa-methods');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);

		// The app alone, ticked on the screen.
		await openTab(page);
		await method(page, 'email').uncheck();
		await method(page, 'totp').check();
		await saveSaying(page, METHOD_BUG);

		await page.reload();
		await expect(method(page, 'totp')).toBeChecked();
		await expect(method(page, 'email')).not.toBeChecked();
		expect((await site.getOptions(['diluxone_users_2fa_methods'])).diluxone_users_2fa_methods).toEqual(['totp']);

		// The person's security section offers the app to set up.
		await guest.goto(accountSection(pages.account.url, 'security'));
		await expect(guest.locator('.diluxone-users-totp__key')).toHaveCount(1);

		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, '2fa', 1)), 'how the code arrives: one method on').toBe('active');

		// The e-mail alone: the app's box is gone from the account.
		await openTab(page);
		await method(page, 'totp').uncheck();
		await method(page, 'email').check();
		await saveSaying(page, METHOD_BUG);

		expect((await site.getOptions(['diluxone_users_2fa_methods'])).diluxone_users_2fa_methods).toEqual(['email']);
		await guest.goto(accountSection(pages.account.url, 'security'));
		await expect(guest.locator('.diluxone-users-totp__key')).toHaveCount(0);

		// Off, with nothing ticked: saved, and the summary row says nothing carries a code.
		await openTab(page);
		await mode(page, 'off').check();
		await method(page, 'email').uncheck();
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods'])).toEqual({
			diluxone_users_2fa_mode: 'off',
			diluxone_users_2fa_methods: [],
		});
		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, '2fa', 1))).toBe('off');
	});

	test('somebody who came by link: “always”, “never” and “work it out” each do what they say, and the summary agrees', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_link: 'auto',
		});

		// Somebody who turned the code by e-mail on.
		const email = freshEmail('2fa-link');

		await site.makeUser({ email, meta: { diluxone_users_2fa_on: 1 } });

		/** Saves one answer and reads back the screen, the side door, the rail and the summary. */
		const choose = async (answer: string, expected: 'active' | 'off') => {
			await openTab(page);
			await linkRule(page, answer).check();
			await savePanel(page);
			await page.reload();
			await expect(linkRule(page, answer)).toBeChecked();
			expect((await site.getOptions(['diluxone_users_2fa_link'])).diluxone_users_2fa_link).toBe(answer);
			expect(await stateIn(rail(page)), `the rail, on “${answer}”`).toBe(expected);

			await page.goto(adminUrl(SECURITY, 'summary'));
			expect(await stateIn(summaryRow(page, SECURITY, '2fa', 2)), `the summary row, on “${answer}”`).toBe(expected);
		};

		// Always: even a code to the same inbox is asked.
		await choose('always', 'active');
		await followLink(guest, site, pages.login.url, email);
		await expect(challengeScreen(guest), '“always” asks for the second step after the link').toBeVisible();
		await expectSignedOut(guest);

		// Never: the link is the whole of it, even with the app on offer.
		await options.set({ diluxone_users_2fa_methods: ['email', 'totp'] });
		await site.makeUser({ email, meta: { diluxone_users_2fa_on: 1, diluxone_users_totp: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' } });
		await choose('never', 'off');
		await guest.context().clearCookies();
		await followLink(guest, site, pages.login.url, email);
		await expect(challengeScreen(guest)).toHaveCount(0);
		await expectSignedIn(guest, email);

		// Work it out: an app does not arrive by e-mail, so it asks.
		await choose('auto', 'active');
		await guest.context().clearCookies();
		await followLink(guest, site, pages.login.url, email);
		await expect(challengeScreen(guest), '“work it out” asks when an app is on').toBeVisible();
		await expectSignedOut(guest);
	});

	test('remembering a browser: 0 offers no “do not ask again” and asks every time; 7 keeps a cookie for seven days', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_scope: 'all' });

		const email = freshEmail('2fa-remember');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_2fa_on: 1 } });

		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();

		/** Password, then the code that arrives, in the person's browser. */
		const signInWithCode = async (trust: boolean) => {
			const before = Date.now() / 1000;

			await person.goto(pages.login.url);
			await signInWithPassword(person, email, PASSWORD);
			await expect(challengeScreen(person)).toBeVisible();

			if (trust) {
				await person.locator('input[name="diluxone_users_2fa_trust"]').check();
			}

			await answerChallenge(person, codeIn(await waitForMail(site, email, { after: before })));
			await expectSignedIn(person, email);
		};

		try {
			// 0, typed into the box.
			await openTab(page);
			await days(page).fill('0');
			await savePanel(page);
			await page.reload();
			await expect(days(page)).toHaveValue('0');
			expect(Number((await site.getOptions(['diluxone_users_2fa_remember_days'])).diluxone_users_2fa_remember_days)).toBe(0);

			await person.goto(pages.login.url);
			await signInWithPassword(person, email, PASSWORD);
			await expect(challengeScreen(person)).toBeVisible();
			await expect(person.locator('input[name="diluxone_users_2fa_trust"]'), 'no “do not ask again” at 0').toHaveCount(0);
			await signOutOnly(person);

			// 7: the box is offered, and the cookie it leaves lives seven days.
			await openTab(page);
			await days(page).fill('7');
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_2fa_remember_days'])).diluxone_users_2fa_remember_days)).toBe(7);

			await signInWithCode(true);

			const trusted = (await context.cookies()).find((cookie) => cookie.name.startsWith('diluxone_users_2fa_'));

			expect(trusted, 'the browser keeps the cookie that remembers it').toBeTruthy();
			expect(daysUntil(trusted!.expires)).toBeGreaterThan(6.9);
			expect(daysUntil(trusted!.expires)).toBeLessThan(7.1);

			// Out and back: no second screen on this browser.
			await signOutOnly(person);
			await person.goto(pages.login.url);
			await signInWithPassword(person, email, PASSWORD);
			await expect(challengeScreen(person)).toHaveCount(0);
			await expectSignedIn(person, email);

			// Back to 0 on the screen: the cookie it left is no longer believed.
			await openTab(page);
			await days(page).fill('0');
			await savePanel(page);
			await signOutOnly(person);
			await person.goto(pages.login.url);
			await signInWithPassword(person, email, PASSWORD);
			await expect(challengeScreen(person), 'at 0 the remembered browser is asked again').toBeVisible();
		} finally {
			await context.close();
		}
	});
});

test.describe('Security › Two-step verification: what the screen says about itself', () => {
	test('“not now”: under “Off” while it is saved off, and above the link rule while the link is not a way in', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'], diluxone_users_login_method: 'both' });

		await openTab(page);
		await expect(notNow(page), 'nothing to excuse while it is on and the link is a way in').toHaveCount(0);

		await options.set({ diluxone_users_2fa_mode: 'off' });
		await openTab(page);
		await expect(notNow(page)).toHaveCount(1);
		await expect(notNow(page).locator('a')).toHaveCount(0);

		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_login_method: 'password' });
		await openTab(page);
		await expect(notNow(page)).toHaveCount(1);
		await expect(notNow(page).locator('a')).toHaveAttribute('href', /page=diluxone-users-login&tab=ways/);

		// Both at once: two lines, each under what it is about.
		await options.set({ diluxone_users_2fa_mode: 'off' });
		await openTab(page);
		await expect(notNow(page)).toHaveCount(2);
	});

	test('the rail’s links lead to Access › Ways in and to the e-mails', async ({ page }) => {
		await openTab(page);

		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-login&tab=ways"]').click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-login');
		expect(new URL(page.url()).searchParams.get('tab')).toBe('ways');

		await openTab(page);
		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-notices&tab=templates"]').click());
		expect(new URL(page.url()).searchParams.get('tab')).toBe('templates');
	});

	test('the Summary’s rail leads to Access › Ways in and to Access’s own summary', async ({ page }) => {
		await page.goto(adminUrl(SECURITY, 'summary'));
		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-login&tab=ways"]').click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-login');
		expect(new URL(page.url()).searchParams.get('tab')).toBe('ways');

		await page.goto(adminUrl(SECURITY, 'summary'));
		await navigated(page, () => rail(page).locator('a[href$="page=diluxone-users-login"]').click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-login');
		expect(new URL(page.url()).searchParams.get('tab')).toBeNull();
	});
});
