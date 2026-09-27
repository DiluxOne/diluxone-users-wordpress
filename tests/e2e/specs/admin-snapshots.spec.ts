import type { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { Site } from '../support/api';
import { adminTabs } from '../support/screens';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * A picture of every screen, compared with the picture from last time.
 *
 * The measurements next door know the rules a layout must not break. They do
 * not know what the screens are supposed to look like, and they never will: a
 * line nobody asked for, a heading two sizes too big, a card whose ground went
 * grey, a preview that stopped matching the page it claims to preview — every
 * one of those keeps every rule and is still wrong. The only thing that
 * catches them is the picture, and the only thing that makes a picture an
 * assertion is having yesterday's to compare it with.
 *
 * Three things keep that from becoming a suite that cries wolf, and all three
 * are decisions rather than settings:
 *
 *   1. What is photographed is the plugin's own block, not the window. The
 *      admin bar counts how long the page took to build and says so; the menu
 *      carries update badges; the footer prints the WordPress version. None
 *      of that is this plugin's and all of it changes on its own.
 *   2. What moves by itself inside that block is masked — the dates and the
 *      counts in the reports, the environment table, an avatar.
 *   3. The window, the pixel ratio, the motion and the caret are all pinned,
 *      in the `visual` project in playwright.config.ts.
 *
 * A baseline is a picture of one machine's font rendering, which is why this
 * project is opt-in (`make test-visual`) rather than part of the run CI does.
 * When a change to a screen IS the change you wanted, `make test-visual-update`
 * writes the new pictures and the diff in the commit is the review.
 */

test.use({ storageState: ADMIN_STATE });

/**
 * What is painted over before the picture is taken.
 *
 * A mask keeps the element's box and fills it, so a block that changes SIZE
 * still shows up as a difference — which is the point. It is only the content
 * that is being forgiven, never the geometry. Selectors that match nothing on
 * a given screen cost nothing.
 */
const MOVES_BY_ITSELF = [
	// The report of who is signed in: when they signed in, when it expires,
	// and how many sessions they have open. All three change while you look.
	'.diluxone-users-list td:nth-child(2)',
	'.diluxone-users-list td:nth-child(3)',
	'.diluxone-users-list__num',
	// The environment table: PHP and WordPress versions, the site's paths.
	'.diluxone-users-summary td code',
	// Somebody's photograph, which comes from Gravatar or from the theme.
	'img.avatar',
];

/** The picture's name: the slug and the tab, never a translated title. */
function pictureOf(name: string): string {
	return `${name.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}.png`;
}

/**
 * Waits until the screen has stopped becoming itself.
 *
 * Fonts first — a screen photographed before its font arrives is a picture of
 * the fallback — then the frames, because the design tabs draw the real
 * sign-in page in an iframe, and then a pair of animation frames so the last
 * layout pass is over. `toHaveScreenshot` keeps shooting until two shots
 * match, so this is about getting there sooner, not about getting there.
 */
async function settled(page: Page): Promise<void> {
	await page.evaluate(() => document.fonts.ready.then(() => undefined));

	await Promise.all(page.frames().map((frame) => frame.waitForLoadState('load').catch(() => undefined)));

	await page.evaluate(() => {
		window.scrollTo(0, 0);

		return new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
	});
}

/*
 * The pictures are of the plugin in English, the language its strings are
 * written in. The development site this runs against is somebody's, in their
 * language: it is said for the length of each test, and the `options`
 * fixture puts it back, as the listing screenshots already do.
 */
test.beforeEach(async ({ options }) => {
	await options.set({ WPLANG: '' });
});

test.describe('Every screen looks like it did', () => {
	/*
	 * The overview counts the accounts on the site, and the suite next door
	 * makes accounts. They are all deleted when it ends, but a run that was
	 * interrupted leaves some behind, and then the first number on the first
	 * screen is off by two and every picture of it is wrong. This is one call
	 * and it makes the whole project independent of what ran before it.
	 */
	test.beforeAll(async ({ baseURL }) => {
		await (await Site.open(baseURL!)).deleteE2EUsers();
	});

	for (const tab of adminTabs()) {
		test(tab.name, async ({ page }) => {
			await page.goto(tab.url);
			await settled(page);

			await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(pictureOf(tab.name), {
				mask: MOVES_BY_ITSELF.map((one) => page.locator(one)),
			});
		});
	}
});

/**
 * And the one screen that is not in the dashboard at all.
 *
 * It is the only screen of this plugin most people will ever see, it is drawn
 * by the theme rather than by the dashboard's stylesheet, and every setting on
 * the Design tabs claims to change it. A picture of it is the only assertion
 * that has ever been able to tell whether the preview was telling the truth.
 */
test.describe('The sign-in page looks like it did', () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	test('signed out', async ({ page, pages }) => {
		await page.goto(pages.login.url);
		await settled(page);

		await expect(page.locator('.diluxone-users-login').first()).toHaveScreenshot('front-sign-in.png', {
			mask: MOVES_BY_ITSELF.map((one) => page.locator(one)),
		});
	});

	/**
	 * And the same screen with every way in switched on, behind tabs.
	 *
	 * The picture above is the ordinary case and it says nothing about the
	 * arrangement that was built for the other one: four doors on a laptop.
	 * That one has a tab strip in it, a neutral icon per tab, one panel
	 * showing and three not — none of which a measurement can look at. The
	 * settings are written here rather than left to the site's, because a
	 * baseline of a screen whose configuration drifts is a baseline that
	 * fails for a reason nobody can act on.
	 */
	test('signed out, four ways in, in tabs', async ({ page, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_users_sso_login: 1,
			diluxone_users_login_layout: 'tabs',
			diluxone_users_login_order: ['social', 'email', 'password'],
			diluxone_users_login_open: 'email',
		});

		await page.goto(pages.login.url);
		await settled(page);

		await expect(page.locator('.diluxone-users-login').first()).toHaveScreenshot('front-sign-in-tabs.png', {
			mask: MOVES_BY_ITSELF.map((one) => page.locator(one)),
		});
	});
});

