import { Page } from '@playwright/test';
import { test, expect, loginUrl } from '../support/fixtures';

/**
 * Issue #68: with Colours set to the theme's, the colours are measured once on
 * load and never again, so a light/dark switch leaves the sign-in card on the
 * old ground.
 *
 * The theme here is Astra, which the report names. Its free version has no
 * switch of its own, so the dark palette is what a switch does to it: its
 * global colours and the grounds of the content area change in place, through
 * a stylesheet added once the page has been measured in light mode.
 */

const DARK_GROUND = 'rgb(11, 16, 32)';

/** What the plugin has written for the surface on <html>, or '' while nothing has. */
async function surface(page: Page): Promise<string> {
	return page.evaluate(() => document.documentElement.style.getPropertyValue('--diluxone-users-surface').trim());
}

/** The theme's dark palette: the grounds the plugin measures and the text on them, under `scope`. */
function darkRules(scope: string): string {
	return `
		${scope} { --ast-global-color-4: #0B1020; --ast-global-color-2: #E6E9F2; }
		${scope} body, ${scope} #page, ${scope} #content, ${scope} .site-content, ${scope} #primary,
		${scope} main, ${scope} .entry-content, ${scope} .ast-container {
			background-color: #0B1020 !important;
			color: #E6E9F2 !important;
		}
	`;
}

test.describe('Theme colours when the theme switches light/dark', () => {
	test.use({ colorScheme: 'light' });

	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_styles: 1, diluxone_users_colors: 'theme' });
	});

	async function openMeasuredInLight(guest: Page, url: string): Promise<void> {
		await guest.goto(url);
		await expect.poll(() => surface(guest), { message: 'the first measure' }).not.toBe('');
		expect(await surface(guest), 'light mode starts on a light ground').not.toBe(DARK_GROUND);
	}

	test('the surface follows the theme when the site switches to dark in place', async ({ guest, pages }) => {
		await openMeasuredInLight(guest, loginUrl(pages));
		await guest.addStyleTag({ content: darkRules('html[data-theme="dark"]') });

		await guest.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));

		await expect.poll(() => surface(guest), { message: 'the surface after the switch, with no reload' }).toBe(DARK_GROUND);
	});

	test('the surface follows the theme when the system changes to dark with the page open', async ({ guest, pages }) => {
		await openMeasuredInLight(guest, loginUrl(pages));
		await guest.addStyleTag({ content: `@media (prefers-color-scheme: dark) { ${darkRules('html')} }` });

		await guest.emulateMedia({ colorScheme: 'dark' });

		await expect.poll(() => surface(guest), { message: 'the surface after the system changed, with no reload' }).toBe(DARK_GROUND);
	});
});
