import { Page } from '@playwright/test';
import { test, expect, whoOn, hubDoor, toTheHub, signInFrom, SiteHandle } from './support';
import { codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { FRONT_RULES, WIDTHS, expectSoundLayout } from '../support/layout';
import {
	askForLink,
	challengeCode,
	challengeScreen,
	emailField,
	linkForm,
	openPanel,
	accountSection,
	registerForm,
	signInWithPassword,
	ssoButton,
	submitPluginForm,
} from '../support/ui';

/**
 * Every door of the network is the hub's, and every one of them comes back.
 *
 * On a network the person's account lives on one site, the hub — the main
 * site — and the other sites send people there: the page that holds the
 * sign-in on /beta/ is a button to the hub's, /beta/wp-login.php is the hub's
 * wp-login.php, the account link in /beta/'s menu is the account on the hub.
 * Whichever way the person gets in there — a password, the e-mail link, a
 * social account, a passkey, with the second step or without it — they land
 * back on the page of /beta/ they started from, signed in, and — under the
 * network's "every site" membership, the default — a member of /beta/ (the
 * other policies are membership.spec.ts's).
 *
 * Which doors there are, and the role a newcomer gets, are the hub's settings,
 * and the network's own registration setting sits above everything.
 */

const PASSWORD = 'e2e-Network-1!';

const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

/** The fields the registration form asks for, pinned — see register.spec.ts for why. */
const PLAIN_FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

/** The same key the single-site passkey spec uses: a laptop with a fingerprint reader. */
const VIRTUAL_KEY = {
	protocol: 'ctap2' as const,
	transport: 'internal' as const,
	hasResidentKey: true,
	hasUserVerification: true,
	isUserVerified: true,
	automaticPresenceSimulation: true,
};

/** Waits to be back on a page of `one`, anywhere under its address. */
async function backOn(page: Page, one: SiteHandle, what: string): Promise<void> {
	await page.waitForURL((url) => url.href.startsWith(one.url), { waitUntil: 'domcontentloaded', timeout: 20_000 });
	expect(new URL(page.url()).pathname, what).toMatch(new RegExp(`^/${one.slug}/`));
}

test.describe('A site of the network has no sign-in of its own: its doors are the hub’s', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.set({ diluxone_users_login_register: 1, diluxone_users_register_form: 1, diluxone_users_fields: PLAIN_FIELDS });
	});

	test('the pages that held the forms on /alpha/ and /beta/ are buttons to the hub’s, with the way back', async ({
		page,
		hub,
		alpha,
		beta,
	}) => {
		for (const one of [alpha, beta]) {
			await page.goto(one.pages.login.url);
			await expect(emailField(page), `no form on /${one.slug}/`).toHaveCount(0);

			const href = (await hubDoor(page).locator('a').getAttribute('href')) as string;

			expect(href.startsWith(hub.pages.login.url), `/${one.slug}/'s sign-in goes to the hub's page`).toBe(true);
			expect(new URL(href).searchParams.get('redirect_to'), 'and back to where it was pressed').toBe(one.pages.login.url);

			await page.goto(one.pages.register.url);
			const register = (await hubDoor(page, 'register').locator('a').getAttribute('href')) as string;

			expect(register.startsWith(hub.pages.register.url), `/${one.slug}/'s registration is the hub's form`).toBe(true);

			await page.goto(one.pages.account.url);
			await expect(hubDoor(page, 'login'), `/${one.slug}/'s account page asks a stranger to sign in, on the hub`).toBeVisible();
		}

		// The door is a piece of the site's page, drawn by its theme: it holds
		// together at every width, like the sign-in it stands in for.
		await page.goto(beta.pages.login.url);
		await expectSoundLayout(page, FRONT_RULES, WIDTHS);

		// The hub draws its own forms.
		await page.goto(hub.pages.login.url);
		await expect(linkForm(page)).toBeVisible();
		await expect(hubDoor(page)).toHaveCount(0);
	});

	test('from /beta/: Sign in → the hub → a password → back on /beta/, signed in and a member', async ({
		page,
		hub,
		alpha,
		beta,
	}) => {
		const email = freshEmail('hub-pass');

		// Made while the network was "by invitation": a member of /alpha/
		// alone. Under "every site" signing in is the safety net.
		await hub.set({ diluxone_users_membership: 'invite' });
		await alpha.site.makeUser({ email, password: PASSWORD });
		await hub.set({ diluxone_users_membership: 'all' });
		expect((await beta.site.user(email)).member).toBe(false);

		await signInFrom(page, beta, hub, email, PASSWORD);
		await backOn(page, beta, 'back on /beta/');

		expect(page.url()).toBe(beta.pages.login.url);
		expect(await whoOn(page, beta.url)).toBe(email);
		expect((await beta.site.user(email)).member, 'signing in for /beta/ makes them a member of /beta/').toBe(true);
	});

	test('from /beta/: by e-mail link, from the hub’s mailbox, back on /beta/ — and on another browser, on the hub', async ({
		page,
		browser,
		hub,
		beta,
	}) => {
		const email = freshEmail('hub-link');

		await toTheHub(page, beta, hub);
		await askForLink(page, page.url(), email);

		const link = linkIn(await waitForMail(hub.site, email));

		expect(link.startsWith(hub.url), 'the link is the hub’s').toBe(true);
		expect(link, 'and carries no address of where to go').not.toContain('redirect_to');
		expect((await beta.site.user(email)).member, 'under “every site” the account the link request made is a member of every site').toBe(true);

		await page.goto(link);
		await backOn(page, beta, 'the browser it was asked in comes back to /beta/');
		expect(page.url()).toBe(beta.pages.login.url);
		expect(await whoOn(page, beta.url)).toBe(email);
		expect((await beta.site.user(email)).member).toBe(true);

		// Asked from /beta/ and opened in another browser, which cannot know
		// where the person came from: it lands on the hub, signed in.
		const other = freshEmail('hub-link-elsewhere');

		await page.context().clearCookies();
		await toTheHub(page, beta, hub);
		await askForLink(page, page.url(), other);

		const elsewhere = await (await browser.newContext()).newPage();

		await elsewhere.goto(linkIn(await waitForMail(hub.site, other)));
		expect(new URL(elsewhere.url()).pathname, 'another browser lands on the hub’s front page').toBe('/');
		expect(await whoOn(elsewhere, hub.url)).toBe(other);
		await elsewhere.context().close();
	});

	test('from /beta/: by a social account, on the hub, back on /beta/', async ({ page, hub, beta }) => {
		await hub.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_2fa_mode: 'off',
		});

		const email = freshEmail('hub-sso');

		await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		await toTheHub(page, beta, hub);

		const button = ssoButton(page, 'mock');
		const href = (await button.getAttribute('href')) as string;

		expect(href.startsWith(`${hub.url}sso/mock/`), 'the round trip is the hub’s').toBe(true);

		await button.click();
		await backOn(page, beta, 'back on /beta/ from the provider');
		expect(await whoOn(page, beta.url)).toBe(email);
		expect((await beta.site.user(email)).member).toBe(true);
	});

	test('from /beta/: by passkey, on the hub’s domain, back on /beta/', async ({ page, browserName, hub, alpha, beta }) => {
		test.skip(browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

		await hub.set({
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
		});

		const email = freshEmail('hub-passkey');

		await alpha.site.makeUser({ email, password: PASSWORD });

		const cdp = await page.context().newCDPSession(page);

		await cdp.send('WebAuthn.enable', { enableUI: false });

		const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: VIRTUAL_KEY });

		// Made on the account, which is the hub's.
		await signInFrom(page, alpha, hub, email, PASSWORD);
		await page.goto(accountSection(hub.pages.account.url, 'security'));

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');

		await panel.locator('[data-diluxone-users-passkey-label]').fill('Network laptop');
		await panel.locator('[data-diluxone-users-passkey="register"]').click();
		await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveValue('Network laptop', { timeout: 20_000 });

		// Out, and in again from /beta/ with the key alone.
		await page.context().clearCookies();
		await toTheHub(page, beta, hub);
		await page.locator('[data-diluxone-users-passkey="login"]').click();
		await backOn(page, beta, 'the passkey lands back on /beta/');
		expect(await whoOn(page, beta.url)).toBe(email);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('from /beta/: a password and the second step, both on the hub, back on /beta/', async ({ page, hub, alpha, beta }) => {
		await hub.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_remember_days: 0,
			diluxone_users_login_method: 'both',
		});

		const email = freshEmail('hub-2fa');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await signInFrom(page, beta, hub, email, PASSWORD);

		await expect(challengeScreen(page)).toBeVisible();
		expect(page.url().startsWith(hub.pages.login.url), 'the second step is asked on the hub').toBe(true);
		expect(await whoOn(page, beta.url), 'nobody is in before the code').toBeNull();

		await challengeCode(page).fill(codeIn(await waitForMail(hub.site, email)));
		await page.locator('form.diluxone-users-form button[type="submit"]').first().click();

		await backOn(page, beta, 'the code sends them back to /beta/');
		expect(await whoOn(page, beta.url)).toBe(email);
	});

	test('register from /beta/: the hub’s form, the link, and back on /beta/ as a member', async ({ page, hub, beta }) => {
		const email = freshEmail('hub-register');

		await toTheHub(page, beta, hub, 'register');
		await registerForm(page).locator('input[name="diluxone_users_email"]').fill(email);
		expect(await submitPluginForm(page, registerForm(page))).toBe('registered');

		await page.goto(linkIn(await waitForMail(hub.site, email)));
		await backOn(page, beta, 'the first sign-in lands on /beta/');
		expect(await whoOn(page, beta.url)).toBe(email);
		expect((await beta.site.user(email)).member, 'a member of the site they registered from').toBe(true);
	});
});

