import { test, expect, expectSignedIn } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, savePanel, signInWithPassword } from '../support/ui';
import { buttonPaint, contrast, FLEX_MENU, menuBoxes } from '../support/menu';
import { DARK_SITE, themeSwitch } from '../support/dark-mode';
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

	test('left at “link”, “Sign in” is drawn as it always was: a link like the rest, and no CSS of the plugin’s', async ({ page, options }) => {
		// Own colours saved beside it change nothing while it is a link.
		await options.set({ diluxone_users_menu_signin_style: 'link', diluxone_users_menu_signin_colors: 'own', diluxone_users_menu_signin_bg: '#0b3d91' });
		await page.goto(menuUrl);

		const li = item(page, 'sign-in');

		await expect(li).toHaveAttribute('class', 'menu-item diluxone-users-menu diluxone-users-menu--sign-in menu-item-990001');
		await expect(li.locator('> a')).not.toHaveAttribute('class', /./);
		await expect(page.locator('#diluxone-users-menu-inline-css')).toHaveCount(0);

		const site = page.locator('nav.diluxone-e2e-menu li').first().locator('> a');
		const [own, theirs] = await Promise.all([buttonPaint(li.locator('> a')), buttonPaint(site)]);

		expect(own.link, 'painted like the site’s own link').toEqual(theirs.link);
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

		// It says it opens, the class themes draw the arrow and the drop-down
		// from, and the sub-menu hangs from it.
		await expect(person).toHaveClass(/(^|\s)menu-item-has-children(\s|$)/);
		await expect(person.locator('> ul.sub-menu li.diluxone-users-menu--sign-out')).toHaveCount(1);

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

		// With Gravatar off the photo is the one the plugin draws: a data:
		// URL, which esc_url() would have turned into an empty src.
		await options.set({ diluxone_users_avatar_gravatar: 0 });
		await page.goto(menuUrl);
		await expect(item(page, 'person').locator('img.diluxone-users-menu__avatar')).toHaveAttribute(
			'src',
			/^data:image\/svg\+xml,/
		);
	});

	test('with no location chosen, the menu is the site’s own and nothing more', async ({ page, options }) => {
		await options.set({ diluxone_users_menu_location: '' });
		await page.goto(menuUrl);

		await expect(page.locator('nav.diluxone-e2e-menu li')).toHaveCount(1);
		await expect(page.locator('nav.diluxone-e2e-menu li.diluxone-users-menu')).toHaveCount(0);
	});
});

test.describe('“Sign in” as a button, in the theme’s colours', () => {
	let menuUrl = '';

	test.beforeEach(async ({ site, options }) => {
		menuUrl = (await site.menu()).url;
		await options.set({ diluxone_users_menu_location: 'diluxone-e2e' });
	});

	test('filled: the theme’s own button, in its colours, and it still leads to the sign-in page', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_menu_signin_style: 'filled' });
		await page.goto(menuUrl);

		const link = item(page, 'sign-in').locator('> a');

		await expect(item(page, 'sign-in')).toHaveClass(/diluxone-users-menu--button/);
		await expect(item(page, 'sign-in')).toHaveClass(/diluxone-users-menu--filled/);
		await expect(link).toHaveClass(/(^|\s)wp-element-button(\s|$)/);
		await expect(link).toHaveAttribute('href', pages.login.url);

		const paint = await buttonPaint(link);

		expect(paint.link.background, 'the theme’s button ground').toBe(paint.theme.background);
		expect(paint.link.color, 'the theme’s button text').toBe(paint.theme.color);
		expect(paint.link.background, 'a ground of its own, not a link’s').not.toBe('rgba(0, 0, 0, 0)');

		// The site's own link is left as it is.
		await expect(page.locator('nav.diluxone-e2e-menu a.wp-element-button')).toHaveCount(1);

		await link.click();
		await expect(page).toHaveURL(pages.login.url);
	});

	test('outlined: the theme’s button with no ground, a border in its text’s colour', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_menu_signin_style: 'outlined' });
		await page.goto(menuUrl);

		const link = item(page, 'sign-in').locator('> a');

		await expect(item(page, 'sign-in')).toHaveClass(/diluxone-users-menu--outlined/);
		await expect(link).toHaveClass(/(^|\s)wp-element-button(\s|$)/);
		await expect(link).toHaveAttribute('href', pages.login.url);

		const paint = await buttonPaint(link);

		expect(paint.link.background, 'no ground').toBe('rgba(0, 0, 0, 0)');
		expect(paint.link.borderWidth, 'a border').toBeGreaterThanOrEqual(1);
		expect(paint.link.border, 'in the text’s colour').toBe(paint.link.color);
	});
});

