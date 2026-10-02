import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, navigated } from '../support/ui';
import { devWp, stateIn, summaryRowAt } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Status › Status and Status › If you get locked out, telling the truth.
 *
 * The checks draw no control, so the only way they fail is by saying
 * something that is not so. Each test here puts the site into the state a
 * check is about — no page chosen, a page that is gone or not published, one
 * without its shortcode; mail that never went, went, or failed; rewrite rules
 * from another version; the e-mail link as the only way in while mail fails;
 * a provider saved but off — and reads the row's pill and the headline above
 * the table. The rows are read by their place in the table, which is the
 * order the screen draws them in: they carry no name of their own that does
 * not translate.
 */

test.use({ storageState: ADMIN_STATE });

const STATUS = adminUrl('diluxone-users-status', 'status');
const VERSION = readFileSync('diluxone-users.php', 'utf8').match(/define\(\s*'DILUXONE_USERS_VERSION',\s*'([^']+)'/)![1];

/** The rows of the checks table, in the order `diluxone_users_checks()` draws them. */
const ROW = { account: 0, login: 1, mail: 2, https: 3, permalinks: 4, rewrite: 5, ways: 6, social: 7 } as const;

const row = (page: Page, name: keyof typeof ROW) => summaryRowAt(page, ROW[name]);

/** The headline above the table: "everything checks out" or "N things need attention". */
function headline(page: Page, kind: 'success' | 'error') {
	return page.locator(`.wrap .notice-${kind}`).filter({ hasNot: page.locator('a') });
}

