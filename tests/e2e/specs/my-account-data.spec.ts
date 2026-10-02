import { Browser, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { test, expect, expectSignedIn, signedInAs, stateOf } from '../support/fixtures';
import { Site, freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, adminUrl, openPanel, savePanel, signInWithPassword, ssoButton } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';
import { PASSWORD, devWp, formOf, nonceOf, postByHand, reveal, send, signedIn } from '../support/my-account';

/**
 * "Your data": asking for a copy or for the account to go, the links the
 * confirmation e-mails carry — opened right, opened wrong, opened twice — the
 * account closed into an empty shell when it has published something, the
 * file and what is in it, and the e-mails, in the site's words when it wrote
 * its own.
 *
 * An account anonymised on closing loses its @e2e.test address, so the
 * suite's teardown cannot find it: those are deleted here, by id, at the end
 * of the test that made them.
 */

const requestForm = (page: Page, kind: 'export' | 'erase') =>
	page.locator('form').filter({ has: page.locator(`input[name="diluxone_users_request"][value="${kind}"]`) });

/** Asks from the account; erasing goes through the page's own question. */
async function ask(page: Page, accountUrl: string, kind: 'export' | 'erase'): Promise<string> {
	await page.goto(accountSection(accountUrl, 'privacy'));
	await reveal(page, `form:has(input[name="diluxone_users_request"][value="${kind}"]) button[type="submit"]`);

	const button = requestForm(page, kind).locator('button[type="submit"]');

	if (kind === 'export') {
		return send(page, button);
	}

	await button.click();

	const question = page.locator('dialog#diluxone-users-ask-erase');

	await expect(question).toBeVisible();

	return send(page, question.locator('[data-diluxone-users-dialog-ok]'));
}

async function confirmationLink(site: Site, email: string): Promise<string> {
	return linkIn(await waitForMail(site, email, { subject: /./ }), /https?:\/\/\S+action=confirmaction\S*/);
}

/** The account's last step: "Yes, delete my account". */
const yesDelete = (page: Page) => page.locator('.diluxone-users-closing button[type="submit"]');

async function adminPage(browser: Browser, baseURL: string | undefined): Promise<Page> {
	return (await browser.newContext({ baseURL, storageState: ADMIN_STATE })).newPage();
}

/**
 * The requests this file filed, gone with it: WordPress keeps a request as a
 * post long after its account, and a run should not leave a page of them in
 * Tools. Only this file's addresses (`d-…@e2e.test`) are touched.
 */
test.afterAll(() => {
	const rows = JSON.parse(
		devWp(['post', 'list', '--post_type=user_request', '--post_status=any', '--posts_per_page=-1', '--fields=ID,post_title', '--format=json'])
	) as Array<{ ID: number; post_title: string }>;
	const ours = rows.filter((row) => /^d-[a-z]+-[a-z0-9]+-[a-z0-9]+@e2e\.test$/.test(row.post_title)).map((row) => String(row.ID));

	if (ours.length > 0) {
		devWp(['post', 'delete', ...ours, '--force']);
	}
});

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: [{ key: 'e2e_city', label: 'City', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }],
		diluxone_users_2fa_mode: 'off',
		diluxone_users_privacy_export: 1,
		diluxone_users_privacy_delete: 1,
		diluxone_users_privacy_delete_link: 'account',
		diluxone_users_privacy_delete_when: 'confirm',
		diluxone_users_privacy_export_link: 'account',
		diluxone_users_privacy_export_when: 'confirm',
		diluxone_users_privacy_export_file: 'account',
		diluxone_users_account_sections: {},
	});
});

