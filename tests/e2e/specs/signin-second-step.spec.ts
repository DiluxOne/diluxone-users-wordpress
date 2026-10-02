import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { avoidWindowEdge, totp } from '../support/totp';
import { accountSection, askForLink, challengeScreen, openPanel, signInWithPassword } from '../support/ui';
import { Meta, answerChallenge } from '../support/signin';

/**
 * The second step off the plain path.
 *
 * `two-factor.spec.ts` proves a code arrives and a wrong one runs out. These
 * are the limits and rules around it: the account's own lock across attempts,
 * a code of the app used twice, the code that turned the app on, an attempt
 * key nobody issued, the e-mail code's own life, the link rule both ways, the
 * roles the step is asked of, the methods the site offers, and the two doors
 * with no screen — application passwords and XML-RPC — closed for whoever it
 * applies to.
 */

const PASSWORD = 'e2e-Second-Ways-1!';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Signs in with a password from the sign-in page. */
async function withPassword(page: Page, url: string, email: string): Promise<void> {
	await page.goto(url);
	await signInWithPassword(page, email, PASSWORD);
	await page.waitForLoadState('domcontentloaded');
}

/** Signs in with the e-mail link and opens it. */
async function withLink(page: Page, site: any, url: string, email: string): Promise<void> {
	await askForLink(page, url, email);
	await page.goto(linkIn(await waitForMail(site, email, { subject: /./ })));
}

test.describe('The account’s own lock, across attempts', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_remember_days: 0,
		});
	});

	test('ten wrong codes lock the account: the right code is then refused as “locked”, and the wait grows', async ({ page, site, pages, request }) => {
		const email = freshEmail('2fa-lock');
		const meta = new Meta(request);

		await site.makeUser({ email, password: PASSWORD });

		// Two attempts of five wrong codes each: each attempt dies on its
		// fifth, and the account counts all ten.
		for (let attempt = 0; attempt < 2; attempt++) {
			await withPassword(page, pages.login.url, email);
			await expect(challengeScreen(page)).toBeVisible();

			for (let n = 0; n < 5; n++) {
				await answerChallenge(page, '000000');
			}

			expect(stateOf(page.url()), `attempt ${attempt + 1} ends back at the start`).toBe('expired');
		}

		const locked = await meta.get(email, ['diluxone_users_2fa_lock', 'diluxone_users_2fa_fails']);
		expect(Number(locked.diluxone_users_2fa_fails)).toBe(10);
		expect(Number(locked.diluxone_users_2fa_lock), 'locked for about five minutes').toBeGreaterThan(Date.now() / 1000 + 200);

		// A third attempt, with the code it mailed: right, and refused all the
		// same while the account waits — said as the lock, not as a wrong code.
		const before = (await site.mail(email)).length;
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();
		await expect.poll(async () => (await site.mail(email)).length).toBe(before + 1);

		const right = codeIn((await site.mail(email))[before]);

		await answerChallenge(page, right);
		expect(stateOf(page.url())).toBe('locked');
		await expect(page.locator('[data-diluxone-users-message="two_step_locked"]')).toHaveClass(/diluxone-users-notice--error/);
		await expect(challengeScreen(page), 'the attempt is still there').toBeVisible();
		await expectSignedOut(page);

		// The wait is over: one more try is let through. A wrong one locks
		// again, for twice as long.
		await meta.set(email, {}, ['diluxone_users_2fa_lock']);
		await answerChallenge(page, right === '000000' ? '111111' : '000000');
		expect(stateOf(page.url())).toBe('locked');

		const longer = Number((await meta.get(email, ['diluxone_users_2fa_lock'])).diluxone_users_2fa_lock);
		expect(longer, 'about ten minutes now').toBeGreaterThan(Date.now() / 1000 + 500);

		// Over again, and the right code opens it and wipes the count.
		await meta.set(email, {}, ['diluxone_users_2fa_lock']);
		await answerChallenge(page, right);
		await expectSignedIn(page, email);

		const after = await meta.get(email, ['diluxone_users_2fa_lock', 'diluxone_users_2fa_fails']);
		expect(after.diluxone_users_2fa_fails, 'the count starts from zero').toBe('');
		expect(after.diluxone_users_2fa_lock).toBe('');
	});

	test('the e-mail code runs out on its own, inside a live attempt', async ({ page, site, pages, request }) => {
		const email = freshEmail('2fa-codeold');

		await site.makeUser({ email, password: PASSWORD });
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();

		const code = codeIn(await waitForMail(site, email));

		await new Meta(request).set(email, {}, ['diluxone_users_2fa_email.expires']);
		await answerChallenge(page, code);

		expect(stateOf(page.url()), 'refused as a wrong code').toBe('code');
		await expect(page.locator('[data-diluxone-users-message="two_step_wrong"]')).toBeVisible();
		await expectSignedOut(page);
	});

	test('an attempt key nobody issued draws no second step, and a form posted with one starts over', async ({ page, site, pages }) => {
		const email = freshEmail('2fa-forged');

		await site.makeUser({ email, password: PASSWORD });
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();

		const real = new URL(page.url());
		const forged = new URL(real.href);
		forged.searchParams.set('diluxone_users_key', 'forged-key-forged-key-forged-key');

		await page.goto(forged.href);
		await expect(challengeScreen(page), 'no challenge for a key nobody issued').toHaveCount(0);
		await expect(page.locator('form#loginform'), 'the plain sign-in instead').toBeVisible();

		// The real screen again, with its hidden key changed before posting.
		await page.goto(real.href);
		await expect(challengeScreen(page)).toBeVisible();
		await page.locator('input[name="diluxone_users_2fa_key"]').evaluate((input: HTMLInputElement) => {
			input.value = 'forged-key-forged-key-forged-key';
		});
		await answerChallenge(page, codeIn(await waitForMail(site, email)));

		expect(stateOf(page.url())).toBe('expired');
		await expectSignedOut(page);
	});
});

