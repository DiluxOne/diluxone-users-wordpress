import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl, savePanel } from '../support/ui';
import { previewAnswer, rgb, signInMember, stage } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Design › The account area: the shape, the header and its pieces, the menu,
 * the spacing, the ground and the width — each saved through the tab's
 * control and measured on a subscriber's account by class and computed style.
 * The account area with a cover is Users+ Pro's, and its own suite covers
 * it; here a card stands where it would go, and a cover stored while Pro was
 * on is the simple area.
 */

test.use({ storageState: ADMIN_STATE });

const ACCOUNT_KEYS = [
	'diluxone_users_account_template',
	'diluxone_users_account_layout',
	'diluxone_users_account_nav_style',
	'diluxone_users_account_nav_align',
	'diluxone_users_account_width',
	'diluxone_users_account_header',
	'diluxone_users_account_avatar',
	'diluxone_users_account_since',
	'diluxone_users_account_action',
	'diluxone_users_account_ground',
	'diluxone_users_pro_previews',
	'diluxone_users_account_nav_small',
	'diluxone_users_account_row_w',
	'diluxone_users_account_row_pad',
	'diluxone_users_account_body_pad',
	'diluxone_users_account_nav_top',
	'diluxone_users_account_nav_bottom',
	'diluxone_users_account_nav_left',
	'diluxone_users_account_nav_right',
	'diluxone_users_account_bar_gap',
];

const TAB = adminUrl('diluxone-users-design', 'account');
const radio = (page: Page, name: string, value: string) => page.locator(`input[name="${name}"][value="${value}"]`);
const tick = (page: Page, name: string, on: boolean) => page.locator(`input[type="checkbox"][name="${name}"]`).setChecked(on, { force: true });
const area = (page: Page) => page.locator('.diluxone-users-account');

test.beforeEach(async ({ options, guest, site, pages }) => {
	await options.keep(ACCOUNT_KEYS);
	await options.set({
		diluxone_users_account_template: 'plain',
		diluxone_users_account_layout: 'tabs',
		diluxone_users_account_nav_style: 'pills',
		diluxone_users_account_nav_align: 'start',
		diluxone_users_account_width: 'contained',
		diluxone_users_account_header: 1,
		diluxone_users_account_avatar: 1,
		diluxone_users_account_since: 1,
		diluxone_users_account_action: 0,
		diluxone_users_account_ground: '',
		diluxone_users_pro_previews: 1,
		diluxone_users_account_nav_small: 'scroll',
		diluxone_users_account_row_w: '',
		diluxone_users_account_row_pad: '',
		diluxone_users_account_body_pad: '',
		diluxone_users_account_nav_top: '',
		diluxone_users_account_nav_bottom: '',
		diluxone_users_account_nav_left: '',
		diluxone_users_account_nav_right: '',
		diluxone_users_account_bar_gap: '',
	});

	await signInMember(guest, site, pages.login.url, 'design-account');
});

