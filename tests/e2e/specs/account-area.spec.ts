import { Browser, Locator, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { Site, codeIn, freshEmail, linkIn, waitForMail } from '../support/api';
import { accountSection, askForLink, notice, signInWithPassword } from '../support/ui';
import { avoidWindowEdge, totp } from '../support/totp';

/**
 * The account area, section by section, as the person whose account it is.
 *
 * Every block here is a form that posts to admin-post.php and comes back as a
 * redirect — the part no unit test reaches. Each test does one thing a person
 * does on their own account and then asks the site, through the side door,
 * whether it happened: the meta written, the attachment made, the session
 * closed, the request filed.
 */

const PASSWORD = 'e2e-Account-1!';

/** A PNG of one pixel: a real image for the media library, small enough to inline. */
const PIXEL = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkaPhfDwAEmwH/8d3cZQAAAABJRU5ErkJggg==',
	'base64'
);

/** The fields the details form draws, pinned so the site's own list decides nothing here. */
const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_city', label: 'City', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' },
];

/** Somebody new, signed in with a password in this browser. */
async function signedIn(page: Page, site: Site, loginUrl: string, prefix: string): Promise<{ email: string; id: number }> {
	const email = freshEmail(prefix);
	const made = await site.makeUser({ email, password: PASSWORD });

	await page.goto(loginUrl);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);

	return { email, id: made.id };
}

/**
 * Brings something on an account screen into view.
 *
 * Some blocks there are a closed `<details>` and some are plain: which is
 * which is the template's business, not this test's. So the thing is found
 * first, and only a closed box around it is opened.
 */
async function reveal(page: Page, inner: string): Promise<void> {
	const target = page.locator(inner).first();

	await expect(target).toBeAttached();

	const closed = page.locator('details:not([open])').filter({ has: page.locator(inner) });

	for (let n = await closed.count(); n > 0; n = await closed.count()) {
		await closed.first().locator('> summary').click();
	}

	await expect(target).toBeVisible();
}

/** Presses a form's submit button and waits for the redirect's answer. */
async function send(page: Page, button: Locator): Promise<string> {
	await Promise.all([page.waitForURL(/[?&]diluxone[-_]users/, { waitUntil: 'domcontentloaded' }), button.click()]);

	return stateOf(page.url());
}

test.beforeEach(async ({ options }) => {
	await options.set({ diluxone_users_2fa_mode: 'optional', diluxone_users_fields: FIELDS });
});

test.describe('The account page itself', () => {
	test('a stranger is shown the way in, not the account', async ({ page, pages }) => {
		await page.goto(pages.account.url);

		const guest = page.locator('.diluxone-users-account--guest');

		await expect(guest).toBeVisible();
		await expect(guest.locator('a.diluxone-users-button')).toHaveAttribute('href', /e2e-login/);
	});

	test('the menu reaches every section, and each one is the current one on its own address', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_privacy_export: 1, diluxone_users_privacy_delete: 1 });
		await signedIn(page, site, pages.login.url, 'nav');

		for (const section of ['details', 'security', 'notifications', 'privacy']) {
			await page.goto(pages.account.url);

			const tab = page.locator(`a.diluxone-users-account__tab[href*="/${section}/"]`);

			await expect(tab, `the ${section} tab is in the menu`).toBeVisible();
			await Promise.all([page.waitForLoadState('domcontentloaded'), tab.click()]);

			await expect(page.locator(`a.diluxone-users-account__tab.is-current[href*="/${section}/"]`)).toHaveAttribute(
				'aria-current',
				'page'
			);
		}
	});

	test('“Your data” is there only while the site lets people ask for something', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_privacy_export: 0, diluxone_users_privacy_delete: 0 });
		await signedIn(page, site, pages.login.url, 'nodata');

		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/privacy/"]')).toHaveCount(0);

		await options.set({ diluxone_users_privacy_export: 1 });
		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/privacy/"]')).toBeVisible();
	});

	test('“Linked accounts” is there only while a social network is on', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_e2e_sso: 0 });
		await signedIn(page, site, pages.login.url, 'nolinks');

		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/accounts/"]')).toHaveCount(0);

		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
		});
		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/accounts/"]')).toBeVisible();
	});
});

