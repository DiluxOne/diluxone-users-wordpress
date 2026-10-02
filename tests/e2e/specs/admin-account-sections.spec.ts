import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { Site } from '../support/api';
import { accountSection, adminError, adminSaved, adminUrl, navigated, saveButton } from '../support/ui';
import { memberElsewhere, railNotice, signInMember, unique } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Account area › Sections, driven from the screen and proven on the account.
 *
 * A section of the site's own with its words, its content, its address and
 * the roles that see it; the plugin's own sections with content before or
 * instead of what they draw; the cards of the front page; the switches; the
 * order by dragging and by the button that works without a script; and the
 * refusals a person can run into on the screen itself.
 */

test.use({ storageState: ADMIN_STATE });

type Sections = Record<string, { label?: string; slug?: string; enabled?: number | boolean; position?: number; placement?: string; roles?: string[]; visibility?: string; content?: string; custom?: boolean }>;

async function sections(site: Site): Promise<Sections> {
	return ((await site.getOptions(['diluxone_users_account_sections'])).diluxone_users_account_sections ?? {}) as Sections;
}

const sectionUrl = (id: string) => adminUrl('diluxone-users-account', 'sections') + `&section=${id}`;

/** The section form's own fields. */
const box = (page: Page, name: string) => page.locator(`[name="diluxone_users_section_form[${name}]"]`);

/** Writes into the rich-text box through its Text tab, as a person pasting HTML does. */
async function writeContent(page: Page, html: string): Promise<void> {
	const textTab = page.locator('#diluxone-users-section-content-html');

	if (await textTab.isVisible()) {
		await textTab.click();
	}

	await page.locator('textarea#diluxone-users-section-content').fill(html);
}

/*
 * WordPress remembers which tab of the editor somebody used last, per person,
 * in a cookie it copies to their user settings — so the Text tab pressed above
 * would open every editor of the administrator's from then on, the dashboard's
 * pictures included. Each test puts the Visual tab back as the one remembered.
 */
test.afterEach(async ({ page }) => {
	await page.goto(adminUrl('diluxone-users-account', 'sections'));
	await page.evaluate(() => (window as unknown as { setUserSetting?: (name: string, value: string) => void }).setUserSetting?.('editor', 'tinymce'));
	await page.goto(adminUrl('diluxone-users-account', 'sections'));
});

/** Saves the section form and returns the message and section in the address. */
async function saveSection(page: Page): Promise<{ msg: string; section: string }> {
	await navigated(page, () => page.locator('[data-diluxone-users-save] [form="diluxone-users-section-form"], #diluxone-users-section-form [data-diluxone-users-save] .du-save__button').first().click());

	const url = new URL(page.url());

	return { msg: url.searchParams.get('diluxone_users_msg') ?? '', section: url.searchParams.get('section') ?? '' };
}

/** A new section of the site's own, through the form. */
async function newSection(page: Page, fields: { label: string; slug?: string; intro?: string; content?: string }): Promise<string> {
	await page.goto(sectionUrl('diluxone-users-new'));
	await box(page, 'label').fill(fields.label);

	if (fields.slug !== undefined) {
		await box(page, 'slug').fill(fields.slug);
	}

	if (fields.intro !== undefined) {
		await box(page, 'intro').fill(fields.intro);
	}

	if (fields.content !== undefined) {
		await writeContent(page, fields.content);
	}

	const saved = await saveSection(page);

	expect(saved.msg, `the section “${fields.label}” is saved`).toBe('saved');

	return saved.section;
}

const tab = (page: Page, slug: string) => page.locator(`a.diluxone-users-account__tab[href*="/${slug}/"]`);

test.beforeEach(async ({ options }) => {
	await options.keep(['diluxone_users_account_sections', 'diluxone_users_home_cards_off']);
});

