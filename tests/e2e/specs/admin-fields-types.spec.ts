import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { Site } from '../support/api';
import { accountSection, adminUrl } from '../support/ui';
import {
	NATIVE_FIELDS,
	NewField,
	StoredField,
	addField,
	fieldForm,
	fieldsForm,
	forceValue,
	saveFieldsForm,
	signInMember,
	unique,
} from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * User fields › every type a field can have, added from the screen.
 *
 * Each row adds one field through the field form with the rows its type
 * needs, then a subscriber opens their details and saves it: the control the
 * page draws is the one the type promises, a good answer is stored in the
 * shape the type promises, and a bad one — sent the way a hand-made request
 * sends it, with the browser's own checks out of the way — is not stored.
 * The server is the rule; the browser is a courtesy.
 */

test.use({ storageState: ADMIN_STATE });

interface TypeRow {
	type: string;
	field?: Partial<NewField>;
	/** What the details page draws for it. */
	control: (page: Page, key: string) => Promise<void>;
	/** A good answer, put into the form, and what is then stored. */
	good: { fill: (page: Page, key: string) => Promise<void>; stored: string };
	/** A bad answer, forced in, and what is then stored. */
	bad?: { value: string; stored: string };
}

const fill = (value: string) => async (page: Page, key: string) => {
	await page.locator(`[name="${key}"]`).fill(value);
};

const ROWS: TypeRow[] = [
	{
		type: 'text',
		field: { help: 'e2e-help-text', placeholder: 'e2e-placeholder' },
		control: async (page, key) => {
			const box = page.locator(`input[type="text"][name="${key}"]`);

			await expect(box).toHaveAttribute('placeholder', 'e2e-placeholder');
			// The help text sits with its own field and nowhere else.
			await expect(fieldsForm(page).locator('.diluxone-users-field').filter({ has: box }).locator('.diluxone-users-field__help')).toHaveText(
				'e2e-help-text'
			);
		},
		good: { fill: fill('  <b>Plain</b> words  '), stored: 'Plain words' },
	},
	{
		type: 'textarea',
		control: async (page, key) => {
			await expect(page.locator(`textarea[name="${key}"]`)).toHaveCount(1);
		},
		good: { fill: fill('first line\nsecond <i>line</i>'), stored: 'first line\nsecond line' },
	},
	{
		type: 'email',
		control: async (page, key) => {
			await expect(page.locator(`input[type="email"][name="${key}"]`)).toHaveCount(1);
		},
		good: { fill: fill('ana.gomez@example.com'), stored: 'ana.gomez@example.com' },
		bad: { value: 'not an address', stored: '' },
	},
	{
		type: 'url',
		control: async (page, key) => {
			await expect(page.locator(`input[type="url"][name="${key}"]`)).toHaveCount(1);
		},
		good: { fill: fill('https://example.com/a b'), stored: 'https://example.com/a%20b' },
		bad: { value: 'javascript:alert(1)', stored: '' },
	},
	{
		type: 'number',
		control: async (page, key) => {
			await expect(page.locator(`input[type="number"][name="${key}"]`)).toHaveCount(1);
		},
		good: { fill: fill('42.50'), stored: '42.5' },
		bad: { value: '1e999', stored: '' },
	},
	{
		type: 'date',
		control: async (page, key) => {
			await expect(page.locator(`input[type="date"][name="${key}"]`)).toHaveCount(1);
		},
		good: { fill: fill('2020-12-31'), stored: '2020-12-31' },
		// The shape and not a day: February has no 30th.
		bad: { value: '2024-02-30', stored: '' },
	},
	{
		type: 'select',
		field: { values: ['Rojo', 'Verde'] },
		control: async (page, key) => {
			const values = await page.locator(`select[name="${key}"] option`).evaluateAll((all) => all.map((one) => (one as HTMLOptionElement).value));

			expect(values, 'exactly the values the list was given, after the empty choice').toEqual(['', 'Rojo', 'Verde']);
		},
		good: {
			fill: async (page, key) => {
				await page.locator(`select[name="${key}"]`).selectOption('Verde');
			},
			stored: 'Verde',
		},
		bad: { value: 'Azul', stored: '' },
	},
	{
		type: 'datalist',
		field: { values: ['Rojo', 'Verde'] },
		control: async (page, key) => {
			const input = page.locator(`input[name="${key}"]`);
			const list = await input.getAttribute('list');

			expect(list).toBeTruthy();

			const values = await page.locator(`datalist[id="${list}"] option`).evaluateAll((all) => all.map((one) => (one as HTMLOptionElement).value));

			expect(values).toEqual(['Rojo', 'Verde']);
		},
		// Suggestions, not a closed list: something else is welcome.
		good: { fill: fill('Turquesa'), stored: 'Turquesa' },
	},
	{
		type: 'country',
		field: { preferred: ['AR', 'UY'] },
		control: async (page, key) => {
			const options = page.locator(`select[name="${key}"] option`);
			const values = await options.evaluateAll((all) => all.slice(0, 4).map((one) => [(one as HTMLOptionElement).value, (one as HTMLOptionElement).disabled]));

			expect(values, 'the countries shown first, then a line that cannot be chosen').toEqual([
				['', false],
				['AR', false],
				['UY', false],
				['', true],
			]);
		},
		good: {
			fill: async (page, key) => {
				await page.locator(`select[name="${key}"]`).selectOption('UY');
			},
			stored: 'UY',
		},
		bad: { value: 'ZZ', stored: '' },
	},
	{
		type: 'phone',
		field: { dial: 'AR' },
		control: async (page, key) => {
			await expect(page.locator(`select[name="${key}_dial"]`), 'the default country comes chosen').toHaveValue('AR');
			await expect(page.locator(`input[type="tel"][name="${key}"]`)).toHaveCount(1);
		},
		good: { fill: fill('11 4444-5555'), stored: '+541144445555' },
	},
	{
		type: 'checkbox',
		control: async (page, key) => {
			await expect(page.locator(`input[type="checkbox"][name="${key}"]`)).toHaveCount(1);
		},
		good: {
			fill: async (page, key) => {
				await page.locator(`input[type="checkbox"][name="${key}"]`).check();
			},
			stored: '1',
		},
	},
];

