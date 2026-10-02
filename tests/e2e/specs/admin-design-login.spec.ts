import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminUrl, askForLink, registerForm, savePanel, sentScreen, submitPluginForm } from '../support/ui';
import { forgetPicture, mediaPicture, pickPicture, previewAnswer, railNotice, stage, unique } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Design › Sign-in page and Registration page: the shape, the picture, the
 * panel and every sentence, each saved through its control and found on the
 * public page by its class.
 */

test.use({ storageState: ADMIN_STATE });

const LOGIN_KEYS = [
	'diluxone_users_login_template',
	'diluxone_users_login_side',
	'diluxone_users_login_image',
	'diluxone_users_login_panel_logo',
	'diluxone_users_login_panel_title',
	'diluxone_users_login_panel_text',
	'diluxone_users_login_panel_points',
	'diluxone_users_login_panel_foot',
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
			diluxone_users_login_side: 'left',
			diluxone_users_login_image: 0,
			diluxone_users_login_title: '',
			diluxone_users_login_intro: '',
			diluxone_users_login_legal: '',
			diluxone_users_sent_title: '',
			diluxone_users_sent_note: '',
			diluxone_users_sent_icon: 'plain',
			diluxone_users_login_panel_title: '',
			diluxone_users_login_panel_text: '',
			diluxone_users_login_panel_points: '',
			diluxone_users_login_panel_foot: '',
		});
	});

	for (const id of ['split', 'backdrop', 'card']) {
		test(`the “${id}” shape, chosen on the tab, frames the sign-in page; “plain” leaves it alone`, async ({ page, guest, site, pages }) => {
			await page.goto(LOGIN_TAB);
			await template(page, id).check({ force: true });
			await savePanel(page);
			await expect(template(page, id)).toBeChecked();
			expect((await site.getOptions(['diluxone_users_login_template'])).diluxone_users_login_template).toBe(id);

			await guest.goto(pages.login.url);
			await expect(frame(guest)).toHaveClass(new RegExp(`diluxone-users-login-frame--${id}`));
			await expect(guest.locator('.diluxone-users-login-frame__picture'), 'only “split” has a panel of its own').toHaveCount(id === 'split' ? 1 : 0);

			await template(page, 'plain').check({ force: true });
			await savePanel(page);

			await guest.goto(pages.login.url);
			await expect(frame(guest)).toHaveCount(0);
		});
	}

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

	test('“split” with a picture on the right: the picture is the one picked, beside the form on the side chosen', async ({ page, guest, site, pages }) => {
		const picture = await mediaPicture(page.request, 'e2e-login-picture');

		try {
			await page.goto(LOGIN_TAB);
			await template(page, 'split').check({ force: true });
			await pickPicture(page, 'diluxone_users_login_image', picture);
			await page.locator('input[name="diluxone_users_login_side"][value="left"]').check({ force: true });
			await savePanel(page);
			expect(Number((await site.getOptions(['diluxone_users_login_image'])).diluxone_users_login_image)).toBe(picture.id);

			await guest.goto(pages.login.url);
			await expect(frame(guest)).toHaveAttribute('style', /e2e-login-picture/);

			const leftSide = await guest.evaluate(() => {
				const picture = document.querySelector('.diluxone-users-login-frame__picture')!.getBoundingClientRect();
				const form = document.querySelector('.diluxone-users-login-frame__box')!.getBoundingClientRect();

				return picture.left < form.left;
			});

			expect(leftSide, '“left”: the picture is left of the form').toBe(true);

			await page.locator('input[name="diluxone_users_login_side"][value="right"]').check({ force: true });
			await savePanel(page);
			expect((await site.getOptions(['diluxone_users_login_side'])).diluxone_users_login_side).toBe('right');

			await guest.goto(pages.login.url);
			await expect(frame(guest)).toHaveClass(/diluxone-users-login-frame--right/);

			const rightSide = await guest.evaluate(() => {
				const picture = document.querySelector('.diluxone-users-login-frame__picture')!.getBoundingClientRect();
				const form = document.querySelector('.diluxone-users-login-frame__box')!.getBoundingClientRect();

				return picture.left > form.left;
			});

			expect(rightSide, '“right”: the picture is right of the form').toBe(true);
		} finally {
			await forgetPicture(page.request, 'e2e-login-picture');
		}
	});

	test('the panel’s words, written on the tab: a title in two lines, the text, one point per line and the foot', async ({ page, guest, pages }) => {
		await page.goto(LOGIN_TAB);
		await template(page, 'split').check({ force: true });
		await box(page, 'diluxone_users_login_panel_title').fill('E2E first line\nE2E second line');
		await box(page, 'diluxone_users_login_panel_text').fill('E2E panel text');
		await box(page, 'diluxone_users_login_panel_points').fill('E2E point one\nE2E point two\nE2E point three');
		await box(page, 'diluxone_users_login_panel_foot').fill('E2E foot');
		await savePanel(page);

		await expect(box(page, 'diluxone_users_login_panel_points')).toHaveValue('E2E point one\nE2E point two\nE2E point three');

		await guest.goto(pages.login.url);

		const title = guest.locator('.diluxone-users-login-frame__title');

		await expect(title).toContainText('E2E first line');
		await expect(title).toContainText('E2E second line');
		await expect(title.locator('span'), 'each line written is a line of its own').toHaveText(['E2E first line', 'E2E second line']);
		await expect(guest.locator('.diluxone-users-login-frame__text')).toHaveText('E2E panel text');
		await expect(guest.locator('.diluxone-users-login-frame__points li')).toHaveText(['E2E point one', 'E2E point two', 'E2E point three']);
		await expect(guest.locator('.diluxone-users-login-frame__foot')).toHaveText('E2E foot');
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
