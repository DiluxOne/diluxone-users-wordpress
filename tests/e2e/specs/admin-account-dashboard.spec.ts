import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { accountSection, adminUrl, savePanel } from '../support/ui';
import { memberElsewhere, railNotice, signInMember } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Account area › The page, the summary, the site menu and the WordPress
 * dashboard: what each tab saves, and what a subscriber, an author or the
 * administrator meets because of it.
 */

test.use({ storageState: ADMIN_STATE });

const DASHBOARD = [
	'diluxone_users_wp_profile',
	'diluxone_users_wp_profile_scope',
	'diluxone_users_wp_profile_roles',
	'diluxone_users_admin_bar',
	'diluxone_users_admin_bar_scope',
	'diluxone_users_admin_bar_roles',
	'diluxone_users_admin_bar_keep_admins',
	'diluxone_users_bar_account',
];

const choice = (page: Page, name: string, value: string) => page.locator(`input[name="${name}"][value="${value}"]`);
const tick = (page: Page, name: string, on: boolean) => page.locator(`input[type="checkbox"][name="${name}"]`).setChecked(on, { force: true });

/** The summary row whose link goes to a tab, by its state class. */
const summaryState = (page: Page, href: string) =>
	page.locator('table.diluxone-users-summary tr').filter({ has: page.locator(`.diluxone-users-summary__change a[href*="${href}"]`) }).first().locator('.diluxone-users-summary__state .diluxone-users-state');

