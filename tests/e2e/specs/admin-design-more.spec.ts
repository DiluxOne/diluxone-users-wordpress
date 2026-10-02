import { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, adminUrl, navigated, savePanel, signInWithPassword, ssoButton } from '../support/ui';
import { MEMBER_PASSWORD, forgetPicture, mediaPicture, noisyPng, pickPicture, rgb, signInMember, stage } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Design › Social buttons, Profile photo and WordPress's own screen, and the
 * stage every Design tab shares: the device switch, the zoom and the trial
 * that draws a real page with unsaved choices — for its maker only.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Design › Social buttons', () => {
	test('the finish saved is the finish of the sign-in page’s buttons', async ({ page, guest, site, pages, options }) => {
		await options.keep([
			'diluxone_users_sso_button_skin',
			'diluxone_users_sso_button_shape',
			'diluxone_users_sso_button_show',
			'diluxone_users_sso_button_text',
			'diluxone_users_sso_button_columns',
		]);
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
		});

		await page.goto(adminUrl('diluxone-users-design', 'social'));
		await page.locator('select[name="diluxone_users_sso_button_skin"]').selectOption('dark');
		await page.locator('select[name="diluxone_users_sso_button_shape"]').selectOption('pill');
		await page.locator('select[name="diluxone_users_sso_button_show"]').selectOption('icon-text');
		await page.locator('select[name="diluxone_users_sso_button_columns"]').selectOption('1');
		await page.locator('[name="diluxone_users_sso_button_text"]').fill('Entrar con %s');
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_sso_button_skin', 'diluxone_users_sso_button_shape', 'diluxone_users_sso_button_columns'])).toMatchObject({
			diluxone_users_sso_button_skin: 'dark',
			diluxone_users_sso_button_shape: 'pill',
		});

		await guest.goto(pages.login.url);

		const group = guest.locator('.diluxone-users-socials');

		await expect(group).toHaveClass(/diluxone-users-socials--dark/);
		await expect(group).toHaveClass(/diluxone-users-socials--pill/);
		await expect(group).toHaveClass(/diluxone-users-socials--cols-1/);
		await expect(ssoButton(guest, 'mock').locator('.diluxone-users-social__text')).toHaveText('Entrar con Mock');
		await expect(ssoButton(guest, 'mock').locator('.diluxone-users-social__text')).toBeVisible();

		// The pill is a pill: its corners are at least half its height.
		const round = await ssoButton(guest, 'mock').evaluate((button) => parseFloat(getComputedStyle(button).borderTopLeftRadius) >= button.getBoundingClientRect().height / 2 - 1);

		expect(round).toBe(true);

		await page.locator('select[name="diluxone_users_sso_button_show"]').selectOption('icon');
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(group).toHaveClass(/diluxone-users-socials--icon\b/);

		// Logo only: the name is still in the button, for a screen reader, and not on screen.
		const text = ssoButton(guest, 'mock').locator('.diluxone-users-social__text');

		await expect(text).toHaveText('Entrar con Mock');
		const hidden = await text.evaluate((span) => {
			const box = span.getBoundingClientRect();

			return box.width <= 1 || box.height <= 1 || getComputedStyle(span).clip !== 'auto' || getComputedStyle(span).position === 'absolute';
		});

		expect(hidden, 'the words are kept for a screen reader, out of sight').toBe(true);
	});
});

