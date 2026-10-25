import { test, expect } from './support';
import { buttonPaint, FLEX_MENU, menuBoxes } from '../support/menu';
import { freshEmail } from '../support/api';
import { signedInContext } from '../support/refusals';
import { NETWORK_URL } from '../../../playwright.network.config';

/**
 * The plugin's items in the menu of a site of the network that is not the hub.
 *
 * The look of "Sign in" is each site's own (`site` scope): /alpha/ draws it as
 * its setting says, with /alpha/'s theme's button, and the way in still leads
 * to the hub's sign-in, wherever the network sends it.
 */

test.describe('“Sign in” as a button on a site of the network', () => {
	for (const style of ['filled', 'outlined'] as const) {
		test(`${style}: /alpha/ draws the theme’s button, and it leads to the sign-in page`, async ({ guest, alpha, beta }) => {
			const menu = await alpha.site.menu();
			const other = await beta.site.menu();

			await alpha.set({ diluxone_users_menu_location: menu.location, diluxone_users_menu_signin_style: style });
			await beta.set({ diluxone_users_menu_location: other.location, diluxone_users_menu_signin_style: 'link' });

			await guest.goto(menu.url);

			const li = guest.locator('nav.diluxone-e2e-menu li.diluxone-users-menu--sign-in');
			const link = li.locator('> a');

			await expect(li).toHaveClass(new RegExp(`diluxone-users-menu--${style}`));
			await expect(link).toHaveClass(/(^|\s)wp-element-button(\s|$)/);

			const paint = await buttonPaint(link);

			if ('filled' === style) {
				expect(paint.link.background, 'the theme’s button ground').toBe(paint.theme.background);
				expect(paint.link.color, 'the theme’s button text').toBe(paint.theme.color);
			} else {
				expect(paint.link.background, 'no ground').toBe('rgba(0, 0, 0, 0)');
				expect(paint.link.border, 'a border in the text’s colour').toBe(paint.link.color);
			}

			const href = (await link.getAttribute('href'))!;
			const answer = await guest.request.get(href);

			expect(answer.ok(), 'the way in opens').toBe(true);
			expect(await answer.text(), 'on a sign-in form').toContain('diluxone_users_email');

			// /beta/ keeps its own: a link.
			await guest.goto(other.url);
			await expect(guest.locator('nav.diluxone-e2e-menu li.diluxone-users-menu--sign-in')).not.toHaveClass(/diluxone-users-menu--button/);
			await expect(guest.locator('nav.diluxone-e2e-menu a.wp-element-button')).toHaveCount(0);
		});
	}
});

test.describe('The room between two of the plugin’s items on a site of the network', () => {
	test('“Join” and the person, side by side on /alpha/, are the room chosen apart', async ({ browser, hub, alpha }) => {
		const menu = await alpha.site.menu();
		const email = freshEmail('net-menu-join');
		const password = 'e2e-Menu-Join-1!';

		// By invitation, and an add-on (the e2e mu-plugin) offering a way in:
		// somebody who is not a member of /alpha/ gets its "Join" in the menu.
		await hub.set({ diluxone_users_membership: 'invite', diluxone_users_membership_confirmed: 1 });
		await alpha.set({ diluxone_users_menu_location: menu.location, diluxone_users_menu_inner_gap: '18px', diluxone_e2e_join: 1 });
		await hub.site.makeUser({ email, password });

		const context = await signedInContext(browser, NETWORK_URL, email, password);

		try {
			const page = await context.newPage();

			await page.setViewportSize({ width: 1280, height: 800 });
			await page.goto(menu.url);
			await page.addStyleTag({ content: FLEX_MENU });

			const { items } = await menuBoxes(page);
			const join = items.findIndex((one) => /diluxone-users-menu--join/.test(one.classes));
			const person = items.findIndex((one) => /diluxone-users-menu--person/.test(one.classes));

			expect(join, '“Join” shows').toBeGreaterThan(-1);
			expect(person, 'beside the person').toBe(join + 1);
			expect(items[person].classes).toMatch(/diluxone-users-menu--next/);
			expect(items[person].li.left - items[join].li.right, 'the room chosen').toBeCloseTo(18, 0);
		} finally {
			await context.close();
			await hub.site.deleteUser(email);
		}
	});
});
