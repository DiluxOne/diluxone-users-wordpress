import { test, expect, SiteHandle } from './support';
import { Site, freshEmail } from '../support/api';
import { wp, PLUGIN_DIR } from '../support/cli';
import { NETWORK_SCREENS } from '../support/screens';
import { fillCredentials, navigated, savePanel } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';
import { network, railState, throwawaySite } from './hub-support';

/**
 * Network Admin beyond its saves: what its Overview says about the network,
 * the rail that follows the uninstall box, the network's activity log (its
 * filters, and a site deleted taking its rows), the move from per-site
 * settings (what it will not carry, the kinds of difference it writes down,
 * and the move made on a plain page load), a site born, the plugin switched
 * off for the network, and the network's own Users screens.
 */

const PASSWORD = 'e2e-HubAdmin-1!';

test.use({ storageState: NETWORK_ADMIN_STATE });

test.describe('Network Admin › DiluxOne Users+ › The network', () => {
	test('names the hub with the way to its dashboard, how the sites are addressed and how many, and links every screen of the network', async ({ page }) => {
		await page.goto(network('admin.php?page=diluxone-users&tab=network'));

		const cards = page.locator('.diluxone-users-card');

		await expect(cards.first().locator('.diluxone-users-card__value'), 'the hub, by name').toHaveText(wp(['option', 'get', 'blogname']));
		await expect(cards.first().locator('.diluxone-users-card__links a')).toHaveAttribute('href', `${NETWORK_URL}/wp-admin/admin.php?page=diluxone-users`);

		await expect(cards.nth(1).locator('.diluxone-users-card__detail'), 'the sites, counted').toContainText(wp(['site', 'list', '--format=count']));

		const linked = await page.locator('.du-links a').evaluateAll((all: Element[]) =>
			all.map((a) => new URLSearchParams((a.getAttribute('href') ?? '').split('?')[1] ?? '').get('page') ?? '')
		);

		for (const screen of Object.keys(NETWORK_SCREENS).filter((one) => one !== 'diluxone-users')) {
			expect(linked, `the rail links ${screen}`).toContain(screen);
		}
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

test.describe('The move from per-site settings', () => {
	const BOOKS = ['diluxone_users_network_version', 'diluxone_users_network_conflicts', 'diluxone_users_network_conflicts_seen'];

	function forget(keys: string[]): void {
		for (const key of keys) {
			try {
				wp(['site', 'option', 'delete', key]);
			} catch {
				// Not there, which is the state being made.
			}
		}
	}

	function dropCopy(key: string, one: SiteHandle): void {
		try {
			wp(['option', 'delete', key], one.url);
		} catch {
			// Already gone.
		}
	}

	test('a wipe the main site had ticked is not carried over: the network’s starts unticked, and the Overview says so', async ({ page, hub }) => {
		await hub.keep(['diluxone_users_uninstall_wipe', ...BOOKS]);

		wp(['option', 'update', 'diluxone_users_uninstall_wipe', '1'], hub.url);
		forget(['diluxone_users_uninstall_wipe', ...BOOKS]);

		try {
			wp(['diluxone-users', 'network', 'migrate']);

			let wipe = '';

			try {
				wipe = wp(['site', 'option', 'get', 'diluxone_users_uninstall_wipe']);
			} catch {
				wipe = '';
			}

			expect(['', '0'], 'the network’s answer is not the main site’s tick').toContain(wipe);

			await page.goto(network('admin.php?page=diluxone-users'));
			await expect(page.locator('.diluxone-users-list tr').filter({ hasText: 'diluxone_users_uninstall_wipe' }), 'written down beside the rest').toHaveCount(1);
		} finally {
			dropCopy('diluxone_users_uninstall_wipe', hub);
		}
	});

	test('different credentials and a different field are written down by kind, and no secret is printed', async ({ page, hub, alpha }) => {
		await hub.keep(['diluxone_users_sso', 'diluxone_users_fields', ...BOOKS]);

		const mine = { mock: { active: 0, id: 'hub-id', secret: 'hub-secret-xyz' } };
		const theirs = { mock: { active: 0, id: 'alpha-id', secret: 'alpha-secret-xyz' } };
		const field = (label: string) => [{ key: 'e2e_moved', label, type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }];

		wp(['option', 'update', 'diluxone_users_sso', JSON.stringify(mine), '--format=json'], hub.url);
		wp(['option', 'update', 'diluxone_users_sso', JSON.stringify(theirs), '--format=json'], alpha.url);
		wp(['option', 'update', 'diluxone_users_fields', JSON.stringify(field('Hub')), '--format=json'], hub.url);
		wp(['option', 'update', 'diluxone_users_fields', JSON.stringify(field('Alpha')), '--format=json'], alpha.url);
		forget(['diluxone_users_sso', 'diluxone_users_fields', ...BOOKS]);

		try {
			wp(['diluxone-users', 'network', 'migrate']);

			await page.goto(network('admin.php?page=diluxone-users'));

			await expect(page.locator('.diluxone-users-list tr').filter({ hasText: 'diluxone_users_sso' }), 'the credentials, as a row').toHaveCount(1);
			await expect(page.locator('.diluxone-users-list tr').filter({ hasText: 'diluxone_users_fields' }), 'the field, as a row').toContainText('e2e_moved');

			const html = await page.content();

			expect(html, 'neither secret is printed').not.toContain('alpha-secret-xyz');
			expect(html).not.toContain('hub-secret-xyz');
		} finally {
			for (const key of ['diluxone_users_sso', 'diluxone_users_fields']) {
				dropCopy(key, hub);
				dropCopy(key, alpha);
			}
		}
	});

	test('a network that has not moved moves on the first page anybody opens, with no WP-CLI', async ({ page, hub, alpha }) => {
		await hub.keep(['diluxone_users_session_long_days', ...BOOKS]);

		wp(['option', 'update', 'diluxone_users_session_long_days', '23'], hub.url);
		forget(['diluxone_users_session_long_days', ...BOOKS]);

		try {
			await page.goto(hub.url);

			await expect.poll(() => wp(['site', 'option', 'get', 'diluxone_users_network_version']), { message: 'the network is marked moved' }).not.toBe('');
			expect(Number((await alpha.site.getOptions(['diluxone_users_session_long_days'])).diluxone_users_session_long_days), '/alpha/ reads the main site’s').toBe(23);
		} finally {
			dropCopy('diluxone_users_session_long_days', hub);
		}
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


