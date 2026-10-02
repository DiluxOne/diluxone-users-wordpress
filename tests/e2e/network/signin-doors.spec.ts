import { test, expect, whoOn, toTheHub, signInFrom } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, askForLink, fillCredentials, linkForm, navigated, openAllPanels, resetScreen, signInWithPassword, ssoButton } from '../support/ui';
import { wp } from '../support/cli';
import { MAPPED_HOST, NETWORK_URL } from '../../../playwright.network.config';
import { MOCK_ON } from '../support/signin';

/**
 * The ways in on a network, off their plain paths.
 *
 * Every door of the network is the hub's (`doors.spec.ts`). These are the
 * answers that door gives when something goes wrong or is asked from the wrong
 * place: a spent link and a failed social trip said on the hub's page, the
 * passkey dialogue and the plugin's forms asked of another site, an old
 * social address on another site, WordPress's registration and lost-password
 * screens of another site, a password reset on the hub's page for a person of
 * another site — and a site on a domain of its own, whose door is its own
 * wp-login.php.
 */

const PASSWORD = 'e2e-Net-Ways-1!';
const NEW_PASSWORD = 'e2e-Net-Ways-New-2!';

/** The state the plugin put in an address. */
function stateOf(url: string): string {
	return new URL(url).searchParams.get('diluxone-users') ?? '';
}

test.describe('Answers said on the hub’s page', () => {
	test('a link opened a second time, from another browser, lands on the hub’s sign-in with “expired” and signs nobody in', async ({ page, browser, hub, beta }) => {
		await hub.set({ diluxone_users_login_method: 'both', diluxone_users_login_register: 1, diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('net-spent');

		await toTheHub(page, beta, hub);
		await askForLink(page, page.url(), email);
		const link = linkIn(await waitForMail(hub.site, email));

		await page.goto(link);
		expect(await whoOn(page, beta.url)).toBe(email);

		const other = await (await browser.newContext()).newPage();

		try {
			await other.goto(link);

			expect(other.url().startsWith(hub.pages.login.url), 'on the hub’s sign-in page').toBe(true);
			expect(stateOf(other.url())).toBe('expired');
			await expect(other.locator('[data-diluxone-users-message="login_expired"]')).toHaveClass(/diluxone-users-notice--error/);
			expect(await whoOn(other, beta.url)).toBeNull();
			expect(await whoOn(other, hub.url)).toBeNull();
		} finally {
			await other.context().close();
		}
	});

	test('a social trip from /beta/ that comes back without a session lands on the hub’s sign-in, saying so', async ({ page, hub, beta }) => {
		await hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: MOCK_ON, diluxone_users_sso_login: 1, diluxone_users_2fa_mode: 'off' });
		await hub.site.setIdentity({ deny: true });

		await toTheHub(page, beta, hub);
		await navigated(page, () => ssoButton(page, 'mock').click());

		expect(page.url().startsWith(hub.pages.login.url)).toBe(true);
		expect(stateOf(page.url())).toBe('social');
		await expect(page.locator('[data-diluxone-users-message="login_social"]')).toHaveClass(/diluxone-users-notice--error/);
		expect(await whoOn(page, beta.url)).toBeNull();
	});
});

