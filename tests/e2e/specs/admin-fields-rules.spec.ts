import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { Site } from '../support/api';
import { accountSection, adminError, adminUrl, answeringDialog, navigated } from '../support/ui';
import {
	NATIVE_FIELDS,
	StoredField,
	addField,
	editField,
	fieldForm,
	fieldsForm,
	forceValue,
	railNotice,
	saveFieldsForm,
	setUserMeta,
	signInMember,
	storedFields,
	submitField,
	unique,
} from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * User fields › what a field is allowed to do, decided on the screen.
 *
 * The rules a field carries — required, active, how often its owner may
 * change it, which block it is in, its place in the order — each set through
 * the field form or the list, and each proven where it bites: on the
 * subscriber's details, in the stored answer, and on the dashboard profile of
 * an administrator, who is the exception to every limit.
 */

test.use({ storageState: ADMIN_STATE });

const keyOf = (fields: StoredField[]) => fields.map((one) => one.key);

async function answer(site: Site, email: string, key: string): Promise<string> {
	return (await site.user(email, [key])).fields[key];
}

async function details(page: Page, accountUrl: string): Promise<void> {
	await page.goto(accountSection(accountUrl, 'details'));
	await expect(fieldsForm(page)).toBeVisible();
}

/** The administrator writes a value on somebody's dashboard profile. */
async function adminWrites(page: Page, site: Site, email: string, key: string, value: string): Promise<void> {
	const { id } = await site.user(email);

	await page.goto(`/wp-admin/user-edit.php?user_id=${id}`);

	const box = page.locator(`[name="${key}"]`);

	// Soft, so the save behind it is still proven: the box on somebody
	// else's profile has to be one an administrator can type in.
	await expect
		.soft(box, 'bug: on somebody else’s profile the administrator gets the box read-only (fields-forms.php diluxone_users_field_input asks diluxone_users_field_editable() about the viewer, get_current_user_id(), not the profile’s owner)')
		.toBeEditable({ timeout: 2_000 });
	await forceValue(page, key, value);
	await navigated(page, () => page.locator('#submit').click());
	await expect(page.locator('#message.updated, .notice-success').first()).toBeVisible();
}

test.beforeEach(async ({ options }) => {
	await options.keep(['diluxone_users_fields']);
	await options.set({ diluxone_users_fields: NATIVE_FIELDS });
});

test.describe('User fields › editing a field', () => {
	test('renamed, a field keeps its key and every answer already given', async ({ page, guest, site, pages }) => {
		const field = await addField(page, site, { label: unique('E2E Ciudad'), type: 'text' });
		const email = await signInMember(guest, site, pages.login.url, 'rename');

		await details(guest, pages.account.url);
		await guest.locator(`[name="${field.key}"]`).fill('Mendoza');
		expect(await saveFieldsForm(guest)).toBe('saved');

		const renamed = unique('E2E Localidad');

		await page.goto(adminUrl('diluxone-users-fields') + `&field=${field.key}`);
		await expect(fieldForm(page).locator('input[type="hidden"][name="diluxone_users_field[key]"]')).toHaveValue(field.key);
		await fieldForm(page).locator('[name="diluxone_users_field[label]"]').fill(renamed);
		await fieldForm(page).locator('[name="diluxone_users_field[help]"]').fill('e2e-renamed-help');
		expect(await submitField(page)).toBe('saved');

		const after = await storedFields(site);
		const same = after.filter((one) => one.key === field.key);

		expect(same, 'one field under that key, not a second one').toHaveLength(1);
		expect(same[0].label).toBe(renamed);
		expect(after.some((one) => one.label === field.label), 'the old name is gone').toBe(false);

		await details(guest, pages.account.url);

		const box = guest.locator(`[name="${field.key}"]`);

		await expect(box, 'the answer is still there under the new name').toHaveValue('Mendoza');
		await expect(fieldsForm(guest).locator(`label[for="${field.key}"]`)).toContainText(renamed);
		await expect(fieldsForm(guest).locator('.diluxone-users-field').filter({ has: box }).locator('.diluxone-users-field__help').first()).toHaveText(
			'e2e-renamed-help'
		);
		expect(await answer(site, email, field.key)).toBe('Mendoza');
	});

	test('a field nobody named is not saved, and the list says so', async ({ page, site }) => {
		const before = await storedFields(site);

		await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');
		await fieldForm(page).locator('[name="diluxone_users_field[label]"]').evaluate((box: HTMLInputElement) => box.removeAttribute('required'));
		await fieldForm(page).locator('[name="diluxone_users_field[label]"]').fill('   ');

		expect(await submitField(page)).toBe('nolabel');
		await expect(adminError(page)).toBeVisible();
		expect(await storedFields(site)).toEqual(before);
	});

	for (const reserved of ['wp_capabilities', 'diluxone_users_totp', 'diluxone_users_2fa_on', 'session_tokens']) {
		test(`a key WordPress or the plugin keeps for itself (${reserved}) cannot be given to a field`, async ({ page, site }) => {
			const before = await storedFields(site);

			await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');
			await fieldForm(page).locator('input[type="hidden"][name="diluxone_users_field[key]"]').evaluate((box: HTMLInputElement, key) => {
				box.value = key;
			}, reserved);
			await fieldForm(page).locator('[name="diluxone_users_field[label]"]').fill(unique('E2E forged'));

			expect(await submitField(page), 'refused as a field with no name').toBe('nolabel');
			await expect(adminError(page)).toBeVisible();

			const after = await storedFields(site);

			expect(keyOf(after)).not.toContain(reserved);
			expect(after).toEqual(before);
		});
	}

	test('a name whose key is taken by the plugin is given a free one instead', async ({ page, site }) => {
		// "Avatar" would be diluxone_users_avatar, where the photo is stored.
		await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');
		await fieldForm(page).locator('[name="diluxone_users_field[label]"]').fill('Avatar');
		expect(await submitField(page)).toBe('saved');

		const stored = (await storedFields(site)).find((one) => one.label === 'Avatar');

		expect(stored?.key).toBe('diluxone_users_avatar_2');
	});

	test('a field that does not exist is said so, with no form to fill', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-fields') + '&field=diluxone_users_nope_e2e');

		await expect(adminError(page)).toBeVisible();
		await expect(fieldForm(page)).toHaveCount(0);
	});
});

