import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail, waitForMail } from '../support/api';
import { accountSection, adminUrl, askForLink, navigated, openPanel, savePanel, signInWithPassword } from '../support/ui';
import { PLAIN_FIELDS, expectPill } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The rest of what the admin script and its front-end siblings share: the
 * field editor that opens in a dialog, the rows a field type shows, the
 * in-page question of the account area (Escape and a click outside are "no"),
 * the theme colours remembered between pages, and the two corners of
 * E-mail notices that belong to the framework — the language links with the
 * way back, and the summary that reads the rules.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-More-1!';

const TOWN = { key: 'e2e_town', label: 'E2E Town', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' };

test.describe('The field editor', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_fields: [...PLAIN_FIELDS, TOWN] });
	});

	test('opens over the list, Cancel closes it with nothing saved, and a change sent from it is in the list', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-fields'));

		const edit = page.locator(`.row-actions .edit a[href*="field=${TOWN.key}"]`);
		const dialog = page.locator('dialog[data-diluxone-users-dialog]');

		await page.locator('tr').filter({ has: edit }).hover();
		await edit.click();

		await expect(dialog).toHaveJSProperty('open', true);
		await expect(page, 'the link navigated instead of opening the dialog').toHaveURL(/page=diluxone-users-fields$|page=diluxone-users-fields&?(?!.*field=)/);

		const form = dialog.locator('form.diluxone-users-form-admin');

		await expect(form).toBeVisible();
		await expect(form.locator('#diluxone-users-label')).toBeFocused();
		await expect(form.locator('#diluxone-users-label')).toHaveValue(TOWN.label);

		await form.locator('#diluxone-users-label').fill('Changed and cancelled');
		await dialog.locator('p.submit [data-diluxone-users-dialog-close]').click();
		await expect(dialog).toHaveJSProperty('open', false);

		const stored = () => site.getOptions(['diluxone_users_fields']).then((all) => (all.diluxone_users_fields as Array<{ key: string; label: string }>).find((one) => one.key === TOWN.key)!.label);

		expect(await stored()).toBe(TOWN.label);

		await page.locator('tr').filter({ has: edit }).hover();
		await edit.click();
		await expect(form).toBeVisible();
		await form.locator('#diluxone-users-label').fill('E2E City');
		await navigated(page, () => form.locator('p.submit [type="submit"]').first().click());

		expect(await stored()).toBe('E2E City');
		await page.goto(adminUrl('diluxone-users-fields'));
		await expect(page.locator(`a[data-diluxone-users-field-dialog][href*="field=${TOWN.key}"]`).first()).toHaveText('E2E City');
	});

	test('when the form cannot be fetched, the link goes to the field’s own screen instead', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-fields'));

		let failed = false;

		await page.route('**/wp-admin/admin.php?*field=*', (route) => {
			if (!failed && route.request().resourceType() === 'fetch') {
				failed = true;
				return route.fulfill({ status: 500, body: '' });
			}

			return route.continue();
		});

		const edit = page.locator(`.row-actions .edit a[href*="field=${TOWN.key}"]`);

		await page.locator('tr').filter({ has: edit }).hover();
		await navigated(page, () => edit.click());

		expect(failed, 'the dialog never asked for the form').toBe(true);
		expect(new URL(page.url()).searchParams.get('field')).toBe(TOWN.key);
		await expect(page.locator('form.diluxone-users-form-admin #diluxone-users-label')).toHaveValue(TOWN.label);
	});

	test('a new field shows only the rows its type uses', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-fields'));
		await page.locator('a.button-primary[data-diluxone-users-field-dialog]').click();

		const form = page.locator('dialog[data-diluxone-users-dialog] form.diluxone-users-form-admin');

		await expect(form).toBeVisible();

		const options = form.locator('.diluxone-users-if-type[data-type~="select"]');
		const country = form.locator('.diluxone-users-if-type[data-type="country"]');

		await form.locator('#diluxone-users-type').selectOption('date');
		await expect(options).toBeHidden();
		await expect(country).toBeHidden();

		await form.locator('#diluxone-users-type').selectOption('select');
		await expect(options).toBeVisible();
		await expect(country).toBeHidden();

		await form.locator('#diluxone-users-type').selectOption('country');
		await expect(country).toBeVisible();
		await expect(options).toBeHidden();
	});
});

test.describe('The account area’s own question', () => {
	test('Escape and a click outside the box are both “no”: nothing is filed and nothing is mailed', async ({ guest, site, pages }) => {
		const email = freshEmail('ask-no');

		await site.makeUser({ email, password: PASSWORD });
		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, PASSWORD);
		await expectSignedIn(guest, email);

		await guest.goto(accountSection(pages.account.url, 'privacy'));

		const erase = 'form:has(input[name="diluxone_users_request"][value="erase"]) button[type="submit"]';

		await openPanel(guest, erase);

		const question = guest.locator('dialog#diluxone-users-ask-erase');

		await guest.locator(erase).click();
		await expect(question).toHaveJSProperty('open', true);
		await guest.keyboard.press('Escape');
		await expect(question).toHaveJSProperty('open', false);

		await guest.locator(erase).click();
		await expect(question).toHaveJSProperty('open', true);

		// The backdrop: a point of the window outside the box.
		const box = (await question.boundingBox())!;

		await guest.mouse.click(Math.max(2, box.x - 10), Math.max(2, box.y - 10) || 2);
		await expect(question).toHaveJSProperty('open', false);

		expect(new URL(guest.url()).searchParams.get('diluxone-users'), 'the form was sent').toBeNull();
		expect(await site.mail(email)).toEqual([]);
		await expect(guest.locator('.diluxone-users-requests tbody tr')).toHaveCount(0);
	});
});

