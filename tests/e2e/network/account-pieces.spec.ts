import { test, expect, hubDoor, whoOn } from './support';
import { freshEmail } from '../support/api';
import { signInWithPassword } from '../support/ui';

/**
 * The pieces of the account on a page of a site of the network.
 *
 * On a single site each piece is the thing itself (`specs/account-pieces`). On
 * a network the account lives on the hub, so the same page on /beta/ draws,
 * for each piece, a door to the account on the hub — never a form that would
 * write to an account from the wrong site — and still nothing to a stranger.
 * On the hub the same page draws the forms.
 */

const PASSWORD = 'e2e-Pieces-1!';
const SLUG = 'pieces';

const SHORTCODES = [
	'diluxone_users_account_nav',
	'diluxone_users_fields',
	'diluxone_users_sessions',
	'diluxone_users_accounts',
	'diluxone_users_avatar',
	'diluxone_users_handle',
	'diluxone_users_notifications',
];

const PIECES = SHORTCODES.map((tag) => `[${tag}]`).join('\n\n');

/** The forms the pieces post, none of which may be drawn off the hub. */
const FORMS = ['diluxone_users_fields_save', 'diluxone_users_sessions', 'diluxone_users_avatar', 'diluxone_users_handle', 'diluxone_users_notifications'];

const FIELDS = [{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }];

test.beforeEach(async ({ hub }) => {
	await hub.set({ diluxone_users_fields: FIELDS, diluxone_users_avatar_upload: 1, diluxone_users_handle_enabled: 1, diluxone_users_2fa_mode: 'off' });
});

test('on /beta/ every piece is a door to the account on the hub, and nothing to a stranger; on the hub they are the forms', async ({
	page,
	guest,
	hub,
	beta,
}) => {
	const here = await beta.site.page(SLUG, PIECES);
	const there = await hub.site.page(SLUG, PIECES);

	try {
		// A stranger: nothing at all, not even a door.
		await guest.goto(here.url);
		await expect(hubDoor(guest, 'account'), 'the pieces draw nothing to a stranger').toHaveCount(0);
		await expect(hubDoor(guest, 'login')).toHaveCount(0);

		// Somebody signed in on the hub, which signs them in on the network.
		const email = freshEmail('net-pieces');

		await hub.site.makeUser({ email, password: PASSWORD });
		await page.goto(hub.pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		expect(await whoOn(page, beta.url)).toBe(email);

		await page.goto(here.url);

		const doors = hubDoor(page, 'account');

		await expect(doors).toHaveCount(SHORTCODES.length);

		for (const href of await doors.locator('a').evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).href))) {
			expect(href.startsWith(hub.pages.account.url), `${href} is the account on the hub`).toBe(true);
		}

		for (const action of FORMS) {
			await expect(page.locator(`form input[name="action"][value="${action}"]`), `no ${action} form on /beta/`).toHaveCount(0);
		}

		// The hub's own page with the same pieces draws them.
		await page.goto(there.url);
		await expect(hubDoor(page, 'account')).toHaveCount(0);
		await expect(page.locator('.diluxone-users-account__nav')).toBeAttached();

		await expect(page.locator('.diluxone-users-sessions')).toBeAttached();

		// The sessions piece has a form only with another session to close.
		for (const action of FORMS.filter((name) => name !== 'diluxone_users_sessions')) {
			await expect(page.locator(`form input[name="action"][value="${action}"]`).first(), `the ${action} form on the hub`).toBeAttached();
		}
	} finally {
		await beta.site.forgetPage(SLUG);
		await hub.site.forgetPage(SLUG);
	}
});
