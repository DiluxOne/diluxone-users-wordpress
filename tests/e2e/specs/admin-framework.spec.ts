import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminSaved, adminUrl, answeringDialog, fillCredentials, navigated, saveButton, savePanel } from '../support/ui';
import { adminTabs } from '../support/screens';
import { MOCK_SSO, accessTab, control } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * What every screen of the dashboard shares: the titles, the tabs, the box
 * that saves, what hangs off an answer, and the small behaviours the admin
 * script wires once for the whole page. Each is asked once, on a
 * representative tab, with what it does to the page or to what is stored.
 */

test.use({ storageState: ADMIN_STATE });

/** A field of Security › Sessions to make the box dirty with. */
async function dirty(page: Page): Promise<string> {
	const number = page.locator('.diluxone-users-admin input[type="number"]').first();
	const before = await number.inputValue();

	await number.fill(String(Number(before || '0') + 3));

	return before;
}

test.describe('Titles and tabs', () => {
	test('the heading and the browser tab say the plugin and the screen', async ({ page }) => {
		await page.goto(accessTab('summary'));

		const plugin = (await page.locator('#toplevel_page_diluxone-users .wp-menu-name').innerText()).trim();
		const screen = (await page.locator('#adminmenu a[href="admin.php?page=diluxone-users-login"]').innerText()).trim();
		const heading = (await page.locator('.wrap.diluxone-users-admin > h1').innerText()).trim();

		expect(heading.startsWith(plugin), `“${heading}” starts with the plugin`).toBe(true);
		expect(heading.endsWith(screen), `“${heading}” ends with the screen`).toBe(true);
		expect(await page.title()).toContain(heading);
	});

	test('an unknown tab in the address opens the first one', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-login', 'nope'));

		await expect(page.locator('.nav-tab-wrapper .nav-tab').first()).toHaveClass(/nav-tab-active/);
		await expect(page.locator('.nav-tab-active')).toHaveCount(1);
		await expect(page.locator('table.diluxone-users-summary'), 'the first tab’s content').toBeVisible();
	});

	test.describe('on a phone', () => {
		test.use({ viewport: { width: 400, height: 800 } });

		test('the open tab is scrolled into the one-row strip', async ({ page }) => {
			await page.goto(accessTab('messages'));

			const strip = await page.locator('.nav-tab-wrapper').boundingBox();
			const open = await page.locator('.nav-tab-wrapper .nav-tab-active').boundingBox();

			expect(open!.x).toBeGreaterThanOrEqual(strip!.x - 1);
			expect(open!.x + open!.width, 'the open tab is past the edge of the strip').toBeLessThanOrEqual(strip!.x + strip!.width + 1);
		});
	});
});

