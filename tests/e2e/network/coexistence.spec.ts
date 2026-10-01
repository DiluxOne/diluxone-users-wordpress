import { Page } from '@playwright/test';
import { test, expect, whoOn, signInFrom, SiteHandle } from './support';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { wp } from '../support/cli';
import { avoidWindowEdge, totp } from '../support/totp';
import { askForLink, challengeCode, challengeScreen, fillCredentials, saveButton, signInWithPassword, ssoButton } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * Living with WordPress and with the rest of the site, on a network.
 *
 * The same cases as `specs/coexistence.spec.ts`, where a network makes them
 * different: the second step answered on the hub's wp-login.php when the hub
 * has no sign-in page, coming back to the site the sign-in started on;
 * `wp_login` fired once on the hub by its own doors; a registration from
 * elsewhere on any site; the network's fields seeded and suggested in Network
 * Admin; the network's session length; the membership policy waiting for its
 * confirmation; the emergency switch giving every site its own wp-login.php
 * back; and the hub's pages kept out of caches.
 */

const PASSWORD = 'e2e-Network-Coexist-1!';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };
const MEMBERSHIP = `${NETWORK_URL}/wp-admin/network/admin.php?page=diluxone-users-membership`;

/** Network Admin's address for one of the plugin's screens. */
function networkAdmin(screen: string, tab = ''): string {
	return `${NETWORK_URL}/wp-admin/network/admin.php?page=${screen}${tab ? `&tab=${tab}` : ''}`;
}

/** Signs in on a site's wp-login.php the way a person does; on /beta/ that is the hub's, with the way back. */
async function signInOnWpLogin(page: Page, one: SiteHandle, email: string, remember = false): Promise<void> {
	await page.goto(one.path('wp-login.php'));
	await fillCredentials(page, email, PASSWORD);

	if (remember) {
		await page.locator('#rememberme').check();
	}

	await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wp-submit').click()]);
}

async function answerOnWpLogin(page: Page, code: string): Promise<void> {
	await challengeCode(page).fill(code);
	await Promise.all([
		page.waitForURL((url) => !url.searchParams.has('diluxone_users_2fa'), { waitUntil: 'domcontentloaded' }),
		challengeScreen(page).locator('input[type="submit"]').click(),
	]);
}

test.describe('The second step with no sign-in page on the hub: the hub’s wp-login.php draws it', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.set({
			diluxone_users_login_page: null,
			diluxone_users_login_method: 'both',
			diluxone_users_wp_screens: 'auto',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['totp', 'email'],
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_remember_days: 0,
		});
	});

	test('from /beta/: a password and the code by e-mail on the hub’s wp-login.php, back on /beta/', async ({ page, hub, alpha, beta }) => {
		const email = freshEmail('net-wplogin-mail');
		await alpha.site.makeUser({ email, password: PASSWORD });

		await signInOnWpLogin(page, beta, email);

		await expect(challengeScreen(page)).toBeVisible();
		expect(page.url().startsWith(`${NETWORK_URL}/wp-login.php`), 'on the hub’s wp-login.php').toBe(true);
		expect(new URL(page.url()).searchParams.get('action')).toBe('diluxone_users_2fa');
		expect(await whoOn(page, beta.url), 'nobody is in before the code').toBeNull();

		await answerOnWpLogin(page, codeIn(await waitForMail(hub.site, email)));

		expect(new URL(page.url()).pathname, 'back on /beta/').toMatch(/^\/beta\//);
		expect(await whoOn(page, beta.url)).toBe(email);
	});

	test('a password and the authenticator app, on the hub’s wp-login.php', async ({ page, hub, alpha }) => {
		const email = freshEmail('net-wplogin-app');
		await alpha.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_totp: SECRET, diluxone_users_2fa_on: 1 } });

		await signInOnWpLogin(page, hub, email);
		await expect(challengeScreen(page)).toBeVisible();

		await avoidWindowEdge();
		await answerOnWpLogin(page, totp(SECRET));

		expect(await whoOn(page, hub.url)).not.toBeNull();
	});

	test('with a sign-in page on the hub, the second step is on that page', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_login_page: hub.pages.login.id, diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('net-wplogin-page');
		await alpha.site.makeUser({ email, password: PASSWORD });

		await signInFrom(page, beta, hub, email, PASSWORD);

		await expect(challengeScreen(page)).toBeVisible();
		expect(page.url().startsWith(hub.pages.login.url)).toBe(true);
	});
});

