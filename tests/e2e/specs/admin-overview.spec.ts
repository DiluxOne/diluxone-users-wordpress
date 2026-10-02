import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, signInWithPassword } from '../support/ui';
import { MOCK_SSO, PLAIN_FIELDS, expectPill, numbersIn, rail } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The Overview: the four cards, the first steps, and the three panels under
 * them — what people use, how they get in, what is asked of them.
 *
 * Every number is read off the card or the row and compared with what the
 * test itself changed: a user made, a session opened, a field switched on, a
 * provider tested. The cards are cached for a quarter of an hour, which is
 * asked too; every test starts with the plugin's transients forgotten.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Overview-1!';

/** A card's value, by the screen its link goes to (or users.php). */
function cardFor(page: Page, href: string): Locator {
	return page.locator('.diluxone-users-card').filter({ has: page.locator(`.diluxone-users-card__links a[href*="${href}"]`) });
}

async function cardNumbers(page: Page, href: string): Promise<number[]> {
	return numbersIn((await cardFor(page, href).locator('.diluxone-users-card__value').innerText()).replace(/[.,](?=\d{3}\b)/g, ''));
}

async function forget(site: import('../support/api').Site): Promise<void> {
	await site.setOptions({}, { forgetTransients: true });
}

test.describe('Overview › the cards', () => {
	test('accounts and open sessions are counted, kept for a while, and counted again once the cache goes', async ({ page, guest, site }) => {
		await page.goto(adminUrl('diluxone-users'));

		const [accounts] = await cardNumbers(page, 'users.php');
		const [sessions] = await cardNumbers(page, 'page=diluxone-users-security');

		const one = freshEmail('ov-a');
		const two = freshEmail('ov-b');

		await site.makeUser({ email: one, password: PASSWORD });
		await site.makeUser({ email: two, password: PASSWORD });

		await guest.goto('/wp-login.php');
		await signInWithPassword(guest, one, PASSWORD);
		await expect.poll(async () => (await site.user(one)).sessions).toBe(1);

		// Within the quarter of an hour, the same numbers.
		await page.reload();
		expect((await cardNumbers(page, 'users.php'))[0], 'the accounts card is not cached').toBe(accounts);

		await forget(site);
		await page.reload();
		expect((await cardNumbers(page, 'users.php'))[0]).toBe(accounts + 2);
		expect((await cardNumbers(page, 'page=diluxone-users-security'))[0]).toBe(sessions + 1);
	});

	test('fields in use are the active ones of those defined, and the social card counts the providers that work', async ({ page, options }) => {
		await options.set({
			...MOCK_SSO,
			diluxone_users_fields: [...PLAIN_FIELDS, { key: 'e2e_off', label: 'Off', type: 'text', required: 0, active: 0, group: 'extra', edit: 'always' }],
		});

		await page.goto(adminUrl('diluxone-users'));
		expect(await cardNumbers(page, 'page=diluxone-users-fields')).toEqual([2, 3]);

		const [ready, total] = await cardNumbers(page, 'page=diluxone-users-social');

		expect(ready).toBeGreaterThanOrEqual(1);

		// The mock untested: one fewer that works, the same number in all.
		await options.set({ diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 0 } } });
		await page.reload();
		expect(await cardNumbers(page, 'page=diluxone-users-social')).toEqual([ready - 1, total]);
	});

	test('each card leads to the screen its number is about', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users'));

		for (const href of ['users.php', 'page=diluxone-users-security', 'page=diluxone-users-fields', 'page=diluxone-users-social']) {
			await expect(cardFor(page, href), href).toHaveCount(1);
		}

		await expect(page.locator('.diluxone-users-card')).toHaveCount(4);
	});
});

test.describe('Overview › first steps', () => {
	test('listed while one is pending, the button only on the first undone, and gone once all three are done', async ({ page, pages, options }) => {
		await options.keep(['diluxone_users_mail_last']);
		await options.set({ diluxone_users_login_page: 0, diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000), error: '' } });

		await page.goto(adminUrl('diluxone-users'));

		const steps = page.locator('ol.diluxone-users-steps > li');

		await expect(steps).toHaveCount(3);
		await expect(steps.nth(0)).not.toHaveClass(/is-done/);
		await expect(steps.nth(0).locator('a.button-primary')).toHaveAttribute('href', /page=diluxone-users-login/);
		await expect(steps.nth(1)).toHaveClass(/is-done/);
		await expect(steps.nth(2)).toHaveClass(/is-done/);
		await expect(page.locator('ol.diluxone-users-steps a.button-primary')).toHaveCount(1);

		// The sign-in page chosen, the account page not, and mail never tested.
		await options.set({ diluxone_users_login_page: pages.login.id, diluxone_users_account_page: 0, diluxone_users_mail_last: null });
		await page.reload();
		await expect(steps.nth(0)).toHaveClass(/is-done/);
		await expect(steps.nth(1).locator('a.button-primary')).toHaveAttribute('href', /page=diluxone-users-account/);
		await expect(steps.nth(2)).not.toHaveClass(/is-done/);
		await expect(steps.nth(2).locator('a.button-primary'), 'the button belongs to the first undone step only').toHaveCount(0);

		await options.set({ diluxone_users_account_page: pages.account.id, diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000), error: '' } });
		await page.reload();
		await expect(page.locator('ol.diluxone-users-steps')).toHaveCount(0);
	});
});

