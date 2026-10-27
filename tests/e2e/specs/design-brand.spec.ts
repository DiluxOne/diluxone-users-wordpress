import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl, savePanel } from '../support/ui';
import { resolvedColour, rgb } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Your brand: one question, two answers and Users+ Pro's third, and what
 * hangs off each of them.
 *
 * The screen used to be six blocks in a column and it was read as six
 * questions, which is what it was. Now it is one — where the plugin's look
 * comes from — and everything else is a child of the answer that needs it.
 * That shape is only true if the browser actually shows and hides the right
 * things, and if the thing shown is in a state somebody can act on: a row of
 * colour squares with nothing in them is a screen that answers 200 and still
 * cannot be used.
 *
 * Nothing in here is found by its words. The site this runs against is in
 * Spanish and the plugin ships eight locales, so every hold is a name, an id
 * or a class: the radio posts `diluxone_users_look`, the accent's swatches
 * carry `data-diluxone-users-swatches`, the picture of Users+ Pro's map of the
 * theme's colours `data-diluxone-users-pro`, and its squares are
 * `.diluxone-users-palette__chip`.
 */

test.use({ storageState: ADMIN_STATE });

const BRAND = adminUrl('diluxone-users-design', 'brand');

/** The two answers, by the value each one posts. */
type Look = 'own' | 'site';

function look(page: Page, answer: Look): Locator {
	return page.locator(`input[name="diluxone_users_look"][value="${answer}"]`);
}

/** The card of one answer and everything indented under it. */
function branch(page: Page, answer: Look): Locator {
	return page.locator('.du-choice-group').filter({ has: look(page, answer) });
}

/** What belongs to "I choose it here": the accent's swatches. */
function accent(page: Page): Locator {
	return page.locator('[data-diluxone-users-swatches="diluxone_users_style_accent"]');
}

/** "From your theme": Users+ Pro's, the radio it would be, switched off. */
function fromTheme(page: Page): Locator {
	return page.locator('[data-diluxone-users-pro="design/brand/theme"]');
}

/** After it, the rows of which colour does what, Users+ Pro's too. */
function fineTuning(page: Page): Locator {
	return page.locator('[data-diluxone-users-pro="design/brand/color-map"]');
}

/** What belongs to "the site writes it": the properties, to copy. */
function properties(page: Page): Locator {
	return branch(page, 'site').locator('.du-note');
}

test.describe('Your brand › one question, and what hangs off the answer', () => {
	test('each answer shows its own and hides the other, and “from your theme” is a disabled Users+ Pro preview', async ({ page, options }) => {
		// From a known answer rather than from whatever the last run left: the
		// assertion is about what changes when the answer changes. A site
		// that had the theme's colours saved gets the plugin's own.
		await options.set({ diluxone_users_styles: 1, diluxone_users_colors: 'theme', diluxone_users_pro_previews: 1 });

		await page.goto(BRAND);

		await expect(look(page, 'own')).toBeChecked();
		await expect(accent(page)).toBeVisible();
		await expect(properties(page)).toBeHidden();

		await expect(page.locator('input[name="diluxone_users_look"][value="theme"]'), 'no answer to post').toHaveCount(0);
		await expect(fromTheme(page).locator('input[type="radio"]')).toBeDisabled();
		await expect(fromTheme(page).locator('[name]'), 'nothing in it posts').toHaveCount(0);
		await expect(fromTheme(page).locator('.diluxone-users-state--pro'), 'the badge').toHaveCount(1);
		await expect(fromTheme(page).locator('a')).toHaveAttribute('href', /diluxone\.com\/wordpress-plugins\/users\/.*utm_medium=design/);

		await look(page, 'site').check();

		await expect(properties(page)).toBeVisible();
		await expect(accent(page)).toBeHidden();
	});

	/**
	 * The squares beside the seven parts, which is where the screen was worst.
	 *
	 * A part nobody mapped was painted `transparent`: a bordered square with
	 * nothing in it, seven of them in a column, indistinguishable from a
	 * checkbox nobody has ticked. The claim is not that a particular colour is
	 * in there — that is the theme's business — but that there is one.
	 */
	test('no colour square is left empty', async ({ page, options }) => {
		await options.set({
			diluxone_users_styles: 1,
			diluxone_users_pro_previews: 1,
		});

		await page.goto(BRAND);

		const chips = fineTuning(page).locator('.diluxone-users-palette__chip');

		await expect(chips.first()).toBeVisible();

		const painted = await chips.evaluateAll((squares: Element[]) =>
			squares.map((square) => window.getComputedStyle(square).backgroundColor)
		);

		expect(painted.length).toBe(7);

		for (const colour of painted) {
			expect(colour).not.toBe('rgba(0, 0, 0, 0)');
			expect(colour).not.toBe('transparent');
		}
	});
});

