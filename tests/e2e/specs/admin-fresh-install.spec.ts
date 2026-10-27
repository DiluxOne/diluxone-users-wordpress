import type { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl, savePanel, loginWay } from '../support/ui';
import { control, expectPill, notNow, rail, waitingBlocks, waits } from '../support/admin-access';
import { stateIn, summaryRow, summaryRowAt } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * A fresh install says nothing was chosen.
 *
 * Activating the plugin changes nothing, and the screens have to say so. With
 * nothing saved, every row that reads a setting is Not configured and says
 * what WordPress does meanwhile, no box is ticked and no option chosen, and a
 * block that depends on another answer waits for it: disabled, with nothing
 * chosen, under one line saying what for, let go the moment the answer is
 * given on the same tab. A tab saved once, at its defaults, reads as decided.
 *
 * The baseline every other spec stands on is a site with its features on, so
 * this one takes the settings away for the length of each test; the fixture
 * puts them back.
 */

test.use({ storageState: ADMIN_STATE });

/** Every setting these screens read, taken away: the plugin as activated. */
const NOTHING_SAVED: Record<string, null> = Object.fromEntries(
	[
		'diluxone_users_login_page',
		'diluxone_users_login_method',
		'diluxone_users_sso_login',
		'diluxone_users_login_register',
		'diluxone_users_sso_register',
		'diluxone_users_register_form',
		'diluxone_users_login_role',
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
		'diluxone_users_notice_rules',
	].map((key) => [key, null])
);

const SECURITY = 'diluxone-users-security';

/** Every state pill of the first summary table, in order. */
async function pills(page: Page): Promise<string[]> {
	const rows = page.locator('table.diluxone-users-summary').first().locator('tbody > tr');
	const states: string[] = [];

	for (let at = 0; at < (await rows.count()); at += 1) {
		states.push(await stateIn(rows.nth(at)));
	}

	return states;
}

