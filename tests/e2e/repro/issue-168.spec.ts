import { test, expect } from '../support/fixtures';

/**
 * Issue #168: in the phone field, a number written in international form
 * ("+54 9 11 4123 4567"), pasted or typed, does not pick its country. The
 * reporter expects the longest dialling code that matches to switch the
 * country and the rest to stay in the box as the national number.
 *
 * The registration form draws the field for a stranger; the field starts on
 * the first country its options name.
 */

const MAIL = { key: 'e2e_mail', label: 'Work mail', type: 'email', options: [], required: 1, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };

function phone(country: string) {
	return { key: 'e2e_phone', label: 'Phone', type: 'phone', options: [country], required: 1, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };
}

test.describe('Issue 168: a number written with + and a dialling code', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_users_register_form: 1 });
	});

	test('pasted with another country picked, it picks Argentina and leaves the national number in the box', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_fields: [MAIL, phone('UY')] });
		await guest.goto(pages.register.url);

		const box = guest.locator('.diluxone-users-register .diluxone-users-phone');
		const select = box.locator('select.diluxone-users-phone__dial');
		const number = box.locator('input.diluxone-users-phone__number');

		await expect(box).toHaveClass(/is-enhanced/);
		await expect(select, 'the field starts on Uruguay').toHaveValue('UY');

		await number.fill('+54 9 11 4123 4567');

		await expect(select, 'the +54 picked Argentina').toHaveValue('AR');
		expect((await number.inputValue()).replace(/\D/g, ''), 'the rest stays as the national number').toBe('91141234567');
	});

	test('typed with Argentina picked, +56 switches the country to Chile', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_fields: [MAIL, phone('AR')] });
		await guest.goto(pages.register.url);

		const box = guest.locator('.diluxone-users-register .diluxone-users-phone');
		const select = box.locator('select.diluxone-users-phone__dial');
		const number = box.locator('input.diluxone-users-phone__number');

		await expect(box).toHaveClass(/is-enhanced/);
		await expect(select, 'the field starts on Argentina').toHaveValue('AR');

		await number.click();
		await number.pressSequentially('+56 9 1234 5678');

		await expect(select, 'the +56 picked Chile').toHaveValue('CL');
	});
});