test.describe('Account area › a section of the site’s own', () => {
	test('its own content is the section, shortcodes run, and the hidden placement is “replace”', async ({ page, guest, site, pages }) => {
		const label = unique('E2E Mis cursos');
		const id = await newSection(page, { label, content: '<p class="e2e-own">Hola</p>[diluxone_users_sessions]' });

		expect((await sections(site))[id]).toMatchObject({ label, placement: 'replace', custom: true });

		await signInMember(guest, site, pages.login.url, 'own-content');
		await guest.goto(accountSection(pages.account.url, (await sections(site))[id].slug!));

		const body = guest.locator('.diluxone-users-account__section');

		await expect(body.locator('p.e2e-own')).toHaveText('Hola');
		await expect(body.locator('.diluxone-users-sessions'), 'the shortcode inside it ran').toHaveCount(1);
	});

	test('renamed, it keeps its id and its address; the menu shows the new name only', async ({ page, guest, site, pages }) => {
		const label = unique('E2E Antes');
		const id = await newSection(page, { label, intro: 'e2e-old-intro' });
		const slug = (await sections(site))[id].slug!;
		const renamed = unique('E2E Despues');

		await page.goto(sectionUrl(id));
		await expect(page.locator('input[name="diluxone_users_section_form[id]"]')).toHaveValue(id);
		await box(page, 'label').fill(renamed);
		await box(page, 'intro').fill('e2e-new-intro');

		const saved = await saveSection(page);

		expect(saved).toEqual({ msg: 'saved', section: id });
		await expect(adminSaved(page)).toBeVisible();

		const stored = await sections(site);

		expect(stored[id]).toMatchObject({ label: renamed, slug, intro: 'e2e-new-intro' });
		expect(Object.values(stored).filter((one) => one.label === label), 'no second section under the old name').toHaveLength(0);

		await signInMember(guest, site, pages.login.url, 'rename-section');
		await guest.goto(pages.account.url);
		await expect(tab(guest, slug)).toHaveText(renamed);
		await expect(guest.locator('a.diluxone-users-account__tab', { hasText: label })).toHaveCount(0);

		await guest.goto(accountSection(pages.account.url, slug));
		await expect(guest.locator('.diluxone-users-account__section')).toContainText('e2e-new-intro');
	});

	test('its address is the one written: served there, shown under the box, and refused for a second section', async ({ page, guest, site, pages }) => {
		const slug = `e2e-cursos-${Date.now().toString(36)}`;
		const label = unique('E2E Cursos');
		const id = await newSection(page, { label, slug });

		expect((await sections(site))[id].slug).toBe(slug);

		// The help under the address is a link to it, once the section exists.
		await expect(page.locator(`a[href*="/${slug}/"][target="_blank"]`)).toHaveCount(1);

		await signInMember(guest, site, pages.login.url, 'address');
		await guest.goto(accountSection(pages.account.url, slug));
		await expect(tab(guest, slug)).toHaveClass(/is-current/);
		await expect(guest.locator('.diluxone-users-account__section')).toContainText(label);

		// A second section on the same address is one that could never be opened.
		const before = await sections(site);

		await page.goto(sectionUrl('diluxone-users-new'));
		await box(page, 'label').fill(unique('E2E Otro'));
		await box(page, 'slug').fill(slug);
		expect((await saveSection(page)).msg).toBe('error');
		await expect(adminError(page)).toBeVisible();
		expect(await sections(site)).toEqual(before);

		// Nor the address of one of the plugin's own.
		await page.goto(sectionUrl('diluxone-users-new'));
		await box(page, 'label').fill(unique('E2E Seguridad'));
		await box(page, 'slug').fill('security');
		expect((await saveSection(page)).msg).toBe('error');
		expect(await sections(site)).toEqual(before);

		// Nor a section with no name.
		await page.goto(sectionUrl('diluxone-users-new'));
		await box(page, 'label').evaluate((input: HTMLInputElement) => input.removeAttribute('required'));
		await box(page, 'label').fill('');
		expect((await saveSection(page)).msg).toBe('error');
		expect(await sections(site)).toEqual(before);
	});

	test('“only some roles”: an editor sees it, a subscriber has no tab and its address opens another section', async ({ page, guest, site, pages, browser, baseURL }) => {
		const label = unique('E2E Editores');
		const id = await newSection(page, { label, intro: 'e2e-editors-only' });
		const slug = (await sections(site))[id].slug!;

		await page.goto(sectionUrl(id));
		await page.locator('input[name="diluxone_users_section_form[visibility]"][value="some"]').check({ force: true });
		await page.locator('input[name="diluxone_users_section_form[roles][]"][value="editor"]').setChecked(true, { force: true });
		expect((await saveSection(page)).msg).toBe('saved');
		expect((await sections(site))[id]).toMatchObject({ visibility: 'some', roles: ['editor'] });

		await signInMember(guest, site, pages.login.url, 'roles-sub');
		await guest.goto(pages.account.url);
		await expect(tab(guest, slug)).toHaveCount(0);
		await guest.goto(accountSection(pages.account.url, slug));
		await expect(guest.locator('.diluxone-users-account__section')).not.toContainText('e2e-editors-only');

		const editor = await memberElsewhere(browser, baseURL!, site, pages.login.url, 'roles-ed', { role: 'editor' });

		try {
			await editor.page.goto(accountSection(pages.account.url, slug));
			await expect(tab(editor.page, slug)).toHaveClass(/is-current/);
			await expect(editor.page.locator('.diluxone-users-account__section')).toContainText('e2e-editors-only');
		} finally {
			await editor.close();
		}

		// Back to everybody: the roles ticked are kept for the day it changes again.
		await page.goto(sectionUrl(id));
		await page.locator('input[name="diluxone_users_section_form[visibility]"][value="all"]').check({ force: true });
		expect((await saveSection(page)).msg).toBe('saved');
		expect((await sections(site))[id]).toMatchObject({ visibility: 'all', roles: ['editor'] });

		await guest.goto(pages.account.url);
		await expect(tab(guest, slug)).toHaveCount(1);
	});
});

