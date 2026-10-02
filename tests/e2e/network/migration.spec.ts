import { test, expect } from './support';
import { wp } from '../support/cli';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';
import { navigated } from '../support/ui';

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
		await navigated(page, () => notice.getByRole('link', { name: 'Dismiss' }).click());
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

/**
 * A network whose sites each kept a log table of their own, moved into the
 * network's: the rows arrive with their site, the old table goes, and a
 * second run has nothing to do.
 */
test('each site’s old activity log moves into the network’s table, with its site, and the old table goes', async ({ page, beta }) => {
	const tag = `moved-${Date.now().toString(36)}`;
	const prefix = wp(['eval', 'global $wpdb; echo $wpdb->prefix;'], beta.url).trim();
	const betaId = wp(['eval', 'echo get_current_blog_id();'], beta.url).trim();
	const old = `${prefix}diluxone_users_log`;

	// The table /beta/ kept before the log was the network's: the first shape,
	// with no site column, and three refused sign-ins in it.
	wp(['db', 'query', `DROP TABLE IF EXISTS ${old}`]);
	wp([
		'db',
		'query',
		`CREATE TABLE ${old} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL DEFAULT 0, event varchar(32) NOT NULL DEFAULT '', happened datetime NOT NULL DEFAULT '0000-00-00 00:00:00', ip varchar(45) NOT NULL DEFAULT '', agent varchar(255) NOT NULL DEFAULT '', detail text NOT NULL, PRIMARY KEY (id))`,
	]);
	wp([
		'db',
		'query',
		`INSERT INTO ${old} (user_id, event, happened, ip, agent, detail) VALUES ` +
			[1, 2, 3].map((n) => `(0, 'sign_in_failed', UTC_TIMESTAMP(), '203.0.113.${n}', 'old', '{"tried":"${tag}-${n}"}')`).join(','),
	]);

	// A network that has not moved its sites' logs yet.
	for (const key of ['diluxone_users_log_moved', 'diluxone_users_log_moving']) {
		try {
			wp(['site', 'option', 'delete', key]);
		} catch {
			// Not there, which is the state being made.
		}
	}

	const said = wp(['diluxone-users', 'network', 'migrate']);

	expect(said).toContain(`Site ${betaId}: 3 rows moved, its old table dropped.`);
	expect(wp(['db', 'query', `SHOW TABLES LIKE '${old}'`, '--skip-column-names']).trim(), 'the old table is gone').toBe('');

	// On the network's report, as /beta/'s rows.
	await page.goto(`${NETWORK_URL}/wp-admin/network/admin.php?page=diluxone-users-reports&tab=network-activity&s=${tag}`);
	await expect(page.locator(`table[data-diluxone-users-log] tbody tr[data-diluxone-users-site="${betaId}"]`)).toHaveCount(3);

	// And on /beta/'s own.
	await page.goto(`${beta.url}wp-admin/admin.php?page=diluxone-users-reports&tab=activity&s=${tag}`);
	await expect(page.locator('table[data-diluxone-users-log] tbody tr[data-diluxone-users-event]')).toHaveCount(3);

	// Done is done.
	expect(wp(['diluxone-users', 'network', 'migrate'])).toContain('already in the network’s table');
});
