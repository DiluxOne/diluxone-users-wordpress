import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import {
	askForLink,
	challengeScreen,
	emailField,
	linkForm,
	openWay,
	passwordForm,
	registerScreen,
	resetScreen,
	sentScreen,
	signInWithPassword,
	submitPluginForm,
} from '../support/ui';
import { MOCK_ON, uploadImage } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The sign-in page itself: what it draws for whom, every message it can say,
 * the arrangement of the ways in, and the e-mail link's limits that only show
 * from the outside.
 *
 * `magic-link.spec.ts`, `login-ways.spec.ts` and `login-screen.spec.ts` walk
 * the main paths. These are the states and settings they leave out: somebody
 * already in, the heading on demand, the button that starts over, the "sent"
 * screen's own words, every `?diluxone-users=` state drawn with its tone and
 * in the site's own words, the logo and the backdrop, the automatic layout,
 * the keyboard on the tabs, the dividers, and the link request's per-inbox,
 * per-machine and per-registration limits.
 */

const PASSWORD = 'e2e-Page-Ways-1!';

/** Every message the ways in can say, where it is said, and its tone. */
const MESSAGES: Array<{ key: string; tone: 'ok' | 'error'; screen: 'login' | 'two_step' | 'reset' | 'register'; state: string }> = [
	{ key: 'login_changed', tone: 'ok', screen: 'login', state: 'changed' },
	{ key: 'login_expired', tone: 'error', screen: 'login', state: 'expired' },
	{ key: 'login_confirm', tone: 'ok', screen: 'login', state: 'confirm' },
	{ key: 'login_closed', tone: 'ok', screen: 'login', state: 'closed' },
	{ key: 'login_email', tone: 'error', screen: 'login', state: 'email' },
	{ key: 'login_social', tone: 'error', screen: 'login', state: 'social' },
	{ key: 'login_error', tone: 'error', screen: 'login', state: 'error' },
	{ key: 'two_step_wrong', tone: 'error', screen: 'two_step', state: 'code' },
	{ key: 'two_step_locked', tone: 'error', screen: 'two_step', state: 'locked' },
	{ key: 'two_step_sent', tone: 'ok', screen: 'two_step', state: 'sent' },
	{ key: 'reset_mismatch', tone: 'error', screen: 'reset', state: 'nomatch' },
	{ key: 'register_taken', tone: 'error', screen: 'register', state: 'taken' },
	{ key: 'register_email', tone: 'error', screen: 'register', state: 'email' },
	{ key: 'register_missing', tone: 'error', screen: 'register', state: 'missing' },
	{ key: 'register_slow', tone: 'error', screen: 'register', state: 'slow' },
	{ key: 'register_closed', tone: 'error', screen: 'register', state: 'closed' },
	{ key: 'register_error', tone: 'error', screen: 'register', state: 'error' },
];

/** A page address with a state added. */
function withState(url: string, state: string): string {
	const out = new URL(url);
	out.searchParams.set('diluxone-users', state);

	return out.href;
}

test.describe('What the sign-in page draws, and for whom', () => {
	test('somebody already signed in gets no form on the sign-in page, nor on the registration page', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_register_form: 1 });

		const email = freshEmail('page-in');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(pages.login.url);
		await expect(page.locator('.diluxone-users-login')).toHaveCount(0);
		await expect(page.locator('form.diluxone-users-form')).toHaveCount(0);

		await page.goto(pages.register.url);
		await expect(registerScreen(page)).toHaveCount(0);
		await expect(page.locator('form.diluxone-users-form')).toHaveCount(0);
	});

	test('title="yes" draws the heading the page otherwise leaves to the theme', async ({ page, site }) => {
		const plain = await site.page('signin-plain', '[diluxone_users_login]');
		const titled = await site.page('signin-titled', '[diluxone_users_login title="yes"]');

		try {
			await page.goto(plain.url);
			await expect(linkForm(page)).toBeVisible();
			await expect(page.locator('.diluxone-users-login__title')).toHaveCount(0);

			await page.goto(titled.url);
			await expect(page.locator('.diluxone-users-login__title')).toBeVisible();
			await expect(page.locator('.diluxone-users-login__title')).not.toBeEmpty();
		} finally {
			await site.forgetPage('signin-plain');
			await site.forgetPage('signin-titled');
		}
	});

	test('the site’s heading, introduction and small print are on the page, and the logo above them', async ({ browser, baseURL, page, pages, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({
				diluxone_users_login_title: 'E2E heading',
				diluxone_users_login_intro: 'E2E introduction',
				diluxone_users_login_legal: 'E2E small print',
				diluxone_users_login_logo: image.id,
			});

			await page.goto(pages.login.url);
			await expect(page.locator('.diluxone-users-login__title')).toHaveText('E2E heading');
			await expect(page.locator('.diluxone-users-login__intro')).toHaveText('E2E introduction');
			await expect(page.locator('.diluxone-users-login__legal')).toHaveText('E2E small print');
			await expect(page.locator('.diluxone-users-login__logo img')).toHaveAttribute('src', image.url);
		} finally {
			await image.forget();
		}
	});

	test('the backdrop shape puts the chosen picture behind the form', async ({ browser, baseURL, page, pages, options }) => {
		const image = await uploadImage(browser, baseURL!, ADMIN_STATE);

		try {
			await options.set({ diluxone_users_login_template: 'backdrop', diluxone_users_login_image: image.id });

			await page.goto(pages.login.url);

			const frame = page.locator('.diluxone-users-login-frame--backdrop');
			await expect(frame).toBeVisible();
			expect(await frame.getAttribute('style')).toContain(image.url);
			await expect(frame.locator('.diluxone-users-login-frame__box').locator(linkForm(page))).toBeVisible();
		} finally {
			await image.forget();
		}
	});

	test('a state nobody knows draws no notice at all', async ({ page, pages }) => {
		await page.goto(withState(pages.login.url, 'zzz-nobody'));

		await expect(linkForm(page)).toBeVisible();
		await expect(page.locator('.diluxone-users-notice:visible')).toHaveCount(0);
	});
});

