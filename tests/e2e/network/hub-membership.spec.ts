import { test, expect, whoOn, signInFrom } from './support';
import { Site, freshEmail } from '../support/api';
import { wp } from '../support/cli';
import { adminError, adminSaved, answeringDialog, navigated, saveButton, savePanel, signInWithPassword } from '../support/ui';
import { NETWORK_ADMIN_STATE } from '../../../playwright.network.config';
import {
	MEMBERSHIP,
	blogId,
	drain,
	forgetJoinPage,
	joinBox,
	joinPage,
	liveSites,
	network,
	policy,
	queued,
	railState,
	throwawaySite,
	userMeta,
} from './hub-support';

/**
 * The membership policy beyond its happy paths: the button that adds
 * everybody, pressed — and not pressed — with its question; the queue it
 * starts on a network too big to do it at once; an answer that is not one of
 * the three; the role a site's default would give and the policy will not;
 * the sites and the people the policy leaves alone; and a removal, which only
 * an administrator undoes.
 */

const PASSWORD = 'e2e-HubMember-1!';

test.describe('Network Admin › Membership › “Sync everyone now”', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('asks first: Cancel adds nobody; OK adds whoever is missing everywhere, except where an administrator removed them', async ({
		page,
		hub,
		alpha,
		beta,
	}) => {
		// Two people of the hub alone, made while the network was by
		// invitation; one of them taken off /beta/ by its administrator.
		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });

		const missing = freshEmail('hub-sync-missing');
		const removed = freshEmail('hub-sync-removed');

		await hub.site.makeUser({ email: missing, password: PASSWORD });
		await hub.site.makeUser({ email: removed, password: PASSWORD, meta: { diluxone_users_removed_from: [blogId(beta)] } });
		// Done on the spot whatever a full run has left in the network by now:
		// the queued path is the next test's.
		await hub.set({ diluxone_users_membership: 'all', diluxone_e2e_membership_inline: 100000 });

		await page.goto(MEMBERSHIP);

		const sync = page.locator('[data-diluxone-users-sync]');

		await expect(sync).toBeVisible();
		await expect(railState(page), 'the rail: confirmed, nothing in the background').toHaveClass(/diluxone-users-state--active/);
		await expect(page.locator('.du-state__line'), 'the rail counts the live sites').toContainText(String(liveSites()));

		// Cancel: the question is asked, and nothing happens.
		const asked = await answeringDialog(page, 'dismiss', () => sync.click());

		expect(asked, 'the question names how many accounts').toMatch(/\d/);
		expect(page.url(), 'still on the screen').toBe(MEMBERSHIP);
		expect((await beta.site.user(missing)).member, 'Cancel added nobody').toBe(false);

		// OK: the request goes, and comes back saying it is done.
		await answeringDialog(page, 'accept', () => Promise.all([page.waitForURL(/diluxone-users-synced=/), sync.click()]));

		expect(new URL(page.url()).searchParams.get('diluxone-users-synced'), 'done on the spot').toBe('done');
		await expect(adminSaved(page), 'it says so').toBeVisible();
		await expect(adminError(page)).toHaveCount(0);

		expect((await alpha.site.user(missing)).member, 'a member of /alpha/ now').toBe(true);
		expect((await beta.site.user(missing)).member, 'and of /beta/').toBe(true);
		expect((await alpha.site.user(removed)).member, 'the removed one: /alpha/, where nobody removed them').toBe(true);
		expect((await beta.site.user(removed)).member, 'but not /beta/, where an administrator did').toBe(false);
	});

	test('on a network too big to do it at once it is queued: the notice says so, the screen shows the progress, cron finishes it', async ({
		page,
		hub,
		alpha,
	}) => {
		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-sync-queued');

		await hub.site.makeUser({ email, password: PASSWORD });
		// Nothing on the spot: every addition goes through the queue.
		// And cron kept off it, so the queue is still there to be seen.
		await hub.set({ diluxone_users_membership: 'all', diluxone_e2e_membership_inline: 0, diluxone_e2e_membership_hold: 1 });

		try {
			await page.goto(MEMBERSHIP);
			await answeringDialog(page, 'accept', () =>
				Promise.all([page.waitForURL(/diluxone-users-synced=/), page.locator('[data-diluxone-users-sync]').click()])
			);

			expect(new URL(page.url()).searchParams.get('diluxone-users-synced')).toBe('queued');
			await expect(page.locator('.notice-info'), 'it says it started').toBeVisible();
			await expect(page.locator('[data-diluxone-users-membership-queue] [data-diluxone-users-job]').first(), 'the job, with its progress').toBeVisible();
			await expect(railState(page), 'the rail: something in the background').toHaveClass(/diluxone-users-state--pending/);
			expect(queued(), 'the queue holds it').toBeGreaterThan(0);
			expect((await alpha.site.user(email)).member, 'nobody added yet').toBe(false);

			// What cron would do, done to the end.
			wp(['diluxone-users', 'network', 'membership', 'sync']);

			await page.goto(MEMBERSHIP);
			await expect(page.locator('[data-diluxone-users-membership-queue]'), 'the list is gone').toHaveCount(0);
			await expect(railState(page)).toHaveClass(/diluxone-users-state--active/);
			expect((await alpha.site.user(email)).member, 'the queue, worked, added them').toBe(true);
		} finally {
			if (queued() > 0) {
				wp(['diluxone-users', 'network', 'membership', 'sync']);
			}
		}
	});
});