test.describe('Your details', () => {
	test('the fields are saved to the account, and the name the site shows follows them', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'details');

		await page.goto(accountSection(pages.account.url, 'details'));

		const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_fields_save"]') });

		await reveal(page, 'input[name="first_name"]');
		await form.locator('input[name="first_name"]').fill('Ada');
		await form.locator('input[name="last_name"]').fill('Lovelace');
		await form.locator('input[name="e2e_city"]').fill('London');

		expect(await send(page, form.locator('button[type="submit"]').first())).toBe('saved');
		await expect(notice(page, 'ok').first()).toBeVisible();

		const saved = await site.user(email, ['e2e_city']);

		expect(saved.meta.first_name).toBe('Ada');
		expect(saved.meta.last_name).toBe('Lovelace');
		expect(saved.fields.e2e_city).toBe('London');
		expect(saved.name, 'the public name is built from the two').toBe('Ada Lovelace');
	});

	test('a required field left empty is refused by the server, even with the browser’s check off', async ({
		page,
		site,
		pages,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 'required');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[name="e2e_city"]');

		const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_fields_save"]') });

		await form.evaluate((element: HTMLFormElement) => {
			element.noValidate = true;
		});
		await form.locator('input[name="e2e_city"]').fill('');

		expect(await send(page, form.locator('button[type="submit"]').first())).toBe('missing');
		expect((await site.user(email, ['e2e_city'])).fields.e2e_city).toBe('');
	});
});

test.describe('Your photo', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_avatar_upload: 1 });
	});

	test('uploaded, it is the picture the site draws; removed, it is gone from the media library too', async ({
		page,
		site,
		pages,
	}) => {
		const { email } = await signedIn(page, site, pages.login.url, 'photo');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'form.diluxone-users-avatar__form');

		const form = page.locator('form.diluxone-users-avatar__form');

		await form.locator('input[name="diluxone_users_avatar_file"]').setInputFiles({
			name: 'me.png',
			mimeType: 'image/png',
			buffer: PIXEL,
		});

		expect(await send(page, form.locator('button[type="submit"]').first())).toBe('saved');

		const id = (await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar;

		expect(Number(id), 'the photo became an attachment').toBeGreaterThan(0);
		await expect(page.locator('.diluxone-users-avatar__current img')).toHaveAttribute('src', /\/uploads\//);

		const removed = page.request.get(`/?attachment_id=${id}`);

		expect((await removed).status(), 'the attachment exists').toBe(200);

		await reveal(page, 'form.diluxone-users-avatar__form');
		expect(await send(page, form.locator('button[name="diluxone_users_avatar_remove"]'))).toBe('saved');

		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');
		await expect(page.locator('.diluxone-users-avatar__current img')).not.toHaveAttribute('src', /\/uploads\//);
		expect((await page.request.get(`/?attachment_id=${id}`)).status(), 'and the file went with it').toBe(404);
	});

	test('a file that is not a picture is refused, whatever it is called', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'notphoto');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'form.diluxone-users-avatar__form');

		const form = page.locator('form.diluxone-users-avatar__form');

		await form.locator('input[name="diluxone_users_avatar_file"]').setInputFiles({
			name: 'me.png',
			mimeType: 'image/png',
			buffer: Buffer.from('<?php echo "not a picture";'),
		});
		await Promise.all([page.waitForURL(/diluxone_users_avatar=/), form.locator('button[type="submit"]').first().click()]);

		await expect(page.locator('.diluxone-users-avatar .diluxone-users-notice--error, .diluxone-users-notice--error').first()).toBeVisible();
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');
	});
});

test.describe('The public name', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_handle_enabled: 1, diluxone_users_handle_cooldown: 0 });
	});

	test('chosen on the account, it is saved — and with “sign in with it” on, the link box takes it', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_handle_login: 1 });

		const { email } = await signedIn(page, site, pages.login.url, 'handle');
		const handle = `ada-${Date.now().toString(36)}`;

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[name="diluxone_users_handle"]');

		const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_handle"]') });

		await form.locator('input[name="diluxone_users_handle"]').fill(handle);
		expect(await send(page, form.locator('button[type="submit"]').first())).toBe('saved');

		expect((await site.user(email, ['diluxone_users_handle'])).fields.diluxone_users_handle).toBe(handle);

		// Out, and back in by typing the public name where the address goes:
		// the link still goes to the address, which is the only place it can.
		await page.context().clearCookies();
		await page.goto(pages.login.url);

		// A box that only takes an address cannot be given a name: the
		// browser refuses to send it before the server is ever asked.
		await expect(
			page.locator('input[name="diluxone_users_email"]'),
			'the sign-in box is type="email", so the browser refuses a public name'
		).not.toHaveAttribute('type', 'email');

		await askForLink(page, pages.login.url, handle);
		expect((await waitForMail(site, email)).to).toContain(email);
	});

	test('a reserved name is refused and nothing is written', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'reserved');

		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'input[name="diluxone_users_handle"]');

		const form = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_handle"]') });

		await form.locator('input[name="diluxone_users_handle"]').fill('admin');
		await Promise.all([page.waitForURL(/diluxone_users_handle=/), form.locator('button[type="submit"]').first().click()]);

		expect((await site.user(email, ['diluxone_users_handle'])).fields.diluxone_users_handle).toBe('');
	});
});

