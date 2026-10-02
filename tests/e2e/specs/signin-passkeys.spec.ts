import { Browser, CDPSession, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, challengeScreen, navigated, openAllPanels, openPanel, signInWithPassword } from '../support/ui';
import { Meta, virtualKey } from '../support/signin';

/**
 * Passkeys, every path past the first one.
 *
 * `passkeys.spec.ts` registers a key, signs in with it, renames it and finds
 * a removed key refused. These are the rest: the switch that takes the button
 * and the door away, the ceiling on sign-in challenges, a key another account
 * already holds, the two settings that reach the browser's dialogue, removing
 * a key with the mail it sends, a key of somebody else's renamed or removed
 * from one's own form, the second step that a passkey is never asked for, the
 * new-device notice, and a browser that has no passkeys at all.
 *
 * Chromium only: the virtual authenticator is a DevTools protocol.
 */

const PASSWORD = 'e2e-Passkey-Ways-1!';

/** The script's own data on the page: the ajax address and its nonce. */
async function passkeyData(page: Page): Promise<{ ajax: string; nonce: string; texts: { old: string; error: string } }> {
	return page.evaluate(() => (window as any).diluxOneUsersPasskeys);
}

/** One step of the dialogue, posted the way the script posts it. */
async function ask(page: Page, nonce: string, step: string, extra: Record<string, string> = {}) {
	return page.request.post('/wp-admin/admin-ajax.php', {
		form: { action: 'diluxone_users_passkeys', nonce, step, ...extra },
		failOnStatusCode: false,
	});
}

/** Signs in with a password and adds a key from the security section; waits for it in the list. */
async function addKey(page: Page, pages: any, email: string, label: string): Promise<void> {
	await page.goto(pages.login.url);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);
	await page.goto(accountSection(pages.account.url, 'security'));

	const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');

	await panel.locator('[data-diluxone-users-passkey-label]').fill(label);
	await panel.locator('[data-diluxone-users-passkey="register"]').click();
	await expect(page.locator('input[name="diluxone_users_passkey_label"]').first()).toHaveValue(label, { timeout: 20_000 });
}

/** The ids of the keys stored on an account. */
async function keysOf(meta: Meta, email: string): Promise<Array<{ id: string; label: string }>> {
	const stored = (await meta.get(email, ['diluxone_users_passkeys'])).diluxone_users_passkeys;

	return Array.isArray(stored) ? stored : [];
}

/** Presses the passkey button on the sign-in page and waits to have left it. */
async function signInWithKey(page: Page, loginUrl: string): Promise<void> {
	await page.goto(loginUrl);
	await page.locator('[data-diluxone-users-passkey="login"]').click();
	await page.waitForURL((url) => !url.href.startsWith(loginUrl), { timeout: 20_000 });
}