test.describe('The hub’s doors asked of another site', () => {
	test('the passkey dialogue asked of /beta/ answers 403 and names the hub’s sign-in page', async ({ page, hub, beta }) => {
		await hub.set({ diluxone_users_passkey_enabled: 1, diluxone_users_login_method: 'both' });

		await page.goto(hub.pages.login.url);
		const nonce = await page.evaluate(() => (window as any).diluxOneUsersPasskeys?.nonce as string);
		expect(nonce).toBeTruthy();

		const answer = await page.request.post(`${beta.url}wp-admin/admin-ajax.php`, {
			form: { action: 'diluxone_users_passkeys', nonce, step: 'login-options' },
			failOnStatusCode: false,
		});

		expect(answer.status()).toBe(403);
		const json = await answer.json();
		expect(json.success).toBe(false);
		expect(String(json.data.redirect).startsWith(hub.pages.login.url), 'told where passkeys are used').toBe(true);
	});

	test('the plugin’s forms posted to /beta/ are sent to the hub and do nothing there', async ({ page, hub, beta }) => {
		await hub.set({ diluxone_users_login_method: 'both', diluxone_users_login_register: 1, diluxone_users_register_form: 1, diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('net-bounced');

		// A nonce a stranger is handed on the hub's page: valid, so what stops
		// the request is where it was sent, not a failed check.
		await page.goto(hub.pages.login.url);
		const nonce = await page.locator('input[name="diluxone_users_nonce"]').inputValue();

		for (const form of [
			{ action: 'diluxone_users_link_request', diluxone_users_nonce: nonce, diluxone_users_email: email },
			{ action: 'diluxone_users_signup', diluxone_users_email: email },
			{ action: 'diluxone_users_reset', diluxone_users_pass: NEW_PASSWORD, diluxone_users_pass2: NEW_PASSWORD },
		]) {
			const answer = await page.request.post(`${beta.url}wp-admin/admin-post.php`, { form, maxRedirects: 0 });

			expect(answer.status(), form.action).toBe(302);
			expect(answer.headers()['location'].startsWith(hub.pages.login.url), `${form.action} goes to the hub’s sign-in`).toBe(true);
		}

		expect((await hub.site.user(email)).exists, 'no account was made').toBe(false);
		expect(await hub.site.mail(email)).toEqual([]);
		expect(await beta.site.mail(email)).toEqual([]);
	});

	test('an unlink posted to /beta/ by somebody signed in goes to the hub’s account and leaves the network linked', async ({ page, hub, alpha, beta, root }) => {
		await hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: MOCK_ON, diluxone_users_sso_login: 1, diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('net-unlink');
		await alpha.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: 'mock|net-unlink' } });

		await signInFrom(page, beta, hub, email, PASSWORD);
		await page.goto(accountSection(hub.pages.account.url, 'accounts'));
		await openAllPanels(page);
		const nonce = await page.locator('.diluxone-users-linked__item.is-linked input[name="_wpnonce"]').first().inputValue();

		const answer = await page.request.post(`${beta.url}wp-admin/admin-post.php`, {
			form: { action: 'diluxone_users_sso_unlink', diluxone_users_provider: 'mock', _wpnonce: nonce },
			maxRedirects: 0,
		});

		expect(answer.status()).toBe(302);
		expect(answer.headers()['location'].startsWith(hub.pages.account.url), 'to the account, on the hub').toBe(true);
		expect((await root.user(email)).meta.diluxone_users_sso_mock, 'still linked').toBe('mock|net-unlink');
	});

	/** Asks /beta/ for an old social address, as a stranger and as somebody signed in through the hub. */
	async function oldAddress(page: any, hub: any, alpha: any, beta: any, old: string, bug = ''): Promise<void> {
		await hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: MOCK_ON, diluxone_users_sso_login: 1, diluxone_users_2fa_mode: 'off' });
		await beta.set({ diluxone_e2e_sso: 1 });

		const stranger = await page.request.get(old, { maxRedirects: 0, failOnStatusCode: false });

		expect(stranger.status(), bug || 'a redirect').toBe(302);
		expect((stranger.headers()['location'] ?? '').startsWith(hub.pages.login.url), 'a stranger goes to the hub’s sign-in').toBe(true);
		expect(stranger.headers()['location']).not.toContain('/oauth/authorize');

		const email = freshEmail('net-oldsso');
		await alpha.site.makeUser({ email, password: PASSWORD });
		await signInFrom(page, beta, hub, email, PASSWORD);

		const member = await page.request.get(old, { maxRedirects: 0, failOnStatusCode: false });

		expect(member.status()).toBe(302);
		expect((member.headers()['location'] ?? '').startsWith(hub.pages.account.url), 'somebody signed in goes to the account').toBe(true);
	}

	test('an old social address on /beta/, by parameter, starts no trip: to the hub’s sign-in, or to the account', async ({ page, hub, alpha, beta }) => {
		await oldAddress(page, hub, alpha, beta, `${beta.url}?diluxone_users_sso=mock&diluxone_users_go=1`);
	});

	test('an old social address on /beta/, by path, starts no trip: to the hub’s sign-in, or to the account', async ({ page, hub, alpha, beta }) => {
		await oldAddress(
			page,
			hub,
			alpha,
			beta,
			`${beta.url}sso/mock/?diluxone_users_go=1`,
			'bug: the path a provider’s console kept, /beta/sso/<id>/, answers 404 instead of going to the hub: the /sso/ rule is only registered on the hub (includes/sso.php:226), so the off-hub branch of diluxone_users_sso_handle() never sees it'
		);
	});

	test('WordPress’s registration and lost-password screens of /beta/ are the hub’s, with the same action', async ({ page, hub, beta }) => {
		for (const action of ['register', 'lostpassword']) {
			const answer = await page.request.get(`${beta.url}wp-login.php?action=${action}`, { maxRedirects: 0 });

			expect(answer.status(), action).toBe(302);

			const to = new URL(answer.headers()['location']);
			expect(to.href.startsWith(`${hub.url}wp-login.php`), `${action} goes to the hub’s wp-login.php`).toBe(true);
			expect(to.searchParams.get('action')).toBe(action);
		}
	});
});

