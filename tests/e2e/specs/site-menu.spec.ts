import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, savePanel, signInWithPassword } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The person in the site's own menu.
 *
 * The plugin adds its items through `wp_nav_menu_objects`, which only a
 * classic menu runs, and the theme wp-env ships has none. The e2e mu-plugin
 * registers a location of its own and draws it from a shortcode on a page
 * (`POST /menu`): the same `wp_nav_menu()` call a theme's header makes, so the
 * filter sees what it would see in a real one.
 */

const PASSWORD = 'e2e-Menu-1!';

/** One of the plugin's items, by the class it gives each kind. */
const item = (page: import('@playwright/test').Page, kind: string) =>
	page.locator(`nav.diluxone-e2e-menu li.diluxone-users-menu--${kind}`);

test.describe('The person in the site menu', () => {
	let menuUrl = '';

	test.beforeEach(async ({ site, options }) => {
		// Made once and reused, so asking again is cheap.
		menuUrl = (await site.menu()).url;
		await options.set({ diluxone_users_menu_location: 'diluxone-e2e', diluxone_users_menu_style: 'avatar-name' });
	});

	test('a stranger is offered the way in, and it leads to the sign-in page', async ({ page, pages }) => {
		await page.goto(menuUrl);

		await expect(item(page, 'sign-in')).toHaveCount(1);
		await expect(item(page, 'sign-in').locator('a')).toHaveAttribute('href', pages.login.url);
		await expect(item(page, 'person')).toHaveCount(0);
	});

	test('somebody signed in is in it, with the account’s sections and the way out under their name', async ({
		page,
		site,
		pages,
	}) => {
		const email = freshEmail('menu');

		await site.makeUser({ email, password: PASSWORD, name: 'Menu Person' });
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.goto(menuUrl);

		const person = item(page, 'person');

		await expect(person).toHaveCount(1);
		await expect(item(page, 'sign-in')).toHaveCount(0);
		await expect(person.locator('img.diluxone-users-menu__avatar')).toHaveCount(1);
		await expect(person.locator('.diluxone-users-menu__name')).toContainText('Menu Person');

		// Under the name: the sections of the account, then the way out. No
		// dashboard for somebody who cannot edit anything.
		await expect(person.locator(`a[href^="${pages.account.url}"]`).first()).toBeAttached();
		await expect(item(page, 'sign-out')).toHaveCount(1);
		await expect(item(page, 'dashboard')).toHaveCount(0);

		// The way out works.
		await page.goto((await item(page, 'sign-out').locator('a').getAttribute('href'))!);
		expect(await page.request.get('/wp-admin/profile.php').then((r) => new URL(r.url()).pathname)).toContain(
			'wp-login.php'
		);
	});

	test('“name only” draws no photo, and “photo only” keeps the name for screen readers', async ({
		page,
		site,
		pages,
		options,
	}) => {
		const email = freshEmail('menu-style');

		await site.makeUser({ email, password: PASSWORD, name: 'Styled Person' });
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await options.set({ diluxone_users_menu_style: 'name' });
		await page.goto(menuUrl);
		await expect(item(page, 'person').locator('img.diluxone-users-menu__avatar')).toHaveCount(0);
		await expect(item(page, 'person').locator('> a')).toContainText('Styled Person');

		await options.set({ diluxone_users_menu_style: 'avatar' });
		await page.goto(menuUrl);
		await expect(item(page, 'person').locator('img.diluxone-users-menu__avatar')).toHaveCount(1);
		await expect(item(page, 'person').locator('.screen-reader-text')).toContainText('Styled Person');
	});

	test('with no location chosen, the menu is the site’s own and nothing more', async ({ page, options }) => {
		await options.set({ diluxone_users_menu_location: '' });
		await page.goto(menuUrl);

		await expect(page.locator('nav.diluxone-e2e-menu li')).toHaveCount(1);
		await expect(page.locator('nav.diluxone-e2e-menu li.diluxone-users-menu')).toHaveCount(0);
	});
});

test.describe('Account area › In the site menu', () => {
	test.use({ storageState: ADMIN_STATE });

	test('the location and the style saved on the screen are what the menu draws', async ({ page, guest, site, options }) => {
		await options.keep(['diluxone_users_menu_location', 'diluxone_users_menu_style']);
		await options.set({ diluxone_users_menu_location: '' });

		const { url } = await site.menu();

		await guest.goto(url);
		await expect(item(guest, 'sign-in')).toHaveCount(0);

		// The screen offers the locations the theme registered — here, the
		// one the mu-plugin did.
		await page.goto(adminUrl('diluxone-users-account', 'menu'));
		await page.locator('select[name="diluxone_users_menu_location"]').selectOption('diluxone-e2e');
		await page.locator('select[name="diluxone_users_menu_style"]').selectOption('name');
		await savePanel(page);

		await guest.goto(url);
		await expect(item(guest, 'sign-in')).toHaveCount(1);

		expect(await site.getOptions(['diluxone_users_menu_style'])).toEqual({ diluxone_users_menu_style: 'name' });
	});
});