test.describe('Notifications', () => {
	/** A browser that looks like another device: the notice is about a device the account has not seen. */
	async function device(browser: Browser, baseURL: string, agent: string): Promise<Page> {
		const context = await browser.newContext({ baseURL, userAgent: agent, storageState: undefined });

		return context.newPage();
	}

	/** Signs in with the e-mail link, the way in every device notice has to follow. */
	async function byLink(page: Page, site: Site, loginUrl: string, email: string): Promise<void> {
		// One link a second per address is the throttle this suite runs with,
		// and three devices in a row are faster than that.
		await site.setOptions({}, { forgetTransients: true });

		const asked = Date.now() / 1000;

		await askForLink(page, loginUrl, email);
		await page.goto(linkIn(await waitForMail(site, email, { after: asked - 1, subject: /./ })));
		await expectSignedIn(page, email);
	}

	/** The messages that are not a sign-in link: here, the device notices. */
	async function notices(site: Site, email: string) {
		return (await site.mail(email)).filter((one) => !one.body.includes('diluxone_users_token'));
	}

	test('a new device is announced, and not once the person switched that notice off', async ({
		browser,
		baseURL,
		site,
		pages,
	}) => {
		const email = freshEmail('device');

		await site.makeUser({ email, password: PASSWORD });

		// The first device is the one the account starts on: quiet.
		const first = await device(browser, baseURL!, 'E2E-Device-One Chrome/1');

		await byLink(first, site, pages.login.url, email);
		expect(await notices(site, email), 'the first device is not news').toEqual([]);

		await byLink(await device(browser, baseURL!, 'E2E-Device-Two Firefox/2'), site, pages.login.url, email);
		expect((await notices(site, email)).length, 'the second device is announced').toBe(1);

		// Off, from the account's own Notifications section.
		await first.goto(accountSection(pages.account.url, 'notifications'));

		const form = first.locator('form').filter({ has: first.locator('input[name="action"][value="diluxone_users_notifications"]') });
		const box = form.locator('input[name="diluxone_users_notify_login"]');

		await reveal(first, 'input[name="diluxone_users_notify_login"]');
		await expect(box).toBeChecked();
		await box.uncheck();
		expect(await send(first, form.locator('button[type="submit"]').first())).toBe('saved');
		expect((await site.user(email, ['diluxone_users_notify_login'])).fields.diluxone_users_notify_login).toBe('0');

		await site.clearMail();
		await byLink(await device(browser, baseURL!, 'E2E-Device-Three Safari/3'), site, pages.login.url, email);
		expect(await notices(site, email), 'a third device, and nothing said').toEqual([]);
	});

	test('a new device is announced when it came in with the password, too', async ({ browser, baseURL, site, pages }) => {
		const email = freshEmail('device-pass');

		await site.makeUser({ email, password: PASSWORD });

		for (const agent of ['E2E-Device-One Chrome/1', 'E2E-Device-Two Firefox/2']) {
			const page = await device(browser, baseURL!, agent);

			await page.goto(pages.login.url);
			await signInWithPassword(page, email, PASSWORD);
			await expectSignedIn(page, email);
		}

		// The notice lists "your password" among the ways it names; a sign-in
		// by password on a device the account has never seen is the case it
		// exists for.
		expect((await notices(site, email)).length, 'a password sign-in on a new device was not announced').toBe(1);
	});

	test('a notice the site sends “always” has no switch on the account', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_notice_rules: { diluxone_users_notify_login: 'always' } });
		await signedIn(page, site, pages.login.url, 'always');

		await page.goto(accountSection(pages.account.url, 'notifications'));
		await expect(page.locator('input[name="diluxone_users_notify_login"]')).toHaveCount(0);
	});
});

