import { Page } from '@playwright/test';
import { test, expect, expectSignedIn, stateOf } from '../support/fixtures';
import { freshEmail, waitForMail } from '../support/api';
import { accountSection, navigated, openAllPanels } from '../support/ui';
import { PASSWORD, devWp, nonceOf, postByHand, signedIn, wpFails } from '../support/my-account';

/**
 * The account's other doors on a single site: the linked social accounts
 * (as the account area and its shortcode draw them), the person in the site
 * menu, "Join this site" where there is nothing to join, a theme's own copy
 * of a template, and the plugin's WP-CLI commands against the dev site.
 */

const MOCK_SSO = {
	diluxone_e2e_sso: 1,
	diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
	diluxone_users_sso_login: 1,
	diluxone_users_sso_register: 1,
	diluxone_users_sso_link_by_email: 1,
	diluxone_users_sso_verified_only: 0,
};

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: [{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' }],
		diluxone_users_2fa_mode: 'off',
		diluxone_users_account_sections: {},
	});
});

/** The notices about the account, not the sign-in link. */
async function securityMails(site: any, email: string) {
	return (await site.mail(email)).filter((one: { body: string }) => !one.body.includes('diluxone_users_token'));
}

test.describe('Linked accounts', () => {
	test.beforeEach(async ({ options }) => {
		await options.set(MOCK_SSO);
	});

	const mockRow = (page: Page) => page.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' });

	test('linking says so on the page and by mail; unlinking is mailed too', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'l-mails');

		await site.setIdentity({ sub: `mock|l-${Date.now().toString(36)}`, email: freshEmail('l-other'), email_verified: true });
		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);
		await navigated(page, () => mockRow(page).locator('a.diluxone-users-button').click());

		expect(stateOf(page.url())).toBe('linked');
		await expect(page.locator('.diluxone-users-accounts .diluxone-users-notice--ok')).toBeVisible();

		const linked = await securityMails(site, email);

		expect(linked.length, 'linking is mailed').toBe(1);
		expect(linked[0].body).toContain('Mock');

		await openAllPanels(page);
		await navigated(page, () => page.locator('.diluxone-users-linked__item.is-linked').filter({ hasText: 'Mock' }).locator('button[type="submit"]').click());
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('');

		const unlinked = await securityMails(site, email);

		expect(unlinked.length, 'unlinking is mailed').toBe(2);
		expect(unlinked[1].body).toContain('Mock');
		expect(unlinked[1].body).not.toBe(unlinked[0].body);
	});

	test('an identity that already opens another account is not linked to this one', async ({ page, site, pages }) => {
		const owner = freshEmail('l-owner');
		const sub = `mock|shared-${Date.now().toString(36)}`;

		await site.makeUser({ email: owner, password: PASSWORD, meta: { diluxone_users_sso_mock: sub } });

		const { email } = await signedIn(page, site, pages.login.url, 'l-taker');

		await site.setIdentity({ sub, email: freshEmail('l-whoever'), email_verified: true });
		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);
		await navigated(page, () => mockRow(page).locator('a.diluxone-users-button').click());

		expect(stateOf(page.url())).toBe('taken');
		await expect(page.locator('.diluxone-users-accounts .diluxone-users-notice--error')).toBeVisible();
		expect((await site.user(email)).meta.diluxone_users_sso_mock).toBe('');
		expect((await site.user(owner)).meta.diluxone_users_sso_mock).toBe(sub);
		await expectSignedIn(page, email);
	});

	test('unlinking a provider that is not on the list touches nothing and mails nobody', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'l-forged', { meta: { diluxone_users_sso_mock: 'mock|kept', diluxone_users_sso_zzz: 'keep-me' } });

		await page.goto(accountSection(pages.account.url, 'accounts'));
		await openAllPanels(page);

		await postByHand(page, { action: 'diluxone_users_sso_unlink', _wpnonce: await nonceOf(page, 'diluxone_users_sso_unlink'), diluxone_users_provider: 'zzz' });

		const after = await site.user(email, ['diluxone_users_sso_zzz']);

		expect(after.fields.diluxone_users_sso_zzz).toBe('keep-me');
		expect(after.meta.diluxone_users_sso_mock).toBe('mock|kept');
		expect(await securityMails(site, email)).toEqual([]);
	});

	test('the shortcode split in two lists, with their empty words, and a link made from it comes back to its page', async ({ page, site, pages, options }) => {
		const slug = 'my-account-linked';
		const piece = await site.page(slug, '<div id="e2e-linked">[diluxone_users_accounts only="linked"]</div><div id="e2e-available">[diluxone_users_accounts only="available"]</div>');

		try {
			const { email } = await signedIn(page, site, pages.login.url, 'l-split');
			const linked = page.locator('#e2e-linked');
			const available = page.locator('#e2e-available');

			await page.goto(piece.url);
			await expect(linked.locator('.diluxone-users-linked__item')).toHaveCount(0);
			await expect(linked.locator('.diluxone-users-note')).toBeVisible();
			await expect(available.locator('.diluxone-users-linked__item').filter({ hasText: 'Mock' })).toHaveCount(1);

			const noneYet = await linked.locator('.diluxone-users-note').innerText();

			// Linked from this page: back on this page, said once.
			await site.setIdentity({ sub: `mock|split-${Date.now().toString(36)}`, email: freshEmail('l-split-other'), email_verified: true });
			await navigated(page, () => available.locator('.diluxone-users-linked__item a.diluxone-users-button').click());

			expect(new URL(page.url()).pathname).toBe(new URL(piece.url).pathname);
			expect(stateOf(page.url())).toBe('linked');
			await expect(page.locator('.diluxone-users-notice--ok'), 'one notice for the two lists').toHaveCount(1);
			expect((await site.user(email)).meta.diluxone_users_sso_mock).not.toBe('');

			await expect(linked.locator('.diluxone-users-linked__item.is-linked')).toHaveCount(1);
			await expect(available.locator('.diluxone-users-linked__item')).toHaveCount(0);
			await expect(available.locator('.diluxone-users-note')).toBeVisible();
			expect(await available.locator('.diluxone-users-note').innerText(), '"all linked" is another sentence').not.toBe(noneYet);

			// No provider at all: the bare shortcode says so.
			await site.page(slug, '<div id="e2e-bare">[diluxone_users_accounts]</div>');
			await options.set({ diluxone_e2e_sso: 0, diluxone_users_sso: {} });
			await page.goto(piece.url);
			await expect(page.locator('#e2e-bare .diluxone-users-linked__item')).toHaveCount(0);
			await expect(page.locator('#e2e-bare .diluxone-users-note')).toBeVisible();
		} finally {
			await site.forgetPage(slug);
		}
	});
});

