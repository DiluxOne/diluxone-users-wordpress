import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { notice, ssoButton } from '../support/ui';

/**
 * A social sign-in whose address the provider did not confirm, on a site that
 * asks for confirmed addresses: proved by mail instead of refused.
 *
 * The fake network answers from inside the site (the e2e mu-plugin), so the
 * test decides what it claims — here, an address with no `email_verified` —
 * and the confirmation arrives in the site's own mailbox.
 */

const MOCK_CREDENTIALS = {
	mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 },
};

/** The link a confirmation mail carries. */
const PENDING_LINK = /https?:\/\/\S*diluxone_users_sso_pending=\S+/;

/** The holding screen's masked address: the first letters and the ending, nothing more. */
function masked(email: string): string {
	const [local, domain] = email.split('@');

	return `${local[0]}•••@${domain[0]}•••${domain.slice(domain.lastIndexOf('.'))}`;
}

test.describe('A social address confirmed by mail', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: MOCK_CREDENTIALS,
			diluxone_users_sso_login: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_sso_link_by_email: 1,
			diluxone_users_sso_verified_only: 1,
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('a provider that does not confirm the address: nothing yet, a mail, and the holding screen', async ({
		page,
		site,
		pages,
	}) => {
		const email = freshEmail('sso-pending');

		// No `email_verified` claim at all.
		await site.setIdentity({ sub: `mock|${email}`, email, given_name: 'Grace' });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		expect(stateOf(page.url())).toBe('social-pending');
		expect(page.url(), 'the address never goes in a URL').not.toContain(encodeURIComponent(email));
		await expectSignedOut(page);

		await expect(page.locator('.diluxone-users-login__email')).toHaveText(masked(email));
		await expect(page.locator('body')).not.toContainText(email);
		await expect(page.locator('form input[name="action"][value="diluxone_users_sso_resend"]')).toHaveCount(1);

		expect((await site.user(email)).exists, 'no account until the link is opened').toBe(false);

		const mail = await waitForMail(site, email);

		expect(linkIn(mail, PENDING_LINK)).toMatch(/diluxone_users_token=/);
	});

	test('the link opened, in another browser: the account made, the identity linked, signed in where they were going', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		const email = freshEmail('sso-pending-open');

		await site.setIdentity({ sub: `mock|${email}`, email, given_name: 'Grace', family_name: 'Hopper' });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();
		expect(stateOf(page.url())).toBe('social-pending');

		// Opened on the phone, where the mail is read: the inbox is the proof.
		await guest.goto(linkIn(await waitForMail(site, email), PENDING_LINK));

		await expectSignedIn(guest, email);
		// Nothing filters diluxone_users_login_redirect here: the front page.
		expect(new URL(guest.url()).pathname, 'where a sign-in lands on this site').toBe('/');

		const made = await site.user(email);

		expect(made.exists).toBe(true);
		expect(made.meta.diluxone_users_sso_mock, 'the identity is written on the account').toBe(`mock|${email}`);
		expect(made.meta.first_name).toBe('Grace');
	});

	test('the link opens the account that already has the address', async ({ page, site, pages }) => {
		const email = freshEmail('sso-pending-known');
		const existing = await site.makeUser({ email, password: 'e2e-Pending-1!', name: 'Already Here' });

		await site.setIdentity({ sub: `mock|${email}`, email });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();
		expect(stateOf(page.url())).toBe('social-pending');
		expect((await site.user(email)).meta.diluxone_users_sso_mock, 'nothing linked before the link').toBe('');

		await page.goto(linkIn(await waitForMail(site, email), PENDING_LINK));

		await expectSignedIn(page, email);

		const after = await site.user(email);

		expect(after.id, 'the same account, not a second one').toBe(existing.id);
		expect(after.meta.diluxone_users_sso_mock).toBe(`mock|${email}`);
	});

	test('the link opened after it expired: the usual expired answer, and nothing made', async ({ page, site, pages }) => {
		const email = freshEmail('sso-pending-late');

		await site.setIdentity({ sub: `mock|${email}`, email });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		const link = linkIn(await waitForMail(site, email), PENDING_LINK);

		await site.expire(email, 'sso-pending');
		await page.goto(link);

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('expired');
		await expect(notice(page, 'error')).toBeVisible();
		expect((await site.user(email)).exists, 'nothing created').toBe(false);
	});

	test('with “Only verified addresses” off, silence signs in as before and nothing is mailed', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_sso_verified_only: 0 });

		const email = freshEmail('sso-pending-off');

		await site.setIdentity({ sub: `mock|${email}`, email });

		await page.goto(pages.login.url);
		await ssoButton(page, 'mock').click();

		await expectSignedIn(page, email);
		expect(stateOf(page.url())).not.toBe('social-pending');
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe(`mock|${email}`);
		expect(await site.mail(email), 'no confirmation mail').toEqual([]);
	});
});