test.describe('“Sign in” as a button, in the site’s own colours', () => {
	let menuUrl = '';

	const OWN = {
		diluxone_users_menu_signin_colors: 'own',
		diluxone_users_menu_signin_bg: '#0b3d91',
		diluxone_users_menu_signin_text: '#ffffff',
		diluxone_users_menu_signin_border: '#072b66',
		diluxone_users_menu_signin_hover: '#062456',
	};

	test.beforeEach(async ({ site, options }) => {
		menuUrl = (await site.menu()).url;
		// The e2e mu-plugin's dark stand-in: its switch is `is-dark` on <html>.
		await options.set({ diluxone_users_menu_location: 'diluxone-e2e', ...DARK_SITE });
	});

	for (const style of ['filled', 'outlined'] as const) {
		test(`${style}: those colours, the hover colour on hover, and text that reads, in the light scheme and the dark one`, async ({ page, options }) => {
			await options.set({ diluxone_users_menu_signin_style: style, ...OWN });
			await page.goto(menuUrl);

			const link = item(page, 'sign-in').locator('> a');

			await expect(item(page, 'sign-in')).toHaveClass(/diluxone-users-menu--own/);
			await expect(link, 'the theme’s button would paint over them').not.toHaveClass(/wp-element-button/);

			for (const dark of [false, true]) {
				const scheme = dark ? 'dark' : 'light';
				await themeSwitch(page, dark);

				await page.mouse.move(0, 0);
				const rest = (await buttonPaint(link)).link;

				expect(rest.background, `${scheme}: the background`).toBe('rgb(11, 61, 145)');
				expect(rest.color, `${scheme}: the text`).toBe('rgb(255, 255, 255)');
				expect(rest.border, `${scheme}: the border`).toBe('rgb(7, 43, 102)');
				expect(contrast(rest.color, rest.background), `${scheme}: the text reads`).toBeGreaterThanOrEqual(4.5);

				await link.hover();
				const hover = (await buttonPaint(link)).link;

				expect(hover.background, `${scheme}: the hover colour`).toBe('rgb(6, 36, 86)');
				expect(contrast(hover.color, hover.background), `${scheme}: the text reads on hover`).toBeGreaterThanOrEqual(4.5);
			}
		});
	}
});

