import type { Frame, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * What the preview columns claim, checked against the thing they claim it of.
 *
 * A preview is the one part of a settings screen that is an assertion rather
 * than a control: it says "this is what your site looks like". Three of them
 * were saying it and being wrong, each in its own way — a palette the frame
 * could not resolve, a page that could only ever show what was already saved,
 * and a column that was not the column. All three looked fine from the server
 * and all three were obvious to anybody looking at the screen, which is why
 * they are tested here and not next door.
 *
 * Nothing in here looks for a sentence: the site runs in Spanish and the
 * plugin ships eight locales.
 */

test.use({ storageState: ADMIN_STATE });

/** What a colour resolves to, asked of the document that has to resolve it. */
async function resolved(where: Page | Frame, property: string): Promise<string> {
	return where.evaluate((name: string) => {
		const probe = document.createElement('div');

		probe.style.backgroundColor = `var(${name})`;
		document.body.appendChild(probe);

		const painted = window.getComputedStyle(probe).backgroundColor;

		probe.remove();

		return painted;
	}, property);
}

/** The window a design tab draws its preview in. */
function stageFrame(page: Page) {
	return page.frameLocator('.diluxone-users-studio__preview iframe.diluxone-users-stage__frame');
}

/** The same window, as a frame one can evaluate in. */
async function stage(page: Page): Promise<Frame> {
	const element = await page.locator('.diluxone-users-studio__preview iframe.diluxone-users-stage__frame').elementHandle();
	const frame = await element!.contentFrame();

	await frame!.waitForLoadState('load');

	return frame!;
}

/**
 * The colours in the preview are the site's colours.
 *
 * This is the one that was wrong in the way that matters most. A theme is
 * allowed to publish its palette as properties of its own — Astra and its
 * like hand over `var(--ast-global-color-0)` — and the plugin passes those
 * straight through so the site follows the theme live. The frame had none of
 * the theme's stylesheet in it, so those properties resolved to nothing, every
 * token built on them fell back, and a name in white on a blue band came out
 * grey on white.
 *
 * So the assertion is not "the preview has a colour": it is that the colour
 * the preview resolves is the colour the site resolves, asked of both.
 */
test.describe('The preview takes the colours the site takes', () => {
	test('from the theme', async ({ page, options, pages }) => {
		await options.set({
			diluxone_users_styles: 1,
			diluxone_users_colors: 'theme',
			// The guess, whatever the theme's slugs turn out to be: the test is
			// about the colour arriving, not about which one was picked.
			diluxone_users_color_map: [],
		});

		await page.goto(pages.account.url);

		const onTheSite = await resolved(page, '--diluxone-users-accent');

		await page.goto(adminUrl('diluxone-users-design', 'brand'));

		const inThePreview = await resolved(await stage(page), '--diluxone-users-accent');

		// Unresolvable is how this failed: a property that cannot be worked out
		// leaves the paint transparent rather than wrong-coloured.
		expect(inThePreview).not.toBe('rgba(0, 0, 0, 0)');
		expect(inThePreview).toBe(onTheSite);

		// And it is the theme's: both falling back to the plugin's own blue
		// would be the same colour too.
		expect(onTheSite, 'the theme’s accent, not the plugin’s #2b59d6').not.toBe('rgb(43, 89, 214)');
	});

	/** And the ink on top of it, which is the half that went invisible. */
	test('including what is written on it', async ({ page, options, pages }) => {
		await options.set({
			diluxone_users_styles: 1,
			diluxone_users_colors: 'theme',
			diluxone_users_color_map: [],
		});

		await page.goto(pages.account.url);

		const onTheSite = await resolved(page, '--diluxone-users-accent-ink');

		await page.goto(adminUrl('diluxone-users-design', 'brand'));

		const inThePreview = await resolved(await stage(page), '--diluxone-users-accent-ink');

		expect(inThePreview).not.toBe('rgba(0, 0, 0, 0)');
		expect(inThePreview).toBe(onTheSite);
	});
});

/**
 * A preview that is a real page shows what was chosen, when it is asked to.
 *
 * wp-login.php cannot be drawn from the dashboard: the frame fetches the page
 * itself, so what is in it is what is saved. Untick the box, watch nothing
 * happen, conclude the setting is broken — which is exactly what happened.
 *
 * The button under the frame sends the form to the page instead, so the page
 * comes back drawn with what is on screen. Nothing is written: that is the
 * other half of what is checked here.
 */
test.describe('A real page can be shown with what was chosen', () => {
	test('the frame shows the page itself', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-design', 'wp'));

		const src = await page
			.locator('.diluxone-users-studio__preview iframe.diluxone-users-stage__frame')
			.getAttribute('src');

		expect(src ?? '').toContain('wp-login.php');
	});

	test('what was chosen, without saving it', async ({ page, site, options }) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);
		await options.set({ diluxone_users_wp_login_brand: 0, diluxone_users_wp_login_bg: '' });

		await page.goto(adminUrl('diluxone-users-design', 'wp'));

		// Switched on and given a colour nothing else on the site uses, so what
		// comes back can only have come from the form.
		await page.locator('input[name="diluxone_users_wp_login_brand"]').check();
		await page.locator('input[name="diluxone_users_wp_login_bg"]').fill('#7b2d8e');

		await Promise.all([
			page.waitForResponse((answer) => answer.url().includes('diluxone-users-try')),
			page.locator('button[data-diluxone-users-try]').click(),
		]);

		const trial = await stage(page);

		await expect(stageFrame(page).locator('[data-diluxone-users-trial]')).toBeVisible();

		await expect
			.poll(async () =>
				trial.evaluate(() => window.getComputedStyle(document.body).backgroundColor)
			)
			.toBe('rgb(123, 45, 142)');

		// And the site is exactly as it was: a trial is not a save.
		const saved = await site.getOptions(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);

		expect(Number(saved.diluxone_users_wp_login_brand)).toBe(0);
		expect(String(saved.diluxone_users_wp_login_bg ?? '')).toBe('');
	});

	/** With the box unticked, the trial shows the page with nothing painted on it. */
	test('and the page unpainted when the box comes off', async ({ page, options }) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);
		await options.set({ diluxone_users_wp_login_brand: 1, diluxone_users_wp_login_bg: '#7b2d8e' });

		await page.goto(adminUrl('diluxone-users-design', 'wp'));

		await page.locator('input[name="diluxone_users_wp_login_brand"]').uncheck();

		await Promise.all([
			page.waitForResponse((answer) => answer.url().includes('diluxone-users-try')),
			page.locator('button[data-diluxone-users-try]').click(),
		]);

		const trial = await stage(page);

		await expect
			.poll(async () =>
				trial.evaluate(() => window.getComputedStyle(document.body).backgroundColor)
			)
			.not.toBe('rgb(123, 45, 142)');
	});
});