test.describe('Account area › the plugin’s own sections', () => {
	test('content “before” goes above what the section draws; “instead of it” replaces it', async ({ page, guest, site, pages }) => {
		await page.goto(sectionUrl('security'));
		await box(page, 'placement').selectOption('before');
		await writeContent(page, '<p class="e2e-before">Antes</p>');
		expect((await saveSection(page)).msg).toBe('saved');
		expect((await sections(site)).security).toMatchObject({ placement: 'before' });

		await signInMember(guest, site, pages.login.url, 'placement');
		await guest.goto(accountSection(pages.account.url, 'security'));

		const body = guest.locator('.diluxone-users-account__section');

		await expect(body.locator('.e2e-before')).toHaveCount(1);
		await expect(body.locator('.diluxone-users-sessions')).toHaveCount(1);

		const above = await body.evaluate((root) => {
			const mine = root.querySelector('.e2e-before')!;
			const theirs = root.querySelector('.diluxone-users-sessions')!;

			return Boolean(mine.compareDocumentPosition(theirs) & Node.DOCUMENT_POSITION_FOLLOWING);
		});

		expect(above, 'the site’s words come first').toBe(true);

		await page.goto(sectionUrl('security'));
		await box(page, 'placement').selectOption('after');
		expect((await saveSection(page)).msg).toBe('saved');

		await guest.goto(accountSection(pages.account.url, 'security'));
		expect(
			await body.evaluate((root) =>
				Boolean(root.querySelector('.diluxone-users-sessions')!.compareDocumentPosition(root.querySelector('.e2e-before')!) & Node.DOCUMENT_POSITION_FOLLOWING)
			),
			'“after” puts them under it'
		).toBe(true);

		await page.goto(sectionUrl('security'));
		await box(page, 'placement').selectOption('replace');
		expect((await saveSection(page)).msg).toBe('saved');

		await guest.goto(accountSection(pages.account.url, 'security'));
		await expect(body.locator('.e2e-before')).toHaveCount(1);
		await expect(body.locator('.diluxone-users-sessions'), 'replaced: the plugin draws nothing').toHaveCount(0);
	});

	test('a section from code has no Remove, and a delete built by hand with a good nonce removes nothing', async ({ page, guest, site, pages }) => {
		await page.goto(sectionUrl('security'));

		await expect(page.locator('a[href*="diluxone_users_action=delete"]')).toHaveCount(0);

		const toggle = await page.locator('a.diluxone-users-toggle').getAttribute('href');

		await page.goto(toggle!.replace(/diluxone_users_action=(on|off)/, 'diluxone_users_action=delete'));

		expect((await sections(site)).security ?? { kept: true }, 'nothing was taken out').not.toBeUndefined();

		await signInMember(guest, site, pages.login.url, 'code-delete');
		await guest.goto(pages.account.url);
		await expect(tab(guest, 'security')).toHaveCount(1);
	});

	test('switched off and on again from its toggle: the tab goes and comes back', async ({ page, guest, site, pages }) => {
		await signInMember(guest, site, pages.login.url, 'toggle');

		await page.goto(sectionUrl('notifications'));

		const toggle = page.locator('a.diluxone-users-toggle');

		await expect(toggle).toHaveClass(/is-on/);
		await navigated(page, () => toggle.click());
		await expect(toggle).not.toHaveClass(/is-on/);
		expect(new URL(page.url()).searchParams.get('section')).toBe('notifications');
		expect((await sections(site)).notifications?.enabled).toBeFalsy();

		await guest.goto(pages.account.url);
		await expect(tab(guest, 'notifications')).toHaveCount(0);

		await navigated(page, () => toggle.click());
		await expect(toggle).toHaveClass(/is-on/);
		expect(new URL(page.url()).searchParams.get('tab')).toBe('sections');
		expect(new URL(page.url()).searchParams.get('section')).toBe('notifications');
		expect(Boolean((await sections(site)).notifications?.enabled)).toBe(true);

		await guest.goto(pages.account.url);
		await expect(tab(guest, 'notifications')).toHaveCount(1);
	});

	test('on, but with nothing to show: the screen says so above the form', async ({ page, options }) => {
		await options.set({ diluxone_users_privacy_export: 0, diluxone_users_privacy_delete: 0 });

		await page.goto(sectionUrl('privacy'));
		await expect(page.locator('#diluxone-users-section-form .du-notice--warning')).toHaveCount(1);

		await options.set({ diluxone_users_privacy_export: 1 });
		await page.goto(sectionUrl('privacy'));
		await expect(page.locator('#diluxone-users-section-form .du-notice--warning')).toHaveCount(0);
	});
});

