import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, adminUrl, answeringDialog, navigated, openPanel, savePanel, signInWithPassword, submitPanelWithoutScript } from '../support/ui';
import { freshTag, Ops, rail, stateIn } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Reports › Activity and Reports › Log settings, used the way an
 * administrator uses them — on rows of the test's own.
 *
 * The site's log is never emptied or rewritten for a test: every row a test
 * reads is one it wrote (through the side door, tagged so the search finds
 * them and nothing else, and deleted again by that tag) or one a person in
 * the test made happen. The one test that presses "Delete every row" keeps a
 * copy of the whole table first and puts every row back.
 */

test.use({ storageState: ADMIN_STATE });

const REPORT = 'diluxone-users-reports';
const PASSWORD = 'e2e-Report-Activity-1!';

const rows = (page: Page) => page.locator('table[data-diluxone-users-log] tbody tr');
const eventRows = (page: Page) => page.locator('table[data-diluxone-users-log] tbody tr[data-diluxone-users-event]');
const searchForm = (page: Page) => page.locator('form.diluxone-users-search');

/** A day as the date box takes it, `days` from today (UTC, which is the site's here). */
function day(days: number): string {
	return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
}

test.describe('Reports › Activity', () => {
	let ops: Ops;
	let tag: string;

	test.beforeEach(async ({ request }) => {
		ops = new Ops(request);
		tag = freshTag('ra');
	});

	test.afterEach(async () => {
		await ops.deleteLogRows(tag);
	});

	test('the event chosen in its list and sent shows those rows only; Clear brings everything back', async ({ page }) => {
		await ops.addLogRows({ tag, count: 3, event: 'sign_in_failed' });
		await ops.addLogRows({ tag, count: 2, event: 'signed_out' });

		await page.goto(adminUrl(REPORT, 'activity'));
		await page.locator('#diluxone-users-log-s').fill(tag);
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		await expect(eventRows(page)).toHaveCount(5);

		await page.locator('#diluxone-users-log-event').selectOption('sign_in_failed');
		await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());

		expect(new URL(page.url()).searchParams.get('event')).toBe('sign_in_failed');
		expect(new URL(page.url()).searchParams.get('s')).toBe(tag);
		await expect(eventRows(page)).toHaveCount(3);
		await expect(page.locator('tr[data-diluxone-users-event]:not([data-diluxone-users-event="sign_in_failed"])')).toHaveCount(0);

		await navigated(page, () => searchForm(page).locator('a.button[href*="tab=activity"]').click());
		expect(new URL(page.url()).searchParams.get('s')).toBeNull();
		expect(new URL(page.url()).searchParams.get('event')).toBeNull();
		await expect(page.locator('#diluxone-users-log-event')).toHaveValue('');
	});

	test('the dates From and To keep the rows of their days and leave out the rest', async ({ page }) => {
		await ops.addLogRows({ tag: `${tag}-new`, count: 2, days: 0 });
		await ops.addLogRows({ tag: `${tag}-old`, count: 3, days: 10 });

		const filter = async (from: string, to: string) => {
			await page.goto(adminUrl(REPORT, 'activity', { s: tag }));
			await page.locator('#diluxone-users-log-from').fill(from);
			await page.locator('#diluxone-users-log-to').fill(to);
			await navigated(page, () => searchForm(page).locator('input[type="submit"]').click());
		};

		try {
			await filter(day(-5), '');
			expect(new URL(page.url()).searchParams.get('from')).toBe(day(-5));
			await expect(eventRows(page)).toHaveCount(2);

			await filter('', day(-5));
			await expect(eventRows(page)).toHaveCount(3);

			await filter(day(-11), day(-9));
			await expect(eventRows(page)).toHaveCount(3);

			await filter(day(1), '');
			await expect(eventRows(page)).toHaveCount(0);
			await expect(rows(page).locator('td[colspan="6"]'), 'the row that says nothing matches').toHaveCount(1);
		} finally {
			await ops.deleteLogRows(`${tag}-new`);
			await ops.deleteLogRows(`${tag}-old`);
		}
	});

	test('the number per page sends itself, and the pages hold the rest', async ({ page }) => {
		await ops.addLogRows({ tag, count: 25 });

		await page.goto(adminUrl(REPORT, 'activity', { s: tag }));
		await expect(eventRows(page)).toHaveCount(20);
		await expect(page.locator('.tablenav-pages')).toHaveCount(1);

		await navigated(page, () => searchForm(page).locator('select[name="per"]').selectOption('10'));
		expect(new URL(page.url()).searchParams.get('per')).toBe('10');
		await expect(eventRows(page)).toHaveCount(10);

		await navigated(page, () => page.locator('.tablenav-pages a.page-numbers').filter({ hasText: /^3$/ }).click());
		expect(new URL(page.url()).searchParams.get('paged')).toBe('3');
		expect(new URL(page.url()).searchParams.get('s'), 'the search travels with the pages').toBe(tag);
		await expect(eventRows(page)).toHaveCount(5);
	});

	test('the person: “—” for a refused attempt that is nobody’s, a link and the address for somebody’s', async ({ page, site }) => {
		const email = freshEmail(tag);
		const person = await site.makeUser({ email });

		await ops.addLogRows({ tag, count: 1, event: 'sign_in_failed' });
		await ops.addLogRows({ tag, count: 1, event: 'profile_saved', email });

		await page.goto(adminUrl(REPORT, 'activity', { s: tag, event: 'sign_in_failed' }));
		await expect(eventRows(page)).toHaveCount(1);
		await expect(eventRows(page).locator('.diluxone-users-list__name a')).toHaveCount(0);
		await expect(eventRows(page).locator('.diluxone-users-list__mail')).toHaveText('—');

		await page.goto(adminUrl(REPORT, 'activity', { s: email, event: 'profile_saved' }));
		await expect(eventRows(page)).toHaveCount(1);
		await expect(eventRows(page).locator('.diluxone-users-list__mail')).toHaveText(email);
		await expect(eventRows(page).locator('.diluxone-users-list__name a')).toHaveAttribute('href', new RegExp(`user-edit\\.php\\?user_id=${person.id}`));
	});
});

