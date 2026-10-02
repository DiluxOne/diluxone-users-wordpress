import type { Page } from '@playwright/test';
import { test, expect, signInFrom, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { accountSection, answeringDialog, navigated, savePanel } from '../support/ui';
import { freshTag, networkScreen, Ops, siteScreen } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The reports on a network, used the way a super admin uses them — on rows
 * of the test's own.
 *
 * A site's report of open sessions and its button; Network Admin's activity
 * report with its filters, its pages and its Site list; emptying a site's
 * rows and the network's, each with the whole table kept and put back; and
 * the log's groups saved in Network Admin recording what a person does on the
 * hub's account.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Net-Reports-1!';

const rows = (page: Page) => page.locator('table[data-diluxone-users-log] tbody tr[data-diluxone-users-event]');
const searchForm = (page: Page) => page.locator('form.diluxone-users-search');
const networkActivity = (query: Record<string, string> = {}) => networkScreen(NETWORK_URL, 'diluxone-users-reports', 'network-activity', query);

function idOf(one: SiteHandle): string {
	return wp(['eval', 'echo get_current_blog_id();'], one.url).trim();
}

function day(days: number): string {
	return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
}

test.describe('Reports › Open sessions on a site of the network', () => {
	test('a super admin closes a member’s sessions from /alpha/’s report and comes back to it, told so', async ({ page, browser, hub, alpha }) => {
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });

		const email = freshEmail('nrep-close');
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });

		try {
			await alpha.site.makeUser({ email, password: PASSWORD });
			await signInFrom(await context.newPage(), alpha, hub, email, PASSWORD);

			await page.goto(siteScreen(alpha.url, 'diluxone-users-reports', 'sessions', { s: email }));
			await navigated(page, () => page.locator('table.diluxone-users-list tbody tr button[type="submit"]').click());

			expect(new URL(page.url()).pathname.startsWith(new URL(alpha.url).pathname), 'back on /alpha/').toBe(true);
			expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('closed');
			await expect(page.locator('.notice-success')).toHaveCount(1);
			expect((await alpha.site.user(email)).sessions).toBe(0);
		} finally {
			await context.close();
		}
	});
});

test.describe('Network Admin › Activity', () => {
	let tag: string;
	let alphaOps: Ops;
	let betaOps: Ops;

	test.beforeEach(async ({ request, alpha, beta }) => {
		tag = freshTag('nra');
		alphaOps = new Ops(request, alpha.url);
		betaOps = new Ops(request, beta.url);
	});

	test.afterEach(async () => {
		await alphaOps.deleteLogRows(tag);
	});

	test('the Site list, the event list and the dates, chosen and sent, narrow the rows; nothing matching is one row across every column', async ({
		page,
		alpha,
		beta,
	}) => {
		const alphaId = idOf(alpha);
		const betaId = idOf(beta);

		await alphaOps.addLogRows({ tag, count: 2, event: 'sign_in_failed' });
		await alphaOps.addLogRows({ tag, count: 1, event: 'signed_out', days: 10 });
		await betaOps.addLogRows({ tag, count: 3, event: 'sign_in_failed' });

		await page.goto(networkActivity({ s: tag }));
		await expect(rows(page)).toHaveCount(6);

		// One site, chosen in the list.
		await page.locator('#diluxone-users-log-site').selectOption(alphaId);
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		expect(new URL(page.url()).searchParams.get('site')).toBe(alphaId);
		await expect(rows(page)).toHaveCount(3);
		await expect(page.locator(`tr[data-diluxone-users-event]:not([data-diluxone-users-site="${alphaId}"])`)).toHaveCount(0);

		// And one kind of event, on top.
		await page.locator('#diluxone-users-log-event').selectOption('sign_in_failed');
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		await expect(rows(page)).toHaveCount(2);

		// Every site again, from five days ago.
		await page.locator('#diluxone-users-log-site').selectOption('0');
		await page.locator('#diluxone-users-log-event').selectOption('');
		await page.locator('#diluxone-users-log-from').fill(day(-5));
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		await expect(rows(page)).toHaveCount(5);
		await expect(page.locator(`tr[data-diluxone-users-site="${betaId}"]`)).toHaveCount(3);

		// Up to five days ago: the old row alone.
		await page.locator('#diluxone-users-log-from').fill('');
		await page.locator('#diluxone-users-log-to').fill(day(-5));
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		await expect(rows(page)).toHaveCount(1);

		await page.locator('#diluxone-users-log-to').fill(day(-20));
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		await expect(rows(page)).toHaveCount(0);
		await expect(page.locator('table[data-diluxone-users-log] tbody td[colspan="8"]')).toHaveCount(1);

		// Clear: every site, every kind, every day.
		await navigated(page, () => searchForm(page).locator('a.button[href*="tab=network-activity"]').click());
		expect(new URL(page.url()).searchParams.get('to')).toBeNull();
	});

	test('the number per page sends itself, and the pages keep the search', async ({ page }) => {
		await alphaOps.addLogRows({ tag, count: 12 });

		await page.goto(networkActivity({ s: tag }));
		await navigated(page, () => searchForm(page).locator('select[name="per"]').selectOption('10'));
		expect(new URL(page.url()).searchParams.get('per')).toBe('10');
		await expect(rows(page)).toHaveCount(10);

		await navigated(page, () => page.locator('.tablenav-pages a.page-numbers').filter({ hasText: /^2$/ }).click());
		expect(new URL(page.url()).searchParams.get('s')).toBe(tag);
		await expect(rows(page)).toHaveCount(2);
	});
});

