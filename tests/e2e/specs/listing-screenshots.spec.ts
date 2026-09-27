import type { Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { test, expect } from '../support/fixtures';
import { Site, codeIn, waitForMail } from '../support/api';
import { accountSection, adminUrl, challengeCode, challengeScreen, signInWithPassword } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The thirteen pictures the wordpress.org listing shows.
 *
 * Not the same thing as `admin-snapshots.spec.ts`, and the difference is the
 * whole reason this file exists. Those pictures are assertions: they are
 * compared with yesterday's and the test fails when a screen changed. These
 * are not compared with anything — they are written, and what they are for is
 * somebody deciding whether to install the plugin.
 *
 * They were being taken by hand, which is why they went stale: the admin was
 * redesigned, "Status" became "Maintenance", Reports appeared, and the
 * listing went on showing screens that no longer existed. A picture taken by
 * hand is a picture nobody retakes. So this is a spec, it runs against the
 * same seeded site as the rest of the suite, and re-taking all thirteen is
 * one command.
 *
 * The numbering and the order are the readme's: `screenshot-N.png` matches
 * the Nth line under `== Screenshots ==`, and a picture that moves without
 * its caption moving is a listing that lies. The caption of each one is
 * copied into the test name, so `npx playwright test listing` prints the
 * readme back and the two can be read side by side.
 *
 * Opt-in (`make screenshots`) and its own Playwright project, for the same
 * reason the visual one is: a fixed window, no motion, and a machine whose
 * fonts these are.
 */

const SHOTS = '.wordpress-org';

/** The password the person in the front-end pictures signs in with. */
const PASSWORD = 'e2e-Listing-Shot-1!';

/**
 * What the site has to be while its picture is taken.
 *
 * The listing on wordpress.org is in English and the readme's captions are in
 * English, so pictures of a Spanish dashboard read as pictures of a different
 * plugin. The development site this runs against is somebody's, in their
 * language, with their site name — so this says what it needs for the length
 * of the run and the `options` fixture puts all of it back afterwards,
 * exactly as it does for a plugin setting.
 */
const SHOP_WINDOW = {
	WPLANG: '',
	blogname: 'Rivera Club',
	blogdescription: 'Members, courses and the odd asado',
	// The account area's sections as the plugin names them: a development
	// site keeps whatever labels somebody typed into it, in their language.
	diluxone_users_account_sections: {},
};

/** The member the front-end pictures are of: an address, not a test id. */
const MEMBER = 'ana.gomez@riveraclub.example';

/*
 * The development site keeps what the other suites leave behind when a run is
 * interrupted — accounts called shot-mu7n3709@e2e.test with expired sessions,
 * the page the menu spec draws its menu on — and a listing picture with those
 * in it is a picture of a test run. They go before the first picture, and the
 * member of the pictures goes after the last.
 */
test.beforeAll(async ({ baseURL }) => {
	const site = await Site.open(baseURL!);

	await site.deleteE2EUsers();
	await site.forgetMenu();
});

test.afterAll(async ({ baseURL }) => {
	await (await Site.open(baseURL!)).deleteUser(MEMBER);
});

/**
 * How tall a listing picture is allowed to be.
 *
 * wordpress.org scales a screenshot to the width of its column and shows it
 * whole, so a tall one arrives at the reader shrunk until nothing in it can
 * be read. The Design tab with its live preview is four and a half thousand
 * pixels of perfectly good screen; as a picture of what the plugin looks
 * like, the top third says everything the whole says and says it legibly.
 */
const TALLEST = 1600;

/** Waits until the screen has stopped becoming itself. */
async function settled(page: Page): Promise<void> {
	await page.evaluate(() => document.fonts.ready.then(() => undefined));

	await Promise.all(page.frames().map((frame) => frame.waitForLoadState('load').catch(() => undefined)));

	await page.evaluate(() => {
		window.scrollTo(0, 0);

		return new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
	});
}

/**
 * The whole window, for the pictures of the site's own pages.
 *
 * Cropping those to the plugin's block gives a 646-pixel column floating on
 * nothing — and the thing worth showing about the front end is precisely
 * that it is the site's page, in the site's theme, with the plugin inside it.
 */
async function shootWindow(page: Page, n: number): Promise<void> {
	mkdirSync(SHOTS, { recursive: true });
	await settled(page);

	await page.screenshot({ path: `${SHOTS}/screenshot-${n}.png` });
}

/**
 * One block of the dashboard, capped.
 *
 * `fullPage` with a clip rather than the element's own screenshot: the
 * element's own would be as tall as the element, and the cap is the point.
 */
async function shootBlock(page: Page, n: number, selector: string): Promise<void> {
	mkdirSync(SHOTS, { recursive: true });
	await settled(page);

	const box = await page.locator(selector).first().boundingBox();

	if (!box) {
		throw new Error(`nothing to photograph for screenshot-${n}: ${selector} has no box`);
	}

	await page.screenshot({
		path: `${SHOTS}/screenshot-${n}.png`,
		fullPage: true,
		clip: { ...box, height: Math.min(box.height, TALLEST) },
	});
}

/**
 * The block the dashboard pictures are of.
 *
 * The plugin's own wrap and not the window: the admin bar says how long the
 * page took to build, the menu carries update badges and the footer prints
 * the WordPress version. None of that is this plugin's, and all of it would
 * date the picture the week after it was taken.
 */
const ADMIN_BLOCK = '.wrap.diluxone-users-admin';

/* ── The front end: what most people ever see ──────────────────────── */

test.describe('The front end', () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	test('1 · the sign-in page, with every way in the site turned on', async ({ page, pages, options }) => {
		await options.set({
			...SHOP_WINDOW,
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_users_sso_login: 1,
			diluxone_users_login_layout: 'tabs',
			diluxone_users_login_order: ['social', 'email', 'password'],
			diluxone_users_login_open: 'email',
		});

		await page.goto(pages.login.url);

		await expect(page.locator('.diluxone-users-login').first()).toBeVisible();
		await shootWindow(page, 1);
	});

	test('4 · the second step at sign-in, for whoever turned it on', async ({ page, site, pages, options }) => {
		await options.set({
			...SHOP_WINDOW,
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
		});

		const email = MEMBER;
		await site.deleteUser(email);
		await site.makeUser({ email, password: PASSWORD, name: 'Ana Gómez' });

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);

		await expect(challengeScreen(page)).toBeVisible();

		// The box is filled but not submitted: the picture is of the screen
		// asking, and an empty box reads as a screen that has not loaded.
		const code = codeIn(await waitForMail(site, email, { subject: /c(o|ó)digo|code/i }));

		await challengeCode(page).fill(code);

		await shootWindow(page, 4);
	});
});

