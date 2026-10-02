import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { E2E_HEADER, E2E_NS, freshEmail } from '../support/api';
import { adminUrl, savePanel, signInWithPassword } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The shared pieces the design tabs are built from, and the preview beside
 * them: a template that fills in its pieces, the way back to a default, the
 * colour box that means "a colour of its own", the media picker, the two
 * widths of the stage and its big copy, the live preview that never lets an
 * older answer win, and the trial page that only the administrator who
 * pressed it sees, only while it lives.
 *
 * Each is asked once, on the tab that has it, with its effect: what is
 * stored, what the public page draws, what the frame shows.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Design-1!';

function liveAnswer(page: Page) {
	return page.waitForResponse((response) => response.url().includes('admin-ajax.php') && (response.request().postData() ?? '').includes('action=diluxone_users_preview'));
}

test.describe('Templates and defaults', () => {
	test('pressing a template fills the pieces under it with that template’s values and redraws the preview', async ({ page, options }) => {
		await options.keep(['diluxone_users_account_template']);

		await page.goto(adminUrl('diluxone-users-design', 'account'));

		const value = (await page.locator('input[data-diluxone-users-template]:not(:checked)').first().getAttribute('value'))!;
		const other = page.locator(`input[data-diluxone-users-template][value="${value}"]`);
		const pieces = JSON.parse((await other.getAttribute('data-diluxone-users-template-pieces'))!) as Record<string, unknown>;
		const named = Object.keys(pieces).filter((piece) => piece !== 'label' && piece !== 'help');

		expect(named.length, 'the template says what its pieces are').toBeGreaterThan(0);

		const answered = liveAnswer(page);

		await other.locator('xpath=..').click();
		await expect(other.locator('xpath=..')).toHaveClass(/is-chosen/);
		await expect(page.locator('.diluxone-users-templates__one.is-chosen')).toHaveCount(1);

		let checked = 0;

		for (const piece of named) {
			const fields = page.locator(`[data-diluxone-users-piece="${piece}"]`);

			for (const field of await fields.all()) {
				const type = await field.getAttribute('type');
				const wanted = String(pieces[piece]);

				if (type === 'checkbox') {
					expect(await field.isChecked(), piece).toBe(wanted === '1');
				} else if (type === 'radio') {
					expect(await field.isChecked(), `${piece}=${await field.getAttribute('value')}`).toBe((await field.getAttribute('value')) === wanted);
				} else {
					await expect(field, piece).toHaveValue(wanted);
				}

				checked++;
			}
		}

		expect(checked, 'no piece of the form follows the template').toBeGreaterThan(0);
		expect((await (await answered).json()).success).toBe(true);
	});

	test('“back to the default” empties its field, redraws the preview, and saves the empty answer', async ({ page, site, options }) => {
		await options.keep(['diluxone_users_style_radius']);
		await options.set({ diluxone_users_style_radius: 12 });

		await page.goto(adminUrl('diluxone-users-design', 'brand'));

		const radius = page.locator('#diluxone_users_style_radius');

		await expect(radius).toHaveValue('12');

		const answered = liveAnswer(page);

		await page.locator('button[data-diluxone-users-default*="diluxone_users_style_radius"]').click();
		await expect(radius).toHaveValue('');
		expect((await (await answered).json()).success).toBe(true);
		await expect(page.locator('[data-diluxone-users-save]')).toHaveClass(/is-dirty/);

		await savePanel(page);
		expect(String((await site.getOptions(['diluxone_users_style_radius'])).diluxone_users_style_radius ?? '')).toBe('');
	});
});

test.describe('A colour of its own', () => {
	test('unticked, the picker is off and nothing is stored; ticked, the colour is stored and the account area is painted with it', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_account_ground']);
		await options.set({ diluxone_users_account_ground: '#123456' });

		await page.goto(adminUrl('diluxone-users-design', 'account'));

		const own = page.locator('input[name="diluxone_users_account_ground_own"]');
		const picker = page.locator('input[type="color"][name="diluxone_users_account_ground"]');

		await expect(own).toBeChecked();
		await expect(picker).toBeEnabled();

		await page.locator('label.du-choice').filter({ has: own }).click();
		await expect(picker).toBeDisabled();
		await savePanel(page);

		expect(String((await site.getOptions(['diluxone_users_account_ground'])).diluxone_users_account_ground ?? '')).toBe('');
		await expect(own).not.toBeChecked();
		await expect(picker).toBeDisabled();

		const email = freshEmail('ground');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto('/wp-login.php');
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);
		await guest.goto(pages.account.url);
		expect((await guest.content()).toLowerCase(), 'a colour unticked still painted').not.toContain('#2a7b4f');

		await page.locator('label.du-choice').filter({ has: own }).click();
		await expect(picker).toBeEnabled();
		await picker.fill('#2a7b4f');
		await savePanel(page);

		expect(String((await site.getOptions(['diluxone_users_account_ground'])).diluxone_users_account_ground).toLowerCase()).toBe('#2a7b4f');

		await guest.reload();
		expect((await guest.content()).toLowerCase(), 'the account area does not carry the colour').toContain('#2a7b4f');
	});
});