test.describe('Emptying the log on a network', () => {
	test('/alpha/’s “Delete every row” asks with the number, takes /alpha/’s rows only, and says how many — every row is put back', async ({
		page,
		request,
		hub,
		alpha,
		beta,
	}) => {
		const tag = freshTag('nre');
		const alphaOps = new Ops(request, alpha.url);
		const betaOps = new Ops(request, beta.url);

		await alphaOps.addLogRows({ tag, count: 2 });
		await betaOps.addLogRows({ tag, count: 2 });
		await hub.set({ diluxone_users_log_levels: ['access'] });

		await alphaOps.keepLog();

		try {
			await page.goto(siteScreen(alpha.url, 'diluxone-users-reports', 'activity'));

			const remove = page.locator('a.button-link-delete[href*="action=diluxone_users_log_empty"][data-diluxone-users-confirm]');

			await expect(remove).toHaveAttribute('href', /action=diluxone_users_log_empty&/);

			const asked = await answeringDialog(page, 'accept', () => navigated(page, () => remove.click()));
			const gone = Number(new URL(page.url()).searchParams.get('diluxone-users-emptied'));

			expect(gone).toBeGreaterThanOrEqual(2);
			expect(asked.replace(/\D/g, '')).toContain(String(gone));
			expect((await page.locator('.notice-success').innerText()).replace(/\D/g, '')).toBe(String(gone));

			const [alphaId, betaId] = [idOf(alpha), idOf(beta)];

			await page.goto(networkActivity({ s: tag }));
			await expect(page.locator(`tr[data-diluxone-users-site="${alphaId}"]`)).toHaveCount(0);
			await expect(page.locator(`tr[data-diluxone-users-site="${betaId}"]`), '/beta/’s rows are not /alpha/’s to empty').toHaveCount(2);
		} finally {
			await alphaOps.restoreLog();
			await alphaOps.deleteLogRows(tag);
		}

		await page.goto(networkActivity({ s: tag }));
		await expect(rows(page)).toHaveCount(0);
	});

	test('Network Admin’s “Delete every row” takes every site’s rows and says how many — every row is put back', async ({
		page,
		request,
		hub,
		alpha,
		beta,
	}) => {
		const tag = freshTag('nrn');
		const alphaOps = new Ops(request, alpha.url);
		const betaOps = new Ops(request, beta.url);

		await alphaOps.addLogRows({ tag, count: 2 });
		await betaOps.addLogRows({ tag, count: 1 });
		await hub.set({ diluxone_users_log_levels: ['access'] });

		await alphaOps.keepLog();

		try {
			await page.goto(networkActivity());

			const remove = page.locator('a.button-link-delete[href*="action=diluxone_users_log_empty_network"]');
			const asked = await answeringDialog(page, 'accept', () => navigated(page, () => remove.click()));
			const gone = Number(new URL(page.url()).searchParams.get('diluxone-users-emptied'));

			expect(gone).toBeGreaterThanOrEqual(3);
			expect(asked.replace(/\D/g, '')).toContain(String(gone));
			expect((await page.locator('.notice-success').innerText()).replace(/\D/g, '')).toBe(String(gone));

			await page.goto(networkActivity({ s: tag }));
			await expect(rows(page)).toHaveCount(0);
		} finally {
			await alphaOps.restoreLog();
			await alphaOps.deleteLogRows(tag);
		}
	});
});

test.describe('Network Admin › Log settings', () => {
	test('“changes to the account” saved there records a member saving their details on the hub’s account; unticked, it does not', async ({
		page,
		browser,
		hub,
		alpha,
	}) => {
		await hub.keep(['diluxone_users_log_levels']);
		await hub.set({
			diluxone_users_log_levels: ['access'],
			diluxone_users_2fa_mode: 'off',
			diluxone_users_login_method: 'both',
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const email = freshEmail('nlog-account');
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();
		const levels = (group: string) => page.locator(`input[name="diluxone_users_log_levels[]"][value="${group}"]`);
		const count = async () => {
			await page.goto(networkActivity({ s: email, event: 'profile_saved' }));

			return rows(page).count();
		};
		const saveDetails = async (name: string) => {
			await person.goto(accountSection(hub.pages.account.url, 'details'));

			const form = person.locator('form').filter({ has: person.locator('input[name="action"][value="diluxone_users_fields_save"]') });

			await form.locator('input[name="first_name"]').fill(name);
			await navigated(person, () => form.locator('button[type="submit"]').first().click());
		};

		try {
			await alpha.site.makeUser({ email, password: PASSWORD });
			await signInFrom(person, alpha, hub, email, PASSWORD);

			await saveDetails('Unrecorded');
			expect(await count()).toBe(0);

			await page.goto(networkScreen(NETWORK_URL, 'diluxone-users-reports', 'logging'));
			await levels('account').check();
			await savePanel(page);
			expect(((await alpha.site.getOptions(['diluxone_users_log_levels'])).diluxone_users_log_levels as string[]).sort()).toEqual(['access', 'account']);

			await saveDetails('Recorded');
			expect(await count()).toBe(1);
		} finally {
			await context.close();
		}
	});
});
