import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl, emailField, linkForm, navigated, saveButton, savePanel } from '../support/ui';
import { forgetPicture, mediaPicture, previewAnswer, resolvedColour, rgb, stage } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Design › Your brand: every control on the tab saved, read back after the
 * reload, and measured on the public sign-in page — the computed style, the
 * custom property, the class — never a picture.
 */

test.use({ storageState: ADMIN_STATE });

const BRAND_KEYS = [
	'diluxone_users_styles',
	'diluxone_users_colors',
	'diluxone_users_color_map',
	'diluxone_users_style_accent',
	'diluxone_users_style_radius',
	'diluxone_users_style_control',
	'diluxone_users_style_border',
	'diluxone_users_button_style',
	'diluxone_users_button_icons',
	'diluxone_users_notice_style',
	'diluxone_users_login_logo',
];

const look = (page: Page, answer: 'theme' | 'own' | 'site') => page.locator(`input[name="diluxone_users_look"][value="${answer}"]`);

/** The sign-in form's own button, the one the brand paints. */
const linkButton = (page: Page) => linkForm(page).locator('button[type="submit"]');

test.beforeEach(async ({ options }) => {
	await options.keep(BRAND_KEYS);
	await options.set({
		diluxone_users_styles: 1,
		diluxone_users_colors: 'own',
		diluxone_users_style_accent: '',
		diluxone_users_style_radius: '',
		diluxone_users_style_control: '',
		diluxone_users_style_border: '',
		diluxone_users_button_style: 'solid',
		diluxone_users_button_icons: 0,
		diluxone_users_notice_style: 'bar',
		diluxone_users_login_logo: 0,
	});
});