test.describe('Every message, with its tone, in the site’s own words', () => {
	test('each state draws its message with its tone, and the rewrite reaches every one of them', async ({ page, site, pages, options }) => {
		const words = Object.fromEntries(MESSAGES.map((one) => [one.key, `E2E words for ${one.key}`]));

		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_register_form: 1,
			diluxone_users_lost_password: 'site',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_login_messages: { es_AR: words, en_US: words },
		});

		// A live attempt for the second step's three, and a live reset for its one.
		const halfway = freshEmail('msg-2fa');
		await site.makeUser({ email: halfway, password: PASSWORD });
		const twoStep = await page.context().browser()!.newContext({ baseURL: new URL(pages.login.url).origin });
		const challenge = await twoStep.newPage();

		await challenge.goto(pages.login.url);
		await signInWithPassword(challenge, halfway, PASSWORD);
		await expect(challengeScreen(challenge)).toBeVisible();
		const challengeUrl = challenge.url();

		const resetting = freshEmail('msg-reset');
		await site.makeUser({ email: resetting, password: PASSWORD });
		const resetContext = await page.context().browser()!.newContext({ baseURL: new URL(pages.login.url).origin });
		const reset = await resetContext.newPage();

		await reset.goto('/wp-login.php?action=lostpassword');
		await reset.locator('input[name="user_login"]').fill(resetting);
		await reset.locator('#wp-submit').click();
		await reset.goto(linkIn(await waitForMail(site, resetting, { subject: /contrase|password/i })));
		await expect(resetScreen(reset)).toBeVisible();

		try {
			for (const one of MESSAGES) {
				const where =
					one.screen === 'two_step' ? challenge : one.screen === 'reset' ? reset : page;
				const url =
					one.screen === 'two_step'
						? withState(challengeUrl, one.state)
						: one.screen === 'register'
							? withState(pages.register.url, one.state)
							: withState(pages.login.url, one.state);

				await where.goto(url);

				const said = where.locator(`[data-diluxone-users-message="${one.key}"]`);

				await expect(said, one.key).toBeVisible();
				await expect(said, `${one.key} is a ${one.tone} notice`).toHaveClass(new RegExp(`diluxone-users-notice--${one.tone}`));
				await expect(said, `${one.key} in the site’s words`).toHaveText(new RegExp(`^E2E words for ${one.key}`));
			}
		} finally {
			await twoStep.close();
			await resetContext.close();
		}
	});

	test('a real non-address typed into the link box answers “email”, with the e-mail tab open and nothing sent', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_login_layout: 'tabs', diluxone_users_login_method: 'both' });
		await site.clearMail();

		await page.goto(pages.login.url);
		await openWay(page, 'email');
		await linkForm(page).evaluate((form: HTMLFormElement) => {
			form.noValidate = true;
		});
		await emailField(page).fill('not-an-address');

		expect(await submitPluginForm(page, linkForm(page))).toBe('email');
		await expect(page.locator('[data-diluxone-users-message="login_email"]')).toHaveClass(/diluxone-users-notice--error/);
		await expect(page.locator('[data-diluxone-users-way="email"]'), 'the box to type it again is open').toBeVisible();
		expect(await site.mail('not-an-address')).toEqual([]);
	});
});