test.describe('Asking', () => {
	test('the question closes on a click outside it and on Esc, and sends nothing', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-dialog');

		await page.goto(accountSection(pages.account.url, 'privacy'));
		await reveal(page, 'form:has(input[name="diluxone_users_request"][value="erase"]) button[type="submit"]');

		const question = page.locator('dialog#diluxone-users-ask-erase');

		await requestForm(page, 'erase').locator('button[type="submit"]').click();
		await expect(question).toBeVisible();
		await page.mouse.click(5, 5);
		await expect(question).toBeHidden();

		await requestForm(page, 'erase').locator('button[type="submit"]').click();
		await expect(question).toBeVisible();
		await page.keyboard.press('Escape');
		await expect(question).toBeHidden();

		expect(stateOf(page.url())).toBe('');
		expect(await site.mail(email)).toEqual([]);
	});

	test('a second request while one waits is refused, and only one confirmation is mailed', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-twice');

		expect(await ask(page, pages.account.url, 'erase')).toBe('requested');
		expect(await ask(page, pages.account.url, 'erase')).toBe('error');
		await expect(page.locator('.diluxone-users-notice--error').first()).toBeVisible();

		expect((await site.mail(email)).length).toBe(1);
	});

	test('with copies switched off, a copy posted by hand is refused and nothing is mailed', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_privacy_export: 0 });

		const { email } = await signedIn(page, site, pages.login.url, 'd-noexport');

		await page.goto(accountSection(pages.account.url, 'privacy'));
		await expect(requestForm(page, 'export')).toHaveCount(0);

		const answer = await postByHand(page, {
			action: 'diluxone_users_data_request',
			diluxone_users_request: 'export',
			_wpnonce: await requestForm(page, 'erase').locator('input[name="_wpnonce"]').inputValue(),
		});

		expect(answer.state).toBe('error');
		expect(await site.mail(email)).toEqual([]);
	});

	test('with “Your data” turned off in Sections, a request posted by hand is refused', async ({ page, site, pages, options }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-sectionoff');

		await page.goto(accountSection(pages.account.url, 'privacy'));

		const nonce = await nonceOf(page, 'diluxone_users_data_request');

		await options.set({ diluxone_users_account_sections: { privacy: { enabled: 0 } } });

		for (const kind of ['erase', 'export']) {
			const answer = await postByHand(page, { action: 'diluxone_users_data_request', diluxone_users_request: kind, _wpnonce: nonce });

			expect(answer.state, kind).not.toBe('requested');
		}

		expect(await site.mail(email)).toEqual([]);
	});

	test('the button and the list say where a copy stands: asked, waiting for the e-mail, ready', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-pills');

		await page.goto(accountSection(pages.account.url, 'privacy'));
		await reveal(page, 'form:has(input[name="diluxone_users_request"][value="export"]) button[type="submit"]');

		const button = requestForm(page, 'export').locator('button[type="submit"]');
		const before = await button.innerText();

		await expect(page.locator('.diluxone-users-requests')).toHaveCount(0);

		expect(await ask(page, pages.account.url, 'export')).toBe('requested');
		await expect(page.locator('.diluxone-users-requests .diluxone-users-pill')).toHaveClass(/diluxone-users-pill--pending/);
		expect(await button.innerText(), 'the button now asks again, up to date').not.toBe(before);

		await page.goto(await confirmationLink(site, email));
		expect(stateOf(page.url())).toBe('ready');
		await expect(page.locator('.diluxone-users-requests .diluxone-users-pill').first()).toHaveClass(/diluxone-users-pill--ok/);
	});

	test('the warning that no mail goes out is shown to administrators, never to anybody else', async ({ page, browser, baseURL, site, pages, options }) => {
		await options.set({ diluxone_users_mail_last: { ok: 0, time: Math.floor(Date.now() / 1000), error: 'e2e: no mailer' } });

		await signedIn(page, site, pages.login.url, 'd-nomail');
		await page.goto(accountSection(pages.account.url, 'privacy'));

		// A subscriber's page has no error notice at all before anything is asked.
		await expect(page.locator('.diluxone-users-account__section .diluxone-users-notice--error')).toHaveCount(0);

		const admin = await adminPage(browser, baseURL);

		await admin.goto(accountSection(pages.account.url, 'privacy'));
		await expect(admin.locator('.diluxone-users-account__section .diluxone-users-notice--error')).toHaveCount(1);
		await admin.context().close();
	});
});