test.describe('Network Admin › Membership › the policy', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('an answer that is not one of the three is refused, and nothing is saved', async ({ page, hub }) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 1 });

		await page.goto(MEMBERSHIP);
		await policy(page, 'invite').evaluate((radio: HTMLInputElement) => {
			radio.value = 'everyone';
			radio.checked = true;
		});
		await Promise.all([
			page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document'),
			saveButton(page).click(),
		]);

		await expect(adminError(page), 'the refusal is on the screen').toBeVisible();
		await expect(adminSaved(page), 'and no “Saved.” over it').toHaveCount(0);
		expect((await hub.site.getOptions(['diluxone_users_membership'])).diluxone_users_membership).toBe('click');
	});

	test('the button confirms the policy while it waits, and saves it once it is confirmed; the notice points here', async ({ page, hub }) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 0 });

		// The network's dashboard says the policy is waiting, and its link
		// is the way here.
		await page.goto(network(''));

		const link = page.locator(`.notice a[href*="page=diluxone-users-membership"]`);

		await expect(link, 'the unconfirmed notice links to the screen').toBeVisible();
		await navigated(page, () => link.click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-membership');

		const waiting = (await saveButton(page).textContent())?.trim();

		await expect(page.locator('.diluxone-users-not-now'), 'the “not confirmed yet” box').toBeVisible();
		await expect(railState(page), 'the rail: waiting').toHaveClass(/diluxone-users-state--pending/);

		await savePanel(page);

		const saved = (await saveButton(page).textContent())?.trim();

		expect(saved, 'the button says something else once there is nothing to confirm').not.toBe(waiting);
		expect(Number((await hub.site.getOptions(['diluxone_users_membership_confirmed'])).diluxone_users_membership_confirmed)).toBe(1);
		await expect(page.locator('.diluxone-users-not-now'), 'no box once confirmed').toHaveCount(0);

		await page.goto(network(''));
		await expect(page.locator(`.notice a[href*="page=diluxone-users-membership"]`), 'the notice is gone').toHaveCount(0);
	});
});

