import type { Page } from '@playwright/test';
import { test, expect, hubDoor, signInFrom, SiteHandle } from './support';
import { masks, settled } from '../support/pictures';
import { challengeScreen } from '../support/ui';
import { wp } from '../support/cli';

/**
 * The public pages a site of a network draws and a single site never does.
 *
 * Everywhere but the hub, the page that would hold a form holds a door to the
 * hub's instead — a different box with different words, three times over —
 * and a person signed in on the network who is not a member of the site is
 * offered to join it, told it is by invitation, or welcomed. And the hub's
 * own sign-in and second step, which are the single site's screens reached
 * from another site. Opt-in with the network's other pictures:
 * `make test-visual-network`.
 */

const PERSON = { email: 'visual-person@e2e.test', password: 'e2e-Visual-Person-1!' };

/** A page of `one` with the join shortcode on it, deleted by the caller. */
function joinPage(one: SiteHandle): { id: string; url: string } {
	const id = wp(['post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Join', '--post_content=[diluxone_users_join]', '--porcelain'], one.url);

	return { id, url: wp(['post', 'get', id, '--field=url'], one.url) };
}

async function picture(page: Page, block: string, name: string): Promise<void> {
	await settled(page);

	await expect(page.locator(block).first()).toHaveScreenshot(name, { mask: masks(page) });
}

test.beforeEach(async ({ hub }) => {
	// The network's tests site speaks English already; the fields and the
	// social apps are pinned like the network's admin pictures pin them, and
	// the person starts as a member of nothing but what the test gives them.
	await hub.site.deleteUser(PERSON.email);
	await hub.set({
		diluxone_users_fields: [
			{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
		],
		diluxone_users_sso: {},
		diluxone_e2e_sso: 0,
	});
});

test.describe('A site’s doors to the hub look like they did', () => {
	for (const door of ['login', 'register', 'account'] as const) {
		test(`the ${door} door`, async ({ page, alpha }) => {
			await page.goto(alpha.pages[door].url);
			await expect(hubDoor(page, door === 'account' ? 'login' : door)).toBeVisible();

			await picture(page, '.diluxone-users-hub-door', `network-front-door-${door}.png`);
		});
	}
});

test.describe('The hub’s own pages look like they did', () => {
	test('the sign-in page, reached from a site', async ({ page, alpha, hub }) => {
		await page.goto(alpha.pages.login.url);
		await Promise.all([page.waitForURL((url) => url.href.startsWith(hub.pages.login.url)), hubDoor(page).locator('a').click()]);

		await picture(page, '.diluxone-users-login', 'network-front-hub-sign-in.png');
	});

	test('the second step, after coming from a site', async ({ page, alpha, hub }) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'] });
		await hub.site.makeUser({ email: PERSON.email, password: PERSON.password, name: 'Alex Rivera', meta: { diluxone_users_2fa_on: '1', diluxone_users_totp: null } });

		await signInFrom(page, alpha, hub, PERSON.email, PERSON.password);
		await expect(challengeScreen(page)).toBeVisible();

		await picture(page, '.diluxone-users-login--2fa', 'network-front-hub-two-step.png');
	});
});

test.describe('Joining a site looks like it did', () => {
	for (const policy of ['click', 'invite'] as const) {
		test(`offered: ${policy}`, async ({ page, hub, beta }) => {
			await hub.set({ diluxone_users_membership: policy });
			await hub.site.makeUser({ email: PERSON.email, password: PERSON.password, name: 'Alex Rivera', });

			const join = joinPage(beta);

			try {
				await signInFrom(page, beta, hub, PERSON.email, PERSON.password);
				await page.goto(join.url);
				await expect(page.locator(`[data-diluxone-users-join="${policy}"]`)).toBeVisible();

				await picture(page, '[data-diluxone-users-join]', `network-front-join-${policy}.png`);

				if (policy === 'click') {
					await Promise.all([page.waitForURL(/diluxone-users=joined/), page.locator('[data-diluxone-users-join="click"] button[type="submit"]').click()]);
					// Said once on the way back, where the shortcode is: the
					// block theme draws the page's content before its top.
					const welcome = '[data-diluxone-users-join="joined"]';

					await expect(page.locator(welcome)).toHaveCount(1);

					await picture(page, welcome, 'network-front-join-joined.png');
				}
			} finally {
				wp(['post', 'delete', join.id, '--force'], beta.url);
			}
		});
	}
});
