import { test, expect } from '../support/fixtures';

/**
 * Issue 69: the account area a stranger sees, on a theme that underlines
 * content links and gives its content column no padding (Astra).
 */

test.describe('The account area shown to somebody signed out', () => {
	test.beforeEach(async ({ page, pages }) => {
		await page.goto(pages.account.url);
		await expect(page.locator('.diluxone-users-account--guest')).toBeVisible();
	});

	test('keeps its title, its line and its button away from the edge of the block', async ({ page }) => {
		const block = page.locator('.diluxone-users-account--guest');
		const edge = (await block.boundingBox())!.x;

		for (const inner of [block.locator('h2'), block.locator('p').first(), block.locator('a.diluxone-users-button')]) {
			const box = (await inner.boundingBox())!;

			expect(box.x - edge, 'space between the block’s left edge and its content').toBeGreaterThanOrEqual(8);
		}
	});

	test('draws the Sign in button without an underline', async ({ page }) => {
		const button = page.locator('.diluxone-users-account--guest a.diluxone-users-button');

		await expect(button).toHaveCSS('text-decoration-line', 'none');
	});
});