test.describe('What the policy gives, and to whom', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('a site whose default role is editor or administrator gives a new member subscriber, never more', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 1, diluxone_users_login_role: 'subscriber' });

		const was = { alpha: wp(['option', 'get', 'default_role'], alpha.url), beta: wp(['option', 'get', 'default_role'], beta.url) };

		wp(['option', 'update', 'default_role', 'editor'], alpha.url);
		wp(['option', 'update', 'default_role', 'administrator'], beta.url);

		try {
			const email = freshEmail('hub-role');

			await page.goto(network('user-new.php'));
			await page.locator('input[name="user[username]"]').fill(email.split('@')[0].replace(/[^a-z0-9]/g, ''));
			await page.locator('input[name="user[email]"]').fill(email);
			await navigated(page, () => page.locator('#add-user').click());

			expect((await alpha.site.user(email)).roles, '/alpha/ says editor; self-serve never hands that out').toEqual(['subscriber']);
			expect((await beta.site.user(email)).roles, '/beta/ says administrator; nor that').toEqual(['subscriber']);
		} finally {
			wp(['option', 'update', 'default_role', was.alpha], alpha.url);
			wp(['option', 'update', 'default_role', was.beta], beta.url);
		}
	});

	test('a new site under “whoever asks” gets nobody, and queues nothing', async ({ hub }) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-newsite-click');

		await hub.site.makeUser({ email, password: PASSWORD });

		const site = throwawaySite('e2e-click-born');

		try {
			expect(queued(), 'no job for the new site').toBe(0);
			expect((await (await Site.open(site.url)).user(email)).member, 'not a member of the new site').toBe(false);
		} finally {
			site.done();
		}
	});

	test('an archived site gets nobody: not from a new account, not from the sync', async ({ hub }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 1 });

		const site = throwawaySite('e2e-archived');

		wp(['site', 'archive', String(site.id)]);

		try {
			const email = freshEmail('hub-archived');

			await hub.site.makeUser({ email, password: PASSWORD });
			wp(['diluxone-users', 'network', 'membership', 'sync']);
			drain();

			const sites = (await hub.site.user(email)).sites as number[];

			expect(sites, 'a member of the hub').toContain(1);
			expect(sites, 'never of the archived site').not.toContain(site.id);
		} finally {
			wp(['site', 'unarchive', String(site.id)]);
			site.done();
		}
	});

	test('a closed account is added nowhere by the sync, and a super admin is never shown a site to join', async ({ page, hub, beta }) => {
		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });

		const closed = freshEmail('hub-closed');

		await hub.site.makeUser({ email: closed, password: PASSWORD, meta: { diluxone_users_closed: Math.floor(Date.now() / 1000) } });
		await hub.set({ diluxone_users_membership: 'all' });
		wp(['diluxone-users', 'network', 'membership', 'sync']);
		drain();

		expect((await beta.site.user(closed)).member, 'a closed account is nobody to add').toBe(false);

		// The super admin, under “whoever asks”, on /beta/: reaches every
		// site already, so there is no box.
		await hub.set({ diluxone_users_membership: 'click' });

		const join = joinPage(beta);

		try {
			await page.goto(join.url);
			expect(await joinBox(page), 'no join box for a super admin').toBeNull();
			await page.goto(`${beta.url}?diluxone-users=join`);
			expect(await joinBox(page), 'not even when the way back says “join”').toBeNull();
		} finally {
			forgetJoinPage(beta, join);
		}
	});
});

test.describe('A removal is a decision', () => {
	test('removed from /beta/, under “whoever asks” they are told it is by invitation; the sync under “every site” skips them', async ({
		guest,
		hub,
		beta,
	}) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-removed-click');

		await hub.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_removed_from: [blogId(beta)] } });

		const menu = await beta.site.menu();
		const join = joinPage(beta);

		await beta.set({ diluxone_users_menu_location: menu.location });

		try {
			await signInFrom(guest, beta, hub, email, PASSWORD);
			await guest.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });
			expect(await joinBox(guest), 'at the top: by invitation, for them').toBe('invite');

			await guest.goto(join.url);
			expect(await joinBox(guest), 'on the shortcode’s page too').toBe('invite');
			await expect(guest.locator('[data-diluxone-users-join] button[type="submit"]'), 'no button to press').toHaveCount(0);

			await guest.goto(menu.url);
			await expect(guest.locator('.diluxone-users-menu--invite'), 'the menu says so').toHaveCount(1);
			await expect(guest.locator('.diluxone-users-menu--join'), 'and offers no join').toHaveCount(0);

			await hub.set({ diluxone_users_membership: 'all' });
			wp(['diluxone-users', 'network', 'membership', 'sync']);
			drain();

			expect((await beta.site.user(email)).member, 'the sync left them out of /beta/').toBe(false);
		} finally {
			await beta.site.forgetMenu();
			forgetJoinPage(beta, join);
		}
	});

});

test.describe('A removal an administrator undoes', () => {
	test.use({ storageState: NETWORK_ADMIN_STATE });

	test('an administrator adding them back clears the removal, and the policy applies to them again', async ({ page, guest, hub, beta }) => {
		// Made while the network was by invitation, so the removal is
		// written before any policy could have added them.
		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-added-back');

		await hub.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_removed_from: [blogId(beta)] } });
		await hub.set({ diluxone_users_membership: 'all' });
		expect((await beta.site.user(email)).member, 'removed: not a member').toBe(false);

		// /beta/'s administrator adds them, the way WordPress offers it.
		await page.goto(beta.admin('user-new.php'));
		await page.locator('#adduser-email').fill(email);
		await page.locator('#adduser-role').selectOption('subscriber');

		const noConfirm = page.locator('#adduser-noconfirmation');

		if ((await noConfirm.count()) > 0) {
			await noConfirm.check();
		}

		await navigated(page, () => page.locator('#addusersub').click());

		expect((await beta.site.user(email)).member, 'a member of /beta/ again').toBe(true);
		expect(userMeta(email, 'diluxone_users_removed_from'), 'the removal is over').toBe('');

		// And signing in from /beta/ under “every site” keeps it so.
		await signInFrom(guest, beta, hub, email, PASSWORD);
		expect((await beta.site.user(email)).member).toBe(true);
		expect(await whoOn(guest, beta.url), 'a member, with /beta/’s profile').toBe(email);
	});

	test('a site deleted is not a removal anybody decided: nothing is written down', async ({ hub }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 1 });

		const site = throwawaySite('e2e-goes');
		const email = freshEmail('hub-site-goes');

		await hub.site.makeUser({ email, password: PASSWORD });
		drain();
		expect(((await hub.site.user(email)).sites as number[]).includes(site.id), 'a member of the throwaway site').toBe(true);

		site.done();

		expect(userMeta(email, 'diluxone_users_removed_from'), 'no removal written for a site that is gone').toBe('');
	});
});

