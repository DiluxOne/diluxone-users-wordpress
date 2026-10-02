import { Page } from '@playwright/test';
import { test, expect, hubDoor, whoOn, toTheHub, SiteHandle } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, signInWithPassword, ssoButton } from '../support/ui';
import { wp } from '../support/cli';
import { postByHand, wpFails } from '../support/my-account';

/**
 * The account area's doors on a network, past the ones the other network
 * specs walk: the account page on /beta/ for somebody signed in, every form
 * of the account posted to /beta/ instead of the hub, the confirmation and
 * the download used on /beta/, closing an account that administers another
 * site or the whole network, the shell nothing reopens, and WP-CLI.
 *
 * An account anonymised on closing loses its @e2e.test address and the
 * suite's sweep would not find it, so each test that makes one deletes it by
 * id from the whole network before it ends.
 */

const PASSWORD = 'e2e-MyHub-1!';

const MOCK_SSO = {
	diluxone_e2e_sso: 1,
	diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
	diluxone_users_sso_login: 1,
	diluxone_users_sso_register: 0,
	diluxone_users_sso_link_by_email: 1,
	diluxone_users_sso_verified_only: 0,
};

/** The requests this file filed on the hub (`nh-…@e2e.test`), gone with it. */
test.afterAll(() => {
	const rows = JSON.parse(
		wp(['post', 'list', '--post_type=user_request', '--post_status=any', '--posts_per_page=-1', '--fields=ID,post_title', '--format=json'])
	) as Array<{ ID: number; post_title: string }>;
	const ours = rows.filter((row) => /^nh-[a-z-]+-[a-z0-9]+-[a-z0-9]+@e2e\.test$/.test(row.post_title)).map((row) => String(row.ID));

	if (ours.length > 0) {
		wp(['post', 'delete', ...ours, '--force']);
	}
});

test.beforeEach(async ({ hub }) => {
	await hub.set({
		diluxone_users_fields: [{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }],
		diluxone_users_2fa_mode: 'off',
		diluxone_users_privacy_export: 1,
		diluxone_users_privacy_delete: 1,
		diluxone_users_privacy_delete_link: 'account',
		diluxone_users_privacy_delete_when: 'confirm',
		diluxone_users_handle_enabled: 1,
	});
});

