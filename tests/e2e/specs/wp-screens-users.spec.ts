import { randomBytes } from 'node:crypto';
import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, navigated, savePanel, signInWithPassword } from '../support/ui';
import { personSignedIn } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * WordPress's own screens, as the plugin changes them.
 *
 * The Users list's Access column, the block on somebody else's profile — what
 * it reads and what it can take off — Add New User with and without its
 * script, and the toolbar on the site: hidden for some roles, kept for whoever
 * edits users, and its "Edit profile" sent to the account area. Each is
 * checked on the screen WordPress draws and in the account it changed.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Wp-Screens-1!';
const SECRET = 'JBSWY3DPEHPK3PXP';

/** A passkey as the plugin stores it on the account. */
function passkey(label: string) {
	return { id: `e2e-${randomBytes(6).toString('hex')}`, label, created: Math.floor(Date.now() / 1000), rp: '' };
}

/** One session of a day, as WordPress keeps it. */
function session() {
	const now = Math.floor(Date.now() / 1000);

	return { [randomBytes(32).toString('hex')]: { expiration: now + 86_400, ip: '192.0.2.55', ua: 'Mozilla/5.0 Chrome/120.0', login: now - 120 } };
}

/** The Access cell of one person's row on users.php. */
function accessCell(page: Page, email: string) {
	return page.locator('table.users tbody tr').filter({ hasText: email }).locator('td.column-diluxone_users');
}

test.describe('Users › All users: the Access column', () => {
	test('each person’s ways in, as pills: only the link, the second step and the app, passkeys counted, a network by name', async ({
		page,
		site,
	}) => {
		// Short: a username (here, the address) longer than sixty characters
		// is refused by WordPress.
		const tag = `ac${Date.now().toString(36)}`;
		const plain = freshEmail(`${tag}-plain`);
		const twoStep = freshEmail(`${tag}-two`);
		const keys = freshEmail(`${tag}-keys`);
		const social = freshEmail(`${tag}-social`);

		await site.makeUser({ email: plain });
		await site.makeUser({ email: twoStep, meta: { diluxone_users_2fa_on: 1, diluxone_users_totp: SECRET } });
		await site.makeUser({ email: keys, meta: { diluxone_users_passkeys: [passkey('one'), passkey('two')] } });
		await site.makeUser({ email: social, meta: { diluxone_users_sso_github: 'github|e2e-column' } });

		await page.goto(`/wp-admin/users.php?s=${encodeURIComponent(tag)}`);

		await expect(accessCell(page, plain).locator('.diluxone-users-pill')).toHaveCount(1);
		await expect(accessCell(page, plain).locator('.diluxone-users-pill--off')).toHaveCount(1);

		await expect(accessCell(page, twoStep).locator('.diluxone-users-pill--on')).toHaveCount(2);
		await expect(accessCell(page, twoStep).locator('.diluxone-users-pill--off')).toHaveCount(0);

		await expect(accessCell(page, keys).locator('.diluxone-users-pill--on')).toHaveCount(1);
		await expect(accessCell(page, keys).locator('.diluxone-users-pill--on')).toContainText('2');

		await expect(accessCell(page, social).locator('.diluxone-users-pill--blank')).toHaveText('GitHub');
	});
});