test.describe('With nowhere to answer the second step, in Network Admin', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('“required” cannot be chosen or saved, and the screen says why', async ({ page, hub }) => {
		await hub.set({
			diluxone_users_login_page: null,
			diluxone_e2e_no_wp_login_2fa: 1,
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
		});

		await page.goto(networkAdmin('diluxone-users-security', '2fa'));

		const required = page.locator('input[name="diluxone_users_2fa_mode"][value="required"]');

		await expect(required).toBeDisabled();
		await expect(page.locator('.diluxone-users-not-now')).toBeVisible();

		await required.evaluate((input: HTMLInputElement) => {
			input.disabled = false;
			input.checked = true;
		});
		await Promise.all([page.waitForLoadState('domcontentloaded'), saveButton(page).click()]);

		await expect(page.locator('.notice-error')).toBeVisible();
		expect((await hub.site.getOptions(['diluxone_users_2fa_mode'])).diluxone_users_2fa_mode).toBe('optional');
	});
});

test.describe('The hub’s own doors fire wp_login, once', () => {
	test('the e-mail link', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_e2e_wp_login: null });

		const email = freshEmail('net-wplogin-link');
		await alpha.site.makeUser({ email, password: PASSWORD });

		await askForLink(page, hub.pages.login.url, email);
		await page.goto(linkIn(await waitForMail(hub.site, email)));

		expect(await whoOn(page, hub.url)).not.toBeNull();
		expect(await hub.site.wpLogins(email)).toBe(1);
	});

	test('a social account', async ({ page, hub }) => {
		await hub.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_2fa_mode: 'off',
			diluxone_e2e_wp_login: null,
		});

		const email = freshEmail('net-wplogin-sso');

		await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });
		await page.goto(hub.pages.login.url);
		await ssoButton(page, 'mock').click();
		await page.waitForLoadState('domcontentloaded');

		expect(await whoOn(page, hub.url)).not.toBeNull();
		expect(await hub.site.wpLogins(email)).toBe(1);
	});

	test('a passkey', async ({ page, hub, alpha, browserName }) => {
		test.skip(browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

		await hub.set({ diluxone_users_passkey_enabled: 1, diluxone_users_passkey_where: 'any', diluxone_users_passkey_verify: 1, diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('net-wplogin-passkey');
		await alpha.site.makeUser({ email, password: PASSWORD });

		const cdp = await page.context().newCDPSession(page);
		await cdp.send('WebAuthn.enable', { enableUI: false });
		const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
			options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
		});

		await page.goto(hub.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');

		await page.goto(`${hub.pages.account.url.replace(/\/?$/, '/')}security/`);
		await page.locator('details.diluxone-users-panel').filter({ has: page.locator('[data-diluxone-users-passkey="register"]') }).first().evaluate((d: HTMLDetailsElement) => (d.open = true));
		await page.locator('[data-diluxone-users-passkey-label]').fill('e2e');
		await page.locator('[data-diluxone-users-passkey="register"]').click();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveValue('e2e', { timeout: 20_000 });

		await page.context().clearCookies();
		await hub.set({ diluxone_e2e_wp_login: null });

		await page.goto(hub.pages.login.url);
		await page.locator('[data-diluxone-users-passkey="login"]').click();
		await page.waitForURL((url) => !url.href.startsWith(hub.pages.login.url), { timeout: 20_000 });

		expect(await whoOn(page, hub.url)).not.toBeNull();
		expect(await hub.site.wpLogins(email)).toBe(1);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});
});

