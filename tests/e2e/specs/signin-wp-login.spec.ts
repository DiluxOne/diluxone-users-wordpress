import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink, challengeCode, challengeScreen, fillCredentials, navigated, signInWithPassword } from '../support/ui';
import { MOCK_ON, Meta, dropSession, uploadImage } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * wp-login.php, both ways it meets the plugin.
 *
 * With no sign-in page it draws the second step itself (`auth-wp-login.php`),
 * and these walk what `coexistence.spec.ts` leaves out there: "send it again",
 * the lock, the trust box, "Or use:", an attempt that ran out, a link that was
 * spent and a challenge address with no attempt behind it. With a sign-in page
 * and the takeover on (`passwordless.php`), they walk every action WordPress
 * keeps or hands over: registration, the lost password, a protected post, the
 * dashboard's session-expired modal, a site with no page chosen, WordPress's
 * own registration closed on a link-only site, the profile guard, where "I
 * forgot my password" points, the hatch, and the emergency switch closing the
 * rest.
 */

const PASSWORD = 'e2e-WpLogin-Ways-1!';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Signs in on wp-login.php's own form and waits for where it lands. */
async function onWpLogin(page: Page, email: string, query = ''): Promise<void> {
	await page.goto(`/wp-login.php${query}`);
	await fillCredentials(page, email, PASSWORD);
	await navigated(page, () => page.locator('#wp-submit').click());
}

/** Presses a button of wp-login.php's second step and waits for the answer. */
async function pressOnChallenge(page: Page, button: string): Promise<void> {
	await navigated(page, () => challengeScreen(page).locator(button).click());
}

test.describe('wp-login.php’s own second step, past the plain code', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_page: null,
			diluxone_users_login_method: 'both',
			diluxone_users_wp_screens: 'auto',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['totp', 'email'],
			diluxone_users_2fa_remember_days: 0,
		});
	});

	test('“Send it again” waits, then sends a second code and says so in WordPress’s message box', async ({ page, site }) => {
		const email = freshEmail('wpl-resend');
		await site.makeUser({ email, password: PASSWORD });

		await onWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();
		await expect.poll(async () => (await site.mail(email)).length).toBe(1);

		await pressOnChallenge(page, 'button[name="diluxone_users_2fa_resend"]');
		expect(stateOf(page.url()), 'too soon: nothing claimed').toBe('');
		expect(await site.mail(email)).toHaveLength(1);

		await site.expire(email, 'resend');
		await pressOnChallenge(page, 'button[name="diluxone_users_2fa_resend"]');

		expect(stateOf(page.url())).toBe('sent');
		expect(new URL(page.url()).pathname).toMatch(/\/wp-login\.php$/);
		await expect(page.locator('#login-message, #login .message')).toBeVisible();
		await expect(page.locator('#login_error')).toHaveCount(0);

		const mails = await site.mail(email);
		expect(mails).toHaveLength(2);

		await challengeCode(page).fill(codeIn(mails[1]));
		await pressOnChallenge(page, 'input[type="submit"]');
		await expectSignedIn(page, email);
	});

	test('a locked account is told so on wp-login.php, in its error box, even with the right code', async ({ page, site, request }) => {
		const email = freshEmail('wpl-locked');
		await site.makeUser({ email, password: PASSWORD });

		await onWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();

		await new Meta(request).set(email, { diluxone_users_2fa_lock: Math.floor(Date.now() / 1000) + 300 });

		await challengeCode(page).fill(codeIn(await waitForMail(site, email)));
		await pressOnChallenge(page, 'input[type="submit"]');

		expect(stateOf(page.url())).toBe('locked');
		await expect(page.locator('#login_error')).toBeVisible();
		await expect(challengeScreen(page)).toBeVisible();
		await expectSignedOut(page);
	});

	test('the trust box on wp-login.php keeps this browser from being asked again', async ({ page, site, options }) => {
		await options.set({ diluxone_users_2fa_remember_days: 7 });

		const email = freshEmail('wpl-trust');
		await site.makeUser({ email, password: PASSWORD });

		await onWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();

		await challengeCode(page).fill(codeIn(await waitForMail(site, email)));
		await page.locator('#diluxone-users-2fa-trust').check();
		await pressOnChallenge(page, 'input[type="submit"]');
		await expectSignedIn(page, email);

		await dropSession(page.context());
		await expectSignedOut(page);

		await onWpLogin(page, email);
		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);
	});

	test('“Or use:” on wp-login.php switches from the app to the e-mail code', async ({ page, site }) => {
		const email = freshEmail('wpl-switch');
		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });

		await onWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();
		expect(new URL(page.url()).searchParams.get('diluxone_users_method')).toBe('totp');

		await navigated(page, () => challengeScreen(page).locator('a[href*="diluxone_users_method=email"]').click());
		expect(new URL(page.url()).searchParams.get('diluxone_users_method')).toBe('email');
		expect(new URL(page.url()).searchParams.get('action')).toBe('diluxone_users_2fa');

		await pressOnChallenge(page, 'button[name="diluxone_users_2fa_resend"]');
		expect(stateOf(page.url())).toBe('sent');

		await challengeCode(page).fill(codeIn(await waitForMail(site, email)));
		await pressOnChallenge(page, 'input[type="submit"]');
		await expectSignedIn(page, email);
	});

	test('an attempt that ran out lands on wp-login.php’s “retry”, said in its error box', async ({ page, site }) => {
		const email = freshEmail('wpl-retry');
		await site.makeUser({ email, password: PASSWORD });

		await onWpLogin(page, email);
		await expect(challengeScreen(page)).toBeVisible();
		const code = codeIn(await waitForMail(site, email));

		await site.expire(email, '2fa');
		await challengeCode(page).fill(code);
		await pressOnChallenge(page, 'input[type="submit"]');

		expect(new URL(page.url()).pathname).toMatch(/\/wp-login\.php$/);
		expect(stateOf(page.url())).toBe('retry');
		await expect(page.locator('#login_error')).toBeVisible();
		await expect(page.locator('form#loginform input[name="log"]'), 'the first step again').toBeVisible();
		await expectSignedOut(page);
	});

	test('the challenge address with no attempt behind it starts over at “retry”', async ({ page }) => {
		await page.goto('/wp-login.php?action=diluxone_users_2fa&diluxone_users_2fa=1&diluxone_users_key=nothing-here');

		expect(stateOf(page.url())).toBe('retry');
		await expect(challengeScreen(page)).toHaveCount(0);
		await expect(page.locator('#login_error')).toBeVisible();
	});

	test('a spent sign-in link, with no page to land on, is said on wp-login.php', async ({ page, site, pages, options }) => {
		// Asked while the page is there, opened once, then the page goes.
		await options.set({ diluxone_users_login_page: pages.login.id, diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('wpl-spent');
		await site.makeUser({ email, password: PASSWORD });
		await askForLink(page, pages.login.url, email);
		const link = linkIn(await waitForMail(site, email));

		await page.goto(link);
		await expectSignedIn(page, email);
		await page.context().clearCookies();

		await options.set({ diluxone_users_login_page: null });
		await page.goto(link);

		expect(new URL(page.url()).pathname).toMatch(/\/wp-login\.php$/);
		expect(stateOf(page.url())).toBe('expired');
		await expect(page.locator('#login_error')).toBeVisible();
		await expectSignedOut(page);
	});
});

