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
