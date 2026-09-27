import { test as setup, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { Site } from './support/api';
import { BASELINE } from './support/baseline';
import { BASELINE_FILE, PAGES_FILE } from './support/fixtures';
import { ADMIN_STATE } from '../../playwright.config';

/**
 * Puts the site into the state every spec assumes, and remembers what it was.
 *
 * The environment this runs in is somebody's development site, not a scratch
 * one: whatever is configured there is configured for a reason. So nothing is
 * wiped — the settings the suite depends on are written down, their old values
 * go into a file, and the teardown writes them back.
 */

setup('seed the site and remember what it was', async ({ baseURL }) => {
	const site = await Site.open(baseURL!);

	const seeded = await site.seed();

	mkdirSync('build', { recursive: true });
	writeFileSync(PAGES_FILE, JSON.stringify(seeded.pages, null, 2));

	const previous = await site.setOptions(
		BASELINE({
			login: seeded.pages.login.id,
			register: seeded.pages.register.id,
			account: seeded.pages.account.id,
		}),
		{ flush: true, forgetTransients: true }
	);

	writeFileSync(BASELINE_FILE, JSON.stringify(previous, null, 2));

	await site.clearMail();

	// The sign-in page has to be the one the plugin resolves, or every spec is
	// looking at the wrong screen and saying so in nine different ways.
	expect(seeded.pages.login.url).toContain('e2e-login');
});

setup('keep an administrator session for the specs that need one', async ({ page, context }) => {
	const user = process.env.WP_USER ?? 'admin';
	const pass = process.env.WP_PASS ?? 'password';

	// The escape hatch, always: whatever a previous run left behind — including
	// "only a link, no passwords" — this is the door that stays open, and it is
	// the one an administrator would use in the same situation.
	//
	// By name and not by label: this site is in Spanish and the plugin ships
	// eight locales — the markup is the contract, the wording is a setting.
	await page.goto('/wp-login.php?diluxone-users-admin=1');

	const login = page.locator('input[name="log"]');

	await expect(login, 'wp-login.php has to draw its own form for the hatch').toBeVisible();
	await login.fill(user);
	await page.locator('input[name="pwd"]').fill(pass);

	await Promise.all([page.waitForURL(/wp-admin/), page.locator('#wp-submit').click()]);
	await context.storageState({ path: ADMIN_STATE });
});
