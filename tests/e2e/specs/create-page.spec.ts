import { test, expect } from '../support/fixtures';
import { adminUrl } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * “Create the page”: the button a screen shows beside a page the plugin needs
 * and the site has not chosen.
 *
 * It is a link to admin-post.php with a nonce, and it does three things a
 * person can see: the page exists and draws the plugin's form, the setting
 * points at it, and the screen they came from says so. A link that carries
 * someone else's nonce — or none — must make nothing.
 */

test.use({ storageState: ADMIN_STATE });

test('the registration page is made, chosen and announced, and draws the form to a stranger', async ({
	page,
	guest,
	site,
	options,
}) => {
	// The page lives inside the box that turns the site's own form on.
	await options.set({ diluxone_users_register_page: 0, diluxone_users_register_form: 1 });

	await page.goto(adminUrl('diluxone-users-login', 'register'));

	const create = page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_register_page"]');

	await expect(create).toBeVisible();
	await expect(create).toHaveAttribute('href', /_wpnonce=/);

	await Promise.all([page.waitForURL(/diluxone_users_created=\d+/), create.click()]);

	const id = Number(new URL(page.url()).searchParams.get('diluxone_users_created'));

	try {
		expect(new URL(page.url()).searchParams.get('page'), 'back on the screen the button was on').toBe('diluxone-users-login');
		expect(Number((await site.getOptions(['diluxone_users_register_page'])).diluxone_users_register_page)).toBe(id);
		await expect(page.locator('.notice-success a').filter({ hasText: /\S/ })).toBeVisible();

		// The button has done its job: with a page chosen it is not offered again.
		await expect(create).toHaveCount(0);

		// The new page, as a stranger sees it, is the registration form.
		await guest.goto(`/?page_id=${id}`);
		await expect(guest.locator('form input[name="action"][value="diluxone_users_signup"]')).toBeAttached();
	} finally {
		await site.forgetPageId(id);
	}
});

test('a link without the nonce makes nothing', async ({ page, site, options }) => {
	await options.set({ diluxone_users_register_page: 0 });

	const answer = await page.goto('/wp-admin/admin-post.php?action=diluxone_users_create_page&page=diluxone_users_register_page');

	expect(answer?.status()).toBe(403);
	expect(Number((await site.getOptions(['diluxone_users_register_page'])).diluxone_users_register_page)).toBe(0);
});
