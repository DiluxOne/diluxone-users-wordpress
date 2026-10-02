import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminError, adminSaved, navigated } from '../support/ui';
import { MOCK_SSO, accessTab, control, notNow } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Access › How they are arranged, driven through its controls.
 *
 * login-ways.spec.ts proves the order and "always stacked" from the tab, and
 * the rest with the settings put in place by hand. This is the rest from the
 * tab: "always in tabs" and "whichever suits" with their threshold, the tab
 * that opens first, a hand-made form full of junk, the notes, a real drag
 * that sends the form by itself, and the preview beside the list.
 */

test.use({ storageState: ADMIN_STATE });

const LAPTOP = { width: 1366, height: 768 };

/** The arrangement's own Save button, in the column beside the list. */
function saveArrangement(page: Page): Locator {
	return page.locator('[data-diluxone-users-save] [form="diluxone-users-arrangement"]');
}

async function save(page: Page): Promise<void> {
	await navigated(page, () => saveArrangement(page).click());
	await expect(adminSaved(page)).toBeVisible();
	await expect(adminError(page)).toHaveCount(0);
}

function strip(page: Page): Locator {
	return page.locator('[data-diluxone-users-ways-strip]');
}

/** The tab a page opened on, by the way in it shows. */
async function openTab(page: Page): Promise<string> {
	return (await page.locator('[data-diluxone-users-way-tab][aria-selected="true"]').getAttribute('data-diluxone-users-way-tab')) ?? '';
}

/** Two ways in: the link and the password, nothing else. */
const TWO_WAYS = { diluxone_users_login_method: 'both', diluxone_users_sso_login: 0, diluxone_users_passkey_enabled: 0, diluxone_e2e_sso: 0 };