test.describe('The links in the e-mails', () => {
	test('a copy’s link opened with no session asks to sign in, and lands on the ready file', async ({ page, browser, baseURL, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-elsewhere');

		await ask(page, pages.account.url, 'export');

		const link = await confirmationLink(site, email);
		const other = await (await browser.newContext({ baseURL, storageState: undefined })).newPage();

		await other.goto(link);
		await expect(other.locator('[data-diluxone-users-message="login_confirm"]')).toBeVisible();
		await signInWithPassword(other, email, PASSWORD);
		await other.waitForURL(/diluxone-users=ready/);
		await expect(other.locator('.diluxone-users-requests a[download]').first()).toBeVisible();
		await other.context().close();
	});

	test('a tampered key deletes nothing, by the e-mail’s address or by the account’s', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-tamper');

		await ask(page, pages.account.url, 'erase');

		const link = new URL(await confirmationLink(site, email));
		const key = link.searchParams.get('confirm_key') as string;
		const id = link.searchParams.get('request_id') as string;
		const bad = `${key.slice(0, -1)}${key.endsWith('a') ? 'b' : 'a'}`;

		link.searchParams.set('confirm_key', bad);
		await page.goto(link.href);
		await expect(yesDelete(page)).toHaveCount(0);

		await page.goto(`${accountSection(pages.account.url, 'privacy')}?diluxone-users-request=${id}&diluxone-users-key=${bad}`);
		expect(stateOf(page.url())).toBe('expired');
		await expect(page.locator('.diluxone-users-notice--error').first()).toBeVisible();
		await expect(yesDelete(page)).toHaveCount(0);

		expect((await site.user(email)).exists).toBe(true);
	});

	test('the last step posted with a wrong key is refused, and by somebody else with the right one too', async ({ page, browser, baseURL, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-closepost');

		await ask(page, pages.account.url, 'erase');
		await page.goto(await confirmationLink(site, email));
		await expect(yesDelete(page)).toBeVisible();

		const closing = formOf(page, 'diluxone_users_confirm_close');
		const requestId = await closing.locator('input[name="diluxone_users_request_id"]').inputValue();
		const key = await closing.locator('input[name="diluxone_users_key"]').inputValue();
		const nonce = await closing.locator('input[name="_wpnonce"]').inputValue();

		const wrong = await postByHand(page, { action: 'diluxone_users_confirm_close', diluxone_users_request_id: requestId, diluxone_users_key: `${key}x`, _wpnonce: nonce });

		expect(wrong.state).toBe('expired');
		expect((await site.user(email)).exists).toBe(true);

		// A stranger, signed in, posting the owner's request with the right key
		// and a nonce of their own: the nonce is of the same action.
		const other = await (await browser.newContext({ baseURL, storageState: undefined })).newPage();
		const { email: strangerEmail } = await signedIn(other, site, pages.login.url, 'd-closestranger');

		await ask(other, pages.account.url, 'erase');
		await other.goto(await confirmationLink(site, strangerEmail));

		const theirNonce = await formOf(other, 'diluxone_users_confirm_close').locator('input[name="_wpnonce"]').inputValue();
		const stolen = await postByHand(other, { action: 'diluxone_users_confirm_close', diluxone_users_request_id: requestId, diluxone_users_key: key, _wpnonce: theirNonce });

		expect(stolen.state).toBe('other');
		expect((await site.user(email)).exists, 'the owner is still there').toBe(true);
		expect((await site.user(strangerEmail)).exists, 'and so is the stranger').toBe(true);
		await other.context().close();
	});

	test('a copy’s link used twice: the second time it no longer works', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-reuse');

		await ask(page, pages.account.url, 'export');

		const link = new URL(await confirmationLink(site, email));

		await page.goto(link.href);
		expect(stateOf(page.url())).toBe('ready');

		await page.goto(`${accountSection(pages.account.url, 'privacy')}?diluxone-users-request=${link.searchParams.get('request_id')}&diluxone-users-key=${encodeURIComponent(link.searchParams.get('confirm_key') as string)}`);
		expect(stateOf(page.url())).toBe('expired');
	});

	test('a request filed from Tools keeps WordPress’s own flow: its link confirms on wp-login.php and closes nothing', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const email = freshEmail('d-tools');

		await site.makeUser({ email, password: PASSWORD });

		const admin = await adminPage(browser, baseURL);

		await admin.goto('/wp-admin/erase-personal-data.php');
		await admin.locator('#username_or_email_for_privacy_request').fill(email);
		await admin.locator('#send_confirmation_email').check();
		await admin.locator('form#createuser input[type="submit"], form.wp-privacy-request-form input[type="submit"]').first().click();
		await admin.waitForLoadState('domcontentloaded');
		await admin.context().close();

		const link = await confirmationLink(site, email);

		await page.goto(link);

		expect(new URL(page.url()).pathname, 'WordPress’s own confirmation page, not the account').toMatch(/\/wp-login\.php$/);
		await expect(page.locator('.diluxone-users-closing')).toHaveCount(0);
		expect((await site.user(email)).exists, 'confirming a request from Tools closes no account').toBe(true);
	});

	test('the cookie that holds a link across signing in is ignored when its key was changed', async ({ page, browser, baseURL, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-cookie');

		await ask(page, pages.account.url, 'erase');

		const link = await confirmationLink(site, email);
		const context = await browser.newContext({ baseURL, storageState: undefined });
		const other = await context.newPage();

		await other.goto(link);
		await expect(other.locator('[data-diluxone-users-message="login_confirm"]')).toBeVisible();

		const held = (await context.cookies()).find((cookie) => cookie.name === 'diluxone_users_confirm');

		expect(held, 'the link is held in a cookie while they sign in').toBeTruthy();

		await context.clearCookies({ name: 'diluxone_users_confirm' });
		await context.addCookies([{ ...held!, value: encodeURIComponent(decodeURIComponent(held!.value).replace(/:(.*)$/, ':tampered-key')) }]);
		expect((await context.cookies()).filter((cookie) => cookie.name === 'diluxone_users_confirm').map((cookie) => decodeURIComponent(cookie.value))).toEqual([
			decodeURIComponent(held!.value).replace(/:(.*)$/, ':tampered-key'),
		]);

		// The sign-in page draws where it will send them when it is drawn, so
		// it is drawn again with the cookie as it is now.
		await other.reload();
		await signInWithPassword(other, email, PASSWORD);
		await expectSignedIn(other, email);

		expect(other.url()).not.toContain('diluxone-users-request');
		await expect(other.locator('.diluxone-users-closing')).toHaveCount(0);
		expect((await site.user(email)).exists).toBe(true);
		await context.close();
	});
});