test.describe('WordPress’s own doors on /beta/ are the hub’s', () => {
	test('/beta/wp-login.php opens the hub’s, and signing in there comes back to /beta/', async ({ page, hub, alpha, beta }) => {
		const email = freshEmail('hub-wplogin');

		await alpha.site.makeUser({ email, password: PASSWORD });

		await page.goto(`${beta.url}wp-login.php`);
		expect(page.url().startsWith(`${hub.url}wp-login.php`), 'the hub’s wp-login.php').toBe(true);
		expect(new URL(page.url()).searchParams.get('redirect_to')).toBe(beta.url);

		await signInWithPassword(page, email, PASSWORD);
		await backOn(page, beta, 'back on /beta/');
		expect(await whoOn(page, beta.url)).toBe(email);
	});

	test('/beta/wp-admin/ with no session goes through the hub and back to /beta/’s dashboard', async ({ page, hub, beta }) => {
		const email = freshEmail('hub-admin');

		await beta.site.makeUser({ email, password: PASSWORD, role: 'editor' });

		await page.goto(`${beta.url}wp-admin/`);
		expect(page.url().startsWith(`${hub.url}wp-login.php`)).toBe(true);

		await signInWithPassword(page, email, PASSWORD);
		await page.waitForURL((url) => url.pathname.startsWith(`/${beta.slug}/wp-admin`));
	});

	test('what /beta/ keeps for itself stays on /beta/: the emergency door, logging out', async ({ page, beta }) => {
		await page.goto(`${beta.url}wp-login.php?diluxone-users-admin=1`);
		expect(new URL(page.url()).pathname).toBe(`/${beta.slug}/wp-login.php`);
		await expect(page.locator('form#loginform')).toBeVisible();

		await page.goto(`${beta.url}wp-login.php?action=logout`);
		expect(new URL(page.url()).pathname, 'logging out is answered where it is asked').toBe(`/${beta.slug}/wp-login.php`);
	});

	test('a way back to somewhere that is not the network is dropped', async ({ page, hub, alpha, beta }) => {
		await page.goto(`${beta.url}wp-login.php?redirect_to=${encodeURIComponent('https://evil.test/')}`);
		expect(new URL(page.url()).searchParams.get('redirect_to'), 'replaced by /beta/’s front page').toBe(beta.url);

		const email = freshEmail('hub-evil');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await page.goto(`${hub.pages.login.url}?redirect_to=${encodeURIComponent('//evil.test/')}`);
		await signInWithPassword(page, email, PASSWORD);
		await page.waitForLoadState('domcontentloaded');

		expect(new URL(page.url()).host, 'still on the network').toBe(new URL(hub.url).host);
	});
});