test.describe('Design › The account area', () => {
	test('pressing “Simple” puts back the pieces its shape is made of and redraws the stage', async ({ page, guest, site, pages }) => {
		await page.goto(TAB);
		await expect(page.locator('input[name="diluxone_users_account_template"]'), 'the one shape of the plugin’s own').toHaveCount(1);

		await radio(page, 'diluxone_users_account_nav_style', 'underline').check({ force: true });
		await tick(page, 'diluxone_users_account_action', true);

		const plain = radio(page, 'diluxone_users_account_template', 'plain');
		const preset = JSON.parse((await plain.getAttribute('data-diluxone-users-template-pieces'))!) as Record<string, string | number>;
		const redraw = previewAnswer(page);

		await plain.dispatchEvent('change');
		await expect(radio(page, 'diluxone_users_account_nav_style', String(preset.nav_style)), 'the menu’s look follows the shape').toBeChecked();
		await expect(page.locator('input[type="checkbox"][name="diluxone_users_account_action"]')).toBeChecked({ checked: String(preset.action) === '1' });

		await redraw;
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_template'])).diluxone_users_account_template).toBe('plain');

		await guest.goto(pages.account.url);
		await expect(area(guest)).toHaveClass(/diluxone-users-account--plain/);
		await expect(guest.locator('.diluxone-users-account__nav')).toHaveClass(new RegExp(`--${preset.nav_style}`));
	});

	test('where Users+ Pro’s cover would go: a shape after the real ones, disabled, gone with the previews setting', async ({ page, options }) => {
		await page.goto(TAB);

		const card = page.locator('[data-diluxone-users-pro="design/account/shapes"]');

		await expect(card).toHaveCount(1);
		await expect(card, 'in the row of shapes').toHaveClass(/diluxone-users-templates__one/);
		await expect(page.locator('.diluxone-users-templates > *').last(), 'after the real shapes').toHaveAttribute('data-diluxone-users-pro', 'design/account/shapes');
		await expect(card.locator('input[type="radio"]'), 'nothing to pick').toBeDisabled();
		await expect(card.locator('[name]'), 'nothing posts').toHaveCount(0);
		await expect(card.locator('.diluxone-users-state--pro'), 'the badge').toHaveCount(1);
		await expect(card.locator('a')).toHaveAttribute('href', /diluxone\.com\/wordpress-plugins\/users\/.*utm_medium=design/);
		await expect(page.locator('[name="diluxone_users_account_cover"], [name="diluxone_users_account_cover_kind"]'), 'nothing of the cover is a control here').toHaveCount(0);

		await options.set({ diluxone_users_pro_previews: 0 });
		await page.goto(TAB);
		await expect(card).toHaveCount(0);
	});

	test('a cover stored while Pro was on is the simple area on the page and kept on the tab', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_account_template: 'cover' });

		await guest.goto(pages.account.url);
		await expect(area(guest)).toHaveClass(/diluxone-users-account--plain/);
		await expect(area(guest)).not.toHaveClass(/--cover/);

		await page.goto(TAB);
		await expect(radio(page, 'diluxone_users_account_template', 'cover')).toBeChecked();
		await expect(page.locator('.diluxone-users-templates__one.is-chosen')).toContainText('Your Pro choice (inactive)');
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_template'])).diluxone_users_account_template).toBe('cover');
	});

	test('the header’s pieces: without the month and the button it keeps the picture; switched off, there is no header', async ({ page, guest, site, pages }) => {
		await page.goto(TAB);
		await tick(page, 'diluxone_users_account_action', true);
		await savePanel(page);

		await guest.goto(pages.account.url);
		await expect(guest.locator('.diluxone-users-account__since')).toHaveCount(1);
		await expect(guest.locator('.diluxone-users-account__action')).toHaveCount(1);

		await tick(page, 'diluxone_users_account_since', false);
		await tick(page, 'diluxone_users_account_action', false);
		await savePanel(page);
		const pieces = await site.getOptions(['diluxone_users_account_since', 'diluxone_users_account_action', 'diluxone_users_account_avatar']);

		expect([pieces.diluxone_users_account_since, pieces.diluxone_users_account_action, pieces.diluxone_users_account_avatar].map(Number)).toEqual([0, 0, 1]);

		await guest.goto(pages.account.url);
		await expect(guest.locator('.diluxone-users-account__header')).toHaveCount(1);
		await expect(guest.locator('.diluxone-users-account__avatar')).toHaveCount(1);
		await expect(guest.locator('.diluxone-users-account__since')).toHaveCount(0);
		await expect(guest.locator('.diluxone-users-account__action')).toHaveCount(0);

		await tick(page, 'diluxone_users_account_header', false);
		await savePanel(page);

		await guest.goto(pages.account.url);
		await expect(guest.locator('.diluxone-users-account__header')).toHaveCount(0);
		await expect(area(guest), 'opened by the menu, it says so').toHaveClass(/diluxone-users-account--bare/);
	});

	test('the menu: its style and its alignment are the classes and the place the account shows', async ({ page, guest, site, pages }) => {
		await page.goto(TAB);
		await radio(page, 'diluxone_users_account_nav_style', 'plain').check({ force: true });
		await radio(page, 'diluxone_users_account_nav_align', 'center').check({ force: true });
		await savePanel(page);
		expect(await site.getOptions(['diluxone_users_account_nav_style', 'diluxone_users_account_nav_align'])).toEqual({
			diluxone_users_account_nav_style: 'plain',
			diluxone_users_account_nav_align: 'center',
		});

		await guest.goto(pages.account.url);

		const nav = guest.locator('.diluxone-users-account__nav');

		await expect(nav).toHaveClass(/diluxone-users-account__nav--plain/);
		await expect(nav).toHaveClass(/diluxone-users-account__nav--center/);

		// Centred: the tabs of the first line are as far from one edge of the
		// strip as from the other. The first line only: on a site with more
		// sections the menu wraps, and a second line is centred on its own.
		const gap = await nav.evaluate((element) => {
			const all = [...element.querySelectorAll('a.diluxone-users-account__tab')].map((one) => one.getBoundingClientRect());
			const tabs = all.filter((one) => Math.abs(one.top - all[0].top) < 2);
			const box = element.getBoundingClientRect();

			return Math.abs(tabs[0].left - box.left - (box.right - tabs[tabs.length - 1].right));
		});

		expect(gap, 'as much room on either side').toBeLessThan(4);
	});

	test('menus that are not on their lists are drawn as the plain ones and not kept', async ({ page, guest, site, pages }) => {
		await page.goto(TAB);

		for (const [name, value] of [
			['diluxone_users_account_nav_style', 'pills'],
			['diluxone_users_account_nav_align', 'start'],
		]) {
			await radio(page, name, value).evaluate((input: HTMLInputElement) => {
				input.value = 'e2e-forged';
				input.checked = true;
			});
		}

		await savePanel(page);

		await guest.goto(pages.account.url);
		await expect(guest.locator('.diluxone-users-account__nav')).toHaveClass(/diluxone-users-account__nav--pills/);
		await expect(guest.locator('.diluxone-users-account__nav')).toHaveClass(/diluxone-users-account__nav--start/);

		const stored = await site.getOptions(['diluxone_users_account_nav_style', 'diluxone_users_account_nav_align']);

		expect
			.soft(Object.values(stored), 'bug: the menu style and alignment keep values from outside their lists (admin-design.php diluxone_users_design_account_save stores sanitize_key() of what was posted)')
			.not.toContain('e2e-forged');
	});

	test('the spacing typed is the spacing drawn; “back to the default” empties every box and saves the plugin’s own', async ({ page, guest, site, pages }) => {
		await page.goto(TAB);
		await page.locator('[name="diluxone_users_account_nav_top"]').fill('40');
		await page.locator('[name="diluxone_users_account_bar_gap"]').fill('0');
		await page.locator('[name="diluxone_users_account_row_w"]').fill('600');
		await page.locator('[name="diluxone_users_account_nav_left"]').fill('24');
		await savePanel(page);

		await guest.goto(pages.account.url);

		const measured = await guest.evaluate(() => {
			const root = getComputedStyle(document.documentElement);
			const body = document.querySelector('.diluxone-users-account__body')!;
			const nav = document.querySelector('.diluxone-users-account__bar .diluxone-users-account__nav')!;

			return {
				top: root.getPropertyValue('--diluxone-users-nav-top').trim(),
				gap: root.getPropertyValue('--diluxone-users-bar-gap').trim(),
				width: getComputedStyle(body).maxWidth,
				left: getComputedStyle(nav).paddingLeft,
			};
		});

		expect({ top: measured.top, gap: measured.gap, left: measured.left }).toEqual({ top: '40px', gap: '0px', left: '24px' });
		expect
			.soft(measured.width, 'bug: the width typed for the rows is beaten by the reading column’s own rule (templates.php diluxone_users_account_css prints `.diluxone-users-account__body{max-width:…}`, weaker than `.diluxone-users-account--contained .diluxone-users-account__body` in diluxone-users.css)')
			.toBe('600px');

		for (const target of ['#diluxone_users_account_nav_top', '#diluxone_users_account_bar_gap', '#diluxone_users_account_row_w']) {
			await page.locator(`button[data-diluxone-users-default*="${target}"]`).click();
		}

		for (const name of ['nav_top', 'nav_left', 'bar_gap', 'row_w']) {
			await expect(page.locator(`[name="diluxone_users_account_${name}"]`), `${name} is back to the plugin’s own`).toHaveValue('');
		}

		await savePanel(page);
		expect(await site.getOptions(['diluxone_users_account_nav_top', 'diluxone_users_account_bar_gap', 'diluxone_users_account_row_w', 'diluxone_users_account_nav_left'])).toEqual({
			diluxone_users_account_nav_top: '',
			diluxone_users_account_bar_gap: '',
			diluxone_users_account_row_w: '',
			diluxone_users_account_nav_left: '',
		});

		await guest.goto(pages.account.url);
		expect(await guest.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--diluxone-users-nav-top').trim())).toBe('');
	});

	test('on a fresh install the ground is “The theme’s”, the first swatch, and the account area prints no ground rule', async ({ page, guest, options, pages }) => {
		await options.set({ diluxone_users_account_ground: null });

		await page.goto(TAB);

		const first = page.locator('[data-diluxone-users-swatches="diluxone_users_account_ground"] input[type="radio"]').first();

		await expect(first, 'the theme’s, first').toHaveAttribute('value', '');
		await expect(first, 'and chosen').toBeChecked();

		await guest.goto(pages.account.url);
		await expect(area(guest)).toBeVisible();
		expect(
			await guest.evaluate(() => Array.from(document.querySelectorAll('style')).some((style) => /--diluxone-users-ground|\.diluxone-users-account\{background/.test(style.textContent ?? ''))),
			'no ground rule printed'
		).toBe(false);
		expect(await area(guest).evaluate((element) => getComputedStyle(element).backgroundColor), 'the page’s own ground shows through').toBe('rgba(0, 0, 0, 0)');
	});

	test('a ground from its palette is the colour behind the area; the theme’s keeps nothing, and a colour sent by hand is no answer', async ({ page, guest, site, pages }) => {
		await page.goto(TAB);

		const row = page.locator('[data-diluxone-users-swatches="diluxone_users_account_ground"]');

		await expect(row.locator('input[type="radio"]'), 'the theme’s and four shades').toHaveCount(5);
		await expect(row.locator('input[value=""]')).toBeChecked();

		await radio(page, 'diluxone_users_account_ground', '#eef6f1').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_ground'])).diluxone_users_account_ground).toBe('#eef6f1');

		await guest.goto(pages.account.url);
		expect(await area(guest).evaluate((element) => getComputedStyle(element).backgroundColor)).toBe(rgb('#eef6f1'));

		await radio(page, 'diluxone_users_account_ground', '#eef6f1').evaluate((input: HTMLInputElement) => {
			input.value = '#f3e9d2';
		});
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_ground'])).diluxone_users_account_ground, 'a shade nobody offered').toBe('');

		await radio(page, 'diluxone_users_account_ground', '').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_ground'])).diluxone_users_account_ground).toBe('');

		await guest.goto(pages.account.url);
		expect(await area(guest).evaluate((element) => getComputedStyle(element).backgroundColor)).not.toBe(rgb('#eef6f1'));
	});

	test('the width: “as wide as the theme allows” lifts the reading column’s limit', async ({ page, guest, site, pages }) => {
		const limit = () => guest.locator('.diluxone-users-account__body').evaluate((body) => getComputedStyle(body).maxWidth);

		await guest.goto(pages.account.url);
		await expect(area(guest)).toHaveClass(/diluxone-users-account--contained/);
		expect(await limit(), 'held to a reading column').not.toBe('none');

		await page.goto(TAB);
		await radio(page, 'diluxone_users_account_width', 'full').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_width'])).diluxone_users_account_width).toBe('full');

		await guest.goto(pages.account.url);
		await expect(area(guest)).toHaveClass(/diluxone-users-account--full/);
		expect(await limit(), 'no limit of its own').toBe('none');
	});

	test('“above and below” is the room above the area’s first row and under the taller of menu and content, in every layout', async ({ guest, pages, options }) => {
		const ROOM = 36;

		for (const layout of ['tabs', 'side']) {
			for (const header of [1, 0]) {
				await options.set({ diluxone_users_account_layout: layout, diluxone_users_account_header: header, diluxone_users_account_body_pad: String(ROOM) });
				await guest.goto(pages.account.url);
				await expect(area(guest)).toBeVisible();

				const gaps = await guest.evaluate(() => {
					const box = (selector: string) => document.querySelector(selector)?.getBoundingClientRect();
					const whole = box('.diluxone-users-account')!;
					const first = box('.diluxone-users-account__header') ?? box('.diluxone-users-account__bar') ?? box('.diluxone-users-account__body')!;
					const ends = [...document.querySelectorAll('.diluxone-users-account__nav .diluxone-users-account__tab, .diluxone-users-account__section')].map((element) => element.getBoundingClientRect().bottom);

					return { above: first.top - whole.top, below: whole.bottom - Math.max(...ends) };
				});

				const where = `${layout}, ${header ? 'with' : 'without'} the header`;

				expect(Math.abs(gaps.above - ROOM), `${where}: the room above its first row`).toBeLessThanOrEqual(1);
				expect(gaps.below, `${where}: the room under the taller of menu and content`).toBeGreaterThanOrEqual(ROOM - 1);
			}
		}
	});
});

