import { test, expect } from './support';
import { DARK_GROUND, DARK_TEXT, DOOR_FORM, drawnIn, siteScheme, switchSite, token } from '../support/dark-mode';

/**
 * Dark mode follows › the site's own switch, on the hub's sign-in page of its
 * own: the same claim as the single site's suite, on the one site of the
 * network that has the page. The site is the e2e mu-plugin's stand-in with a
 * switch of its own (`diluxone_e2e_switch`).
 */

test.describe('The hub’s page of its own follows the site’s switch', () => {
	test('C2: the hub dark by data-theme="dark", the device light: its page of its own is drawn with the theme’s dark colours', async ({ hub, guest }) => {
		await hub.set(switchSite('data-theme', 'html data-theme="dark"', '', { diluxone_e2e_switch_off_door: 1 }));
		await guest.emulateMedia({ colorScheme: 'light' });
		await siteScheme(guest, hub.pages.account.url, 'dark');

		await guest.goto(hub.path('sign-in/'));
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(guest.locator('html'), 'the site’s signal, on the page of its own').toHaveAttribute('data-theme', 'dark');
		await expect.poll(() => drawnIn(guest, DOOR_FORM), { message: 'light letters on a dark ground' }).toBe('dark');
		expect(await token(guest, 'text'), 'the text measured on the hub, dark').toBe(DARK_TEXT);
		expect(await token(guest, 'surface'), 'the ground measured on the hub, dark').toBe(DARK_GROUND);
	});
});
