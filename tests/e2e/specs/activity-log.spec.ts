import type { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, savePanel, saveButton, signInWithPassword } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The activity log, end to end: tick a group, do the thing, see the row.
 *
 * It is the one question neither of the other two suites can answer. The unit
 * suite knows whether the policy says yes; the integration suite knows whether
 * a row written by hand comes back out of the table. Neither of them has ever
 * pressed Save on the settings tab, and neither has ever signed in — so
 * neither would notice a tick box whose name does not match what the save
 * reads, a save that writes the option and a report that reads another one, or
 * a listener hooked to something that no longer fires on a real sign-in. Every
 * one of those is a log that is quietly empty on a real site while all three
 * suites are green.
 *
 * So this one does the whole loop through the browser: the switch is thrown on
 * the screen a person throws it on, the sign-in is a real password on the real
 * form, and the row is read off the real report.
 *
 * Every hold here is an id, a class or a data attribute. The site runs in
 * Spanish and the plugin ships eight locales, so not one assertion reads a
 * sentence.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Password-123!';

/** One of the three tick boxes on the Log settings tab. */
function group(page: Page, name: 'access' | 'account' | 'security') {
	return page.locator(`#diluxone-users-log-${name}`);
}

/** The rows of the Activity table, all of them or one kind of them. */
function rows(page: Page, event?: string) {
	const what = event ? `[data-diluxone-users-event="${event}"]` : '[data-diluxone-users-event]';

	return page.locator(`table[data-diluxone-users-log] tbody tr${what}`);
}

const SETTINGS = adminUrl('diluxone-users-reports', 'logging');

/** The report, pinned to one person so the count is about them. */
function activity(who: string): string {
	return adminUrl('diluxone-users-reports', 'activity', { s: who });
}

/** Leaves the three groups exactly as asked, through the screen. */
async function record(page: Page, on: Array<'access' | 'account' | 'security'>): Promise<void> {
	await page.goto(SETTINGS);

	for (const name of ['access', 'account', 'security'] as const) {
		await group(page, name).setChecked(on.includes(name));
	}

	await savePanel(page);
}

/** Signs somebody in on the public form, from a browser with no session. */
async function signIn(guest: Page, loginPage: string, email: string): Promise<void> {
	await guest.context().clearCookies();
	await guest.goto(loginPage);
	await signInWithPassword(guest, email, PASSWORD);
}

test.describe('The activity log', () => {
	test('a group that is off records nothing, and the same group ticked fills the report', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_log_levels']);
		await options.set({ diluxone_users_login_method: 'both' });

		// ── With the group off ──────────────────────────────────────
		await record(page, []);

		const quiet = freshEmail('log-off');
		await site.makeUser({ email: quiet, password: PASSWORD });

		await signIn(guest, pages.login.url, quiet);

		await page.goto(activity(quiet));
		await expect(rows(page)).toHaveCount(0);

		// ── The same switch, the other way ──────────────────────────
		await record(page, ['access']);

		// The save round trip: the box comes back ticked, which is what says
		// the name the form posts and the name the save reads are the same one.
		await page.goto(SETTINGS);
		await expect(group(page, 'access')).toBeChecked();
		await expect(group(page, 'account')).not.toBeChecked();

		const loud = freshEmail('log-on');
		await site.makeUser({ email: loud, password: PASSWORD });

		await signIn(guest, pages.login.url, loud);

		await page.goto(activity(loud));
		await expect(rows(page, 'signed_in')).toHaveCount(1);

		// And the first person is still not there. Without this the test would
		// pass on a log that records everything and simply took a moment.
		await page.goto(activity(quiet));
		await expect(rows(page)).toHaveCount(0);
	});

	test('the rail reports on this site, and it changes when the site does', async ({
		page,
		options,
	}) => {
		await options.keep(['diluxone_users_log_levels']);

		await record(page, []);
		await page.goto(activity(''));

		// Nothing recorded is a state and it is said as one: the pill at the
		// head of the rail, not a paragraph somebody has to read.
		const rail = page.locator('.diluxone-users-studio__aside .du-state');

		await expect(rail.locator('.diluxone-users-state--off')).toHaveCount(1);

		// The sentence beside it is the reading this feature was asked for:
		// how many rows there are on this site. A number, not a warning.
		await expect(rail.locator('.du-state__line')).toContainText(/\d/);

		await record(page, ['access', 'account', 'security']);
		await page.goto(activity(''));

		await expect(rail.locator('.diluxone-users-state--active')).toHaveCount(1);
	});

	test('emptying it asks first, and then the report is empty', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_log_levels']);
		await options.set({ diluxone_users_login_method: 'both' });
		await record(page, ['access']);

		const email = freshEmail('log-empty');
		await site.makeUser({ email, password: PASSWORD });
		await signIn(guest, pages.login.url, email);

		await page.goto(SETTINGS);

		const empty = page.locator('a[href*="action=diluxone_users_log_empty"]');

		page.once('dialog', (dialog) => dialog.dismiss());
		await empty.click();
		await page.goto(activity(email));
		await expect(rows(page)).not.toHaveCount(0);

		await page.goto(SETTINGS);
		page.once('dialog', (dialog) => dialog.accept());
		await Promise.all([page.waitForURL(/diluxone-users-emptied=/), empty.click()]);

		await page.goto(activity(''));
		await expect(rows(page)).toHaveCount(0);
	});

	test('on a single site every row is the site’s, with no Site column, no site filter and no network report', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_log_levels']);
		await options.set({ diluxone_users_login_method: 'both' });
		await record(page, ['access']);

		const email = freshEmail('log-single');
		await site.makeUser({ email, password: PASSWORD });
		await signIn(guest, pages.login.url, email);

		await page.goto(activity(email));
		await expect(rows(page, 'signed_in')).toHaveCount(1);
		await expect(rows(page).first(), 'stamped as the one site there is').toHaveAttribute('data-diluxone-users-site', '1');
		await expect(page.locator('table[data-diluxone-users-log] thead th')).toHaveCount(6);
		await expect(page.locator('select[name="site"], input[name="site"]')).toHaveCount(0);
		await expect(page.locator('.nav-tab[href*="tab=network-activity"]')).toHaveCount(0);
		await expect(page.locator('a[href*="action=diluxone_users_log_empty_network"]')).toHaveCount(0);
	});

	test('the report is a report and the settings are settings', async ({ page }) => {
		// The rule the whole Reports screen exists for: nothing on the tab with
		// the table gets written when a button is pressed, because there is no
		// button on it.
		await page.goto(activity(''));
		await expect(saveButton(page)).toHaveCount(0);
		await expect(rows(page)).toHaveCount(await rows(page).count());

		await page.goto(SETTINGS);
		await expect(saveButton(page)).toHaveCount(1);
		await expect(page.locator('table[data-diluxone-users-log]')).toHaveCount(0);
	});
});