test.describe('The authenticator app’s codes', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['totp', 'email'],
			diluxone_users_2fa_remember_days: 30,
		});
	});

	test('a code of the app that already opened a session is refused the second time; the next one gets in', async ({ page, site, pages }) => {
		const email = freshEmail('totp-replay');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });

		await avoidWindowEdge(20_000);
		const code = totp(SECRET);

		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();
		await answerChallenge(page, code);
		await expectSignedIn(page, email);

		await page.context().clearCookies();
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();
		await answerChallenge(page, code);

		expect(stateOf(page.url()), 'the same six digits, inside the same thirty seconds').toBe('code');
		await expectSignedOut(page);

		await answerChallenge(page, totp(SECRET, Date.now() + 30_000));
		await expectSignedIn(page, email);
	});

	test('the code that turned the app on is spent: it is not the first sign-in code', async ({ page, site, pages }) => {
		const email = freshEmail('totp-activation');

		await site.makeUser({ email, password: PASSWORD });
		await withPassword(page, pages.login.url, email);
		await expectSignedIn(page, email);

		await page.goto(accountSection(pages.account.url, 'security'));
		await openPanel(page, '.diluxone-users-totp__key');
		const secret = (await page.locator('.diluxone-users-totp__key').innerText()).replace(/\s+/g, '');

		await avoidWindowEdge(20_000);
		const activation = totp(secret);

		const setup = page.locator('form').filter({ has: page.locator('input[name="diluxone_users_security"][value="totp"]') });
		await setup.locator('input[name="diluxone_users_code"]').fill(activation);
		await Promise.all([page.waitForURL(/diluxone-users=/), setup.locator('button[type="submit"]').click()]);
		expect((await site.user(email)).meta.diluxone_users_totp, 'the app is on').not.toBe('');

		await page.context().clearCookies();
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();
		await answerChallenge(page, activation);

		expect(stateOf(page.url()), 'whoever watched it typed cannot sign in with it').toBe('code');
		await expectSignedOut(page);
	});

	test('“Or use:” switches the attempt to the e-mail code, which arrives and gets in', async ({ page, site, pages }) => {
		const email = freshEmail('2fa-switch');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page)).toBeVisible();
		expect(new URL(page.url()).searchParams.get('diluxone_users_method'), 'the app first').toBe('totp');
		expect(await site.mail(email), 'the app sends nothing').toEqual([]);
		await expect(challengeScreen(page).locator('button[name="diluxone_users_2fa_resend"]'), 'nothing to send again for the app').toHaveCount(0);

		const other = challengeScreen(page).locator(`a[href*="diluxone_users_method=email"]`);
		await other.click();
		await page.waitForURL(/diluxone_users_method=email/);

		// On the e-mail method the code goes out when asked for.
		await page.locator('button[name="diluxone_users_2fa_resend"]').click();
		await page.waitForURL(/diluxone-users=sent/);
		await expect(page.locator('[data-diluxone-users-message="two_step_sent"]')).toHaveClass(/diluxone-users-notice--ok/);

		await answerChallenge(page, codeIn(await waitForMail(site, email)));
		await expectSignedIn(page, email);
	});

	test('with the app the only method the site offers, nothing is mailed and nothing else is offered', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['totp'] });

		const email = freshEmail('2fa-totponly');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page)).toBeVisible();
		await expect(challengeScreen(page).locator('button[name="diluxone_users_2fa_resend"]')).toHaveCount(0);
		await expect(challengeScreen(page).locator('a[href*="diluxone_users_method="]'), 'no “Or use:”').toHaveCount(0);
		expect(await site.mail(email)).toEqual([]);

		await avoidWindowEdge();
		await answerChallenge(page, totp(SECRET));
		await expectSignedIn(page, email);
	});
});

