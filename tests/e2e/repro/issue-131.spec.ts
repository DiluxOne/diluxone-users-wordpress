import { test, expect } from '../support/fixtures';
import { DARK_GROUND, DARK_SITE, groundBehind, unreadableOn } from '../support/dark-mode';
import { signInMember } from '../support/admin-content';
import { accountSection } from '../support/ui';
import { reveal } from '../support/my-account';

/**
 * Issue #131: the status colours are one light-scheme value for both schemes,
 * so on a dark ground the pills of a done and a failed request fall under
 * 4.5:1.
 *
 * The site is the e2e mu-plugin's dark stand-in with Colours › the theme's.
 * The two requests are drawn as the privacy template draws them (the same
 * table, row and pill classes, `ok` for a done one and `off` for a failed
 * one) into the real privacy tab, because the browser cannot make a request
 * of either state without WP-CLI.
 */

const ROW = (tone: string) =>
	`<tr><td>1 Jan 2026, 10:00</td><td><span class="diluxone-users-pill diluxone-users-pill--${tone}">${tone}</span></td><td class="diluxone-users-requests__action"></td></tr>`;

test.describe('Issue 131: status colours as text in dark mode', () => {
	test('on the privacy tab, the pills of a done and a failed request keep 4.5:1 against what is behind them', async ({ guest, site, pages, options }) => {
		await options.set({ ...DARK_SITE, diluxone_users_2fa_mode: 'off', diluxone_users_privacy_export: 1 });

		await guest.emulateMedia({ colorScheme: 'dark' });
		await signInMember(guest, site, pages.login.url, 'status-dark');

		await guest.goto(accountSection(pages.account.url, 'privacy'));
		await reveal(guest, '.diluxone-users-panel[open] .diluxone-users-panel__body');
		await expect.poll(() => groundBehind(guest, '.diluxone-users-panel__body'), { message: 'the page is drawn on the dark ground' }).toBe(DARK_GROUND);

		await guest.locator('.diluxone-users-panel[open] .diluxone-users-panel__body').first().evaluate((body, rows) => {
			body.insertAdjacentHTML(
				'afterbegin',
				`<table class="diluxone-users-table diluxone-users-requests"><tbody>${rows}</tbody></table>`
			);
		}, ROW('ok') + ROW('off'));

		await expect(guest.locator('.diluxone-users-pill--ok')).toBeVisible();
		await expect(guest.locator('.diluxone-users-pill--off')).toBeVisible();

		const found = [
			...(await unreadableOn(guest, 'the done pill', '.diluxone-users-pill--ok')),
			...(await unreadableOn(guest, 'the failed pill', '.diluxone-users-pill--off')),
		];

		expect(found, 'both pills at 4.5:1 or more').toEqual([]);
	});
});
