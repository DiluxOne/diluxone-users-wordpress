import type { Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, navigated, savePanel, ssoButton } from '../support/ui';
import { rail, stateIn } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Social login › Rules, driven through its own boxes.
 *
 * `sso.spec.ts` proves what each rule does with the value written by the side
 * door. Here each one is pressed on the screen, saved, read back after a
 * reload and through the side door, and then the same person comes back
 * from the fake network in a stranger's browser: linked by e-mail or turned
 * away, a silent provider refused, a role outside the list sent to the other
 * ways in. And the rail, which says whether an account gets created.
 */

test.use({ storageState: ADMIN_STATE });

const RULES = adminUrl('diluxone-users-social', 'general');
const PASSWORD = 'e2e-Social-Rules-1!';

const box = (page: Page, name: string) => page.locator(`input[name="${name}"]`);

/** A stranger presses the mock's button on the sign-in page. */
async function comeBackFromTheNetwork(guest: Page, loginUrl: string): Promise<void> {
	await guest.goto(loginUrl);
	await expect(ssoButton(guest, 'mock')).toBeVisible();
	await navigated(guest, () => ssoButton(guest, 'mock').click());
}

test.describe('Social login › Rules', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_sso_link_by_email', 'diluxone_users_sso_verified_only', 'diluxone_users_sso_scope', 'diluxone_users_sso_roles']);
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 0,
			diluxone_users_sso_scope: 'all',
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('a site has no switch for social sign-in on this tab: it is Access’s', async ({ page }) => {
		await page.goto(RULES);
		await expect(box(page, 'diluxone_users_sso_login')).toHaveCount(0);
	});

	test('“link by e-mail” unticked: a verified address that already has an account is turned away; ticked, it opens it', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		await page.goto(RULES);
		await box(page, 'diluxone_users_sso_link_by_email').uncheck();
		await savePanel(page);
		await page.reload();
		await expect(box(page, 'diluxone_users_sso_link_by_email')).not.toBeChecked();
		expect(Number((await site.getOptions(['diluxone_users_sso_link_by_email'])).diluxone_users_sso_link_by_email)).toBe(0);

		const email = freshEmail('rules-link');
		const existing = await site.makeUser({ email, password: PASSWORD });

		await site.setIdentity({ sub: 'mock|rules-link', email, email_verified: true });
		await comeBackFromTheNetwork(guest, pages.login.url);

		await expectSignedOut(guest);
		expect(stateOf(guest.url())).toBe('social');
		expect((await site.user(email)).meta.diluxone_users_sso_mock, 'nothing linked').toBe('');

		await page.goto(RULES);
		await box(page, 'diluxone_users_sso_link_by_email').check();
		await savePanel(page);

		await comeBackFromTheNetwork(guest, pages.login.url);
		await expectSignedIn(guest, email);

		const after = await site.user(email);

		expect(after.id, 'the same account').toBe(existing.id);
		expect(after.meta.diluxone_users_sso_mock).toBe('mock|rules-link');
	});

	test('“refuse when the e-mail is not said to be verified” ticked: a provider that says nothing creates nobody', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		await page.goto(RULES);
		await box(page, 'diluxone_users_sso_verified_only').check();
		await savePanel(page);
		await page.reload();
		await expect(box(page, 'diluxone_users_sso_verified_only')).toBeChecked();
		expect(Number((await site.getOptions(['diluxone_users_sso_verified_only'])).diluxone_users_sso_verified_only)).toBe(1);

		const email = freshEmail('rules-verified');

		await site.setIdentity({ sub: 'mock|rules-silent', email });
		await comeBackFromTheNetwork(guest, pages.login.url);

		await expectSignedOut(guest);
		expect(stateOf(guest.url())).toBe('social');
		expect((await site.user(email)).exists, 'no account').toBe(false);

		// Unticked: the same provider makes the account.
		await page.goto(RULES);
		await box(page, 'diluxone_users_sso_verified_only').uncheck();
		await savePanel(page);

		await comeBackFromTheNetwork(guest, pages.login.url);
		await expectSignedIn(guest, email);
	});

	test('“only some roles”, subscriber ticked: an editor with the network linked is sent to the other ways in, a subscriber comes in', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		await page.goto(RULES);
		await page.locator('input[name="diluxone_users_sso_scope"][value="some"]').check();

		for (const one of await page.locator('input[name="diluxone_users_sso_roles[]"]').all()) {
			await one.uncheck();
		}

		await page.locator('input[name="diluxone_users_sso_roles[]"][value="subscriber"]').check();
		await savePanel(page);
		await page.reload();
		await expect(page.locator('input[name="diluxone_users_sso_scope"][value="some"]')).toBeChecked();
		await expect(page.locator('input[name="diluxone_users_sso_roles[]"][value="subscriber"]')).toBeChecked();
		expect(await site.getOptions(['diluxone_users_sso_scope', 'diluxone_users_sso_roles'])).toEqual({
			diluxone_users_sso_scope: 'some',
			diluxone_users_sso_roles: ['subscriber'],
		});

		const editor = freshEmail('rules-editor');

		await site.makeUser({ email: editor, password: PASSWORD, role: 'editor', meta: { diluxone_users_sso_mock: 'mock|rules-editor' } });
		await site.setIdentity({ sub: 'mock|rules-editor', email: editor, email_verified: true });
		await comeBackFromTheNetwork(guest, pages.login.url);
		await expectSignedOut(guest);
		expect(stateOf(guest.url())).toBe('social');

		const subscriber = freshEmail('rules-subscriber');

		await site.makeUser({ email: subscriber, password: PASSWORD, meta: { diluxone_users_sso_mock: 'mock|rules-subscriber' } });
		await site.setIdentity({ sub: 'mock|rules-subscriber', email: subscriber, email_verified: true });
		await guest.context().clearCookies();
		await comeBackFromTheNetwork(guest, pages.login.url);
		await expectSignedIn(guest, subscriber);
	});

	test('the rail says whether a social sign-in creates the account, as Registration decides, and leads there', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_users_sso_register: 0 });
		await page.goto(RULES);
		expect(await stateIn(rail(page))).toBe('off');

		await options.set({ diluxone_users_sso_register: 1 });
		await page.goto(RULES);
		expect(await stateIn(rail(page))).toBe('active');

		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-login&tab=register"]').click());
		expect(new URL(page.url()).searchParams.get('tab')).toBe('register');

		await page.goto(RULES);
		await navigated(page, () => rail(page).locator('a[href*="page=diluxone-users-design&tab=social"]').click());
		expect(new URL(page.url()).searchParams.get('page')).toBe('diluxone-users-design');
	});
});