test.describe('User fields › required and active', () => {
	test('made required on the screen, an emptied answer is refused and kept; optional again, it can be emptied', async ({ page, guest, site, pages }) => {
		const field = await addField(page, site, { label: unique('E2E Empresa'), type: 'text' });
		const email = await signInMember(guest, site, pages.login.url, 'required');

		await details(guest, pages.account.url);
		await guest.locator(`[name="${field.key}"]`).fill('Acme');
		expect(await saveFieldsForm(guest)).toBe('saved');

		expect(await editField(page, field.key, { required: true })).toBe('saved');
		expect((await storedFields(site)).find((one) => one.key === field.key)?.required).toBe(1);

		await details(guest, pages.account.url);
		await expect(guest.locator(`[name="${field.key}"]`)).toHaveAttribute('required', '');
		await guest.locator(`[name="${field.key}"]`).fill('');
		expect(await saveFieldsForm(guest)).toBe('missing');
		expect(await answer(site, email, field.key), 'the answer it had is kept').toBe('Acme');

		expect(await editField(page, field.key, { required: false })).toBe('saved');

		await details(guest, pages.account.url);
		await guest.locator(`[name="${field.key}"]`).fill('');
		expect(await saveFieldsForm(guest)).toBe('saved');
		expect(await answer(site, email, field.key), 'emptied, the answer is gone').toBe('');
	});

	test('unticked “active”, a field leaves every form and keeps its answers; ticked again, they are back', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_register_form: 1 });

		const field = await addField(page, site, { label: unique('E2E Club'), type: 'text', required: true });
		const email = await signInMember(guest, site, pages.login.url, 'active');

		await details(guest, pages.account.url);
		await guest.locator(`[name="${field.key}"]`).fill('Boca');
		expect(await saveFieldsForm(guest)).toBe('saved');

		expect(await editField(page, field.key, { active: false })).toBe('saved');

		await page.goto(adminUrl('diluxone-users-fields'));
		const row = page.locator('table.diluxone-users-list tr').filter({ has: page.locator(`code:text-is("${field.key}")`) });
		await expect(row.locator('.diluxone-users-pill'), 'the list says it is hidden').toHaveClass(/diluxone-users-pill--off/);

		await details(guest, pages.account.url);
		await expect(guest.locator(`[name="${field.key}"]`)).toHaveCount(0);
		expect(await answer(site, email, field.key), 'the answer is kept').toBe('Boca');

		const stranger = await guest.context().browser()!.newContext({ baseURL: new URL(pages.register.url).origin, storageState: { cookies: [], origins: [] } });
		const visitor = await stranger.newPage();

		await visitor.goto(pages.register.url);
		await expect(visitor.locator('.diluxone-users-register')).toBeVisible();
		await expect(visitor.locator(`[name="${field.key}"]`), 'nor on the registration form').toHaveCount(0);
		await stranger.close();

		expect(await editField(page, field.key, { active: true })).toBe('saved');

		await details(guest, pages.account.url);
		await expect(guest.locator(`[name="${field.key}"]`)).toHaveValue('Boca');
	});
});

