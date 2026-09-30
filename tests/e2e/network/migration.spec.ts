import { test, expect } from './support';
import { wp } from '../support/cli';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * A network whose sites kept their own settings, moved to the network.
 *
 * Before the settings had scopes every site kept all of them in its own
 * options table. The move takes the main site's as the network's, writes down
 * where another site disagreed, says so once in Network Admin and for good on
 * the network's Overview — and a second run does nothing. It is driven here
 * the way a network administrator would drive it by hand: through WP-CLI, and
 * then the screens.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

/** The network's settings this spec moves, and the network's own bookkeeping. */
const MOVED = ['diluxone_users_2fa_mode', 'diluxone_users_session_long_days'];
const BOOKS = ['diluxone_users_network_version', 'diluxone_users_network_conflicts', 'diluxone_users_network_conflicts_seen'];

test('the main site’s values become the network’s, the differences are shown once, and running it again does nothing', async ({
	page,
	hub,
	alpha,
}) => {
	// What the network has now is put back when the test ends.
	await hub.keep([...MOVED, ...BOOKS]);

	// Two sites that kept their own copies, and disagree about one of them.
	wp(['option', 'update', 'diluxone_users_2fa_mode', 'required'], hub.url);
	wp(['option', 'update', 'diluxone_users_session_long_days', '30'], hub.url);
	wp(['option', 'update', 'diluxone_users_2fa_mode', 'off'], alpha.url);
	wp(['option', 'update', 'diluxone_users_session_long_days', '30'], alpha.url);

	// A network that has not moved yet: no values of its own, no marker.
	for (const key of [...MOVED, ...BOOKS]) {
		try {
			wp(['site', 'option', 'delete', key]);
		} catch {
			// Not there to begin with, which is the state being made.
		}
	}

	try {
		const said = wp(['diluxone-users', 'network', 'migrate']);

		expect(said).toContain('had diluxone_users_2fa_mode set differently');
		expect(said).not.toContain('diluxone_users_session_long_days');

		expect((await alpha.site.getOptions(['diluxone_users_2fa_mode'])).diluxone_users_2fa_mode, 'the main site’s answer, on /alpha/').toBe('required');

		// Said once, in Network Admin, naming the site.
		await page.goto(`${NETWORK_URL}/wp-admin/network/`);

		const notice = page.locator('.notice-warning').filter({ hasText: 'had set some of them differently' });

		await expect(notice).toContainText('Alpha');

		// And for good on the Overview, beside the settings.
		await notice.getByRole('link', { name: 'See what was different' }).click();

		const row = page.locator('.diluxone-users-list tr').filter({ hasText: 'diluxone_users_2fa_mode' });

		await expect(row).toContainText('Alpha');
		await expect(row).toContainText('off');

		await page.goto(`${NETWORK_URL}/wp-admin/network/`);
		await Promise.all([page.waitForLoadState('domcontentloaded'), notice.getByRole('link', { name: 'Dismiss' }).click()]);
		await page.goto(`${NETWORK_URL}/wp-admin/network/`);
		await expect(notice, 'dismissed is dismissed').toHaveCount(0);

		await page.goto(`${NETWORK_URL}/wp-admin/network/admin.php?page=diluxone-users`);
		await expect(row, 'the table stays').toHaveCount(1);

		// Done is done.
		expect(wp(['diluxone-users', 'network', 'migrate'])).toContain('already moved');
	} finally {
		// The old copies stay until the plugin is deleted; these were made by
		// this test and go with it.
		for (const [key, one] of MOVED.flatMap((key) => [
			[key, hub.url],
			[key, alpha.url],
		])) {
			try {
				wp(['option', 'delete', key], one);
			} catch {
				// Already gone.
			}
		}
	}
});
