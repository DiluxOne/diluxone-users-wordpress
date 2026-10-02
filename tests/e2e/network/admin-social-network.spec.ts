import type { Page } from '@playwright/test';
import { test, expect, toTheHub, whoOn, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { answeringDialog, navigated, savePanel, ssoButton } from '../support/ui';
import { closeTheTest, networkScreen, rail, stateIn } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * Social login in Network Admin: a provider set up, tested and switched on
 * and off there, and the rules saved there — read back through a site's side
 * door (the network's value every site reads) and seen on the hub's sign-in
 * page, which is where every site's door leads.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const SOCIAL = 'diluxone-users-social';
const ID = 'e2e-net-client-id';
const SECRET = 'e2e-net-client-secret';
const PASSWORD = 'e2e-Net-Social-1!';

type Stored = { active?: number; id?: string; secret?: string; tested?: number };

const provider = (tab = '') => networkScreen(NETWORK_URL, SOCIAL, undefined, tab ? { provider: 'mock', tab } : { provider: 'mock' });
const grid = () => networkScreen(NETWORK_URL, SOCIAL);

async function stored(one: SiteHandle): Promise<Stored | undefined> {
	return ((await one.site.getOptions(['diluxone_users_sso'])).diluxone_users_sso as Record<string, Stored> | null)?.mock;
}

function card(page: Page) {
	return page.locator('.diluxone-users-card').filter({ has: page.locator('a[href*="provider=mock"]') });
}

test.describe('Network Admin › Social login › a provider', () => {
	test.beforeEach(async ({ hub, alpha, beta }) => {
		// The fake network is a row this file's mu-plugin adds on each site
		// that asks: the hub draws the buttons and runs the round trip.
		for (const one of [hub, alpha, beta]) {
			await one.set({ diluxone_e2e_sso: 1 });
		}

		await hub.set({ diluxone_users_sso: {}, diluxone_users_sso_login: 1, diluxone_users_sso_register: 1, diluxone_users_2fa_mode: 'off', diluxone_users_login_method: 'both' });
	});

	test('Getting started gives the hub’s redirect URL; Settings saves the credentials for every site and never prints the secret back', async ({
		page,
		alpha,
	}) => {
		await page.goto(provider());
		expect(await page.locator('input[name="diluxone_users_redirect_uri"]').inputValue()).toBe(`${NETWORK_URL}/sso/mock/`);

		await page.goto(provider('settings'));
		await page.locator('input[name="diluxone_users_client_id"]').fill(ID);
		await page.locator('input[name="diluxone_users_client_secret"]').fill(SECRET);
		await savePanel(page);

		expect(await stored(alpha), '/alpha/ reads the network’s credentials').toEqual({ active: 0, id: ID, secret: SECRET, tested: 0 });
		await expect(page.locator('input[name="diluxone_users_client_secret"]')).toHaveValue('');
		expect(await page.content()).not.toContain(SECRET);

		// Saved again with the box empty: kept.
		await page.goto(provider('settings'));
		await savePanel(page);
		expect((await stored(alpha))?.secret).toBe(SECRET);
	});

	test('the live test from Network Admin goes round through the hub, marks it tested, and turning it on puts the button on the hub’s sign-in', async ({
		page,
		guest,
		hub,
		alpha,
	}) => {
		await hub.set({ diluxone_users_sso: { mock: { active: 0, id: ID, secret: SECRET, tested: 0 } } });
		await hub.site.setIdentity({ sub: 'mock|net-admin-test', email: freshEmail('net-sso-test'), email_verified: true });

		await page.goto(provider());

		const [popup] = await Promise.all([page.waitForEvent('popup'), page.locator('a[data-diluxone-users-popup][target="diluxone-users-test"]').click()]);

		await expect(popup.locator('.diluxone-users-sso-test.is-ok')).toBeVisible();
		expect(new URL(popup.url()).origin + new URL(popup.url()).pathname).toBe(`${NETWORK_URL}/sso/mock/`);
		expect((await stored(alpha))?.tested).toBe(1);

		await Promise.all([popup.waitForEvent('close'), page.waitForEvent('load'), closeTheTest(popup)]);
		expect(await stateIn(rail(page))).toBe('off');

		// "Turn it on", from the provider's own screen in Network Admin.
		await navigated(page, () => page.locator('a.button-primary[href*="diluxone_users_action=on"]').click());
		expect(new URL(page.url()).pathname).toContain('/wp-admin/network/');
		expect((await stored(alpha))?.active).toBe(1);

		// From /alpha/'s door, the hub's page, with the button.
		await toTheHub(guest, alpha, hub);
		await expect(ssoButton(guest, 'mock')).toBeVisible();
	});

	test('changed credentials there take the button down on every site', async ({ page, guest, hub, beta }) => {
		await hub.set({ diluxone_users_sso: { mock: { active: 1, id: ID, secret: SECRET, tested: 1 } } });

		await toTheHub(guest, beta, hub);
		await expect(ssoButton(guest, 'mock')).toBeVisible();

		await page.goto(provider('settings'));
		await page.locator('input[name="diluxone_users_client_id"]').fill('another-net-id');
		await savePanel(page);

		expect(await stored(beta)).toMatchObject({ tested: 0, active: 0, id: 'another-net-id' });
		await toTheHub(guest, beta, hub);
		await expect(ssoButton(guest, 'mock')).toHaveCount(0);
	});

	test('the grid’s “Turn it off” asks first; “Delete its settings” asks first and leaves the provider unconfigured for every site', async ({
		page,
		guest,
		hub,
		alpha,
	}) => {
		await hub.set({ diluxone_users_sso: { mock: { active: 1, id: ID, secret: SECRET, tested: 1 } } });

		await page.goto(grid());
		expect(await stateIn(card(page))).toBe('active');

		const off = card(page).locator('a[href*="diluxone_users_action=off"]');

		await expect(off).toHaveAttribute('href', /\/wp-admin\/network\/admin\.php/);
		await answeringDialog(page, 'dismiss', () => off.click());
		expect((await stored(alpha))?.active, 'no').toBe(1);
		await answeringDialog(page, 'accept', () => navigated(page, () => off.click()));
		expect((await stored(alpha))?.active, 'yes').toBe(0);

		await toTheHub(guest, alpha, hub);
		await expect(ssoButton(guest, 'mock')).toHaveCount(0);

		await page.goto(provider());

		const forget = page.locator('a.diluxone-users-admin__forget[data-diluxone-users-confirm]');

		await answeringDialog(page, 'dismiss', () => forget.click());
		expect(await stored(alpha), 'no').toBeTruthy();
		await answeringDialog(page, 'accept', () => navigated(page, () => forget.click()));

		expect(await stored(alpha), 'the provider’s settings are gone').toBeUndefined();
		expect(new URL(page.url()).searchParams.get('provider'), 'back on the grid').toBeNull();
		expect(new URL(page.url()).pathname).toContain('/wp-admin/network/');
		expect(await stateIn(card(page))).toBe('pending');
	});
});

test.describe('Network Admin › Social login › Rules', () => {
	test.beforeEach(async ({ hub, alpha, beta }) => {
		for (const one of [hub, alpha, beta]) {
			await one.set({ diluxone_e2e_sso: 1 });
		}

		await hub.keep(['diluxone_users_sso_link_by_email', 'diluxone_users_sso_verified_only', 'diluxone_users_sso_scope', 'diluxone_users_sso_roles']);
		await hub.set({
			diluxone_users_sso: { mock: { active: 1, id: ID, secret: SECRET, tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_sso_scope: 'all',
			diluxone_users_2fa_mode: 'off',
			diluxone_users_login_method: 'both',
		});
	});

	test('“link by e-mail” unticked there: a member of /beta/ coming back from the network with their address is turned away; ticked, let in', async ({
		page,
		guest,
		hub,
		beta,
	}) => {
		await page.goto(networkScreen(NETWORK_URL, SOCIAL, 'general'));
		await page.locator('input[name="diluxone_users_sso_link_by_email"]').uncheck();
		await savePanel(page);
		expect(Number((await beta.site.getOptions(['diluxone_users_sso_link_by_email'])).diluxone_users_sso_link_by_email)).toBe(0);

		const email = freshEmail('net-rules');

		await beta.site.makeUser({ email, password: PASSWORD });
		await hub.site.setIdentity({ sub: 'mock|net-rules', email, email_verified: true });

		await toTheHub(guest, beta, hub);
		await navigated(guest, () => ssoButton(guest, 'mock').click());
		expect(await whoOn(guest, beta.url)).toBeNull();
		expect((await beta.site.user(email)).meta.diluxone_users_sso_mock).toBe('');

		await page.goto(networkScreen(NETWORK_URL, SOCIAL, 'general'));
		await page.locator('input[name="diluxone_users_sso_link_by_email"]').check();
		await savePanel(page);

		await toTheHub(guest, beta, hub);
		await navigated(guest, () => ssoButton(guest, 'mock').click());
		expect(await whoOn(guest, beta.url)).toBe(email);
	});

	test('“refuse when the e-mail is not said to be verified” saved there: a provider that says nothing creates nobody from /alpha/', async ({
		page,
		guest,
		hub,
		alpha,
	}) => {
		await page.goto(networkScreen(NETWORK_URL, SOCIAL, 'general'));
		await page.locator('input[name="diluxone_users_sso_verified_only"]').check();
		await savePanel(page);
		expect(Number((await alpha.site.getOptions(['diluxone_users_sso_verified_only'])).diluxone_users_sso_verified_only)).toBe(1);

		const email = freshEmail('net-rules-silent');

		await hub.site.setIdentity({ sub: 'mock|net-rules-silent', email });
		await toTheHub(guest, alpha, hub);
		await navigated(guest, () => ssoButton(guest, 'mock').click());

		expect(await whoOn(guest, alpha.url)).toBeNull();
		expect((await hub.site.user(email)).exists, 'no account').toBe(false);
	});

	test('“only some roles” saved there: an editor of /alpha/ with the network linked is sent to the other ways in', async ({
		page,
		guest,
		hub,
		alpha,
		beta,
	}) => {
		await page.goto(networkScreen(NETWORK_URL, SOCIAL, 'general'));
		await page.locator('input[name="diluxone_users_sso_scope"][value="some"]').check();

		for (const box of await page.locator('input[name="diluxone_users_sso_roles[]"]').all()) {
			await box.uncheck();
		}

		await page.locator('input[name="diluxone_users_sso_roles[]"][value="subscriber"]').check();
		await savePanel(page);
		expect(await alpha.site.getOptions(['diluxone_users_sso_scope', 'diluxone_users_sso_roles'])).toEqual({
			diluxone_users_sso_scope: 'some',
			diluxone_users_sso_roles: ['subscriber'],
		});

		const email = freshEmail('net-rules-ed');

		await alpha.site.makeUser({ email, password: PASSWORD, role: 'editor', meta: { diluxone_users_sso_mock: 'mock|net-rules-ed' } });
		await hub.site.setIdentity({ sub: 'mock|net-rules-ed', email, email_verified: true });

		await toTheHub(guest, alpha, hub);
		await navigated(guest, () => ssoButton(guest, 'mock').click());

		const elsewhere = JSON.stringify({ hub: (await hub.site.user(email)).roles, beta: (await beta.site.user(email)).roles });

		expect(
			await whoOn(guest, alpha.url),
			`bug: includes/options.php:487 reads an "only these roles may" list against the roles of every site, so an editor of /alpha/ who is a subscriber elsewhere (${elsewhere}) comes in through the network the list was meant to keep editors off`
		).toBeNull();
	});
});
