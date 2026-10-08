import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink, challengeScreen, signInWithPassword } from '../support/ui';
import { Meta, answerChallenge } from '../support/signin';

/**
 * The second step off the plain path.
 *
 * `two-factor.spec.ts` proves a code arrives and a wrong one runs out. These
 * are the limits and rules around it: the account's own lock across attempts,
 * an attempt key nobody issued, the e-mail code's own life, a site whose only
 * method was Users+ Pro's app with Pro off, the link rule both ways, roles and
 * days Pro left behind read as nothing, and the two doors with no screen —
 * application passwords and XML-RPC — closed for whoever it applies to.
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

test.describe('The app, with Users+ Pro off', () => {
	test('a site whose only method was the app asks the e-mail code in its place, and somebody with only the app gets in', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['totp'],
		});

		const email = freshEmail('app-gone');

		// What Users+ Pro left: the app set up, the step on.
		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page), 'the step is still asked').toBeVisible();
		await expectSignedOut(page);
		expect(new URL(page.url()).searchParams.get('diluxone_users_method')).toBe('email');

		await answerChallenge(page, codeIn(await waitForMail(site, email)));
		await expectSignedIn(page, email);
	});
});

test.describe('Who is asked, and on which door', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_methods: ['email'],
		});
	});

	test('with the link rule on “never”, the e-mail link is never asked for the step', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_link: 'never' });

		const email = freshEmail('2fa-never');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_2fa_on: 1 } });
		await withLink(page, site, pages.login.url, email);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);

		// And the password still is: the rule belongs to the link alone.
		await page.context().clearCookies();
		await withPassword(page, pages.login.url, email);
		await expect(challengeScreen(page)).toBeVisible();
	});

	test('with the step off, somebody who turned it on goes straight in', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('2fa-off');

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_2fa_on: 1 } });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);
	});

	test('roles chosen while Users+ Pro was on are not read: required, it is asked of the subscriber too', async ({ page, site, pages, options }) => {
		// Users+ Pro's two settings, as it left them.
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_scope: 'some', diluxone_users_2fa_roles: ['editor'] });

		const subscriber = freshEmail('2fa-role-sub');

		await site.makeUser({ email: subscriber, password: PASSWORD, role: 'subscriber' });

		await withPassword(page, pages.login.url, subscriber);
		await expect(challengeScreen(page)).toBeVisible();
		await expectSignedOut(page);
	});

	test('there is no box to tick: without Users+ Pro no browser is remembered, whatever days were stored', async ({ page, site, pages, options }) => {
		// Users+ Pro's setting, as it left it.
		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_remember_days: 7 });

		const email = freshEmail('2fa-notrust');

		await site.makeUser({ email, password: PASSWORD });
		await withPassword(page, pages.login.url, email);

		await expect(challengeScreen(page)).toBeVisible();
		await expect(challengeScreen(page).locator('input[type="checkbox"]')).toHaveCount(0);
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