test.describe('Status › Status: the checks', () => {
	test.beforeEach(async ({ options }) => {
		// HTTPS is off on this machine, and with passkeys on that is a check
		// that fails for a reason none of these tests is about.
		await options.set({ diluxone_users_passkey_enabled: 0 });
	});

	test('the account page: none chosen, gone, not published, and right — a chosen page draws its form even without the shortcode', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		const draft = devWp(['post', 'create', '--post_type=page', '--post_status=draft', '--post_title=e2e-status-draft', '--post_content=[diluxone_users_account]', '--porcelain']);
		const plain = await site.page('status-no-shortcode', '<p>No shortcode here.</p>');

		try {
			const cases: Array<{ value: number; state: string; link: RegExp | null }> = [
				{ value: 0, state: 'pending', link: /page=diluxone-users-account/ },
				{ value: 999_999_999, state: 'off', link: /page=diluxone-users-account/ },
				{ value: Number(draft), state: 'off', link: /post\.php\?post=\d+&action=edit/ },
				// The plugin adds its shortcode to the page it was given
				// (pages.php), so a page without one is not a fault.
				{ value: plain.id, state: 'active', link: null },
				{ value: pages.account.id, state: 'active', link: null },
			];

			for (const one of cases) {
				await options.set({ diluxone_users_account_page: one.value });
				await page.goto(STATUS);

				expect(await stateIn(row(page, 'account')), `account page ${one.value}`).toBe(one.state);

				if (one.link) {
					await expect(row(page, 'account').locator('td.diluxone-users-summary__change a'), `account page ${one.value}`).toHaveAttribute('href', one.link);
				} else {
					await expect(row(page, 'account').locator('td.diluxone-users-summary__change a')).toHaveCount(0);
				}
			}

			// The sign-in page is read the same way, from its own setting —
			// and the page without a shortcode really does draw the form.
			await options.set({ diluxone_users_login_page: 0 });
			await page.goto(STATUS);
			expect(await stateIn(row(page, 'login'))).toBe('pending');
			await expect(row(page, 'login').locator('td.diluxone-users-summary__change a')).toHaveAttribute('href', /page=diluxone-users-login/);

			await options.set({ diluxone_users_login_page: plain.id });
			await page.goto(STATUS);
			expect(await stateIn(row(page, 'login'))).toBe('active');
			await guest.goto(plain.url);
			await expect(guest.locator('input[name="log"], input[name="diluxone_users_email"]').first()).toBeAttached();

			await options.set({ diluxone_users_login_page: pages.login.id });
			await page.goto(STATUS);
			expect(await stateIn(row(page, 'login'))).toBe('active');
		} finally {
			devWp(['post', 'delete', draft, '--force']);
			await site.forgetPage('status-no-shortcode');
		}
	});

	test('outgoing mail: unknown until something is sent, “going out” after the test message, and the headline says all is well', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_users_mail_last: null, diluxone_e2e_mail_outcome: 'ok' });

		await page.goto(STATUS);
		expect(await stateIn(row(page, 'mail'))).toBe('unknown');

		// Its link is the way to the test.
		await navigated(page, () => row(page, 'mail').locator('td.diluxone-users-summary__change a').click());
		expect(new URL(page.url()).searchParams.get('tab')).toBe('tools');

		const send = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_mail_test"]') });

		await navigated(page, () => send.locator('input[type="submit"], button[type="submit"]').first().click());
		await expect(page.locator('.notice-success')).toBeVisible();

		await page.goto(STATUS);
		expect(await stateIn(row(page, 'mail'))).toBe('active');
		await expect(headline(page, 'success')).toHaveCount(1);
		await expect(headline(page, 'error')).toHaveCount(0);
	});

	test('mail failing: the test message says so on Tools, the row is off, and with the link the only way in nobody can sign in', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_users_mail_last: null, diluxone_e2e_mail_outcome: 'fail', diluxone_users_login_method: 'both' });

		await page.goto(adminUrl('diluxone-users-status', 'tools'));

		const send = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_mail_test"]') });

		await navigated(page, () => send.locator('input[type="submit"], button[type="submit"]').first().click());
		await expect(page.locator('.notice-error')).toBeVisible();
		await expect(page.locator('.notice-error')).toContainText('The e2e mailer was told to refuse this message.');

		await page.goto(STATUS);
		expect(await stateIn(row(page, 'mail'))).toBe('off');
		await expect(row(page, 'mail').locator('.diluxone-users-summary__detail')).toContainText('The e2e mailer was told to refuse this message.');
		await expect(headline(page, 'error')).toHaveCount(1);

		// With a password still a way in, the site can still be entered.
		expect(await stateIn(row(page, 'ways'))).toBe('active');

		// The e-mail link alone, and no social network: nobody can.
		await options.set({ diluxone_users_login_method: 'link', diluxone_users_sso: {} });
		await page.goto(STATUS);
		expect(await stateIn(row(page, 'ways'))).toBe('off');
		await expect(row(page, 'ways').locator('td.diluxone-users-summary__change a')).toHaveAttribute('href', /page=diluxone-users-login/);
		await expect(headline(page, 'error')).toContainText('2');
	});

	test('rewrite rules from another version are rebuilt by the next request, so the row reads active and the stamp is this version', async ({
		page,
		site,
		options,
	}) => {
		// The "not rewritten for this version" state lasts until the next
		// request's wp_loaded (account.php), which is the one that draws
		// this screen: it is never on screen, and what is proven is that it
		// heals rather than that it shows.
		await options.set({ diluxone_users_rewrite_version: '0.0.1' });

		await page.goto(STATUS);
		expect(await stateIn(row(page, 'rewrite'))).toBe('active');
		expect((await site.getOptions(['diluxone_users_rewrite_version'])).diluxone_users_rewrite_version).toBe(VERSION);
	});

	test('a social provider saved but off is pending and leads to Social login; on, the row is active; none saved, no row', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_e2e_sso: 1, diluxone_users_sso: {} });
		await page.goto(STATUS);
		await expect(page.locator('table.diluxone-users-summary').first().locator('tbody > tr')).toHaveCount(7);

		await options.set({ diluxone_users_sso: { mock: { active: 0, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } } });
		await page.goto(STATUS);
		expect(await stateIn(row(page, 'social'))).toBe('pending');
		await expect(row(page, 'social').locator('td.diluxone-users-summary__change a')).toHaveAttribute('href', /page=diluxone-users-social/);

		await options.set({ diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } } });
		await page.goto(STATUS);
		expect(await stateIn(row(page, 'social'))).toBe('active');
	});

	test('HTTPS: on plain HTTP it is only a warning while passkeys are off, and a failure the moment they are on', async ({ page, baseURL, options }) => {
		test.skip(baseURL!.startsWith('https:'), 'this site is on HTTPS');

		await page.goto(STATUS);
		expect(await stateIn(row(page, 'https'))).toBe('pending');
		expect(await stateIn(row(page, 'permalinks')), 'pretty permalinks on this site').toBe('active');

		await options.set({ diluxone_users_passkey_enabled: 1 });
		await page.goto(STATUS);
		expect(await stateIn(row(page, 'https'))).toBe('off');
		await expect(headline(page, 'error')).toHaveCount(1);
	});

	test('the usage counts move with the site, and the environment names this version', async ({ page, site }) => {
		const usage = (n: number) => page.locator('table.diluxone-users-summary').nth(1).locator('tbody > tr').nth(n).locator('td');
		const read = async (n: number) => Number((await usage(n).innerText()).replace(/\D/g, ''));

		await page.goto(STATUS);

		const accounts = await read(0);
		const app = await read(1);
		const passkeys = await read(2);
		const social = await read(3);

		await site.makeUser({
			email: freshEmail('status-usage'),
			meta: {
				diluxone_users_totp: 'JBSWY3DPEHPK3PXP',
				diluxone_users_passkeys: [{ id: `e2e-status-${Date.now()}`, label: 'e2e', created: 1, rp: '' }],
				diluxone_users_sso_github: 'github|e2e-status',
			},
		});

		await page.goto(STATUS);
		expect(await read(0), 'accounts').toBe(accounts + 1);
		expect(await read(1), 'with an app').toBe(app + 1);
		expect(await read(2), 'with a passkey').toBe(passkeys + 1);
		expect(await read(3), 'with a social account').toBe(social + 1);

		const environment = page.locator('table.diluxone-users-summary').nth(2);

		await expect(environment.locator('tbody > tr').first().locator('code')).toHaveText(VERSION);
		await expect(page.locator('code').filter({ hasText: /wp-content\/themes\/.+\/diluxone-users\// })).toHaveCount(1);
	});
});

test.describe('Status › If you get locked out', () => {
	test('the WP-CLI line is the administrator’s own, and run on the server it prints a link that signs them in', async ({
		page,
		browser,
		baseURL,
		options,
	}) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_2fa_mode: 'off' });

		await page.goto('/wp-admin/profile.php');

		const me = await page.locator('input#email').inputValue();

		await page.goto(adminUrl('diluxone-users-status', 'lockout'));

		const line = (await page.locator('.wrap p > code').first().innerText()).trim();

		expect(line).toBe(`wp diluxone-users login ${me}`);

		// The line as it is shown, on the server.
		const printed = devWp(line.replace(/^wp /, '').split(' '));
		const link = printed.match(/https?:\/\/\S+/)?.[0];

		expect(link, `the command prints a link: ${printed}`).toBeTruthy();

		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
		const fresh = await context.newPage();

		try {
			await fresh.goto(link!);
			await expectSignedIn(fresh, me);
		} finally {
			await context.close();
		}
	});

	test('the emergency address is this site’s own wp-login.php, and it draws the password form', async ({ page, baseURL, guest }) => {
		await page.goto(adminUrl('diluxone-users-status', 'lockout'));

		const door = (await page.locator('.wrap p > code').nth(1).innerText()).trim();

		expect(door).toBe(`${baseURL}/wp-login.php?diluxone-users-admin=1`);

		await guest.goto(door);
		await expect(guest.locator('form#loginform input[name="pwd"]')).toBeVisible();

		// And the two ways on.
		await expect(page.locator(`.wrap a[href*="page=diluxone-users-login"]`).last()).toBeVisible();
		await navigated(page, () => page.locator('.wrap a[href*="page=diluxone-users-status&tab=tools"]').last().click());
		expect(new URL(page.url()).searchParams.get('tab')).toBe('tools');
	});
});