/**
 * The social buttons are previewed where every other design tab previews.
 *
 * It was the last screen carrying two columns of its own, drawn inside the
 * column of fields — so its preview sat a step further in than all the others
 * and did not look like them. What is measured is that: the same left edge as
 * the tab next door, which is the thing a person sees and a page-level rule
 * cannot.
 */
test.describe('The social buttons are previewed in the preview column', () => {
	test('in the stage, at the same edge as every other tab', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));

		const elsewhere = await page.locator('.diluxone-users-studio__preview .diluxone-users-stage').boundingBox();

		await page.goto(adminUrl('diluxone-users-design', 'social'));

		const here = await page.locator('.diluxone-users-studio__preview .diluxone-users-stage').boundingBox();

		expect(here).not.toBeNull();
		expect(Math.round(here!.x)).toBe(Math.round(elsewhere!.x));
		expect(Math.round(here!.width)).toBe(Math.round(elsewhere!.width));
	});

	test('with the real buttons inside it', async ({ page, options }) => {
		await options.set({ diluxone_users_sso_button_show: 'icon-text' });

		await page.goto(adminUrl('diluxone-users-design', 'social'));

		await expect(stageFrame(page).locator('a.diluxone-users-social').first()).toBeVisible();
	});

	/**
	 * And the frame follows the choosers, which the old preview could not do
	 * for the words: they came from the server, and the browser had no way to
	 * rebuild them.
	 */
	test('and it follows what is typed', async ({ page, options }) => {
		await options.set({ diluxone_users_sso_button_text: '' });

		await page.goto(adminUrl('diluxone-users-design', 'social'));

		await expect(stageFrame(page).locator('a.diluxone-users-social').first()).toBeVisible();

		await page.locator('input[name="diluxone_users_sso_button_text"]').fill('Zzyzx %s');

		await expect(stageFrame(page).locator('a.diluxone-users-social').first()).toContainText('Zzyzx', {
			timeout: 15_000,
		});
	});

	/**
	 * What carries it there is the server: the panel's own save run over the
	 * form without writing, asked of admin-ajax.php with the screen's nonce.
	 * The answer is checked here, not only its effect, so a frame that caught
	 * up by some other road cannot pass for it.
	 */
	test('asked of the server, which draws it and saves nothing', async ({ page, site, options }) => {
		await options.set({ diluxone_users_sso_button_text: '' });

		await page.goto(adminUrl('diluxone-users-design', 'social'));

		const [answer] = await Promise.all([
			page.waitForResponse(
				(response) =>
					response.url().includes('admin-ajax.php') &&
					(response.request().postData() ?? '').includes('action=diluxone_users_preview')
			),
			page.locator('input[name="diluxone_users_sso_button_text"]').fill('Qwxyz %s'),
		]);

		expect(answer.status()).toBe(200);

		const body = await answer.json();

		expect(body.success, 'the preview endpoint answers').toBe(true);
		expect(JSON.stringify(body.data)).toContain('Qwxyz');
		expect(String((await site.getOptions(['diluxone_users_sso_button_text'])).diluxone_users_sso_button_text ?? '')).toBe('');
	});
});