test.describe('A fresh install says nothing was chosen', () => {
	test.beforeEach(async ({ options }) => {
		await options.set(NOTHING_SAVED);
	});

	test('the overview, Access › Summary and Registration › Summary read Not configured on every setting, and say what WordPress does', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users', 'doors'));

		// The page, the method and the doors; the mail row is not a setting.
		await expectPill(summaryRowAt(page, 0), 'unset');
		await expectPill(summaryRowAt(page, 1), 'unset');
		await expectPill(summaryRowAt(page, 3), 'unset');
		await expect(summaryRowAt(page, 0).locator('.diluxone-users-summary__detail')).toContainText('wp-login.php');
		await expect(summaryRowAt(page, 0).locator('.diluxone-users-summary__change a')).toHaveAttribute('href', /page=diluxone-users-login/);

		// Registration, a row of its own, decided by nobody either.
		await expectPill(summaryRowAt(page, 4), 'unset');
		await expect(summaryRowAt(page, 4).locator('.diluxone-users-summary__change a')).toHaveAttribute('href', /page=diluxone-users-register/);

		await page.goto(adminUrl('diluxone-users-login', 'summary'));

		const states = await pills(page);

		expect(states.length).toBeGreaterThanOrEqual(5);
		expect(states.every((state) => state === 'unset'), `every row: ${states.join(', ')}`).toBe(true);
		await expect(page.locator('table.diluxone-users-summary .diluxone-users-state__why'), 'a Not configured pill carries no reason').toHaveCount(0);

		// Registration › Summary: every door of the plugin's and the role read
		// Not configured; WordPress's own form is WordPress's switch, always
		// decided (the fourth row).
		await page.goto(adminUrl('diluxone-users-register', 'summary'));

		const doors = await pills(page);

		expect(doors).toHaveLength(5);
		expect([...doors.slice(0, 3), doors[4]].every((state) => state === 'unset'), `every row but WordPress’s form: ${doors.join(', ')}`).toBe(true);
		await expect(page.locator('table.diluxone-users-summary .diluxone-users-state__why'), 'a Not configured pill carries no reason').toHaveCount(0);
	});

	test('Access › Ways in ticks nothing and says the form is WordPress’s own until a way in is chosen; saved once, the doors read as decided', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-login', 'ways'));

		await expect(loginWay(page, 'link')).not.toBeChecked();
		await expect(loginWay(page, 'password')).not.toBeChecked();
		await expect(control(page, 'diluxone_users_sso_login')).not.toBeChecked();
		await expect(waits(page)).toHaveCount(1);
		await expect(page.locator('label.du-choice').filter({ has: control(page, 'diluxone_users_sso_login') }).locator('.diluxone-users-state'), 'a box nobody ticked waits on nothing').toHaveCount(0);

		// The one choice the plugin's own default would have made, made by a person.
		await loginWay(page, 'password').check({ force: true });
		await savePanel(page);

		const ways = await site.getOptions(['diluxone_users_login_method', 'diluxone_users_sso_login']);

		expect(ways.diluxone_users_login_method).toBe('password');
		expect(Number(ways.diluxone_users_sso_login), 'the box was drawn and not ticked').toBe(0);
		await expect(waits(page)).toHaveCount(0);

		await page.goto(adminUrl('diluxone-users-login', 'summary'));
		await expectPill(summaryRowAt(page, 0), 'unset');
		await expectPill(summaryRowAt(page, 1), 'off');
		await expectPill(summaryRowAt(page, 2), 'active');
		await expectPill(summaryRowAt(page, 3), 'off');
	});

	test('Access › The sign-in page: what becomes of wp-login.php and the reset wait for a page, and go the moment one is picked', async ({ page, pages, site }) => {
		await page.goto(adminUrl('diluxone-users-login', 'page'));

		await expect(waits(page)).toHaveCount(2);
		await expect(waitingBlocks(page)).toHaveCount(2);
		await expect(notNow(page)).toHaveCount(0);
		await expect(page.locator('[name="diluxone_users_wp_screens"]:checked, [name="diluxone_users_lost_password"]:checked')).toHaveCount(0);
		await expect(control(page, 'diluxone_users_wp_screens', 'auto')).toBeDisabled();
		await expect(control(page, 'diluxone_users_lost_password', 'wp')).toBeDisabled();
		await expect(page.locator('.diluxone-users-studio__fields .diluxone-users-state'), 'no pending pill on any option').toHaveCount(0);

		// Saved as it stands: the page alone is written, the groups that
		// waited are not.
		await savePanel(page);
		const saved = await site.getOptions(['diluxone_users_login_page', 'diluxone_users_wp_screens', 'diluxone_users_lost_password']);

		expect(Number(saved.diluxone_users_login_page)).toBe(0);
		expect(saved.diluxone_users_wp_screens, 'waited, so not asked').toBeNull();
		expect(saved.diluxone_users_lost_password, 'waited, so not asked').toBeNull();

		await page.locator('#diluxone_users_login_page').selectOption(String(pages.login.id));
		await expect(waits(page)).toHaveCount(0);
		await expect(control(page, 'diluxone_users_wp_screens', 'auto')).toBeEnabled();
		await expect(page.locator('[name="diluxone_users_wp_screens"]:checked'), 'let go, with nothing chosen yet').toHaveCount(0);

		await control(page, 'diluxone_users_wp_screens', 'mine').check({ force: true });
		await control(page, 'diluxone_users_lost_password', 'site').check({ force: true });
		await savePanel(page);

		const chosen = await site.getOptions(['diluxone_users_login_page', 'diluxone_users_wp_screens', 'diluxone_users_lost_password']);

		expect(Number(chosen.diluxone_users_login_page)).toBe(pages.login.id);
		expect(chosen.diluxone_users_wp_screens).toBe('mine');
		expect(chosen.diluxone_users_lost_password).toBe('site');
	});

	test('Security › Summary reads Not configured for the second step and the sessions, and the two-step tab waits for a mode', async ({ page }) => {
		await page.goto(adminUrl(SECURITY, 'summary'));

		for (const at of [0, 1, 2]) {
			expect(await stateIn(summaryRow(page, SECURITY, '2fa', at)), `two-step row ${at}`).toBe('unset');
		}

		expect(await stateIn(summaryRow(page, SECURITY, 'sessions', 0)), 'how long a session lasts').toBe('unset');
		await expect(summaryRow(page, SECURITY, 'sessions', 0).locator('.diluxone-users-summary__detail')).toContainText('WordPress');
		expect(await stateIn(summaryRow(page, SECURITY, 'sessions', 1)), 'their own sessions').toBe('unset');

		await page.goto(adminUrl(SECURITY, '2fa'));

		await expect(page.locator('input[name="diluxone_users_2fa_mode"]:checked')).toHaveCount(0);
		await expect(waits(page)).toHaveCount(2);
		await expect(waitingBlocks(page)).toHaveCount(2);
		await expect(control(page, 'diluxone_users_2fa_methods[]', 'email')).toBeDisabled();
		await expect(page.locator('input[name="diluxone_users_2fa_link"]'), 'the link rule is no choice').toHaveCount(0);
		await expect(notNow(page)).toHaveCount(0);
		expect(await stateIn(rail(page)), 'the rail: nothing asked, nothing decided').toBe('unset');

		// The three Pro answers in the waiting groups are not part of the wait:
		// disabled for a reason of their own, badge on, nothing for the
		// script to let go.
		const pro = page.locator(
			'[data-diluxone-users-pro="security/2fa/app"], [data-diluxone-users-pro="security/2fa/link"], [data-diluxone-users-pro="security/2fa/remember"]',
		);

		await expect(pro).toHaveCount(3);
		await expect(pro.locator('input:disabled')).toHaveCount(3);
		await expect(pro.locator('input[data-diluxone-users-waited]'), 'not marked as waiting').toHaveCount(0);
		await expect(pro.locator('.diluxone-users-state--pro'), 'the badge, while the group waits').toHaveCount(3);

		await control(page, 'diluxone_users_2fa_mode', 'optional').check();
		await expect(waits(page)).toHaveCount(0);
		await expect(control(page, 'diluxone_users_2fa_methods[]', 'email')).toBeEnabled();
		await expect(control(page, 'diluxone_users_2fa_methods[]', 'email')).not.toBeChecked();
		await expect(pro.locator('input:disabled'), 'and still disabled once the wait ends').toHaveCount(3);
		await expect(pro.locator('.diluxone-users-state--pro')).toHaveCount(3);
	});

	test('Security › Sessions: at 0 and 0 the tab and the rail say WordPress decides; saved so on purpose, that reads as Off', async ({ page, site }) => {
		await page.goto(adminUrl(SECURITY, 'sessions'));

		await expect(page.locator('.du-section__intro').first()).toContainText('WordPress');
		expect(await stateIn(rail(page))).toBe('unset');
		await expect(rail(page).locator('.du-state__line')).toContainText('WordPress');

		await expect(control(page, 'diluxone_users_session_long_days')).toHaveValue('0');
		await savePanel(page);

		const lengths = await site.getOptions(['diluxone_users_session_long_days', 'diluxone_users_session_short_days']);

		expect(Number(lengths.diluxone_users_session_long_days)).toBe(0);
		expect(Number(lengths.diluxone_users_session_short_days)).toBe(0);
		expect(lengths.diluxone_users_session_long_days, 'stored, on purpose').not.toBeNull();
		expect(await stateIn(rail(page)), 'decided: WordPress decides, on purpose').toBe('off');

		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await stateIn(summaryRow(page, SECURITY, 'sessions', 0))).toBe('off');
	});

	test('Account area › Summary reads Not configured on every row, and “for whom” waits until the profile is redirected or closed', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-account', 'summary'));

		const states = await pills(page);

		expect(states, 'the page, the sections, the public name, the profile, the toolbar, their data').toEqual(['unset', 'unset', 'unset', 'unset', 'unset', 'unset']);

		await page.goto(adminUrl('diluxone-users-account', 'dashboard'));

		await expect(page.locator('input[name="diluxone_users_wp_profile"]:checked')).toHaveCount(0);
		await expect(waits(page)).toHaveCount(1);
		await expect(control(page, 'diluxone_users_wp_profile_scope', 'all')).toBeDisabled();
		await expect(page.locator('input[name="diluxone_users_wp_profile_roles[]"]:enabled')).toHaveCount(0);
		await expect(notNow(page)).toHaveCount(0);

		await control(page, 'diluxone_users_wp_profile', 'redirect').check({ force: true });
		await expect(waits(page)).toHaveCount(0);
		await expect(control(page, 'diluxone_users_wp_profile_scope', 'all')).toBeEnabled();
		await expect(page.locator('input[name="diluxone_users_wp_profile_scope"]:checked')).toHaveCount(0);

		await control(page, 'diluxone_users_wp_profile_scope', 'all').check({ force: true });
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_wp_profile', 'diluxone_users_wp_profile_scope'])).toEqual({
			diluxone_users_wp_profile: 'redirect',
			diluxone_users_wp_profile_scope: 'all',
		});

		await page.goto(adminUrl('diluxone-users-account', 'summary'));
		expect(await stateIn(summaryRowAt(page, 3)), 'the profile, decided').toBe('off');
	});

	test('Notifications › Summary reads Not configured until the rules are saved', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-notices', 'summary'));

		const states = await pills(page);

		expect(states.length).toBeGreaterThan(3);
		expect(states.every((state) => state === 'unset'), `every row: ${states.join(', ')}`).toBe(true);

		await page.goto(adminUrl('diluxone-users-notices', 'rules'));
		await savePanel(page);

		await page.goto(adminUrl('diluxone-users-notices', 'summary'));
		// The plugin's own two notices come first; the rows after them read
		// other settings, still undecided.
		expect((await pills(page)).slice(0, 2).every((state) => state !== 'unset'), 'the rules, saved at their defaults, are decided').toBe(true);
	});
});
