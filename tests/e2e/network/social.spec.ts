import { createHash } from 'node:crypto';
import { test, expect, whoOn, toTheHub, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { accountSection, openAllPanels, navigated, ssoButton } from '../support/ui';

/**
 * A social account linked once, used from any site of the network.
 *
 * Social sign-in is the hub's: its round trip, its address in the provider's
 * console, the account area where an identity is linked. The link is written
 * on the account — user meta, which a network shares — so the same identity
 * coming back through the hub from /beta/ is the same person. The identity's
 * address is deliberately NOT the account's: that is the case where a plugin
 * that only matched by e-mail would make a second account, and the one where
 * finding the first one by the identity is the only right answer.
 */

const PASSWORD = 'e2e-Network-1!';
const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

test('linked from /alpha/ on the hub’s account, the same network from /beta/ opens the same account and makes no second one', async ({
	browser,
	hub,
	alpha,
	beta,
	root,
}) => {
	// The providers and their rules are the network's; the fake network
	// answers on the hub, the one site that talks to it.
	await hub.set({
		diluxone_e2e_sso: 1,
		diluxone_users_sso: MOCK,
		diluxone_users_sso_login: 1,
		diluxone_users_sso_register: 1,
		diluxone_users_sso_link_by_email: 1,
		diluxone_users_sso_verified_only: 0,
		diluxone_users_login_register: 1,
		diluxone_users_2fa_mode: 'off',
	});

	const email = freshEmail('net-sso');
	const elsewhere = freshEmail('net-sso-identity');
	const sub = `mock|${elsewhere}`;
	const person = await alpha.site.makeUser({ email, password: PASSWORD });

	await hub.site.setIdentity({ sub, email: elsewhere, email_verified: true });

	// Signed in from /alpha/, linked on the account, which is the hub's.
	const first = await (await browser.newContext()).newPage();

	await signInFrom(first, alpha, hub, email, PASSWORD);
	await first.goto(accountSection(hub.pages.account.url, 'accounts'));
	await openAllPanels(first);
	await navigated(first, () => first.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' }).locator('a.diluxone-users-button').click());

	expect((await root.user(email)).meta.diluxone_users_sso_mock, 'linked on the hub').toBe(sub);

	// A different browser, from /beta/, through the button on the hub.
	const second = await (await browser.newContext()).newPage();

	await toTheHub(second, beta, hub);
	await navigated(second, () => ssoButton(second, 'mock').click());
	await second.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

	expect(await whoOn(second, beta.url), 'the identity opens the account it is linked to').toBe(email);
	expect((await root.user(elsewhere)).exists, 'no second account under the identity’s address').toBe(false);
	expect((await beta.site.user(email)).id).toBe(person.id);
});

test('from /beta/, a sign-in with “Use PKCE” on goes through the hub with the S256 challenge and the verifier that matches it', async ({
	browser,
	hub,
	beta,
}) => {
	await hub.set({
		diluxone_e2e_sso: 1,
		diluxone_e2e_pkce: null,
		diluxone_users_sso: { mock: { ...MOCK.mock, pkce: 1 } },
		diluxone_users_sso_login: 1,
		diluxone_users_sso_register: 1,
		diluxone_users_sso_link_by_email: 1,
		diluxone_users_sso_verified_only: 0,
		diluxone_users_2fa_mode: 'off',
	});

	const email = freshEmail('net-sso-pkce');

	await hub.site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

	const page = await (await browser.newContext()).newPage();

	await toTheHub(page, beta, hub);
	await navigated(page, () => ssoButton(page, 'mock').click());
	await page.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

	expect(await whoOn(page, beta.url), 'signed in, back on /beta/').toBe(email);

	// The trip is the hub's, and so is what the provider was sent.
	const seen = ((await hub.site.getOptions(['diluxone_e2e_pkce'])).diluxone_e2e_pkce ?? {}) as { challenge?: string; method?: string; verifier?: string };

	expect(seen.method).toBe('S256');
	expect(seen.verifier, 'the exchange sent a verifier').toMatch(/^[A-Za-z0-9]{64}$/);
	expect(createHash('sha256').update(seen.verifier!).digest('base64url'), 'the one the challenge was made from').toBe(seen.challenge);
});
