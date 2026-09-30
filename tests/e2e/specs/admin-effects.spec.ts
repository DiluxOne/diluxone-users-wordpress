import { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, adminUrl, openWay, passwordForm, savePanel, signInWithPassword, ssoButton } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * A setting saved on a dashboard screen, and what it changes where people are.
 *
 * admin-settings.spec.ts covers the first eight of these; this file is the
 * rest of the screens. Each test presses Save the way an administrator does —
 * `options.keep()` writes the old values down beforehand, since nothing can
 * intercept that write — and then looks at the public side in the `guest`
 * browser, or as a subscriber signed in there.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Effects-1!';

/** A subscriber signed in in the guest browser. */
async function subscriber(guest: Page, site: import('../support/api').Site, loginUrl: string, prefix: string) {
	const email = freshEmail(prefix);

	await site.makeUser({ email, password: PASSWORD });
	await guest.goto(loginUrl);
	await signInWithPassword(guest, email, PASSWORD);
	await expectSignedIn(guest, email);

	return email;
}

/** A tick box on a panel, ticked or not, whatever widget draws it. */
async function tick(page: Page, name: string, on: boolean): Promise<void> {
	const box = page.locator(`input[type="checkbox"][name="${name}"]`);

	await box.setChecked(on, { force: true });
}

test.describe('Design › WordPress’s own screens', () => {
	test('the brand on wp-login.php: the site’s name, its address and its colour', async ({ page, guest, options }) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);
		await options.set({ diluxone_users_wp_login_brand: 0 });

		await guest.goto('/wp-login.php?diluxone-users-admin=1');

		const plain = await guest.locator('body').evaluate((body) => getComputedStyle(body).backgroundColor);

		await page.goto(adminUrl('diluxone-users-design', 'wp'));
		await tick(page, 'diluxone_users_wp_login_brand', true);
		await savePanel(page);

		await guest.goto('/wp-login.php?diluxone-users-admin=1');

		const home = new URL('/', guest.url()).toString();

		await expect(guest.locator('#login h1 a')).toHaveAttribute('href', home);
		expect(
			await guest.locator('body').evaluate((body) => getComputedStyle(body).backgroundColor),
			'the background is the site’s colour, not WordPress’s grey'
		).not.toBe(plain);
	});
});

test.describe('Design › Profile photo', () => {
	test('photos switched off: the account has no upload form', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_avatar_upload']);
		await options.set({ diluxone_users_avatar_upload: 1 });

		await subscriber(guest, site, pages.login.url, 'nophoto');
		await guest.goto(accountSection(pages.account.url, 'details'));
		await expect(guest.locator('form.diluxone-users-avatar__form')).toHaveCount(1);

		await page.goto(adminUrl('diluxone-users-design', 'photo'));
		await tick(page, 'diluxone_users_avatar_upload', false);
		await savePanel(page);

		await guest.goto(accountSection(pages.account.url, 'details'));
		await expect(guest.locator('form.diluxone-users-avatar__form')).toHaveCount(0);
	});

	test('no Gravatar and initials on: the picture is drawn here, and nothing is asked of gravatar.com', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_avatar_gravatar', 'diluxone_users_avatar_initials', 'diluxone_users_avatar_upload']);
		await options.set({ diluxone_users_avatar_upload: 1 });

		await page.goto(adminUrl('diluxone-users-design', 'photo'));
		await tick(page, 'diluxone_users_avatar_gravatar', false);
		await tick(page, 'diluxone_users_avatar_initials', true);
		await savePanel(page);

		await subscriber(guest, site, pages.login.url, 'initials');
		await guest.goto(accountSection(pages.account.url, 'details'));

		const src = await guest.locator('.diluxone-users-avatar__current img').getAttribute('src');

		expect(src, 'an initials picture made on the site').toMatch(/^data:image\/svg/);
		expect(src).not.toContain('gravatar.com');
	});
});

test.describe('Design › The registration page', () => {
	test('the heading saved on the screen is the one the page shows', async ({ page, guest, pages, options }) => {
		await options.keep(['diluxone_users_register_title']);
		await options.set({ diluxone_users_register_form: 1 });

		const title = `Join us ${Date.now().toString(36)}`;

		await page.goto(adminUrl('diluxone-users-design', 'register'));
		await page.locator('[name="diluxone_users_register_title"]').fill(title);
		await savePanel(page);

		await guest.goto(pages.register.url);
		await expect(guest.locator('.diluxone-users-register')).toContainText(title);
	});
});

test.describe('Design › The account area', () => {
	test('“a menu down the side” draws the menu as a column', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_account_layout']);
		await options.set({ diluxone_users_account_layout: 'tabs' });

		await subscriber(guest, site, pages.login.url, 'layout');
		await guest.goto(pages.account.url);
		await expect(guest.locator('.diluxone-users-account__nav')).toHaveClass(/--row/);

		await page.goto(adminUrl('diluxone-users-design', 'account'));
		await page.locator('[name="diluxone_users_account_layout"][value="side"]').check({ force: true });
		await savePanel(page);

		await guest.goto(pages.account.url);
		await expect(guest.locator('.diluxone-users-account__nav')).toHaveClass(/--column/);
	});
});

