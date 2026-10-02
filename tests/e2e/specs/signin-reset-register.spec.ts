import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { Site, freshEmail, linkIn, waitForMail } from '../support/api';
import { registerForm, registerScreen, resetScreen, signInWithPassword, submitPluginForm } from '../support/ui';

/**
 * Choosing a new password on the site's page, and the registration form, off
 * their plain paths.
 *
 * `password-reset.spec.ts` and `register.spec.ts` walk the forms that work.
 * These are the rest: a key nobody issued, a reset whose cookie is gone before
 * it is saved, the note that says a password is optional, a new password
 * ending the sessions the old one opened; and on registration the screen
 * after it, the screen for a site that makes accounts from the link, a form
 * posted after the site closed it, an account WordPress refuses, and the
 * site's own words on the form.
 */

const OLD = 'e2e-Reset-Old-1!';
const NEW = 'e2e-Reset-New-2!';

const PLAIN_FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

/** Asks WordPress's own form for a reset and opens the mailed link; lands on the site's reset screen. */
async function toResetScreen(page: Page, site: Site, email: string): Promise<void> {
	await page.goto('/wp-login.php?action=lostpassword');
	await page.locator('input[name="user_login"]').fill(email);
	await page.locator('#wp-submit').click();
	await page.goto(linkIn(await waitForMail(site, email, { subject: /contrase|password/i })));
	await expect(resetScreen(page)).toBeVisible();
}

/** Types the two passwords on the reset screen and saves. */
async function saveReset(page: Page, one: string, two = one): Promise<string> {
	await page.locator('input[name="diluxone_users_pass"]').fill(one);
	await page.locator('input[name="diluxone_users_pass2"]').fill(two);

	return submitPluginForm(page, resetScreen(page).locator('form.diluxone-users-form'));
}

test.describe('Choosing a new password on the site’s page', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_lost_password: 'site', diluxone_users_login_method: 'both', diluxone_users_2fa_mode: 'off' });
	});

	test('a key nobody issued, or one already spent, lands on “expired” with its notice', async ({ page, site, pages }) => {
		await page.goto(`${pages.login.url}?diluxone_users_key=made-up-key&diluxone_users_login=nobody`);

		expect(stateOf(page.url())).toBe('expired');
		expect(page.url(), 'the key is not left in the address').not.toContain('diluxone_users_key');
		await expect(page.locator('[data-diluxone-users-message="login_expired"]')).toHaveClass(/diluxone-users-notice--error/);
		await expect(resetScreen(page)).toHaveCount(0);

		const email = freshEmail('rst-spent');
		await site.makeUser({ email, password: OLD });
		await page.goto('/wp-login.php?action=lostpassword');
		await page.locator('input[name="user_login"]').fill(email);
		await page.locator('#wp-submit').click();
		const link = linkIn(await waitForMail(site, email, { subject: /contrase|password/i }));

		await page.goto(link);
		expect(await saveReset(page, NEW)).toBe('changed');

		await page.goto(link);
		expect(stateOf(page.url()), 'the spent key').toBe('expired');
		await expect(page.locator('[data-diluxone-users-message="login_expired"]')).toBeVisible();
	});

	test('a reset whose cookie is gone before it is saved lands on “expired”, and the old password still works', async ({ page, site, pages }) => {
		const email = freshEmail('rst-nocookie');
		await site.makeUser({ email, password: OLD });

		await toResetScreen(page, site, email);
		await page.context().clearCookies({ name: /^diluxone-users-reset-/ });

		expect(await saveReset(page, NEW)).toBe('expired');
		await expect(page.locator('[data-diluxone-users-message="login_expired"]')).toBeVisible();

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, OLD);
		await expectSignedIn(page, email);
	});

	test('the screen says a password is optional only where the e-mail link gets people in', async ({ page, site, options }) => {
		const email = freshEmail('rst-note');
		await site.makeUser({ email, password: OLD });

		await toResetScreen(page, site, email);
		await expect(resetScreen(page).locator('.diluxone-users-note--icon')).toBeVisible();

		await options.set({ diluxone_users_login_method: 'password' });
		await page.reload();
		await expect(resetScreen(page)).toBeVisible();
		await expect(resetScreen(page).locator('.diluxone-users-note--icon')).toHaveCount(0);
	});

	test('a new password ends every session the old one had open', async ({ browser, baseURL, page, site, pages }) => {
		const email = freshEmail('rst-sessions');
		await site.makeUser({ email, password: OLD });

		const elsewhere = await browser.newContext({ baseURL, storageState: undefined });

		try {
			const other = await elsewhere.newPage();
			await other.goto(pages.login.url);
			await signInWithPassword(other, email, OLD);
			await expectSignedIn(other, email);
			expect((await site.user(email)).sessions).toBe(1);

			await toResetScreen(page, site, email);
			expect(await saveReset(page, NEW)).toBe('changed');

			await expectSignedOut(other);
			expect((await site.user(email)).sessions).toBe(0);
		} finally {
			await elsewhere.close();
		}
	});
});