test.describe('/beta/’s menu', () => {
	test('“Sign in” goes to the hub and back; the person’s item opens their account on the hub', async ({ page, hub, alpha, beta }) => {
		const menu = await beta.site.menu();

		await beta.set({ diluxone_users_menu_location: menu.location });

		try {
			await page.goto(menu.url);

			const signIn = page.locator('.diluxone-users-menu--sign-in a');
			const href = (await signIn.getAttribute('href')) as string;

			expect(href.startsWith(hub.pages.login.url), '“Sign in” is the hub’s').toBe(true);
			expect(new URL(href).searchParams.get('redirect_to')).toBe(menu.url);

			const email = freshEmail('hub-menu');

			await alpha.site.makeUser({ email, password: PASSWORD });
			await signIn.click();
			await signInWithPassword(page, email, PASSWORD);
			await page.waitForURL(menu.url, { waitUntil: 'domcontentloaded' });

			const person = page.locator('.diluxone-users-menu--person > a');

			await expect(person).toHaveAttribute('href', hub.pages.account.url);
			await person.click();
			await expect(page.locator('.diluxone-users-account__nav'), 'the account, on the hub').toBeVisible();
			expect(page.url().startsWith(hub.pages.account.url)).toBe(true);
		} finally {
			await beta.site.forgetMenu();
		}
	});
});