test.describe('The person in the site menu', () => {
	const item = (page: Page, kind: string) => page.locator(`nav.diluxone-e2e-menu li.diluxone-users-menu--${kind}`);
	let menuUrl = '';

	test.beforeEach(async ({ site, options }) => {
		menuUrl = (await site.menu()).url;
		await options.set({ diluxone_users_menu_location: 'diluxone-e2e', diluxone_users_menu_style: 'avatar-name' });
	});

	test('an editor gets the dashboard item; a subscriber does not', async ({ page, browser, baseURL, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'm-editor', { role: 'editor' });
		await page.goto(menuUrl);
		await expect(item(page, 'dashboard')).toHaveCount(1);
		await expect(item(page, 'dashboard').locator('a')).toHaveAttribute('href', /\/wp-admin\/$/);

		const other = await (await browser.newContext({ baseURL, storageState: undefined })).newPage();

		await signedIn(other, site, pages.login.url, 'm-sub');
		await other.goto(menuUrl);
		await expect(item(other, 'dashboard')).toHaveCount(0);
		await other.context().close();
	});

	test('the sections under the name follow the ones turned off or kept from the role', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_privacy_export: 1,
			diluxone_users_account_sections: {
				privacy: { enabled: 0 },
				staff: { custom: 1, label: 'Staff room', content: '<p>x</p>', position: 80, visibility: 'some', roles: ['editor'] },
			},
		});
		await signedIn(page, site, pages.login.url, 'm-sections');
		await page.goto(menuUrl);

		const person = item(page, 'person');

		await expect(person.locator(`a[href="${accountSection(pages.account.url, 'security')}"]`)).toHaveCount(1);
		await expect(person.locator('a[href*="/privacy/"]')).toHaveCount(0);
		await expect(person.locator('a[href*="/staff/"]')).toHaveCount(0);
	});

	test('the menu’s own style is printed only for somebody signed in, and only with a location', async ({ page, site, pages, options }) => {
		const style = page.locator('style#diluxone-users-menu-inline-css');

		await page.goto(menuUrl);
		await expect(style, 'a stranger').toHaveCount(0);

		await signedIn(page, site, pages.login.url, 'm-style');
		await page.goto(menuUrl);
		await expect(style, 'signed in').toHaveCount(1);

		await options.set({ diluxone_users_menu_location: '' });
		await page.goto(menuUrl);
		await expect(style, 'no location').toHaveCount(0);
	});
});

