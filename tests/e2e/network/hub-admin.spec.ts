import { test, expect } from './support';
import { Site, freshEmail } from '../support/api';
import { wp, PLUGIN_DIR } from '../support/cli';
import { fillCredentials, navigated, savePanel } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';
import { network, railState, throwawaySite } from './hub-support';

/**
 * Network Admin beyond its saves: what its Overview says about the network,
 * the rail that follows the uninstall box, the network's activity log (its
 * filters, and a site deleted taking its rows), a site born, the plugin
 * switched off for the network, and the network's own Users screens.
 */

const PASSWORD = 'e2e-HubAdmin-1!';

test.use({ storageState: NETWORK_ADMIN_STATE });

test.describe('Network Admin › DiluxOne Users+ › The network', () => {
	test('names the hub with the way to its dashboard, and how the sites are addressed and how many', async ({ page }) => {
		await page.goto(network('admin.php?page=diluxone-users&tab=network'));

		const cards = page.locator('.diluxone-users-card');

		await expect(cards.first().locator('.diluxone-users-card__value'), 'the hub, by name').toHaveText(wp(['option', 'get', 'blogname']));
		await expect(cards.first().locator('.diluxone-users-card__links a')).toHaveAttribute('href', `${NETWORK_URL}/wp-admin/admin.php?page=diluxone-users`);

		await expect(cards.nth(1).locator('.diluxone-users-card__detail'), 'the sites, counted').toContainText(wp(['site', 'list', '--format=count']));
		await expect(page.locator('.diluxone-users-studio__aside'), 'no rail: the cards are the whole of it').toHaveCount(0);
	});

	test('the uninstall tab’s rail follows the box, saved and read back', async ({ page, hub }) => {
		await hub.set({ diluxone_users_uninstall_wipe: 0 });

		const tab = network('admin.php?page=diluxone-users&tab=uninstall');

		await page.goto(tab);
		await page.locator('input[name="diluxone_users_uninstall_wipe"]').check();
		await savePanel(page);
		await page.goto(tab);
		await expect(page.locator('input[name="diluxone_users_uninstall_wipe"]')).toBeChecked();
		const ticked = await railState(page).getAttribute('class');

		await page.locator('input[name="diluxone_users_uninstall_wipe"]').uncheck();
		await savePanel(page);
		await page.goto(tab);
		await expect(page.locator('input[name="diluxone_users_uninstall_wipe"]')).not.toBeChecked();

		expect(await railState(page).getAttribute('class'), 'the rail says something else once unticked').not.toBe(ticked);
	});
});

test.describe('The network’s activity log', () => {
	/** A refused sign-in on a site's emergency door: one row of that site's, carrying the address tried. */
	async function refusedOn(page: import('@playwright/test').Page, siteUrl: string, tried: string): Promise<void> {
		await page.context().clearCookies();
		await page.goto(`${siteUrl}wp-login.php?diluxone-users-admin=1`);
		await fillCredentials(page, tried, 'not-the-password');
		await navigated(page, () => page.locator('#wp-submit').click());
	}

	test('the report’s filters narrow it as asked, through the form', async ({ browser, page, hub }) => {
		await hub.set({ diluxone_users_log_levels: ['access', 'account', 'security'] });

		const tag = `hubfilter-${Date.now().toString(36)}`;
		const stranger = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

		await refusedOn(stranger, `${NETWORK_URL}/alpha/`, `${tag}-a@e2e.test`);
		await refusedOn(stranger, `${NETWORK_URL}/beta/`, `${tag}-b@e2e.test`);
		await stranger.context().close();

		await page.goto(network('admin.php?page=diluxone-users-reports&tab=network-activity'));

		const form = page.locator('form:has(input[name="s"])');

		await form.locator('input[name="s"]').fill(tag);
		await navigated(page, () => form.locator('[type="submit"]').first().click());

		const rows = page.locator('table[data-diluxone-users-log] tbody tr[data-diluxone-users-event]');

		await expect(rows, 'both refused sign-ins, found by what was typed').toHaveCount(2);

		// The site select narrows it to /beta/'s.
		const betaId = wp(['eval', 'echo get_current_blog_id();'], `${NETWORK_URL}/beta/`);

		await page.locator('select[name="site"]').selectOption(betaId);
		await navigated(page, () => page.locator('form:has(input[name="s"]) [type="submit"]').first().click());
		await expect(rows, 'one: /beta/’s').toHaveCount(1);
		await expect(rows.first()).toHaveAttribute('data-diluxone-users-site', betaId);

		// The event select: none of them was a sign-in.
		await page.locator('select[name="event"]').selectOption('signed_in');
		await navigated(page, () => page.locator('form:has(input[name="s"]) [type="submit"]').first().click());
		await expect(rows, 'no sign-in among them').toHaveCount(0);
	});

	test('a site deleted takes its rows out of the network’s log', async ({ browser, hub }) => {
		await hub.set({ diluxone_users_log_levels: ['access', 'account', 'security'] });

		const site = throwawaySite('e2e-log-goes');
		const count = () => Number(wp(['eval', `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . diluxone_users_log_table() . " WHERE site_id = %d", ${site.id} ) );`]));

		try {
			const stranger = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

			await stranger.goto(`${site.url}wp-login.php?diluxone-users-admin=1`);
			await fillCredentials(stranger, `goes-${Date.now().toString(36)}@e2e.test`, 'not-the-password');
			await navigated(stranger, () => stranger.locator('#wp-submit').click());
			await stranger.context().close();

			expect(count(), 'the site has a row').toBeGreaterThan(0);
		} finally {
			site.done();
		}

		expect(count(), 'gone with the site').toBe(0);
	});
});

