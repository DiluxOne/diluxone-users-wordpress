import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { accountSection, navigated } from '../support/ui';
import { PIXEL, anotherBrowser, formOf, reveal, send, signedIn } from '../support/my-account';

/**
 * The account page as a whole: which section opens for which address, what
 * the front page greets and sums up, what the header carries, what the menu
 * looks like, and where the site's own words go inside a section.
 *
 * Each test sets what a site would have saved and asserts what the person
 * sees on the page — the class the template prints, the tab that is current,
 * the form that is or is not drawn. Nothing is read by its wording: this site
 * runs in Spanish.
 */

/** Three fields, so the front page's "x/3" has something to count. */
const FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'e2e_city', label: 'City', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

const MOCK_SSO = {
	diluxone_e2e_sso: 1,
	diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
};

/** The tab that is open right now, by the `aria-current` the menu gives it. */
const current = (page: Page) => page.locator('a.diluxone-users-account__tab.is-current[aria-current="page"]');

/** The form that files a data request: what the "Your data" section draws. */
const dataForm = (page: Page) => page.locator('form:has(input[name="diluxone_users_request"])');

test.beforeEach(async ({ options }) => {
	await options.set({
		diluxone_users_fields: FIELDS,
		diluxone_users_2fa_mode: 'optional',
		diluxone_users_privacy_export: 1,
		diluxone_users_privacy_delete: 1,
		diluxone_users_account_sections: {},
		diluxone_users_home_cards_off: [],
		diluxone_users_account_layout: 'tabs',
		diluxone_users_account_header: 1,
		diluxone_users_account_avatar: 1,
		diluxone_users_account_since: 1,
		diluxone_users_account_action: 0,
		diluxone_users_account_template: 'plain',
	});
});

test.describe('Which section opens', () => {
	test('a section asked for with ?section= opens, as on a site without pretty addresses', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'sec-query');

		await page.goto(`${pages.account.url}?section=security`);

		await expect(current(page)).toHaveAttribute('href', /\/security\/$/);
		await expect(page.locator('.diluxone-users-account__section .diluxone-users-sessions, .diluxone-users-account__section [name="diluxone_users_security"]').first()).toBeAttached();
	});

	test('an address that names no section opens the first one, and says nothing broke', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'sec-unknown');

		const response = await page.goto(accountSection(pages.account.url, 'no-such-section'));

		expect(response?.status()).toBe(200);
		await expect(current(page), 'the first tab, Home, is the open one').toHaveAttribute('href', pages.account.url);
		await expect(page.locator('.diluxone-users-cards, .diluxone-users-account__section p').first()).toBeAttached();
		await expect(page.locator('body')).not.toContainText(/Warning:|Notice:|Fatal error/);
	});

	test('a section turned off is not drawn even at its own address', async ({ page, site, pages, options }) => {
		await signedIn(page, site, pages.login.url, 'sec-off');

		await page.goto(accountSection(pages.account.url, 'privacy'));
		await expect(dataForm(page).first(), 'drawn while it is on').toBeAttached();

		await options.set({ diluxone_users_account_sections: { privacy: { enabled: 0 } } });
		await page.goto(accountSection(pages.account.url, 'privacy'));

		await expect(dataForm(page)).toHaveCount(0);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/privacy/"]')).toHaveCount(0);
		await expect(current(page)).toHaveAttribute('href', pages.account.url);
	});

	test('a section for some roles only is not drawn at its address for the others, and is for them', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_account_sections: { privacy: { visibility: 'some', roles: ['editor'] } } });

		await signedIn(page, site, pages.login.url, 'sec-role-sub');
		await page.goto(accountSection(pages.account.url, 'privacy'));
		await expect(dataForm(page), 'a subscriber does not get it').toHaveCount(0);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/privacy/"]')).toHaveCount(0);

		const editor = await browser.newContext({ baseURL, storageState: undefined });
		const other = await editor.newPage();

		await signedIn(other, site, pages.login.url, 'sec-role-ed', { role: 'editor' });
		await other.goto(accountSection(pages.account.url, 'privacy'));
		await expect(dataForm(other).first(), 'an editor does').toBeAttached();
		await expect(current(other)).toHaveAttribute('href', /\/privacy\/$/);
		await editor.close();
	});

	test('with every section turned off, the page draws no empty shell', async ({ page, site, pages, options }) => {
		await options.set({
			diluxone_users_account_sections: Object.fromEntries(
				['home', 'details', 'security', 'notifications', 'privacy', 'accounts'].map((id) => [id, { enabled: 0 }])
			),
		});
		await signedIn(page, site, pages.login.url, 'sec-none');

		const response = await page.goto(pages.account.url);

		expect(response?.status()).toBe(200);
		await expect(page.locator('.diluxone-users-account')).toHaveCount(0);
		await expect(page.locator('body')).not.toContainText(/Warning:|Notice:|Fatal error/);
	});

	test('the Notifications section is not there when the site neither sends nor offers anything', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await signedIn(page, site, pages.login.url, 'sec-nonotify');
		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/notifications/"]'), 'there by default').toBeVisible();

		await options.set({
			diluxone_users_notice_rules: { diluxone_users_notify_login: 'never', diluxone_users_notify_security: 'never' },
			diluxone_users_login_method: 'password',
			diluxone_users_2fa_mode: 'off',
		});
		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/notifications/"]')).toHaveCount(0);
	});
});