test.describe('A new password, on a network', () => {
	test('asked from /beta/, mailed by the hub, chosen on the hub’s page, and it signs in from /beta/', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_lost_password: 'site', diluxone_users_login_method: 'both', diluxone_users_2fa_mode: 'off' });

		const email = freshEmail('net-reset');
		await alpha.site.makeUser({ email, password: PASSWORD });

		await page.goto(`${beta.url}wp-login.php?action=lostpassword`);
		expect(page.url().startsWith(`${hub.url}wp-login.php`), 'the hub’s form').toBe(true);
		await page.locator('input[name="user_login"]').fill(email);
		await navigated(page, () => page.locator('#wp-submit').click());

		const mail = await waitForMail(hub.site, email, { subject: /password|contrase/i });
		const link = linkIn(mail);
		expect(link.startsWith(hub.url), 'the hub’s link').toBe(true);

		await page.goto(link);
		expect(page.url().startsWith(hub.pages.login.url), 'the hub’s sign-in page draws the reset').toBe(true);
		await expect(resetScreen(page)).toBeVisible();

		await page.locator('input[name="diluxone_users_pass"]').fill(NEW_PASSWORD);
		await page.locator('input[name="diluxone_users_pass2"]').fill(NEW_PASSWORD);
		await Promise.all([page.waitForURL(/diluxone-users=changed/), resetScreen(page).locator('button[type="submit"]').click()]);

		await page.context().clearCookies();
		await toTheHub(page, beta, hub);
		await signInWithPassword(page, email, NEW_PASSWORD);
		await page.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });
		expect(await whoOn(page, beta.url)).toBe(email);
	});
});

test.describe('A site on a domain of its own', () => {
	test('its sign-in page is a door to its own wp-login.php, which signs in and comes back, and then draws nothing', async ({ browser, hub, alpha }) => {
		const port = new URL(NETWORK_URL).port;
		const domain = `${MAPPED_HOST}${port ? `:${port}` : ''}`;
		const home = `http://${domain}/`;
		const id = wp(['site', 'create', `--slug=e2e-mapped-signin-${Date.now().toString(36)}`, '--title=Mapped', '--porcelain']);
		const email = freshEmail('net-mapped');

		await alpha.site.makeUser({ email, password: PASSWORD });

		try {
			wp(['eval', `wp_update_site( ${id}, array( 'domain' => '${domain}', 'path' => '/' ) ); update_blog_option( ${id}, 'home', '${home.replace(/\/$/, '')}' ); update_blog_option( ${id}, 'siteurl', '${home.replace(/\/$/, '')}' );`]);
			wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Sign in here', '--post_name=e2e-mapped-door', '--post_content=[diluxone_users_login]', '--porcelain'], home);
			const door = `${home}e2e-mapped-door/`;

			const page = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

			await page.goto(door);
			await expect(linkForm(page), 'no form drawn here').toHaveCount(0);

			const button = page.locator('.diluxone-users-hub-door a.diluxone-users-button');
			await expect(button).toBeVisible();
			const href = new URL((await button.getAttribute('href')) as string);
			expect(href.host, 'the door stays on this domain').toBe(domain);
			expect(href.pathname).toBe('/wp-login.php');
			expect(href.href.startsWith(hub.pages.login.url), 'not the hub’s page, which would never bring a session back').toBe(false);

			await navigated(page, () => button.click());
			expect(new URL(page.url()).host).toBe(domain);
			await fillCredentials(page, email, PASSWORD);
			await navigated(page, () => page.locator('#wp-submit').click());

			await page.waitForURL((url) => url.host === domain && !url.pathname.endsWith('wp-login.php'));
			await page.goto(door);
			await expect(page.locator('.diluxone-users-hub-door'), 'signed in here, the door is gone').toHaveCount(0);

			await page.goto(`${home}wp-admin/profile.php`);
			expect(new URL(page.url()).pathname, 'a session on this domain').toBe('/wp-admin/profile.php');
			await page.context().close();
		} finally {
			wp(['site', 'delete', id, '--yes']);
		}
	});
});

test.describe('The second step after a social sign-in, on a network', () => {
	test('from /beta/: the network on the hub, the code on the hub, and back on /beta/ only after it', async ({ page, hub, alpha, beta }) => {
		await hub.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_remember_days: 0,
		});

		const email = freshEmail('net-sso-2fa');
		await alpha.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: `mock|${email}` } });
		await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		await toTheHub(page, beta, hub);
		await navigated(page, () => ssoButton(page, 'mock').click());

		expect(page.url().startsWith(hub.pages.login.url), 'the code is asked on the hub').toBe(true);
		await expect(page.locator('.diluxone-users-login--2fa')).toBeVisible();
		expect(await whoOn(page, beta.url)).toBeNull();

		const code = (await waitForMail(hub.site, email)).body.match(/\b(\d{6})\b/)![1];
		await page.locator('input[name="diluxone_users_2fa_code"]').fill(code);
		await page.locator('.diluxone-users-login--2fa form.diluxone-users-form button[type="submit"]').first().click();

		await page.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });
		expect(await whoOn(page, beta.url)).toBe(email);
	});
});