test.describe('Access › How they are arranged', () => {
	test.beforeEach(async ({ options, guest }) => {
		await options.keep(['diluxone_users_login_layout', 'diluxone_users_login_order', 'diluxone_users_login_open']);
		await guest.setViewportSize(LAPTOP);
	});

	test('“always in tabs” puts even two ways behind a strip', async ({ page, guest, site, pages, options }) => {
		await options.set({ ...TWO_WAYS, diluxone_users_login_layout: 'stack' });

		await page.goto(accessTab('arrangement'));
		await control(page, 'diluxone_users_login_layout', 'tabs').check();
		await save(page);

		expect((await site.getOptions(['diluxone_users_login_layout'])).diluxone_users_login_layout).toBe('tabs');
		await expect(control(page, 'diluxone_users_login_layout', 'tabs')).toBeChecked();

		await guest.goto(pages.login.url);
		await expect(strip(guest)).toBeVisible();
		await expect(guest.locator('[data-diluxone-users-way-tab]')).toHaveCount(2);
	});

	test('“whichever suits” stacks two ways and puts three behind tabs', async ({ page, guest, site, pages, options }) => {
		await options.set({ ...TWO_WAYS, diluxone_users_login_layout: 'tabs' });

		await page.goto(accessTab('arrangement'));
		await control(page, 'diluxone_users_login_layout', 'auto').check();
		await save(page);

		expect((await site.getOptions(['diluxone_users_login_layout'])).diluxone_users_login_layout).toBe('auto');

		await guest.goto(pages.login.url);
		await expect(strip(guest), 'two ways in, and a strip anyway').toBeHidden();
		await expect(guest.locator('[data-diluxone-users-way="email"]')).toBeVisible();
		await expect(guest.locator('[data-diluxone-users-way="password"]')).toBeVisible();

		// A third way in, and the same answer is a strip.
		await options.set({ ...MOCK_SSO, diluxone_users_sso_login: 1 });
		await guest.goto(pages.login.url);
		await expect(strip(guest)).toBeVisible();
		await expect(guest.locator('[data-diluxone-users-way-tab]')).toHaveCount(3);
	});

	test('the tab a stranger’s first visit opens on, chosen in the drop-down; stacked, the tab says none opens', async ({ page, guest, site, pages, options }) => {
		await options.set({ ...TWO_WAYS, diluxone_users_login_layout: 'stack', diluxone_users_login_open: '' });

		await page.goto(accessTab('arrangement'));
		await expect(notNow(page), 'stacked: no tab opens first, and the tab says so').toHaveCount(1);

		await control(page, 'diluxone_users_login_layout', 'tabs').check();
		await page.locator('select[name="diluxone_users_login_open"]').selectOption('password');
		await save(page);

		expect((await site.getOptions(['diluxone_users_login_open'])).diluxone_users_login_open).toBe('password');
		await expect(page.locator('select[name="diluxone_users_login_open"]')).toHaveValue('password');
		await expect(notNow(page), 'in tabs, nothing waits').toHaveCount(0);

		await guest.goto(pages.login.url);
		expect(await openTab(guest)).toBe('password');

		// Back to the first in the order.
		await page.locator('select[name="diluxone_users_login_open"]').selectOption('');
		await save(page);
		expect((await site.getOptions(['diluxone_users_login_open'])).diluxone_users_login_open).toBe('');

		await guest.context().clearCookies();
		await guest.goto(pages.login.url);

		const first = await guest.locator('[data-diluxone-users-way-tab]').first().getAttribute('data-diluxone-users-way-tab');

		expect(await openTab(guest)).toBe(first);
	});

	test('a form sent with an unknown layout, an unknown first tab and a way in nobody has keeps none of them', async ({ page, site, options }) => {
		await options.set({ ...TWO_WAYS, diluxone_users_login_layout: 'stack' });

		await page.goto(accessTab('arrangement'));
		await page.locator('#diluxone-users-arrangement').evaluate((form: HTMLFormElement) => {
			const evil = document.createElement('input');

			evil.type = 'hidden';
			evil.name = 'diluxone_users_login_order[]';
			evil.value = 'evil';
			form.querySelector('[data-diluxone-users-sortable]')!.append(evil);

			const layout = form.querySelector<HTMLInputElement>('input[name="diluxone_users_login_layout"]:checked')!;

			layout.value = 'sideways';

			const open = form.querySelector<HTMLSelectElement>('select[name="diluxone_users_login_open"]')!;

			open.append(new Option('nope', 'nope'));
			open.value = 'nope';
		});
		await save(page);

		const stored = await site.getOptions(['diluxone_users_login_layout', 'diluxone_users_login_open', 'diluxone_users_login_order']);

		expect(stored.diluxone_users_login_layout).toBe('auto');
		expect(stored.diluxone_users_login_open).toBe('');
		expect(stored.diluxone_users_login_order as string[]).not.toContain('evil');
		expect((stored.diluxone_users_login_order as string[]).length).toBe(3);
	});

	test('the passkey is named above the list, never in it', async ({ page, options }) => {
		await options.set({ diluxone_users_passkey_enabled: 1 });

		await page.goto(accessTab('arrangement'));

		await expect(page.locator('[data-diluxone-users-sortable] li')).toHaveCount(3);
		await expect(page.locator('[data-diluxone-users-sortable] input[value="passkey"]')).toHaveCount(0);
		await expect(page.locator('.du-note strong').first()).toBeVisible();
	});

	/*
	 * login-ways.spec.ts moves the rows by script and presses Save. The drop
	 * itself sends the form — that is the whole point of dragging — so here
	 * the rows are dragged with the mouse and nothing is pressed.
	 */
	test('a real drag reorders the list and saves it on the drop, with no press', async ({ page, site, options }) => {
		await options.set({ ...MOCK_SSO, diluxone_users_sso_login: 1, diluxone_users_login_order: ['email', 'password', 'social'] });

		await page.goto(accessTab('arrangement'));

		const rows = page.locator('[data-diluxone-users-sortable] li');

		const values = () => page.$$eval('[data-diluxone-users-sortable] li input', (all) => all.map((one) => (one as HTMLInputElement).value));

		expect(await values()).toEqual(['email', 'password', 'social']);

		await navigated(page, () => rows.last().dragTo(rows.first(), { targetPosition: { x: 10, y: 2 } }));

		await expect(adminSaved(page), 'the drop did not save').toBeVisible();

		const order = (await site.getOptions(['diluxone_users_login_order'])).diluxone_users_login_order as string[];

		expect(order[0]).toBe('social');
		expect(await values(), 'the list came back in the stored order').toEqual(order);
	});

	test('the preview beside the list redraws as the layout changes, and nothing is saved', async ({ page, site, options }) => {
		await options.set({ ...TWO_WAYS, diluxone_users_login_layout: 'stack' });

		await page.goto(accessTab('arrangement'));

		const frame = page.frameLocator('[data-diluxone-users-stage-frame]');

		await expect(frame.locator('[data-diluxone-users-way="email"]')).toBeVisible();

		const answered = page.waitForResponse((response) => response.url().includes('admin-ajax.php') && response.request().postData()?.includes('action=diluxone_users_preview') === true);

		await control(page, 'diluxone_users_login_layout', 'tabs').check();

		const response = await answered;
		const body = response.request().postData() ?? '';

		expect(body).toContain('screen=diluxone-users-login');
		expect(body).toContain('panel=arrangement');
		expect((await response.json()).success).toBe(true);

		await expect(frame.locator('[data-diluxone-users-way-tab]').first(), 'the preview did not draw the tabs').toBeAttached();
		expect((await site.getOptions(['diluxone_users_login_layout'])).diluxone_users_login_layout, 'the preview saved').toBe('stack');
	});
});
