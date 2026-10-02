import { test, expect } from '../support/fixtures';
import { adminUrl, saveButton } from '../support/ui';
import { MOCK_SSO, accessTab, expectPill, rail } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Access › Summary: every door and the state it is in, read from the settings
 * the other tabs write.
 *
 * The rows are found by position — the table always has the same seven, in
 * the same order — and their state by the pill's class, never its word. Each
 * case sets the doors through the side door (they are this tab's input, not
 * its subject: the tabs that write them have their own specs) and reads the
 * table back.
 */

test.use({ storageState: ADMIN_STATE });

/** The seven rows, in the order the table draws them. */
const ROWS = ['page', 'link', 'password', 'social', 'passkey', 'register', 'after'] as const;
type Row = (typeof ROWS)[number];

/** Where each row's "change it" link goes. */
const LINKS: Record<Row, RegExp> = {
	page: /page=diluxone-users-login&tab=page/,
	link: /page=diluxone-users-login&tab=ways/,
	password: /page=diluxone-users-login&tab=ways/,
	social: /page=diluxone-users-social/,
	passkey: /page=diluxone-users-login&tab=ways/,
	register: /page=diluxone-users-login&tab=register/,
	after: /page=diluxone-users-security/,
};

const CASES: Array<{ name: string; set: Record<string, unknown>; expect: Partial<Record<Row, 'active' | 'pending' | 'off'>> }> = [
	{
		name: 'every door open',
		set: {
			...MOCK_SSO,
			diluxone_users_login_method: 'both',
			diluxone_users_sso_login: 1,
			diluxone_users_passkey_enabled: 1,
			diluxone_users_login_register: 1,
			diluxone_users_2fa_mode: 'optional',
		},
		expect: { page: 'active', link: 'active', password: 'active', social: 'active', passkey: 'active', register: 'active', after: 'active' },
	},
	{
		name: 'every door shut but the link',
		set: {
			diluxone_e2e_sso: 0,
			diluxone_users_login_method: 'link',
			diluxone_users_sso_login: 0,
			diluxone_users_passkey_enabled: 0,
			diluxone_users_login_register: 0,
			diluxone_users_sso_register: 0,
			diluxone_users_register_form: 0,
			users_can_register: 0,
			diluxone_users_2fa_mode: 'off',
		},
		expect: { link: 'active', password: 'off', social: 'off', passkey: 'off', register: 'off', after: 'off' },
	},
	{
		name: 'the buttons on with nothing to show, and the form with no page',
		set: {
			diluxone_e2e_sso: 0,
			diluxone_users_sso: {},
			diluxone_users_login_method: 'password',
			diluxone_users_sso_login: 1,
			diluxone_users_login_register: 0,
			diluxone_users_sso_register: 0,
			diluxone_users_register_form: 1,
			diluxone_users_register_page: 0,
			users_can_register: 0,
			diluxone_users_login_page: 0,
		},
		expect: { page: 'pending', link: 'off', password: 'active', social: 'pending', register: 'pending' },
	},
];

test.describe('Access › Summary', () => {
	for (const one of CASES) {
		test(`the table reads the settings: ${one.name}`, async ({ page, options }) => {
			await options.set(one.set);

			await page.goto(accessTab('summary'));

			const rows = page.locator('table.diluxone-users-summary tbody tr');

			await expect(rows).toHaveCount(ROWS.length);

			for (const [at, row] of ROWS.entries()) {
				await expect(rows.nth(at).locator('.diluxone-users-summary__change a'), `${row}: its way to change it`).toHaveAttribute('href', LINKS[row]);

				const state = one.expect[row];

				if (state) {
					await expectPill(rows.nth(at).locator('.diluxone-users-summary__state'), state);
				}
			}
		});
	}

	test('is the first tab, saves nothing, and carries the way back in for a locked-out administrator', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-login'));

		await expect(page.locator('.nav-tab-wrapper .nav-tab').first()).toHaveClass(/nav-tab-active/);
		await expect(page.locator('.nav-tab-active')).toHaveAttribute('href', /tab=summary/);
		await expect(page.locator('table.diluxone-users-summary')).toBeVisible();
		await expect(saveButton(page), 'a Save button on a tab that edits nothing').toHaveCount(0);
		await expect(page.locator('.diluxone-users-studio form[method="post"]')).toHaveCount(0);

		await page.goto('/wp-admin/profile.php');

		const email = await page.locator('#email').inputValue();

		await page.goto(accessTab('summary'));

		const codes = rail(page).locator('code');

		await expect(codes.filter({ hasText: /wp-login\.php\?diluxone-users-admin=1$/ })).toHaveCount(1);
		await expect(codes.filter({ hasText: `wp diluxone-users login ${email}` })).toHaveCount(1);

		// And the emergency address it names is a real one: it draws
		// WordPress's own form even on a site that took wp-login.php over.
		const url = (await codes.filter({ hasText: /diluxone-users-admin=1/ }).innerText()).trim();

		expect(new URL(url).origin).toBe(new URL(page.url()).origin);
	});
});