test.describe('Your data', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_privacy_export: 1, diluxone_users_privacy_delete: 1 });
	});

	/** The form that files one kind of request: it says which in a hidden field. */
	const requestForm = (page: Page, kind: 'export' | 'erase') =>
		page.locator('form').filter({ has: page.locator(`input[name="diluxone_users_request"][value="${kind}"]`) });

	/**
	 * Files a request the way a hand-written POST would: the export form's own
	 * nonce, and whatever kind the test says. It answers with the state the
	 * redirect carries.
	 */
	async function postByHand(page: Page, kind: 'export' | 'erase'): Promise<string> {
		const form = requestForm(page, 'export');
		const nonce = await form.locator('input[name="_wpnonce"]').inputValue();
		const response = await page.request.post(await form.getAttribute('action') as string, {
			form: { action: 'diluxone_users_data_request', diluxone_users_request: kind, _wpnonce: nonce },
			headers: { referer: page.url() },
			maxRedirects: 0,
		});

		return stateOf(response.headers().location ?? 'http://x/');
	}

	for (const kind of ['export', 'erase'] as const) {
		test(`asking for ${kind === 'export' ? 'a copy' : 'the account to be erased'} files WordPress’s own request and mails its confirmation`, async ({
			page,
			site,
			pages,
		}) => {
			const { email } = await signedIn(page, site, pages.login.url, `data-${kind}`);

			// Erasing asks "are you sure" first, in the browser's own box.
			page.on('dialog', (dialog) => dialog.accept());

			await page.goto(accountSection(pages.account.url, 'privacy'));
			await reveal(page, `form:has(input[name="diluxone_users_request"][value="${kind}"]) button[type="submit"]`);

			expect(await send(page, requestForm(page, kind).locator('button[type="submit"]'))).toBe('requested');

			// WordPress's confirmation, which is what makes it a request and
			// not a wish: nothing happens until the person clicks it.
			expect((await waitForMail(site, email)).body).toContain('action=confirmaction');

			// And the account lists it, waiting.
			await expect(page.locator('.diluxone-users-requests').first()).toBeAttached();
		});
	}

	test('erasure switched off on the site is refused by the server, not only hidden', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_privacy_export: 1, diluxone_users_privacy_delete: 0 });

		const { email } = await signedIn(page, site, pages.login.url, 'data-forged');

		await page.goto(accountSection(pages.account.url, 'privacy'));
		await expect(requestForm(page, 'erase'), 'no erase form on the page').toHaveCount(0);

		// The export form, turned into the erase form the setting took off
		// the page. A form is a suggestion; the server is the rule.
		await postByHand(page, 'erase');

		expect(await site.mail(email), 'an erasure request was filed on a site that does not offer erasure').toEqual([]);
	});

	test('an administrator cannot ask for their own erasure, not even by posting it by hand', async ({ page, site, pages }) => {
		const email = freshEmail('data-admin');

		await site.makeUser({ email, password: PASSWORD, role: 'administrator' });
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(accountSection(pages.account.url, 'privacy'));
		await expect(requestForm(page, 'erase'), 'the account is told why, and given no button').toHaveCount(0);

		expect(await postByHand(page, 'erase')).toBe('admin');
		expect(await site.mail(email)).toEqual([]);
	});
});

