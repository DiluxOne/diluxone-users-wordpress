import type { Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { test, expect } from '../support/fixtures';
import { SCREENS } from '../support/screens';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * A single site is what it always was.
 *
 * On a network the plugin moves the rules about people to Network Admin and
 * the sign-in screens to the main site. None of that has anywhere to go on a
 * single site, and none of it may show: every screen stays in the one menu,
 * every setting is saved from it, and no screen talks about a network the site
 * does not have. Each test is the single-site half of one in
 * network/network-admin.spec.ts.
 */

test.use({ storageState: ADMIN_STATE });

/** The plugin's entries in the dashboard menu, by slug. */
async function menuOf(page: Page): Promise<string[]> {
	const hrefs = await page.locator('#adminmenu a[href*="page=diluxone-users"]').evaluateAll((all: Element[]) =>
		all.map((a) => a.getAttribute('href') ?? '')
	);

	return [...new Set(hrefs.map((href) => new URLSearchParams(href.split('?')[1] ?? '').get('page') ?? ''))].filter(Boolean);
}

test('the menu carries every screen, the network’s included', async ({ page }) => {
	await page.goto(adminUrl('diluxone-users'));

	expect((await menuOf(page)).sort()).toEqual(Object.keys(SCREENS).sort());
});

test('the Overview has no network tabs and sends nobody anywhere else', async ({ page }) => {
	await page.goto(adminUrl('diluxone-users'));

	await expect(page.locator('.nav-tab[href*="tab=network"], .nav-tab[href*="tab=uninstall"]')).toHaveCount(0);
	await expect(page.locator('.diluxone-users-card').filter({ hasText: 'Managed by the network' })).toHaveCount(0);
});

test('the doors on Access › Ways in are this site’s to switch', async ({ page }) => {
	await page.goto(adminUrl('diluxone-users-login', 'ways'));

	await expect(page.locator('input[name="diluxone_users_sso_login"]')).toBeEnabled();
	await expect(page.locator('input[name="diluxone_users_passkey_enabled"]')).toBeEnabled();
});

test('Security › Passkeys and Social login › Rules do not repeat the switches Access has', async ({ page }) => {
	await page.goto(adminUrl('diluxone-users-security', 'passkeys'));
	await expect(page.locator('input[name="diluxone_users_passkey_enabled"]')).toHaveCount(0);

	await page.goto(adminUrl('diluxone-users-social', 'general'));
	await expect(page.locator('input[name="diluxone_users_sso_login"]')).toHaveCount(0);
});

test('Reports › Log settings keeps the way to empty the log, beside the rules', async ({ page }) => {
	const empty = page.locator('h3.du-section').filter({ hasText: 'Empty it now' });

	await page.goto(adminUrl('diluxone-users-reports', 'logging'));
	await expect(page.locator('input[name="diluxone_users_log_days"]')).toBeVisible();
	await expect(empty).toHaveCount(1);

	// Beside the rows is a network's arrangement, where the rules are elsewhere.
	await page.goto(adminUrl('diluxone-users-reports', 'activity'));
	await expect(empty).toHaveCount(0);
});

test('Maintenance › Tools still asks this site about deleting the plugin', async ({ page }) => {
	await page.goto(adminUrl('diluxone-users-status', 'tools'));

	await expect(page.locator('input[name="wipe"]')).toBeVisible();
});

test('there is no Network Admin to find', async ({ page }) => {
	await page.goto('/wp-admin/network/admin.php?page=diluxone-users');

	await expect(page.locator('.wrap.diluxone-users-admin')).toHaveCount(0);
});

/** WP-CLI on the dev site, the one this suite drives. */
function devWp(args: string[]): string {
	return execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
		.split('\n')
		.filter((line) => !/^[ℹ✔✖⚠]|^- |^Starting |^Ran `/.test(line))
		.join('\n')
		.trim();
}

test('there is no membership: no screen, no “Join this site”, no invitation, whatever is asked', async ({ page }) => {
	const screen = await page.goto(adminUrl('diluxone-users-membership'));

	expect(screen?.status(), 'no Membership screen').not.toBe(200);
	expect(await menuOf(page)).not.toContain('diluxone-users-membership');

	const id = devWp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Join', '--post_content=[diluxone_users_join]', '--porcelain']);

	try {
		const url = devWp(['post', 'url', id]);

		for (const state of ['', 'join', 'joined', 'join-refused']) {
			await page.goto(state ? `${url}${url.includes('?') ? '&' : '?'}diluxone-users=${state}` : url);
			await expect(page.locator('[data-diluxone-users-join]'), `nothing drawn (${state || 'plain'})`).toHaveCount(0);
			await expect(page.locator('body'), 'and the shortcode is not left as text').not.toContainText('[diluxone_users_join]');
		}
	} finally {
		devWp(['post', 'delete', id, '--force']);
	}
});
