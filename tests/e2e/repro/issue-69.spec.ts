import { test, expect } from '../support/fixtures';

/**
 * Issue #69: on Astra, which underlines content links and gives the content
 * column no padding, the account area a stranger sees has its title, its line
 * and its Sign in button flush against the block's left edge, and the button's
 * label is underlined.
 */

test.describe('The account area for somebody signed out, on a theme that underlines content links', () => {
	test('keeps its title, its line and its button off the block’s edge', async ({ page, pages }) => {
		await page.goto(pages.account.url);

		const guest = page.locator('.diluxone-users-account--guest');

		await expect(guest).toBeVisible();

		const block = await guest.boundingBox();

		expect(block, 'the block has a box').not.toBeNull();

		for (const inside of [guest.locator('h2'), guest.locator('p').first(), guest.locator('a.diluxone-users-button')]) {
			const box = await inside.boundingBox();

			expect(box, 'the piece has a box').not.toBeNull();
			expect(box!.x - block!.x, 'space between the block’s left edge and its content').toBeGreaterThanOrEqual(8);
		}
	});

	test('does not underline the Sign in button', async ({ page, pages }) => {
		await page.goto(pages.account.url);

		const button = page.locator('.diluxone-users-account--guest a.diluxone-users-button');

		await expect(button).toBeVisible();
		await expect(button).toHaveCSS('text-decoration-line', 'none');
	});
});