test.describe('wp-login.php under the takeover', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_wp_screens: 'mine',
			diluxone_users_lost_password: 'wp',
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('action=register goes to the registration page while its form is open, and to the sign-in page when not', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_register_form: 1 });
		await page.goto('/wp-login.php?action=register');
		expect(page.url().startsWith(pages.register.url), 'the registration page').toBe(true);

		await options.set({ diluxone_users_register_form: 0 });
		await page.goto('/wp-login.php?action=register');
		expect(page.url().startsWith(pages.login.url), 'the sign-in page').toBe(true);
	});

	test('action=lostpassword stays on WordPress’s form while the reset is WordPress’s or the site’s, and goes to the page when nobody resets', async ({ page, pages, options }) => {
		for (const lost of ['wp', 'site']) {
			await options.set({ diluxone_users_lost_password: lost });
			await page.goto('/wp-login.php?action=lostpassword');

			expect(new URL(page.url()).pathname, lost).toMatch(/\/wp-login\.php$/);
			await expect(page.locator('#lostpasswordform'), lost).toBeVisible();
		}

		await options.set({ diluxone_users_lost_password: 'link' });
		await page.goto('/wp-login.php?action=lostpassword');
		expect(page.url().startsWith(pages.login.url)).toBe(true);
	});

	test('the dashboard’s “session expired” sign-in is left on wp-login.php', async ({ page }) => {
		await page.goto('/wp-login.php?interim-login=1');

		expect(new URL(page.url()).pathname).toMatch(/\/wp-login\.php$/);
		await expect(page.locator('form#loginform input[name="log"]')).toBeVisible();
	});

	test('a password-protected page still opens with its password', async ({ browser, baseURL, page, options }) => {
		const admin = await browser.newContext({ baseURL, storageState: ADMIN_STATE });
		let id = 0;

		try {
			const dash = await admin.newPage();
			await dash.goto('/wp-admin/');
			const nonce = await dash.evaluate(() => (window as any).wpApiSettings?.nonce ?? (window as any).wp?.apiFetch?.nonceMiddleware?.nonce);
			const made = await dash.request.post('/wp-json/wp/v2/pages', {
				headers: { 'X-WP-Nonce': nonce },
				data: { title: 'e2e protected', slug: 'e2e-signin-protected', status: 'publish', password: 'open-sesame', content: 'E2E-PROTECTED-CONTENT' },
			});
			expect(made.status(), await made.text()).toBe(201);
			const json = await made.json();
			id = json.id;

			await page.goto(json.link);
			await expect(page.locator('form.post-password-form')).toBeVisible();
			expect(await page.content()).not.toContain('E2E-PROTECTED-CONTENT');

			await page.locator('form.post-password-form input[name="post_password"]').fill('open-sesame');
			await navigated(page, () => page.locator('form.post-password-form input[type="submit"]').click());

			expect(page.url(), 'back on the page, not sent to the sign-in').toBe(json.link);
			await expect(page.locator('body')).toContainText('E2E-PROTECTED-CONTENT');
		} finally {
			if (id) {
				const dash = await admin.newPage();
				await dash.goto('/wp-admin/');
				const nonce = await dash.evaluate(() => (window as any).wpApiSettings?.nonce);
				await dash.request.delete(`/wp-json/wp/v2/pages/${id}?force=true`, { headers: { 'X-WP-Nonce': nonce } });
			}

			await admin.close();
		}
	});

	test('with no sign-in page chosen, wp-login.php is never closed: its own form signs people in', async ({ page, site, options }) => {
		await options.set({ diluxone_users_login_page: null, diluxone_users_login_method: 'link' });

		const email = freshEmail('wpl-nopage');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto('/wp-login.php');
		expect(new URL(page.url()).pathname, 'no redirect away, no loop').toMatch(/\/wp-login\.php$/);
		await expect(page.locator('form#loginform')).toBeVisible();

		await fillCredentials(page, email, PASSWORD);
		await navigated(page, () => page.locator('#wp-submit').click());
		await expectSignedIn(page, email);
	});

	test('on a link-only site WordPress’s own registration is closed, even with “Anyone can register” stored on', async ({ page, options }) => {
		await options.set({ users_can_register: 1, diluxone_users_login_method: 'link' });

		const closed = await page.request.get('/wp-login.php?action=register&diluxone-users-admin=1', { maxRedirects: 0 });
		expect(closed.status()).toBe(302);
		expect(new URL(closed.headers()['location']).searchParams.get('registration'), 'WordPress says registration is disabled').toBe('disabled');

		await page.goto('/wp-login.php?action=register&diluxone-users-admin=1');
		await expect(page.locator('#registerform')).toHaveCount(0);

		await options.set({ diluxone_users_login_method: 'both' });
		await page.goto('/wp-login.php?action=register&diluxone-users-admin=1');
		await expect(page.locator('#registerform'), 'and open again once a password is a way in').toBeVisible();
	});

	test('the profile guard on “block” refuses the dashboard profile, and only for the chosen roles', async ({ page, site, pages, options }) => {
		const email = freshEmail('wpl-guard');
		await site.makeUser({ email, password: PASSWORD, role: 'subscriber' });
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await options.set({ diluxone_users_wp_profile: 'block', diluxone_users_wp_profile_scope: 'all', diluxone_users_wp_profile_roles: [] });

		const blocked = await page.goto('/wp-admin/profile.php');
		expect(blocked?.status(), 'refused where it is').toBe(403);
		expect(new URL(page.url()).pathname).toMatch(/\/wp-admin\/profile\.php$/);

		await options.set({ diluxone_users_wp_profile_scope: 'some', diluxone_users_wp_profile_roles: ['editor'] });

		const open = await page.goto('/wp-admin/profile.php');
		expect(open?.status(), 'a subscriber is not one of the chosen roles').toBe(200);
		await expect(page.locator('#your-profile')).toBeVisible();
	});

	test('“I forgot my password” points at the sign-in page when nobody resets, and at WordPress’s form otherwise', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_lost_password: 'link' });
		await page.goto(pages.login.url);

		const links = await page.locator('a').evaluateAll((all) => all.map((a) => (a as HTMLAnchorElement).href));

		expect(links.some((href) => href.includes('action=lostpassword')), 'no link to WordPress’s reset').toBe(false);
		expect(links, 'the link points at the sign-in page itself').toContain(pages.login.url);

		await options.set({ diluxone_users_lost_password: 'wp' });
		await page.goto(pages.login.url);

		const again = await page.locator('a').evaluateAll((all) => all.map((a) => (a as HTMLAnchorElement).href));
		expect(again.some((href) => href.includes('action=lostpassword'))).toBe(true);
	});

	test('the hatch keeps a mailed reset on wp-login.php when the reset is the site’s', async ({ page, site, options }) => {
		await options.set({ diluxone_users_lost_password: 'site' });

		const email = freshEmail('wpl-hatch');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto('/wp-login.php?action=lostpassword');
		await page.locator('input[name="user_login"]').fill(email);
		await navigated(page, () => page.locator('#wp-submit').click());

		const link = linkIn(await waitForMail(site, email, { subject: /contrase|password/i }));

		await page.goto(`${link}&diluxone-users-admin=1`);
		expect(new URL(page.url()).pathname).toMatch(/\/wp-login\.php$/);
		await expect(page.locator('#resetpassform')).toBeVisible();
	});
});