test.describe('Users › somebody’s profile: the plugin’s block', () => {
	test('it reads the public name, the last session, the second step, the passkeys and the networks, and leads to their sessions', async ({
		page,
		site,
	}) => {
		const email = freshEmail('profile-block');
		const handle = `e2e${Date.now().toString(36)}`;
		const person = await site.makeUser({
			email,
			meta: {
				diluxone_users_handle: handle,
				session_tokens: session(),
				diluxone_users_2fa_on: 1,
				diluxone_users_totp: SECRET,
				diluxone_users_passkeys: [passkey('Laptop')],
				diluxone_users_sso_github: 'github|e2e-profile',
			},
		});

		await page.goto(`/wp-admin/user-edit.php?user_id=${person.id}`);

		const summary = page.locator('table.diluxone-users-summary');
		const states = await summary.locator('tbody > tr').evaluateAll((all: Element[]) =>
			all.map((row) => (row.querySelector('.diluxone-users-state')?.className.match(/--([a-z]+)/) ?? [])[1])
		);

		expect(states, 'public name, last seen, second step, passkeys, networks').toEqual(['active', 'active', 'active', 'active', 'active']);
		await expect(summary.locator('code').filter({ hasText: handle })).toHaveCount(1);
		await expect(summary).toContainText('GitHub');

		// Every one of their sessions, on the report.
		const every = summary.locator(`a[href*="page=diluxone-users-reports"][href*="tab=sessions"]`);

		await navigated(page, () => every.click());
		expect(new URL(page.url()).searchParams.get('s')).toBe(email);
		await expect(page.locator('table.diluxone-users-list tbody .diluxone-users-list__mail')).toHaveText([email]);
	});

	test('for somebody with none of it: every row says so, nothing to take off, and an administrator’s own profile has no block', async ({
		page,
		site,
		options,
	}) => {
		// Required for everybody would make the second step "on" for anybody.
		await options.set({ diluxone_users_2fa_mode: 'optional' });

		const person = await site.makeUser({ email: freshEmail('profile-plain') });

		await page.goto(`/wp-admin/user-edit.php?user_id=${person.id}`);

		const states = await page
			.locator('table.diluxone-users-summary tbody > tr')
			.evaluateAll((all: Element[]) => all.map((row) => (row.querySelector('.diluxone-users-state')?.className.match(/--([a-z]+)/) ?? [])[1]));

		expect(states.slice(1), 'last seen, second step, passkeys, networks').toEqual(['unknown', 'off', 'off', 'off']);
		await expect(page.locator('input[name="diluxone_users_forget_totp"], input[name="diluxone_users_forget_passkey[]"], input[name="diluxone_users_unlink[]"]')).toHaveCount(0);

		await page.goto('/wp-admin/profile.php');
		await expect(page.locator('table.diluxone-users-summary')).toHaveCount(0);
	});

	test('somebody who never chose a public name: the row says none was chosen', async ({ page, site }) => {
		const person = await site.makeUser({ email: freshEmail('profile-nohandle') });

		await page.goto(`/wp-admin/user-edit.php?user_id=${person.id}`);

		const first = page.locator('table.diluxone-users-summary tbody > tr').first();

		await expect(
			first.locator('.diluxone-users-state'),
			'bug: includes/admin-people.php:29 reads diluxone_users_public_handle(), which falls back to the nicename, so “Public name” says Chosen for somebody who never chose one and its None branch is never drawn'
		).toHaveClass(/diluxone-users-state--off/);
		await expect(first.locator('code')).toHaveCount(0);
	});

	test('a passkey ticked and the profile saved is gone from the account; the one not ticked stays', async ({ page, site }) => {
		const email = freshEmail('profile-passkey');
		const gone = passkey('Lost phone');
		const kept = passkey('Desk key');
		const person = await site.makeUser({ email, meta: { diluxone_users_passkeys: [gone, kept] } });

		await page.goto(`/wp-admin/user-edit.php?user_id=${person.id}`);
		await expect(page.locator('input[name="diluxone_users_forget_passkey[]"]')).toHaveCount(2);
		await page.locator(`input[name="diluxone_users_forget_passkey[]"][value="${gone.id}"]`).check();
		await navigated(page, () => page.locator('#submit').click());

		await expect(page.locator('input[name="diluxone_users_forget_passkey[]"]')).toHaveCount(1);
		await expect(page.locator(`input[name="diluxone_users_forget_passkey[]"][value="${kept.id}"]`)).toHaveCount(1);

		// And the list on users.php counts one.
		await page.goto(`/wp-admin/users.php?s=${encodeURIComponent(email)}`);
		await expect(accessCell(page, email).locator('.diluxone-users-pill--on')).toContainText('1');
	});
});

test.describe('Users › Add New User', () => {
	test('the username row steps aside with a line saying why, and follows the e-mail as it is typed', async ({ page }) => {
		await page.goto('/wp-admin/user-new.php');

		const email = page.locator('form#createuser input#email');

		await expect(email.locator('xpath=ancestor::td[1]').locator('p.description')).toHaveCount(1);
		await email.fill('typed-here@e2e.test');
		await expect(page.locator('form#createuser input#user_login')).toHaveValue('typed-here@e2e.test');
	});

	test.describe('with the script switched off', () => {
		test.use({ javaScriptEnabled: false });

		test('the server makes the e-mail the username, whatever was in the username box', async ({ page, site }) => {
			const email = freshEmail('new-user-nojs');

			await page.goto('/wp-admin/user-new.php');

			const form = page.locator('form#createuser');

			await expect(form.locator('input#user_login')).toBeVisible();
			await form.locator('input#user_login').fill('somebody-else-entirely');
			await form.locator('input#email').fill(email);
			// Without the script nobody generates a password: it is typed.
			await form.locator('input#pass1').fill(PASSWORD);
			await form.locator('input#pass2').fill(PASSWORD);
			await navigated(page, () => form.locator('#createusersub').click());

			const made = await site.user(email);

			expect(made.exists).toBe(true);
			expect(made.login).toBe(email);
		});
	});
});