test.describe('Reports › Log settings', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_log_levels', 'diluxone_users_log_days']);
	});

	test('the days kept are saved, read back, and said in the rail', async ({ page, site }) => {
		await page.goto(adminUrl(REPORT, 'logging'));
		await page.locator('input[name="diluxone_users_log_days"]').fill('30');
		await savePanel(page);
		await page.reload();

		await expect(page.locator('input[name="diluxone_users_log_days"]')).toHaveValue('30');
		expect(Number((await site.getOptions(['diluxone_users_log_days'])).diluxone_users_log_days)).toBe(30);
		await expect(rail(page)).toContainText('30');

		await page.locator('input[name="diluxone_users_log_days"]').fill('0');
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_log_days'])).diluxone_users_log_days)).toBe(0);
	});

	test('a group that is not one of the three is not saved, whatever the form sends', async ({ page, site }) => {
		await page.goto(adminUrl(REPORT, 'logging'));
		await page.locator('input[name="diluxone_users_log_levels[]"][value="access"]').check();
		await page.locator('input[name="diluxone_users_log_levels[]"][value="access"]').evaluate((input: HTMLInputElement) => {
			const forged = input.cloneNode() as HTMLInputElement;

			forged.value = 'everything';
			forged.id = 'forged';
			forged.checked = true;
			input.parentElement!.appendChild(forged);
		});
		await submitPanelWithoutScript(page);

		const saved = (await site.getOptions(['diluxone_users_log_levels'])).diluxone_users_log_levels as string[];

		expect(saved).toContain('access');
		expect(saved).not.toContain('everything');
	});

	test('“changes to the account” and “changes to the security” ticked record what a person does; unticked, nothing', async ({
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
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const levels = (page: Page, group: string) => page.locator(`input[name="diluxone_users_log_levels[]"][value="${group}"]`);
		const count = async (email: string, event: string) => {
			await page.goto(adminUrl(REPORT, 'activity', { s: email, event }));

			return page.locator(`tr[data-diluxone-users-event="${event}"]`).count();
		};

		const email = freshEmail('ra-groups');

		await site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
		const person = await context.newPage();

		/** The person saves their details with a new first name. */
		const saveDetails = async (name: string) => {
			await person.goto(accountSection(pages.account.url, 'details'));

			const form = person.locator('form').filter({ has: person.locator('input[name="action"][value="diluxone_users_fields_save"]') });

			await form.locator('input[name="first_name"]').fill(name);
			await navigated(person, () => form.locator('button[type="submit"]').first().click());
		};

		/** The person turns the second step on, or off, from their security section. */
		const twoStep = async (value: 'on' | 'off') => {
			await person.goto(accountSection(pages.account.url, 'security'));

			const button = person.locator(`button[name="diluxone_users_security"][value="${value}"]`);

			await openPanel(person, `button[name="diluxone_users_security"][value="${value}"]`);
			await navigated(person, () => button.click());
		};

		try {
			await person.goto(pages.login.url);
			await signInWithPassword(person, email, PASSWORD);
			await expectSignedIn(person, email);

			// Only "ways in and out".
			await page.goto(adminUrl(REPORT, 'logging'));
			await levels(page, 'access').check();
			await levels(page, 'account').uncheck();
			await levels(page, 'security').uncheck();
			await savePanel(page);
			expect((await site.getOptions(['diluxone_users_log_levels'])).diluxone_users_log_levels).toEqual(['access']);

			await saveDetails('Unrecorded');
			await twoStep('on');
			expect((await site.user(email)).meta.diluxone_users_2fa_on, 'it is on').toBe('1');
			expect(await count(email, 'profile_saved'), 'the account group is off').toBe(0);
			expect(await count(email, '2fa_on'), 'the security group is off').toBe(0);

			// The other two ticked as well.
			await page.goto(adminUrl(REPORT, 'logging'));
			await levels(page, 'account').check();
			await levels(page, 'security').check();
			await savePanel(page);
			expect(((await site.getOptions(['diluxone_users_log_levels'])).diluxone_users_log_levels as string[]).sort()).toEqual(['access', 'account', 'security']);

			await saveDetails('Recorded');
			expect(await count(email, 'profile_saved'), 'the account group records the details saved').toBe(1);

			// Somebody else turns the second step on, now that it is recorded.
			const other = freshEmail('ra-groups-security');

			await site.makeUser({ email: other, password: PASSWORD });
			await context.clearCookies();
			await person.goto(pages.login.url);
			await signInWithPassword(person, other, PASSWORD);
			await expectSignedIn(person, other);
			await twoStep('on');
			expect((await site.user(other)).meta.diluxone_users_2fa_on, 'it is on').toBe('1');
			expect(await count(other, '2fa_on'), 'the security group records the second step turned on').toBe(1);
		} finally {
			await context.close();
		}
	});

	test('“Delete every row” asks first with the number, and says how many went — every row is put back afterwards', async ({
		page,
		request,
		options,
	}) => {
		const ops = new Ops(request);
		const tag = freshTag('ra-empty');

		await options.set({ diluxone_users_log_levels: ['access'] });
		await ops.addLogRows({ tag, count: 3 });

		const kept = await ops.keepLog();

		try {
			await page.goto(adminUrl(REPORT, 'logging'));

			const remove = page.locator('a.button-link-delete[href*="action=diluxone_users_log_empty"][data-diluxone-users-confirm]');

			// No: nothing goes.
			await answeringDialog(page, 'dismiss', () => remove.click());
			await page.goto(adminUrl(REPORT, 'activity', { s: tag }));
			await expect(eventRows(page)).toHaveCount(3);

			// Yes: every row of this site goes, and the number is said.
			await page.goto(adminUrl(REPORT, 'logging'));

			const asked = await answeringDialog(page, 'accept', () => navigated(page, () => remove.click()));
			const gone = Number(new URL(page.url()).searchParams.get('diluxone-users-emptied'));

			expect(gone, 'at least the three rows of this test').toBeGreaterThanOrEqual(3);
			expect(asked.replace(/\D/g, ''), 'the question named the same number').toContain(String(gone));
			await expect(page.locator('.notice-success')).toHaveCount(1);
			expect((await page.locator('.notice-success').innerText()).replace(/\D/g, ''), 'the notice says how many').toBe(String(gone));
			await expect(page.locator('a.button-link-delete[href*="action=diluxone_users_log_empty"]'), 'nothing left to delete').toHaveCount(0);
			expect(await stateIn(rail(page))).toBe('active');

			await page.goto(adminUrl(REPORT, 'activity', { s: tag }));
			await expect(eventRows(page)).toHaveCount(0);
		} finally {
			await ops.restoreLog();
			await ops.deleteLogRows(tag);
		}

		expect(kept).toBeGreaterThanOrEqual(3);
		await page.goto(adminUrl(REPORT, 'logging'));
		await expect(page.locator('a.button-link-delete[href*="action=diluxone_users_log_empty"]'), 'the site’s rows are back').toHaveCount(kept > 3 ? 1 : 0);
	});
});