test.describe('User fields › who can change it', () => {
	test('“only a few times”: each change spends one, the same answer spends none, and then it closes', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');

		const max = fieldForm(page).locator('[name="diluxone_users_field[edit_max]"]');

		await fieldForm(page).locator('input[name="diluxone_users_field[edit]"][value="always"]').check({ force: true });
		await expect(max, '“how many times” belongs to the answer that limits').toBeHidden();
		await fieldForm(page).locator('input[name="diluxone_users_field[edit]"][value="limited"]').check({ force: true });
		await expect(max).toBeVisible();

		const label = unique('E2E Documento');

		await fieldForm(page).locator('[name="diluxone_users_field[label]"]').fill(label);
		await max.fill('2');
		expect(await submitField(page)).toBe('saved');

		const field = (await storedFields(site)).find((one) => one.label === label)!;

		expect(field).toMatchObject({ edit: 'limited', edit_max: 2 });

		const email = await signInMember(guest, site, pages.login.url, 'limited');
		const counter = `diluxone_users_edits_${field.key}`;
		const box = guest.locator(`[name="${field.key}"]`);
		const note = fieldsForm(guest).locator('.diluxone-users-field').filter({ has: box }).locator('.diluxone-users-field__limit');
		const spent = async () => (await site.user(email, [counter])).fields[counter];

		await details(guest, pages.account.url);
		await expect(note, 'two goes left').toContainText('2');
		await box.fill('A-1');
		expect(await saveFieldsForm(guest)).toBe('saved');
		expect(await spent()).toBe('1');

		// The same answer again is no change, and spends nothing.
		await details(guest, pages.account.url);
		await expect(note, 'one go left').toContainText('1');
		expect(await saveFieldsForm(guest)).toBe('saved');
		expect(await spent(), 'saving the same thing again spends nothing').toBe('1');

		await details(guest, pages.account.url);
		await box.fill('B-2');
		expect(await saveFieldsForm(guest)).toBe('saved');
		expect(await spent()).toBe('2');
		expect(await answer(site, email, field.key)).toBe('B-2');

		await details(guest, pages.account.url);
		await expect(box, 'closed once the goes are spent').toHaveAttribute('readonly', '');
		await expect(note).not.toContainText(/\d/);

		// Typed in anyway, the readonly taken out with the inspector.
		await forceValue(guest, field.key, 'C-3');
		await saveFieldsForm(guest);
		expect(await answer(site, email, field.key), 'what cannot be changed is not saved').toBe('B-2');

		// Whoever administers fixes it, and spends nobody's goes.
		await adminWrites(page, site, email, field.key, 'D-4');
		expect(await answer(site, email, field.key)).toBe('D-4');
		expect(await spent(), 'an administrator spends nobody’s goes').toBe('2');
	});

	test('“never”: the person sees it read-only and cannot change it; the administrator can', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_register_form: 1 });

		const field = await addField(page, site, { label: unique('E2E Socio'), type: 'text', edit: 'never', required: true });

		expect(field.edit).toBe('never');

		const email = await signInMember(guest, site, pages.login.url, 'never');

		await adminWrites(page, site, email, field.key, 'S-100');

		await details(guest, pages.account.url);

		const box = guest.locator(`[name="${field.key}"]`);

		await expect(box).toHaveValue('S-100');
		await expect(box).toHaveAttribute('readonly', '');
		await expect(fieldsForm(guest).locator('.diluxone-users-field').filter({ has: box }).locator('.diluxone-users-field__limit')).toHaveCount(1);

		await forceValue(guest, field.key, 'S-999');
		await saveFieldsForm(guest);
		expect(await answer(site, email, field.key)).toBe('S-100');

		// Required, but a person cannot fill in what only the site decides:
		// the registration form does not ask for it.
		await guest.context().clearCookies();
		await guest.goto(pages.register.url);
		await expect(guest.locator('.diluxone-users-register')).toBeVisible();
		await expect(guest.locator(`[name="${field.key}"]`)).toHaveCount(0);
	});
});