test.describe('Home', () => {
	test('the heading greets the person by first name', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'home-hello', { meta: { first_name: 'Adalinda' } });

		await page.goto(pages.account.url);

		await expect(page.locator('.diluxone-users-account__title')).toContainText('Adalinda');
	});

	test('the cards count the details filled, the networks linked and the open sessions, and open their sections', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		await options.set(MOCK_SSO);

		const { email } = await signedIn(page, site, pages.login.url, 'home-cards', { meta: { e2e_city: 'Rosario' } });
		const second = await anotherBrowser(browser, baseURL, email, pages.login.url);

		await page.goto(pages.account.url);

		const card = (section: string) =>
			page.locator(`a.diluxone-users-card-summary[href="${accountSection(pages.account.url, section)}"]`);

		await expect(card('details').locator('.diluxone-users-card-summary__value')).toHaveText('1/3');
		await expect(card('security').locator('.diluxone-users-card-summary__value')).toHaveText('2');
		await expect(card('accounts').locator('.diluxone-users-card-summary__value')).toHaveText('0');

		await navigated(page, () => card('security').click());
		await expect(current(page)).toHaveAttribute('href', /\/security\/$/);
		await second.context().close();
	});

	test('a card the site hid is gone from the front page, and with every one hidden it says there is nothing yet', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ ...MOCK_SSO, diluxone_users_home_cards_off: ['security'] });
		await signedIn(page, site, pages.login.url, 'home-hidden');

		await page.goto(pages.account.url);
		await expect(page.locator(`a.diluxone-users-card-summary[href="${accountSection(pages.account.url, 'security')}"]`)).toHaveCount(0);
		await expect(page.locator(`a.diluxone-users-card-summary[href="${accountSection(pages.account.url, 'details')}"]`)).toHaveCount(1);
		await expect(page.locator(`a.diluxone-users-card-summary[href="${accountSection(pages.account.url, 'accounts')}"]`)).toHaveCount(1);

		await options.set({ diluxone_users_home_cards_off: ['security', 'details', 'accounts'] });
		await page.goto(pages.account.url);
		await expect(page.locator('.diluxone-users-cards')).toHaveCount(0);
		await expect(page.locator('.diluxone-users-account__section > p').first()).toBeVisible();
	});
});

