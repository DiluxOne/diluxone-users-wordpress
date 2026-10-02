import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, navigated, openAllPanels, signInWithPassword, ssoButton } from '../support/ui';

/**
 * Signing in with a social account, against a network that answers from inside
 * the site.
 *
 * The round trip is three requests and only the first is a browser redirect,
 * so the other two are answered by the e2e mu-plugin through
 * `pre_http_request` — which means the whole flow runs with no network, no
 * credentials and no clicking through somebody's consent screen, and the test
 * gets to decide exactly what the provider claims.
 *
 * The case worth having is H-01: a provider that says the e-mail is NOT
 * verified must not be able to hand over an account that already exists with
 * that address. Discord does return unverified addresses, so "create an
 * account with the victim's e-mail, sign in as them" was a real path and this
 * is the test that keeps it closed.
 *
 * Covers T-SSO-05 and the reachable half of T-SSO-02.
 */

const PASSWORD = 'e2e-Social-1!';

/** The provider's credentials, in the three states it has to pass through. */
const MOCK_CREDENTIALS = {
	mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 },
};

test.describe('Signing in with a social account', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_CREDENTIALS,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('a network nobody knows here creates the account and opens the session', async ({ page, site, pages }) => {
		const email = freshEmail('sso-new');

		await site.setIdentity({
			sub: `mock|${email}`,
			email,
			email_verified: true,
			given_name: 'Grace',
			family_name: 'Hopper',
		});

		await page.goto(pages.login.url);
		await expect(ssoButton(page, 'mock')).toBeVisible();
		await ssoButton(page, 'mock').click();

		await expectSignedIn(page, email);

		const made = await site.user(email);

		expect(made.exists).toBe(true);
		expect(made.meta.diluxone_users_sso_mock, 'the identity is written on the account').toBe(`mock|${email}`);
		// The name the network gave is used, because the account had none.
		expect(made.meta.first_name).toBe('Grace');
		expect(made.meta.last_name).toBe('Hopper');
	});

	test('a verified address that already has an account opens that same account', async ({ page, site, pages }) => {
		const email = freshEmail('sso-known');
		const existing = await site.makeUser({ email, password: PASSWORD, name: 'Already Here' });

		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true, given_name: 'Ignored' });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedIn(page, email);

		const after = await site.user(email);

		expect(after.id, 'the same account, not a second one').toBe(existing.id);
		expect(after.meta.diluxone_users_sso_mock).toBe(`mock|${email}`);
		// What the person typed on the site wins over what the network says.
		expect(after.name).toBe('Already Here');
	});

	test('H-01: an unverified address does NOT get handed an existing account', async ({ page, site, pages }) => {
		const email = freshEmail('sso-unverified');
		const victim = await site.makeUser({ email, password: PASSWORD });

		// The attacker's account at the provider, carrying the victim's
		// address, which the provider has not vouched for.
		await site.setIdentity({ sub: 'mock|attacker', email, email_verified: false });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedOut(page);
		expect(stateOf(page.url()), 'back at the sign-in page with the social error').toBe('social');

		const after = await site.user(email);

		expect(after.id).toBe(victim.id);
		expect(after.meta.diluxone_users_sso_mock, 'and nothing was written on the account').toBe('');
	});

	test('a provider that says nothing about the address cannot take over an account either', async ({
		page,
		site,
		pages,
	}) => {
		const email = freshEmail('sso-silent');
		await site.makeUser({ email, password: PASSWORD });

		// No `email_verified` claim at all: silence is not a yes when an
		// existing account is what is at stake.
		await site.setIdentity({ sub: 'mock|silent', email });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('social');
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('');
	});

	test('with “verified only” on, silence stops a new account too', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_sso_verified_only: 1 });

		const email = freshEmail('sso-vonly');

		await site.setIdentity({ sub: 'mock|vonly', email });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('social');
		expect((await site.user(email)).exists, 'no account may be created').toBe(false);
	});

	test('with social registration off, an unknown address is turned away', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_sso_register: 0, diluxone_users_login_register: 0 });

		const email = freshEmail('sso-closed');

		await site.setIdentity({ sub: 'mock|closed', email, email_verified: true });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('social');
		expect((await site.user(email)).exists).toBe(false);
	});

	test('somebody who presses “cancel” at the provider lands back, told so, and signed out', async ({
		page,
		site,
		pages,
	}) => {
		await site.setIdentity({ deny: true });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('social');
	});

	test('a role outside the list cannot come in through a network, even with the identity linked', async ({
		page,
		site,
		pages,
		options,
	}) => {
		// Said the way the screen says it: everybody, or only these. An
		// editor is not on the list, so the editor uses the e-mail link.
		await options.set({
			diluxone_users_sso_scope: 'some',
			diluxone_users_sso_roles: ['subscriber'],
		});

		const email = freshEmail('sso-blocked');
		await site.makeUser({
			email,
			password: PASSWORD,
			role: 'editor',
			meta: { diluxone_users_sso_mock: 'mock|editor' },
		});

		await site.setIdentity({ sub: 'mock|editor', email, email_verified: true });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('social');
	});

	test('linking from the account area, and unlinking again', async ({ page, site, pages }) => {
		const email = freshEmail('sso-link');
		const person = await site.makeUser({ email, password: PASSWORD });

		await site.setIdentity({ sub: 'mock|linkable', email: freshEmail('other'), email_verified: true });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);

		const row = page.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' });
		await expect(row).toBeVisible();

		// The "Link" link carries a nonce of its own: linking writes to an
		// account, so a page somebody else made must not be able to start it.
		const link = row.locator('a.diluxone-users-button');
		await expect(link).toHaveAttribute('href', /diluxone_users_nonce=/);

		await link.click();

		// Back on the account, still the same person, now with the network on
		// it — and NOT signed in as whoever the identity belongs to.
		await expectSignedIn(page, email);
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('mock|linkable');

		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);

		const linkedRow = page.locator('.diluxone-users-linked__item.is-linked').filter({ hasText: 'Mock' });
		await expect(linkedRow).toBeVisible();

		// Unlinking sends the person back where they were, with no state in the
		// address — so what is waited for is the round trip, not a parameter.
		await navigated(page, () => linkedRow.locator('button[type="submit"]').click());
		await expect(page.locator('.diluxone-users-linked__item.is-linked').filter({ hasText: 'Mock' })).toHaveCount(
			0
		);

		expect((await site.user(email)).meta.diluxone_users_sso_mock, 'unlinked').toBe('');
		expect((await site.user(email)).id).toBe(person.id);
	});

	test('H-02: a link trip started without the account’s own nonce goes nowhere', async ({
		page,
		site,
		pages,
	}) => {
		const email = freshEmail('sso-csrf');
		const victim = await site.makeUser({ email, password: PASSWORD });

		await site.setIdentity({ sub: 'mock|attacker-identity', email: freshEmail('attacker'), email_verified: true });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		// The address an attacker's page would send the victim to: the same
		// trip the "Link" button starts, with the nonce left off.
		await page.goto('/sso/mock/?diluxone_users_go=1');

		expect(stateOf(page.url())).toBe('social');
		expect(
			(await site.user(email)).meta.diluxone_users_sso_mock,
			'the attacker’s identity must not end up on the victim’s account'
		).toBe('');
		// Still signed in as themselves, which is the other half of it.
		await expectSignedIn(page, email);
		expect((await site.user(email)).id).toBe(victim.id);
	});

	test('a callback with a state nobody issued is refused', async ({ page, site, pages }) => {
		await site.setIdentity({ sub: 'mock|forged', email: freshEmail('forged'), email_verified: true });

		await page.goto('/sso/mock/?code=e2e-code&state=made-up');

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('social');
	});

	test('the buttons disappear when the site stops offering them', async ({ page, pages, options }) => {
		await page.goto(pages.login.url);
		await expect(ssoButton(page, 'mock')).toBeVisible();

		await options.set({ diluxone_users_sso_login: 0 });

		await page.goto(pages.login.url);
		await expect(ssoButton(page, 'mock')).toHaveCount(0);
	});
});