test.describe('Closing an account', () => {
	test.skip(({ browserName }) => browserName !== 'chromium', 'the passkey part needs the virtual authenticator');

	test('with something published: an empty shell `deleted-<id>`, no role, every plugin key erased, nothing reopens it, and the person is told', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 0,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_where: 'any',
			diluxone_users_passkey_verify: 1,
			diluxone_users_handle_enabled: 1,
		});

		const sub = `mock|closing-${Date.now().toString(36)}`;
		const { email, id } = await signedIn(page, site, pages.login.url, 'd-shell', {
			meta: {
				diluxone_users_handle: `shell-${Date.now().toString(36)}`,
				diluxone_users_totp: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
				diluxone_users_2fa_on: 1,
				e2e_city: 'Rosario',
				diluxone_users_notify_login: '0',
				diluxone_users_sso_mock: sub,
			},
		});
		const post = devWp(['post', 'create', '--post_type=post', '--post_status=publish', '--post_title=E2E closing', `--post_author=${id}`, '--porcelain']);

		try {
			// A passkey of theirs, before closing.
			const cdp = await page.context().newCDPSession(page);

			await cdp.send('WebAuthn.enable', { enableUI: false });
			await cdp.send('WebAuthn.addVirtualAuthenticator', {
				options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
			});
			await page.goto(accountSection(pages.account.url, 'security'));
			await (await openPanel(page, '[data-diluxone-users-passkey="register"]')).locator('[data-diluxone-users-passkey="register"]').click();
			await expect(page.locator('input[name="diluxone_users_passkey_label"]')).toHaveCount(1, { timeout: 20_000 });

			expect(await ask(page, pages.account.url, 'erase')).toBe('requested');
			await page.goto(await confirmationLink(site, email));

			// What arrives from here on is what the button sent. Emptied rather
			// than filtered by time: the browser's clock and the server's are
			// not the same clock, and a mail from just before (the new
			// passkey's notice) could fall on either side of a timestamp.
			await site.clearMail();

			await Promise.all([page.waitForURL(/diluxone-users=closed/), yesDelete(page).click()]);
			await expect(page.locator('[data-diluxone-users-message="login_closed"]')).toBeVisible();

			// The address names nobody any more; the id is a shell.
			expect((await site.user(email)).exists).toBe(false);
			expect(devWp(['user', 'get', String(id), '--field=user_login'])).toBe(`deleted-${id}`);
			expect(devWp(['user', 'get', String(id), '--field=roles']), 'no role left').toBe('');
			expect(devWp(['post', 'get', post, '--field=post_author']), 'what they published stays, under the shell').toBe(String(id));

			// Every key of the plugin's is gone but the flag that keeps it closed.
			const keys = (JSON.parse(devWp(['user', 'meta', 'list', String(id), '--format=json'])) as Array<{ meta_key: string }>).map((row) => row.meta_key);
			const left = keys.filter((key) => /^_?diluxone_users_/.test(key) && key !== 'diluxone_users_closed');

			expect(left, 'plugin meta left on the shell').toEqual([]);
			expect(keys).not.toContain('e2e_city');
			expect(keys).toContain('diluxone_users_closed');

			// Told: a message after the button, which is not the confirmation.
			const told = (await site.mail(email)).filter((mail) => !mail.body.includes('confirmaction'));

			expect(told.map((mail) => mail.subject), 'the “your account was deleted” mail, and nothing else').toHaveLength(1);

			// The old password does not open it.
			await page.goto(pages.login.url);
			await signInWithPassword(page, email, PASSWORD);
			await page.waitForLoadState('domcontentloaded');
			expect(await signedInAs(page)).toBeNull();

			// The social identity it had does not open it.
			await site.setIdentity({ sub, email, email_verified: true });
			await page.goto(pages.login.url);
			await ssoButton(page, 'mock').click();
			await page.waitForLoadState('domcontentloaded');

			const who = await signedInAs(page);

			if (who !== null) {
				expect((await site.user(who)).id, 'whoever got in, it is not the shell').not.toBe(id);
			}

			// Nor does the passkey that was on it.
			await page.context().clearCookies();
			await page.goto(pages.login.url);
			await page.locator('[data-diluxone-users-passkey="login"]').click();
			await expect(page.locator('[data-diluxone-users-passkey-notice]')).toBeVisible({ timeout: 20_000 });
			expect(await signedInAs(page)).toBeNull();
		} finally {
			devWp(['user', 'delete', String(id), '--yes']);
		}
	});
});