test.describe('A registration from somewhere else', () => {
	test('register_new_user() with none of the plugin’s fields goes through, on the hub and on /beta/', async ({ hub, beta }) => {
		await hub.set({
			diluxone_users_fields: [{ key: 'e2e_city', label: 'City', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' }],
		});

		for (const one of [hub, beta]) {
			const email = freshEmail('net-elsewhere');
			const made = await one.site.register(`e2e${Date.now().toString(36)}${one.slug}`, email);

			expect(made.errors, `${one.slug || 'hub'}: no error from a field it never showed`).toBeUndefined();
			expect(made.id).toBeGreaterThan(0);
		}
	});

	// WordPress's own form with the fields and a forged nonce is the single
	// site's case: on a network WordPress's registration is wp-signup.php,
	// which never draws the fields. The nonce check itself runs on both
	// topologies in WpRegisterFieldsTest.
});

test.describe('The network’s fields: a name, and the rest suggested', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('a fresh network seeds the first and last name; suggested fields are added in Network Admin and reach /beta/', async ({ page, hub, beta }) => {
		await hub.set({ diluxone_users_fields: null });

		await page.goto(networkAdmin('diluxone-users-fields'));

		const seeded = (await hub.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; required: number | boolean }>;

		expect(seeded.map((one) => one.key)).toEqual(['first_name', 'last_name']);
		expect(seeded.every((one) => !one.required)).toBe(true);

		await page.goto(networkAdmin('diluxone-users-fields', 'suggested'));
		await page.locator('input[name="diluxone_users_suggested[]"][value="diluxone_users_phone"]').check({ force: true });
		await Promise.all([page.waitForLoadState('domcontentloaded'), saveButton(page).click()]);

		await expect(page.locator('.notice-success')).toBeVisible();

		const fromBeta = (await beta.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string }>;

		expect(fromBeta.map((one) => one.key), 'one list for the network').toEqual(['first_name', 'last_name', 'diluxone_users_phone']);
	});
});

test.describe('How long a session lasts, on the network', () => {
	test('by default WordPress’s 14 days, for a sign-in on the hub', async ({ page, hub, alpha }) => {
		await hub.set({ diluxone_users_2fa_mode: 'off', diluxone_users_session_long_days: null, diluxone_users_session_short_days: null });

		const email = freshEmail('net-session');
		await alpha.site.makeUser({ email, password: PASSWORD });

		await page.goto(`${NETWORK_URL}/wp-login.php?diluxone-users-admin=1`);
		await fillCredentials(page, email, PASSWORD);
		await page.locator('#rememberme').check();
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wp-submit').click()]);

		const cookie = (await page.context().cookies()).find((one) => one.name.startsWith('wordpress_logged_in_'));

		expect(cookie, 'a session').toBeTruthy();
		expect(Math.round((cookie!.expires - Date.now() / 1000) / 86400)).toBe(14);
	});

	test.describe('Network Admin', () => {
		test.use({ storageState: NETWORK_ADMIN_STATE });

		test('shows 0 for what WordPress decides', async ({ page, hub }) => {
			await hub.set({ diluxone_users_session_long_days: null, diluxone_users_session_short_days: null });

			await page.goto(networkAdmin('diluxone-users-security', 'sessions'));

			await expect(page.locator('input[name="diluxone_users_session_long_days"]')).toHaveValue('0');
		});
	});
});

test.describe('Membership waits for the network to confirm it', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	/** How many jobs the queue holds. */
	function queued(): number {
		return Number(wp(['eval', 'echo count( diluxone_users_membership_queue() );'])) || 0;
	}

	test('until confirmed nobody is added and Network Admin says so; “Confirm the policy” adds everybody', async ({ page, guest, hub, alpha, beta }) => {
		await hub.set({
			diluxone_users_membership: 'all',
			diluxone_users_membership_confirmed: null,
			diluxone_users_membership_queue: null,
			diluxone_users_2fa_mode: 'off',
			diluxone_users_login_method: 'both',
		});

		// An account made while the policy waits: WordPress's own membership only.
		const email = freshEmail('net-unconfirmed');
		await hub.site.makeUser({ email, password: PASSWORD });

		expect((await alpha.site.user(email)).member, 'not added to /alpha/').toBe(false);
		expect((await beta.site.user(email)).member, 'nor /beta/').toBe(false);

		// Signing in from /beta/ — under a confirmed "every site", the safety
		// net that makes them a member — adds nobody either.
		await signInFrom(guest, beta, hub, email, PASSWORD);
		expect((await beta.site.user(email)).member, 'signing in from /beta/ adds nobody').toBe(false);

		await page.goto(`${NETWORK_URL}/wp-admin/network/`);
		await expect(page.locator('[data-diluxone-users-membership-unconfirmed]')).toBeVisible();

		await page.goto(`${NETWORK_URL}/wp-admin/network/users.php`);
		await expect(page.locator('[data-diluxone-users-membership-unconfirmed]'), 'quiet on a screen that is not about the plugin').toHaveCount(0);

		await page.goto(MEMBERSHIP);
		await expect(page.locator('[data-diluxone-users-membership-unconfirmed]'), 'quiet on the screen that asks').toHaveCount(0);
		await expect(page.locator('.diluxone-users-not-now')).toBeVisible();
		await expect(page.locator('[data-diluxone-users-sync]'), 'no Sync before the confirmation').toHaveCount(0);

		await hub.keep(['diluxone_users_membership', 'diluxone_users_membership_confirmed']);
		await page.locator('input[name="diluxone_users_membership"][value="all"]').check();
		await Promise.all([page.waitForLoadState('domcontentloaded'), saveButton(page).click()]);

		// On the spot when small, through the queue when not: cron runs it here.
		for (let run = 0; run < 60 && queued() > 0; run++) {
			wp(['cron', 'event', 'run', '--due-now']);
		}

		expect((await alpha.site.user(email)).member, 'a member of /alpha/ now').toBe(true);
		expect((await beta.site.user(email)).member, 'and of /beta/').toBe(true);

		await page.goto(`${NETWORK_URL}/wp-admin/network/`);
		await expect(page.locator('[data-diluxone-users-membership-unconfirmed]')).toHaveCount(0);

		await hub.site.deleteUser(email);
	});
});