test.describe('Account area › The page', () => {
	test('another page chosen is the account: its sections answer at its own addresses', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_account_page']);

		const other = await site.page('h4-account', '[diluxone_users_account]');

		try {
			await page.goto(adminUrl('diluxone-users-account', 'page'));
			await page.locator('select#diluxone_users_account_page').selectOption(String(other.id));
			await savePanel(page);

			expect(Number((await site.getOptions(['diluxone_users_account_page'])).diluxone_users_account_page)).toBe(other.id);
			await expect(page.locator('.du-note a[href]').filter({ hasText: /\S/ }).first(), 'the rail names the page').toHaveAttribute('href', other.url);

			await signInMember(guest, site, pages.login.url, 'account-page');

			const answer = await guest.goto(accountSection(other.url, 'security'));

			expect(answer?.status(), 'the address was rebuilt for the new page').toBe(200);
			await expect(guest.locator('a.diluxone-users-account__tab.is-current[href*="/security/"]')).toHaveAttribute('href', new RegExp(`^${other.url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
		} finally {
			// Back, and the addresses rebuilt for the page every other spec uses.
			await site.setOptions({ diluxone_users_account_page: pages.account.id }, { flush: true });
			await site.forgetPage('h4-account');
		}
	});

	test('“Create the page” makes one with the shortcode, chooses it and a stranger meets the way in on it', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_account_page']);
		await options.set({ diluxone_users_account_page: 0 });

		await page.goto(adminUrl('diluxone-users-account', 'page'));

		const create = page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_account_page"]');

		await expect(create).toHaveAttribute('href', /_wpnonce=/);
		await Promise.all([page.waitForURL(/diluxone_users_created=\d+/), create.click()]);

		const id = Number(new URL(page.url()).searchParams.get('diluxone_users_created'));

		try {
			expect(Number((await site.getOptions(['diluxone_users_account_page'])).diluxone_users_account_page)).toBe(id);
			await expect(create, 'not offered again once there is a page').toHaveCount(0);

			await guest.goto(`/?page_id=${id}`);
			await expect(guest.locator('.diluxone-users-account--guest')).toBeVisible();
		} finally {
			await site.setOptions({ diluxone_users_account_page: pages.account.id }, { flush: true });
			await site.forgetPageId(id);
		}
	});

	test('“None”: the rail says the site has no account area and what that means; the toolbar keeps WordPress’s profile', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_account_page', ...DASHBOARD]);
		await options.set({ diluxone_users_admin_bar: 'wp', diluxone_users_bar_account: 1 });

		await page.goto(adminUrl('diluxone-users-account', 'page'));
		await page.locator('select#diluxone_users_account_page').selectOption('0');

		try {
			await savePanel(page);

			expect(Number((await site.getOptions(['diluxone_users_account_page'])).diluxone_users_account_page)).toBe(0);
			await expect(page.locator('.du-note .diluxone-users-state--pending')).toHaveCount(1);
			await expect(railNotice(page, 'warning')).toHaveCount(1);

			await page.goto(adminUrl('diluxone-users-account', 'summary'));
			await expect(summaryState(page, 'tab=page')).toHaveClass(/--pending/);

			await signInMember(guest, site, pages.login.url, 'no-page');
			await guest.goto('/');
			await expect(guest.locator('#wp-admin-bar-user-info a'), 'with no account page, Edit profile stays WordPress’s').toHaveAttribute('href', /\/wp-admin\/profile\.php/);
		} finally {
			await site.setOptions({ diluxone_users_account_page: pages.account.id }, { flush: true });
		}
	});
});

test.describe('Account area › Summary', () => {
	test('each row reads the setting its tab writes, and links to that tab', async ({ page, options }) => {
		await options.keep([...DASHBOARD, 'diluxone_users_privacy_export', 'diluxone_users_privacy_delete', 'diluxone_users_handle_enabled']);
		await options.set({
			diluxone_users_admin_bar: 'hide',
			diluxone_users_admin_bar_scope: 'some',
			diluxone_users_wp_profile: 'block',
			diluxone_users_privacy_export: 0,
			diluxone_users_privacy_delete: 0,
			diluxone_users_handle_enabled: 0,
		});

		await page.goto(adminUrl('diluxone-users-account', 'summary'));

		await expect(summaryState(page, 'tab=page')).toHaveClass(/--active/);
		await expect(summaryState(page, 'tab=sections')).toHaveClass(/--active/);
		await expect(summaryState(page, 'tab=handle')).toHaveClass(/--off/);
		await expect(summaryState(page, 'section=privacy'), 'both data switches off').toHaveClass(/--off/);

		const dashboardRows = page.locator('table.diluxone-users-summary tr').filter({ has: page.locator('.diluxone-users-summary__change a[href*="tab=dashboard"]') });

		await expect(dashboardRows).toHaveCount(2);
		await expect(dashboardRows.nth(0).locator('.diluxone-users-state'), 'the profile is kept out').toHaveClass(/--off/);
		await expect(dashboardRows.nth(1).locator('.diluxone-users-state'), 'the toolbar is hidden').toHaveClass(/--off/);

		await options.set({ diluxone_users_admin_bar: 'wp', diluxone_users_wp_profile: 'allow', diluxone_users_privacy_delete: 1, diluxone_users_handle_enabled: 1 });
		await page.reload();

		await expect(summaryState(page, 'tab=handle')).toHaveClass(/--active/);
		await expect(summaryState(page, 'section=privacy')).toHaveClass(/--active/);
		await expect(dashboardRows.nth(0).locator('.diluxone-users-state')).toHaveClass(/--active/);
		await expect(dashboardRows.nth(1).locator('.diluxone-users-state')).toHaveClass(/--active/);
	});
});

test.describe('Account area › In the site menu', () => {
	test('the place and the look are saved, and the rail names the menu that place has', async ({ page, site, options }) => {
		await options.keep(['diluxone_users_menu_location', 'diluxone_users_menu_style']);

		const made = await site.menu();

		try {
			await page.goto(adminUrl('diluxone-users-account', 'menu'));
			await page.locator('select[name="diluxone_users_menu_location"]').selectOption(made.location);
			await page.locator('select[name="diluxone_users_menu_style"]').selectOption('name');
			await savePanel(page);

			expect(await site.getOptions(['diluxone_users_menu_location', 'diluxone_users_menu_style'])).toEqual({
				diluxone_users_menu_location: made.location,
				diluxone_users_menu_style: 'name',
			});
			await expect(page.locator('select[name="diluxone_users_menu_style"]')).toHaveValue('name');
			await expect(page.locator('.du-note .diluxone-users-state--active'), 'that place has a menu').toHaveCount(1);

			// A look that is not on the list is not kept.
			await page.locator('select[name="diluxone_users_menu_style"]').evaluate((select: HTMLSelectElement) => {
				select.add(new Option('forged', 'forged'));
				select.value = 'forged';
			});
			await savePanel(page);
			expect((await site.getOptions(['diluxone_users_menu_style'])).diluxone_users_menu_style).toBe('avatar-name');
		} finally {
			await site.forgetMenu();
		}
	});
});

test.describe('Account area › The WordPress dashboard', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(DASHBOARD);
		await options.set({ diluxone_users_wp_profile: 'allow', diluxone_users_admin_bar: 'wp', diluxone_users_admin_bar_keep_admins: 1, diluxone_users_bar_account: 0 });
	});

	test('“close it”: a subscriber’s profile screen answers that it is not available; the administrator’s still opens', async ({ page, guest, site, pages }) => {
		await signInMember(guest, site, pages.login.url, 'profile-block');

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await choice(page, 'diluxone_users_wp_profile', 'block').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_wp_profile'])).diluxone_users_wp_profile).toBe('block');

		const answer = await guest.goto('/wp-admin/profile.php');

		expect(answer?.status()).toBe(403);
		await expect(guest.locator('#your-profile')).toHaveCount(0);

		await page.goto('/wp-admin/profile.php');
		await expect(page.locator('#your-profile')).toHaveCount(1);
	});

	test('“for whom”: only the roles ticked are sent away; the others keep their profile', async ({ page, guest, site, pages, browser, baseURL }) => {
		// Signed in first: the check that says "signed in" asks profile.php,
		// the very screen this rule moves.
		await signInMember(guest, site, pages.login.url, 'scope-sub');

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await choice(page, 'diluxone_users_wp_profile', 'redirect').check({ force: true });
		await choice(page, 'diluxone_users_wp_profile_scope', 'some').check({ force: true });
		await page.locator('input[name="diluxone_users_wp_profile_roles[]"][value="subscriber"]').setChecked(true, { force: true });
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_wp_profile', 'diluxone_users_wp_profile_scope', 'diluxone_users_wp_profile_roles'])).toEqual({
			diluxone_users_wp_profile: 'redirect',
			diluxone_users_wp_profile_scope: 'some',
			diluxone_users_wp_profile_roles: ['subscriber'],
		});

		await guest.goto('/wp-admin/profile.php');
		expect(new URL(guest.url()).pathname, 'the subscriber is sent to the details').toBe(new URL(accountSection(pages.account.url, 'details')).pathname);

		const contributor = await memberElsewhere(browser, baseURL!, site, pages.login.url, 'scope-contrib', { role: 'contributor' });

		try {
			await contributor.page.goto('/wp-admin/profile.php');
			expect(new URL(contributor.page.url()).pathname).toBe('/wp-admin/profile.php');
			await expect(contributor.page.locator('#your-profile')).toHaveCount(1);
		} finally {
			await contributor.close();
		}
	});

	test('the toolbar hidden for some roles: gone for a subscriber, there for an author; nothing ticked hides it from nobody', async ({
		page,
		guest,
		site,
		pages,
		browser,
		baseURL,
	}) => {
		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));

		const roles = page.locator('.diluxone-users-scope__roles').filter({ has: page.locator('input[name="diluxone_users_admin_bar_roles[]"]') });

		await choice(page, 'diluxone_users_admin_bar', 'hide-all').check({ force: true });
		await expect(roles, 'the roles belong to “only some roles”').toBeHidden();
		await choice(page, 'diluxone_users_admin_bar', 'hide-some').check({ force: true });
		await expect(roles).toBeVisible();

		await page.locator('input[name="diluxone_users_admin_bar_roles[]"][value="subscriber"]').setChecked(true, { force: true });
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_admin_bar', 'diluxone_users_admin_bar_scope', 'diluxone_users_admin_bar_roles'])).toEqual({
			diluxone_users_admin_bar: 'hide',
			diluxone_users_admin_bar_scope: 'some',
			diluxone_users_admin_bar_roles: ['subscriber'],
		});

		await signInMember(guest, site, pages.login.url, 'bar-sub');
		await guest.goto('/');
		await expect(guest.locator('#wpadminbar')).toHaveCount(0);

		const author = await memberElsewhere(browser, baseURL!, site, pages.login.url, 'bar-author', { role: 'author' });

		try {
			await author.page.goto('/');
			await expect(author.page.locator('#wpadminbar')).toHaveCount(1);
		} finally {
			await author.close();
		}

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await page.locator('input[name="diluxone_users_admin_bar_roles[]"][value="subscriber"]').setChecked(false, { force: true });
		await savePanel(page);

		await guest.goto('/');
		await expect(guest.locator('#wpadminbar'), 'nothing ticked: the strip stays for everybody').toHaveCount(1);
	});

	test('“whoever can edit users keeps it”: ticked the administrator keeps the toolbar, unticked they lose it too', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await choice(page, 'diluxone_users_admin_bar', 'hide-all').check({ force: true });
		await tick(page, 'diluxone_users_admin_bar_keep_admins', true);
		await savePanel(page);

		// Under “only some roles”, the administrator's row is fixed.
		await choice(page, 'diluxone_users_admin_bar', 'hide-some').check({ force: true });
		await expect(page.locator('.diluxone-users-roles__item--fixed input:disabled').first()).toBeAttached();
		await choice(page, 'diluxone_users_admin_bar', 'hide-all').check({ force: true });

		await page.goto('/');
		await expect(page.locator('#wpadminbar'), 'the administrator keeps it').toHaveCount(1);

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await tick(page, 'diluxone_users_admin_bar_keep_admins', false);
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_admin_bar_keep_admins'])).diluxone_users_admin_bar_keep_admins)).toBe(0);

		await page.goto('/');
		await expect(page.locator('#wpadminbar'), 'unticked, the administrator loses it as well').toHaveCount(0);
		await expect(page.locator('.diluxone-users-roles__item--fixed'), '…and the fixed row is gone').toHaveCount(0);
	});

	test('the toolbar’s user menu: ticked, the name and the profile line go to the account; unticked, to profile.php', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await tick(page, 'diluxone_users_bar_account', true);
		await savePanel(page);

		await signInMember(guest, site, pages.login.url, 'bar-account');
		await guest.goto('/');

		const account = new RegExp(`^${pages.account.url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`);

		await expect(guest.locator('#wp-admin-bar-user-info a')).toHaveAttribute('href', account);
		await expect(guest.locator('#wp-admin-bar-my-account > a')).toHaveAttribute('href', account);

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await tick(page, 'diluxone_users_bar_account', false);
		await savePanel(page);

		await guest.goto('/');
		await expect(guest.locator('#wp-admin-bar-user-info a')).toHaveAttribute('href', /\/wp-admin\/profile\.php/);
	});
});
