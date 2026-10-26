import { Locator } from '@playwright/test';
import { test, expect } from './support';

/**
 * The phone field on a network: the hub's registration form and account area
 * draw it as the one control the single site does, with the height, border
 * and corners of the e-mail input beside it.
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