test.describe('Safe mode, on the network', () => {
	test.beforeEach(async ({ hub, alpha, beta }) => {
		// The must-use plugin's switch is read on the site the request is for.
		for (const one of [hub, alpha, beta]) {
			await one.set({ diluxone_e2e_safe_mode: 1 });
		}

		await hub.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'], diluxone_users_wp_screens: 'mine' });
	});

	test('/beta/’s wp-login.php is its own, and its password signs in with no second step', async ({ page, alpha, beta }) => {
		const email = freshEmail('net-safe');
		await alpha.site.makeUser({ email, password: PASSWORD });

		await page.goto(beta.path('wp-login.php'));
		expect(new URL(page.url()).pathname, 'not sent to the hub').toBe('/beta/wp-login.php');

		await fillCredentials(page, email, PASSWORD);
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wp-submit').click()]);

		await expect(challengeScreen(page)).toHaveCount(0);
		expect(await whoOn(page, beta.url)).not.toBeNull();
	});

	test.describe('the dashboards', () => {
		test.use({ storageState: NETWORK_ADMIN_STATE });

		test('Network Admin and every site say so', async ({ page, alpha }) => {
			for (const url of [`${NETWORK_URL}/wp-admin/network/`, `${NETWORK_URL}/wp-admin/`, alpha.admin('')]) {
				await page.goto(url);
				await expect(page.locator('[data-diluxone-users-safe-mode]'), url).toBeVisible();
			}
		});
	});
});

test.describe('The hub’s pages a cache must not keep', () => {
	test('the sign-in, registration and account pages send no-store and define DONOTCACHEPAGE; a site’s front page does neither', async ({ request, hub, beta }) => {
		for (const url of [hub.pages.login.url, hub.pages.register.url, hub.pages.account.url]) {
			const response = await request.get(url);

			expect(response.headers()['cache-control'] ?? '', url).toContain('no-store');
			expect(response.headers()['x-diluxone-e2e-donotcachepage'], url).toBe('1');
		}

		const front = await request.get(beta.url);

		expect(front.headers()['cache-control'] ?? '').not.toContain('no-store');
	});
});