test.describe('The “sent” screen', () => {
	test('it never puts the address in the address bar, and “Use a different address” starts over with an empty box', async ({ page, pages }) => {
		const email = freshEmail('Sent-Screen');

		await askForLink(page, pages.login.url, email);

		expect(stateOf(page.url())).toBe('sent');
		expect(page.url().toLowerCase()).not.toContain(encodeURIComponent(email).toLowerCase());
		expect(page.url().toLowerCase()).not.toContain(email.toLowerCase());

		await page.locator('.diluxone-users-login__again a').click();
		await page.waitForURL((url) => url.searchParams.get('diluxone-users') !== 'sent');

		await expect(sentScreen(page)).toHaveCount(0);
		await expect(linkForm(page)).toBeVisible();
		await expect(emailField(page)).toHaveValue('');
	});

	test('it says what the site wrote for it, with the round icon', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_sent_title: 'E2E look in your inbox', diluxone_users_sent_note: 'E2E try the spam folder', diluxone_users_sent_icon: 'circle' });

		await askForLink(page, pages.login.url, freshEmail('sent-words'));

		await expect(page.locator('.diluxone-users-login__title')).toHaveText('E2E look in your inbox');
		await expect(page.locator('.diluxone-users-note--icon span')).toHaveText('E2E try the spam folder');
		await expect(page.locator('.diluxone-users-login__icon--circle')).toBeVisible();
	});
});

test.describe('The arrangement of the ways in', () => {
	const ALL = {
		diluxone_users_login_method: 'both',
		diluxone_users_passkey_enabled: 1,
		diluxone_users_sso_login: 1,
		diluxone_e2e_sso: 1,
		diluxone_users_sso: MOCK_ON,
	};

	test('“automatic” draws tabs from three ways that would be tabs, and one column below that', async ({ page, pages, options }) => {
		await options.set({ ...ALL, diluxone_users_login_layout: 'auto' });

		await page.goto(pages.login.url);
		await expect(page.locator('[data-diluxone-users-ways-strip]')).toBeVisible();
		await expect(page.locator('[data-diluxone-users-ways]')).toHaveAttribute('data-diluxone-users-ways-mode', 'tabs');

		await options.set({ diluxone_users_login_method: 'link', diluxone_users_sso_login: 0 });

		await page.goto(pages.login.url);
		await expect(page.locator('[data-diluxone-users-ways]')).toHaveAttribute('data-diluxone-users-ways-mode', 'stack');
		await expect(page.locator('[data-diluxone-users-ways-strip]')).toBeHidden();
		await expect(linkForm(page)).toBeVisible();
	});

	test('the arrow keys move between tabs, open the panel, and the choice is remembered', async ({ page, pages, options }) => {
		await options.set({ ...ALL, diluxone_users_login_layout: 'tabs' });

		await page.goto(pages.login.url);

		const tabs = page.locator('[data-diluxone-users-way-tab]');
		await expect(tabs.first()).toBeVisible();
		const ids = await tabs.evaluateAll((all) => all.map((one) => one.getAttribute('data-diluxone-users-way-tab') as string));
		expect(ids.length).toBeGreaterThan(1);

		const selected = page.locator('[data-diluxone-users-way-tab][aria-selected="true"]');
		const from = (await selected.getAttribute('data-diluxone-users-way-tab')) as string;
		const next = ids[(ids.indexOf(from) + 1) % ids.length];

		await selected.focus();
		await page.keyboard.press('ArrowRight');

		await expect(page.locator(`[data-diluxone-users-way-tab="${next}"]`)).toHaveAttribute('aria-selected', 'true');
		await expect(page.locator(`[data-diluxone-users-way-tab="${next}"]`)).toBeFocused();
		await expect(page.locator(`[data-diluxone-users-way="${next}"]`)).toBeVisible();
		await expect(page.locator(`[data-diluxone-users-way="${from}"]`)).toBeHidden();

		await page.keyboard.press('End');
		const last = ids[ids.length - 1];
		await expect(page.locator(`[data-diluxone-users-way-tab="${last}"]`)).toHaveAttribute('aria-selected', 'true');

		await page.reload();
		await expect(page.locator(`[data-diluxone-users-way-tab="${last}"]`), 'the same tab opens next time').toHaveAttribute('aria-selected', 'true');
	});

	test('the “or” between ways is there in one column and hidden between tabs', async ({ page, pages, options }) => {
		await options.set({ ...ALL, diluxone_users_login_layout: 'stack' });

		await page.goto(pages.login.url);
		await expect(page.locator('[data-diluxone-users-ways-or]').first()).toBeVisible();

		await options.set({ diluxone_users_login_layout: 'tabs' });

		await page.goto(pages.login.url);
		await expect(page.locator('[data-diluxone-users-ways-strip]')).toBeVisible();
		expect(await page.locator('[data-diluxone-users-ways-or]').count()).toBeGreaterThan(0);
		await expect(page.locator('[data-diluxone-users-ways-or]:visible')).toHaveCount(0);
	});
});