/** The subscriber's details, opened again. */
async function details(page: Page, accountUrl: string): Promise<void> {
	await page.goto(accountSection(accountUrl, 'details'));
	await expect(fieldsForm(page)).toBeVisible();
}

async function answer(site: Site, email: string, key: string): Promise<string> {
	return (await site.user(email, [key])).fields[key];
}

test.describe('User fields › every type, added from the screen', () => {
	for (const row of ROWS) {
		test(`a “${row.type}” field: drawn as its type, a good answer kept in its shape${row.bad ? ', a bad one refused' : ''}`, async ({
			page,
			guest,
			site,
			pages,
			options,
		}) => {
			await options.keep(['diluxone_users_fields']);
			await options.set({ diluxone_users_fields: NATIVE_FIELDS });

			const label = unique(`E2E ${row.type}`);
			const field: StoredField = await addField(page, site, { label, type: row.type, ...row.field });

			expect(field.type, 'stored with the type chosen').toBe(row.type);

			if (row.field?.values) {
				expect(field.options).toEqual(row.field.values);
			}

			if (row.field?.preferred) {
				expect(field.options).toEqual(row.field.preferred);
			}

			if (row.field?.dial) {
				expect(field.options).toEqual([row.field.dial]);
			}

			const email = await signInMember(guest, site, pages.login.url, `type-${row.type}`);

			await details(guest, pages.account.url);
			await row.control(guest, field.key);

			await row.good.fill(guest, field.key);
			expect(await saveFieldsForm(guest)).toBe('saved');
			// A browser sends a text area's lines as CRLF, which is the form's
			// business; what is asserted is the words and the lines.
			expect((await answer(site, email, field.key)).replace(/\r\n/g, '\n'), 'the good answer, stored in its shape').toBe(row.good.stored);

			if (row.bad) {
				await details(guest, pages.account.url);
				await forceValue(guest, field.key, row.bad.value);
				await saveFieldsForm(guest);
				expect(await answer(site, email, field.key), `“${row.bad.value}” is not a ${row.type}`).toBe(row.bad.stored);
			}
		});
	}

	test('a phone keeps its two halves apart when it is read back, and another dial code is another number', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_fields']);
		await options.set({ diluxone_users_fields: NATIVE_FIELDS });

		const field = await addField(page, site, { label: unique('E2E phone'), type: 'phone', dial: 'AR' });
		const email = await signInMember(guest, site, pages.login.url, 'type-phone2');

		await details(guest, pages.account.url);
		await guest.locator(`select[name="${field.key}_dial"]`).selectOption('UY');
		await guest.locator(`input[name="${field.key}"]`).fill('(099) 123-456');
		expect(await saveFieldsForm(guest)).toBe('saved');
		expect(await answer(site, email, field.key), 'the dial code and the digits, nothing else').toBe('+598099123456');

		await details(guest, pages.account.url);
		await expect(guest.locator(`select[name="${field.key}_dial"]`), 'read back, the stored code is chosen again').toHaveValue('UY');
		await expect(guest.locator(`input[name="${field.key}"]`)).toHaveValue('099123456');
	});

	test('a required closed list refuses a value from outside it and keeps the answer it had', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_fields']);
		await options.set({ diluxone_users_fields: NATIVE_FIELDS });

		const field = await addField(page, site, { label: unique('E2E list'), type: 'select', values: ['Rojo', 'Verde'], required: true });
		const email = await signInMember(guest, site, pages.login.url, 'type-closed');

		await details(guest, pages.account.url);
		await guest.locator(`select[name="${field.key}"]`).selectOption('Rojo');
		expect(await saveFieldsForm(guest)).toBe('saved');

		await details(guest, pages.account.url);
		await forceValue(guest, field.key, 'Azul');
		expect(await saveFieldsForm(guest), 'off the list is no answer, and the field needs one').toBe('missing');
		await expect(fieldsForm(guest).locator('xpath=..').locator('.diluxone-users-notice--error')).toHaveCount(1);
		expect(await answer(site, email, field.key), 'the answer it had is kept').toBe('Rojo');
	});

	test('a date in another shape is no date: 31/12/2020 is refused by a required date field', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_fields']);
		await options.set({ diluxone_users_fields: NATIVE_FIELDS });

		const field = await addField(page, site, { label: unique('E2E birthday'), type: 'date', required: true });
		const email = await signInMember(guest, site, pages.login.url, 'type-date');

		await details(guest, pages.account.url);
		await forceValue(guest, field.key, '31/12/2020');
		expect(await saveFieldsForm(guest)).toBe('missing');
		expect(await answer(site, email, field.key)).toBe('');
	});

	test('a yes/no field ticked and then unticked is a no', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_fields']);
		await options.set({ diluxone_users_fields: NATIVE_FIELDS });

		const field = await addField(page, site, { label: unique('E2E newsletter'), type: 'checkbox' });
		const email = await signInMember(guest, site, pages.login.url, 'type-yesno');
		const box = guest.locator(`input[type="checkbox"][name="${field.key}"]`);

		await details(guest, pages.account.url);
		await box.check();
		expect(await saveFieldsForm(guest)).toBe('saved');
		expect(await answer(site, email, field.key)).toBe('1');

		await details(guest, pages.account.url);
		await expect(box).toBeChecked();
		await box.uncheck();
		expect(await saveFieldsForm(guest)).toBe('saved');

		// An unticked box sends nothing at all, so the save has to read "not
		// sent" as "no" for a yes/no field — or the person can never take it back.
		expect(await answer(site, email, field.key), 'bug: an unticked yes/no field is never cleared (fields.php diluxone_users_save skips a key that was not posted, and an unticked checkbox posts nothing)').toBe('');
	});
});