test.describe('The registration form, off the plain path', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_register_form: 1, diluxone_users_login_register: 1, diluxone_users_fields: PLAIN_FIELDS });
	});

	test('the screen after registering says what the site wrote for it', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_register_done: 'E2E your account is waiting' });

		const email = freshEmail('reg-done');

		await page.goto(pages.register.url);
		await registerForm(page).locator('input[name="diluxone_users_email"]').fill(email);
		expect(await submitPluginForm(page, registerForm(page))).toBe('registered');

		await expect(registerScreen(page).locator('.diluxone-users-login__title')).toHaveText('E2E your account is waiting');
		await expect(registerForm(page)).toHaveCount(0);
		await expect.poll(async () => (await site.mail(email)).length).toBe(1);
	});

	test('with the form closed but the link making accounts, the page sends people to sign in instead', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_register_form: 0, diluxone_users_login_register: 1 });

		await page.goto(pages.register.url);
		const byLink = registerScreen(page);

		await expect(byLink).toBeVisible();
		await expect(registerForm(page)).toHaveCount(0);
		await expect(byLink.locator('a.diluxone-users-button--soft')).toHaveAttribute('href', pages.login.url);
		const saying = await byLink.locator('.diluxone-users-login__title').innerText();

		// And it is not the closed screen: that one is for a site that takes
		// nobody, and says so.
		await options.set({ diluxone_users_login_register: 0 });
		await page.goto(pages.register.url);
		await expect(registerForm(page)).toHaveCount(0);
		expect(await registerScreen(page).locator('.diluxone-users-login__title').innerText()).not.toBe(saying);
	});

	test('a form posted after the site closed registration answers “closed”, and makes no account', async ({ page, site, pages, options }) => {
		const email = freshEmail('reg-closed');

		await page.goto(pages.register.url);
		await registerForm(page).locator('input[name="diluxone_users_email"]').fill(email);

		await options.set({ diluxone_users_register_form: 0, diluxone_users_login_register: 0 });

		expect(await submitPluginForm(page, registerForm(page))).toBe('closed');
		await expect(page.locator('[data-diluxone-users-message="register_closed"]')).toHaveClass(/diluxone-users-notice--error/);
		expect((await site.user(email)).exists).toBe(false);
		expect(await site.mail(email)).toEqual([]);
	});

	test('an account WordPress refuses to make answers “error”, and mails nothing', async ({ page, site, pages, options }) => {
		const email = freshEmail('reg-refused');

		await page.goto(pages.register.url);
		await registerForm(page).locator('input[name="diluxone_users_email"]').fill(email);

		await options.set({ diluxone_e2e_refuse_accounts: 1 });

		expect(await submitPluginForm(page, registerForm(page))).toBe('error');
		await expect(page.locator('[data-diluxone-users-message="register_error"]')).toHaveClass(/diluxone-users-notice--error/);
		expect((await site.user(email)).exists).toBe(false);
		expect(await site.mail(email)).toEqual([]);
	});

	test('the form carries the site’s heading, introduction and small print', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_register_title: 'E2E join us', diluxone_users_register_intro: 'E2E it takes a minute', diluxone_users_login_legal: 'E2E the terms' });

		await page.goto(pages.register.url);

		await expect(registerScreen(page).locator('.diluxone-users-login__title')).toHaveText('E2E join us');
		await expect(registerScreen(page).locator('.diluxone-users-login__intro')).toHaveText('E2E it takes a minute');
		await expect(registerScreen(page).locator('.diluxone-users-login__legal')).toHaveText('E2E the terms');
	});
});
