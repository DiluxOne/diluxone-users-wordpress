import { Page } from '@playwright/test';
import { test, expect, expectSignedIn } from '../support/fixtures';
import { Site, freshEmail, linkIn, waitForMail } from '../support/api';
import { adminUrl, askForLink, navigated, savePanel, ssoButton } from '../support/ui';
import { MOCK_ON } from '../support/signin';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The required fields an account came in without, asked for after a sign-in.
 *
 * An account made by the e-mail link or a social network never saw the
 * registration form. Once the first name is required, signing in sends it to
 * the account page drawing that field alone, and from there on to where it
 * was going; skipping it leaves the whole site open and asks again next time.
 */

/** The starter fields, with the first name required. */
const FIRST_NAME_REQUIRED = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 1, group: 'main', active: 1 },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, group: 'main', active: 1 },
];

/** The step: the account page, asked for it. */
function onStep(page: Page, accountUrl: string): boolean {
	const url = new URL(page.url());

	return url.href.startsWith(accountUrl) && url.searchParams.get('diluxone-users-complete') === '1';
}

/** Where the step says it goes on to. */
function goingTo(page: Page): string {
	return new URL(page.url()).searchParams.get('redirect_to') ?? '';
}

/** The step's form. */
function stepForm(page: Page) {
	return page.locator('.diluxone-users-complete form.diluxone-users-form');
}

/** Signs in by the link, start to end, and lands wherever the sign-in sends. */
async function signInByLink(page: Page, site: Site, loginUrl: string, email: string): Promise<void> {
	await askForLink(page, loginUrl, email);
	await page.goto(linkIn(await waitForMail(site, email)));
	await expectSignedIn(page, email);
}

test.describe('After signing in, the required fields missing', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_users_fields: FIRST_NAME_REQUIRED,
			diluxone_users_login_register: 1,
			diluxone_users_login_role: 'subscriber',
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('a new account by the e-mail link lands on the step with only the name, and its save goes on to where it was headed', async ({ page, site, pages }) => {
		const email = freshEmail('missing-link');

		await signInByLink(page, site, pages.login.url, email);

		expect(onStep(page, pages.account.url), `on the step, at ${page.url()}`).toBe(true);
		const going = goingTo(page);
		expect(going, 'carrying where the sign-in was going').not.toBe('');

		await expect(stepForm(page).locator('input[name="first_name"]')).toBeVisible();
		await expect(stepForm(page).locator('input[name="last_name"]'), 'only what is missing').toHaveCount(0);
		await expect(page.locator('nav[class*="diluxone-users-account"]'), 'in place of the sections and their nav').toHaveCount(0);

		// Required in the browser too: the same control as the account area's.
		await expect(stepForm(page).locator('input[name="first_name"]')).toHaveAttribute('required', /.*/);

		await stepForm(page).locator('input[name="first_name"]').fill('Ada');
		await navigated(page, () => stepForm(page).locator('button[type="submit"]').click());

		expect(page.url(), 'on to where it was headed').toBe(going);
		expect((await site.user(email, ['first_name'])).fields.first_name).toBe('Ada');
	});

	test('a new account by a social network that gave no name lands on the same step', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_ON,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_verified_only: 0,
		});

		const email = freshEmail('missing-sso');
		await site.setIdentity({ sub: `mock|${email}`, email, email_verified: true });

		await page.goto(pages.login.url);
		await navigated(page, () => ssoButton(page, 'mock').click());

		await expectSignedIn(page, email);
		expect(onStep(page, pages.account.url), `on the step, at ${page.url()}`).toBe(true);
		await expect(stepForm(page).locator('input[name="first_name"]')).toBeVisible();
	});
});