test.describe('Design › Your brand', () => {
	test('“the site writes it”: the plugin’s stylesheet leaves the public pages; back to “mine”, it returns', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await look(page, 'site').check({ force: true });
		await savePanel(page);
		await expect(look(page, 'site')).toBeChecked();
		expect(Number((await site.getOptions(['diluxone_users_styles'])).diluxone_users_styles)).toBe(0);

		await guest.goto(pages.login.url);
		await expect(guest.locator('link#diluxone-users-css')).toHaveCount(0);
		await expect(guest.locator('style#diluxone-users-inline-css')).toHaveCount(0);

		await look(page, 'own').check({ force: true });
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(guest.locator('link#diluxone-users-css')).toHaveCount(1);
	});

	test('an accent chosen in the picker is the accent of the sign-in page and of its filled button', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await look(page, 'own').check({ force: true });
		await page.locator('#diluxone_users_style_accent').fill('#7b2d8e');
		await savePanel(page);

		await expect(page.locator('#diluxone_users_style_accent')).toHaveValue('#7b2d8e');
		expect((await site.getOptions(['diluxone_users_style_accent'])).diluxone_users_style_accent).toBe('#7b2d8e');

		await guest.goto(pages.login.url);
		expect(await resolvedColour(guest, '--diluxone-users-accent')).toBe(rgb('#7b2d8e'));
		expect(await linkButton(guest).evaluate((button) => getComputedStyle(button).backgroundColor)).toBe(rgb('#7b2d8e'));
	});

	test('the corners: 0 squares every button; “back to the default” empties the box, redraws the preview and saves the plugin’s own', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		await guest.goto(pages.login.url);

		const shipped = await linkButton(guest).evaluate((button) => getComputedStyle(button).borderTopLeftRadius);

		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await page.locator('[name="diluxone_users_style_radius"]').fill('0');
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_style_radius'])).diluxone_users_style_radius).toBe('0');

		await guest.goto(pages.login.url);
		expect(await linkButton(guest).evaluate((button) => getComputedStyle(button).borderTopLeftRadius)).toBe('0px');

		const box = page.locator('[data-diluxone-users-save]').first();
		const redraw = previewAnswer(page);

		await page.locator('button[data-diluxone-users-default="#diluxone_users_style_radius"]').click();
		await expect(page.locator('[name="diluxone_users_style_radius"]')).toHaveValue('');
		await expect(box, 'emptied is a change to save').toHaveClass(/is-dirty/);
		await redraw;

		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_style_radius'])).diluxone_users_style_radius).toBe('');

		await guest.goto(pages.login.url);
		expect(await linkButton(guest).evaluate((button) => getComputedStyle(button).borderTopLeftRadius)).toBe(shipped);
	});

	test('the controls: their height and their border are the ones typed', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await page.locator('[name="diluxone_users_style_control"]').fill('52');
		await page.locator('[name="diluxone_users_style_border"]').fill('2');
		await savePanel(page);

		await expect(page.locator('[name="diluxone_users_style_control"]')).toHaveValue('52');
		expect(await site.getOptions(['diluxone_users_style_control', 'diluxone_users_style_border'])).toEqual({
			diluxone_users_style_control: '52',
			diluxone_users_style_border: '2',
		});

		await guest.goto(pages.login.url);

		const measured = await emailField(guest).evaluate((input) => {
			const style = getComputedStyle(input);

			return { height: Math.round(input.getBoundingClientRect().height), border: style.borderTopWidth };
		});

		expect(measured).toEqual({ height: 52, border: '2px' });
	});

	for (const style of ['outline', 'soft'] as const) {
		test(`buttons “${style}”: the filled button is no longer filled with the accent`, async ({ page, guest, pages }) => {
			await page.goto(adminUrl('diluxone-users-design', 'brand'));
			await look(page, 'own').check({ force: true });
			await page.locator('#diluxone_users_style_accent').fill('#7b2d8e');
			await page.locator(`input[name="diluxone_users_button_style"][value="${style}"]`).check({ force: true });
			await savePanel(page);
			await expect(page.locator(`input[name="diluxone_users_button_style"][value="${style}"]`)).toBeChecked();

			await guest.goto(pages.login.url);

			const painted = await linkButton(guest).evaluate((button) => ({ bg: getComputedStyle(button).backgroundColor, ink: getComputedStyle(button).color }));

			expect(painted.bg, 'not the solid accent').not.toBe(rgb('#7b2d8e'));
			expect(painted.ink, 'the words take the accent instead').toBe(rgb('#7b2d8e'));

			if (style === 'outline') {
				expect(painted.bg).toBe('rgba(0, 0, 0, 0)');
			}
		});
	}

	test('icons in the doors: on, the link button carries an icon; off, it does not', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await page.locator('input[type="checkbox"][name="diluxone_users_button_icons"]').setChecked(true, { force: true });
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_button_icons'])).diluxone_users_button_icons)).toBe(1);

		await guest.goto(pages.login.url);
		await expect(linkButton(guest).locator('svg')).toHaveCount(1);

		await page.locator('input[type="checkbox"][name="diluxone_users_button_icons"]').setChecked(false, { force: true });
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(linkButton(guest).locator('svg')).toHaveCount(0);
	});

	test('messages “soft”: the notice loses its bar down the side; “bar” puts it back', async ({ page, guest, site, pages }) => {
		const expired = `${pages.login.url}${pages.login.url.includes('?') ? '&' : '?'}diluxone-users=expired`;

		await guest.goto(expired);

		const notice = guest.locator('.diluxone-users-notice--error').first();

		await expect(notice).toBeVisible();

		const bar = await notice.evaluate((element) => getComputedStyle(element).borderLeftWidth);

		expect(bar, 'the plugin’s own notice has its bar').not.toBe('0px');

		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await page.locator('input[name="diluxone_users_notice_style"][value="soft"]').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_notice_style'])).diluxone_users_notice_style).toBe('soft');

		await guest.goto(expired);
		expect(await notice.evaluate((element) => getComputedStyle(element).borderLeftWidth)).toBe('0px');

		await page.locator('input[name="diluxone_users_notice_style"][value="bar"]').check({ force: true });
		await savePanel(page);

		await guest.goto(expired);
		expect(await notice.evaluate((element) => getComputedStyle(element).borderLeftWidth)).toBe(bar);
	});

	test('the site’s logo, picked from the media library, stands above the sign-in form; cleared, it is gone', async ({ page, guest, site, pages }) => {
		const picture = await mediaPicture(page.request, 'e2e-brand-logo');

		try {
			await page.goto(adminUrl('diluxone-users-design', 'brand'));

			const field = page.locator('[data-diluxone-users-image]').filter({ has: page.locator('input[name="diluxone_users_login_logo"]') });

			await field.locator('[data-diluxone-users-image-pick]').click();

			const modal = page.locator('.media-modal:visible');

			await expect(modal).toBeVisible();

			const item = modal.locator(`li.attachment[data-id="${picture.id}"]`);

			// The library opens on its grid; it may have to be asked for first.
			if ((await item.count()) === 0) {
				await modal.locator('.media-menu-item, .media-router button, [role="tab"]').filter({ hasText: /./ }).last().click();
			}

			await item.click();

			const redraw = previewAnswer(page);

			await modal.locator('.media-button-select').click();
			await expect(modal).toBeHidden();
			await expect(field.locator('input[name="diluxone_users_login_logo"]')).toHaveValue(String(picture.id));
			await expect(field.locator('[data-diluxone-users-image-preview]')).toBeVisible();
			await expect(field.locator('[data-diluxone-users-image-preview] img')).toHaveAttribute('src', /e2e-brand-logo/);
			await redraw;

			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_login_logo'])).diluxone_users_login_logo)).toBe(picture.id);

			await guest.goto(pages.login.url);

			const logo = guest.locator('.diluxone-users-login__logo img');

			await expect(logo).toHaveAttribute('src', /e2e-brand-logo/);

			const above = await guest.locator('.diluxone-users-login').evaluate((root) => {
				const mark = root.querySelector('.diluxone-users-login__logo')!;
				const form = root.querySelector('form')!;

				return Boolean(mark.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING);
			});

			expect(above, 'the logo comes before the form').toBe(true);

			await page.goto(adminUrl('diluxone-users-design', 'brand'));
			await field.locator('[data-diluxone-users-image-clear]').click();
			await expect(field.locator('input[name="diluxone_users_login_logo"]')).toHaveValue('0');
			await expect(field.locator('[data-diluxone-users-image-preview]')).toBeHidden();
			await savePanel(page);

			expect(Number((await site.getOptions(['diluxone_users_login_logo'])).diluxone_users_login_logo)).toBe(0);
			await guest.goto(pages.login.url);
			await expect(guest.locator('.diluxone-users-login__logo')).toHaveCount(0);
		} finally {
			await forgetPicture(page.request, 'e2e-brand-logo');
		}
	});

	test('the theme’s palette: a colour mapped to the accent is the accent the sign-in page shows', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await look(page, 'theme').check({ force: true });

		const fine = page.locator('details[data-diluxone-users-brand-map]');

		if (!(await fine.evaluate((element: HTMLDetailsElement) => element.open))) {
			await fine.locator('> summary').click();
		}

		const select = page.locator('#diluxone_users_color_map_accent');
		const slugs = await select.locator('option').evaluateAll((all) => all.map((one) => (one as HTMLOptionElement).value).filter((value) => value !== ''));
		const now = await select.inputValue();
		const chosen = slugs.find((slug) => slug !== now)!;

		expect(chosen, 'the theme has a palette to choose from').toBeTruthy();

		await select.selectOption(chosen);
		await savePanel(page);

		expect(((await site.getOptions(['diluxone_users_color_map'])).diluxone_users_color_map as Record<string, string>).accent).toBe(chosen);

		if (!(await fine.evaluate((element: HTMLDetailsElement) => element.open))) {
			await fine.locator('> summary').click();
		}

		await expect(select).toHaveValue(chosen);

		// The chip beside the select is the palette colour that slug names.
		const swatch = await page
			.locator('.diluxone-users-palette__row')
			.filter({ has: select })
			.locator('.diluxone-users-palette__chip')
			.evaluate((chip) => getComputedStyle(chip).backgroundColor);

		await guest.goto(pages.login.url);
		expect(await resolvedColour(guest, '--diluxone-users-accent'), 'the accent on the page is the palette colour chosen').toBe(swatch);
	});
});