/* ── The account area, as the person who owns it sees it ───────────── */

test.describe('The account area', () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	/** Signs one person in and hands back where their account lives. */
	async function asMember(page: Page, site: any, pages: any): Promise<string> {
		const email = MEMBER;

		await site.deleteUser(email);
		await site.makeUser({
			email,
			password: PASSWORD,
			name: 'Ana Gómez',
			meta: {
				first_name: 'Ana',
				last_name: 'Gómez',
				diluxone_users_country: 'AR',
				diluxone_users_phone: '+5491150000000',
			},
		});

		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);

		return pages.account.url;
	}

	test('2 · the account area on the front end, in the site’s own theme', async ({ page, site, pages, options }) => {
		await options.set({ ...SHOP_WINDOW, diluxone_users_login_method: 'both' });

		const account = await asMember(page, site, pages);

		await page.goto(account);

		await expect(page.locator('.diluxone-users-account').first()).toBeVisible();
		await shootWindow(page, 2);
	});

	test('3 · security: passkeys, two-step verification and every browser signed in', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({
			...SHOP_WINDOW,
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['totp', 'email'],
		});

		const account = await asMember(page, site, pages);

		await page.goto(accountSection(account, 'security'));

		await expect(page.locator('.diluxone-users-account').first()).toBeVisible();
		await shootWindow(page, 3);
	});
});

/* ── The dashboard ─────────────────────────────────────────────────── */

test.describe('The dashboard', () => {
	test.use({ storageState: ADMIN_STATE });

	/*
	 * The development site keeps what the other suites leave behind when a
	 * run is interrupted — accounts called shot-mu7n3709@e2e.test with
	 * sessions that expired an hour ago — and a listing picture of the users
	 * list or the open sessions with those in it is a picture of a test run.
	 */
	test.beforeEach(async ({ options }) => {
		await options.set(SHOP_WINDOW);
	});

	/**
	 * Each picture, the screen it is of, and the caption it answers to.
	 *
	 * The captions are the readme's, word for word. When one of them changes
	 * here it has to change there in the same commit — that is the whole
	 * contract this table exists to keep.
	 */
	const SCREENS: Array<{ n: number; screen: string; tab: string; caption: string }> = [
		{
			n: 5,
			screen: 'diluxone-users',
			tab: 'usage',
			caption: 'how many accounts, how they get in, and the first steps until there are none left',
		},
		{
			n: 6,
			screen: 'diluxone-users-login',
			tab: 'summary',
			caption: 'every way into the site in one table, read from the settings the other tabs write',
		},
		{
			n: 7,
			screen: 'diluxone-users-security',
			tab: '2fa',
			caption: 'two-step verification: when it is asked for, with what, and to whom',
		},
		{
			n: 8,
			screen: 'diluxone-users-social',
			tab: 'providers',
			caption: 'social login: twelve networks, each with its own credentials and a live test',
		},
		{
			n: 9,
			screen: 'diluxone-users-fields',
			tab: 'list',
			caption: 'user fields: what is asked of a person, where it shows and who can change it',
		},
		{
			n: 10,
			screen: 'diluxone-users-design',
			tab: 'account',
			caption: 'how the account area looks, with a live preview of the real markup',
		},
		{
			n: 11,
			screen: 'diluxone-users-reports',
			tab: 'sessions',
			caption: 'open sessions across the site, with the button to close them',
		},
		{
			n: 13,
			screen: 'diluxone-users-status',
			tab: 'status',
			caption: 'maintenance: every check, including the ones that fail',
		},
	];

	for (const { n, screen, tab, caption } of SCREENS) {
		test(`${n} · ${caption}`, async ({ page }) => {
			await page.goto(adminUrl(screen, tab));

			await expect(page.locator(ADMIN_BLOCK)).toBeVisible();
			await shootBlock(page, n, ADMIN_BLOCK);
		});
	}

	test('12 · the Access column WordPress’s own Users list gains', async ({ page }) => {
		// The one dashboard picture that is not of this plugin's screen: the
		// column it adds to a screen that is WordPress's. So the whole table
		// is photographed, because the column means nothing beside the ones
		// it sits with.
		await page.goto('/wp-admin/users.php');

		await expect(page.locator('table.wp-list-table')).toBeVisible();
		await shootBlock(page, 12, 'table.wp-list-table');
	});
});
