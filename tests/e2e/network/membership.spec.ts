import { Page } from '@playwright/test';
import { test, expect, whoOn, signInFrom, SiteHandle } from './support';
import { Site, freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { savePanel } from '../support/ui';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * Which sites an account is a member of: the network's membership policy.
 *
 * Set on Network Admin › Membership, one of three: every live site (the
 * default), whoever asks ("Join this site"), or by invitation. Each test
 * walks one of them the way people meet it: an administrator making an
 * account or a site in Network Admin, taking somebody off /beta/ in its Users
 * screen; a person signing in from /beta/ and finding a button, a sentence,
 * or nothing to do. And the activity log of /beta/, which has the sign-ins
 * on the hub that were for it.
 */

const PASSWORD = 'e2e-Membership-1!';
const SCREEN = `${NETWORK_URL}/wp-admin/network/admin.php?page=diluxone-users-membership`;

function policy(page: Page, value: string) {
	return page.locator(`input[name="diluxone_users_membership"][value="${value}"]`);
}

/** How many jobs the queue holds, asked of the network. */
function queued(): number {
	return Number(wp(['eval', 'echo count( diluxone_users_membership_queue() );'])) || 0;
}

/**
 * Lets WP-Cron work the queue to the end, as it would on its own.
 *
 * The container cannot reach its own address, so WordPress cannot spawn its
 * cron from a page load; WP-CLI runs what is due, which is what that spawn
 * does.
 */
function drain(): void {
	for (let run = 0; run < 60 && queued() > 0; run++) {
		wp(['cron', 'event', 'run', '--due-now']);
	}

	expect(queued(), 'the queue is worked to the end').toBe(0);
}

/** The signed-in person's box on a page of a site: 'click', 'invite', 'joined', or null. */
async function joinBox(page: Page): Promise<string | null> {
	const box = page.locator('[data-diluxone-users-join]').first();

	return (await box.count()) > 0 ? box.getAttribute('data-diluxone-users-join') : null;
}

/** A page of a site with the shortcode on it; deleted by the caller. */
function joinPage(one: SiteHandle): { id: string; url: string } {
	const id = wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Join', '--post_content=[diluxone_users_join]', '--porcelain'], one.url);

	return { id, url: wp(['post', 'url', id], one.url) };
}

test.describe('Network Admin › Membership', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('saves each of the three answers, and the network reads it', async ({ page, hub, alpha }) => {
		await hub.keep(['diluxone_users_membership']);

		for (const value of ['click', 'invite', 'all']) {
			await page.goto(SCREEN);
			await policy(page, value).check();
			await savePanel(page);

			await page.goto(SCREEN);
			await expect(policy(page, value), `${value} is what the screen shows`).toBeChecked();
			expect((await alpha.site.getOptions(['diluxone_users_membership'])).diluxone_users_membership, `/alpha/ reads ${value}`).toBe(value);
		}

		// "Sync everyone now" is there under every site, and only there.
		await expect(page.locator('[data-diluxone-users-sync]')).toBeVisible();
		await hub.set({ diluxone_users_membership: 'click' });
		await page.goto(SCREEN);
		await expect(page.locator('[data-diluxone-users-sync]')).toHaveCount(0);
	});

	test('a site’s menu has no Membership: it is the network’s', async ({ page, alpha }) => {
		await page.goto(alpha.admin('admin.php?page=diluxone-users'));
		await expect(page.locator('#adminmenu a[href*="page=diluxone-users-membership"]')).toHaveCount(0);

		const refused = await page.goto(alpha.admin('admin.php?page=diluxone-users-membership'));
		expect(refused?.status(), 'refused by address on a site').not.toBe(200);
	});
});

