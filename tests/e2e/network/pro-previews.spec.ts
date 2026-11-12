import { test, expect } from './support';
import { networkScreen, siteScreen } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The six settings that became Users+ Pro's (#188), on a network: the
 * network's in Network Admin, the hub's on the main site's dashboard. The
 * network's previews setting draws them all, or none.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

/** Each preview, and where it is drawn on a network. */
function moved(hubUrl: string): Array<[string, string]> {
	return [
		['security/2fa/link', networkScreen(NETWORK_URL, 'diluxone-users-security', '2fa')],
		['social/general/roles', networkScreen(NETWORK_URL, 'diluxone-users-social', 'general')],
		['fields/missing/ask', networkScreen(NETWORK_URL, 'diluxone-users-fields', 'missing')],
		['fields/missing/skip', networkScreen(NETWORK_URL, 'diluxone-users-fields', 'missing')],
		['account/handle/cooldown', siteScreen(hubUrl, 'diluxone-users-account', 'handle')],
		['account/handle/locked', siteScreen(hubUrl, 'diluxone-users-account', 'handle')],
		['design/brand/theme', siteScreen(hubUrl, 'diluxone-users-design', 'brand')],
	];
}

test('the network’s previews setting draws the six where their settings were, or none of them', async ({ page, hub }) => {
	await hub.keep(['diluxone_users_pro_previews']);
	await hub.set({ diluxone_users_pro_previews: 1 });

	for (const [where, url] of moved(hub.url)) {
		await page.goto(url);
		await expect(page.locator(`[data-diluxone-users-pro="${where}"]`), `${where}, on`).toHaveCount(1);
		await expect(page.locator(`[data-diluxone-users-pro="${where}"] input, [data-diluxone-users-pro="${where}"] select`).first(), where).toBeDisabled();
	}

	await hub.set({ diluxone_users_pro_previews: 0 });

	for (const [where, url] of moved(hub.url)) {
		await page.goto(url);
		await expect(page.locator('.wrap.diluxone-users-admin'), `${where}: the screen`).toBeVisible();
		await expect(page.locator(`[data-diluxone-users-pro="${where}"]`), `${where}, off`).toHaveCount(0);
	}
});

/**
 * Social login and User fields are the network's: their Summary is in
 * Network Admin, the first tab, with the rows of what Users+ Pro would add
 * (#241), and none of them while the previews are off.
 */
test('Social login and User fields open on a Summary in Network Admin, with their Pro rows while the previews are on', async ({ page, hub }) => {
	await hub.keep(['diluxone_users_pro_previews']);

	const summaries: Array<[string, string[]]> = [
		['diluxone-users-social', ['social/summary/providers', 'social/summary/roles']],
		['diluxone-users-fields', ['fields/summary/missing', 'fields/summary/skip']],
	];

	for (const previews of [1, 0]) {
		await hub.set({ diluxone_users_pro_previews: previews });

		for (const [screen, keys] of summaries) {
			await page.goto(networkScreen(NETWORK_URL, screen));

			await expect(page.locator('.nav-tab-wrapper a.nav-tab').first(), `${screen}: Summary is the first tab`).toHaveAttribute('href', /[?&]tab=summary\b/);
			await expect(page.locator('.nav-tab-wrapper a.nav-tab-active'), `${screen}: opened on it`).toHaveAttribute('href', /[?&]tab=summary\b/);
			await expect(page.locator('table.diluxone-users-summary'), `${screen}: what it has set up`).toBeVisible();

			const drawn = await page
				.locator('table.diluxone-users-summary tr.du-pro--row')
				.evaluateAll((rows) => rows.map((row) => row.getAttribute('data-diluxone-users-pro') ?? ''));

			expect(drawn, `${screen}, previews ${previews ? 'on' : 'off'}`).toEqual(previews ? keys : []);

			if (!previews) {
				await expect(page.locator('table.diluxone-users-summary a[href*="utm_campaign=free"]'), `${screen}: no row mentions Users+ Pro`).toHaveCount(0);
			}
		}
	}
});

test('with the previews off, Security’s Summary and Membership’s rail in Network Admin have no Pro row', async ({ page, hub }) => {
	await hub.keep(['diluxone_users_pro_previews']);
	await hub.set({ diluxone_users_pro_previews: 0 });

	for (const url of [networkScreen(NETWORK_URL, 'diluxone-users-security', 'summary'), networkScreen(NETWORK_URL, 'diluxone-users-membership')]) {
		await page.goto(url);
		await expect(page.locator('.wrap.diluxone-users-admin'), `${url}: the screen`).toBeVisible();
		await expect(page.locator('table.diluxone-users-summary tr.du-pro--row'), `${url}: no Pro row`).toHaveCount(0);
		await expect(page.locator('table.diluxone-users-summary a[href*="utm_campaign=free"]'), `${url}: no row mentions Users+ Pro`).toHaveCount(0);
	}
});
