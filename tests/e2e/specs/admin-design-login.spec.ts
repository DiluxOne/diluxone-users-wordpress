import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, askForLink, registerForm, savePanel, sentScreen, submitPluginForm } from '../support/ui';
import { previewAnswer, railNotice, stage, unique } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Design › Sign-in page and Registration page: the shape and every sentence,
 * each saved through its control and found on the public page by its class.
 * The split and full-background layouts are Users+ Pro's, and its own suite
 * covers them; here a card stands where they would go.
 */

test.use({ storageState: ADMIN_STATE });

const LOGIN_KEYS = [
	'diluxone_users_login_template',
	'diluxone_users_pro_previews',
	'diluxone_users_login_title',
	'diluxone_users_login_intro',
	'diluxone_users_login_legal',
	'diluxone_users_sent_title',
	'diluxone_users_sent_note',
	'diluxone_users_sent_icon',
];

const LOGIN_TAB = adminUrl('diluxone-users-design', 'login');
const box = (page: Page, name: string) => page.locator(`[name="${name}"]`);
const template = (page: Page, id: string) => page.locator(`input[name="diluxone_users_login_template"][value="${id}"]`);
const frame = (page: Page) => page.locator('.diluxone-users-login-frame');

test.describe('Design › The sign-in page', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(LOGIN_KEYS);
		await options.set({
			diluxone_users_login_template: 'plain',
			diluxone_users_pro_previews: 1,
			diluxone_users_login_title: '',
			diluxone_users_login_intro: '',
			diluxone_users_login_legal: '',
			diluxone_users_sent_title: '',
			diluxone_users_sent_note: '',
			diluxone_users_sent_icon: 'plain',
		});
	});

	test('the card, chosen on the tab, frames the sign-in page; “plain” leaves it alone', async ({ page, guest, site, pages }) => {
		await page.goto(LOGIN_TAB);
		await expect(page.locator('input[name="diluxone_users_login_template"]'), 'the three shapes of the plugin’s own').toHaveCount(3);
		await template(page, 'card').check({ force: true });
		await savePanel(page);
		await expect(template(page, 'card')).toBeChecked();
		expect((await site.getOptions(['diluxone_users_login_template'])).diluxone_users_login_template).toBe('card');

		await guest.goto(pages.login.url);
		await expect(frame(guest)).toHaveAttribute('class', 'diluxone-users-login-frame diluxone-users-login-frame--card');
		await expect(frame(guest)).not.toHaveAttribute('style', /./);

		await template(page, 'plain').check({ force: true });
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(frame(guest)).toHaveCount(0);
	});

	test('where Users+ Pro’s layouts would go: two shapes after the real ones, disabled, gone with the previews setting', async ({ page, options }) => {
		await page.goto(LOGIN_TAB);

		const card = page.locator('[data-diluxone-users-pro="design/login/shapes"]');

		await expect(card, 'the split and the full background').toHaveCount(2);
		await expect(card.first(), 'in the row of shapes').toHaveClass(/diluxone-users-templates__one/);
		await expect(page.locator('.diluxone-users-templates > *').last(), 'after the real shapes').toHaveAttribute('data-diluxone-users-pro', 'design/login/shapes');
		await expect(card.locator('input[type="radio"]'), 'nothing to pick').toHaveCount(2);
		await expect(card.locator('input[type="radio"]:disabled')).toHaveCount(2);
		await expect(card.locator('[name]'), 'nothing posts').toHaveCount(0);
		await expect(card.locator('.diluxone-users-state--pro'), 'the badge on each').toHaveCount(2);
		await expect(card.first().locator('a')).toHaveAttribute('href', /diluxone\.com\/users-pro\/.*utm_medium=design/);
		await expect(page.locator('input[name="diluxone_users_login_side"], input[name="diluxone_users_login_panel_title"], textarea[name="diluxone_users_login_panel_title"]'), 'nothing of theirs is a control here').toHaveCount(0);

		await options.set({ diluxone_users_pro_previews: 0 });
		await page.goto(LOGIN_TAB);
		await expect(card).toHaveCount(0);
	});

	test('a Pro layout stored while Pro was on is a card on the page and kept on the tab', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_template: 'split' });

		await guest.goto(pages.login.url);
		await expect(frame(guest)).toHaveAttribute('class', 'diluxone-users-login-frame diluxone-users-login-frame--card');
		await expect(guest.locator('.diluxone-users-login-frame__picture')).toHaveCount(0);

		await page.goto(LOGIN_TAB);
		await expect(template(page, 'split')).toBeChecked();
		await expect(page.locator('.diluxone-users-templates__one.is-chosen')).toContainText('Your Pro choice (inactive)');
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_login_template'])).diluxone_users_login_template).toBe('split');
	});

	test('a shape that is not on the list is not kept, and the page stays plain', async ({ page, guest, site, pages }) => {
		await page.goto(LOGIN_TAB);
		await template(page, 'card').evaluate((input: HTMLInputElement) => {
			input.value = 'e2e-forged';
			input.checked = true;
		});
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(frame(guest), 'read back, an unknown shape is the plain page').toHaveCount(0);

		expect.soft((await site.getOptions(['diluxone_users_login_template'])).diluxone_users_login_template, 'bug: the sign-in shape keeps a value from outside its list (admin-design.php diluxone_users_design_login_save stores sanitize_key() of whatever was posted)').not.toBe('e2e-forged');
	});

	test('a shape stored while an add-on offered it: a card on the page, an inactive Pro choice on the tab, kept by its save', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_template: 'e2e_addon_shape' });

		await guest.goto(pages.login.url);
		await expect(frame(guest), 'the closest shape of the plugin’s own that still takes the page over').toHaveClass(/diluxone-users-login-frame--card/);

		await page.goto(LOGIN_TAB);
		await expect(template(page, 'e2e_addon_shape')).toBeChecked();
		await expect(page.locator('.diluxone-users-templates__one.is-chosen')).toContainText('Your Pro choice (inactive)');
		await expect(template(page, 'card'), 'the card it is drawn as is not what is chosen').not.toBeChecked();

		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_login_template'])).diluxone_users_login_template).toBe('e2e_addon_shape');

		await template(page, 'plain').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_login_template'])).diluxone_users_login_template, 'replaced once another is picked').toBe('plain');
		await expect(page.locator('input[name="diluxone_users_login_template"][value="e2e_addon_shape"]')).toHaveCount(0);
	});

	test('the line under the heading is on the page, under the heading', async ({ page, guest, pages }) => {
		const heading = unique('E2E Bienvenida');
		const intro = unique('E2E primera vez');

		await page.goto(LOGIN_TAB);
		await box(page, 'diluxone_users_login_title').fill(heading);
		await box(page, 'diluxone_users_login_intro').fill(intro);
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(guest.locator('.diluxone-users-login__title')).toHaveText(heading);
		await expect(guest.locator('.diluxone-users-login__title + .diluxone-users-login__intro')).toHaveText(intro);
	});

	test('the terms line keeps its link and loses its script and its handlers', async ({ page, guest, site, pages }) => {
		await page.goto(LOGIN_TAB);
		await box(page, 'diluxone_users_login_legal').fill('<a href="/e2e-terms/" onclick="window.e2eClicked=1">E2E terms</a><script>window.e2eRan=1</script>');
		await savePanel(page);

		const stored = (await site.getOptions(['diluxone_users_login_legal'])).diluxone_users_login_legal as string;

		expect(stored).toContain('href="/e2e-terms/"');
		expect(stored).not.toMatch(/onclick|<script/i);

		await guest.goto(pages.login.url);

		const link = guest.locator('.diluxone-users-login__legal a');

		await expect(link).toHaveAttribute('href', '/e2e-terms/');
		await expect(link).not.toHaveAttribute('onclick', /./);
		expect(await guest.evaluate(() => (window as unknown as { e2eRan?: number }).e2eRan)).toBeUndefined();
	});

	test('after the link is sent: the title, the note and the circled envelope are the ones saved', async ({ page, guest, site, pages }) => {
		const title = unique('E2E Revisá tu correo');
		const note = unique('E2E mirá en spam');

		await page.goto(LOGIN_TAB);
		await box(page, 'diluxone_users_sent_title').fill(title);
		await box(page, 'diluxone_users_sent_note').fill(note);
		await page.locator('input[name="diluxone_users_sent_icon"][value="circle"]').check({ force: true });
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_sent_icon'])).diluxone_users_sent_icon).toBe('circle');

		const email = freshEmail('sent-words');

		await site.makeUser({ email });
		await askForLink(guest, pages.login.url, email);

		await expect(sentScreen(guest)).toBeVisible();
		await expect(guest.locator('.diluxone-users-login .diluxone-users-login__title')).toHaveText(title);
		await expect(guest.locator('.diluxone-users-note--icon span')).toHaveText(note);
		await expect(guest.locator('.diluxone-users-login__icon')).toHaveClass(/diluxone-users-login__icon--circle/);
	});

	test('a word box emptied is the plugin’s own sentence again — the one the box shows as its placeholder', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_sent_title: 'E2E something of the site’s' });

		await page.goto(LOGIN_TAB);

		const sentTitle = box(page, 'diluxone_users_sent_title');
		const shipped = await sentTitle.getAttribute('placeholder');

		expect(shipped, 'the box shows the plugin’s own sentence').toBeTruthy();

		await sentTitle.fill('');
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_sent_title'])).diluxone_users_sent_title).toBe('');

		const email = freshEmail('sent-empty');

		await site.makeUser({ email });
		await askForLink(guest, pages.login.url, email);
		await expect(guest.locator('.diluxone-users-login .diluxone-users-login__title')).toHaveText(shipped!);
	});

	test('the live preview: a heading typed on the tab is drawn in the stage before it is saved, and nothing is stored', async ({ page, site }) => {
		await page.goto(LOGIN_TAB);

		const heading = unique('E2E preview heading');
		const redraw = previewAnswer(page);

		await box(page, 'diluxone_users_login_title').fill(heading);
		await redraw;

		await expect.poll(async () => (await stage(page)).locator('.diluxone-users-login__title').textContent().catch(() => ''), { message: 'the stage shows it' }).toContain(heading);
		expect((await site.getOptions(['diluxone_users_login_title'])).diluxone_users_login_title).toBe('');
	});
});

