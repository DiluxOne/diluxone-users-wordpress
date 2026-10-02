import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { linkIn, Site, waitForMail } from '../support/api';
import { accountSection, askForLink, challengeScreen, emailField, fillCredentials, notice, resetScreen, signInWithPassword } from '../support/ui';
import { masks, settled } from '../support/pictures';
import { pinVisualState } from '../support/visual-state';

/**
 * A picture of every public page the plugin draws, compared with last time.
 *
 * The dashboard's pictures are of screens an administrator sees; these are of
 * the ones everybody else does, drawn inside the site's theme: the sign-in
 * page on each of its shapes and arrangements, the screens that follow it —
 * the link on its way, the second step, a new password — the registration
 * form open and closed, and the account area on both its shapes and both its
 * menus, section by section. Every one of them is a screen a measurement can
 * pass while it looks wrong, and every one has a setting on the Design tabs
 * that claims to change it.
 *
 * The same spec runs twice: in the `visual` project at 1280 and in
 * `visual-mobile` at 390, where the stylesheet's 640, 560 and 480 rules take
 * over and the split layout gives up its panel. The pictures of the second
 * run carry `-mobile` in their name.
 *
 * Everything a picture shows is pinned here or in `visual-state.ts`: the
 * settings, and a person with a fixed address and name, made for these
 * pictures and deleted with every other account of the suite.
 */

const PERSON = { email: 'visual-person@e2e.test', name: 'Alex Rivera', password: 'e2e-Visual-Person-1!' };

/** The person the account pictures are of, made now, with nothing open. */
async function person(site: Site, meta: Record<string, unknown> = {}): Promise<void> {
	await site.makeUser({
		email: PERSON.email,
		password: PERSON.password,
		name: PERSON.name,
		// Nothing left over from the picture before: no session, no second
		// step, no device it has been seen on.
		meta: {
			first_name: 'Alex',
			last_name: 'Rivera',
			session_tokens: null,
			diluxone_users_devices: null,
			diluxone_users_2fa_on: null,
			diluxone_users_totp: null,
			...meta,
		},
	});
}

/** Signs the person in through the escape hatch, which draws the same form on every shape. */
async function signIn(page: Page): Promise<void> {
	await page.goto('/wp-login.php?diluxone-users-admin=1');
	await fillCredentials(page, PERSON.email, PERSON.password);
	await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);
	await expectSignedIn(page, PERSON.email);
}

/**
 * The block a sign-in shape draws: the frame for the framed ones — the card,
 * the split and the backdrop reach past the theme's column on purpose — and
 * the form itself for the plain one.
 */
async function signInBlock(page: Page) {
	const frame = page.locator('.diluxone-users-login-frame');

	return (await frame.count()) > 0 ? frame.first() : page.locator('.diluxone-users-login').first();
}

test.beforeEach(async ({ options, site }) => {
	await options.set({ WPLANG: '' });
	await pinVisualState(site, options.set);
});