test.describe('The header and the look', () => {
	test('the header carries the photo, the join date and the edit button only as the site chose, and none at all when it is off', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await signedIn(page, site, pages.login.url, 'look-header');

		const root = page.locator('.diluxone-users-account');

		await page.goto(pages.account.url);
		await expect(root.locator('.diluxone-users-account__avatar')).toHaveCount(1);
		await expect(root.locator('.diluxone-users-account__since')).toHaveCount(1);
		await expect(root.locator('.diluxone-users-account__action')).toHaveCount(0);

		await options.set({ diluxone_users_account_avatar: 0, diluxone_users_account_since: 0, diluxone_users_account_action: 1 });
		await page.goto(pages.account.url);
		await expect(root.locator('.diluxone-users-account__avatar')).toHaveCount(0);
		await expect(root.locator('.diluxone-users-account__since')).toHaveCount(0);
		await expect(root.locator('.diluxone-users-account__action')).toHaveAttribute('href', accountSection(pages.account.url, 'details'));

		await options.set({ diluxone_users_account_header: 0 });
		await page.goto(pages.account.url);
		await expect(root).toHaveClass(/diluxone-users-account--bare/);
		await expect(root.locator('.diluxone-users-account__header')).toHaveCount(0);
	});

	test('the cover carries its colour, and a picture only once one is chosen', async ({ page, site, pages, options }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'look-cover');
		const root = page.locator('.diluxone-users-account');

		await options.set({ diluxone_users_account_template: 'cover', diluxone_users_account_cover: '#123456', diluxone_users_account_cover_kind: 'color' });
		await page.goto(pages.account.url);
		await expect(root).toHaveClass(/diluxone-users-account--cover(\s|$)/);
		await expect(root).toHaveAttribute('style', /--diluxone-users-cover:#123456/);

		// A picture asked for and none chosen: the colour, not an empty band.
		await options.set({ diluxone_users_account_cover_kind: 'image', diluxone_users_account_cover_image: 0 });
		await page.goto(pages.account.url);
		await expect(root).not.toHaveClass(/--cover-image|--cover-dim/);

		// A real picture: uploaded as the person's photo, then chosen as the cover.
		await options.set({ diluxone_users_avatar_upload: 1 });
		await page.goto(accountSection(pages.account.url, 'details'));
		await reveal(page, 'form.diluxone-users-avatar__form');
		await page.locator('input[name="diluxone_users_avatar_file"]').setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: PIXEL });
		expect(await send(page, formOf(page, 'diluxone_users_avatar').locator('button[type="submit"]').first())).toBe('saved');

		const picture = Number((await site.user(email, ['diluxone_users_avatar'])).fields.diluxone_users_avatar);

		await options.set({ diluxone_users_account_cover_image: picture });
		await page.goto(pages.account.url);
		await expect(root).toHaveClass(/diluxone-users-account--cover-image/);
		await expect(root).toHaveAttribute('style', /--diluxone-users-cover-image:url\(/);

		await options.set({ diluxone_users_account_cover_kind: 'dim' });
		await page.goto(pages.account.url);
		await expect(root).toHaveClass(/diluxone-users-account--cover-dim/);

		// The plain shape carries no cover style at all.
		await options.set({ diluxone_users_account_template: 'plain' });
		await page.goto(pages.account.url);
		await expect(root).not.toHaveAttribute('style', /.+/);
	});

	test('the width and the menu’s look and alignment are classes the stylesheet reads; an unknown value is the default', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await signedIn(page, site, pages.login.url, 'look-nav');

		const root = page.locator('.diluxone-users-account');
		const nav = page.locator('.diluxone-users-account .diluxone-users-account__nav');

		await options.set({ diluxone_users_account_width: 'full' });
		await page.goto(pages.account.url);
		await expect(root).toHaveClass(/diluxone-users-account--full/);

		await options.set({ diluxone_users_account_width: 'contained' });
		await page.goto(pages.account.url);
		await expect(root).toHaveClass(/diluxone-users-account--contained/);

		for (const [style, align] of [
			['underline', 'center'],
			['plain', 'end'],
			['pills', 'start'],
		]) {
			await options.set({ diluxone_users_account_nav_style: style, diluxone_users_account_nav_align: align });
			await page.goto(pages.account.url);
			await expect(nav).toHaveClass(new RegExp(`diluxone-users-account__nav--${style}(\\s|$)`));
			await expect(nav).toHaveClass(new RegExp(`diluxone-users-account__nav--${align}(\\s|$)`));
		}

		await options.set({ diluxone_users_account_nav_style: 'zigzag', diluxone_users_account_nav_align: 'middle' });
		await page.goto(pages.account.url);
		await expect(nav).toHaveClass(/diluxone-users-account__nav--pills/);
		await expect(nav).toHaveClass(/diluxone-users-account__nav--start/);
	});

	test('the menu shortcode on a page of its own runs down the side when the area does', async ({ page, site, pages, options }) => {
		const slug = 'my-account-navonly';
		const piece = await site.page(slug, '[diluxone_users_account_nav]');

		try {
			await signedIn(page, site, pages.login.url, 'look-navcol');

			await options.set({ diluxone_users_account_layout: 'side' });
			await page.goto(piece.url);
			await expect(page.locator('.diluxone-users-account__nav')).toHaveClass(/diluxone-users-account__nav--column/);

			await options.set({ diluxone_users_account_layout: 'tabs' });
			await page.goto(piece.url);
			await expect(page.locator('.diluxone-users-account__nav')).toHaveClass(/diluxone-users-account__nav--row/);
		} finally {
			await site.forgetPage(slug);
		}
	});
});