test.describe('The link request, from the outside', () => {
	test('on a password-only site a link request made by hand answers “error” and mails nothing', async ({ page, site, pages, options }) => {
		const email = freshEmail('pw-only');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		const nonce = await page.locator('input[name="diluxone_users_nonce"]').inputValue();

		await options.set({ diluxone_users_login_method: 'password' });

		const answer = await page.request.post('/wp-admin/admin-post.php', {
			form: { action: 'diluxone_users_link_request', diluxone_users_nonce: nonce, diluxone_users_email: email },
			maxRedirects: 0,
		});

		expect(answer.status()).toBe(302);
		expect(stateOf(answer.headers()['location'])).toBe('error');
		expect(await site.mail(email)).toEqual([]);

		await page.goto(pages.login.url);
		await expect(linkForm(page), 'and no form to send it from').toHaveCount(0);
		await expect(passwordForm(page)).toBeVisible();
	});

	test('the wait per inbox ignores capitals: Ana@ and ana@ are one inbox', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_login_throttle: 120 });

		const email = freshEmail('caps');
		await site.makeUser({ email, password: PASSWORD });

		await askForLink(page, pages.login.url, email.toUpperCase());
		await askForLink(page, pages.login.url, email.toLowerCase());

		await expect.poll(async () => (await site.mail(email)).length).toBe(1);
		// Long enough for a second message to have been written, had there been one.
		await page.goto(pages.login.url);
		expect(await site.mail(email), 'one link, not two').toHaveLength(1);
	});

	test('one machine asking for link after link is answered the same, and mailed nothing, over the ceiling', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_e2e_login_burst: 2 });

		const people = [freshEmail('burst-a'), freshEmail('burst-b'), freshEmail('burst-c')];

		for (const email of people) {
			await site.makeUser({ email, password: PASSWORD });
		}

		for (const email of people) {
			await askForLink(page, pages.login.url, email);
			expect(stateOf(page.url()), `${email} gets the same answer`).toBe('sent');
		}

		await expect.poll(async () => (await site.mail(people[1])).length).toBe(1);
		expect((await site.mail(people[0])).length).toBe(1);
		expect(await site.mail(people[2]), 'the third, over the ceiling, gets nothing').toEqual([]);
	});

	test('addresses nobody has are counted on the registration ceiling: the seventh makes no account', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_login_register: 1 });

		const fresh = Array.from({ length: 7 }, (_, n) => freshEmail(`regburst-${n}`));

		for (const email of fresh) {
			await askForLink(page, pages.login.url, email);
			expect(stateOf(page.url())).toBe('sent');
		}

		for (const email of fresh.slice(0, 6)) {
			expect((await site.user(email)).exists, `${email} got an account`).toBe(true);
		}

		expect((await site.user(fresh[6])).exists, 'the seventh did not').toBe(false);
		expect(await site.mail(fresh[6])).toEqual([]);
	});

	test('a role no stranger may give themselves falls back to subscriber when the account is made', async ({ page, site, pages, options }) => {
		// Written past the screen, which would not offer it.
		await options.set({ diluxone_users_login_register: 1, diluxone_users_login_role: 'editor' });

		const email = freshEmail('role-fallback');

		await askForLink(page, pages.login.url, email);
		await page.goto(linkIn(await waitForMail(site, email)));
		await expectSignedIn(page, email);

		expect((await site.user(email)).roles).toEqual(['subscriber']);
	});

	test('a public name nobody has answers like one somebody has, and mails nobody', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_handle_enabled: 1, diluxone_users_handle_login: 1 });
		await site.clearMail();

		const nobody = `nobody${Date.now().toString(36)}`;

		await page.goto(pages.login.url);
		await emailField(page).fill(nobody);
		expect(await submitPluginForm(page, linkForm(page))).toBe('sent');

		await expect(sentScreen(page)).toContainText(nobody);
		await expectSignedOut(page);

		const mailbox = await page.request.get('/wp-json/diluxone-e2e/v1/mail', { headers: { 'X-Diluxone-E2E': 'diluxone-e2e' } });
		expect(await mailbox.json(), 'nothing was sent to anybody').toEqual([]);
	});
});