test.describe('The media picker', () => {
	test('a picture chosen from the library is stored by id and drawn on the sign-in page; removed, it goes', async ({ page, guest, site, pages, options, request }) => {
		await options.keep(['diluxone_users_login_image', 'diluxone_users_login_template']);
		await options.set({ diluxone_users_login_image: 0, diluxone_users_login_template: 'split' });

		const made = await request.post(`${E2E_NS}/admin-access/attachment`, { headers: E2E_HEADER });

		expect(made.ok()).toBe(true);

		const picture = (await made.json()) as { id: number; url: string };

		try {
			await page.goto(adminUrl('diluxone-users-design', 'login'));

			const box = page.locator('[data-diluxone-users-image]').filter({ has: page.locator('input[name="diluxone_users_login_image"]') });

			await box.locator('[data-diluxone-users-image-pick]').click();

			const modal = page.locator('.media-modal:visible');

			await expect(modal).toBeVisible();

			// The library, not the upload box it may open on.
			const browse = modal.locator('#menu-item-browse');

			if (await browse.isVisible()) {
				await browse.click();
			}

			await modal.locator(`li.attachment[data-id="${picture.id}"]`).click();
			await modal.locator('.media-button-select').click();

			await expect(box.locator('input[name="diluxone_users_login_image"]')).toHaveValue(String(picture.id));
			await expect(box.locator('[data-diluxone-users-image-preview]')).toBeVisible();
			await expect(box.locator('[data-diluxone-users-image-clear]')).toBeVisible();

			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_login_image'])).diluxone_users_login_image)).toBe(picture.id);

			const file = new URL(picture.url).pathname.split('/').pop()!.replace(/\.png$/, '');

			await guest.goto(pages.login.url);
			expect(await guest.content(), 'the sign-in page does not draw the picture').toContain(file);

			await box.locator('[data-diluxone-users-image-clear]').click();
			await expect(box.locator('input[name="diluxone_users_login_image"]')).toHaveValue('0');
			await expect(box.locator('[data-diluxone-users-image-preview]')).toBeHidden();
			await savePanel(page);

			expect(Number((await site.getOptions(['diluxone_users_login_image'])).diluxone_users_login_image)).toBe(0);
			await guest.reload();
			expect(await guest.content()).not.toContain(file);
		} finally {
			await request.delete(`${E2E_NS}/admin-access/attachment?id=${picture.id}`, { headers: E2E_HEADER });
		}
	});
});