test.describe('User fields › WordPress’s own two', () => {
	test('the first name keeps its type and cannot be deleted, not even by a link built by hand', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-fields') + '&field=first_name');

		await expect(fieldForm(page).locator('#diluxone-users-type')).toBeDisabled();

		const renamed = unique('E2E Nombre');

		await fieldForm(page).locator('[name="diluxone_users_field[label]"]').fill(renamed);
		expect(await submitField(page)).toBe('saved');

		const first = (await storedFields(site)).find((one) => one.key === 'first_name');

		expect(first).toMatchObject({ label: renamed, type: 'text' });

		const row = page.locator('table.diluxone-users-list tr').filter({ has: page.locator('code:text-is("first_name")') });

		await expect(row.locator('.trash'), 'no Delete on WordPress’s own').toHaveCount(0);

		// The arrows carry the same nonce action as Delete: the link is built
		// from one of them, exactly as somebody would.
		const up = await row.locator('a[href*="diluxone_users_action=up"]').getAttribute('href');

		await page.goto(up!.replace('diluxone_users_action=up', 'diluxone_users_action=delete'));
		expect(keyOf(await storedFields(site))).toContain('first_name');
	});
});

test.describe('User fields › deleting', () => {
	test('Delete asks first; no keeps it, yes removes it — and the answers already given stay, back with the field', async ({ page, guest, site, pages, options }) => {
		const field = await addField(page, site, { label: unique('E2E Hobby'), type: 'text' });
		const email = await signInMember(guest, site, pages.login.url, 'delete-keeps');

		await details(guest, pages.account.url);
		await guest.locator(`[name="${field.key}"]`).fill('Ajedrez');
		expect(await saveFieldsForm(guest)).toBe('saved');

		await page.goto(adminUrl('diluxone-users-fields'));

		const row = page.locator('table.diluxone-users-list tr').filter({ has: page.locator(`code:text-is("${field.key}")`) });
		const remove = row.locator('.trash a');

		await row.hover();
		await answeringDialog(page, 'dismiss', () => remove.click());
		expect(keyOf(await storedFields(site)), 'no keeps it').toContain(field.key);

		await answeringDialog(page, 'accept', () => navigated(page, () => remove.click()));
		expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('delete');
		expect(keyOf(await storedFields(site))).not.toContain(field.key);

		await details(guest, pages.account.url);
		await expect(guest.locator(`[name="${field.key}"]`)).toHaveCount(0);
		expect(await answer(site, email, field.key), 'the answer is left alone').toBe('Ajedrez');

		await options.set({ diluxone_users_fields: [...NATIVE_FIELDS, field] });
		await details(guest, pages.account.url);
		await expect(guest.locator(`[name="${field.key}"]`), 'back with the field').toHaveValue('Ajedrez');
	});
});

test.describe('User fields › the order', () => {
	test('the arrows move a field, the list and the forms follow, and the ends do not move', async ({ page, guest, site, pages }) => {
		const a = await addField(page, site, { label: unique('E2E A'), type: 'text' });
		const b = await addField(page, site, { label: unique('E2E B'), type: 'text' });
		const c = await addField(page, site, { label: unique('E2E C'), type: 'text' });
		const mine = (fields: StoredField[]) => keyOf(fields).filter((key) => [a.key, b.key, c.key].includes(key));

		const press = async (key: string, way: 'up' | 'down') => {
			await page.goto(adminUrl('diluxone-users-fields'));
			await navigated(page, () => page.locator(`a[href*="diluxone_users_action=${way}"][href*="field=${key}"]`).click());
			expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe(way);
		};

		await press(a.key, 'down');
		expect(mine(await storedFields(site))).toEqual([b.key, a.key, c.key]);

		await press(c.key, 'up');
		expect(mine(await storedFields(site))).toEqual([b.key, c.key, a.key]);

		// The list draws the stored order.
		const listed = await page.locator('table.diluxone-users-list td.diluxone-users-list__name code').allTextContents();

		expect(listed.filter((key) => [a.key, b.key, c.key].includes(key))).toEqual([b.key, c.key, a.key]);

		const all = keyOf(await storedFields(site));

		await press(all[0], 'up');
		expect(keyOf(await storedFields(site)), 'the first goes no higher').toEqual(all);
		await press(all[all.length - 1], 'down');
		expect(keyOf(await storedFields(site)), 'the last goes no lower').toEqual(all);

		await signInMember(guest, site, pages.login.url, 'order');
		await details(guest, pages.account.url);

		const drawn = await fieldsForm(guest).locator('input[type="text"]').evaluateAll((all) => all.map((one) => (one as HTMLInputElement).name));

		expect(drawn.filter((name) => [a.key, b.key, c.key].includes(name)), 'the form asks in the same order').toEqual([b.key, c.key, a.key]);
	});
});