test.describe('Security', () => {
	test('two-step by e-mail: on without a code, off only with one', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });

		const { email } = await signedIn(page, site, pages.login.url, 'sec2fa');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="on"]'))).toBe('on');

		expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('1');
		expect((await site.mail(email)).length, 'the change is announced').toBeGreaterThan(0);

		// Off asks for proof: a code, asked for from the same box.
		await reveal(page, 'button[name="diluxone_users_security"][value="off"]');
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="off"]'))).not.toBe('off');
		expect((await site.user(email)).meta.diluxone_users_2fa_on, 'not without a code').toBe('1');

		await site.clearMail();
		await reveal(page, 'button[name="diluxone_users_security"][value="code"]');
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="code"]'))).toBe('codesent');

		const code = codeIn(await waitForMail(site, email));

		await reveal(page, '#diluxone-users-reauth-code');
		await page.locator('#diluxone-users-reauth-code').fill(code);
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="off"]'))).toBe('off');
		expect((await site.user(email)).meta.diluxone_users_2fa_on).toBe('');
	});

	test('the authenticator app: set up, then removed with one of the backup codes it came with', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_methods: ['totp', 'email'] });

		const { email } = await signedIn(page, site, pages.login.url, 'totp-off');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-totp__key');

		const secret = (await page.locator('.diluxone-users-totp__key').innerText()).replace(/\s+/g, '');

		await avoidWindowEdge();

		const setup = page.locator('form').filter({ has: page.locator('input[name="diluxone_users_security"][value="totp"]') });

		await setup.locator('input[name="diluxone_users_code"]').fill(totp(secret));
		expect(await send(page, setup.locator('button[type="submit"]'))).toBe('totp');

		// Handed over once, on the way in: a code for the day the phone is lost.
		const backup = (await page.locator('.diluxone-users-backup__list code').allInnerTexts()).map((one) => one.trim());

		expect(backup.length).toBeGreaterThan(0);

		await reveal(page, '#diluxone-users-totp-off-code');
		await page.locator('#diluxone-users-totp-off-code').fill(backup[0]);
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="totp_off"]'))).toBe('totpoff');

		expect((await site.user(email)).meta.diluxone_users_totp, 'the app is gone from the account').toBe('');
	});

	test('new backup codes are handed over only for a code, and the old ones stop working', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_2fa_methods: ['email'] });

		const { email } = await signedIn(page, site, pages.login.url, 'backup-new');

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, 'button[name="diluxone_users_security"][value="on"]');
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="on"]'))).toBe('on');

		const first = (await page.locator('.diluxone-users-backup__list code').allInnerTexts()).map((one) => one.trim());

		expect(first.length, 'turning it on hands over a set').toBeGreaterThan(0);

		// Without a code: refused.
		await reveal(page, 'button[name="diluxone_users_security"][value="backup"]');
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="backup"]'))).toBe('reauth');

		await site.clearMail();
		await reveal(page, 'button[name="diluxone_users_security"][value="code"]');
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="code"]'))).toBe('codesent');

		await reveal(page, '#diluxone-users-reauth-code');
		await page.locator('#diluxone-users-reauth-code').fill(codeIn(await waitForMail(site, email)));
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="backup"]'))).toBe('backup');

		const second = (await page.locator('.diluxone-users-backup__list code').allInnerTexts()).map((one) => one.trim());

		expect(second.length).toBeGreaterThan(0);
		expect(second, 'a new set, not the old one again').not.toEqual(first);

		// An old code no longer proves anything.
		await reveal(page, 'button[name="diluxone_users_security"][value="off"]');
		await page.locator('#diluxone-users-reauth-code').fill(first[0]);
		expect(await send(page, page.locator('button[name="diluxone_users_security"][value="off"]'))).toBe('reauth');
	});

	test('the list of browsers: one closed from another, then “close the others”', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_sessions_show: 1 });

		const { email } = await signedIn(page, site, pages.login.url, 'sessions');

		const others: Page[] = [];

		for (let n = 0; n < 2; n++) {
			const context = await browser.newContext({ baseURL, storageState: undefined });
			const other = await context.newPage();

			await other.goto(pages.login.url);
			await signInWithPassword(other, email, PASSWORD);
			await expectSignedIn(other, email);
			others.push(other);
		}

		expect((await site.user(email)).sessions).toBe(3);

		await page.goto(accountSection(pages.account.url, 'security'));
		await reveal(page, '.diluxone-users-sessions__list');

		const rows = page.locator('.diluxone-users-session');

		await expect(rows).toHaveCount(3);
		await expect(page.locator('.diluxone-users-session--current')).toHaveCount(1);

		// One of the others, by its own button.
		await send(page, page.locator('.diluxone-users-session:not(.diluxone-users-session--current) button[type="submit"]').first());
		expect((await site.user(email)).sessions).toBe(2);

		await reveal(page, 'form.diluxone-users-sessions__all');
		await send(page, page.locator('form.diluxone-users-sessions__all button[type="submit"]'));
		expect((await site.user(email)).sessions, 'only this one is left').toBe(1);

		await expectSignedIn(page, email);

		for (const other of others) {
			await expectSignedOut(other);
		}
	});

	test('with the list switched off on the site, the account does not draw it', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_sessions_show: 0 });
		await signedIn(page, site, pages.login.url, 'nosessions');

		await page.goto(accountSection(pages.account.url, 'security'));
		await expect(page.locator('.diluxone-users-sessions')).toHaveCount(0);
	});
});