test.describe('Who may join, and who may exist', () => {
	test('a network member signing in for /beta/ is not a member there until they open the link', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_login_register: 1, diluxone_users_login_role: 'subscriber' });

		const email = freshEmail('net-join');

		// A member of /alpha/ alone, made under "by invitation"; then every site.
		await hub.set({ diluxone_users_membership: 'invite' });
		await alpha.site.makeUser({ email, password: PASSWORD });
		await hub.set({ diluxone_users_membership: 'all' });

		await toTheHub(page, beta, hub);
		await askForLink(page, page.url(), email);

		// Anybody can type anybody's address into a form. If typing it were
		// enough, any address on the network could be made a member of any
		// site by a stranger.
		expect((await beta.site.user(email)).member, 'asking for a link is not using it').toBe(false);

		await page.goto(linkIn(await waitForMail(hub.site, email)));
		await backOn(page, beta, 'back on /beta/');

		const after = await beta.site.user(email);

		expect(after.member, 'opening it is what makes them a member').toBe(true);
		expect(after.roles, 'with /beta/’s own New User Default Role').toEqual(['subscriber']);
	});

	test.describe('the network decides whether anybody new can exist at all', () => {
		test.beforeEach(async ({ hub }) => {
			await hub.set({
				diluxone_e2e_sso: 1,
				diluxone_users_login_register: 1,
				diluxone_users_register_form: 1,
				diluxone_users_fields: PLAIN_FIELDS,
				diluxone_users_sso: MOCK,
				diluxone_users_sso_login: 1,
				diluxone_users_sso_register: 1,
				diluxone_users_2fa_mode: 'off',
			});
		});

		test('“Registration is currently turned off”: no door of the hub creates anybody', async ({ browser, hub, alpha, root, network }) => {
			await network.set('registration', 'none');

			// The link.
			const byLink = freshEmail('net-none-link');
			const one = await browser.newPage();

			await toTheHub(one, alpha, hub);
			await askForLink(one, one.url(), byLink);
			expect((await root.user(byLink)).exists, 'the sign-in link made an account').toBe(false);

			// The hub's own form.
			const byForm = freshEmail('net-none-form');
			const two = await browser.newPage();

			await two.goto(hub.pages.register.url);

			if ((await registerForm(two).count()) > 0) {
				await two.locator('input[name="diluxone_users_email"]').fill(byForm);
				await submitPluginForm(two, registerForm(two));
			}

			expect((await root.user(byForm)).exists, 'the registration form made an account').toBe(false);

			// A social network.
			const bySocial = freshEmail('net-none-sso');
			const three = await browser.newPage();

			await hub.site.setIdentity({ sub: `mock|${bySocial}`, email: bySocial, email_verified: true });
			await toTheHub(three, alpha, hub);
			await ssoButton(three, 'mock').click();
			await three.waitForLoadState('domcontentloaded');

			expect((await root.user(bySocial)).exists, 'a social sign-in made an account').toBe(false);
		});

		test('“User accounts may be registered”: the link makes the account, and a member of the site it came from', async ({
			page,
			hub,
			alpha,
			network,
		}) => {
			await network.set('registration', 'user');

			const email = freshEmail('net-user');

			await toTheHub(page, alpha, hub);
			await askForLink(page, page.url(), email);

			expect((await hub.site.user(email)).exists).toBe(true);

			await page.goto(linkIn(await waitForMail(hub.site, email)));
			await backOn(page, alpha, 'back on /alpha/');
			expect(await whoOn(page, alpha.url)).toBe(email);
			expect((await alpha.site.user(email)).member).toBe(true);
		});
	});
});