test.describe('The stage', () => {
	test('the phone and desktop widths, and the big copy that opens and closes', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-design', 'brand'));

		const stage = page.locator('[data-diluxone-users-stage]');
		const frame = stage.locator('[data-diluxone-users-stage-frame]');

		await expect(frame).toHaveCSS('width', '1200px');

		await stage.locator('[data-diluxone-users-device="390"]').click();
		await expect(stage).toHaveClass(/is-phone/);
		await expect(stage.locator('[data-diluxone-users-device="390"]')).toHaveClass(/is-on/);
		await expect.poll(() => frame.evaluate((element: HTMLElement) => element.style.width)).toBe('390px');

		await stage.locator('[data-diluxone-users-device="1200"]').click();
		await expect(stage).not.toHaveClass(/is-phone/);
		await expect.poll(() => frame.evaluate((element: HTMLElement) => element.style.width)).toBe('1200px');

		const zoom = page.locator('dialog[data-diluxone-users-zoom-box]');

		await stage.locator('[data-diluxone-users-zoom]').click();
		await expect(zoom).toHaveJSProperty('open', true);

		const [small, big] = await page.evaluate(() => {
			const one = document.querySelector<HTMLIFrameElement>('[data-diluxone-users-stage-frame]')!;
			const two = document.querySelector<HTMLIFrameElement>('[data-diluxone-users-zoom-frame]')!;

			return [one.getAttribute('src') || one.srcdoc, two.getAttribute('src') || two.srcdoc];
		});

		expect(big, 'the big copy is a copy of the stage').toBe(small);

		await zoom.locator('[data-diluxone-users-zoom-close]').click();
		await expect(zoom).toHaveJSProperty('open', false);
	});

	/*
	 * Two answers in flight: the first is held back by the browser, the
	 * second lands at once, then the first arrives late. The frame keeps the
	 * second.
	 */
	test('an older live answer that arrives late does not draw over a newer one', async ({ page, options }) => {
		await options.set({ diluxone_users_sso_button_text: '', diluxone_e2e_sso: 1, diluxone_users_sso: { mock: { active: 1, id: 'a', secret: 'b', tested: 1 } } });

		await page.goto(adminUrl('diluxone-users-design', 'social'));

		const stage = page.frameLocator('.diluxone-users-studio__preview iframe.diluxone-users-stage__frame');

		await expect(stage.locator('a.diluxone-users-social').first()).toBeVisible();

		let held: (() => void) | null = null;
		let first = true;

		await page.route('**/admin-ajax.php', async (route) => {
			if (!(route.request().postData() ?? '').includes('action=diluxone_users_preview')) {
				return route.continue();
			}

			if (first) {
				first = false;

				const response = await route.fetch();

				await new Promise<void>((resolve) => (held = resolve));

				return route.fulfill({ response });
			}

			return route.continue();
		});

		const field = page.locator('input[name="diluxone_users_sso_button_text"]');

		await field.fill('Oldest %s');
		await expect.poll(() => held !== null, { message: 'the first preview request never left' }).toBe(true);

		await field.fill('Newest %s');
		await expect(stage.locator('a.diluxone-users-social').first()).toContainText('Newest', { timeout: 15_000 });

		held!();
		// Give the late answer every chance to land, then ask again: it is
		// the absence of an effect being proved, so a poll that waits for it
		// to stay away is the only honest wait.
		await expect
			.poll(async () => stage.locator('a.diluxone-users-social').first().innerText(), { intervals: [500, 500, 500, 500] })
			.toContain('Newest');
		await expect(stage.locator('a.diluxone-users-social').first()).not.toContainText('Oldest');

		await page.unroute('**/admin-ajax.php');
	});
});

test.describe('The trial page', () => {
	/** Presses "show me what I chose" on Design › wp-login.php and returns the trial address. */
	async function trial(page: Page): Promise<string> {
		await page.goto(adminUrl('diluxone-users-design', 'wp'));
		await page.locator('input[name="diluxone_users_wp_login_brand"]').check();
		await page.locator('input[name="diluxone_users_wp_login_bg"]').fill('#7b2d8e');

		const [answer] = await Promise.all([
			page.waitForResponse((response) => response.url().includes('diluxone-users-try=')),
			page.locator('button[data-diluxone-users-try]').click(),
		]);

		return answer.url();
	}

	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);
		await options.set({ diluxone_users_wp_login_brand: 0, diluxone_users_wp_login_bg: '' });
	});

	test('is what the presser chose, for the presser alone: a stranger and another administrator with the address see what is saved', async ({ page, guest, site, browser, baseURL }) => {
		const url = await trial(page);

		// The presser, in a tab of their own, sees the trial.
		const mine = await page.context().newPage();

		await mine.goto(url);
		await expect(mine.locator('[data-diluxone-users-trial]')).toHaveCount(1);
		await mine.close();

		// A stranger with the same address does not.
		await guest.goto(url);
		await expect(guest.locator('[data-diluxone-users-trial]')).toHaveCount(0);
		expect(await guest.evaluate(() => getComputedStyle(document.body).backgroundColor)).not.toBe('rgb(123, 45, 142)');

		// Nor does another administrator.
		const email = freshEmail('other-admin');

		await site.makeUser({ email, password: PASSWORD, role: 'administrator' });

		const other = await (await browser.newContext({ baseURL, storageState: undefined })).newPage();

		await other.goto('/wp-login.php?diluxone-users-admin=1');
		await signInWithPassword(other, email, PASSWORD);
		await other.waitForURL(/wp-admin|diluxone/);
		await other.goto(url);
		await expect(other.locator('[data-diluxone-users-trial]'), 'another administrator saw somebody else’s trial').toHaveCount(0);
		await other.context().close();
	});

	test('lives as long as its key: once the key is gone, the presser sees what is saved too', async ({ page, site }) => {
		const url = await trial(page);

		// The key is a transient good for a minute; gone is what expired is.
		await site.setOptions({}, { forgetTransients: true });

		const mine = await page.context().newPage();

		await mine.goto(url);
		await expect(mine.locator('[data-diluxone-users-trial]')).toHaveCount(0);
		await mine.close();
	});
});