test.describe('Join, templates and WP-CLI on a single site', () => {
	test('“Join this site” posted by hand on a single site has no door to knock on', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'j-single');
		const before = await site.user(email);
		const answer = await postByHand(page, { action: 'diluxone_users_join' });

		expect(answer.status, 'admin-post.php with no handler answers 400').toBe(400);
		expect(answer.state).toBe('');

		const after = await site.user(email);

		expect(after.roles).toEqual(before.roles);
	});

	test('a theme’s own copy of a template is drawn instead of the plugin’s', async ({ page, pages }) => {
		const marker = `e2e-theme-guest-${Date.now().toString(36)}`;
		const write = `$d = get_stylesheet_directory() . '/diluxone-users'; wp_mkdir_p( $d ); file_put_contents( $d . '/account-guest.php', '<div id="${marker}">theme copy</div>' );`;
		const remove = `$d = get_stylesheet_directory() . '/diluxone-users'; @unlink( $d . '/account-guest.php' ); @rmdir( $d );`;

		devWp(['eval', write]);

		try {
			await page.goto(pages.account.url);
			await expect(page.locator(`#${marker}`)).toBeVisible();
			await expect(page.locator('.diluxone-users-account--guest')).toHaveCount(0);
		} finally {
			devWp(['eval', remove]);
		}
	});

	test('wp diluxone-users login prints a link that opens a session, once', async ({ page, browser, baseURL, site }) => {
		const email = freshEmail('cli-login');

		await site.makeUser({ email, password: PASSWORD });

		const out = devWp(['diluxone-users', 'login', email]);
		const link = (out.match(/https?:\/\/\S+/) ?? [''])[0];

		expect(link, out).toContain('diluxone_users_token');

		await page.goto(link);
		await expectSignedIn(page, email);

		// The same link a second time, in another browser: spent.
		const other = await (await browser.newContext({ baseURL, storageState: undefined })).newPage();

		await other.goto(link);
		expect(await other.request.get('/wp-admin/profile.php').then((r) => new URL(r.url()).pathname)).toContain('wp-login.php');
		await other.context().close();
	});

	test('wp diluxone-users login --send mails the link', async ({ page, site }) => {
		const email = freshEmail('cli-send');

		await site.makeUser({ email, password: PASSWORD });

		const out = devWp(['diluxone-users', 'login', email, '--send']);

		expect(out).toContain('E-mail sent.');

		const mail = await waitForMail(site, email);

		expect(mail.body).toContain('diluxone_users_token');
	});

	test('wp diluxone-users login refuses an address that is not one, or that nobody has', () => {
		const bad = wpFails('cli', ['diluxone-users', 'login', 'not-an-email']);

		expect(bad.failed).toBe(true);
		expect(bad.out).toContain('A valid e-mail address is needed.');

		const nobody = freshEmail('cli-nobody');
		const unknown = wpFails('cli', ['diluxone-users', 'login', nobody]);

		expect(unknown.failed).toBe(true);
		expect(unknown.out).toContain('There is no account with the e-mail');
	});

	test('the network commands on a single site say it is not a network', () => {
		for (const command of [
			['diluxone-users', 'network', 'migrate'],
			['diluxone-users', 'network', 'membership', 'sync'],
		]) {
			const answer = wpFails('cli', command);

			expect(answer.failed, command.join(' ')).toBe(true);
			expect(answer.out, command.join(' ')).toContain('This is not a network');
		}
	});
});

