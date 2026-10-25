import { test, expect } from '../support/fixtures';
import { emailField, linkForm, openWay } from '../support/ui';

/**
 * Issue #102: on a theme that paints every `<button>` on `:focus` like on
 * `:hover` (Astra), the plugin's button keeps the hover colour after a click.
 */

test.describe('The plugin’s buttons after a click, on a theme that styles button:focus', () => {
	test('a clicked button looks as it does at rest once the pointer has moved away', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'link' });

		await page.goto(pages.login.url);
		await openWay(page, 'email');

		const button = linkForm(page).locator('button.diluxone-users-button[type="submit"]');
		await expect(button).toBeVisible();

		// The click must leave the button on the page, as the report's does.
		await linkForm(page).evaluate((form: HTMLFormElement) => {
			form.addEventListener('submit', (event) => event.preventDefault());
		});
		await emailField(page).fill('repro-102@example.com');

		await page.mouse.move(0, 0);
		const rest = await button.evaluate((one) => {
			const style = getComputedStyle(one);

			return { background: style.backgroundColor, color: style.color };
		});

		await button.click();
		await expect(button).toBeFocused();
		await page.mouse.move(0, 0);

		await expect(button, 'background after the click').toHaveCSS('background-color', rest.background);
		await expect(button, 'text colour after the click').toHaveCSS('color', rest.color);
	});
});