test.describe('The box that saves', () => {
	test('is in the rail of every tab that saves, and its button reaches a form on the page', async ({ page }) => {
		test.setTimeout(300_000);

		for (const { name, url } of adminTabs()) {
			await page.goto(url);

			const found = await page.evaluate(() =>
				Array.from(document.querySelectorAll('[data-diluxone-users-save]')).map((box) => ({
					inRail: !!box.closest('.diluxone-users-studio__aside, .diluxone-users-studio__preview'),
					buttons: Array.from(box.querySelectorAll<HTMLButtonElement>('.du-save__button')).map((button) => button.form instanceof HTMLFormElement),
				}))
			);

			for (const box of found) {
				expect(box.inRail, `${name}: the box is outside the rail`).toBe(true);
				expect(box.buttons.length, `${name}: a box with no button`).toBeGreaterThan(0);
				expect(box.buttons.every(Boolean), `${name}: a button with no form`).toBe(true);
			}
		}
	});

	test('its line says whether anything changed, and says it again after a change back', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-security', 'sessions'));

		const box = page.locator('[data-diluxone-users-save]');

		await expect(box.locator('.du-save__state'), 'the script unhides the line').toBeVisible();
		await expect(box.locator('.du-save__clean')).toBeVisible();
		await expect(box.locator('.du-save__dirty')).toBeHidden();
		await expect(box.locator('.du-save__undo')).toBeHidden();

		const before = await dirty(page);

		await expect(box.locator('.du-save__clean')).toBeHidden();
		await expect(box.locator('.du-save__dirty')).toBeVisible();
		await expect(box.locator('.du-save__undo')).toBeVisible();

		await page.locator('.diluxone-users-admin input[type="number"]').first().fill(before);
		await expect(box.locator('.du-save__clean')).toBeVisible();
	});

	test('leaving through a link with something unsaved, and saying yes, leaves once and asks once', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-security', 'sessions'));
		await dirty(page);

		let asked = 0;

		page.on('dialog', (dialog) => {
			asked++;
			void dialog.accept();
		});

		await navigated(page, () => page.locator('.nav-tab-wrapper a.nav-tab').first().click());

		expect(new URL(page.url()).searchParams.get('tab')).toBe('summary');
		expect(asked).toBe(1);
	});

	test('closing the window with something unsaved asks the browser’s question; after a save nothing asks', async ({ page, options }) => {
		await options.keep(['diluxone_users_session_short_days', 'diluxone_users_session_long_days', 'diluxone_users_session_idle']);

		await page.goto(adminUrl('diluxone-users-security', 'sessions'));
		await dirty(page);

		const asked = new Promise<string>((resolve) => page.once('dialog', (dialog) => {
			resolve(dialog.type());
			void dialog.dismiss();
		}));

		await page.close({ runBeforeUnload: true });
		expect(await asked).toBe('beforeunload');
		expect(page.isClosed(), 'the window closed over unsaved changes').toBe(false);

		await savePanel(page);

		let again = false;

		page.on('dialog', (dialog) => {
			again = true;
			void dialog.accept();
		});
		await page.close({ runBeforeUnload: true });
		await expect.poll(() => page.isClosed()).toBe(true);
		expect(again, 'asked again after the save').toBe(false);
	});

	/*
	 * A tab with two forms sends one of them; what was changed in the other
	 * is left behind like any walk away, and is asked about.
	 */
	test('sending one form of a tab while the other has changes asks first, and no stays', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-account', 'sections') + '&section=privacy');

		const buttons = page.locator('[data-diluxone-users-save] .du-save__button');

		await expect(buttons).toHaveCount(2);

		// Change what the first button saves, press the second.
		const first = (await buttons.nth(0).getAttribute('form'))!;
		const box = page.locator(`#${first} label.du-choice`).filter({ has: page.locator('input[type="checkbox"]') }).first();

		await box.click();
		await expect(page.locator('[data-diluxone-users-save]')).toHaveClass(/is-dirty/);

		const url = page.url();

		await answeringDialog(page, 'dismiss', () => buttons.nth(1).click());
		await expect(page).toHaveURL(url);
		await expect(adminSaved(page)).toHaveCount(0);
	});

	test('a press that sends the form into the preview is not leaving: the warning is still armed after it', async ({ page, options }) => {
		await options.keep(['diluxone_users_wp_login_brand', 'diluxone_users_wp_login_bg']);
		await options.set({ diluxone_users_wp_login_brand: 0 });

		await page.goto(adminUrl('diluxone-users-design', 'wp'));
		await page.locator('input[name="diluxone_users_wp_login_brand"]').check();

		await Promise.all([
			page.waitForResponse((answer) => answer.url().includes('diluxone-users-try')),
			page.locator('button[data-diluxone-users-try]').click(),
		]);

		await answeringDialog(page, 'dismiss', () => page.locator('.nav-tab-wrapper a.nav-tab').first().click());
		await expect(page).toHaveURL(/tab=wp/);
	});
});

test.describe('What hangs off an answer', () => {
	test('the children of an option show only while it is chosen', async ({ page, options }) => {
		await options.set({ diluxone_users_login_register: 1 });

		await page.goto(accessTab('register'));

		const group = page.locator('.du-choice-group').filter({ has: control(page, 'diluxone_users_register_open', 'open') });
		const children = group.locator('> .du-choice__children');

		await expect(group).toHaveClass(/is-open/);
		await expect(children).toBeVisible();

		await control(page, 'diluxone_users_register_open', 'closed').check();
		await expect(group).not.toHaveClass(/is-open/);
		await expect(children).toBeHidden();

		await control(page, 'diluxone_users_register_open', 'open').check();
		await expect(children).toBeVisible();
	});

	test('“only some roles” shows the roles, “everybody” hides them, and the roles chosen are kept either way', async ({ page, site, options }) => {
		await options.keep(['diluxone_users_2fa_scope', 'diluxone_users_2fa_roles']);
		await options.set({ diluxone_users_2fa_scope: 'all', diluxone_users_2fa_roles: [] });

		await page.goto(adminUrl('diluxone-users-security', '2fa'));

		const picker = page.locator('fieldset[data-diluxone-users-scope]').filter({ has: page.locator('[name="diluxone_users_2fa_scope"]') });
		const roles = picker.locator('[data-diluxone-users-scope-roles]');

		await expect(roles).toBeHidden();

		await picker.locator('[name="diluxone_users_2fa_scope"][value="some"]').check({ force: true });
		await expect(roles).toBeVisible();

		await roles.locator('input[name="diluxone_users_2fa_roles[]"][value="subscriber"]').check();
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_2fa_scope', 'diluxone_users_2fa_roles'])).toEqual({
			diluxone_users_2fa_scope: 'some',
			diluxone_users_2fa_roles: ['subscriber'],
		});
		await expect(roles).toBeVisible();
		await expect(roles.locator('input[value="subscriber"]')).toBeChecked();

		await picker.locator('[name="diluxone_users_2fa_scope"][value="all"]').check({ force: true });
		await expect(roles).toBeHidden();
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_2fa_scope', 'diluxone_users_2fa_roles'])).toEqual({
			diluxone_users_2fa_scope: 'all',
			diluxone_users_2fa_roles: ['subscriber'],
		});
	});
});