test.describe('Who is asked, and on which door', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_methods: ['totp', 'email'],
			diluxone_users_2fa_remember_days: 0,
		});
	});

	test('with the link rule on “never”, the e-mail link is never asked for the step, app or not', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_link: 'never' });

		const email = freshEmail('2fa-never');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });
		await withLink(page, site, pages.login.url, email);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);

		// And the password still is: the rule belongs to the link alone.
		await page.context().clearCookies();
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();
	});

	test('on “auto”, the e-mail link of somebody with the app asks for the app, and mails no code', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_link: 'auto' });

		const email = freshEmail('2fa-auto-app');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });
		await withLink(page, site, pages.login.url, email);

		await expect(challengeScreen(page)).toBeVisible();
		expect(new URL(page.url()).searchParams.get('diluxone_users_method')).toBe('totp');
		await expectSignedOut(page);
		expect((await site.mail(email)).length, 'the link and nothing after it').toBe(1);

		await avoidWindowEdge();
		await answerChallenge(page, totp(SECRET));
		await expectSignedIn(page, email);
	});

	test('with the step off, somebody who turned it on goes straight in', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('2fa-off');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);
	});

	test('required for chosen roles only: the editor is asked, the subscriber is not', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_scope: 'some', diluxone_users_2fa_roles: ['editor'] });

		const subscriber = freshEmail('2fa-role-sub');
		const editor = freshEmail('2fa-role-ed');

		await site.makeUser({ email: subscriber, password: PASSWORD, role: 'subscriber' });
		await site.makeUser({ email: editor, password: PASSWORD, role: 'editor' });

		await withPassword(page, pages.login.url, subscriber);
		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, subscriber);

		await page.context().clearCookies();
		await withPassword(page, pages.login.url, editor);
		await expect(challengeScreen(page)).toBeVisible();
		await expectSignedOut(page);
	});

	test('the trust box is not offered when the site remembers no browser', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_remember_days: 0 });

		const email = freshEmail('2fa-notrust');

		await site.makeUser({ email, password: PASSWORD });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page)).toBeVisible();
		await expect(page.locator('input[name="diluxone_users_2fa_trust"]')).toHaveCount(0);

		await options.set({ diluxone_users_2fa_remember_days: 7 });
		await page.reload();
		await expect(page.locator('input[name="diluxone_users_2fa_trust"]'), 'and is, once it does').toHaveCount(1);
	});

	test('the code mail is the site’s own rewrite when there is one, and its code works', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_mail_templates: {
				es_AR: { second_step: { subject: 'E2E code for {site}', body: 'E2E says your code is {code}, for {minutes} minutes.' } },
				en_US: { second_step: { subject: 'E2E code for {site}', body: 'E2E says your code is {code}, for {minutes} minutes.' } },
			},
		});

		const email = freshEmail('2fa-template');

		await site.makeUser({ email, password: PASSWORD });
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();

		const mail = await waitForMail(site, email);
		expect(mail.subject).toMatch(/^E2E code for /);
		expect(mail.body).toMatch(/^E2E says your code is \d{6}, for 10 minutes\.$/);

		await answerChallenge(page, codeIn(mail));
		await expectSignedIn(page, email);
	});
});