test.describe('The emergency switch closes the rest', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_passkey_enabled: 1,
			diluxone_users_login_method: 'both',
			diluxone_users_lost_password: 'site',
			diluxone_users_wp_profile: 'block',
			diluxone_users_wp_profile_scope: 'all',
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('in safe mode: no social trip, no passkey dialogue, WordPress’s reset and WordPress’s profile', async ({ page, site, pages, options }) => {
		await page.goto(pages.login.url);
		const nonce = await page.evaluate(() => (window as any).diluxOneUsersPasskeys?.nonce as string);
		expect(nonce, 'the passkey dialogue is there before the switch').toBeTruthy();

		await options.set({ diluxone_e2e_safe_mode: 1 });

		const trip = await page.request.get('/sso/mock/?diluxone_users_go=1', { maxRedirects: 0, failOnStatusCode: false });
		expect(trip.headers()['location'] ?? '', 'no trip to the provider').not.toContain('/oauth/authorize');

		const ajax = await page.request.post('/wp-admin/admin-ajax.php', {
			form: { action: 'diluxone_users_passkeys', nonce, step: 'login-options' },
			failOnStatusCode: false,
		});
		expect(ajax.status(), 'the dialogue answers that there are no passkeys').toBe(400);

		const email = freshEmail('safe-reset');
		await site.makeUser({ email, password: PASSWORD });
		await page.goto('/wp-login.php?action=lostpassword');
		await page.locator('input[name="user_login"]').fill(email);
		await navigated(page, () => page.locator('#wp-submit').click());
		const link = linkIn(await waitForMail(site, email, { subject: /contrase|password/i }));

		await page.goto(link);
		expect(new URL(page.url()).pathname, 'the reset stays WordPress’s').toMatch(/\/wp-login\.php$/);
		await expect(page.locator('#resetpassform')).toBeVisible();

		await page.goto('/wp-login.php');
		await fillCredentials(page, email, PASSWORD);
		await navigated(page, () => page.locator('#wp-submit').click());
		await expectSignedIn(page, email);

		const profile = await page.goto('/wp-admin/profile.php');
		expect(profile?.status(), 'the profile guard is off too').toBe(200);
		await expect(page.locator('#your-profile')).toBeVisible();
	});
});

