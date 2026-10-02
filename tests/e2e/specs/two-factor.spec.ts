import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { codeIn, freshEmail, waitForMail } from '../support/api';
import { avoidWindowEdge, totp, totpPrevious, wrongCode } from '../support/totp';
import { accountSection, challengeCode, challengeScreen, navigated, notice, openPanel, signInWithPassword } from '../support/ui';

/**
 * The second step, and the two limits that make it worth having.
 *
 * A six-digit code is a million answers inside a ten-minute window, so what
 * this really tests is not that the right code works — that is arithmetic —
 * but that the wrong one runs out (five tries, DILUXONE_USERS_2FA_TRIES) and
 * that the "send it again" button is not a mail cannon aimed at somebody
 * else's inbox (sixty seconds, DILUXONE_USERS_2FA_RESEND_WAIT).
 *
 * The authenticator app is tested by being one: the secret is read off the
 * screen where a person would point their phone and the six digits are worked
 * out in Node. See tests/e2e/support/totp.ts.
 *
 * Covers T-2FA-04, and the halves of T-2FA-02 that a browser can reach.
 */

const PASSWORD = 'e2e-Second-Step-1!';

/** Signs somebody out without touching the cookie that remembers this browser. */
async function signOutKeepingTrust(page: any): Promise<void> {
	await page.context().clearCookies({ name: /^wordpress/ });
}

test.describe('The second step by e-mail', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_remember_days: 30,
		});
	});

	test('the password is not enough: a code arrives and finishes the job', async ({ page, site, pages }) => {
		const email = freshEmail('2fa');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);

		// Halfway: the session is NOT open yet, and the page says what is
		// missing instead of asking for the e-mail address all over again.
		await expect(challengeScreen(page)).toBeVisible();
		await expectSignedOut(page);
		expect(page.url()).toMatch(/diluxone_users_2fa=\d+/);

		const code = codeIn(await waitForMail(site, email, { subject: /c(o|ó)digo|code/i }));

		await challengeCode(page).fill(code);
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();

		await expectSignedIn(page, email);
	});

	test('the same code does not work twice', async ({ page, site, pages }) => {
		const email = freshEmail('2fareuse');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		const code = codeIn(await waitForMail(site, email));

		await challengeCode(page).fill(code);
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);

		// Start again and offer the code that already worked once.
		await signOutKeepingTrust(page);
		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		await challengeCode(page).fill(code);
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();

		await expect(notice(page, 'error')).toBeVisible();
		await expectSignedOut(page);
	});

	test('five wrong codes end the attempt — the limit does not reset on reload', async ({ page, site, pages }) => {
		const email = freshEmail('2falock');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		// Four wrong ones cost a try each and leave the attempt alive.
		for (let n = 0; n < 4; n++) {
			await challengeCode(page).fill('000000');
			await Promise.all([
				page.waitForURL(/diluxone-users=code/),
				page.locator('form.diluxone-users-form button[type="submit"]').first().click(),
			]);

			// The answer drawn before the reload: reloading a page whose
			// navigation is still being committed is a protocol error ("not
			// attached to an active page") and not a finding.
			await expect(challengeScreen(page)).toBeVisible();

			// Reloading the screen is not a way of starting the count over.
			await page.reload();
			await expect(challengeScreen(page)).toBeVisible();
		}

		// The fifth throws the whole attempt away: back to the first step.
		await challengeCode(page).fill('000000');
		await Promise.all([
			page.waitForURL(/diluxone-users=expired/),
			page.locator('form.diluxone-users-form button[type="submit"]').first().click(),
		]);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedOut(page);

		// And the code that WAS on its way is no use either: there is nothing
		// half-finished left for it to finish.
		const code = codeIn((await site.mail(email))[0]);
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		await challengeCode(page).fill(code);
		await navigated(page, () => page.locator('form.diluxone-users-form button[type="submit"]').first().click());
		await expectSignedOut(page);

		// Refused for being stale, not because the account is shut: six
		// wrong codes are under the account's limit, and the code this
		// attempt mailed gets in.
		const mails = await site.mail(email);
		const fresh = codeIn(mails[mails.length - 1]);
		expect(fresh).not.toBe(code);
		await challengeCode(page).fill(fresh);
		await navigated(page, () => page.locator('form.diluxone-users-form button[type="submit"]').first().click());
		await expectSignedIn(page, email);
	});

	test('“send it again” is not a mail cannon', async ({ page, site, pages }) => {
		const email = freshEmail('2fasend');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		expect(await site.mail(email), 'the first code went out with the challenge').toHaveLength(1);

		const resend = page.locator('button[name="diluxone_users_2fa_resend"]');

		// Straight away: nothing goes out, and nothing is claimed either — the
		// screen comes back as it was, with the code that is already on its way.
		await Promise.all([page.waitForURL(/diluxone_users_2fa=/), resend.click()]);
		expect(await site.mail(email), 'no second code inside the wait').toHaveLength(1);

		// Sixty seconds later, without waiting sixty seconds.
		await site.expire(email, 'resend');

		await Promise.all([page.waitForURL(/diluxone-users=sent/), resend.click()]);
		await expect(notice(page, 'ok')).toBeVisible();

		const sent = await site.mail(email);
		expect(sent).toHaveLength(2);

		// And it is the NEW code that works.
		await challengeCode(page).fill(codeIn(sent[1]));
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);
	});

	test('an attempt that timed out sends the person back to the beginning', async ({ page, site, pages }) => {
		const email = freshEmail('2fastale');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		const code = codeIn(await waitForMail(site, email));

		await site.expire(email, '2fa');

		await challengeCode(page).fill(code);
		await Promise.all([
			page.waitForURL(/diluxone-users=expired/),
			page.locator('form.diluxone-users-form button[type="submit"]').first().click(),
		]);

		await expectSignedOut(page);
	});

	test('the e-mail link does not ask for a code that goes to the same inbox', async ({ page, site, pages, options }) => {
		// 'auto' is the whole point of the setting: a code sent to the inbox
		// the person just opened to follow the link proves nothing new.
		await options.set({ diluxone_users_2fa_link: 'auto', diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('2falink');
		await site.makeUser({ email });

		await page.goto(pages.login.url);
		await page.locator('input[name="diluxone_users_email"]').fill(email);
		await page.locator('form.diluxone-users-form button[type="submit"]').click();
		await expect(page.locator('.diluxone-users-login__email')).toContainText(email);

		const mail = await waitForMail(site, email);
		const link = mail.body.match(/https?:\/\/\S+/)![0].replace(/[>).,]+$/, '');

		await page.goto(link);

		// Straight in, no second step.
		await expectSignedIn(page, email);
	});
});