test.describe('“Join this site”, what is drawn', () => {
	test('refused, the page says it is by invitation, with no welcome', async ({ browser, hub, beta }) => {
		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-join-refused');

		await hub.site.makeUser({ email, password: PASSWORD });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const page = await context.newPage();

		try {
			await signInFrom(page, beta, hub, email, PASSWORD);
			await page.goto(`${beta.url}?diluxone-users=join-refused`);

			expect(await joinBox(page), 'the box at the top says by invitation').toBe('invite');
			await expect(page.locator('[data-diluxone-users-join="joined"]'), 'no welcome').toHaveCount(0);
			expect((await beta.site.user(email)).member).toBe(false);
		} finally {
			await context.close();
		}
	});

	test('the shortcode draws nothing for a stranger or a member, and once when the top of the page already has the box', async ({
		browser,
		guest,
		hub,
		beta,
	}) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 1 });

		const join = joinPage(beta);
		const member = freshEmail('hub-join-member');
		const asker = freshEmail('hub-join-asker');

		await beta.site.makeUser({ email: member, password: PASSWORD });
		await hub.site.makeUser({ email: asker, password: PASSWORD });

		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
		const page = await context.newPage();

		try {
			await guest.goto(join.url);
			await expect(guest.locator('[data-diluxone-users-join]'), 'a stranger: nothing').toHaveCount(0);

			await signInFrom(page, beta, hub, member, PASSWORD);
			await page.goto(join.url);
			await expect(page.locator('[data-diluxone-users-join]'), 'a member: nothing').toHaveCount(0);

			await context.clearCookies();
			await signInFrom(page, beta, hub, asker, PASSWORD);
			await page.goto(`${join.url}${join.url.includes('?') ? '&' : '?'}diluxone-users=join`);
			await expect(
				page.locator('[data-diluxone-users-join]'),
				'bug: drawn twice — the box at the top (wp_body_open) and the shortcode’s. includes/membership-join.php: diluxone_users_shortcode_join() asks diluxone_users_join_drawn() before the top box is drawn, because a block theme renders the content before wp_body_open fires'
			).toHaveCount(1);
		} finally {
			await context.close();
			forgetJoinPage(beta, join);
		}
	});

	test('a password typed on the hub’s wp-login.php, on the way back to /beta/, lands on the box that offers to join', async ({ guest, hub, beta }) => {
		await hub.set({ diluxone_users_membership: 'click', diluxone_users_membership_confirmed: 1 });

		const email = freshEmail('hub-join-wplogin');

		await hub.site.makeUser({ email, password: PASSWORD });

		await guest.goto(`${beta.url}wp-login.php`);
		expect(new URL(guest.url()).pathname, 'sent to the hub’s wp-login.php').not.toContain('/beta/');

		await signInWithPassword(guest, email, PASSWORD);
		await guest.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' });

		expect(new URL(guest.url()).searchParams.get('diluxone-users'), 'the way back says there is a site to join').toBe('join');
		expect(await joinBox(guest)).toBe('click');
	});
});

test.describe('`wp diluxone-users network membership sync` refuses what it should', () => {
	test('while the policy is not confirmed, and while another run holds the lock', async ({ hub }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 0 });

		expect(() => wp(['diluxone-users', 'network', 'membership', 'sync']), 'refused while unconfirmed').toThrow(/confirm/i);

		await hub.set({ diluxone_users_membership_confirmed: 1 });
		wp(['transient', 'set', 'diluxone_users_membership_lock', '1', '300', '--network']);

		try {
			expect(() => wp(['diluxone-users', 'network', 'membership', 'sync']), 'refused while another run holds the lock').toThrow(/another run/i);
		} finally {
			wp(['transient', 'delete', 'diluxone_users_membership_lock', '--network']);
		}

		expect(wp(['diluxone-users', 'network', 'membership', 'sync']), 'and runs once the lock is gone').toContain('Success');
	});
});