test.describe('Overview › what your people use', () => {
	test('each row is how many of the accounts chose it, and the bar is that share', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users', 'usage'));

		const rows = page.locator('.diluxone-users-usage__row');

		await expect(rows).toHaveCount(4);

		const read = async () =>
			Promise.all(
				(await rows.all()).map(async (row) => {
					const [count, total, share] = numbersIn((await row.locator('.diluxone-users-usage__count').innerText()).replace(/[.,](?=\d{3}\b)/g, ''));
					const width = await row.locator('.diluxone-users-usage__fill').evaluate((fill: HTMLElement) => fill.style.width);

					return { count, total, share, width };
				})
			);

		const before = await read();

		// One with a public name and an authenticator, one with neither.
		await site.makeUser({ email: freshEmail('use-a'), meta: { diluxone_users_handle: 'e2e-use', diluxone_users_totp: 'JBSWY3DPEHPK3PXP' } });
		await site.makeUser({ email: freshEmail('use-b') });

		await page.reload();

		const after = await read();

		// The rows: public name, social, authenticator, passkey.
		expect(after[0].count).toBe(before[0].count + 1);
		expect(after[1].count).toBe(before[1].count);
		expect(after[2].count).toBe(before[2].count + 1);
		expect(after[3].count).toBe(before[3].count);

		for (const row of after) {
			expect(row.total).toBe(before[0].total + 2);
			expect(row.share).toBe(Math.round((row.count / row.total) * 100));
			expect(row.width).toBe(`${row.share}%`);
		}
	});

	test('its rail leads to social login, Access and the list of people', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users', 'usage'));

		for (const href of ['page=diluxone-users-social', 'page=diluxone-users-login', 'users.php']) {
			await expect(rail(page).locator(`a[href*="${href}"]`), href).toHaveCount(1);
		}
	});
});

test.describe('Overview › how your people get in', () => {
	test('the rows follow the method, the mail and whether any door is open', async ({ page, options }) => {
		await options.keep(['diluxone_users_mail_last']);
		// No social provider ready either, whatever the site has set up: with
		// one working, somebody still gets in while the mail fails.
		await options.set({
			diluxone_users_login_method: 'link',
			diluxone_e2e_sso: 0,
			diluxone_users_sso: {},
			diluxone_users_passkey_enabled: 0,
			diluxone_users_mail_last: { ok: 0, time: Math.floor(Date.now() / 1000), error: 'e2e-mail-boom' },
		});

		await page.goto(adminUrl('diluxone-users', 'doors'));

		const rows = page.locator('table.diluxone-users-summary tbody tr');

		await expect(rows).toHaveCount(4);
		await expectPill(rows.nth(0), 'active');
		await expect(rows.nth(1).locator('a')).toHaveAttribute('href', /tab=ways/);

		const linkOnly = await rows.nth(1).locator('.diluxone-users-summary__detail').innerText();

		// Mail failing, and the link the only way in: nobody gets in.
		await expectPill(rows.nth(2), 'off');
		await expect(rows.nth(2)).toContainText('e2e-mail-boom');
		await expectPill(rows.nth(3), 'off');

		await options.set({ diluxone_users_login_method: 'both', diluxone_users_mail_last: { ok: 1, time: Math.floor(Date.now() / 1000), error: '' } });
		await page.reload();

		expect(await rows.nth(1).locator('.diluxone-users-summary__detail').innerText(), 'the method row did not change with the method').not.toBe(linkOnly);
		await expectPill(rows.nth(2), 'active');
		await expectPill(rows.nth(3), 'active');

		// The page row is the maintenance check's: a page never chosen waits.
		await options.set({ diluxone_users_login_page: 0 });
		await page.reload();
		await expectPill(rows.nth(0), 'pending');

		for (const href of ['page=diluxone-users-login', 'page=diluxone-users-notices']) {
			await expect(rail(page).locator(`a[href*="${href}"]`), href).toHaveCount(1);
		}
	});
});

test.describe('Overview › what is asked of them', () => {
	test('the active fields, under their group; with none, only the address', async ({ page, options }) => {
		await options.set({
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 0, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 0, group: 'main', edit: 'always' },
				{ key: 'e2e_town', label: 'E2E Town', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'e2e_hidden', label: 'E2E Hidden', type: 'text', required: 0, active: 0, group: 'extra', edit: 'always' },
			],
		});

		await page.goto(adminUrl('diluxone-users', 'asked'));

		const table = page.locator('.diluxone-users-panel table.diluxone-users-summary');

		await expect(table.locator('tr')).toHaveCount(1);
		await expect(table.locator('td')).toHaveText('E2E Town');
		await expect(table, 'an inactive field is listed').not.toContainText('E2E Hidden');

		for (const href of ['page=diluxone-users-fields', 'page=diluxone-users-account']) {
			await expect(rail(page).locator(`a[href*="${href}"]`), href).toHaveCount(1);
		}

		await options.set({
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 0, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 0, group: 'main', edit: 'always' },
			],
		});
		await page.reload();

		await expect(page.locator('.diluxone-users-panel table.diluxone-users-summary')).toHaveCount(0);
		await expect(page.locator('.diluxone-users-panel .du-notice')).toHaveCount(1);
	});
});
