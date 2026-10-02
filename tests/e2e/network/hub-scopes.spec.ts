import { readFileSync } from 'node:fs';
import { Page } from '@playwright/test';
import { test, expect, signInFrom, toTheHub, SiteHandle } from './support';
import { freshEmail } from '../support/api';
import { adminSaved, challengeScreen, navigated, saveButton, savePanel, signOut } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';
import { network } from './hub-support';

/**
 * Who writes what, from where: the network's settings saved through their own
 * controls in Network Admin — the ones the other specs only ever set through
 * the side door — and read by every site; a site's own settings saved on that
 * site and staying there; the settings file of a site carrying only what is
 * the site's; and the screens of the network and the hub asked for by their
 * address where they do not belong.
 */

const PASSWORD = 'e2e-HubScopes-1!';

test.use({ storageState: NETWORK_ADMIN_STATE });

/** Every site of the suite reads `key` as `value`. */
async function everySiteReads(sites: SiteHandle[], key: string, value: unknown): Promise<void> {
	for (const one of sites) {
		expect((await one.site.getOptions([key]))[key], `/${one.slug}/ reads ${key}`).toEqual(value);
	}
}

/**
 * One control of a Network Admin tab, changed the way a person changes it,
 * and what every site reads afterwards.
 */
const CONTROLS: Array<{
	tab: string;
	keys: Record<string, unknown>;
	act: (page: Page) => Promise<void>;
	after: Record<string, unknown>;
}> = [
	{
		tab: 'diluxone-users-security&tab=2fa',
		keys: { diluxone_users_2fa_remember_days: 30 },
		act: (page) => page.locator('input[name="diluxone_users_2fa_remember_days"]').fill('7'),
		after: { diluxone_users_2fa_remember_days: 7 },
	},
	{
		tab: 'diluxone-users-security&tab=2fa',
		keys: { diluxone_users_2fa_link: 'auto' },
		act: (page) => page.locator('input[name="diluxone_users_2fa_link"][value="never"]').check(),
		after: { diluxone_users_2fa_link: 'never' },
	},
	{
		tab: 'diluxone-users-security&tab=passkeys',
		keys: { diluxone_users_passkey_where: 'any', diluxone_users_passkey_verify: 1 },
		act: async (page) => {
			await page.locator('input[name="diluxone_users_passkey_where"][value="device"]').check();
			await page.locator('input[name="diluxone_users_passkey_verify"]').uncheck();
		},
		after: { diluxone_users_passkey_where: 'device', diluxone_users_passkey_verify: 0 },
	},
	{
		tab: 'diluxone-users-security&tab=sessions',
		keys: { diluxone_users_session_short_days: 0, diluxone_users_sessions_show: 1 },
		act: async (page) => {
			await page.locator('input[name="diluxone_users_session_short_days"]').fill('3');
			await page.locator('input[name="diluxone_users_sessions_show"]').uncheck();
		},
		after: { diluxone_users_session_short_days: 3, diluxone_users_sessions_show: 0 },
	},
	{
		tab: 'diluxone-users-security&tab=proxy',
		keys: { diluxone_users_ip_header: '', diluxone_users_trusted_proxies: '' },
		act: async (page) => {
			await page.locator('textarea[name="diluxone_users_trusted_proxies"]').fill('10.0.0.9');
			await page.locator('select[name="diluxone_users_ip_header"]').selectOption('HTTP_X_REAL_IP');
		},
		after: { diluxone_users_ip_header: 'HTTP_X_REAL_IP' },
	},
	{
		tab: 'diluxone-users-social&tab=general',
		keys: { diluxone_users_sso_link_by_email: 1, diluxone_users_sso_verified_only: 0, diluxone_users_sso_scope: 'all', diluxone_users_sso_roles: [] },
		act: async (page) => {
			await page.locator('input[name="diluxone_users_sso_link_by_email"]').uncheck();
			await page.locator('input[name="diluxone_users_sso_verified_only"]').check();
			await page.locator('input[name="diluxone_users_sso_scope"][value="some"]').check();
			await page.locator('input[name="diluxone_users_sso_roles[]"][value="subscriber"]').check();
		},
		after: {
			diluxone_users_sso_link_by_email: 0,
			diluxone_users_sso_verified_only: 1,
			diluxone_users_sso_scope: 'some',
			diluxone_users_sso_roles: ['subscriber'],
		},
	},
];

