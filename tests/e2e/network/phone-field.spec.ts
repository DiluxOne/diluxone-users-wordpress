import { Locator } from '@playwright/test';
import { test, expect, whoOn } from './support';
import { freshEmail } from '../support/api';
import { accountSection, signInWithPassword } from '../support/ui';
import { formOf, reveal } from '../support/my-account';

/**
 * The phone field on a network: the hub's registration form and account area
 * draw it as the one control the single site does, with the height, border
 * and corners of the e-mail input beside it, and group and check the number
 * as it does.
 */

const FIELDS = [
	{ key: 'e2e_mail', label: 'Work mail', type: 'email', options: [], required: 1, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_phone', label: 'Phone', type: 'phone', options: ['AR'], required: 1, active: 1, group: 'main', edit: 'always' },
];

/** Height, edge and corners, as the browser draws them. */
function boxOf(locator: Locator): Promise<string> {
	return locator.first().evaluate((element) => {
		const style = getComputedStyle(element);

		return [Math.round(element.getBoundingClientRect().height), style.borderTopWidth, style.borderTopStyle, style.borderTopColor, style.borderTopLeftRadius].join(' ');
	});
}

test('on the hub’s registration form the phone is one control, drawn like the e-mail input, wide and at a phone’s width', async ({ guest, hub }) => {
	await hub.set({ diluxone_users_fields: FIELDS, diluxone_users_register_form: 1, diluxone_users_2fa_mode: 'off' });

	await guest.goto(hub.pages.register.url);

	const phone = guest.locator('.diluxone-users-register .diluxone-users-phone');
	const mail = guest.locator('.diluxone-users-register input[name="diluxone_users_email"]');

	for (const width of [1280, 390]) {
		await guest.setViewportSize({ width, height: 900 });
		await expect(phone).toBeVisible();
		expect(await boxOf(phone), `at ${width}px`).toBe(await boxOf(mail));
	}
});

test('on the hub’s registration form an area code of three digits is written apart, and a mobile typed with its 15 is not too long', async ({ guest, hub }) => {
	await hub.set({ diluxone_users_fields: FIELDS, diluxone_users_register_form: 1, diluxone_users_2fa_mode: 'off' });

	await guest.goto(hub.pages.register.url);

	const number = guest.locator('.diluxone-users-register input.diluxone-users-phone__number');

	await number.click();
	await number.pressSequentially('2214123456');
	await expect(number, '221 is La Plata’s code, not 22').toHaveValue('221 412 3456');

	await number.fill('');
	await number.pressSequentially('0111541234567');
	await expect(number, 'the trunk prefix and the 15 kept where they were typed').toHaveValue('0 11 15 4123 4567');
	expect(await number.evaluate((input: HTMLInputElement) => input.validity.valid), 'not too long for the browser').toBe(true);
});

test('in the hub’s account area a field marked as a mobile shows the 9 once the number is whole, and saves it', async ({ guest, hub }) => {
	const email = freshEmail('net-phone-lead');
	const password = 'e2e-NetPhone-1!';

	await hub.set({ diluxone_users_fields: [{ ...FIELDS[0], required: 0 }, { ...FIELDS[1], required: 0, mobile: 1 }], diluxone_users_2fa_mode: 'off' });
	await hub.site.makeUser({ email, password });
	await guest.goto(hub.pages.login.url);
	await signInWithPassword(guest, email, password);
	await guest.waitForLoadState('domcontentloaded');
	expect(await whoOn(guest, hub.url)).toBe(email);

	await guest.goto(accountSection(hub.pages.account.url, 'details'));
	await reveal(guest, 'input[name="e2e_phone"]');

	const number = formOf(guest, 'diluxone_users_fields_save').locator('input.diluxone-users-phone__number');

	await number.click();
	await number.pressSequentially('1141234567');
	await expect(number).toHaveValue('9 11 4123 4567');

	await Promise.all([guest.waitForURL(/diluxone-users=saved/), formOf(guest, 'diluxone_users_fields_save').locator('button[type="submit"]').first().click()]);
	expect((await hub.site.user(email, ['e2e_phone'])).fields.e2e_phone).toBe('+5491141234567');
});
