import type { Locator, Page } from '@playwright/test';
import { test, expect, stateOf } from '../support/fixtures';
import { adminUrl, answeringDialog, savePanel, ssoButton } from '../support/ui';
import { MOCK_SSO, numbersIn, rail } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Access › Messages: the languages, an emptied box, and the count in the rail.
 *
 * login-screen.spec.ts proves one rewrite reaches a stranger and the way back
 * to the plugin's words. This is the rest: each language keeps its own
 * wording and moving between them saves nothing; an emptied box is the
 * plugin's words again; the rail counts what this site wrote.
 */

test.use({ storageState: ADMIN_STATE });

const KEY = 'login_social';
const MINE_ES = 'E2E: esa red no respondió, probá con tu correo.';
const MINE_EN = 'E2E: that network did not answer, try your e-mail.';

async function messageField(page: Page, key = KEY): Promise<Locator> {
	const box = page.locator(`details[data-diluxone-users-rewritable="${key}"]`);

	if (!(await box.evaluate((element: HTMLDetailsElement) => element.open))) {
		await box.locator('> summary').click();
	}

	const field = box.locator(`textarea[name="diluxone_users_message[${key}]"]`);

	await expect(field).toBeVisible();

	return field;
}

/** The rail's first line: how many messages this site wrote, in this language. */
function stateLine(page: Page): Locator {
	return rail(page).locator('.du-state__line');
}

test.describe('Access › Messages', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_login_messages']);
		await options.set({ ...MOCK_SSO, diluxone_users_sso_login: 1, diluxone_users_2fa_mode: 'off', diluxone_users_login_messages: [] });
	});

	test('each language keeps its own wording, the rail counts it, and a stranger reads the one of the site’s language', async ({ page, guest, site, pages, options }) => {
		await page.goto(adminUrl('diluxone-users-login', 'messages'));

		const locale = await page.locator('input[name="diluxone_users_message_locale"]').inputValue();

		expect(numbersIn(await stateLine(page).innerText()), 'nothing written yet: no count').toEqual([]);

		await (await messageField(page)).fill(MINE_ES);
		await savePanel(page);

		expect(numbersIn(await stateLine(page).innerText())[0], 'one message of this site’s own').toBe(1);

		// The other language, by the link in the rail: its box holds what the
		// plugin ships there, not what was just written here.
		const other = rail(page).locator('a[href*="tab=messages"][href*="lang="]').first();
		const otherLocale = new URL((await other.getAttribute('href'))!, page.url()).searchParams.get('lang')!;

		expect(otherLocale).not.toBe(locale);

		await other.click();
		await expect(page).toHaveURL(new RegExp(`lang=${otherLocale}`));
		await expect(page.locator('input[name="diluxone_users_message_locale"]')).toHaveValue(otherLocale);

		const there = await messageField(page);

		await expect(there).not.toHaveValue(MINE_ES);
		expect((await there.inputValue()).trim()).not.toBe('');
		expect(numbersIn(await stateLine(page).innerText()), 'the other language counts its own, which is none').toEqual([]);

		await there.fill(MINE_EN);
		await savePanel(page);
		await expect(page, 'the save came back to the language it was typed in').toHaveURL(new RegExp(`lang=${otherLocale}`));
		await expect(await messageField(page)).toHaveValue(MINE_EN);

		const stored = (await site.getOptions(['diluxone_users_login_messages'])).diluxone_users_login_messages as Record<string, Record<string, string>>;

		expect(stored[locale][KEY]).toBe(MINE_ES);
		expect(stored[otherLocale][KEY]).toBe(MINE_EN);

		// A stranger on this site reads the site language's wording.
		await site.setIdentity({ deny: true });
		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();
		await guest.waitForURL(/diluxone-users=/);
		expect(stateOf(guest.url())).toBe('social');
		await expect(guest.locator(`[data-diluxone-users-message="${KEY}"]`)).toHaveText(locale === 'en_US' ? MINE_EN : MINE_ES);

		// And on a site in the other language, the other one.
		await options.set({ WPLANG: otherLocale === 'en_US' ? '' : otherLocale });
		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();
		await guest.waitForURL(/diluxone-users=/);
		await expect(guest.locator(`[data-diluxone-users-message="${KEY}"]`)).toHaveText(otherLocale === 'en_US' ? MINE_EN : MINE_ES);
	});

	test('moving to another language with something typed asks first, and saves nothing', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-login', 'messages'));

		await (await messageField(page)).fill('E2E: typed and never saved.');

		const other = rail(page).locator('a[href*="tab=messages"][href*="lang="]').first();

		await answeringDialog(page, 'dismiss', () => other.click());
		await expect(page).not.toHaveURL(/lang=/);
		await expect(await messageField(page)).toHaveValue('E2E: typed and never saved.');

		const stored = (await site.getOptions(['diluxone_users_login_messages'])).diluxone_users_login_messages;

		expect(stored === null || (Array.isArray(stored) && stored.length === 0) || Object.keys(stored as object).length === 0, 'something was saved by moving away').toBe(true);
	});

	test('an emptied box is the plugin’s words again, and the way back goes with the rewrite', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-login', 'messages'));

		const shipped = (await (await messageField(page)).inputValue()).trim();

		await (await messageField(page)).fill(MINE_ES);
		await savePanel(page);
		await expect(page.locator(`input[name="diluxone_users_message_shipped[${KEY}]"]`)).toBeAttached();

		await (await messageField(page)).fill('');
		await savePanel(page);

		await expect(await messageField(page)).toHaveValue(shipped);
		await expect(page.locator(`input[name="diluxone_users_message_shipped[${KEY}]"]`)).toHaveCount(0);
		expect(numbersIn(await stateLine(page).innerText())).toEqual([]);

		const stored = (await site.getOptions(['diluxone_users_login_messages'])).diluxone_users_login_messages as Record<string, Record<string, string>> | unknown[] | null;

		expect(JSON.stringify(stored ?? {}), 'the emptied message is still stored').not.toContain(KEY);
	});
});
