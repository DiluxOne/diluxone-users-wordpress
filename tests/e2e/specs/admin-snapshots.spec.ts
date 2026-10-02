import { test, expect } from '../support/fixtures';
import { Site } from '../support/api';
import { adminTabs } from '../support/screens';
import { adminUrl } from '../support/ui';
import { masks, pictureOf, settled } from '../support/pictures';
import { pinVisualState } from '../support/visual-state';
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

/*
 * The pictures are of the plugin in English, the language its strings are
 * written in. The development site this runs against is somebody's, in their
 * language: it is said for the length of each test, and the `options`
 * fixture puts it back, as the listing screenshots already do.
 */
test.beforeEach(async ({ options, site }) => {
	await options.set({ WPLANG: '' });
	await pinVisualState(site, options.set);
});

/**
 * What a single screen adds to the masks.
 *
 * The activity report's rail says how many rows the table holds, how much it
 * weighs and when the oldest is from: every run of the suite next door adds
 * rows, so the sentence is a different sentence every time. Its box is kept;
 * only the words are forgiven.
 */
const MASKED_ON: Record<string, string[]> = {
	'diluxone-users-reports › activity': ['.diluxone-users-studio__aside .du-state__line'],
	'diluxone-users-reports › logging': ['.diluxone-users-studio__aside .du-state__line'],
};

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
				mask: masks(page, MASKED_ON[tab.name]),
			});
		});
	}
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
				{ mask: masks(page) }
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
			{ mask: masks(page) }
		);
	});
});

/**
 * The Design tabs, on each shape they offer.
 *
 * The picture of each tab above is the tab on the site's shape, and the
 * preview inside it draws that one. The other shapes are where the preview
 * has the most to get right — a panel beside the form, a picture behind it, a
 * band across the account — and a single picture shows none of them. The
 * sign-in page itself, on each shape, is in `front-snapshots.spec.ts`.
 */
test.describe('Design, on every shape', () => {
	const LOGIN: Record<string, Record<string, unknown>> = {
		card: { diluxone_users_login_template: 'card' },
		'split-left': {
			diluxone_users_login_template: 'split',
			diluxone_users_login_side: 'left',
			diluxone_users_login_panel_title: 'One account.\nNo passwords.',
			diluxone_users_login_panel_text: 'Everything you do here, in one place.',
		},
		'split-right': {
			diluxone_users_login_template: 'split',
			diluxone_users_login_side: 'right',
			diluxone_users_login_panel_title: 'One account.\nNo passwords.',
			diluxone_users_login_panel_text: 'Everything you do here, in one place.',
		},
		backdrop: { diluxone_users_login_template: 'backdrop' },
	};

	for (const [shape, settings] of Object.entries(LOGIN)) {
		test(`the sign-in page as ${shape}`, async ({ page, options }) => {
			await options.set(settings);

			await page.goto(adminUrl('diluxone-users-design', 'login'));
			await settled(page);

			await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(`design-login-${shape}.png`, {
				mask: masks(page),
			});
		});
	}

	const ACCOUNT: Array<[string, string]> = [
		['plain', 'side'],
		['cover', 'tabs'],
		['cover', 'side'],
	];

	for (const [shape, layout] of ACCOUNT) {
		test(`the account as ${shape}, menu ${layout}`, async ({ page, options }) => {
			await options.set({ diluxone_users_account_template: shape, diluxone_users_account_layout: layout });

			await page.goto(adminUrl('diluxone-users-design', 'account'));
			await settled(page);

			await expect(page.locator('.wrap.diluxone-users-admin')).toHaveScreenshot(`design-account-${shape}-${layout}.png`, {
				mask: masks(page),
			});
		});
	}
});
