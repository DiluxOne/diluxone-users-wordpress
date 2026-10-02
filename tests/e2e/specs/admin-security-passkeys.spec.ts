import type { Page, Response } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { accountSection, adminUrl, openPanel, savePanel } from '../support/ui';
import { notNow, personSignedIn, rail, stateIn, summaryRow } from '../support/admin-ops';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Security › Passkeys on a site, driven through its own controls.
 *
 * Which passkeys are accepted and whether the fingerprint is required are
 * proven where they land: in the options the browser is handed when somebody
 * starts adding a passkey or signing in with one — `authenticatorAttachment`
 * and `userVerification` — which are what the authenticator obeys. And the
 * tab's own manners: it never writes the switch that lives on Access, it says
 * so while passkeys are off, and its rail names the domain they are tied to.
 */

test.use({ storageState: ADMIN_STATE });

const SECURITY = 'diluxone-users-security';

const where = (page: Page, value: string) => page.locator(`input[name="diluxone_users_passkey_where"][value="${value}"]`);
const verify = (page: Page) => page.locator('input[name="diluxone_users_passkey_verify"]');

/** The answer to one step of the passkey dialogue the page has with the site. */
function passkeyStep(step: string) {
	return (response: Response) =>
		response.url().includes('admin-ajax.php') && (response.request().postData() ?? '').includes(`step=${step}`);
}

/** What the site tells the browser when this person starts adding a passkey. */
async function registrationOptions(person: Page, accountUrl: string): Promise<Record<string, unknown>> {
	await person.goto(accountSection(accountUrl, 'security'));

	const panel = await openPanel(person, '[data-diluxone-users-passkey="register"]');
	const [answer] = await Promise.all([
		person.waitForResponse(passkeyStep('register-options')),
		panel.locator('[data-diluxone-users-passkey="register"]').click(),
	]);
	const body = await answer.json();

	expect(body.success, 'the site answers the first step').toBe(true);

	return body.data as Record<string, unknown>;
}