test.describe('The sign-in page looks like it did', () => {
	test('signed out', async ({ page, pages }) => {
		await page.goto(pages.login.url);
		await settled(page);

		await expect(page.locator('.diluxone-users-login').first()).toHaveScreenshot('front-sign-in.png', { mask: masks(page) });
	});

	/**
	 * Every way in switched on, behind tabs.
	 *
	 * The picture above is the ordinary case and says nothing about the
	 * arrangement built for the other one: four doors on a laptop. That one has
	 * a tab strip, a neutral icon per tab, one panel showing and three not —
	 * none of which a measurement can look at.
	 */
	test('signed out, four ways in, in tabs', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_users_sso_login: 1,
			diluxone_users_login_layout: 'tabs',
			diluxone_users_login_order: ['social', 'email', 'password'],
			diluxone_users_login_open: 'email',
		});

		await page.goto(pages.login.url);
		await settled(page);

		await expect(page.locator('.diluxone-users-login').first()).toHaveScreenshot('front-sign-in-tabs.png', { mask: masks(page) });
	});

	const SHAPES: Record<string, Record<string, unknown>> = {
		card: { diluxone_users_login_template: 'card' },
		'split-left': {
			diluxone_users_login_template: 'split',
			diluxone_users_login_side: 'left',
			diluxone_users_login_panel_title: 'One account.\nNo passwords.',
			diluxone_users_login_panel_text: 'Everything you do here, in one place.',
			diluxone_users_login_panel_points: 'Sign in with your e-mail\nOr with a social account',
		},
		'split-right': {
			diluxone_users_login_template: 'split',
			diluxone_users_login_side: 'right',
			diluxone_users_login_panel_title: 'One account.\nNo passwords.',
			diluxone_users_login_panel_text: 'Everything you do here, in one place.',
		},
		backdrop: { diluxone_users_login_template: 'backdrop' },
	};

	for (const [shape, settings] of Object.entries(SHAPES)) {
		for (const layout of ['stack', 'tabs'] as const) {
			test(`as ${shape}, ${layout === 'stack' ? 'stacked' : 'in tabs'}`, async ({ page, pages, options }) => {
				await options.set({ ...settings, diluxone_users_login_layout: layout, diluxone_users_sso_login: 1 });

				await page.goto(pages.login.url);
				await settled(page);

				await expect(await signInBlock(page)).toHaveScreenshot(`front-sign-in-${shape}-${layout}.png`, { mask: masks(page) });
			});
		}
	}

	for (const method of ['link', 'password'] as const) {
		test(`with only the ${method}`, async ({ page, pages, options }) => {
			await options.set({ diluxone_users_login_method: method, diluxone_users_sso_login: 0 });

			await page.goto(pages.login.url);
			await settled(page);

			await expect(await signInBlock(page)).toHaveScreenshot(`front-sign-in-only-${method}.png`, { mask: masks(page) });
		});
	}

	test('with a title, an introduction and a legal line', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_title: 'Welcome back',
			diluxone_users_login_intro: 'Use the address you signed up with.',
			diluxone_users_login_legal: 'By signing in you accept the <a href="/terms/">terms</a>.',
		});

		await page.goto(pages.login.url);
		await settled(page);

		await expect(await signInBlock(page)).toHaveScreenshot('front-sign-in-words.png', { mask: masks(page) });
	});

	test('the link on its way', async ({ page, pages, site }) => {
		await person(site);
		await askForLink(page, pages.login.url, PERSON.email);
		await settled(page);

		await expect(await signInBlock(page)).toHaveScreenshot('front-sign-in-sent.png', { mask: masks(page) });
	});

	test('the link on its way, with the round icon', async ({ page, pages, site, options }) => {
		await options.set({ diluxone_users_sent_icon: 'circle' });
		await person(site);
		await askForLink(page, pages.login.url, PERSON.email);
		await settled(page);

		await expect(await signInBlock(page)).toHaveScreenshot('front-sign-in-sent-circle.png', { mask: masks(page) });
	});

	test('a link that ran out', async ({ page, pages }) => {
		await page.goto(`${pages.login.url}?diluxone-users=expired`);
		await expect(notice(page, 'error')).toBeVisible();
		await settled(page);

		await expect(await signInBlock(page)).toHaveScreenshot('front-sign-in-expired.png', { mask: masks(page) });
	});
});