test.describe('User fields › the list itself', () => {
	test('while nobody can register, the rail says the fields are asked of nobody and links to the doors', async ({ page, options }) => {
		await options.set({
			diluxone_users_login_register: 0,
			diluxone_users_register_form: 0,
			diluxone_users_sso_register: 0,
			users_can_register: 0,
		});
		await page.goto(adminUrl('diluxone-users-fields'));

		const warning = railNotice(page, 'warning');

		await expect(warning).toHaveCount(1);
		await expect(warning.locator('a')).toHaveAttribute('href', /page=diluxone-users-login&tab=register/);

		await options.set({ diluxone_users_register_form: 1 });
		await page.goto(adminUrl('diluxone-users-fields'));
		await expect(railNotice(page, 'warning'), 'with a door open there is nothing to say').toHaveCount(0);
	});

	test('“Add field” opens the form over the list; Cancel, the close button and Escape close it; saving from it saves', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-fields'));

		const before = page.url();
		const dialog = page.locator('dialog[data-diluxone-users-dialog]');
		const add = page.locator('.diluxone-users-admin__actions a[data-diluxone-users-field-dialog]');

		await add.click();
		await expect(dialog).toHaveAttribute('open', '');
		await expect(dialog.locator('form.diluxone-users-form-admin')).toBeVisible();
		expect(page.url(), 'the list stays where it was').toBe(before);

		// Cancel, added beside the button that saves.
		await dialog.locator('p.submit [data-diluxone-users-dialog-close]').click();
		await expect(dialog).not.toHaveAttribute('open', '');

		await add.click();
		await expect(dialog.locator('form.diluxone-users-form-admin')).toBeVisible();
		await dialog.locator('.diluxone-users-dialog__close').click();
		await expect(dialog).not.toHaveAttribute('open', '');

		await add.click();
		await expect(dialog.locator('form.diluxone-users-form-admin')).toBeVisible();
		await page.keyboard.press('Escape');
		await expect(dialog).not.toHaveAttribute('open', '');

		// Edit on a row opens that field, titled with its name.
		const first = (await storedFields(site))[0];
		const edit = page.locator(`.row-actions .edit a[href*="field=${first.key}"]`);

		await page.locator('table.diluxone-users-list tr').filter({ has: page.locator(`code:text-is("${first.key}")`) }).hover();
		await edit.click();
		await expect(dialog.locator('[data-diluxone-users-dialog-heading]')).toHaveText(first.label);
		await expect(dialog.locator('[name="diluxone_users_field[label]"]')).toHaveValue(first.label);
		await page.keyboard.press('Escape');

		const label = unique('E2E from the dialog');

		await add.click();
		await dialog.locator('[name="diluxone_users_field[label]"]').fill(label);
		await dialog.locator('#diluxone-users-type').selectOption('select');
		await expect(dialog.locator('[name="diluxone_users_field[options]"]'), 'the rows per type work inside the dialog too').toBeVisible();
		await dialog.locator('[name="diluxone_users_field[options]"]').fill('Uno\nDos');
		await navigated(page, () => dialog.locator('#submit').click());

		expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('saved');
		expect((await storedFields(site)).find((one) => one.label === label)).toMatchObject({ type: 'select', options: ['Uno', 'Dos'] });
	});

	test.describe('at 400 pixels', () => {
		test.use({ viewport: { width: 400, height: 800 } });

		test('the dialog takes the whole width of the screen', async ({ page }) => {
			await page.goto(adminUrl('diluxone-users-fields'));
			await page.locator('.diluxone-users-admin__actions a[data-diluxone-users-field-dialog]').click();

			const dialog = page.locator('dialog[data-diluxone-users-dialog]');

			await expect(dialog.locator('form.diluxone-users-form-admin')).toBeVisible();

			const box = await dialog.boundingBox();

			expect(Math.round(box!.width)).toBeGreaterThanOrEqual(page.viewportSize()!.width - 2);
		});
	});
});

