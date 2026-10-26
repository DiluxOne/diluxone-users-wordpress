import { test, expect, whoOn, toTheHub } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { navigated, ssoButton } from '../support/ui';
import { stateOf } from '../support/fixtures';

/**
 * A social address confirmed by mail, from a site of the network.
 *
 * The round trip is the hub's, so the holding screen and the link are the
 * hub's too. The link works only in the browser that went to the provider;
 * opened anywhere else it is expired and links nothing. Opened there, it
 * lands on the site the person came from: the way back travels with the
 * pending sign-in, not with the hub's cookie.
 */

const MOCK = { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } };

/** The link a confirmation mail carries. */
const PENDING_LINK = /https?:\/\/\S*diluxone_users_sso_pending=\S+/;

test('from /beta/, an unconfirmed address waits on the hub; the link is expired elsewhere and signs in back on /beta/ where it waited', async ({
	browser,
	hub,
	beta,
	root,
}) => {
	await hub.set({
		diluxone_e2e_sso: 1,
		diluxone_users_sso: MOCK,
		diluxone_users_sso_login: 1,
		diluxone_users_sso_register: 1,
		diluxone_users_sso_link_by_email: 1,
		diluxone_users_sso_verified_only: 1,
		diluxone_users_login_register: 1,
		diluxone_users_2fa_mode: 'off',
	});

	const email = freshEmail('net-sso-pending');

	// No `email_verified` claim at all.
	await hub.site.setIdentity({ sub: `mock|${email}`, email, given_name: 'Grace' });

	const first = await (await browser.newContext()).newPage();

	await toTheHub(first, beta, hub);
	// The trip runs in a popup; the hub's page moves when it closes.
	await navigated(first, () => ssoButton(first, 'mock').click());
	await first.waitForURL((url) => url.href.startsWith(hub.pages.login.url), { waitUntil: 'domcontentloaded' });

	// C1 on the hub: the holding screen, nothing created.
	expect(stateOf(first.url())).toBe('social-pending');
	await expect(first.locator('.diluxone-users-login__email')).toContainText('•••@');
	expect((await root.user(email)).exists, 'no account until the link is opened').toBe(false);

	const link = linkIn(await waitForMail(hub.site, email), PENDING_LINK);

	expect(link.startsWith(hub.url), 'the link is the hub’s').toBe(true);

	// In another browser: expired, nothing made.
	const second = await (await browser.newContext()).newPage();

	await second.goto(link);
	expect(stateOf(second.url()), 'not the browser that started the trip').toBe('expired');
	expect((await root.user(email)).exists, 'nothing created').toBe(false);

	// C2, in the browser that waits: signed in, and back on /beta/.
	await first.goto(link);
	await first.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

	expect(await whoOn(first, beta.url), 'signed in, from the site they started on').toBe(email);
	expect((await root.user(email)).meta.diluxone_users_sso_mock, 'the identity linked').toBe(`mock|${email}`);

	await first.context().close();
	await second.context().close();
});