test.describe('The small behaviours', () => {
	test('a box given to copy selects all of itself on a click', async ({ page, options }) => {
		await options.set(MOCK_SSO);

		await page.goto(adminUrl('diluxone-users-social') + '&provider=mock');

		const box = page.locator('.du-field input[readonly]').first();

		await box.click();

		const [start, end, length] = await box.evaluate((input: HTMLInputElement) => [input.selectionStart, input.selectionEnd, input.value.length]);

		expect(length).toBeGreaterThan(0);
		expect([start, end]).toEqual([0, length]);
	});

	test('the rows-per-page drop-down applies itself the moment it changes', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-reports', 'activity'));

		const per = page.locator('select[name="per"]');
		const other = (await per.inputValue()) === '10' ? '20' : '10';

		await navigated(page, () => per.selectOption(other));

		expect(new URL(page.url()).searchParams.get('per')).toBe(other);
		await expect(page.locator('select[name="per"]')).toHaveValue(other);
		expect(await page.locator('table.diluxone-users-list tbody tr').count()).toBeLessThanOrEqual(Number(other));
	});

	test('the live test opens in a window of its own, and the screen stays where it was', async ({ page, context, options }) => {
		await options.set({ diluxone_e2e_sso: 1, diluxone_users_sso: { mock: { active: 0, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 0 } } });

		await page.goto(adminUrl('diluxone-users-social') + '&provider=mock');

		const url = page.url();
		const [popup] = await Promise.all([context.waitForEvent('page'), page.locator('[data-diluxone-users-popup]').first().click()]);

		await popup.waitForLoadState('domcontentloaded');
		expect(page.url()).toBe(url);
		expect(popup.url()).not.toBe(url);
		await popup.close();
	});

	test('the safe-mode notice is for whoever manages the site, not for an editor', async ({ guest, site, options }) => {
		await options.set({ diluxone_e2e_safe_mode: 1 });

		const email = freshEmail('safe-editor');
		const password = 'e2e-Safe-Editor-1!';

		await site.makeUser({ email, password, role: 'editor' });

		// Safe mode: wp-login.php is the way in.
		await guest.goto('/wp-login.php');
		await fillCredentials(guest, email, password);
		await navigated(guest, () => guest.locator('#wp-submit').click());
		await expectSignedIn(guest, email);

		await guest.goto('/wp-admin/');
		await expect(guest.locator('#wpadminbar')).toBeVisible();
		await expect(guest.locator('[data-diluxone-users-safe-mode]')).toHaveCount(0);
	});
});

test.describe('A save the server refuses', () => {
	test('keeps the stored answer on screen and shows only the error', async ({ page, site, options }) => {
		await options.set({ diluxone_users_login_method: 'both' });

		await page.goto(accessTab('ways'));
		await page.locator('input[name="diluxone_users_login_method[]"]').evaluateAll((boxes: HTMLInputElement[]) => boxes.forEach((box) => (box.checked = false)));
		await navigated(page, () => saveButton(page).evaluate((button: HTMLButtonElement) => HTMLFormElement.prototype.submit.call(button.form)));

		await expect(page.locator('.notice-error')).toHaveCount(1);
		await expect(adminSaved(page)).toHaveCount(0);
		await expect(page.locator('[data-diluxone-users-save]')).not.toHaveClass(/is-dirty/);
		expect((await site.getOptions(['diluxone_users_login_method'])).diluxone_users_login_method).toBe('both');
	});
});
