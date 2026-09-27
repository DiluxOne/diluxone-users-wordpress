import { test, expect, whoOn } from './support';
import { freshEmail } from '../support/api';
import { openAllPanels, signInWithPassword, ssoButton } from '../support/ui';

/**
 * A social account linked on one site of the network, used on another.
 *
 * The link is written on the account — user meta, which a network shares —
 * so the same identity coming back through /beta/ is the same person. The
 * identity's address is deliberately NOT the account's: that is the case where
 * a plugin that only matched by e-mail would make a second account, and the
 * one where finding the first one by the identity is the only right answer.
 */

const PASSWORD = 'e2e-Network-1!';
const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

test('linked on /alpha/, the same network on /beta/ opens the same account and makes no second one', async ({
	browser,
	alpha,
	beta,
	root,
}) => {
	for (const one of [alpha, beta]) {
		await one.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_login_register: 1,
			diluxone_users_2fa_mode: 'off',
		});
	}

	const email = freshEmail('net-sso');
	const elsewhere = freshEmail('net-sso-identity');
	const sub = `mock|${elsewhere}`;
	const person = await alpha.site.makeUser({ email, password: PASSWORD });

	// The fake network is per site; both are told the same thing.
	for (const one of [alpha, beta]) {
		await one.site.setIdentity({ sub, email: elsewhere, email_verified: true });
	}

	// Linked from /alpha/'s account area.
	const first = await (await browser.newContext()).newPage();

	await first.goto(alpha.pages.login.url);
	await signInWithPassword(first, email, PASSWORD);
	await first.waitForLoadState('domcontentloaded');
	await first.goto(`${alpha.pages.account.url.replace(/\/?$/, '/')}accounts/`);
	await openAllPanels(first);
	await first.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' }).locator('a.diluxone-users-button').click();
	await first.waitForLoadState('domcontentloaded');

	expect((await root.user(email)).meta.diluxone_users_sso_mock, 'linked on /alpha/').toBe(sub);

	// A different browser, on /beta/, through the button.
	const second = await (await browser.newContext()).newPage();

	await second.goto(beta.pages.login.url);
	await ssoButton(second, 'mock').click();
	await second.waitForLoadState('domcontentloaded');

	expect(await whoOn(second, beta.url), 'the identity opens the account it is linked to').toBe(email);
	expect((await root.user(elsewhere)).exists, 'no second account under the identity’s address').toBe(false);
	expect((await beta.site.user(email)).id).toBe(person.id);
});