test.describe('Design › Profile photo', () => {
	test('the largest size: a picture over it is refused and nothing is stored; with room, the same picture is taken', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_avatar_max_kb', 'diluxone_users_avatar_upload']);
		await options.set({ diluxone_users_avatar_upload: 1 });

		await page.goto(adminUrl('diluxone-users-design', 'photo'));
		// The smallest the box takes.
		await expect(page.locator('[name="diluxone_users_avatar_max_kb"]')).toHaveAttribute('min', '64');
		await page.locator('[name="diluxone_users_avatar_max_kb"]').fill('64');
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_avatar_max_kb'])).diluxone_users_avatar_max_kb)).toBe(64);

		const email = await signInMember(guest, site, pages.login.url, 'photo-size');
		const picture = noisyPng(160);

		expect(picture.length, 'the picture is over the limit').toBeGreaterThan(64 * 1024);

		const upload = async () => {
			await guest.goto(accountSection(pages.account.url, 'details'));

			const closed = guest.locator('details:not([open])').filter({ has: guest.locator('form.diluxone-users-avatar__form') });

			if ((await closed.count()) > 0) {
				await closed.first().locator('> summary').click();
			}

			const form = guest.locator('form.diluxone-users-avatar__form');

			await form.locator('input[name="diluxone_users_avatar_file"]').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: picture });
			await navigated(guest, () => form.locator('button[type="submit"]').first().click());
		};

		await upload();
		await expect(guest.locator('.diluxone-users-notice--error').first()).toBeVisible();
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar).toBe('');

		await page.locator('[name="diluxone_users_avatar_max_kb"]').fill('2048');
		await savePanel(page);

		await upload();
		expect(new URL(guest.url()).searchParams.get('diluxone-users')).toBe('saved');
		expect((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar, 'the attachment is kept').not.toBe('');
	});
});

test.describe('Design › WordPress’s own screen', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg', 'diluxone_users_wp_login_logo']);
		await options.set({ diluxone_users_wp_login_brand: 1, diluxone_users_wp_login_bg: '', diluxone_users_wp_login_logo: 0 });
	});

	test('the background colour saved is exactly the background of wp-login.php', async ({ page, guest, site }) => {
		await page.goto(adminUrl('diluxone-users-design', 'wp'));
		await page.locator('#diluxone_users_wp_login_bg').fill('#7b2d8e');
		await savePanel(page);

		await expect(page.locator('#diluxone_users_wp_login_bg')).toHaveValue('#7b2d8e');
		expect((await site.getOptions(['diluxone_users_wp_login_bg'])).diluxone_users_wp_login_bg).toBe('#7b2d8e');

		await guest.goto('/wp-login.php?diluxone-users-admin=1');
		expect(await guest.locator('body').evaluate((body) => getComputedStyle(body).backgroundColor)).toBe(rgb('#7b2d8e'));
	});

	test('a mark of its own replaces WordPress’s logo on wp-login.php, and the link still reads the site’s name', async ({ page, guest, site }) => {
		const picture = await mediaPicture(page.request, 'e2e-wp-mark');

		try {
			await page.goto(adminUrl('diluxone-users-design', 'wp'));
			await pickPicture(page, 'diluxone_users_wp_login_logo', picture);
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_wp_login_logo'])).diluxone_users_wp_login_logo)).toBe(picture.id);

			await guest.goto('/wp-login.php?diluxone-users-admin=1');

			const mark = guest.locator('#login h1 a');

			expect(await mark.evaluate((link) => getComputedStyle(link).backgroundImage)).toContain('e2e-wp-mark');

			await page.goto('/wp-admin/options-general.php');
			await expect(mark).toHaveText(await page.locator('#blogname').inputValue());
		} finally {
			await forgetPicture(page.request, 'e2e-wp-mark');
		}
	});
});