test.describe('The file', () => {
	/** Downloads the ready file from the account and hands back what its export.json and index.html say. */
	async function readZip(page: Page): Promise<string> {
		const href = (await page.locator('.diluxone-users-requests a[download]').first().getAttribute('href')) as string;
		const body = await (await page.request.get(href)).body();

		mkdirSync('build/e2e-my-account', { recursive: true });

		const file = `build/e2e-my-account/export-${Date.now()}.zip`;

		writeFileSync(file, body);

		return execFileSync('unzip', ['-p', file], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
	}

	test('the copy holds the plugin’s groups: the details with the custom field and the public name, how they sign in, the passkeys and the log', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_handle_enabled: 1 });

		const handle = `zip-${Date.now().toString(36)}`;
		const { email } = await signedIn(page, site, pages.login.url, 'd-zip', { meta: { e2e_city: 'Cordoba-e2e', diluxone_users_handle: handle } });

		await ask(page, pages.account.url, 'export');
		await page.goto(await confirmationLink(site, email));
		expect(stateOf(page.url())).toBe('ready');

		const contents = await readZip(page);

		expect(contents).toContain('Cordoba-e2e');
		expect(contents).toContain(handle);

		for (const group of ['diluxone-users-details', 'diluxone-users-access']) {
			expect(contents, group).toContain(group);
		}
	});

	test('a file that is gone answers 404, and the account stops offering it', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'd-gone');

		await ask(page, pages.account.url, 'export');
		await page.goto(await confirmationLink(site, email));

		const download = page.locator('.diluxone-users-requests a[download]').first();
		const href = (await download.getAttribute('href')) as string;
		const request = new URL(href, page.url()).searchParams.get('request') as string;

		devWp(['eval', `unlink( wp_privacy_exports_dir() . get_post_meta( ${Number(request)}, '_export_file_name', true ) );`]);

		const answer = await page.request.get(href, { failOnStatusCode: false });

		expect(answer.status()).toBe(404);
		expect((await answer.body()).subarray(0, 2).toString()).not.toBe('PK');

		await page.reload();
		await expect(page.locator('.diluxone-users-requests a[download]')).toHaveCount(0);
	});
});

