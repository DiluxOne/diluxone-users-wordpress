import type { Page } from '@playwright/test';
import { test, expect, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { savePanel } from '../support/ui';
import { wp } from '../support/cli';
import { expectPill, numbersIn } from '../support/admin-access';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The Overview on a network: the hub's, another site's, and Network Admin's.
 *
 * A site's numbers are its own members; the areas a site no longer sets are
 * cards that say where they went, with a way in only for whoever can follow
 * it; Network Admin's Overview names the hub, counts the sites and lists
 * every network screen; and its "deleting the plugin" answer is said in the
 * rail as it is saved.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const PASSWORD = 'e2e-Net-Overview-1!';

const network = (rest: string) => `${NETWORK_URL}/wp-admin/network/${rest.replace(/^\//, '')}`;

/** The cards of "Set somewhere else", by where the area went. */
function elsewhere(page: Page, where: 'network' | 'hub') {
	return page.locator('.diluxone-users-card').filter({ has: page.locator(where === 'network' ? '.dashicons-networking' : '.dashicons-admin-home') });
}

async function accounts(page: Page): Promise<number> {
	const card = page.locator('.diluxone-users-card').filter({ has: page.locator('.dashicons-groups') });

	return numbersIn((await card.locator('.diluxone-users-card__value').innerText()).replace(/[.,](?=\d{3}\b)/g, ''))[0];
}

test.describe('A site’s Overview on a network', () => {
	test('on the hub: only the network’s areas are elsewhere, each with its way into Network Admin', async ({ page, hub }) => {
		await page.goto(`${hub.url}wp-admin/admin.php?page=diluxone-users`);

		await expect(elsewhere(page, 'network'), 'security, social login, user fields, membership and the log settings').toHaveCount(5);
		await expect(elsewhere(page, 'hub'), 'the hub sends nobody to the hub').toHaveCount(0);

		for (const one of await elsewhere(page, 'network').all()) {
			await expect(one.locator('a')).toHaveAttribute('href', /\/wp-admin\/network\/admin\.php\?page=diluxone-users-/);
		}
	});

	test('to a site’s own administrator: the areas elsewhere are named without a way in, and the cards about the network’s screens carry no link', async ({ guest, hub, alpha }) => {
		const email = freshEmail('net-ov-admin');

		await alpha.site.makeUser({ email, password: PASSWORD, role: 'administrator' });
		await signInFrom(guest, alpha, hub, email, PASSWORD);
		await guest.goto(alpha.admin('admin.php?page=diluxone-users'));

		await expect(elsewhere(guest, 'network')).toHaveCount(5);
		await expect(elsewhere(guest, 'network').locator('a'), 'a way into Network Admin for somebody who cannot open it').toHaveCount(0);
		await expect(elsewhere(guest, 'hub')).toHaveCount(4);
		await expect(elsewhere(guest, 'hub').locator('a'), 'a way onto the hub for somebody who is not its administrator').toHaveCount(0);

		// The top cards: the accounts are this site's, and the rest are the
		// network's screens, which this person cannot open.
		const top = guest.locator('.diluxone-users-card').filter({ has: guest.locator('.dashicons-groups, .dashicons-clock, .dashicons-forms, .dashicons-share') });

		await expect(top).toHaveCount(4);
		await expect(top.filter({ has: guest.locator('.dashicons-groups') }).locator('a[href*="users.php"]')).toHaveCount(1);

		for (const icon of ['.dashicons-clock', '.dashicons-forms', '.dashicons-share']) {
			await expect(top.filter({ has: guest.locator(icon) }).locator('a'), icon).toHaveCount(0);
		}
	});

	test('counts this site’s members, not the network’s', async ({ page, alpha, beta }) => {
		await page.goto(alpha.admin('admin.php?page=diluxone-users'));

		const onAlpha = await accounts(page);

		await page.goto(beta.admin('admin.php?page=diluxone-users'));

		const onBeta = await accounts(page);

		const email = freshEmail('net-ov-beta');
		const made = await beta.site.makeUser({ email, password: PASSWORD });

		// A member of /beta/ and of nowhere else, whatever the membership
		// policy added on the way.
		wp(['eval', `foreach ( array_keys( get_blogs_of_user( ${made.id} ) ) as $b ) { if ( (int) $b !== (int) get_id_from_blogname( 'beta' ) ) { remove_user_from_blog( ${made.id}, (int) $b ); } }`]);
		expect((await alpha.site.user(email)).member).toBe(false);
		expect((await beta.site.user(email)).member).toBe(true);

		for (const one of [alpha, beta]) {
			await one.site.setOptions({}, { forgetTransients: true });
		}

		await page.goto(alpha.admin('admin.php?page=diluxone-users'));
		expect(await accounts(page), 'a member of /beta/ counted on /alpha/').toBe(onAlpha);

		await page.goto(beta.admin('admin.php?page=diluxone-users'));
		expect(await accounts(page)).toBe(onBeta + 1);
	});

	test('on a site with nobody on it: nothing to count, and the ways out are still there', async ({ page }) => {
		const slug = `e2e-empty-${Date.now().toString(36)}`;
		const id = wp(['site', 'create', `--slug=${slug}`, '--title=Empty', '--porcelain']);

		try {
			// Whoever the new site was given, taken off it again.
			wp(['eval', `foreach ( get_users( array( 'blog_id' => ${id}, 'fields' => 'ID' ) ) as $u ) { remove_user_from_blog( (int) $u, ${id} ); }`]);

			await page.goto(`${NETWORK_URL}/${slug}/wp-admin/admin.php?page=diluxone-users&tab=usage`);

			await expect(page.locator('.diluxone-users-panel .du-notice')).toHaveCount(1);
			await expect(page.locator('.diluxone-users-usage')).toHaveCount(0);
			await expect(page.locator('.diluxone-users-studio__aside a[href*="users.php"]')).toHaveCount(1);
		} finally {
			wp(['site', 'delete', id, '--yes']);
		}
	});
});

test.describe('Network Admin’s Overview', () => {
	test('names the hub with the way to its dashboard, says how the sites are addressed and how many, and lists every network screen', async ({ page, hub }) => {
		await page.goto(network('admin.php?page=diluxone-users&tab=network'));

		const cards = page.locator('.diluxone-users-card');
		const hubCard = cards.filter({ has: page.locator('.dashicons-admin-home') });

		await expect(hubCard.locator('a')).toHaveAttribute('href', `${hub.url}wp-admin/admin.php?page=diluxone-users`);

		const count = Number(wp(['site', 'list', '--format=count']));

		expect(numbersIn(await cards.filter({ has: page.locator('.dashicons-networking') }).locator('.diluxone-users-card__detail').innerText())[0]).toBe(count);

		for (const screen of ['diluxone-users-membership', 'diluxone-users-security', 'diluxone-users-social', 'diluxone-users-fields', 'diluxone-users-reports']) {
			await expect(page.locator(`.diluxone-users-studio__aside a[href$="/wp-admin/network/admin.php?page=${screen}"]`), screen).toHaveCount(1);
		}
	});

	test('the answer about deleting the plugin is said in the rail as it is saved', async ({ page, hub }) => {
		await hub.keep(['diluxone_users_uninstall_wipe']);
		await hub.set({ diluxone_users_uninstall_wipe: 0 });

		await page.goto(network('admin.php?page=diluxone-users&tab=uninstall'));

		const state = page.locator('.diluxone-users-studio__aside .du-state');

		await expectPill(state, 'off');

		await page.locator('label.du-choice').filter({ has: page.locator('input[name="diluxone_users_uninstall_wipe"]') }).click();
		await savePanel(page);
		await expectPill(state, 'active');
		expect(Number((await hub.site.getOptions(['diluxone_users_uninstall_wipe'])).diluxone_users_uninstall_wipe)).toBe(1);

		await page.locator('label.du-choice').filter({ has: page.locator('input[name="diluxone_users_uninstall_wipe"]') }).click();
		await savePanel(page);
		await expectPill(state, 'off');
	});
});
