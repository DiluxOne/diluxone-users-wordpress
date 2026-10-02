import { test, expect, signInFrom, toTheHub } from './support';
import { freshEmail } from '../support/api';
import { expectSoundLayout } from '../support/layout';
import { NETWORK_SCREENS, SITE_SCREENS_ON_A_NETWORK, networkAdminTabs } from '../support/screens';
import { challengeScreen, fillCredentials, navigated, savePanel } from '../support/ui';
import { wp } from '../support/cli';
import { MAPPED_HOST, NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The network's settings, set in Network Admin and nowhere else.
 *
 * On a network where the plugin is on for every site, who gets in and how
 * safely is one decision for all of them: the second step, passkeys,
 * sessions, the proxy, the social sign-in apps, the fields a person has, what
 * the log keeps. Those screens are in Network Admin, they leave every site's
 * menu, and what is saved there is what every site reads. A site's own
 * dashboard cannot change it — not by its screens, and not by a form sent by
 * hand.
 */

const PASSWORD = 'e2e-Network-1!';

test.use({ storageState: NETWORK_ADMIN_STATE });

/** An address of Network Admin. */
const network = (rest: string) => `${NETWORK_URL}/wp-admin/network/${rest.replace(/^\//, '')}`;

/** The plugin's entries in the dashboard menu that is on screen, by slug. */
async function menuOf(page: import('@playwright/test').Page): Promise<string[]> {
	const hrefs = await page.locator('#adminmenu a[href*="page=diluxone-users"]').evaluateAll((all: Element[]) =>
		all.map((a) => a.getAttribute('href') ?? '')
	);

	return [...new Set(hrefs.map((href) => new URLSearchParams(href.split('?')[1] ?? '').get('page') ?? ''))].filter(Boolean);
}

test.describe('Network Admin has the network’s screens', () => {
	test('in its menu: the overview, membership, security, social login, user fields and the activity log, and nothing of a site’s', async ({ page }) => {
		await page.goto(network('admin.php?page=diluxone-users'));

		expect((await menuOf(page)).sort()).toEqual(Object.keys(NETWORK_SCREENS).sort());
	});

	for (const tab of networkAdminTabs()) {
		test(`${tab.name} answers, holds together, and has the tabs it should`, async ({ page }) => {
			const response = await page.goto(`${NETWORK_URL}${tab.url}`);

			expect(response?.status(), 'the screen answers').toBe(200);
			await expect(page.locator('.wrap.diluxone-users-admin')).toBeVisible();

			// Every tab the strip draws is one the registry knows, so a tab
			// added to the network joins this walk the day it is added.
			const drawn = await page.locator('.diluxone-users-admin .nav-tab').evaluateAll((all: Element[]) =>
				all.map((a) => new URLSearchParams((a.getAttribute('href') ?? '').split('?')[1] ?? '').get('tab') ?? '')
			);

			// A screen of one tab draws no strip: one tab is not navigation.
			expect(drawn).toEqual(NETWORK_SCREENS[tab.screen].length > 1 ? NETWORK_SCREENS[tab.screen] : []);

			await expectSoundLayout(page);
		});
	}
});

test.describe('What is saved in Network Admin is every site’s', () => {
	test('the second step required there is asked of whoever signs in from /alpha/ or /beta/', async ({ page, guest, hub, alpha, beta }) => {
		// Written down first, so the fixture puts back what was there.
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['totp', 'email'], diluxone_users_2fa_scope: 'all' });

		await page.goto(network('admin.php?page=diluxone-users-security&tab=2fa'));
		await page.locator('input[name="diluxone_users_2fa_mode"][value="required"]').check();
		await page.locator('input[name="diluxone_users_2fa_methods[]"][value="email"]').check();
		await savePanel(page);

		for (const one of [alpha, beta]) {
			expect((await one.site.getOptions(['diluxone_users_2fa_mode'])).diluxone_users_2fa_mode, `/${one.slug}/ reads it`).toBe('required');

			const email = freshEmail(`net-admin-2fa-${one.slug}`);

			await one.site.makeUser({ email, password: PASSWORD });
			await guest.context().clearCookies();
			await signInFrom(guest, one, hub, email, PASSWORD);
			await expect(challengeScreen(guest), `asked of whoever signs in from /${one.slug}/`).toBeVisible();
		}
	});

	test('the log’s settings saved there are read on every site', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_log_days: 90 });

		await page.goto(network('admin.php?page=diluxone-users-reports&tab=logging'));
		await page.locator('input[name="diluxone_users_log_days"]').fill('45');
		await savePanel(page);

		for (const one of [alpha, beta]) {
			expect(Number((await one.site.getOptions(['diluxone_users_log_days'])).diluxone_users_log_days), `/${one.slug}/`).toBe(45);
		}
	});
});