test.describe('Account area › The WordPress dashboard', () => {
	test('the toolbar hidden for everybody is gone from the site for a subscriber', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_admin_bar', 'diluxone_users_admin_bar_scope', 'diluxone_users_admin_bar_roles']);
		await options.set({ diluxone_users_admin_bar: 'wp' });

		await subscriber(guest, site, pages.login.url, 'bar');
		await guest.goto('/');
		await expect(guest.locator('#wpadminbar')).toHaveCount(1);

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await page.locator('[name="diluxone_users_admin_bar"][value="hide-all"]').check({ force: true });
		await savePanel(page);

		await guest.goto('/');
		await expect(guest.locator('#wpadminbar')).toHaveCount(0);
	});

	test('the profile screen sent to the account area: a subscriber who opens it lands there', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_wp_profile', 'diluxone_users_wp_profile_scope', 'diluxone_users_wp_profile_roles']);

		// Signed in first: the helper that says "signed in" asks profile.php,
		// which is the very screen this setting moves.
		await subscriber(guest, site, pages.login.url, 'profile');

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));
		await page.locator('[name="diluxone_users_wp_profile"][value="redirect"]').check({ force: true });
		await savePanel(page);

		await guest.goto('/wp-admin/profile.php');

		// To the details section: the profile screen is where a person edits their details.
		expect(new URL(guest.url()).pathname).toBe(new URL(accountSection(pages.account.url, 'details')).pathname);

		// An administrator is not sent anywhere: they manage people from there.
		await page.goto('/wp-admin/profile.php');
		expect(new URL(page.url()).pathname).toBe('/wp-admin/profile.php');
	});
});

test.describe('Security › Sessions', () => {
	test('the list of browsers switched off leaves the account’s security page without it', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_sessions_show']);
		await options.set({ diluxone_users_sessions_show: 1 });

		await subscriber(guest, site, pages.login.url, 'sessionslist');
		await guest.goto(accountSection(pages.account.url, 'security'));
		await expect(guest.locator('.diluxone-users-sessions')).toHaveCount(1);

		await page.goto(adminUrl('diluxone-users-security', 'sessions'));
		await tick(page, 'diluxone_users_sessions_show', false);
		await savePanel(page);

		await guest.goto(accountSection(pages.account.url, 'security'));
		await expect(guest.locator('.diluxone-users-sessions')).toHaveCount(0);
	});
});

test.describe('Notifications › Rules', () => {
	test('“never” takes the switch off the account; “on unless they turn it off” puts it back, ticked', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_notice_rules']);

		const rule = page.locator('select[name="diluxone_users_notice_rules[diluxone_users_notify_login]"]');

		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await rule.selectOption('never');
		await savePanel(page);

		await subscriber(guest, site, pages.login.url, 'rules');
		await guest.goto(accountSection(pages.account.url, 'notifications'));
		await expect(guest.locator('input[name="diluxone_users_notify_login"]')).toHaveCount(0);

		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await rule.selectOption('default_on');
		await savePanel(page);

		await guest.goto(accountSection(pages.account.url, 'notifications'));
		await expect(guest.locator('input[name="diluxone_users_notify_login"]')).toBeChecked();
	});
});

test.describe('Social › Providers', () => {
	test('switching a network off from the list takes its button off the sign-in page', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
		});

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toBeVisible();

		await page.goto(adminUrl('diluxone-users-social', 'providers'));

		const off = page.locator('a[href*="diluxone_users_action=off"][href*="red=mock"]');

		await expect(off).toHaveCount(1);
		await Promise.all([page.waitForLoadState('domcontentloaded'), off.click()]);

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toHaveCount(0);
	});
});

test.describe('Access › The sign-in page', () => {
	test('“WordPress’s own screens” leaves wp-login.php drawing its form while a password is a way in', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_wp_screens']);
		await options.set({ diluxone_users_login_method: 'both' });

		await page.goto(adminUrl('diluxone-users-login', 'page'));
		await page.locator('[name="diluxone_users_wp_screens"][value="wp"]').check({ force: true });
		await savePanel(page);

		await guest.goto('/wp-login.php');
		expect(new URL(guest.url()).pathname).toBe('/wp-login.php');
		await expect(guest.locator('#loginform')).toBeVisible();
	});

	/*
	 * Its form takes a password and nothing else, so on a site with no
	 * password the second door is the one place a password would still open.
	 * The screen does not offer it, shows the answer in force, and the site
	 * does what the screen says.
	 */
	test('with no password, wp-login.php is no second door: the screen and the site agree', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_wp_screens']);
		await options.set({ diluxone_users_login_method: 'link', diluxone_users_wp_screens: 'wp' });

		await page.goto(adminUrl('diluxone-users-login', 'page'));
		await expect(page.locator('[name="diluxone_users_wp_screens"][value="wp"]')).toBeDisabled();
		await expect(page.locator('[name="diluxone_users_wp_screens"][value="auto"]')).toBeChecked();

		await guest.goto('/wp-login.php');
		expect(new URL(guest.url()).pathname).toBe(new URL(pages.login.url).pathname);
	});

	test('“the site’s own page” sends wp-login.php there, even with the password on', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_wp_screens']);
		await options.set({ diluxone_users_login_method: 'both' });

		await page.goto(adminUrl('diluxone-users-login', 'page'));
		await page.locator('[name="diluxone_users_wp_screens"][value="mine"]').check({ force: true });
		await savePanel(page);

		await guest.goto('/wp-login.php');
		expect(new URL(guest.url()).pathname).toBe(new URL(pages.login.url).pathname);

		await openWay(guest, 'password');
		await expect(passwordForm(guest)).toBeVisible();
	});
});