test.describe('Security › Passkeys on a site', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_passkey_where', 'diluxone_users_passkey_verify', 'diluxone_users_passkey_enabled']);
		await options.set({ diluxone_users_passkey_enabled: 1, diluxone_users_login_method: 'both', diluxone_users_2fa_mode: 'optional' });
	});

	test('“only the device being used” is what the browser is told to accept, and “any” takes it away again', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_passkey_where: 'any' });

		const person = await personSignedIn(browser, baseURL!, site, pages.login.url, 'pk-where');

		try {
			await page.goto(adminUrl(SECURITY, 'passkeys'));
			await where(page, 'device').check();
			await savePanel(page);
			await page.reload();
			await expect(where(page, 'device')).toBeChecked();
			expect((await site.getOptions(['diluxone_users_passkey_where'])).diluxone_users_passkey_where).toBe('device');

			expect((await registrationOptions(person.page, pages.account.url)).authenticatorAttachment, 'the device itself').toBe('platform');

			await page.goto(adminUrl(SECURITY, 'passkeys'));
			await where(page, 'any').check();
			await savePanel(page);
			expect((await site.getOptions(['diluxone_users_passkey_where'])).diluxone_users_passkey_where).toBe('any');

			expect((await registrationOptions(person.page, pages.account.url)).authenticatorAttachment, 'anything').toBeNull();

			await page.goto(adminUrl(SECURITY, 'summary'));
			await expect(summaryRow(page, SECURITY, 'passkeys', 0)).toBeVisible();
		} finally {
			await person.context.close();
		}
	});

	test('“require the fingerprint, the face or the PIN” is `required` on adding and on signing in, and unticked `preferred`', async ({
		page,
		browser,
		baseURL,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_passkey_verify: 0 });

		const person = await personSignedIn(browser, baseURL!, site, pages.login.url, 'pk-verify');

		/** What the sign-in page tells a stranger's browser. */
		const loginOptions = async () => {
			await guest.goto(pages.login.url);

			const [answer] = await Promise.all([
				guest.waitForResponse(passkeyStep('login-options')),
				guest.locator('[data-diluxone-users-passkey="login"]').click(),
			]);

			return (await answer.json()).data as Record<string, unknown>;
		};

		try {
			await page.goto(adminUrl(SECURITY, 'passkeys'));
			await verify(page).check();
			await savePanel(page);
			await page.reload();
			await expect(verify(page)).toBeChecked();
			expect(Number((await site.getOptions(['diluxone_users_passkey_verify'])).diluxone_users_passkey_verify)).toBe(1);

			expect((await registrationOptions(person.page, pages.account.url)).userVerification).toBe('required');
			expect((await loginOptions()).userVerification).toBe('required');

			await page.goto(adminUrl(SECURITY, 'passkeys'));
			await verify(page).uncheck();
			await savePanel(page);
			await page.reload();
			await expect(verify(page)).not.toBeChecked();
			expect(Number((await site.getOptions(['diluxone_users_passkey_verify'])).diluxone_users_passkey_verify)).toBe(0);

			expect((await registrationOptions(person.page, pages.account.url)).userVerification).toBe('preferred');
			expect((await loginOptions()).userVerification).toBe('preferred');
		} finally {
			await person.context.close();
		}
	});

	test('saving this tab leaves the switch on Access alone, on and off', async ({ page, site, options }) => {
		await page.goto(adminUrl(SECURITY, 'passkeys'));

		// The switch is not on this tab at all: it is Access's.
		await expect(page.locator('input[name="diluxone_users_passkey_enabled"]')).toHaveCount(0);

		await where(page, 'device').check();
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_passkey_enabled'])).diluxone_users_passkey_enabled), 'still on').toBe(1);

		await options.set({ diluxone_users_passkey_enabled: 0 });
		await page.goto(adminUrl(SECURITY, 'passkeys'));
		await where(page, 'any').check();
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_passkey_enabled'])).diluxone_users_passkey_enabled), 'still off').toBe(0);
	});

	test('while they are off the tab says so and sends to Access › Ways in; the rail says off, and on names the domain', async ({
		page,
		baseURL,
		options,
	}) => {
		await options.set({ diluxone_users_passkey_enabled: 0 });
		await page.goto(adminUrl(SECURITY, 'passkeys'));

		await expect(notNow(page)).toHaveCount(1);
		await expect(notNow(page).locator('a')).toHaveAttribute('href', /page=diluxone-users-login&tab=ways/);
		expect(await stateIn(rail(page))).toBe('off');
		await expect(rail(page).locator('a[href*="page=diluxone-users-login&tab=ways"]')).toHaveCount(1);

		await options.set({ diluxone_users_passkey_enabled: 1 });
		await page.goto(adminUrl(SECURITY, 'passkeys'));

		await expect(notNow(page)).toHaveCount(0);
		expect(await stateIn(rail(page))).toBe('active');
		// The domain a passkey made here is tied to: this site's host.
		await expect(rail(page).locator('.du-note').first().locator('code')).toHaveText(new URL(baseURL!).hostname);
	});

	test('the summary counts the accounts that have one: a new one is one more', async ({ page, site }) => {
		const registered = () => summaryRow(page, 'diluxone-users', 'usage');
		const count = async () => Number(((await registered().locator('.diluxone-users-summary__detail').innerText()).match(/\d+/) ?? ['-1'])[0]);

		await page.goto(adminUrl(SECURITY, 'summary'));

		const before = await count();

		expect(before).toBeGreaterThanOrEqual(0);
		expect(await stateIn(registered())).toBe(before > 0 ? 'active' : 'pending');

		await site.makeUser({
			email: freshEmail('pk-count'),
			meta: { diluxone_users_passkeys: [{ id: `e2e-${Date.now()}`, label: 'e2e', created: Math.floor(Date.now() / 1000), rp: '' }] },
		});

		await page.goto(adminUrl(SECURITY, 'summary'));
		expect(await count()).toBe(before + 1);
		expect(await stateIn(registered())).toBe('active');
	});
});