test.describe('Every site (the default)', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('an account made in Network Admin is a member of /alpha/ and /beta/, with each site’s role', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_login_role: 'subscriber' });

		const before = wp(['option', 'get', 'default_role'], alpha.url);

		wp(['option', 'update', 'default_role', 'author'], alpha.url);

		try {
			const email = freshEmail('net-member-new');

			await page.goto(`${NETWORK_URL}/wp-admin/network/user-new.php`);
			await page.locator('input[name="user[username]"]').fill(email.split('@')[0].replace(/[^a-z0-9]/g, ''));
			await page.locator('input[name="user[email]"]').fill(email);
			await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#add-user').click()]);

			const onAlpha = await alpha.site.user(email);
			const onBeta = await beta.site.user(email);

			expect(onAlpha.member, 'a member of /alpha/').toBe(true);
			expect(onAlpha.roles, 'with /alpha/’s default role').toEqual(['author']);
			expect(onBeta.member, 'and of /beta/').toBe(true);
			expect(onBeta.roles).toEqual(['subscriber']);
			expect((await hub.site.user(email)).roles, 'on the hub, the role new accounts get').toEqual(['subscriber']);
		} finally {
			wp(['option', 'update', 'default_role', before], alpha.url);
		}
	});

	test('a site made in Network Admin gets the network’s people', async ({ page, hub }) => {
		await hub.set({ diluxone_users_membership: 'all' });

		const email = freshEmail('net-member-site');

		await hub.site.makeUser({ email, password: PASSWORD });

		const slug = `e2e-born-${Date.now().toString(36)}`;

		await page.goto(`${NETWORK_URL}/wp-admin/network/site-new.php`);
		await page.locator('input[name="blog[domain]"]').fill(slug);
		await page.locator('input[name="blog[title]"]').fill('Born with members');
		await page.locator('input[name="blog[email]"]').fill(email);
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#add-site').click()]);

		try {
			// A network with many people queues the new site; the screen says
			// how far it has got, and cron takes it to the end.
			if (queued() > 0) {
				await page.goto(SCREEN);
				await expect(page.locator('[data-diluxone-users-membership-queue] [data-diluxone-users-job="site"]'), 'the job, with its progress').toBeVisible();
				drain();
			}

			const born = await Site.open(`${NETWORK_URL}/${slug}/`);

			expect((await born.user(email)).member, 'a member of the new site').toBe(true);
		} finally {
			wp(['site', 'delete', `--slug=${slug}`, '--yes']);
		}
	});

	test('taken off /beta/ in its Users screen, signing in again for /beta/ does not bring them back', async ({ page, guest, hub, beta }) => {
		await hub.set({ diluxone_users_membership: 'all' });

		const email = freshEmail('net-removed');
		const made = await hub.site.makeUser({ email, password: PASSWORD });

		expect((await beta.site.user(email)).member, 'every site: a member of /beta/').toBe(true);

		// /beta/'s administrator removes them, the way WordPress offers it.
		await page.goto(beta.admin(`users.php?s=${encodeURIComponent(email)}`));
		await page.locator(`#user-${made.id}`).hover();
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator(`#user-${made.id} .row-actions .remove a`).click()]);
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#submit').click()]);
		expect((await beta.site.user(email)).member, 'removed').toBe(false);

		await signInFrom(guest, beta, hub, email, PASSWORD);
		await guest.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

		expect((await beta.site.user(email)).member, 'signing in did not bring them back').toBe(false);
		expect(await whoOn(guest, beta.url), 'signed in, and not one of /beta/’s members').toBe('');
		expect(await joinBox(guest), 'the page says the site is by invitation, for them').toBe('invite');
	});

	test('`wp diluxone-users network membership sync` adds whoever is missing, and refuses under another answer', async ({ hub, alpha, beta }) => {
		await hub.set({ diluxone_users_membership: 'invite' });

		const email = freshEmail('net-cli-sync');

		await hub.site.makeUser({ email, password: PASSWORD });
		expect((await beta.site.user(email)).member, 'by invitation: the hub only').toBe(false);

		expect(() => wp(['diluxone-users', 'network', 'membership', 'sync']), 'refused by invitation').toThrow();

		await hub.set({ diluxone_users_membership: 'all' });

		const said = wp(['diluxone-users', 'network', 'membership', 'sync']);

		expect(said).toContain('Success');
		expect((await alpha.site.user(email)).member).toBe(true);
		expect((await beta.site.user(email)).member).toBe(true);
	});
});