test.describe('The theme’s colours, remembered', () => {
	test('measured once and kept in the browser; the next page paints them before any script measures again', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_styles: 1, diluxone_users_colors: 'theme', diluxone_users_color_map: [] });

		await guest.goto(pages.login.url);

		const read = () =>
			guest.evaluate(() => {
				for (let i = 0; i < localStorage.length; i++) {
					const key = localStorage.key(i)!;

					if (key.startsWith('diluxone-users-theme-colors:')) {
						return JSON.parse(localStorage.getItem(key) ?? 'null') as Record<string, string> | null;
					}
				}

				return null;
			});

		await expect.poll(read, { message: 'nothing was kept in the browser' }).not.toBeNull();

		const kept = (await read())!;
		const [role, value] = Object.entries(kept)[0];

		// The measuring script is kept away from the next page: whatever is on
		// <html> came from what the browser remembered.
		await guest.route('**/diluxone-users-theme-measure.js*', (route) => route.abort());
		await guest.goto(pages.register.url);

		expect(await guest.evaluate((name) => document.documentElement.style.getPropertyValue(`--diluxone-users-${name}`).trim(), role)).toBe(String(value).trim());
	});
});

test.describe('E-mail notices: the languages and the summary', () => {
	async function openMail(page: Page, key: string) {
		const box = page.locator(`details:has(#diluxone_users_mail_${key}_subject)`);

		if (!(await box.evaluate((element: HTMLDetailsElement) => element.open))) {
			await box.locator('> summary').click();
		}

		return box;
	}

	test('a subject rewritten in one language leaves the other alone; put back, the mail carries the plugin’s subject again', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_mail_templates']);
		await options.set({ diluxone_users_mail_templates: [] });

		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await openMail(page, 'login_link');

		const subject = page.locator('#diluxone_users_mail_login_link_subject');
		const shipped = await subject.inputValue();

		await subject.fill('E2E: tu entrada');
		await savePanel(page);
		await expect(subject).toHaveValue('E2E: tu entrada');

		// The other language keeps its own.
		const other = page.locator('a[href*="tab=templates"][href*="lang="]').first();

		await navigated(page, () => other.click());
		await openMail(page, 'login_link');
		await expect(page.locator('#diluxone_users_mail_login_link_subject')).not.toHaveValue('E2E: tu entrada');

		// The mail carries the rewrite.
		const email = freshEmail('mail-lang');

		await site.makeUser({ email });
		await askForLink(guest, pages.login.url, email);
		expect((await waitForMail(site, email)).subject).toBe('E2E: tu entrada');

		// Back in the site's language, the way back.
		await page.goto(adminUrl('diluxone-users-notices', 'templates'));

		const box = await openMail(page, 'login_link');

		await box.locator('label.du-choice').filter({ has: page.locator('input[name="diluxone_users_mail[login_link][shipped]"]') }).click();
		await savePanel(page);

		await openMail(page, 'login_link');
		await expect(page.locator('#diluxone_users_mail_login_link_subject')).toHaveValue(shipped);
		await expect(page.locator('input[name="diluxone_users_mail[login_link][shipped]"]')).toHaveCount(0);

		await site.clearMail();
		await options.set({ diluxone_users_login_throttle: 1 });
		await guest.context().clearCookies();
		await askForLink(guest, pages.login.url, email);
		// The plugin's subject, with its placeholders filled in.
		const pattern = new RegExp(`^${shipped.replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\{[a-z_]+\}/g, '.+')}$`);

		expect((await waitForMail(site, email)).subject).toMatch(pattern);
	});

	test('the summary reads each rule and the state of the mails nobody can turn off', async ({ page, options }) => {
		await options.keep(['diluxone_users_notice_rules', 'diluxone_users_mail_last']);
		await options.set({
			diluxone_users_notice_rules: { diluxone_users_notify_login: 'never', diluxone_users_notify_security: 'always' },
			diluxone_users_login_method: 'both',
			diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000), error: '' },
		});

		await page.goto(adminUrl('diluxone-users-notices', 'summary'));

		const rows = page.locator('table.diluxone-users-summary tbody tr');

		await expectPill(rows.nth(0), 'off');
		await expectPill(rows.nth(1), 'active');
		// The sign-in link: on, and the mail going out.
		await expectPill(rows.nth(2), 'active');

		// Mail failing: the link can no longer be counted on.
		await options.set({ diluxone_users_mail_last: { ok: 0, time: Math.floor(Date.now() / 1000), error: 'e2e' } });
		await page.reload();
		await expectPill(rows.nth(2), 'unknown');

		// A site with no link: the row is off.
		await options.set({ diluxone_users_login_method: 'password' });
		await page.reload();
		await expectPill(rows.nth(2), 'off');
		await expect(rows.nth(2).locator('a')).toHaveAttribute('href', /tab=ways/);
	});
});