test.describe('The screens after the sign-in look like they did', () => {
	test('the second step, a code by e-mail', async ({ page, pages, site, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'] });
		await person(site, { diluxone_users_2fa_on: '1', diluxone_users_totp: null });

		await page.goto(pages.login.url);
		await signInWithPassword(page, PERSON.email, PERSON.password);
		await expect(challengeScreen(page)).toBeVisible();
		await settled(page);

		await expect(challengeScreen(page)).toHaveScreenshot('front-two-step-email.png', { mask: masks(page) });
	});

	test('the second step, the authenticator app', async ({ page, pages, site, options }) => {
		await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['totp', 'email'] });
		await person(site, { diluxone_users_2fa_on: '1', diluxone_users_totp: 'JBSWY3DPEHPK3PXP' });

		await page.goto(pages.login.url);
		await signInWithPassword(page, PERSON.email, PERSON.password);
		await expect(challengeScreen(page)).toBeVisible();
		await settled(page);

		await expect(challengeScreen(page)).toHaveScreenshot('front-two-step-app.png', { mask: masks(page) });
	});

	test('choosing a new password on the site’s page', async ({ page, pages, site, options }) => {
		await options.set({ diluxone_users_lost_password: 'site' });
		await person(site);

		await page.goto('/wp-login.php?action=lostpassword');
		await page.locator('input[name="user_login"]').fill(PERSON.email);
		await page.locator('#wp-submit').click();

		await page.goto(linkIn(await waitForMail(site, PERSON.email, { subject: /contrase|password/i })));
		await expect(resetScreen(page)).toBeVisible();
		await settled(page);

		await expect(resetScreen(page)).toHaveScreenshot('front-reset.png', { mask: masks(page) });
	});
});

test.describe('The registration form looks like it did', () => {
	test('open, with the site’s fields', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_register_form: 1 });

		await page.goto(pages.register.url);
		await expect(emailField(page)).toBeVisible();
		await settled(page);

		await expect(page.locator('.diluxone-users-register').first()).toHaveScreenshot('front-register.png', { mask: masks(page) });
	});

	test('with no form of its own: signing in makes the account', async ({ page, pages }) => {
		await page.goto(pages.register.url);
		await expect(emailField(page)).toHaveCount(0);
		await settled(page);

		await expect(page.locator('.diluxone-users-register').first()).toHaveScreenshot('front-register-by-link.png', { mask: masks(page) });
	});

	test('closed', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_login_register: 0, diluxone_users_sso_register: 0 });

		await page.goto(pages.register.url);
		await settled(page);

		await expect(page.locator('.diluxone-users-register').first()).toHaveScreenshot('front-register-closed.png', { mask: masks(page) });
	});
});

test.describe('The account area looks like it did', () => {
	test('to a stranger', async ({ page, pages }) => {
		await page.goto(pages.account.url);
		await settled(page);

		await expect(page.locator('.diluxone-users-account').first()).toHaveScreenshot('front-account-guest.png', { mask: masks(page) });
	});

	const SHAPES: Array<[string, string]> = [
		['plain', 'tabs'],
		['plain', 'side'],
		['cover', 'tabs'],
		['cover', 'side'],
	];

	for (const [shape, layout] of SHAPES) {
		test(`as ${shape}, menu ${layout}`, async ({ page, pages, site, options }) => {
			await options.set({ diluxone_users_account_template: shape, diluxone_users_account_layout: layout });
			await person(site);
			await signIn(page);

			await page.goto(pages.account.url);
			await expect(page.locator('.diluxone-users-account--guest')).toHaveCount(0);
			await settled(page);

			await expect(page.locator('.diluxone-users-account').first()).toHaveScreenshot(`front-account-${shape}-${layout}.png`, { mask: masks(page) });
		});
	}

	for (const section of ['details', 'accounts', 'security', 'notifications', 'privacy']) {
		test(`its ${section} section`, async ({ page, pages, site, options }) => {
			await options.set({ diluxone_users_account_template: 'plain', diluxone_users_account_layout: 'tabs', diluxone_users_handle_enabled: 1 });
			await person(site);
			await signIn(page);

			await page.goto(accountSection(pages.account.url, section));
			await expect(page.locator('.diluxone-users-account--guest')).toHaveCount(0);
			await settled(page);

			await expect(page.locator('.diluxone-users-account').first()).toHaveScreenshot(`front-account-${section}.png`, { mask: masks(page) });
		});
	}
});