test.describe('Whoever asks', () => {
	test('/beta/ offers “Join this site” — in the menu, on its page and on the way back — and pressing it makes them a member', async ({
		page,
		hub,
		beta,
	}) => {
		await hub.set({ diluxone_users_membership: 'click' });

		const email = freshEmail('net-click');

		await hub.site.makeUser({ email, password: PASSWORD });

		const menu = await beta.site.menu();
		const join = joinPage(beta);

		await beta.set({ diluxone_users_menu_location: menu.location });

		try {
			await signInFrom(page, beta, hub, email, PASSWORD);
			await page.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

			expect(new URL(page.url()).searchParams.get('diluxone-users'), 'the way back says there is a site to join').toBe('join');
			expect(await joinBox(page), 'at the top of the page').toBe('click');
			expect((await beta.site.user(email)).member, 'signing in joined only the hub').toBe(false);

			await page.goto(menu.url);
			await expect(page.locator('.diluxone-users-menu--join a'), 'in the menu').toBeVisible();

			await page.goto(join.url);
			const button = page.locator('[data-diluxone-users-join="click"] button[type="submit"]');

			await expect(button, 'on the page with the shortcode').toBeVisible();
			await Promise.all([page.waitForLoadState('domcontentloaded'), button.click()]);

			expect(new URL(page.url()).searchParams.get('diluxone-users')).toBe('joined');
			expect(await joinBox(page), 'welcomed').toBe('joined');
			expect((await beta.site.user(email)).member, 'a member of /beta/ now').toBe(true);
			expect(await whoOn(page, beta.url)).toBe(email);

			await page.goto(menu.url);
			await expect(page.locator('.diluxone-users-menu--join'), 'nothing more to join').toHaveCount(0);
		} finally {
			await beta.site.forgetMenu();
			wp(['post', 'delete', join.id, '--force'], beta.url);
		}
	});
});

test.describe('By invitation', () => {
	test('/beta/ says it is by invitation, and nothing makes them a member', async ({ page, hub, beta }) => {
		await hub.set({ diluxone_users_membership: 'invite' });

		const email = freshEmail('net-invite');

		await hub.site.makeUser({ email, password: PASSWORD });

		const menu = await beta.site.menu();
		const join = joinPage(beta);

		await beta.set({ diluxone_users_menu_location: menu.location });

		try {
			await signInFrom(page, beta, hub, email, PASSWORD);
			await page.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

			expect(await joinBox(page), 'at the top of the page').toBe('invite');

			await page.goto(join.url);
			expect(await joinBox(page), 'on the page with the shortcode').toBe('invite');
			await expect(page.locator('[data-diluxone-users-join] button')).toHaveCount(0);

			await page.goto(menu.url);
			await expect(page.locator('.diluxone-users-menu--invite'), 'in the menu').toHaveCount(1);
			await expect(page.locator('.diluxone-users-menu--join')).toHaveCount(0);

			expect((await beta.site.user(email)).member).toBe(false);
		} finally {
			await beta.site.forgetMenu();
			wp(['post', 'delete', join.id, '--force'], beta.url);
		}
	});
});

test.describe('The activity log', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('a sign-in on the hub from /beta/ is on /beta/’s report, and Network Admin’s says where it came from', async ({
		page,
		guest,
		hub,
		alpha,
		beta,
	}) => {
		await hub.set({ diluxone_users_log_levels: ['access'] });

		const email = freshEmail('net-log-from');

		await hub.site.makeUser({ email, password: PASSWORD });
		await signInFrom(guest, beta, hub, email, PASSWORD);
		await guest.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

		const hubId = wp(['eval', 'echo get_main_site_id();']);
		const betaId = wp(['eval', 'echo get_current_blog_id();'], beta.url);
		const report = (one: SiteHandle) => one.admin(`admin.php?page=diluxone-users-reports&tab=activity&event=signed_in&s=${encodeURIComponent(email)}`);

		await page.goto(report(beta));
		const row = page.locator('[data-diluxone-users-log] tbody tr[data-diluxone-users-event="signed_in"]');

		await expect(row, 'on /beta/’s report').toHaveCount(1);
		await expect(row, 'a row of the hub’s, where the session opened').toHaveAttribute('data-diluxone-users-site', hubId);

		await page.goto(report(alpha));
		await expect(page.locator('[data-diluxone-users-log] tbody tr[data-diluxone-users-event="signed_in"]'), 'not on /alpha/’s').toHaveCount(0);

		await page.goto(`${NETWORK_URL}/wp-admin/network/admin.php?page=diluxone-users-reports&tab=network-activity&event=signed_in&s=${encodeURIComponent(email)}`);
		await expect(page.locator('[data-diluxone-users-log] tbody tr [data-diluxone-users-from]'), 'the From column names /beta/').toHaveAttribute('data-diluxone-users-from', betaId);
	});
});
