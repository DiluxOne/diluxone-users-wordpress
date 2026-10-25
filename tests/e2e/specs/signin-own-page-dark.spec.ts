import { test, expect } from '../support/fixtures';
import { DARK_GROUND, DARK_TEXT, DOOR_FORM, drawnIn, siteScheme, switchSite, token } from '../support/dark-mode';

/**
 * Dark mode follows › the site's own switch, on the sign-in page of its own.
 *
 * The site is the e2e mu-plugin's stand-in with a switch of its own
 * (`diluxone_e2e_switch`): what the visitor chose is kept in their browser and
 * put on <html> from an inline script on wp_head, and the theme's dark
 * colours are keyed on it. The page of its own has no theme template around
 * it, and follows the scheme the site showed, whatever the device says.
 */

test.describe('The page of its own follows the site’s switch', () => {
	test('C2: the site dark by data-theme="dark", the device light: the page of its own is drawn with the theme’s dark colours', async ({ options, guest, pages }) => {
		// The switch does not run on the page of its own: what the site showed last decides.
		await options.set(switchSite('data-theme', 'html data-theme="dark"', '', { diluxone_e2e_switch_off_door: 1 }));
		await guest.emulateMedia({ colorScheme: 'light' });
		await siteScheme(guest, pages.account.url, 'dark');

		await guest.goto('/sign-in/');
		await expect(guest.locator('body.diluxone-users-door')).toHaveCount(1);
		await expect(guest.locator('html'), 'the site’s signal, on the page of its own').toHaveAttribute('data-theme', 'dark');
		await expect.poll(() => drawnIn(guest, DOOR_FORM), { message: 'light letters on a dark ground' }).toBe('dark');
		expect(await token(guest, 'text'), 'the text measured on the site, dark').toBe(DARK_TEXT);
		expect(await token(guest, 'surface'), 'the ground measured on the site, dark').toBe(DARK_GROUND);
	});

	test('C3: the site light, the device dark: the page of its own is drawn with the theme’s light colours', async ({ options, guest, pages }) => {
		await options.set(switchSite('data-theme', 'html data-theme="dark"'));
		await guest.emulateMedia({ colorScheme: 'dark' });
		await siteScheme(guest, pages.account.url, 'light');

		await guest.goto('/sign-in/');
		await expect(guest.locator('html'), 'the site’s own switch, on the page of its own').toHaveAttribute('data-theme', 'light');
		await expect.poll(() => drawnIn(guest, DOOR_FORM), { message: 'dark letters on a light ground' }).toBe('light');
		expect(await token(guest, 'text'), 'not the dark scheme’s text').not.toBe(DARK_TEXT);
		expect(await token(guest, 'surface'), 'not the dark scheme’s ground').not.toBe(DARK_GROUND);
	});

	// data-tema is no name the measurer watched before: it hears it because it is the signal.
	for (const [name, dark, light] of [
		['data-theme', 'dark', 'light'],
		['data-tema', 'oscuro', 'claro'],
	] as const) {
		test(`C4: the visitor flips the site’s switch (${name}) on a page with the sign-in form: the form takes the new scheme’s colours without a reload`, async ({ options, guest, pages }) => {
			await options.set(switchSite(name, `html ${name}="${dark}"`, `html ${name}="${light}"`, { diluxone_users_login_template: 'plain' }));
			await guest.emulateMedia({ colorScheme: 'light' });
			await siteScheme(guest, pages.login.url, 'light');

			const form = '.diluxone-users-login';
			const text = () => guest.locator(form).first().evaluate((el) => getComputedStyle(el).color);
			const flip = (value: string) => guest.evaluate(([n, v]) => document.documentElement.setAttribute(n, v), [name, value] as const);

			await expect(guest.locator(form).first()).toBeVisible();
			await expect.poll(() => drawnIn(guest, form)).toBe('light');
			const before = await text();
			await guest.evaluate(() => ((window as unknown as { e2eStill: boolean }).e2eStill = true));

			await flip(dark);
			await expect.poll(text, { message: 'the form’s letters, in the dark scheme' }).toBe(DARK_TEXT);
			await expect.poll(() => drawnIn(guest, form)).toBe('dark');

			await flip(light);
			await expect.poll(text, { message: 'and back' }).toBe(before);
			expect(await guest.evaluate(() => (window as unknown as { e2eStill?: boolean }).e2eStill), 'the same page, never reloaded').toBe(true);
		});
	}
});