test.describe('Design › The registration page', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep(['diluxone_users_register_title', 'diluxone_users_register_intro', 'diluxone_users_register_done']);
	});

	test('the line under the heading and the words once it is done are the ones saved', async ({ page, guest, pages, options }) => {
		// The field set pinned: a required field the site happens to have
		// would stop the browser sending the form.
		await options.set({
			diluxone_users_register_form: 1,
			diluxone_users_fields: [
				{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
				{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
			],
		});

		const intro = unique('E2E sumate');
		const done = unique('E2E listo');

		await page.goto(adminUrl('diluxone-users-design', 'register'));
		await box(page, 'diluxone_users_register_intro').fill(intro);
		await box(page, 'diluxone_users_register_done').fill(done);
		await savePanel(page);

		await guest.goto(pages.register.url);
		await expect(guest.locator('.diluxone-users-register .diluxone-users-login__intro')).toHaveText(intro);

		await guest.locator('input[name="diluxone_users_email"]').fill(freshEmail('reg-words'));
		expect(await submitPluginForm(guest, registerForm(guest))).toBe('registered');
		await expect(guest.locator('.diluxone-users-register .diluxone-users-login__title')).toHaveText(done);
	});

	test('while the site does not use its own form, the tab says so; with it on, it does not', async ({ page, options }) => {
		await options.set({ diluxone_users_register_form: 0 });
		await page.goto(adminUrl('diluxone-users-design', 'register'));
		await expect(railNotice(page, 'warning').or(page.locator('.diluxone-users-studio__fields .du-notice--info'))).toHaveCount(1);

		await options.set({ diluxone_users_register_form: 1 });
		await page.goto(adminUrl('diluxone-users-design', 'register'));
		await expect(page.locator('.diluxone-users-studio .du-notice')).toHaveCount(0);
	});

	test('the live preview: the heading typed is the stage’s heading before it is saved', async ({ page, site, options }) => {
		await options.set({ diluxone_users_register_form: 1, diluxone_users_register_title: '' });
		await page.goto(adminUrl('diluxone-users-design', 'register'));

		const heading = unique('E2E preview register');
		const redraw = previewAnswer(page);

		await box(page, 'diluxone_users_register_title').fill(heading);
		await redraw;

		await expect.poll(async () => (await stage(page)).locator('.diluxone-users-login__title').first().textContent().catch(() => ''), { message: 'the stage shows it' }).toContain(heading);
		expect((await site.getOptions(['diluxone_users_register_title'])).diluxone_users_register_title).toBe('');
	});
});