test.describe('WordPress’s own tools', () => {
	test.use({ storageState: ADMIN_STATE });

	test('Tools › Export and Erase Personal Data run the plugin’s exporters and erasers', async ({ page, site, options }) => {
		await options.set({ diluxone_users_handle_enabled: 1 });

		const email = freshEmail('d-toolsrun');
		const handle = `tool-${Date.now().toString(36)}`;

		await site.makeUser({ email, password: PASSWORD, meta: { diluxone_users_handle: handle, e2e_city: 'Mendoza-e2e' } });

		// Export, with no confirmation asked: the row is ready to run at once.
		await page.goto('/wp-admin/export-personal-data.php');
		await page.locator('#username_or_email_for_privacy_request').fill(email);
		await page.locator('#send_confirmation_email').uncheck();
		await page.locator('form.wp-privacy-request-form input[type="submit"], #submit').first().click();
		await page.waitForLoadState('domcontentloaded');

		const exportRow = page.locator('tr').filter({ hasText: email });

		// WordPress asks for the file one exporter page at a time; the last
		// answer carries the address of the zip it made.
		const zipUrl = new Promise<string>((resolve) => {
			page.on('response', async (response) => {
				if (!response.url().includes('admin-ajax.php') || !(response.request().postData() ?? '').includes('wp-privacy-export-personal-data')) {
					return;
				}

				const answer = await response.json().catch(() => null);

				if (answer?.data?.url) {
					resolve(String(answer.data.url));
				}
			});
		});

		await exportRow.hover();
		await exportRow.locator('.export-personal-data-handle').first().click();

		const zip = await (await page.request.get(await zipUrl)).body();

		mkdirSync('build/e2e-my-account', { recursive: true });

		const file = `build/e2e-my-account/tools-${Date.now()}.zip`;

		writeFileSync(file, zip);

		const contents = execFileSync('unzip', ['-p', file], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });

		expect(contents).toContain(handle);
		expect(contents).toContain('Mendoza-e2e');
		expect(contents).toContain('diluxone-users-details');

		// Erase, likewise: the plugin's keys go, and the account stays — a
		// request from Tools is not the person closing their account.
		await page.goto('/wp-admin/erase-personal-data.php');
		await page.locator('#username_or_email_for_privacy_request').fill(email);
		await page.locator('#send_confirmation_email').uncheck();
		await page.locator('form.wp-privacy-request-form input[type="submit"], #submit').first().click();
		await page.waitForLoadState('domcontentloaded');

		const eraseRow = page.locator('tr').filter({ hasText: email });

		await eraseRow.locator('.remove-personal-data-handle').first().click();
		await expect(eraseRow.locator('.remove-personal-data-success').first()).toBeVisible({ timeout: 30_000 });

		const after = await site.user(email, ['diluxone_users_handle', 'e2e_city']);

		expect(after.exists).toBe(true);
		expect(after.fields).toEqual({ diluxone_users_handle: '', e2e_city: '' });
	});

	test('Settings › Privacy’s policy guide carries the plugin’s suggested text', async ({ page }) => {
		await page.goto('/wp-admin/options-privacy.php?tab=policyguide');

		await expect(page.locator('.privacy-settings-accordion, .policy-text, #wpbody-content').first()).toContainText('DiluxOne Users+');
	});
});

test.describe('The e-mails', () => {
	test('a data e-mail rewritten on Notifications › The e-mails reaches the inbox in the site’s words, link intact', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_mail_templates']);

		const admin = await adminPage(browser, baseURL);

		await admin.goto(adminUrl('diluxone-users-notices', 'templates'));
		await admin.locator('details:has(#diluxone_users_mail_data_delete_confirm_subject) > summary').click();
		await admin.locator('#diluxone_users_mail_data_delete_confirm_subject').fill('E2E: confirm the deletion');
		await savePanel(admin);
		await admin.context().close();

		const { email } = await signedIn(page, site, pages.login.url, 'd-words');

		await ask(page, pages.account.url, 'erase');

		const mail = await waitForMail(site, email);

		expect(mail.subject).toBe('E2E: confirm the deletion');
		expect(mail.body).toContain('action=confirmaction');

		await page.goto(linkIn(mail, /https?:\/\/\S+action=confirmaction\S*/));
		await expect(yesDelete(page)).toBeVisible();
	});
});