test.describe('The second step with an authenticator app', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['totp', 'email'],
			diluxone_users_2fa_remember_days: 30,
		});
	});

	test('set the app up from the account screen, then sign in with it', async ({ page, site, pages }) => {
		const email = freshEmail('totp');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		// The screen where a person points their phone at a QR code. The test
		// is the phone: it reads the same secret out of the box beside it.
		await page.goto(accountSection(pages.account.url, 'security'));
		await openPanel(page, '.diluxone-users-totp__key');

		const secret = (await page.locator('.diluxone-users-totp__key').innerText()).replace(/\s+/g, '');
		expect(secret, 'a base32 secret').toMatch(/^[A-Z2-7]{16,}$/);

		await avoidWindowEdge();

		const setup = page.locator('form').filter({
			has: page.locator('input[name="diluxone_users_security"][value="totp"]'),
		});

		await setup.locator('input[name="diluxone_users_code"]').fill(totp(secret));
		await Promise.all([
			page.waitForURL(/diluxone-users=/),
			setup.locator('button[type="submit"]').click(),
		]);

		const saved = await site.user(email);
		expect(saved.meta.diluxone_users_totp, 'the app is registered').not.toBe('');
		expect(saved.meta.diluxone_users_2fa_on, 'and the second step came on with it').toBe('1');

		// Out, and back in: the password alone is no longer enough.
		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);

		await expect(challengeScreen(page)).toBeVisible();
		await expectSignedOut(page);

		// A wrong code is refused before a right one is accepted.
		await challengeCode(page).fill(wrongCode(secret));
		await Promise.all([
			page.waitForURL(/diluxone-users=code/),
			page.locator('form.diluxone-users-form button[type="submit"]').first().click(),
		]);
		await expect(notice(page, 'error')).toBeVisible();

		// The code that turned the app on is spent: the one of the step after
		// it — the next the phone shows, inside the window — gets in.
		await avoidWindowEdge();
		await challengeCode(page).fill(totp(secret, Date.now() + 30_000));
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();

		await expectSignedIn(page, email);
	});

	test('“do not ask again on this browser” means this browser is not asked again', async ({
		page,
		site,
		pages,
	}) => {
		const email = freshEmail('trust');
		const secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

		await site.makeUser({
			email,
			password: PASSWORD,
			meta: { diluxone_users_totp: secret, diluxone_users_2fa_on: 1 },
		});

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		await avoidWindowEdge();
		await challengeCode(page).fill(totp(secret));
		await page.locator('input[name="diluxone_users_2fa_trust"]').check();
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);

		// Out, but the browser keeps the cookie that says it already proved it.
		await signOutKeepingTrust(page);
		await expectSignedOut(page);

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);

		// Straight in: no second screen.
		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);
	});

	test('a backup code gets somebody in, and only once', async ({ page, site, pages }) => {
		const email = freshEmail('backup');
		const secret = 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU';

		// The app is there but the second step is NOT on yet: turning it on is
		// what makes the backup codes, and they are shown exactly once, which
		// is why they have to be read from the screen that makes them.
		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: secret } });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(accountSection(pages.account.url, 'security'));

		const panel = await openPanel(page, 'button[value="on"]');

		await Promise.all([
			page.waitForURL(/diluxone-users=on/),
			panel.locator('button[value="on"]').click(),
		]);

		const backup = (await page.locator('.diluxone-users-backup__list code').allInnerTexts()).map((one) =>
			one.trim()
		);

		expect(backup.length, 'a set of codes is handed over on the way in').toBeGreaterThan(0);

		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();

		// It goes in the same box as the app's code: that is the whole point of
		// a backup code — it is for when the app is not at hand.
		await challengeCode(page).fill(backup[0]);
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);

		// The same one a second time is not a code any more.
		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expect(challengeScreen(page)).toBeVisible();
		await challengeCode(page).fill(backup[0]);
		await Promise.all([
			page.waitForURL(/diluxone-users=code/),
			page.locator('form.diluxone-users-form button[type="submit"]').first().click(),
		]);
		await expectSignedOut(page);

		// And a different one still works: only the used one is gone.
		await challengeCode(page).fill(backup[1]);
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();
		await expectSignedIn(page, email);
	});
});