test.describe('User fields › suggested fields', () => {
	test('nothing ticked adds nothing and says so', async ({ page, site }) => {
		const before = await storedFields(site);

		await page.goto(adminUrl('diluxone-users-fields', 'suggested'));
		await navigated(page, () =>
			page.locator('[data-diluxone-users-save] .du-save__button').first().evaluate((button: HTMLButtonElement) => {
				HTMLFormElement.prototype.submit.call(button.form as HTMLFormElement);
			})
		);

		expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('nosuggested');
		await expect(adminError(page)).toBeVisible();
		expect(await storedFields(site)).toEqual(before);
	});

	test('every one added: the tab has nothing left to offer and the list no button for it', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-fields', 'suggested'));

		const boxes = page.locator('input[name="diluxone_users_suggested[]"]');
		const offered = await boxes.evaluateAll((all) => all.map((one) => (one as HTMLInputElement).value));

		expect(offered.length).toBeGreaterThan(0);

		for (const key of offered) {
			await page.locator(`input[name="diluxone_users_suggested[]"][value="${key}"]`).setChecked(true, { force: true });
		}

		await navigated(page, () => page.locator('[data-diluxone-users-save] .du-save__button').first().click());
		expect(new URL(page.url()).searchParams.get('diluxone_users_done')).toBe('suggested');
		expect(keyOf(await storedFields(site))).toEqual(expect.arrayContaining(offered));

		await expect(page.locator('a[href*="tab=suggested"].button'), 'nothing left to suggest').toHaveCount(0);

		await page.goto(adminUrl('diluxone-users-fields', 'suggested'));
		await expect(page.locator('input[name="diluxone_users_suggested[]"]')).toHaveCount(0);
		await expect(page.locator('.diluxone-users-not-now a[href*="page=diluxone-users-fields"]')).toHaveCount(1);
	});
});

test.describe('User fields › How to use them', () => {
	test('the two blocks the Usage tab names draw on a page: group="main" only the main one, group="extra" only the other', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		await page.goto(adminUrl('diluxone-users-fields', 'usage'));
		await expect(page.locator('.diluxone-users-shortcodes code', { hasText: 'group="main"' })).toHaveCount(1);

		const main = await addField(page, site, { label: unique('E2E main'), type: 'text', group: 'main' });
		const extra = await addField(page, site, { label: unique('E2E extra'), type: 'text', group: 'extra' });

		expect(main.group).toBe('main');
		expect(extra.group).toBe('extra');

		const top = await site.page('h4-fields-main', '[diluxone_users_fields group="main"]');
		const bottom = await site.page('h4-fields-extra', '[diluxone_users_fields group="extra"]');

		try {
			const email = await signInMember(guest, site, pages.login.url, 'groups');

			await guest.goto(top.url);
			await expect(guest.locator(`[name="${main.key}"]`)).toHaveCount(1);
			await expect(guest.locator(`[name="${extra.key}"]`)).toHaveCount(0);

			await guest.goto(bottom.url);
			await expect(guest.locator(`[name="${extra.key}"]`)).toHaveCount(1);
			await expect(guest.locator(`[name="${main.key}"]`)).toHaveCount(0);

			// And the block form saves only its own block.
			await guest.locator(`[name="${extra.key}"]`).fill('solo extra');
			expect(await saveFieldsForm(guest)).toBe('saved');
			expect(await answer(site, email, extra.key)).toBe('solo extra');
		} finally {
			await site.forgetPage('h4-fields-main');
			await site.forgetPage('h4-fields-extra');
		}
	});
});

test.describe('User fields › the dashboard profile', () => {
	test('an administrator sees and saves a field on somebody’s profile, in the plugin’s block', async ({ page, site }) => {
		const field = await addField(page, site, { label: unique('E2E Legajo'), type: 'number' });
		const email = (await site.makeUser({ email: `prof-${Date.now().toString(36)}@e2e.test` })).email;

		await adminWrites(page, site, email, field.key, '1234');
		expect(await answer(site, email, field.key)).toBe('1234');

		// WordPress's own names are drawn once, by WordPress.
		await expect(page.locator('[name="first_name"]')).toHaveCount(1);
	});
});
