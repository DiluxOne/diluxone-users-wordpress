import { test, expect } from './support';
import { control, expectPill, notNow, waitingBlocks, waits } from '../support/admin-access';
import { stateIn, summaryRow, summaryRowAt } from '../support/admin-ops';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * A fresh network says nothing was chosen.
 *
 * The same reading as the single-site suite's, where the network keeps the
 * screens: Access and the account area on the hub, Security in Network
 * Admin. A setting of the network's taken away is Not configured on the
 * network's screen; a block that waits for another answer waits there too.
 */

test.use({ storageState: NETWORK_ADMIN_STATE });

const SECURITY = 'diluxone-users-security';
const onHub = (rest: string) => `${NETWORK_URL}/wp-admin/${rest.replace(/^\//, '')}`;
const network = (rest: string) => `${NETWORK_URL}/wp-admin/network/${rest.replace(/^\//, '')}`;

const NOTHING_SAVED: Record<string, null> = Object.fromEntries(
	[
		'diluxone_users_login_page',
		'diluxone_users_login_method',
		'diluxone_users_sso_login',
		'diluxone_users_login_register',
		'diluxone_users_sso_register',
		'diluxone_users_register_form',
		'diluxone_users_wp_screens',
		'diluxone_users_lost_password',
		'diluxone_users_2fa_mode',
		'diluxone_users_2fa_methods',
		'diluxone_users_session_long_days',
		'diluxone_users_session_short_days',
		'diluxone_users_sessions_show',
		'diluxone_users_account_page',
		'diluxone_users_account_sections',
		'diluxone_users_handle_enabled',
		'diluxone_users_wp_profile',
		'diluxone_users_wp_profile_scope',
		'diluxone_users_wp_profile_roles',
		'diluxone_users_admin_bar',
		'diluxone_users_privacy_export',
		'diluxone_users_privacy_delete',
	].map((key) => [key, null])
);

test.describe('A fresh network says nothing was chosen', () => {
	test.beforeEach(async ({ hub }) => {
		await hub.set(NOTHING_SAVED);
	});

	test('the hub’s Access › Summary and the account area read Not configured, with no reason beside the pill', async ({ page }) => {
		await page.goto(onHub('admin.php?page=diluxone-users-login&tab=summary'));

		const rows = page.locator('table.diluxone-users-summary').first().locator('tbody > tr');

		for (let at = 0; at < (await rows.count()); at += 1) {
			await expectPill(rows.nth(at), 'unset');
		}

		await expect(page.locator('table.diluxone-users-summary .diluxone-users-state__why')).toHaveCount(0);

		await page.goto(onHub('admin.php?page=diluxone-users-account&tab=summary'));

		for (const at of [0, 1, 2, 3, 4, 5]) {
			await expectPill(summaryRowAt(page, at), 'unset');
		}
	});

	test('the hub’s sign-in page tab waits for a page, with nothing chosen and no pending pill', async ({ page }) => {
		await page.goto(onHub('admin.php?page=diluxone-users-login&tab=page'));

		await expect(waits(page)).toHaveCount(2);
		await expect(waitingBlocks(page)).toHaveCount(2);
		await expect(notNow(page)).toHaveCount(0);
		await expect(page.locator('[name="diluxone_users_wp_screens"]:checked, [name="diluxone_users_lost_password"]:checked')).toHaveCount(0);
		await expect(control(page, 'diluxone_users_wp_screens', 'auto')).toBeDisabled();
	});

	test('Security in Network Admin reads Not configured for the network’s second step and sessions, and the two-step tab waits', async ({ page }) => {
		await page.goto(network(`admin.php?page=${SECURITY}&tab=summary`));

		for (const at of [0, 1, 2]) {
			expect(await stateIn(summaryRow(page, SECURITY, '2fa', at)), `two-step row ${at}`).toBe('unset');
		}

		expect(await stateIn(summaryRow(page, SECURITY, 'sessions', 0))).toBe('unset');
		await expect(summaryRow(page, SECURITY, '2fa', 0).locator('a')).toHaveAttribute('href', /\/wp-admin\/network\//);

		await page.goto(network(`admin.php?page=${SECURITY}&tab=2fa`));

		await expect(page.locator('input[name="diluxone_users_2fa_mode"]:checked')).toHaveCount(0);
		await expect(waits(page)).toHaveCount(2);
		await expect(control(page, 'diluxone_users_2fa_methods[]', 'email')).toBeDisabled();

		await control(page, 'diluxone_users_2fa_mode', 'optional').check();
		await expect(waits(page)).toHaveCount(0);
		await expect(control(page, 'diluxone_users_2fa_methods[]', 'email')).toBeEnabled();
	});
});