/**
 * Every tab that moved saves from Network Admin, and every site reads what it
 * saved. One row per tab: what to do on it, and the setting that has to come
 * out the other end.
 */
const SAVES: Array<{
	tab: string;
	key: string;
	before: unknown;
	act: (page: import('@playwright/test').Page) => Promise<void>;
	after: string;
}> = [
	{
		tab: 'diluxone-users-security&tab=sessions',
		key: 'diluxone_users_session_long_days',
		before: 30,
		act: (page) => page.locator('input[name="diluxone_users_session_long_days"]').fill('21'),
		after: '21',
	},
	{
		tab: 'diluxone-users-security&tab=proxy',
		key: 'diluxone_users_trusted_proxies',
		before: '',
		act: (page) => page.locator('textarea[name="diluxone_users_trusted_proxies"]').fill('10.0.0.1'),
		after: '10.0.0.1',
	},
	{
		tab: 'diluxone-users-security&tab=passkeys',
		key: 'diluxone_users_passkey_enabled',
		before: 0,
		act: (page) => page.locator('input[name="diluxone_users_passkey_enabled"]').check(),
		after: '1',
	},
	{
		tab: 'diluxone-users-social&tab=general',
		key: 'diluxone_users_sso_login',
		before: 1,
		act: (page) => page.locator('input[name="diluxone_users_sso_login"]').uncheck(),
		after: '0',
	},
	{
		tab: 'diluxone-users&tab=uninstall',
		key: 'diluxone_users_uninstall_wipe',
		before: 0,
		act: (page) => page.locator('input[name="diluxone_users_uninstall_wipe"]').check(),
		after: '1',
	},
];

