import { randomBytes } from 'node:crypto';
import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { codeIn, freshEmail, Site, waitForMail } from '../support/api';
import { adminUrl, challengeCode, challengeScreen, navigated, signInWithPassword } from '../support/ui';
import { freshTag, rail } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Reports › Open sessions, used the way an administrator uses it.
 *
 * The search typed into its box and sent, Clear and Refresh, the number per
 * page that sends itself, the pages, the row that says nobody matches, the
 * pill of a session that has run out and the name that leads to the profile,
 * the notice after closing somebody's sessions — and what closing does
 * besides signing them out: the browsers they told not to ask again for the
 * second step are asked again.
 *
 * The people on the list are made with their sessions already in them (the
 * same `session_tokens` WordPress writes), a dozen at a time, each address
 * carrying a tag of the test's own so the search finds them and nobody else.
 */

test.use({ storageState: ADMIN_STATE });

const REPORT = 'diluxone-users-reports';
const PASSWORD = 'e2e-Report-Sessions-1!';

/** Somebody with one session of `hours` from now (negative: run out). */
async function withSession(site: Site, email: string, hours: number): Promise<number> {
	const now = Math.floor(Date.now() / 1000);
	const made = await site.makeUser({
		email,
		meta: {
			session_tokens: {
				[randomBytes(32).toString('hex')]: {
					expiration: now + hours * 3600,
					ip: '192.0.2.44',
					ua: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36',
					login: now - 60,
				},
			},
		},
	});

	return made.id;
}

/** The report's rows, the "nobody matches" one included. */
const rows = (page: Page) => page.locator('table.diluxone-users-list tbody tr');
const searchForm = (page: Page) => page.locator('form.diluxone-users-search');

test.describe('Reports › Open sessions', () => {
	test('the search typed and sent finds the person; Clear takes it away; Refresh keeps it', async ({ page, site }) => {
		const tag = freshTag('rs-search');
		const email = freshEmail(tag);

		await withSession(site, email, 24);
		await page.goto(adminUrl(REPORT, 'sessions'));

		const everybody = await rows(page).count();

		await page.locator('#diluxone-users-s').fill(tag);
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());

		expect(new URL(page.url()).searchParams.get('s')).toBe(tag);
		await expect(rows(page)).toHaveCount(1);
		await expect(rows(page).locator('.diluxone-users-list__mail')).toHaveText(email);

		// Refresh: the same search, the same page.
		const refresh = searchForm(page).locator('a.diluxone-users-search__refresh');

		await expect(refresh).toHaveAttribute('href', new RegExp(`s=${tag}`));
		await navigated(page, () => refresh.click());
		await expect(rows(page)).toHaveCount(1);

		// Clear: everybody again.
		await navigated(page, () => searchForm(page).locator(`a.button[href*="tab=sessions"]:not(.diluxone-users-search__refresh)`).click());
		expect(new URL(page.url()).searchParams.get('s')).toBeNull();
		await expect(page.locator('#diluxone-users-s')).toHaveValue('');
		expect(await rows(page).count()).toBeGreaterThanOrEqual(Math.min(everybody, 1));
	});

	test('the number per page sends itself, and the pages hold the rest', async ({ page, site }) => {
		const tag = freshTag('rs-pages');

		for (let n = 0; n < 12; n++) {
			await withSession(site, freshEmail(`${tag}-${String(n).padStart(2, '0')}`), 24);
		}

		await page.goto(adminUrl(REPORT, 'sessions', { s: tag }));
		await expect(rows(page)).toHaveCount(12);
		await expect(page.locator('.tablenav-pages')).toHaveCount(0);

		// Chosen, and nothing pressed: the form goes on its own.
		await navigated(page, () => searchForm(page).locator('select[name="per"]').selectOption('10'));
		expect(new URL(page.url()).searchParams.get('per')).toBe('10');
		expect(new URL(page.url()).searchParams.get('s')).toBe(tag);
		await expect(rows(page)).toHaveCount(10);

		const second = page.locator('.tablenav-pages a.page-numbers').filter({ hasText: '2' });

		await navigated(page, () => second.click());
		expect(new URL(page.url()).searchParams.get('paged')).toBe('2');
		await expect(rows(page)).toHaveCount(2);
		await expect(searchForm(page).locator('.diluxone-users-search__account')).toContainText('2');
	});

	test('nobody matching is one row that says so; a session that ran out is “expired”; the name leads to the profile', async ({
		page,
		site,
	}) => {
		await page.goto(adminUrl(REPORT, 'sessions', { s: freshTag('rs-nobody') }));
		await expect(rows(page)).toHaveCount(1);
		await expect(rows(page).locator('td[colspan="8"]')).toHaveCount(1);

		const email = freshEmail(freshTag('rs-expired'));
		const id = await withSession(site, email, -2);

		await page.goto(adminUrl(REPORT, 'sessions', { s: email }));
		await expect(rows(page)).toHaveCount(1);
		await expect(rows(page).locator('.diluxone-users-pill--off')).toHaveCount(1);
		await expect(rows(page).locator('.diluxone-users-pill--on')).toHaveCount(0);

		await navigated(page, () => rows(page).locator('.diluxone-users-list__name a').click());
		expect(new URL(page.url()).pathname).toBe('/wp-admin/user-edit.php');
		expect(new URL(page.url()).searchParams.get('user_id')).toBe(String(id));
	});

	test('closing somebody’s sessions comes back saying so, signs them out and makes the browsers they trusted ask again', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_remember_days: 30,
		});

		const email = freshEmail('rs-trusted');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_2fa_on: 1 } });

		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();

		/** Password, and the second screen if it comes. */
		const signIn = async () => {
			await person.goto(pages.login.url);
			await signInWithPassword(person, email, PASSWORD);
			await person.waitForLoadState('domcontentloaded');
		};

		try {
			// The second step passed once, with "do not ask again on this browser".
			const before = Date.now() / 1000;

			await signIn();
			await expect(challengeScreen(person)).toBeVisible();
			await person.locator('input[name="diluxone_users_2fa_trust"]').check();
			await challengeCode(person).fill(codeIn(await waitForMail(site, email, { after: before })));
			await navigated(person, () => person.locator('form.diluxone-users-form button[type="submit"]').first().click());
			await expectSignedIn(person, email);

			// Signed out on their own, then in again: the browser is trusted.
			await context.clearCookies({ name: /^wordpress/ });
			await signIn();
			await expect(challengeScreen(person)).toHaveCount(0);
			await expectSignedIn(person, email);

			// The administrator closes their sessions from the report.
			await page.goto(adminUrl(REPORT, 'sessions', { s: email }));
			await navigated(page, () => rows(page).locator('button[type="submit"]').click());

			expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('closed');
			await expect(page.locator('.notice-success')).toHaveCount(1);
			expect((await site.user(email)).sessions).toBe(0);

			// The browser still has its cookie, and it no longer counts.
			await context.clearCookies({ name: /^wordpress/ });
			await signIn();
			await expect(challengeScreen(person), 'closing the sessions forgets the trusted browsers').toBeVisible();
		} finally {
			await context.close();
		}
	});

	test('the rail leads to the settings behind the report', async ({ page }) => {
		await page.goto(adminUrl(REPORT, 'sessions'));
		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-security&tab=sessions"]').click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-security');
	});
});