test.describe('Passkeys, off the first path', () => {
	test.skip(({ browserName }) => browserName !== 'chromium', 'the virtual authenticator is a Chromium protocol');

	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
		});
	});

	test('switched off, the button and its way are gone and the dialogue answers that the site has none', async ({ page, pages, options }) => {
		await page.goto(pages.login.url);
		await expect(page.locator('[data-diluxone-users-passkey="login"]')).toBeVisible();

		const { nonce } = await passkeyData(page);

		await options.set({ diluxone_users_passkey_enabled: 0 });
		await page.goto(pages.login.url);

		await expect(page.locator('[data-diluxone-users-passkey="login"]')).toHaveCount(0);
		await expect(page.locator('[data-diluxone-users-way="passkey"], #diluxone-users-way-passkey')).toHaveCount(0);
		expect(await page.evaluate(() => typeof (window as any).diluxOneUsersPasskeys), 'and no script for it').toBe('undefined');

		const answer = await ask(page, nonce, 'login-options');
		expect(answer.status()).toBe(400);
		expect((await answer.json()).success).toBe(false);
	});

	test('sixty sign-in challenges from one machine are allowed, the sixty-first is told to wait', async ({ page, pages }) => {
		await page.goto(pages.login.url);
		const { nonce } = await passkeyData(page);

		for (let n = 1; n <= 60; n++) {
			const answer = await ask(page, nonce, 'login-options');
			expect(answer.status(), `challenge ${n}`).toBe(200);
		}

		const over = await ask(page, nonce, 'login-options');
		expect(over.status()).toBe(429);
		expect((await over.json()).success).toBe(false);
	});

	test('the two settings reach the browser’s dialogue: verification and which devices', async ({ page, site, pages, options }) => {
		const email = freshEmail('pk-options');
		await site.makeUser({ email, password: PASSWORD });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);
		await page.goto(accountSection(pages.account.url, 'security'));
		const { nonce } = await passkeyData(page);

		const strict = (await (await ask(page, nonce, 'register-options')).json()).data;
		expect(strict.userVerification).toBe('required');
		expect(strict.authenticatorAttachment).toBeNull();

		await options.set({ diluxone_users_passkey_verify: 0, diluxone_users_passkey_where: 'device' });

		const loose = (await (await ask(page, nonce, 'register-options')).json()).data;
		expect(loose.userVerification).toBe('preferred');
		expect(loose.authenticatorAttachment, 'this device only').toBe('platform');

		const signIn = (await (await ask(page, nonce, 'login-options')).json()).data;
		expect(signIn.userVerification, 'the sign-in asks the same').toBe('preferred');
	});

	test('a key another account already holds is not claimed: the page says so and the list stays empty', async ({ page, site, pages, request }) => {
		const owner = freshEmail('pk-owner');
		const other = freshEmail('pk-claimer');
		const meta = new Meta(request);

		await site.makeUser({ email: owner, password: PASSWORD });
		await site.makeUser({ email: other, password: PASSWORD });

		const { cdp, authenticatorId } = await virtualKey(page);

		await addKey(page, pages, owner, 'Owner key');
		const [held] = await keysOf(meta, owner);
		expect(held.id).toBeTruthy();

		// The second account, in the same browser, claims the first one's
		// credential id: the request is rewritten on its way out, the way a
		// script that copied the id would send it.
		await page.context().clearCookies();
		await page.goto(pages.login.url);
		await signInWithPassword(page, other, PASSWORD);
		await expectSignedIn(page, other);
		await page.goto(accountSection(pages.account.url, 'security'));

		await page.route('**/wp-admin/admin-ajax.php', async (route) => {
			const body = new URLSearchParams(route.request().postData() ?? '');

			if (body.get('step') === 'register') {
				body.set('id', held.id);
			}

			await route.continue({ postData: body.toString() });
		});

		const panel = await openPanel(page, '[data-diluxone-users-passkey="register"]');
		await panel.locator('[data-diluxone-users-passkey="register"]').click();

		const said = page.locator('[data-diluxone-users-passkey-notice]');
		await expect(said).toBeVisible({ timeout: 20_000 });
		await expect(said).toHaveClass(/diluxone-users-notice--error/);

		expect(await keysOf(meta, other), 'nothing stored on the second account').toEqual([]);
		expect((await keysOf(meta, owner)).map((one) => one.id), 'the first keeps it').toEqual([held.id]);

		await page.unrouteAll({ behavior: 'ignoreErrors' });
		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('adding and removing a key each mail the owner, and a removed key leaves the list and the account', async ({ page, site, pages, request }) => {
		const email = freshEmail('pk-remove');
		const meta = new Meta(request);

		await site.makeUser({ email, password: PASSWORD });
		const { cdp, authenticatorId } = await virtualKey(page);

		await addKey(page, pages, email, 'Mailed laptop');

		const added = await site.mail(email);
		expect(added.length, 'one notice for the key added').toBe(1);
		expect(added[0].body, 'naming it').toContain('Mailed laptop');
		expect(await keysOf(meta, email)).toHaveLength(1);

		await openAllPanels(page);
		await Promise.all([
			page.waitForURL(/diluxone-users=passkeyoff/),
			page.locator('button[name="diluxone_users_passkey_do"][value="delete"]').first().click(),
		]);

		await expect(page.locator('input[name="diluxone_users_passkey_label"]'), 'gone from the list').toHaveCount(0);
		expect(await keysOf(meta, email), 'and from the account').toEqual([]);
		await expect.poll(async () => (await site.mail(email)).length, { message: 'a second notice, for the removal' }).toBe(2);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('somebody else’s key, named in one’s own form, is neither renamed nor removed, and nobody is mailed', async ({ page, site, pages, request }) => {
		const owner = freshEmail('pk-victim');
		const other = freshEmail('pk-meddler');
		const meta = new Meta(request);

		await site.makeUser({ email: owner, password: PASSWORD });
		await site.makeUser({ email: other, password: PASSWORD });
		const { cdp, authenticatorId } = await virtualKey(page);

		await addKey(page, pages, owner, 'Victim key');
		const [victim] = await keysOf(meta, owner);

		await page.context().clearCookies();
		await addKey(page, pages, other, 'Meddler key');
		await site.clearMail();

		for (const what of ['rename', 'delete'] as const) {
			await page.goto(accountSection(pages.account.url, 'security'));
			await openAllPanels(page);

			const row = page.locator('form').filter({ has: page.locator('input[name="diluxone_users_passkey"]') }).first();
			await row.locator('input[name="diluxone_users_passkey"]').evaluate((input: HTMLInputElement, id: string) => {
				input.value = id;
			}, victim.id);
			await row.locator('input[name="diluxone_users_passkey_label"]').fill('Renamed by somebody else');
			await navigated(page, () => row.locator(`button[name="diluxone_users_passkey_do"][value="${what}"]`).click());
		}

		const after = await keysOf(meta, owner);
		expect(after.map((one) => one.id), 'the owner keeps the key').toEqual([victim.id]);
		expect(after[0].label, 'with its own name').toBe('Victim key');
		expect((await keysOf(meta, other)).map((one) => one.label), 'the meddler’s own key is untouched').toEqual(['Meddler key']);
		expect(await site.mail(owner), 'no false alarm to the owner').toEqual([]);
		expect(await site.mail(other), 'nor to the meddler').toEqual([]);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('with the second step required, a passkey still gets in with nothing more asked', async ({ page, site, pages, options }) => {
		const email = freshEmail('pk-2fa');
		await site.makeUser({ email, password: PASSWORD });
		const { cdp, authenticatorId } = await virtualKey(page);

		await addKey(page, pages, email, 'Two in one');

		await options.set({ diluxone_users_2fa_mode: 'required', diluxone_users_2fa_methods: ['email'], diluxone_users_2fa_remember_days: 0 });
		await site.clearMail();
		await page.context().clearCookies();

		await signInWithKey(page, pages.login.url);

		await expect(challengeScreen(page)).toHaveCount(0);
		await expectSignedIn(page, email);
		expect(await site.mail(email), 'no code went out').toEqual([]);
		expect(await site.wpLogins(email), 'announced once: the password, then the key').toBe(2);

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});

	test('a passkey from a device the account has not seen sends the new-device notice', async ({ browser, baseURL, page, site, pages }) => {
		const email = freshEmail('pk-device');
		await site.makeUser({ email, password: PASSWORD });
		const { cdp, authenticatorId } = await virtualKey(page);

		await addKey(page, pages, email, 'Travelling key');

		// The same key carried to another browser: the credential moved over
		// to an authenticator there, as a synced passkey does.
		const { credentials } = await cdp.send('WebAuthn.getCredentials', { authenticatorId });
		expect(credentials).toHaveLength(1);
		await site.clearMail();

		const context = await (browser as Browser).newContext({
			baseURL,
			storageState: undefined,
			userAgent: 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
		});

		try {
			const elsewhere = await context.newPage();
			const there = await virtualKey(elsewhere);

			await there.cdp.send('WebAuthn.addCredential', { authenticatorId: there.authenticatorId, credential: credentials[0] });
			await signInWithKey(elsewhere, pages.login.url);
			await expectSignedIn(elsewhere, email);

			const mails = await site.mail(email);
			expect(mails.length, 'one notice').toBe(1);
			expect(mails[0].body, 'naming the browser').toContain('Firefox');
		} finally {
			await context.close();
		}

		await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
	});
});

test.describe('A browser with no passkeys at all', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_passkey_enabled: 1, diluxone_users_login_method: 'both' });
	});

	test('the button is not a dead end: pressing it says the browser cannot, or it is not offered', async ({ page, pages }) => {
		await page.addInitScript(() => {
			// @ts-expect-error a browser from before WebAuthn
			delete window.PublicKeyCredential;
		});

		await page.goto(pages.login.url);

		const button = page.locator('[data-diluxone-users-passkey="login"]');

		if ((await button.count()) > 0 && (await button.isVisible())) {
			const { texts } = await passkeyData(page);

			await button.click();

			const said = page.locator('[data-diluxone-users-passkey-notice]');
			await expect(said, 'bug: with no WebAuthn the passkey script stops before wiring the button (assets/diluxone-users-passkeys.js:17), so pressing it does nothing and the “too old” text is never shown').toBeVisible();
			await expect(said).toHaveText(texts.old);
			await expect(said).toHaveClass(/diluxone-users-notice--error/);
		}

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('');
	});
});