test.describe('Your brand › what the one question is kept as', () => {
	const KEYS = ['diluxone_users_styles', 'diluxone_users_colors'];

	/**
	 * One question on the screen, one setting underneath it: whether the
	 * stylesheet is loaded. The colours are the plugin's own, and no answer
	 * writes a source for them.
	 */
	test('each answer writes the setting that is actually read, and no colour source', async ({ page, site, options }) => {
		await options.keep(KEYS);
		await options.set({ diluxone_users_colors: null });

		const expected: Array<[Look, string]> = [
			['site', '0'],
			['own', '1'],
		];

		for (const [answer, styles] of expected) {
			await page.goto(BRAND);
			await look(page, answer).check();
			await savePanel(page);

			const got = await site.getOptions(KEYS);

			expect(String(got.diluxone_users_styles), answer).toBe(styles);
			expect(got.diluxone_users_colors ?? null, answer).toBeNull();
		}
	});

	/**
	 * Trying the theme's palette for an afternoon does not cost you your colour.
	 *
	 * With an add-on's theme colours (Users+ Pro's), the picker is folded
	 * away and a folded control may not be posted at all — so the save must
	 * not read an absence as "no colour" and write the accent away.
	 */
	test('the accent, a colour kept from Users+ Pro included, survives a save made while an add-on’s theme palette is in use', async ({
		page,
		site,
		options,
	}) => {
		await options.set({
			diluxone_users_styles: 1,
			diluxone_e2e_colors_from_theme: 1,
			diluxone_users_style_accent: '#123456',
		});

		await page.goto(BRAND);

		await expect(accent(page)).toBeHidden();

		await savePanel(page);

		const got = await site.getOptions(['diluxone_users_style_accent']);

		expect(String(got.diluxone_users_style_accent)).toBe('#123456');
	});
});

test.describe('Your brand › the plugin’s own colours on the front end', () => {
	/**
	 * #188 C6: the plugin alone paints with its palette and the accent chosen
	 * here, light and dark, even on a site that had the theme's colours saved;
	 * the theme is not measured.
	 */
	for (const scheme of ['light', 'dark'] as const) {
		test(`the sign-in page is drawn with the palette accent, ${scheme}`, async ({ guest, pages, options }) => {
			await options.set({
				diluxone_users_styles: 1,
				diluxone_users_colors: 'theme',
				diluxone_users_style_accent: '#6d28d9',
			});

			await guest.emulateMedia({ colorScheme: scheme });
			await guest.goto(pages.login.url);

			expect(await resolvedColour(guest, '--diluxone-users-accent')).toBe(rgb('#6d28d9'));
			expect(await guest.locator('.diluxone-users-login form button[type="submit"]').first().evaluate((button) => getComputedStyle(button).backgroundColor)).toBe(rgb('#6d28d9'));
			await expect(guest.locator('script#diluxone-users-theme-colors-js'), 'the theme is not measured').toHaveCount(0);
		});
	}
});