test.describe('The toolbar on the site', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep([
			'diluxone_users_admin_bar',
			'diluxone_users_admin_bar_scope',
			'diluxone_users_admin_bar_roles',
			'diluxone_users_admin_bar_keep_admins',
			'diluxone_users_bar_account',
			'diluxone_users_wp_profile',
		]);
		await options.set({ diluxone_users_admin_bar: 'wp', diluxone_users_bar_account: 0, diluxone_users_wp_profile: 'allow', diluxone_users_2fa_mode: 'off' });
	});

	test('hidden for some roles: gone for a subscriber, there for an editor', async ({ page, browser, baseURL, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await page.locator('[name="diluxone_users_admin_bar"][value="hide-some"]').check({ force: true });

		for (const box of await page.locator('input[name="diluxone_users_admin_bar_roles[]"]').all()) {
			await box.uncheck({ force: true });
		}

		await page.locator('input[name="diluxone_users_admin_bar_roles[]"][value="subscriber"]').check({ force: true });
		await savePanel(page);
		expect(await site.getOptions(['diluxone_users_admin_bar', 'diluxone_users_admin_bar_scope', 'diluxone_users_admin_bar_roles'])).toEqual({
			diluxone_users_admin_bar: 'hide',
			diluxone_users_admin_bar_scope: 'some',
			diluxone_users_admin_bar_roles: ['subscriber'],
		});

		const subscriber = await personSignedIn(browser, baseURL!, site, pages.login.url, 'bar-some-sub');
		const editor = await personSignedIn(browser, baseURL!, site, pages.login.url, 'bar-some-editor', { role: 'editor' });

		try {
			await subscriber.page.goto('/');
			await expect(subscriber.page.locator('#wpadminbar')).toHaveCount(0);

			await editor.page.goto('/');
			await expect(editor.page.locator('#wpadminbar')).toHaveCount(1);
		} finally {
			await subscriber.context.close();
			await editor.context.close();
		}
	});

	test('hidden for everybody: whoever edits users keeps it while the exception is ticked, and loses it when it is not', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const admin = await personSignedIn(browser, baseURL!, site, pages.login.url, 'bar-keep-admin', { role: 'administrator' });

		try {
			await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
			await page.locator('[name="diluxone_users_admin_bar"][value="hide-all"]').check({ force: true });
			await page.locator('input[name="diluxone_users_admin_bar_keep_admins"]').check({ force: true });
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_admin_bar_keep_admins'])).diluxone_users_admin_bar_keep_admins)).toBe(1);

			await admin.page.goto('/');
			await expect(admin.page.locator('#wpadminbar'), 'kept').toHaveCount(1);

			await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
			await page.locator('input[name="diluxone_users_admin_bar_keep_admins"]').uncheck({ force: true });
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_admin_bar_keep_admins'])).diluxone_users_admin_bar_keep_admins)).toBe(0);

			await admin.page.goto('/');
			await expect(admin.page.locator('#wpadminbar'), 'gone').toHaveCount(0);
		} finally {
			await admin.context.close();
		}
	});

	test('its “Edit profile” leads to the account area on the site and to the profile in the dashboard; unticked, the profile everywhere', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const person = await personSignedIn(browser, baseURL!, site, pages.login.url, 'bar-account');
		// "Edit profile" is the user-info item of the menu under the name.
		const editProfile = (p: Page) => p.locator('#wp-admin-bar-user-info a').first();

		try {
			await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
			await page.locator('input[name="diluxone_users_bar_account"]').check({ force: true });
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_bar_account'])).diluxone_users_bar_account)).toBe(1);

			await person.page.goto('/');
			expect(new URL((await editProfile(person.page).getAttribute('href'))!).pathname).toBe(new URL(pages.account.url).pathname);
			expect(new URL((await person.page.locator('#wp-admin-bar-my-account > a').getAttribute('href'))!).pathname, 'and the name').toBe(
				new URL(pages.account.url).pathname
			);

			await person.page.goto('/wp-admin/');
			expect(new URL((await editProfile(person.page).getAttribute('href'))!).pathname).toBe('/wp-admin/profile.php');

			await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
			await page.locator('input[name="diluxone_users_bar_account"]').uncheck({ force: true });
			await savePanel(page);

			await person.page.goto('/');
			expect(new URL((await editProfile(person.page).getAttribute('href'))!).pathname).toBe('/wp-admin/profile.php');
		} finally {
			await person.context.close();
		}
	});
});