/** Signed in on the hub, which signs them in on the network. */
async function onTheHub(page: Page, hub: SiteHandle, email: string): Promise<void> {
	await page.goto(hub.pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await page.waitForLoadState('domcontentloaded');
	expect(await whoOn(page, hub.url)).toBe(email);
}

/** Asks from the hub's account to be erased, and opens the confirmation it mails. */
async function askAndConfirm(page: Page, hub: SiteHandle, email: string): Promise<void> {
	await page.goto(accountSection(hub.pages.account.url, 'privacy'));

	const button = page.locator('form:has(input[name="diluxone_users_request"][value="erase"]) button[type="submit"]');
	const folded = page.locator('details:not([open])').filter({ has: button });

	if ((await folded.count()) > 0) {
		await folded.first().locator('> summary').click();
	}

	await button.click();
	await Promise.all([page.waitForURL(/diluxone-users=requested/), page.locator('dialog#diluxone-users-ask-erase [data-diluxone-users-dialog-ok]').click()]);
	await page.goto(linkIn(await waitForMail(hub.site, email), /https?:\/\/\S+action=confirmaction\S*/));
	await expect(page.locator('.diluxone-users-closing')).toBeVisible();
}

/** Deletes an account from the whole network by id: a shell has no @e2e.test address left. */
function forget(id: number): void {
	try {
		wp(['user', 'delete', String(id), '--network', '--yes']);
	} catch {
		// Already gone: nothing to put back.
	}
}

test('signed in, the account page on /beta/ is a door to the account on the hub, with no section and no form', async ({ page, hub, beta }) => {
	const email = freshEmail('nh-door');

	await hub.site.makeUser({ email, password: PASSWORD });
	await onTheHub(page, hub, email);

	await page.goto(beta.pages.account.url);

	const door = hubDoor(page, 'account');

	await expect(door).toHaveCount(1);
	expect(await door.locator('a').getAttribute('href')).toMatch(new RegExp(`^${hub.pages.account.url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
	await expect(page.locator('.diluxone-users-account__section')).toHaveCount(0);
	await expect(page.locator('form input[name="action"][value^="diluxone_users_"]')).toHaveCount(0);
});

test.describe('Forms posted to /beta/', () => {
	/** Every form of the account, with what it would write. */
	const POSTS: Array<{ action: string; fields: Record<string, string> }> = [
		{ action: 'diluxone_users_fields_save', fields: { first_name: 'Forged' } },
		{ action: 'diluxone_users_handle', fields: { diluxone_users_handle: 'forged-name' } },
		{ action: 'diluxone_users_avatar', fields: { diluxone_users_avatar_remove: '1' } },
		{ action: 'diluxone_users_notifications', fields: { diluxone_users_notify_login: '0' } },
		{ action: 'diluxone_users_security', fields: { diluxone_users_security: 'on' } },
		{ action: 'diluxone_users_sessions', fields: {} },
		{ action: 'diluxone_users_data_request', fields: { diluxone_users_request: 'export' } },
		{ action: 'diluxone_users_passkey', fields: { diluxone_users_passkey: 'x', diluxone_users_passkey_do: 'delete' } },
		{ action: 'diluxone_users_sso_unlink', fields: { diluxone_users_provider: 'mock' } },
		{ action: 'diluxone_users_link_request', fields: { diluxone_users_email: 'nobody@e2e.test' } },
		{ action: 'diluxone_users_signup', fields: { diluxone_users_email: 'nobody@e2e.test' } },
		{ action: 'diluxone_users_reset', fields: { pass1: 'x', pass2: 'x' } },
	];

	test('each goes to the hub before its handler runs: the account when signed in, the sign-in page when not — and nothing is written', async ({
		page,
		guest,
		hub,
		beta,
		browser,
	}) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['email'] });

		const email = freshEmail('nh-posts');

		await hub.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: 'mock|kept-net' } });
		await onTheHub(page, hub, email);

		// A second session, so "close the others" would have something to close.
		const second = await (await browser.newContext({ storageState: undefined })).newPage();

		await onTheHub(second, hub, email);

		const before = await hub.site.user(email, ['diluxone_users_handle', 'diluxone_users_notify_login']);

		await hub.site.clearMail();

		for (const { action, fields } of POSTS) {
			const signedIn = await postByHand(page, { action, ...fields }, { url: beta.admin('admin-post.php') });

			expect(signedIn.status, action).toBe(302);
			expect(signedIn.location.startsWith(hub.pages.account.url), `${action} → ${signedIn.location}`).toBe(true);

			const stranger = await postByHand(guest, { action, ...fields }, { url: beta.admin('admin-post.php') });

			expect(stranger.status, `${action} as a stranger`).toBe(302);
			expect(stranger.location.startsWith(hub.pages.login.url), `${action} as a stranger → ${stranger.location}`).toBe(true);
		}

		const after = await hub.site.user(email, ['diluxone_users_handle', 'diluxone_users_notify_login']);

		expect(after.meta.first_name).toBe(before.meta.first_name);
		expect(after.meta.diluxone_users_2fa_on).toBe('');
		expect(after.meta.diluxone_users_sso_mock).toBe('mock|kept-net');
		expect(after.fields).toEqual(before.fields);
		expect(after.sessions, 'no session was closed').toBe(2);
		expect(await hub.site.mail(email), 'nothing was mailed').toEqual([]);
		expect(await hub.site.mail('nobody@e2e.test')).toEqual([]);
		await second.context().close();
	});

	test('the last step, the download and the confirmation link used on /beta/ close nothing and hand over nothing', async ({ page, hub, beta }) => {
		const email = freshEmail('nh-beta-links');
		const person = await hub.site.makeUser({ email, password: PASSWORD });

		await onTheHub(page, hub, email);

		// A copy, ready on the hub: its download pointed at /beta/.
		await page.goto(accountSection(hub.pages.account.url, 'privacy'));

		const exportButton = page.locator('form:has(input[name="diluxone_users_request"][value="export"]) button[type="submit"]');
		const folded = page.locator('details:not([open])').filter({ has: exportButton });

		if ((await folded.count()) > 0) {
			await folded.first().locator('> summary').click();
		}

		await Promise.all([page.waitForURL(/diluxone-users=requested/), exportButton.click()]);
		await page.goto(linkIn(await waitForMail(hub.site, email), /https?:\/\/\S+action=confirmaction\S*/));
		await expect(page).toHaveURL(/diluxone-users=ready/);

		const href = (await page.locator('.diluxone-users-requests a[download]').first().getAttribute('href')) as string;
		const onBeta = href.replace(hub.admin('admin-post.php'), beta.admin('admin-post.php'));

		expect(onBeta).toContain('/beta/');

		const file = await page.request.get(onBeta, { failOnStatusCode: false });

		expect((await file.body()).subarray(0, 2).toString(), 'no file handed over by /beta/').not.toBe('PK');

		// The erasure: confirmed on the hub up to the last step, then that step and the link used on /beta/.
		await hub.site.clearMail();
		await askAndConfirm(page, hub, email);

		const closing = page.locator('form:has(input[name="action"][value="diluxone_users_confirm_close"])');
		const fields = {
			action: 'diluxone_users_confirm_close',
			diluxone_users_request_id: await closing.locator('input[name="diluxone_users_request_id"]').inputValue(),
			diluxone_users_key: await closing.locator('input[name="diluxone_users_key"]').inputValue(),
			_wpnonce: await closing.locator('input[name="_wpnonce"]').inputValue(),
		};

		await postByHand(page, fields, { url: beta.admin('admin-post.php') });
		expect((await hub.site.user(email)).exists, 'the last step posted to /beta/ closed nothing').toBe(true);

		const link = new URL(linkIn(await waitForMail(hub.site, email), /https?:\/\/\S+action=confirmaction\S*/));

		await page.goto(`${beta.url}wp-login.php${link.search}`);
		await expect(page.locator('.diluxone-users-closing')).toHaveCount(0);
		expect((await hub.site.user(email)).exists, 'and the link on /beta/ closed nothing').toBe(true);
		expect((await hub.site.user(email)).id).toBe(person.id);
	});
});

test.describe('Closing an account on a network', () => {
	test('somebody who administers /beta/ but is a subscriber on the hub: asked there, the account is emptied and loses its /beta/ role', async ({
		page,
		hub,
		beta,
	}) => {
		const email = freshEmail('nh-betaadmin');
		const person = await hub.site.makeUser({ email, password: PASSWORD });

		await beta.site.makeUser({ email, password: PASSWORD, role: 'administrator' });

		try {
			await onTheHub(page, hub, email);
			await askAndConfirm(page, hub, email);

			const pressed = Math.floor(Date.now() / 1000);

			await Promise.all([page.waitForURL(/diluxone-users=closed/), page.locator('.diluxone-users-closing button[type="submit"]').click()]);

			expect((await hub.site.user(email)).exists).toBe(false);
			expect(wp(['user', 'get', String(person.id), '--field=user_login'])).toBe(`deleted-${person.id}`);
			expect(wp(['user', 'get', String(person.id), '--field=roles'], beta.url), 'no role left on /beta/').toBe('');

			const told = (await hub.site.mail(email)).filter((mail) => mail.sent >= pressed && !mail.body.includes('confirmaction'));

			expect(told.length, 'the “your account was deleted” mail').toBe(1);
		} finally {
			forget(person.id);
		}
	});

	test('a super admin is offered no erasure, and one posted by hand is refused', async ({ page, hub }) => {
		const email = freshEmail('nh-super');
		const person = await hub.site.makeUser({ email, password: PASSWORD });

		wp(['super-admin', 'add', email]);

		try {
			await onTheHub(page, hub, email);
			await page.goto(accountSection(hub.pages.account.url, 'privacy'));
			await expect(page.locator('form:has(input[name="diluxone_users_request"][value="erase"])')).toHaveCount(0);

			const nonce = await page.locator('form:has(input[name="diluxone_users_request"]) input[name="_wpnonce"]').first().inputValue();
			const answer = await postByHand(page, { action: 'diluxone_users_data_request', diluxone_users_request: 'erase', _wpnonce: nonce }, { url: hub.admin('admin-post.php') });

			expect(answer.state).toBe('admin');
			expect(await hub.site.mail(email)).toEqual([]);
			expect((await hub.site.user(email)).exists).toBe(true);
		} finally {
			wp(['super-admin', 'remove', email]);
			forget(person.id);
		}
	});

	test('a social account linked before closing does not open the shell afterwards', async ({ page, hub, beta }) => {
		await hub.set(MOCK_SSO);

		const email = freshEmail('nh-shell');
		const sub = `mock|net-shell-${Date.now().toString(36)}`;
		const person = await hub.site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_sso_mock: sub } });

		// A member of /beta/ too, so closing empties the account instead of deleting it.
		await beta.site.makeUser({ email, password: PASSWORD });

		try {
			await onTheHub(page, hub, email);
			await askAndConfirm(page, hub, email);
			await Promise.all([page.waitForURL(/diluxone-users=closed/), page.locator('.diluxone-users-closing button[type="submit"]').click()]);
			expect(wp(['user', 'get', String(person.id), '--field=user_login'])).toBe(`deleted-${person.id}`);
			const keys = (JSON.parse(wp(['user', 'meta', 'list', String(person.id), '--format=json'])) as Array<{ meta_key: string }>).map((row) => row.meta_key);

			expect(keys, 'the identity was erased').not.toContain('diluxone_users_sso_mock');

			await hub.site.setIdentity({ sub, email, email_verified: true });
			await page.context().clearCookies();
			await toTheHub(page, beta, hub);
			await ssoButton(page, 'mock').click();
			await page.waitForLoadState('domcontentloaded');

			const who = await whoOn(page, hub.url);

			if (who !== null && who !== '') {
				expect((await hub.site.user(who)).id, 'whoever got in, it is not the shell').not.toBe(person.id);
			} else {
				expect(who, 'nobody got in').toBeNull();
			}
		} finally {
			forget(person.id);
		}
	});
});

test.describe('WP-CLI on the network', () => {
	test('login --send mails the link from the hub', async ({ hub }) => {
		const email = freshEmail('nh-cli-send');

		await hub.site.makeUser({ email, password: PASSWORD });

		expect(wp(['diluxone-users', 'login', email, '--send'])).toContain('E-mail sent.');
		expect((await waitForMail(hub.site, email)).body).toContain('diluxone_users_token');
	});

	test('login refuses an address that is not one, or that nobody on the network has', () => {
		const bad = wpFails('tests-cli', ['diluxone-users', 'login', 'not-an-email']);

		expect(bad.failed).toBe(true);
		expect(bad.out).toContain('A valid e-mail address is needed.');

		const unknown = wpFails('tests-cli', ['diluxone-users', 'login', freshEmail('nh-cli-nobody')]);

		expect(unknown.failed).toBe(true);
		expect(unknown.out).toContain('There is no account with the e-mail');
	});

	test('membership sync refuses while the policy is unconfirmed, and names the policy when it is not “all”', async ({ hub }) => {
		await hub.set({ diluxone_users_membership: 'all', diluxone_users_membership_confirmed: 0 });

		const unconfirmed = wpFails('tests-cli', ['diluxone-users', 'network', 'membership', 'sync']);

		expect(unconfirmed.failed).toBe(true);
		expect(unconfirmed.out).toContain('has not been confirmed');

		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });

		const other = wpFails('tests-cli', ['diluxone-users', 'network', 'membership', 'sync']);

		expect(other.failed).toBe(true);
		expect(other.out).toContain('invite');
	});
});
