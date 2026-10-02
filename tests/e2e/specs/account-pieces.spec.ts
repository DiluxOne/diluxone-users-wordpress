import { Browser, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { Site, freshEmail } from '../support/api';
import { accountSection, navigated, signInWithPassword } from '../support/ui';

/**
 * The pieces of the account, each where a site puts it: on a page of its own.
 *
 * The account area draws its sections itself, and `account-area.spec.ts`
 * walks them there. A site can also take a piece out and put it anywhere —
 * the menu, the fields, the sessions, the social networks, the photo, the
 * public name, the notifications — with a shortcode of its own. Those are
 * different doors: a form that came from another page has to go back to that
 * page, and a piece that draws something to a stranger is a piece that leaks.
 * So every one is put on one page here, as a site would, and used from there.
 */

const PASSWORD = 'e2e-Pieces-1!';
const SLUG = 'pieces';

const PIECES = [
	'[diluxone_users_account_nav]',
	'[diluxone_users_fields title="About you"]',
	'[diluxone_users_sessions]',
	'[diluxone_users_accounts]',
	'[diluxone_users_avatar]',
	'[diluxone_users_handle]',
	'[diluxone_users_notifications]',
].join('\n\n');

/** The fields the form draws, pinned so the site's own list decides nothing here. */
const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_city', label: 'City', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' },
];

/** One selector per piece: what each draws, and nothing else on the page draws. */
const DRAWN = {
	nav: '.diluxone-users-account__nav',
	fields: '.diluxone-users-fields form input[name="action"][value="diluxone_users_fields_save"]',
	sessions: '.diluxone-users-sessions',
	accounts: '.diluxone-users-accounts',
	avatar: 'form input[name="action"][value="diluxone_users_avatar"]',
	handle: 'form input[name="action"][value="diluxone_users_handle"]',
	notifications: 'form input[name="action"][value="diluxone_users_notifications"]',
};

let url = '';

test.beforeAll(async ({ request }) => {
	url = (await new Site(request).page(SLUG, PIECES)).url;
});

test.afterAll(async ({ request }) => {
	await new Site(request).forgetPage(SLUG);
});

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: FIELDS,
		diluxone_users_avatar_upload: 1,
		diluxone_users_handle_enabled: 1,
		diluxone_users_handle_cooldown: 0,
		diluxone_users_2fa_mode: 'off',
		diluxone_e2e_sso: 1,
		diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
		diluxone_users_sso_login: 1,
	});
});

/** Somebody new, signed in with a password in this browser. */
async function signedIn(page: Page, site: Site, loginUrl: string, prefix: string): Promise<{ email: string; id: number }> {
	const email = freshEmail(prefix);
	const made = await site.makeUser({ email, password: PASSWORD });

	await page.goto(loginUrl);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);

	return { email, id: made.id };
}

test('a stranger is drawn none of the pieces', async ({ page }) => {
	await page.goto(url);

	for (const [piece, selector] of Object.entries(DRAWN)) {
		await expect(page.locator(selector), `${piece} drawn to somebody with no session`).toHaveCount(0);
	}
});

test('somebody signed in is drawn every piece', async ({ page, site, pages }) => {
	await signedIn(page, site, pages.login.url, 'pieces-all');
	await page.goto(url);

	for (const [piece, selector] of Object.entries(DRAWN)) {
		await expect(page.locator(selector).first(), `${piece} on the page`).toBeAttached();
	}

	// The title the shortcode was given is the form's heading.
	await expect(page.locator('.diluxone-users-fields__title')).toHaveText('About you');
});

test('the menu alone reaches the sections of the account', async ({ page, site, pages }) => {
	await signedIn(page, site, pages.login.url, 'pieces-nav');
	await page.goto(url);

	const tabs = page.locator('.diluxone-users-account__nav a.diluxone-users-account__tab');

	expect(await tabs.count(), 'a section per link').toBeGreaterThan(1);

	const details = tabs.filter({ has: page.locator(`xpath=self::*[@href="${accountSection(pages.account.url, 'details')}"]`) });

	await expect(details).toHaveCount(1);
	await details.click();
	await page.waitForURL(accountSection(pages.account.url, 'details'));
	await expect(page.locator('.diluxone-users-account')).toBeVisible();
});

test('the fields alone: saved from the page and back on it, the answer written', async ({ page, site, pages }) => {
	const { email } = await signedIn(page, site, pages.login.url, 'pieces-fields');

	await page.goto(url);

	const form = page.locator('.diluxone-users-fields form');

	await form.locator('input[name="e2e_city"]').fill('Rosario');
	await Promise.all([page.waitForURL(/[?&]diluxone-users=/), form.locator('button[type="submit"]').click()]);

	expect(stateOf(page.url())).toBe('saved');
	expect(new URL(page.url()).pathname, 'back on the page the form was on').toBe(new URL(url).pathname);
	await expect(page.locator('.diluxone-users-fields .diluxone-users-notice--ok')).toBeVisible();
	expect((await site.user(email, ['e2e_city'])).fields.e2e_city).toBe('Rosario');
});