/**
 * Your brand, in each of the three answers it can be on.
 *
 * Every other screen in this file is photographed once, in whatever state the
 * site happens to be in, and that is right: what they look like does not
 * depend on an answer given on the screen itself. This one is nothing but
 * that. Its whole shape — one question, and only what belongs to the answer
 * under it — is invisible to a single picture, which would show one third of
 * the screen and call it the screen.
 *
 * So: one picture per answer, and a fourth with the fine tuning opened. That
 * last one is where the bug was that nobody could name — seven bordered
 * squares with nothing in them, read as seven tick boxes — and it is the one
 * thing here a measurement cannot see, because an empty square and a painted
 * one are the same box.
 */
test.describe('Your brand looks like it did', () => {
	const ANSWERS: Record<string, Record<string, unknown>> = {
		theme: { diluxone_users_styles: 1, diluxone_users_colors: 'theme' },
		own: { diluxone_users_styles: 1, diluxone_users_colors: 'own' },
		site: { diluxone_users_styles: 0, diluxone_users_colors: 'own' },
	};

	for (const [answer, settings] of Object.entries(ANSWERS)) {
		test(`from ${answer}`, async ({ page, options }) => {
			await options.set(settings);

			await page.goto(adminUrl('diluxone-users-design', 'brand'));
			await settled(page);

			await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(
				`design-brand-${answer}.png`,
				{ mask: MOVES_BY_ITSELF.map((one) => page.locator(one)) }
			);
		});
	}

	test('from the theme, with the fine tuning open', async ({ page, options }) => {
		await options.set({
			...ANSWERS.theme,
			// The guess and nothing else, so the parts the theme says nothing
			// about are left to the plugin — the rows whose square used to be
			// empty.
			diluxone_users_color_map: [],
		});

		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await page.locator('details[data-diluxone-users-brand-map] summary').click();
		await settled(page);

		await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(
			'design-brand-theme-open.png',
			{ mask: MOVES_BY_ITSELF.map((one) => page.locator(one)) }
		);
	});
});