test.describe('User fields › the rows the form shows for each type', () => {
	const ROWS_BY_TYPE: Array<{ type: string; shown: string[] }> = [
		{ type: 'text', shown: ['placeholder'] },
		{ type: 'date', shown: [] },
		{ type: 'select', shown: ['options'] },
		{ type: 'datalist', shown: ['placeholder', 'options'] },
		{ type: 'country', shown: ['preferred'] },
		{ type: 'phone', shown: ['placeholder', 'default_country'] },
		{ type: 'checkbox', shown: [] },
	];

	test('switching the type shows the rows that type needs and hides the rest', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-fields') + '&diluxone_users_new=1');

		const form = fieldForm(page);
		const row = (name: string) => form.locator('.diluxone-users-if-type').filter({ has: page.locator(`[name^="diluxone_users_field[${name}]"]`) });

		for (const { type, shown } of ROWS_BY_TYPE) {
			await form.locator('#diluxone-users-type').selectOption(type);

			for (const name of ['placeholder', 'options', 'preferred', 'default_country']) {
				if (shown.includes(name)) {
					await expect(row(name), `${name} is shown for ${type}`).toBeVisible();
				} else {
					await expect(row(name), `${name} is hidden for ${type}`).toBeHidden();
				}
			}
		}
	});
});