test('the sessions alone: another browser closed from the list, this one kept', async ({ page, site, pages, browser }) => {
	const { email } = await signedIn(page, site, pages.login.url, 'pieces-sessions');
	const other = await secondBrowser(browser, email, pages.login.url);

	await page.goto(url);

	const rows = page.locator('.diluxone-users-session');

	await expect(rows).toHaveCount(2);
	await expect(page.locator('.diluxone-users-session--current')).toHaveCount(1);

	const closeOne = page.locator('.diluxone-users-session:not(.diluxone-users-session--current) button[type="submit"]');

	await Promise.all([page.waitForURL(/[?&]diluxone-users=sessions/), closeOne.click()]);

	expect(new URL(page.url()).pathname, 'back on the page the list was on').toBe(new URL(url).pathname);
	await expect(page.locator('.diluxone-users-sessions .diluxone-users-notice--ok')).toBeVisible();
	await expect(rows).toHaveCount(1);

	await expectSignedIn(page, email);
	await expectSignedOut(other);
	await other.context().close();
});

test('the sessions alone: “close the others” leaves only this one', async ({ page, site, pages, browser }) => {
	const { email } = await signedIn(page, site, pages.login.url, 'pieces-others');
	const second = await secondBrowser(browser, email, pages.login.url);
	const third = await secondBrowser(browser, email, pages.login.url);

	await page.goto(url);
	await expect(page.locator('.diluxone-users-session')).toHaveCount(3);

	await Promise.all([
		page.waitForURL(/[?&]diluxone-users=sessions/),
		page.locator('form.diluxone-users-sessions__all button[type="submit"]').click(),
	]);

	await expect(page.locator('.diluxone-users-session')).toHaveCount(1);
	await expect(page.locator('form.diluxone-users-sessions__all')).toHaveCount(0);
	await expectSignedIn(page, email);
	await expectSignedOut(second);
	await expectSignedOut(third);
	await second.context().close();
	await third.context().close();
});

test('the social networks alone: the provider offered with a link that carries its nonce', async ({ page, site, pages }) => {
	await signedIn(page, site, pages.login.url, 'pieces-accounts');
	await page.goto(url);

	const row = page.locator('.diluxone-users-accounts .diluxone-users-linked__item').filter({ hasText: 'Mock' });

	await expect(row).toBeVisible();
	await expect(row).not.toHaveClass(/is-linked/);
	await expect(row.locator('a.diluxone-users-button')).toHaveAttribute('href', /diluxone_users_nonce=/);
});

test('the public name alone: the availability check answers taken and free as it is typed', async ({
	page,
	site,
	pages,
	options,
}) => {
	// The suite's addresses are long, and the box takes no more than this.
	await options.set({ diluxone_users_handle_max: 60 });

	// A name is taken when it is somebody's login or the name in the address
	// of their profile; the owner's is one, whatever the site makes it from.
	const owner = freshEmail('pieces-owner');

	await site.makeUser({ email: owner, password: PASSWORD });

	const taken = String((await site.user(owner)).nicename);
	await signedIn(page, site, pages.login.url, 'pieces-handle');
	await page.goto(url);

	const field = page.locator('#diluxone-users-handle');
	const notice = page.locator('[data-diluxone-users-handle-notice]');

	// The server is asked after a pause, so the answer is waited for.
	await Promise.all([
		page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('diluxone_users_handle_check') === true),
		field.fill(taken),
	]);
	await expect(notice).toHaveClass(/is-taken/);

	await Promise.all([
		page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('diluxone_users_handle_check') === true),
		field.fill(`free-${Date.now().toString(36)}`),
	]);
	await expect(notice).toHaveClass(/is-free/);
});

test('the photo and the notifications alone save and land on the account', async ({ page, site, pages }) => {
	const { email } = await signedIn(page, site, pages.login.url, 'pieces-notify');

	await page.goto(url);

	const notifications = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_notifications"]') });
	const toggles = notifications.locator('input[type="checkbox"]');

	expect(await toggles.count(), 'the notifications piece has switches').toBeGreaterThan(0);

	const name = (await toggles.first().getAttribute('name')) ?? '';

	await toggles.first().uncheck();
	await Promise.all([page.waitForURL(/[?&]diluxone-users=saved/), notifications.locator('button[type="submit"]').click()]);

	expect(page.url()).toContain(accountSection(pages.account.url, 'notifications').replace(/\/$/, ''));
	expect((await site.user(email, [name])).fields[name]).toBe('0');

	// And on again: the choice goes both ways.
	await page.goto(url);
	await notifications.locator(`input[name="${name}"]`).check();
	await Promise.all([page.waitForURL(/[?&]diluxone-users=saved/), notifications.locator('button[type="submit"]').click()]);
	expect((await site.user(email, [name])).fields[name]).toBe('1');

	await page.goto(url);

	const avatar = page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_avatar"]') });

	// A real picture, uploaded through the piece, kept as the person's photo.
	const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAAFElEQVR4nGNkaCtkwA2Y8MiNYGkANhwBC80BQ4YAAAAASUVORK5CYII=', 'base64');
	await avatar.locator('input[type="file"]').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: png });
	await navigated(page, () => avatar.locator('button[type="submit"], input[type="submit"]').first().click());

	expect(Number((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar), 'the photo is theirs').toBeGreaterThan(0);
});

/** The same person signed in in a browser of their own, which is another session. */
async function secondBrowser(browser: Browser, email: string, loginUrl: string): Promise<Page> {
	const context = await browser.newContext({ storageState: undefined });
	const page = await context.newPage();

	await page.goto(loginUrl);
	await signInWithPassword(page, email, PASSWORD);
	await expectSignedIn(page, email);

	return page;
}