test.describe('The network’s settings, saved through their controls, are every site’s', () => {
	for (const one of CONTROLS) {
		test(`${one.tab} › ${Object.keys(one.after).join(', ')}`, async ({ page, hub, alpha, beta }) => {
			await hub.set(one.keys);

			await page.goto(network(`admin.php?page=${one.tab}`));
			await one.act(page);
			await savePanel(page);

			// The screen shows it back, and every site reads it.
			await page.reload();

			for (const [key, value] of Object.entries(one.after)) {
				const stored = (await hub.site.getOptions([key]))[key];

				expect(typeof value === 'number' ? Number(stored) : stored, `the network stored ${key}`).toEqual(value);

				for (const site of [alpha, beta]) {
					const read = (await site.site.getOptions([key]))[key];

					expect(typeof value === 'number' ? Number(read) : read, `/${site.slug}/ reads ${key}`).toEqual(value);
				}
			}
		});
	}

	test('the second step for chosen roles, saved there: an editor of /beta/ is asked, a subscriber of /beta/ is not', async ({
		page,
		guest,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['totp', 'email'], diluxone_users_2fa_scope: 'all', diluxone_users_2fa_roles: [] });

		await page.goto(network('admin.php?page=diluxone-users-security&tab=2fa'));
		await page.locator('input[name="diluxone_users_2fa_mode"][value="required"]').check();
		await page.locator('input[name="diluxone_users_2fa_scope"][value="some"]').check();
		await page.locator('input[name="diluxone_users_2fa_roles[]"][value="editor"]').check();
		await savePanel(page);

		await everySiteReads([hub, alpha, beta], 'diluxone_users_2fa_scope', 'some');
		await everySiteReads([hub, alpha, beta], 'diluxone_users_2fa_roles', ['editor']);

		const editor = freshEmail('hub-2fa-editor');
		const subscriber = freshEmail('hub-2fa-sub');

		await beta.site.makeUser({ email: editor, password: PASSWORD, role: 'editor' });
		await beta.site.makeUser({ email: subscriber, password: PASSWORD, role: 'subscriber' });

		await signInFrom(guest, beta, hub, editor, PASSWORD);
		await expect(challengeScreen(guest), 'an editor of /beta/ is asked').toBeVisible();

		await signOut(guest);
		await signInFrom(guest, beta, hub, subscriber, PASSWORD);
		await expect(challengeScreen(guest), 'a subscriber is not').toHaveCount(0);
		expect(new URL(guest.url()).href.startsWith(beta.url), 'and is back on /beta/').toBe(true);
	});

	test('the log’s groups, saved there: a group unticked records nothing on any site', async ({ page, guest, hub, alpha }) => {
		await hub.set({ diluxone_users_log_levels: ['access'] });

		await page.goto(network('admin.php?page=diluxone-users-reports&tab=logging'));
		await page.locator('input[name="diluxone_users_log_levels[]"][value="access"]').uncheck();
		await page.locator('input[name="diluxone_users_log_levels[]"][value="account"]').check();
		await savePanel(page);

		await everySiteReads([hub, alpha], 'diluxone_users_log_levels', ['account']);

		const email = freshEmail('hub-levels');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await signInFrom(guest, alpha, hub, email, PASSWORD);

		await page.goto(network(`admin.php?page=diluxone-users-reports&tab=network-activity&s=${encodeURIComponent(email)}`));
		await expect(page.locator('table[data-diluxone-users-log] tbody tr[data-diluxone-users-event="signed_in"]'), 'no sign-in recorded').toHaveCount(0);
	});

	test('a field edited and moved there is what every site reads and what the registration asks', async ({ page, guest, hub, alpha, beta }) => {
		await hub.set({
			diluxone_users_register_form: 1,
			diluxone_users_login_register: 1,
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const label = `Given ${Date.now().toString(36)}`;

		await page.goto(network('admin.php?page=diluxone-users-fields&field=first_name'));
		await page.locator('[name="diluxone_users_field[label]"]').fill(label);
		await page.locator('input[type="checkbox"][name="diluxone_users_field[required]"]').setChecked(true, { force: true });
		await navigated(page, () => page.locator('#submit').click());

		// Moved below the last name with the list's own arrow.
		await page.goto(network('admin.php?page=diluxone-users-fields'));
		await navigated(page, () => page.locator('a[href*="field=first_name"][href*="diluxone_users_action=down"]').first().click());

		const fields = (await beta.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; label: string; required: number }>;
		const first = fields.find((field) => field.key === 'first_name');

		expect(first?.label, '/beta/ reads the new label').toBe(label);
		expect(Number(first?.required), 'and that it is required').toBe(1);
		expect(fields.map((field) => field.key), 'and the new order').toEqual(['last_name', 'first_name']);

		await toTheHub(guest, alpha, hub, 'register');

		const asked = guest.locator('[name="first_name"]');

		await expect(asked, 'the registration /alpha/ sends to asks for it').toHaveCount(1);
		await expect(asked, 'as required').toHaveAttribute('required', /.*/);
	});
});

test.describe('A provider set up in Network Admin', () => {
	test('credentials saved, the secret never printed back, an empty box keeps it, the live test passed and the button on for every site', async ({
		page,
		guest,
		hub,
		beta,
	}) => {
		await hub.set({ diluxone_e2e_sso: 1, diluxone_users_sso: {}, diluxone_users_sso_login: 1 });

		const screen = network('admin.php?page=diluxone-users-social&provider=mock&tab=settings');

		await page.goto(screen);
		await page.locator('[name="diluxone_users_client_id"]').fill('e2e-net-client');
		await page.locator('[name="diluxone_users_client_secret"]').fill('e2e-net-secret');
		await Promise.all([
			page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document'),
			saveButton(page).click(),
		]);
		await expect(adminSaved(page)).toBeVisible();

		let stored = (await beta.site.getOptions(['diluxone_users_sso'])).diluxone_users_sso as Record<string, Record<string, unknown>>;

		expect(stored.mock?.id, '/beta/ reads the network’s credentials').toBe('e2e-net-client');
		expect(stored.mock?.secret).toBe('e2e-net-secret');
		expect(await page.content(), 'the secret is never printed back').not.toContain('e2e-net-secret');

		// An empty secret box is "leave it as it is".
		await page.goto(screen);
		await page.locator('[name="diluxone_users_client_secret"]').fill('');
		await Promise.all([
			page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document'),
			saveButton(page).click(),
		]);

		stored = (await hub.site.getOptions(['diluxone_users_sso'])).diluxone_users_sso as Record<string, Record<string, unknown>>;
		expect(stored.mock?.secret, 'kept').toBe('e2e-net-secret');
		expect(Number(stored.mock?.active), 'not on: never tested').toBe(0);

		// The live test, in its own window, against the e2e provider — which
		// answers with somebody, as a real one would.
		await hub.set({ diluxone_e2e_identity: { sub: `net-test-${Date.now()}`, email: freshEmail('hub-sso-test'), email_verified: true } });
		await page.goto(network('admin.php?page=diluxone-users-social&provider=mock'));

		const [popup] = await Promise.all([page.waitForEvent('popup'), page.locator('a[data-diluxone-users-popup]').first().click()]);

		await popup.waitForLoadState('domcontentloaded');
		await expect.poll(async () => Number(((await hub.site.getOptions(['diluxone_users_sso'])).diluxone_users_sso as Record<string, Record<string, unknown>>).mock?.tested), {
			message: 'the round trip marked it tested',
		}).toBe(1);
		await popup.close().catch(() => undefined);

		// Turned on from its own screen.
		await page.goto(network('admin.php?page=diluxone-users-social&provider=mock'));
		await navigated(page, () => page.locator('a.button-primary[href*="diluxone_users_action=on"]').click());

		stored = (await beta.site.getOptions(['diluxone_users_sso'])).diluxone_users_sso as Record<string, Record<string, unknown>>;
		expect(Number(stored.mock?.active), 'on, for every site').toBe(1);

		await toTheHub(guest, beta, hub);
		await expect(guest.locator('a.diluxone-users-social--mock'), 'the button on the hub’s sign-in page /beta/ sends to').toHaveCount(1);
	});
});

test.describe('A site’s own settings stay its own', () => {
	test('the menu and the dashboard rules saved on /alpha/ are /alpha/’s; /beta/ and the hub keep theirs', async ({ page, hub, alpha, beta }) => {
		const keys = ['diluxone_users_menu_location', 'diluxone_users_menu_style', 'diluxone_users_bar_account', 'diluxone_users_admin_bar'];

		await alpha.keep(keys);
		await beta.keep(keys);
		await hub.keep(keys);

		const menu = await alpha.site.menu();

		try {
			const before = { beta: await beta.site.getOptions(keys), hub: await hub.site.getOptions(keys) };

			await page.goto(alpha.admin('admin.php?page=diluxone-users-account&tab=menu'));
			await page.locator('select[name="diluxone_users_menu_location"]').selectOption(menu.location);
			await page.locator('select[name="diluxone_users_menu_style"]').selectOption('name');
			await savePanel(page);

			await page.goto(alpha.admin('admin.php?page=diluxone-users-account&tab=dashboard'));
			await page.locator('input[name="diluxone_users_bar_account"]').check();
			await page.locator('input[name="diluxone_users_admin_bar"][value="hide-all"]').check();
			await savePanel(page);

			const mine = await alpha.site.getOptions(keys);

			expect(mine.diluxone_users_menu_location).toBe(menu.location);
			expect(mine.diluxone_users_menu_style).toBe('name');
			expect(Number(mine.diluxone_users_bar_account)).toBe(1);
			expect(mine.diluxone_users_admin_bar).toBe('hide');

			expect(await beta.site.getOptions(keys), '/beta/ keeps its own').toEqual(before.beta);
			expect(await hub.site.getOptions(keys), 'the hub keeps its own').toEqual(before.hub);

			// And the menu shows on /alpha/'s page, drawn by /alpha/'s setting.
			await page.context().clearCookies();
			await page.goto(menu.url);
			await expect(page.locator('.diluxone-users-menu--sign-in, [class*="diluxone-users-menu"]').first(), 'the sign-in item on /alpha/').toBeAttached();
		} finally {
			await alpha.site.forgetMenu();
		}
	});

	test('/alpha/’s settings file carries /alpha/’s settings only, and one restored there writes nothing of the network’s or the hub’s', async ({
		page,
		hub,
		alpha,
	}) => {
		const network = ['diluxone_users_2fa_mode', 'diluxone_users_fields', 'diluxone_users_sso', 'diluxone_users_membership'];
		const hubKeys = ['diluxone_users_login_method', 'diluxone_users_login_title', 'diluxone_users_account_page'];

		await alpha.keep(['diluxone_users_menu_style']);
		await alpha.set({ diluxone_users_menu_style: 'avatar' });

		await page.goto(alpha.admin('admin.php?page=diluxone-users-status&tab=tools'));

		const [download] = await Promise.all([
			page.waitForEvent('download'),
			page.locator('form:has(input[name="tool"][value="export"]) [type="submit"]').click(),
		]);
		const file = JSON.parse(readFileSync((await download.path()) as string, 'utf8')) as { settings: Record<string, unknown> };

		expect(file.settings.diluxone_users_menu_style, 'the site’s own setting is in it').toBe('avatar');

		for (const key of [...network, ...hubKeys]) {
			expect(Object.keys(file.settings), `${key} is not /alpha/’s to export`).not.toContain(key);
		}

		// A file that carries everything: only the site's part is written.
		const before = await hub.site.getOptions([...network, ...hubKeys]);
		const forged = {
			settings: {
				diluxone_users_menu_style: 'name',
				diluxone_users_2fa_mode: 'off',
				diluxone_users_membership: 'invite',
				diluxone_users_login_method: 'link',
				diluxone_users_login_title: 'Hacked',
			},
		};

		await page.goto(alpha.admin('admin.php?page=diluxone-users-status&tab=tools'));

		const form = page.locator('form:has(input[name="tool"][value="import"])');

		await form.locator('input[type="file"]').setInputFiles({ name: 'everything.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(forged)) });
		await navigated(page, () => form.locator('[type="submit"]').click());

		await expect(adminSaved(page)).toContainText('1');
		expect((await alpha.site.getOptions(['diluxone_users_menu_style'])).diluxone_users_menu_style, '/alpha/’s own: written').toBe('name');
		expect(await hub.site.getOptions([...network, ...hubKeys]), 'the network’s and the hub’s: untouched').toEqual(before);
	});

	test('the people tools pressed by a super admin on /alpha/ reach the whole network: a session on /beta/ is closed', async ({ page, browser, hub, beta }) => {
		const email = freshEmail('hub-tools-close');

		await beta.site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const them = await context.newPage();

		try {
			await signInFrom(them, beta, hub, email, PASSWORD);
			expect((await beta.site.user(email)).sessions, 'a session').toBeGreaterThan(0);

			await page.goto(`${NETWORK_URL}/alpha/wp-admin/admin.php?page=diluxone-users-status&tab=tools`);

			const form = page.locator('form:has(input[name="tool"][value="close"])');

			await form.locator('input[name="close_email"]').fill(email);
			await navigated(page, () => form.locator('[type="submit"]').click());

			await expect(adminSaved(page)).toBeVisible();
			expect((await beta.site.user(email)).sessions, 'every session of theirs closed').toBe(0);

			const back = await them.request.get(`${beta.url}wp-admin/profile.php`, { maxRedirects: 0 });

			expect(back.status(), 'their browser is signed out on /beta/ too').toBe(302);
		} finally {
			await context.close();
		}
	});
});

test.describe('A screen asked for by its address where it does not belong is refused', () => {
	for (const [label, url] of [
		['Access on /alpha/', '/alpha/wp-admin/admin.php?page=diluxone-users-login'],
		['Notices on /alpha/', '/alpha/wp-admin/admin.php?page=diluxone-users-notices'],
		['Fields on /alpha/', '/alpha/wp-admin/admin.php?page=diluxone-users-fields'],
		['Fields on the hub', '/wp-admin/admin.php?page=diluxone-users-fields'],
		['Security on the hub', '/wp-admin/admin.php?page=diluxone-users-security'],
		['Membership on the hub', '/wp-admin/admin.php?page=diluxone-users-membership'],
	]) {
		test(`${label}`, async ({ page }) => {
			const response = await page.goto(`${NETWORK_URL}${url}`);

			expect(response?.status(), `${label}: refused by WordPress, not drawn and not a fatal`).toBe(403);
		});
	}

	test('a site’s plain administrator sees where the network’s screens are, with no way into Network Admin', async ({ browser, hub, alpha }) => {
		const email = freshEmail('hub-plain-admin');

		await alpha.site.makeUser({ email, password: PASSWORD, role: 'administrator' });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const page = await context.newPage();

		try {
			await signInFrom(page, alpha, hub, email, PASSWORD);
			await page.goto(alpha.admin('admin.php?page=diluxone-users'));

			const cards = page.locator('.diluxone-users-card');

			await expect(cards.first(), 'the Overview draws its cards').toBeVisible();
			await expect(page.locator('.diluxone-users-card a[href*="/wp-admin/network/"]'), 'no link into Network Admin').toHaveCount(0);
		} finally {
			await context.close();
		}
	});
});