test.describe('wp-login.php in the site’s colours, and the pages a cache must not keep', () => {
	test('the brand draws the chosen colour, the chosen corners and the site’s logo', async ({ browser, baseURL, page, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({
				diluxone_users_wp_login_brand: 1,
				diluxone_users_wp_login_bg: '#7b2d8e',
				diluxone_users_style_radius: '12',
				diluxone_users_wp_login_logo: image.id,
			});

			await page.goto('/wp-login.php?diluxone-users-admin=1');

			expect(await page.locator('body').evaluate((body) => getComputedStyle(body).backgroundColor)).toBe('rgb(123, 45, 142)');
			expect(await page.locator('#loginform').evaluate((form) => getComputedStyle(form).borderTopLeftRadius)).toBe('12px');
			expect(await page.locator('#login h1 a').evaluate((a) => getComputedStyle(a).backgroundImage), 'the logo is the uploaded picture').toContain(
				new URL(image.url).pathname.replace(/\.png$/, '')
			);
		} finally {
			await image.forget();
		}
	});

	test('a page holding a private shortcode is kept out of caches even when it is no chosen page', async ({ page, site }) => {
		const loose = await site.page('signin-loose-register', '[diluxone_users_register]');

		try {
			const response = await page.goto(loose.url);

			expect(response?.headers()['cache-control'] ?? '').toContain('no-store');
			expect(response?.headers()['x-diluxone-e2e-donotcachepage'], 'DONOTCACHEPAGE was defined before the page was drawn').toBe('1');
		} finally {
			await site.forgetPage('signin-loose-register');
		}
	});
});