test.describe('Account area › the front page’s cards', () => {
	test('a card unticked leaves the front page; all unticked, every one is stored off; editing another section keeps the list', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_home_cards_off: [] });
		await signInMember(guest, site, pages.login.url, 'cards');

		await guest.goto(accountSection(pages.account.url, 'home'));
		await expect(guest.locator('a.diluxone-users-card-summary[href*="/security/"]')).toHaveCount(1);

		await page.goto(sectionUrl('home'));

		const cards = page.locator('input[name="diluxone_users_section_form[cards][]"]');
		const ids = await cards.evaluateAll((all) => all.map((one) => (one as HTMLInputElement).value));

		expect(ids).toContain('security');

		await page.locator('input[name="diluxone_users_section_form[cards][]"][value="security"]').setChecked(false, { force: true });
		expect((await saveSection(page)).msg).toBe('saved');
		expect((await site.getOptions(['diluxone_users_home_cards_off'])).diluxone_users_home_cards_off).toEqual(['security']);

		await guest.goto(accountSection(pages.account.url, 'home'));
		await expect(guest.locator('a.diluxone-users-card-summary[href*="/security/"]')).toHaveCount(0);

		for (const id of ids) {
			await page.locator(`input[name="diluxone_users_section_form[cards][]"][value="${id}"]`).setChecked(false, { force: true });
		}

		expect((await saveSection(page)).msg).toBe('saved');

		const off = (await site.getOptions(['diluxone_users_home_cards_off'])).diluxone_users_home_cards_off as string[];

		expect([...off].sort(), 'none ticked is “show none”, not “never asked”').toEqual([...ids].sort());

		await guest.goto(accountSection(pages.account.url, 'home'));
		await expect(guest.locator('a.diluxone-users-card-summary')).toHaveCount(0);

		await page.goto(sectionUrl('details'));
		await box(page, 'intro').fill('e2e-details-intro');
		expect((await saveSection(page)).msg).toBe('saved');
		expect(((await site.getOptions(['diluxone_users_home_cards_off'])).diluxone_users_home_cards_off as string[]).sort(), 'another section’s save leaves the cards alone').toEqual(
			[...ids].sort()
		);
	});
});

