import { test, expect } from '../support/fixtures';
import { signInMember } from '../support/admin-content';
import { accountSection } from '../support/ui';

/**
 * Issue #141: on the account area's linked accounts, with every network on
 * offer already linked, the heading "Networks you can link" is still drawn
 * with nothing under it. The reporter expects the heading to go, or a line
 * under it saying every available network is linked.
 *
 * The e2e mu-plugin offers one network, "Mock"; with it linked there is
 * nothing left to link, which is the report's case.
 */

test.describe('Issue 141: the linked accounts with every network linked', () => {
	test('no heading "Networks you can link" stands with nothing visible under it', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_2fa_mode: 'off',
		});

		await signInMember(page, site, pages.login.url, 'repro-141', { meta: { diluxone_users_sso_mock: 'mock|repro-141' } });

		await page.goto(accountSection(pages.account.url, 'accounts'));

		// The page is drawn, and the one network there is is linked.
		await expect(page.locator('.diluxone-users-linked__item.is-linked').filter({ hasText: 'Mock' })).toBeVisible();
		await expect(page.locator('.diluxone-users-linked__item:not(.is-linked)')).toHaveCount(0);

		const heading = page.locator('.diluxone-users-panel__title', { hasText: 'Networks you can link' });

		// Either the heading is gone, or what a person sees under it, without
		// opening anything, is the line saying everything is linked.
		if ((await heading.count()) > 0) {
			const panel = page.locator('details.diluxone-users-panel').filter({ has: heading });

			await expect(panel.locator('.diluxone-users-note'), 'a line under the heading saying every network is linked').toBeVisible();
		}
	});
});
