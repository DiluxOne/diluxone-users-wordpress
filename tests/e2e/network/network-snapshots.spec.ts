import type { Page } from '@playwright/test';
import { test, expect } from './support';
import { networkAdminTabs } from '../support/screens';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * A picture of every screen a network has and a single site does not.
 *
 * The network's screens in Network Admin, and the three places on a site that
 * look different because the site is part of a network: the Overview naming
 * where each moved area went, the main site's Ways in drawing the network's
 * doors as they stand, and another site's Tools saying the wipe is decided for
 * the network. The rest of a site's screens are the single site's screens and
 * are photographed there (specs/admin-snapshots.spec.ts).
 *
 * Opt-in, like the single-site pictures: `make test-visual-network`.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

/**
 * The network's state the pictures are of, pinned: the fields and the social
 * apps are whatever the last suite left, and a picture of a list that drifts
 * fails for a reason nobody can act on.
 */
test.beforeEach(async ({ hub }) => {
	await hub.set({
		diluxone_users_fields: [
			{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			{ key: 'diluxone_users_phone', label: 'Phone', type: 'tel', required: 0, active: 1, group: 'contact', edit: 'always' },
		],
		diluxone_users_sso: {},
		diluxone_e2e_sso: 0,
	});
});

/** What changes by itself inside the plugin's block, painted over. */
const MOVES_BY_ITSELF = ['.diluxone-users-card__value', '.diluxone-users-list td', 'img.avatar', '.diluxone-users-summary td code'];

async function settled(page: Page): Promise<void> {
	await page.evaluate(() => document.fonts.ready.then(() => undefined));
	await page.evaluate(() => {
		window.scrollTo(0, 0);

		return new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
	});
}

async function picture(page: Page, name: string): Promise<void> {
	await settled(page);

	await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(`${name.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}.png`, {
		mask: MOVES_BY_ITSELF.map((one) => page.locator(one)),
	});
}

test.describe('Every network screen looks like it did', () => {
	for (const tab of networkAdminTabs()) {
		test(tab.name, async ({ page }) => {
			await page.goto(`${NETWORK_URL}${tab.url}`);
			await picture(page, tab.name);
		});
	}
});

test.describe('What a site of a network looks like where it differs', () => {
	test('network › site › overview', async ({ page, alpha }) => {
		await page.goto(alpha.admin('admin.php?page=diluxone-users'));
		await picture(page, 'network › site › overview');
	});

	test('network › hub › ways in', async ({ page, hub }) => {
		await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users-login&tab=ways`);
		await picture(page, 'network › hub › ways in');
	});

	test('network › site › tools', async ({ page, alpha }) => {
		await page.goto(alpha.admin('admin.php?page=diluxone-users-status&tab=tools'));
		await picture(page, 'network › site › tools');
	});
});