test.describe('The doors with no screen, when the second step applies', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_remember_days: 0,
		});
	});

	/** wp.getProfile over XML-RPC with a username and a password. */
	async function xmlrpcProfile(page: Page, user: string, pass: string): Promise<string> {
		const body = `<?xml version="1.0"?><methodCall><methodName>wp.getProfile</methodName><params><param><value><int>1</int></value></param><param><value><string>${user}</string></value></param><param><value><string>${pass}</string></value></param></params></methodCall>`;
		const response = await page.request.post('/xmlrpc.php', { data: body, headers: { 'Content-Type': 'text/xml' } });

		return response.text();
	}

	test('an application password made before the step applied stops working once it does', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('2fa-apppass');
		const person = await site.makeUser({ email, password: PASSWORD });

		await withPassword(page, pages.login.url, email);
		await expectSignedIn(page, email);

		await page.goto('/wp-admin/profile.php');
		await expect(page.locator('#application-passwords-section'), 'offered while the step is off').toBeVisible();

		const nonce = await page.evaluate(() => (window as any).wpApiSettings?.nonce as string);
		const made = await page.request.post(`/wp-json/wp/v2/users/${person.id}/application-passwords`, {
			headers: { 'X-WP-Nonce': nonce },
			data: { name: 'e2e script' },
		});
		expect(made.status(), await made.text()).toBe(201);

		const secret = (await made.json()).password as string;
		const basic = `Basic ${Buffer.from(`${email}:${secret}`).toString('base64')}`;

		const stranger = await page.context().browser()!.newContext({ baseURL: new URL(page.url()).origin });

		try {
			const open = await stranger.request.get('/wp-json/wp/v2/users/me?context=edit', { headers: { Authorization: basic } });
			expect(open.status(), 'the script gets in while nothing asks for a code').toBe(200);

			await options.set({ diluxone_users_2fa_mode: 'required' });

			const shut = await stranger.request.get('/wp-json/wp/v2/users/me?context=edit', { headers: { Authorization: basic } });
			expect(shut.status(), 'the same password, refused once a code is asked for').toBe(401);

			await page.goto('/wp-admin/profile.php');
			await expect(page.locator('#application-passwords-section'), 'and the profile no longer offers them').toHaveCount(0);
		} finally {
			await stranger.close();
		}
	});

	test('XML-RPC answers a right password while the step is off, and refuses it while it applies', async ({ page, site, options }) => {
		const email = freshEmail('2fa-xmlrpc');

		await site.makeUser({ email, password: PASSWORD });

		await options.set({ diluxone_users_2fa_mode: 'off' });
		const open = await xmlrpcProfile(page, email, PASSWORD);
		expect(open, 'a profile comes back').toContain(email);
		expect(open).not.toContain('<fault>');

		await options.set({ diluxone_users_2fa_mode: 'required' });
		const shut = await xmlrpcProfile(page, email, PASSWORD);
		expect(shut, 'a fault, not the profile').toContain('<fault>');
		expect(shut).not.toContain(email);

		// A wrong password still says it is wrong: only a right one meets the gate.
		const wrong = await xmlrpcProfile(page, email, 'not-the-password');
		expect(wrong).toContain('<fault>');
	});
});