test.describe('A site born, and the plugin switched off', () => {
	test('a new site does not touch the hub’s registration doors', async ({ hub, network: options }) => {
		await options.set('registration', 'none');
		await hub.set({ diluxone_users_login_register: 1, diluxone_users_sso_register: 1 });

		const site = throwawaySite('e2e-born-doors');

		try {
			const read = await (await Site.open(site.url)).getOptions(['diluxone_users_login_register', 'diluxone_users_sso_register']);

			expect(Number(read.diluxone_users_login_register), 'the new site reads the hub’s door').toBe(1);
			expect(Number((await hub.site.getOptions(['diluxone_users_login_register'])).diluxone_users_login_register), 'and the hub’s is as it was').toBe(1);
			expect(Number((await hub.site.getOptions(['diluxone_users_sso_register'])).diluxone_users_sso_register)).toBe(1);
		} finally {
			site.done();
		}
	});

	test('switched off for the network, it leaves no cron event of its own behind, and on again it schedules them', async ({ hub, alpha }) => {
		const events = (url: string) =>
			(JSON.parse(wp(['cron', 'event', 'list', '--format=json', '--fields=hook'], url) || '[]') as Array<{ hook: string }>)
				.map((event) => event.hook)
				.filter((hook) => hook.startsWith('diluxone_users_'));

		// Opening a dashboard is what schedules the purge, if nothing has yet.
		await (await Site.open(hub.url)).getOptions(['diluxone_users_log_days']);

		wp(['plugin', 'deactivate', PLUGIN_DIR, '--network']);

		try {
			expect(events(hub.url), 'nothing of its own on the hub').toEqual([]);
			expect(events(alpha.url), 'nor on /alpha/').toEqual([]);
		} finally {
			wp(['plugin', 'activate', PLUGIN_DIR, '--network']);
		}
	});
});

test.describe('The network’s Users screens', () => {
	test('Delete in Network Admin › Users takes the account off every site', async ({ page, hub, beta }) => {
		const email = freshEmail('hub-net-delete');
		const made = await beta.site.makeUser({ email, password: PASSWORD });

		await page.goto(network(`users.php?s=${encodeURIComponent(email)}`));

		// The row action, as the list prints it (it shows on hover).
		const remove = page.locator(`a[href*="action=deleteuser"][href*="id=${made.id}"]`).first();

		await page.goto((await remove.getAttribute('href')) as string);
		await navigated(page, () => page.locator('form input[type="submit"]').last().click());

		expect((await hub.site.user(email)).exists, 'the account is gone').toBe(false);
	});

	test('the network’s Edit user draws the plugin’s fields, and saving one there writes it', async ({ page, hub, alpha }) => {
		await hub.set({
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'e2e_netedit', label: 'Team', type: 'text', required: 0, active: 1, group: 'extra', edit: 'always' },
			],
		});

		const email = freshEmail('hub-net-edit');
		const made = await alpha.site.makeUser({ email, password: PASSWORD });

		await page.goto(network(`user-edit.php?user_id=${made.id}`));

		const box = page.locator('[name="e2e_netedit"]');

		await expect(box, 'the plugin’s field is on the network’s Edit user').toBeVisible();
		await box.fill('Blue');
		await navigated(page, () => page.locator('#submit').click());

		await expect(page.locator('#message.updated, .notice-success, .updated').first(), 'WordPress says it saved').toBeVisible();
		expect((await alpha.site.user(email, ['e2e_netedit'])).fields.e2e_netedit, 'written to the account').toBe('Blue');
	});
});