test.describe('Every tab that moved saves from Network Admin, for every site', () => {
	for (const one of SAVES) {
		test(`${one.tab} › ${one.key}`, async ({ page, hub, alpha, beta }) => {
			await hub.set({ [one.key]: one.before });

			await page.goto(network(`admin.php?page=${one.tab}`));
			await one.act(page);
			await savePanel(page);

			for (const site of [hub, alpha, beta]) {
				expect(String((await site.site.getOptions([one.key]))[one.key]), `/${site.slug}/`).toBe(one.after);
			}
		});
	}

	test('a user field added in Network Admin is asked for by the registration /alpha/ sends to, and deleted there it is gone', async ({
		page,
		guest,
		hub,
		alpha,
		beta,
	}) => {
		await hub.keep(['diluxone_users_fields']);
		await hub.set({
			diluxone_users_register_form: 1,
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const label = `Team ${Date.now().toString(36)}`;

		await page.goto(network('admin.php?page=diluxone-users-fields&diluxone_users_new=1'));
		await page.locator('[name="diluxone_users_field[label]"]').fill(label);
		await page.locator('[name="diluxone_users_field[type]"]').selectOption('text');
		// Required: the registration form asks only for what is required.
		await page.locator('input[type="checkbox"][name="diluxone_users_field[required]"]').setChecked(true, { force: true });
		await navigated(page, () => page.locator('#submit').click());

		const fields = (await beta.site.getOptions(['diluxone_users_fields'])).diluxone_users_fields as Array<{ key: string; label: string }>;
		const added = fields.find((field) => field.label === label);

		expect(added, 'the field is the network’s: /beta/ reads it').toBeTruthy();

		await toTheHub(guest, alpha, hub, 'register');
		await expect(guest.locator(`[name="${added!.key}"]`), 'asked for of whoever registers from /alpha/').toHaveCount(1);

		await page.goto(network('admin.php?page=diluxone-users-fields'));

		const remove = page.locator(`a[href*="diluxone_users_action=delete"][href*="field=${added!.key}"]`);

		await page.goto((await remove.getAttribute('href'))!.trim());

		await toTheHub(guest, alpha, hub, 'register');
		await expect(guest.locator(`[name="${added!.key}"]`), 'and gone').toHaveCount(0);
	});
});

test.describe('A site’s menu keeps only what is its own', () => {
	test('the main site keeps the screens people sign in on, and not the network’s', async ({ page, hub }) => {
		await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users`);

		expect((await menuOf(page)).sort()).toEqual([...SITE_SCREENS_ON_A_NETWORK.hub].sort());
	});

	test('another site keeps its menus, its reports and its maintenance, and says where the rest went', async ({ page, alpha }) => {
		await page.goto(alpha.admin('admin.php?page=diluxone-users'));

		expect((await menuOf(page)).sort()).toEqual([...SITE_SCREENS_ON_A_NETWORK.site].sort());

		// The Overview names each area that left, and whoever can go there
		// — a super admin, here — gets the way in.
		const elsewhere = page.locator('.diluxone-users-card').filter({ hasText: 'Managed by the network' });

		await expect(elsewhere, 'security, social login, user fields, log settings and membership').toHaveCount(5);
		await expect(elsewhere.first().locator('a')).toHaveAttribute('href', /\/wp-admin\/network\/admin\.php\?page=diluxone-users-/);

		// And each of the main site's screens, with the way to it.
		const onTheHub = page.locator('.diluxone-users-card').filter({ hasText: 'the site where people sign in' });

		await expect(onTheHub).toHaveCount(4);
		await expect(onTheHub.first().locator('a')).toHaveAttribute('href', new RegExp(`^${NETWORK_URL}/wp-admin/admin\\.php\\?page=diluxone-users-`));

		// And its Reports have the rows but not the rules about them — and the
		// way to empty its own rows goes with the rows.
		await page.goto(alpha.admin('admin.php?page=diluxone-users-reports'));
		await expect(page.locator('.nav-tab[href*="tab=logging"]')).toHaveCount(0);
		await page.goto(alpha.admin('admin.php?page=diluxone-users-reports&tab=activity'));
		await expect(page.locator('h3.du-section').filter({ hasText: 'Empty it now' })).toHaveCount(1);

		// Its Tools say who decides about deleting the plugin, and do not ask.
		await page.goto(alpha.admin('admin.php?page=diluxone-users-status&tab=tools'));
		await expect(page.locator('input[name="wipe"]')).toHaveCount(0);
		await expect(page.locator('.du-notice').filter({ hasText: 'Decided for the whole network' })).toHaveCount(1);
	});

	test('a site’s administrator gets the site’s own tools, and none that signs somebody else out', async ({ guest, alpha, hub }) => {
		const email = freshEmail('net-tools');

		await alpha.site.makeUser({ email, password: PASSWORD, role: 'administrator' });
		await signInFrom(guest, alpha, hub, email, PASSWORD);

		// A session is the network's: closing somebody's, or sending a code
		// that voids theirs, is for whoever administers the network's users.
		await guest.goto(alpha.admin('admin.php?page=diluxone-users-status&tab=tools'));
		await expect(guest.locator('input[name="tool"][value="flush"]')).toHaveCount(1);
		await expect(guest.locator('input[name="tool"][value="close"]')).toHaveCount(0);
		await expect(guest.locator('input[name="tool"][value="code"]')).toHaveCount(0);
	});

	test('a network screen asked for by its address on a site is refused', async ({ page, alpha, hub }) => {
		for (const url of [
			alpha.admin('admin.php?page=diluxone-users-security'),
			alpha.admin('admin.php?page=diluxone-users-design'),
			`${hub.url}wp-admin/admin.php?page=diluxone-users-social`,
		]) {
			const response = await page.goto(url);

			expect(response?.status(), url).toBe(403);
		}
	});
});

test.describe('A site cannot change the network’s settings', () => {
	/**
	 * Access › Ways in draws passkeys and social sign-in as they stand — they
	 * are the network's — and a form forced open and sent anyway writes
	 * nothing of them.
	 */
	test('the network’s doors on the main site’s Access are drawn, not saved', async ({ page, hub }) => {
		await hub.set({ diluxone_users_passkey_enabled: 0, diluxone_users_sso_login: 1, diluxone_users_login_method: 'both', diluxone_users_login_expiry: 15 });

		await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-login&tab=ways`);

		const passkey = page.locator('input[name="diluxone_users_passkey_enabled"]');

		await expect(passkey).toBeDisabled();

		await passkey.evaluate((box: HTMLInputElement) => {
			box.disabled = false;
			box.checked = true;
		});
		// The same form carries a setting that is the hub's: that one saves,
		// so the save ran and only the network's part was left out.
		await page.locator('input[name="diluxone_users_login_expiry"]').fill('37');
		await savePanel(page);

		const stored = await hub.site.getOptions(['diluxone_users_passkey_enabled', 'diluxone_users_login_expiry', 'diluxone_users_sso_login']);

		expect(Number(stored.diluxone_users_passkey_enabled), 'a box forced on and sent from a site’s screen').toBe(0);
		expect(Number(stored.diluxone_users_login_expiry), 'the hub’s own setting, in the same form, saved').toBe(37);
		expect(Number(stored.diluxone_users_sso_login), 'and the network’s other door left as it was').toBe(1);
	});
});

test.describe('A site on a domain of its own', () => {
	/**
	 * A site of the network moved to a domain that is neither the network's
	 * nor a subdomain of it — what a domain-mapping setup writes into
	 * wp_blogs. A session opened on the hub does not reach it, and that is not
	 * supported in this version: the network is told on its Overview, and the
	 * site's administrator on its own dashboard. The browser reaches the
	 * domain through the resolver rule in playwright.network.config.ts.
	 */
	test('is named on the network’s Overview and warned on its own dashboard', async ({ page, browser }) => {
		const port = new URL(NETWORK_URL).port;
		const domain = `${MAPPED_HOST}${port ? `:${port}` : ''}`;
		const home = `http://${domain}/`;
		const id = wp(['site', 'create', `--slug=e2e-mapped-${Date.now().toString(36)}`, '--title=Mapped', '--porcelain']);

		try {
			// Through WordPress, so the network's list of domains hears about it.
			wp(['eval', `wp_update_site( ${id}, array( 'domain' => '${domain}', 'path' => '/' ) ); update_blog_option( ${id}, 'home', '${home.replace(/\/$/, '')}' ); update_blog_option( ${id}, 'siteurl', '${home.replace(/\/$/, '')}' );`]);

			await page.goto(network('admin.php?page=diluxone-users'));
			await expect(page.locator('.du-notice').filter({ hasText: domain }).first(), 'the Overview names it').toBeVisible();

			// Its own dashboard: signed in again, on its own wp-login.php — a
			// session on the network's domain does not arrive here.
			const own = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

			await own.goto(`${home}wp-login.php`);
			expect(new URL(own.url()).host, 'its wp-login.php stays its own').toBe(domain);

			await fillCredentials(own, process.env.WP_USER ?? 'admin', process.env.WP_PASS ?? 'password');
			await Promise.all([own.waitForURL(/wp-admin/), own.locator('#wp-submit').click()]);
			await own.goto(`${home}wp-admin/`);

			const warning = own.locator('[data-diluxone-users-mapped]');

			await expect(warning, 'the site’s administrator is told').toBeVisible();
			await expect(warning).toContainText(MAPPED_HOST);
			await own.context().close();

			// A site on the network's own domain is not warned.
			await page.goto(`${NETWORK_URL}/alpha/wp-admin/`);
			await expect(page.locator('[data-diluxone-users-mapped]')).toHaveCount(0);
		} finally {
			wp(['site', 'delete', id, '--yes']);
		}
	});
});