test.describe('Design › Your brand › the live preview', () => {
	test('changing the accent redraws the stage with it before anything is saved, and saves nothing', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await look(page, 'own').check({ force: true });

		const redraw = previewAnswer(page);

		await page.locator('#diluxone_users_style_accent').fill('#1f8a3b');
		await redraw;

		await expect
			.poll(async () => resolvedColour(await stage(page), '--diluxone-users-accent').catch(() => ''), { message: 'the stage resolves the new accent' })
			.toBe(rgb('#1f8a3b'));

		expect((await site.getOptions(['diluxone_users_style_accent'])).diluxone_users_style_accent, 'the preview saves nothing').toBe('');
	});
});

test.describe('Design › Your brand › saved from another tab', () => {
	test('saving Your brand leaves the envelope chosen on the sign-in tab as it was', async ({ page, site, options }) => {
		await options.keep(['diluxone_users_sent_icon']);
		await options.set({ diluxone_users_sent_icon: 'circle' });

		await page.goto(adminUrl('diluxone-users-design', 'brand'));
		await page.locator('[name="diluxone_users_style_radius"]').fill('6');
		await navigated(page, () => saveButton(page).click());

		expect((await site.getOptions(['diluxone_users_sent_icon'])).diluxone_users_sent_icon).toBe('circle');
	});
});