test.describe('Design › the stage', () => {
	test('the phone button draws the page 390 wide, the zoom opens it large and closes again', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-design', 'login'));

		const phone = page.locator('[data-diluxone-users-device="390"]');
		const desk = page.locator('[data-diluxone-users-device="1200"]');

		await expect(desk).toHaveClass(/is-on/);
		await phone.click();
		await expect(phone).toHaveClass(/is-on/);
		await expect(desk).not.toHaveClass(/is-on/);
		await expect.poll(async () => (await stage(page)).evaluate(() => window.innerWidth), { message: 'the page inside is a phone’s width' }).toBe(390);

		await desk.click();
		await expect.poll(async () => (await stage(page)).evaluate(() => window.innerWidth)).toBe(1200);

		const zoom = page.locator('dialog[data-diluxone-users-zoom-box]');

		await page.locator('[data-diluxone-users-zoom]').click();
		await expect(zoom).toHaveAttribute('open', '');
		await expect(zoom.locator('iframe[data-diluxone-users-zoom-frame]')).toBeVisible();
		await zoom.locator('[data-diluxone-users-zoom-close]').click();
		await expect(zoom).not.toHaveAttribute('open', '');

		await page.locator('[data-diluxone-users-zoom]').click();
		await expect(zoom).toHaveAttribute('open', '');
		await page.keyboard.press('Escape');
		await expect(zoom).not.toHaveAttribute('open', '');
	});

	test('a trial is its maker’s: a stranger and another administrator opening the same address see the saved page, and it ends', async ({
		page,
		guest,
		browser,
		baseURL,
		site,
		options,
	}) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);
		await options.set({ diluxone_users_wp_login_brand: 0, diluxone_users_wp_login_bg: '' });

		await page.goto(adminUrl('diluxone-users-design', 'wp'));
		await page.locator('input[name="diluxone_users_wp_login_brand"]').check();
		await page.locator('input[name="diluxone_users_wp_login_bg"]').fill('#7b2d8e');

		const [answer] = await Promise.all([
			page.waitForResponse((one) => one.url().includes('diluxone-users-try=')),
			page.locator('button[data-diluxone-users-try]').click(),
		]);
		const trialUrl = answer.url();

		expect(new URL(trialUrl).searchParams.get('diluxone-users-try')).toBeTruthy();

		const painted = async (who: Page) => {
			await who.goto(trialUrl);

			return {
				mark: await who.locator('[data-diluxone-users-trial]').count(),
				bg: await who.locator('body').evaluate((body) => getComputedStyle(body).backgroundColor),
			};
		};

		const mine = await page.context().newPage();

		expect(await painted(mine), 'the maker sees the trial').toEqual({ mark: 1, bg: rgb('#7b2d8e') });

		const stranger = await painted(guest);

		expect(stranger.mark, 'a stranger is shown no trial').toBe(0);
		expect(stranger.bg).not.toBe(rgb('#7b2d8e'));

		const other = freshEmail('other-admin');

		await site.makeUser({ email: other, password: MEMBER_PASSWORD, role: 'administrator' });

		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });

		try {
			const second = await context.newPage();

			await second.goto('/wp-login.php?diluxone-users-admin=1');
			await signInWithPassword(second, other, MEMBER_PASSWORD);
			await second.waitForURL(/wp-admin/);
			await expectSignedIn(second, other);

			const theirs = await painted(second);

			expect(theirs.mark, 'another administrator is shown no trial').toBe(0);
			expect(theirs.bg).not.toBe(rgb('#7b2d8e'));
		} finally {
			await context.close();
		}

		// A minute later the key is gone, which is what forgetting it does.
		await site.setOptions({}, { forgetTransients: true });
		expect((await painted(mine)).mark, 'past its minute, not even the maker sees it').toBe(0);
		await mine.close();
	});
});

test.describe('Design › on a narrow window', () => {
	test.use({ viewport: { width: 900, height: 900 } });

	test('the stage goes under the settings, in the flow of the page, and nothing runs past the edge', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));

		const fields = await page.locator('.diluxone-users-studio__fields').boundingBox();
		const preview = page.locator('.diluxone-users-studio__preview');

		await expect(preview).toBeVisible();

		const box = await preview.boundingBox();

		expect(box!.y, 'below the settings').toBeGreaterThanOrEqual(fields!.y + fields!.height - 1);
		expect(await preview.evaluate((element) => getComputedStyle(element).position)).toBe('static');
		expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
	});
});