test.describe('Where the plugin’s items sit in the menu', () => {
	let menuUrl = '';

	test.beforeEach(async ({ site, options }) => {
		menuUrl = (await site.menu()).url;
		await options.set({ diluxone_users_menu_location: 'diluxone-e2e' });
	});

	test('set apart: on a wide screen “Sign in” sits at the far end of the bar, after the site’s last item, with the room chosen', async ({ page, options }) => {
		await options.set({ diluxone_users_menu_position: 'apart', diluxone_users_menu_gap: '40px' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(menuUrl);
		await page.addStyleTag({ content: FLEX_MENU });

		const { bar, items } = await menuBoxes(page);
		const mine = items.findIndex((one) => /diluxone-users-menu--sign-in/.test(one.classes));
		const theirs = items[mine - 1];

		expect(mine, 'after the site’s own items').toBe(items.length - 1);
		expect(items[mine].classes).toMatch(/diluxone-users-menu--first/);
		expect(items[mine].li.top, 'on the same row').toBeCloseTo(theirs.li.top, 0);
		expect(items[mine].li.right, 'at the far end of the bar').toBeCloseTo(bar.right, 0);
		expect(items[mine].link.left - theirs.li.right, 'pushed away from the site’s last item').toBeGreaterThan(40);

		// On a bar too short to push it, the room chosen is still kept.
		await page.addStyleTag({ content: 'nav.diluxone-e2e-menu > ul{width:max-content}' });
		const tight = await menuBoxes(page);

		expect(tight.items[mine].link.left - tight.items[mine - 1].li.right, 'at least the room chosen').toBeGreaterThanOrEqual(39.5);
	});

	test('at their defaults, the person sits right after the site’s last item, with no room of the plugin’s', async ({ page, site, pages, options }) => {
		const email = freshEmail('menu-defaults');

		await options.set({ diluxone_users_menu_position: 'end', diluxone_users_menu_gap: '', diluxone_users_menu_inner_gap: '' });
		await site.makeUser({ email, password: PASSWORD, name: 'Default Person' });
		await page.goto(pages.login.url);
		await signInWithPassword(page, email, PASSWORD);
		await expectSignedIn(page, email);

		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(menuUrl);
		await page.addStyleTag({ content: FLEX_MENU });

		const { items } = await menuBoxes(page);
		const mine = items.findIndex((one) => /diluxone-users-menu--person/.test(one.classes));

		expect(mine, 'the last item, after the site’s own').toBe(items.length - 1);
		expect(items[mine].classes).not.toMatch(/diluxone-users-menu--(first|next)/);
		expect(items[mine].margin).toBe('0px');
		expect(items[mine].li.left - items[mine - 1].li.right, 'right beside it').toBeCloseTo(0, 0);
	});

	test('set apart: on a phone, where menus stack, it stays where the theme puts it', async ({ page, options }) => {
		await options.set({ diluxone_users_menu_position: 'apart', diluxone_users_menu_gap: '40px' });
		await page.setViewportSize({ width: 390, height: 800 });
		await page.goto(menuUrl);
		await page.addStyleTag({ content: FLEX_MENU });

		const { items } = await menuBoxes(page);
		const mine = items.find((one) => /diluxone-users-menu--sign-in/.test(one.classes))!;

		expect(mine.margin, 'not pushed below 600px').toBe('0px');
		expect(mine.link.left - items[items.length - 2].li.right, 'no room of its own').toBeCloseTo(0, 0);
	});
});

test.describe('Account area › In the site menu', () => {
	test.use({ storageState: ADMIN_STATE });

	test('the location and the style saved on the screen are what the menu draws', async ({ page, guest, site, options }) => {
		await options.keep(['diluxone_users_menu_location', 'diluxone_users_menu_style', 'diluxone_users_menu_signin_style', 'diluxone_users_menu_signin_colors']);
		await options.set({ diluxone_users_menu_location: '' });

		const { url } = await site.menu();

		await guest.goto(url);
		await expect(item(guest, 'sign-in')).toHaveCount(0);

		// The screen offers the locations the theme registered — here, the
		// one the mu-plugin did.
		await page.goto(adminUrl('diluxone-users-account', 'menu'));
		await page.locator('select[name="diluxone_users_menu_location"]').selectOption('diluxone-e2e');
		await page.locator('select[name="diluxone_users_menu_style"]').selectOption('name');
		await page.locator('select[name="diluxone_users_menu_signin_style"]').selectOption('outlined');
		await savePanel(page);

		await guest.goto(url);
		await expect(item(guest, 'sign-in')).toHaveCount(1);
		await expect(item(guest, 'sign-in')).toHaveClass(/diluxone-users-menu--outlined/);

		expect(await site.getOptions(['diluxone_users_menu_style', 'diluxone_users_menu_signin_style'])).toEqual({
			diluxone_users_menu_style: 'name',
			diluxone_users_menu_signin_style: 'outlined',
		});
	});

	test('the colour pickers show with “Your own” only, and the preview takes the colours', async ({ page, options }) => {
		await options.keep([
			'diluxone_users_menu_signin_style',
			'diluxone_users_menu_signin_colors',
			'diluxone_users_menu_signin_bg',
			'diluxone_users_menu_signin_text',
			'diluxone_users_menu_signin_border',
			'diluxone_users_menu_signin_hover',
		]);
		await options.set({ diluxone_users_menu_signin_style: 'filled', diluxone_users_menu_signin_colors: 'theme', diluxone_users_menu_signin_bg: '#0b3d91' });

		await page.goto(adminUrl('diluxone-users-account', 'menu'));

		const own = page.locator('[data-diluxone-users-menu-own]');
		const preview = page.locator('[data-diluxone-users-menu-preview] .du-menu-preview__item > a');

		await expect(own).toBeHidden();
		await page.locator('select[name="diluxone_users_menu_signin_colors"]').selectOption('own');
		await expect(own).toBeVisible();
		await expect(own.locator('.wp-picker-container'), 'WordPress’s colour picker, one per colour').toHaveCount(4);
		await expect(preview).toHaveCSS('background-color', 'rgb(11, 61, 145)');
	});
});
