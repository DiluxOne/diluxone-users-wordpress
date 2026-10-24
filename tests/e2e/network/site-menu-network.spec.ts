import { test, expect } from './support';
import { buttonPaint } from '../support/menu';

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