test.describe('Account area › the order', () => {
	test('dragging a section to the top saves the new order at once, and the account follows it', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-account', 'sections'));

		const rows = page.locator('[data-diluxone-users-sortable] > li');
		const ids = () => rows.locator('input[type="hidden"]').evaluateAll((all) => all.map((one) => (one as HTMLInputElement).value));
		const order = await ids();

		expect(order[0], 'security does not start at the top').not.toBe('security');

		// Security is shown to everybody, so the account can say where it went.
		const security = rows.filter({ has: page.locator('input[type="hidden"][value="security"]') });

		await navigated(page, () => security.dragTo(rows.first(), { targetPosition: { x: 10, y: 2 } }));

		expect(new URL(page.url()).searchParams.get('diluxone_users_msg')).toBe('order');
		await expect(adminSaved(page)).toBeVisible();

		const stored = await sections(site);
		const now = await ids();

		expect(now).toEqual(['security', ...order.filter((id) => id !== 'security')]);
		expect(now.map((id) => stored[id]?.position), 'positions rewritten in steps of ten').toEqual(now.map((_, n) => (n + 1) * 10));

		await signInMember(guest, site, pages.login.url, 'drag');
		await guest.goto(pages.account.url);
		// The first section is the account page itself: it opens on Security now.
		await expect(guest.locator('a.diluxone-users-account__tab').first(), 'the account opens its menu with it').toHaveClass(/is-current/);
		await expect(guest.locator('.diluxone-users-account__section .diluxone-users-sessions')).toHaveCount(1);
		await expect(guest.locator('a.diluxone-users-account__tab[href*="/home/"]'), 'and the front page has an address of its own').toHaveCount(1);
	});

	test('“Save the order” works with no script at all', async ({ page, site }) => {
		await page.goto(adminUrl('diluxone-users-account', 'sections'));

		const order = await page.locator('[data-diluxone-users-sortable] input[type="hidden"]').evaluateAll((all) => all.map((one) => (one as HTMLInputElement).value));
		const reversed = [...order].reverse();

		// What dragging does to the DOM, done by hand: the rows in the new order.
		await page.locator('[data-diluxone-users-sortable]').evaluate((list) => {
			[...list.children].reverse().forEach((row) => list.appendChild(row));
		});
		await navigated(page, () => page.locator('.diluxone-users-endpoints__save-order button').click());

		expect(new URL(page.url()).searchParams.get('diluxone_users_msg')).toBe('order');

		const stored = await sections(site);

		expect(reversed.map((id) => stored[id]?.position)).toEqual(reversed.map((_, n) => (n + 1) * 10));
	});
});

