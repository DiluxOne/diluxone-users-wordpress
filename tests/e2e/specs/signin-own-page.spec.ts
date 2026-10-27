import { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { adminUrl, challengeScreen, registerScreen, resetScreen, savePanel, signInWithPassword } from '../support/ui';
import { answerChallenge } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The shape "A page of its own": the plugin answers an address of the site's
 * choosing and draws the whole document, with no header or footer of the
 * theme's. The address, its old ones, and the screens drawn on it.
 */

// The dashboard in `page`; everything a visitor sees in `guest`.
test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Own-Page-1!';
const NEW_PASSWORD = 'e2e-Own-Page-New-2!';

/** On the page of its own, and nowhere else. */
async function onTheDoor(page: Page, path = '/sign-in/'): Promise<void> {
	expect(new URL(page.url()).pathname).toBe(path);
	await expect(page.locator('body.diluxone-users-door')).toHaveCount(1);
	await expect(page.locator('header, footer, .wp-block-template-part')).toHaveCount(0);
}

const SHAPE_KEYS = ['diluxone_users_login_template', 'diluxone_users_login_page', 'diluxone_users_login_slug', 'diluxone_users_login_slug_old'];

test.describe('Sign in › A page of its own', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(SHAPE_KEYS);
		// "No page" on Access, so the page of its own is at the address typed
		// beside it: /sign-in/. The cases about a page chosen choose it.
		await options.set({
			diluxone_users_login_template: 'own',
			diluxone_users_login_page: 0,
			diluxone_users_login_slug: 'sign-in',
			diluxone_users_login_slug_old: [],
		});
	});

	test('C1: the page chosen on Access draws the page of its own at its own address, with no redirect', async ({ guest, options, pages }) => {
		await options.set({ diluxone_users_login_page: pages.login.id });

		const direct = await guest.request.get(pages.login.url, { maxRedirects: 0 });

		expect(direct.status(), 'answered there, not sent anywhere').toBe(200);

		const response = await guest.goto(pages.login.url);

		expect(response?.status()).toBe(200);
		expect(response?.request().redirectedFrom(), 'nothing redirects').toBeNull();
		expect(guest.url()).toBe(pages.login.url);
		await expect(guest.locator('body.diluxone-users-door'), 'the page of its own, drawn there').toHaveCount(1);
		await expect(guest.locator('.diluxone-users-login-frame--own .diluxone-users-login')).toBeVisible();
		await expect(guest.locator('header, footer, .wp-block-template-part'), 'nothing of the theme’s').toHaveCount(0);
	});

	test('C1: /sign-in/ draws the form on a page with no theme header or footer', async ({ guest }) => {
		const response = await guest.goto('/sign-in/');

		expect(response?.status(), 'answered, not a 404').toBe(200);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(guest.locator('.diluxone-users-login-frame--own .diluxone-users-login')).toBeVisible();
		await expect(guest.locator('.diluxone-users-door__site'), 'the site’s name, where there is no mark').toBeVisible();
		await expect(guest.locator('header, footer, .wp-block-template-part'), 'nothing of the theme’s').toHaveCount(0);
		await expect(guest).toHaveTitle(/^Sign in/);
		expect(response?.headers()['cache-control'] ?? '', 'kept out of every cache').toContain('no-store');
	});

	test('C2: the account area leads to /sign-in/', async ({ guest, pages, baseURL }) => {
		const door = `${baseURL}/sign-in/`;

		await guest.goto(pages.account.url);
		const link = guest.locator(`a[href^="${door}?"]`).first();
		await expect(link, 'the account area, to a stranger, links to the page of its own').toBeVisible();
		const back = new URL((await link.getAttribute('href')) ?? '', baseURL).searchParams.get('redirect_to') ?? '';
		expect(new URL(back, baseURL).pathname, 'and back to the account area once signed in').toBe(new URL(pages.account.url, baseURL).pathname);
	});

	test('C3: the address changed from sign-in to entrar on Access, /sign-in/ redirects to /entrar/', async ({ page, guest, baseURL }) => {
		await page.goto(adminUrl('diluxone-users-login', 'page'));
		await page.locator('input[name="diluxone_users_login_slug"]').fill('entrar');
		await savePanel(page);
		await expect(page.locator('input[name="diluxone_users_login_slug"]')).toHaveValue('entrar');

		const moved = await guest.request.get(`${baseURL}/sign-in/?diluxone-users=expired`, { maxRedirects: 0 });

		expect(moved.status(), 'a redirect no browser keeps').toBe(302);
		expect(moved.headers()['cache-control'], 'kept by no cache').toContain('no-store');
		expect(moved.headers().location, 'with what the address carried').toBe(`${baseURL}/entrar/?diluxone-users=expired`);

		await guest.goto('/sign-in/');
		expect(guest.url()).toBe(`${baseURL}/entrar/`);
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
	});

	test('C5: the second step is drawn on /sign-in/, and finishing it signs in', async ({ guest, site, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('own-2fa');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto('/sign-in/');
		await signInWithPassword(guest, email, PASSWORD);
		await guest.waitForLoadState('domcontentloaded');

		await expect(challengeScreen(guest)).toBeVisible();
		await onTheDoor(guest);

		await answerChallenge(guest, codeIn(await waitForMail(site, email, { subject: /./ })));
		await expectSignedIn(guest, email);
	});

	test('C5: a password reset lands on /sign-in/ and the new password is typed there', async ({ guest, site, options }) => {
		await options.set({ diluxone_users_lost_password: 'site', diluxone_users_login_method: 'both' });

		const email = freshEmail('own-reset');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto('/wp-login.php?action=lostpassword');
		await guest.locator('input[name="user_login"]').fill(email);
		await guest.locator('#wp-submit').click();
		await guest.goto(linkIn(await waitForMail(site, email, { subject: /contrase|password/i })));

		await expect(resetScreen(guest)).toBeVisible();
		await onTheDoor(guest);
		expect(guest.url(), 'the key is not left in the address').not.toContain('diluxone_users_key');

		await guest.locator('input[name="diluxone_users_pass"]').fill(NEW_PASSWORD);
		await guest.locator('input[name="diluxone_users_pass2"]').fill(NEW_PASSWORD);
		await Promise.all([guest.waitForURL(/diluxone-users=changed/), guest.locator('form.diluxone-users-form button[type="submit"]').click()]);
		await onTheDoor(guest);

		await signInWithPassword(guest, email, NEW_PASSWORD);
		await expectSignedIn(guest, email);
	});

	test('C5: registration is at /sign-in/register/, on the same document', async ({ guest, options }) => {
		await options.set({ diluxone_users_register_form: 1 });

		const response = await guest.goto('/sign-in/register/');

		expect(response?.status()).toBe(200);
		await expect(registerScreen(guest)).toBeVisible();
		await onTheDoor(guest, '/sign-in/register/');
		await expect(guest).toHaveTitle(/^Create your account/);
	});
});