test.describe('Design › The account area › the live preview', () => {
	test('“a menu down the side”, chosen and not saved, is drawn as a column in the stage; nothing is stored', async ({ page, site }) => {
		await page.goto(TAB);
		await expect.poll(async () => (await stage(page)).locator('.diluxone-users-account__nav').getAttribute('class').catch(() => '')).toContain('--row');

		const redraw = previewAnswer(page);

		await radio(page, 'diluxone_users_account_layout', 'side').check({ force: true });
		await redraw;

		await expect.poll(async () => (await stage(page)).locator('.diluxone-users-account__nav').getAttribute('class').catch(() => ''), { message: 'the stage draws a column' }).toContain('--column');
		expect((await site.getOptions(['diluxone_users_account_layout'])).diluxone_users_account_layout, 'the preview saves nothing').toBe('tabs');
	});
});

test.describe('Design › The account area on a phone', () => {
	test.use({ viewport: { width: 390, height: 844 } });

	test('“a grid with every section visible” wraps the menu instead of scrolling it sideways', async ({ page, guest, site, pages }) => {
		await guest.setViewportSize({ width: 390, height: 844 });

		await page.goto(TAB);
		await radio(page, 'diluxone_users_account_nav_small', 'wrap').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_account_nav_small'])).diluxone_users_account_nav_small).toBe('wrap');

		await guest.goto(pages.account.url);
		await expect(area(guest)).toHaveClass(/diluxone-users-account--nav-wrap/);

		const nav = guest.locator('.diluxone-users-account__nav');
		const fits = await nav.evaluate((element) => element.scrollWidth <= element.clientWidth + 1);

		expect(fits, 'every section on screen, nothing hidden past the edge').toBe(true);

		await radio(page, 'diluxone_users_account_nav_small', 'scroll').check({ force: true });
		await savePanel(page);

		await guest.goto(pages.account.url);
		await expect(area(guest)).toHaveClass(/diluxone-users-account--nav-scroll/);
	});
});