test.describe('Account area › Your data', () => {
	test('the delete switch alone keeps the section with only the erase request; both off, the section is gone', async ({ page, guest, site, pages, options }) => {
		await options.keep(['diluxone_users_privacy_export', 'diluxone_users_privacy_delete']);
		await options.set({ diluxone_users_privacy_export: 0, diluxone_users_privacy_delete: 0 });

		await signInMember(guest, site, pages.login.url, 'privacy-delete');

		const save = () => navigated(page, () => page.locator('[data-diluxone-users-save] [form="diluxone-users-privacy-form"]').click());

		await page.goto(sectionUrl('privacy'));
		await page.locator('input[name="diluxone_users_privacy_delete"]').setChecked(true, { force: true });
		await save();
		expect(new URL(page.url()).searchParams.get('diluxone_users_msg')).toBe('privacy');
		const stored = await site.getOptions(['diluxone_users_privacy_export', 'diluxone_users_privacy_delete']);

		expect([Number(stored.diluxone_users_privacy_export), Number(stored.diluxone_users_privacy_delete)]).toEqual([0, 1]);

		await guest.goto(accountSection(pages.account.url, 'privacy'));
		await expect(tab(guest, 'privacy')).toHaveClass(/is-current/);
		await expect(guest.locator('input[name="diluxone_users_request"][value="erase"]')).toHaveCount(1);
		await expect(guest.locator('input[name="diluxone_users_request"][value="export"]')).toHaveCount(0);

		await page.locator('input[name="diluxone_users_privacy_delete"]').setChecked(false, { force: true });
		await save();
		expect(Number((await site.getOptions(['diluxone_users_privacy_delete'])).diluxone_users_privacy_delete)).toBe(0);

		await guest.goto(pages.account.url);
		await expect(tab(guest, 'privacy')).toHaveCount(0);
	});
});

test.describe('Account area › no account page', () => {
	test('the sections tab warns in the rail and links to the tab where the page is chosen', async ({ page, options }) => {
		await options.set({ diluxone_users_account_page: 0 });

		await page.goto(adminUrl('diluxone-users-account', 'sections'));

		const warning = railNotice(page, 'warning');

		await expect(warning).toHaveCount(1);
		await expect(warning.locator('a')).toHaveAttribute('href', /page=diluxone-users-account&tab=page/);
	});
});

test.describe('Account area › the box that saves a section', () => {
	test('changing the section or the data switches marks its own box unsaved, and leaving asks', async ({ page }) => {
		await page.goto(sectionUrl('privacy'));

		const privacyBox = page.locator('[data-diluxone-users-save]').filter({ has: page.locator('[form="diluxone-users-privacy-form"]') });
		const sectionBox = page.locator('[data-diluxone-users-save]').filter({ has: page.locator('[form="diluxone-users-section-form"]') });

		const exportBox = page.locator('input[name="diluxone_users_privacy_export"]');
		const was = await exportBox.isChecked();

		await expect(privacyBox).not.toHaveClass(/is-dirty/);
		await exportBox.setChecked(!was, { force: true });
		await expect(privacyBox).toHaveClass(/is-dirty/);

		// Undo puts the switch back, and the box is clean again.
		await privacyBox.locator('.du-save__undo').click();
		await expect(exportBox).toBeChecked({ checked: was });
		await expect(privacyBox).not.toHaveClass(/is-dirty/);

		const intro = unique('e2e-dirty');

		await box(page, 'intro').fill(intro);
		await expect(sectionBox).toHaveClass(/is-dirty/);

		let asked = '';

		page.once('dialog', (dialog) => {
			asked = dialog.message() || dialog.type();
			void dialog.dismiss();
		});
		await page.locator('.nav-tab-wrapper a.nav-tab').first().click();

		expect(asked, 'leaving with unsaved changes asks first').not.toBe('');
		await expect(box(page, 'intro')).toHaveValue(intro);
		expect(new URL(page.url()).searchParams.get('section')).toBe('privacy');
	});
});