test.describe('What the site writes into a section', () => {
	/** Where `#e2e-own` sits relative to the details form: -1 before, 1 after, 0 when one of them is missing. */
	async function order(page: Page): Promise<number> {
		return page.evaluate(() => {
			const own = document.querySelector('#e2e-own');
			const form = document.querySelector('input[name="action"][value="diluxone_users_fields_save"]');

			if (!own || !form) {
				return 0;
			}

			return own.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
		});
	}

	test('before, after or instead of what the code draws, with its shortcodes expanded', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_avatar_upload: 1 });
		await signedIn(page, site, pages.login.url, 'own-content');

		const content = '<div id="e2e-own">Own words [diluxone_users_avatar]</div>';
		const details = accountSection(pages.account.url, 'details');

		await options.set({ diluxone_users_account_sections: { details: { content, placement: 'before' } } });
		await page.goto(details);
		expect(await order(page), 'before the fields').toBe(-1);
		await expect(page.locator('#e2e-own form input[name="action"][value="diluxone_users_avatar"]'), 'the shortcode inside is drawn').toBeAttached();

		await options.set({ diluxone_users_account_sections: { details: { content, placement: 'after' } } });
		await page.goto(details);
		expect(await order(page), 'after the fields').toBe(1);

		await options.set({ diluxone_users_account_sections: { details: { content, placement: 'replace' } } });
		await page.goto(details);
		await expect(page.locator('#e2e-own')).toBeAttached();
		await expect(page.locator('input[name="action"][value="diluxone_users_fields_save"]'), 'the fields are replaced').toHaveCount(0);
	});

	test('a section of the site’s own for some roles: an editor has it, a subscriber does not, and with no role ticked nobody does', async ({
		page,
		browser,
		baseURL,
		site,
		pages,
		options,
	}) => {
		const mine = { custom: 1, label: 'Staff room', content: '<p id="e2e-staff">For staff</p>', position: 80 };

		await options.set({ diluxone_users_account_sections: { staff: { ...mine, visibility: 'some', roles: ['editor'] } } });

		await signedIn(page, site, pages.login.url, 'own-role-sub');
		await page.goto(pages.account.url);
		await expect(page.locator('a.diluxone-users-account__tab[href*="/staff/"]')).toHaveCount(0);
		await page.goto(accountSection(pages.account.url, 'staff'));
		await expect(page.locator('#e2e-staff')).toHaveCount(0);

		const editor = await browser.newContext({ baseURL, storageState: undefined });
		const other = await editor.newPage();

		await signedIn(other, site, pages.login.url, 'own-role-ed', { role: 'editor' });
		await other.goto(pages.account.url);

		const tab = other.locator('a.diluxone-users-account__tab[href*="/staff/"]');

		await expect(tab).toHaveText('Staff room');
		await navigated(other, () => tab.click());
		await expect(other.locator('#e2e-staff')).toBeVisible();

		await options.set({ diluxone_users_account_sections: { staff: { ...mine, visibility: 'some', roles: [] } } });
		await other.goto(pages.account.url);
		await expect(other.locator('a.diluxone-users-account__tab[href*="/staff/"]'), 'some roles, none ticked: nobody').toHaveCount(0);
		await editor.close();
	});

	test('a section renamed, moved first and given an address of its own is drawn that way', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_account_sections: { security: { label: 'Safety', position: 1, slug: 'safety' } } });
		await signedIn(page, site, pages.login.url, 'own-relabel');

		await page.goto(accountSection(pages.account.url, 'details'));

		const first = page.locator('a.diluxone-users-account__tab').first();

		await expect(first).toHaveText('Safety');

		// First in the menu is also the section the bare address opens.
		await expect(first).toHaveAttribute('href', pages.account.url);

		await page.goto(accountSection(pages.account.url, 'safety'));
		await expect(current(page)).toHaveText('Safety');
		await expect(page.locator('.diluxone-users-account__section [name="diluxone_users_security"], .diluxone-users-account__section .diluxone-users-sessions').first()).toBeAttached();
	});
});
