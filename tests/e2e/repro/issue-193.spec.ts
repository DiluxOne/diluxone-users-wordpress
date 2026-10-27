import { test, expect } from '../support/fixtures';

/**
 * Issue #193: in the phone field, a number written with the international
 * prefix 00 ("0054 9 11 4123 4567") is not read like one written with "+":
 * the country does not change. And Chile's mobiles are grouped "961 234 567"
 * where Chile writes "9 6123 4567".
 *
 * The registration form draws the field for a stranger; the field starts on
 * the first country its options name.
 */

const MAIL = { key: 'e2e_mail', label: 'Work mail', type: 'email', options: [], required: 1, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };

function phone(country: string) {
	return { key: 'e2e_phone', label: 'Phone', type: 'phone', options: [country], required: 1, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };
}

test.describe('Issue 193: a phone number written with 00, and Chile\'s grouping', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_users_register_form: 1 });
	});

	test('a number pasted with 00 and a dialling code picks Argentina like the + does', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_fields: [MAIL, phone('UY')] });
		await guest.goto(pages.register.url);

		const box = guest.locator('.diluxone-users-register .diluxone-users-phone');
		const select = box.locator('select.diluxone-users-phone__dial');
		const number = box.locator('input.diluxone-users-phone__number');

		await expect(box).toHaveClass(/is-enhanced/);
		await expect(select, 'the field starts on Uruguay').toHaveValue('UY');

		await number.fill('0054 9 11 4123 4567');

		await expect(select, 'the 0054 picked Argentina').toHaveValue('AR');
		expect((await number.inputValue()).replace(/\D/g, ''), 'the rest stays as the national number').toBe('91141234567');
	});

	test('a Chilean mobile is drawn 9 6123 4567', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_fields: [MAIL, phone('CL')] });
		await guest.goto(pages.register.url);

		const box = guest.locator('.diluxone-users-register .diluxone-users-phone');
		const select = box.locator('select.diluxone-users-phone__dial');
		const number = box.locator('input.diluxone-users-phone__number');

		await expect(box).toHaveClass(/is-enhanced/);
		await expect(select, 'the field starts on Chile').toHaveValue('CL');

		await number.fill('961234567');

		await expect(number, 'grouped as Chile writes a mobile').toHaveValue('9 6123 4567');
	});
});
