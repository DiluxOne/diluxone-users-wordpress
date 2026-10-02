import { Page } from '@playwright/test';
import { test, expect, whoOn, signInFrom } from './support';
import { freshEmail } from '../support/api';
import { accountSection, challengeCode, challengeScreen, openAllPanels } from '../support/ui';
import { avoidWindowEdge, totp } from '../support/totp';

/**
 * The account lives on the hub: what a person of /alpha/ or /beta/ does there
 * is what every site of the network sees. The door matrix leaves these as
 * "the form is drawn"; here each form is used on the hub and its effect is
 * read from another site.
 */

const PASSWORD = 'e2e-HubAccount-1!';

const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

/** Presses a form's submit button and waits for the redirect's answer. */
async function send(page: Page, form: ReturnType<Page['locator']>): Promise<string> {
	await Promise.all([page.waitForURL(/[?&]diluxone-users=/, { waitUntil: 'domcontentloaded' }), form.locator('button[type="submit"]').first().click()]);

	return new URL(page.url()).searchParams.get('diluxone-users') ?? '';
}

function formFor(page: Page, action: string) {
	return page.locator('form').filter({ has: page.locator(`input[name="action"][value="${action}"]`) });
}

test.describe('The account on the hub, used by a person of another site', () => {
	test('the app set up on the hub’s security section is asked for when they sign in from /beta/', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_2fa_methods: ['totp', 'email'], diluxone_users_2fa_scope: 'all' });

		const email = freshEmail('hub-acct-totp');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await signInFrom(page, alpha, hub, email, PASSWORD);

		await page.goto(accountSection(hub.pages.account.url, 'security'));
		await openAllPanels(page);

		const secret = (await page.locator('.diluxone-users-totp__key').innerText()).replace(/\s+/g, '');

		expect(secret, 'a base32 secret').toMatch(/^[A-Z2-7]{16,}$/);
		await avoidWindowEdge();

		const setup = page.locator('form').filter({ has: page.locator('input[name="diluxone_users_security"][value="totp"]') });

		await setup.locator('input[name="diluxone_users_code"]').fill(totp(secret));
		await send(page, setup);

		const saved = await beta.site.user(email);

		expect(saved.meta.diluxone_users_totp, '/beta/ sees the app registered: it is the account’s').not.toBe('');
		expect(saved.meta.diluxone_users_2fa_on).toBe('1');

		// Out, and in again from /beta/: the app is asked for.
		await page.context().clearCookies();
		await signInFrom(page, beta, hub, email, PASSWORD);
		await expect(challengeScreen(page), 'asked for the app’s code').toBeVisible();

		// The activation code is spent: wait for the next window.
		await avoidWindowEdge();
		await challengeCode(page).fill(totp(secret, Date.now() + 30_000));
		await Promise.all([
			page.waitForURL((url) => url.href.startsWith(beta.url), { waitUntil: 'domcontentloaded' }),
			challengeScreen(page).locator('button[type="submit"]').first().click(),
		]);
		expect(await whoOn(page, beta.url), 'in, back on /beta/').toBe(email);
	});

	test('details saved on the hub are what /beta/ reads', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_fields: FIELDS });

		const email = freshEmail('hub-acct-details');

		await alpha.site.makeUser({ email, password: PASSWORD });
		await signInFrom(page, alpha, hub, email, PASSWORD);

		await page.goto(accountSection(hub.pages.account.url, 'details'));
		await openAllPanels(page);

		const form = formFor(page, 'diluxone_users_fields_save');

		await form.locator('input[name="first_name"]').fill('Grace');
		await form.locator('input[name="last_name"]').fill('Hopper');
		expect(await send(page, form)).toBe('saved');

		const read = await beta.site.user(email);

		expect(read.meta.first_name, '/beta/ reads the first name').toBe('Grace');
		expect(read.meta.last_name).toBe('Hopper');
	});

	test('the public name chosen on the hub is the account’s, on every site', async ({ page, hub, alpha, beta }) => {
		await hub.set({ diluxone_users_handle_enabled: 1, diluxone_users_handle_cooldown: 0 });

		const email = freshEmail('hub-acct-handle');
		const handle = `hub-${Date.now().toString(36)}`;

		await alpha.site.makeUser({ email, password: PASSWORD });
		await signInFrom(page, alpha, hub, email, PASSWORD);

		await page.goto(accountSection(hub.pages.account.url, 'details'));
		await openAllPanels(page);

		const form = formFor(page, 'diluxone_users_handle');

		await form.locator('input[name="diluxone_users_handle"]').fill(handle);
		expect(await send(page, form)).toBe('saved');

		expect((await beta.site.user(email, ['diluxone_users_handle'])).fields.diluxone_users_handle, '/beta/ reads it').toBe(handle);
	});

	test('the other sessions closed from the hub are closed on /beta/ too', async ({ browser, page, hub, beta }) => {
		const email = freshEmail('hub-acct-sessions');

		await beta.site.makeUser({ email, password: PASSWORD });

		const other = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage();

		try {
			await signInFrom(other, beta, hub, email, PASSWORD);
			await signInFrom(page, beta, hub, email, PASSWORD);
			expect((await hub.site.user(email)).sessions, 'two sessions').toBe(2);

			await page.goto(accountSection(hub.pages.account.url, 'security'));
			await openAllPanels(page);

			const all = page.locator('form.diluxone-users-sessions__all');

			expect(await send(page, all)).toBe('sessions');

			expect((await hub.site.user(email)).sessions, 'one left: this one').toBe(1);
			expect(await whoOn(other, beta.url), 'the other browser is signed out of /beta/').toBeNull();
			expect(await whoOn(page, beta.url), 'this one is still in').toBe(email);
		} finally {
			await other.context().close();
		}
	});
});
