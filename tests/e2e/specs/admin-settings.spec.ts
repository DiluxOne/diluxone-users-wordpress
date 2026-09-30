import type { Locator, Page } from '@playwright/test';
import { test, expect, expectSignedIn, expectSignedOut } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import {
	adminError,
	adminSaved,
	adminUrl,
	challengeScreen,
	linkForm,
	loginWay,
	needsOne,
	openWay,
	passwordForm,
	registerScreen,
	savePanel,
	saveButton,
	signInWithPassword,
	ssoButton,
	submitPanelWithoutScript,
} from '../support/ui';
import { adminTabs } from '../support/screens';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * What the dashboard saves, and what the site does about it.
 *
 * Two different questions, and the second is the one that finds bugs. A
 * setting that saves and is then read from somewhere else — or read with a
 * different default, or sanitised on the way out — looks perfect on the
 * settings screen and does nothing on the page. So each of these presses Save
 * like a person and then goes and looks at the public page.
 *
 * The first block is the cheaper guard: every tab of every screen — the list
 * is in `support/screens.ts`, which the layout and picture suites walk too —
 * renders without a PHP notice and without WordPress's footer riding up into
 * the middle of the layout. That one catches a stray </div> the day it
 * appears.
 *
 * Covers T-ADM-06.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Every settings screen renders', () => {
	for (const { name, url } of adminTabs()) {
		test(name, async ({ page }) => {
			const problems: string[] = [];

			page.on('pageerror', (error) => problems.push(`JS: ${error.message}`));
			page.on('console', (message) => {
				if (message.type() === 'error') {
					problems.push(`console: ${message.text()}`);
				}
			});

			const response = await page.goto(url);

			expect(response?.status()).toBe(200);

			const body = await page.locator('body').innerText();

			expect(body).not.toMatch(/Fatal error|Warning:|Notice:|Deprecated:/);
			expect(body).not.toMatch(/critical error|error crítico/i);

			// The footer sits below the content, not inside it: a stray
			// closing tag floats it up next to the cards, and nothing
			// short of a browser notices.
			const wrap = page.locator('.wrap').first();
			const footer = page.locator('#wpfooter');
			const wrapBox = await wrap.boundingBox();
			const footerBox = await footer.boundingBox();

			expect(wrapBox && footerBox).toBeTruthy();
			expect(footerBox!.y).toBeGreaterThanOrEqual(wrapBox!.y + wrapBox!.height - 2);

			expect(problems, 'browser errors').toEqual([]);
		});
	}
});

test.describe('Saving a tab changes the public page', () => {
	test('Access › Ways in: “only the link” takes the password form off the sign-in page', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_login_method']);

		// The two ways in are two tick boxes now, not one list of three
		// answers: "only the link" is said by leaving the link ticked and
		// unticking the password, and the save works the word out.
		await page.goto(adminUrl('diluxone-users-login', 'ways'));
		await loginWay(page, 'link').check();
		await loginWay(page, 'password').uncheck();
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(passwordForm(guest)).toHaveCount(0);
		await expect(linkForm(guest)).toBeVisible();

		// And back the other way, so the setting is a switch and not a trap.
		await page.goto(adminUrl('diluxone-users-login', 'ways'));
		await loginWay(page, 'password').check();
		await savePanel(page);

		// Its tab is pressed before it is looked for. With the password back
		// on there are two ways in again, and two ways in can be a strip —
		// in which case the form is on the page but behind a tab, and
		// "visible" is a question about the arrangement rather than about
		// the setting this test is proving. Pressing it first asks both:
		// the way is there, and it can be reached.
		await guest.goto(pages.login.url);
		await openWay(guest, 'password');
		await expect(passwordForm(guest)).toBeVisible();
	});

	test('Access › Ways in: turning the social buttons off takes them off the form', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'a', secret: 'b', tested: 1 } },
		});
		await options.keep(['diluxone_users_sso_login']);

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toBeVisible();

		await page.goto(adminUrl('diluxone-users-login', 'ways'));
		await page.locator('input[name="diluxone_users_sso_login"]').uncheck();
		await savePanel(page);

		await guest.goto(pages.login.url);
		await expect(ssoButton(guest, 'mock')).toHaveCount(0);
	});

	test('Access › Ways in: the number of minutes is the number the e-mail says', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_login_expiry', 'diluxone_users_login_throttle']);

		await page.goto(adminUrl('diluxone-users-login', 'ways'));
		await page.locator('input[name="diluxone_users_login_expiry"]').fill('9');
		await page.locator('input[name="diluxone_users_login_throttle"]').fill('1');
		await savePanel(page);

		const email = freshEmail('expiry');
		await site.makeUser({ email });

		await guest.goto(pages.login.url);
		await guest.locator('input[name="diluxone_users_email"]').fill(email);
		await guest.locator('form.diluxone-users-form button[type="submit"]').click();
		await expect(guest.locator('.diluxone-users-login__email')).toContainText(email);

		// The screen and the e-mail quote the same setting, and they used to be
		// able to disagree.
		await expect(guest.locator('.diluxone-users-note').first()).toContainText('9');

		const mail = await waitForMail(site, email);
		expect(mail.body).toMatch(/\b9\b/);
	});

	test('Access › Registration: closing it closes the form on the registration page', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.keep([
			'diluxone_users_login_register',
			'diluxone_users_register_form',
			'diluxone_users_register_page',
			'diluxone_users_rewrite_version',
		]);

		await page.goto(adminUrl('diluxone-users-login', 'register'));
		await page.locator('input[name="diluxone_users_register_form"]').check();
		await page.locator('select[name="diluxone_users_register_page"]').selectOption(String(pages.register.id));
		await page.locator('input[name="diluxone_users_login_register"]').check();
		await savePanel(page);

		await guest.goto(pages.register.url);
		await expect(guest.locator('input[name="diluxone_users_email"]')).toBeVisible();

		// Now close both doors and the same page says so instead.
		await page.goto(adminUrl('diluxone-users-login', 'register'));
		await page.locator('input[name="diluxone_users_register_form"]').uncheck();
		await page.locator('input[name="diluxone_users_login_register"]').uncheck();
		await savePanel(page);

		await guest.goto(pages.register.url);
		await expect(guest.locator('input[name="diluxone_users_email"]')).toHaveCount(0);
		await expect(registerScreen(guest).locator('a[href*="e2e-login"]')).toBeVisible();
	});

	test('Design › The sign-in page: the frame and the legal line come out on the page', async ({
		page,
		guest,
		pages,
		options,
	}) => {
		await options.keep([
			'diluxone_users_login_template',
			'diluxone_users_login_legal',
			'diluxone_users_login_title',
		]);

		await page.goto(adminUrl('diluxone-users-design', 'login'));
		await page.locator('input[name="diluxone_users_login_template"][value="card"]').check();
		await page.locator('input[name="diluxone_users_login_title"]').fill('Entrá al club');

		// M-10: the legal line is the one field allowed to carry a link,
		// because the terms and the privacy policy are pages. A sanitiser that
		// strips it makes the setting useless without saying so.
		await page
			.locator('[name="diluxone_users_login_legal"]')
			.fill('Al entrar aceptás los <a href="/terms/">términos</a>.');
		await savePanel(page);

		await guest.goto(pages.login.url);

		await expect(guest.locator('.diluxone-users-login-frame--card')).toBeVisible();
		await expect(guest.locator('.diluxone-users-login__title')).toContainText('Entrá al club');
		await expect(guest.locator('.diluxone-users-login__legal a[href="/terms/"]')).toBeVisible();
	});

	test('Security › Two-step: “required” asks everybody for a code', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_2fa_mode', 'diluxone_users_2fa_methods']);

		await page.goto(adminUrl('diluxone-users-security', '2fa'));
		await page.locator('input[name="diluxone_users_2fa_mode"][value="required"]').check();
		await page.locator('input[name="diluxone_users_2fa_methods[]"][value="email"]').check();
		await savePanel(page);

		const email = freshEmail('adm2fa');
		const password = 'e2e-Admin-2fa-1!';
		await site.makeUser({ email, password });

		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, password);

		await expect(challengeScreen(guest)).toBeVisible();
		await expectSignedOut(guest);
	});

	test('Account area › Sections: a section turned off leaves the menu', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_account_sections']);

		const email = freshEmail('sections');
		const password = 'e2e-Sections-1!';
		await site.makeUser({ email, password });

		await guest.goto(pages.login.url);
		await signInWithPassword(guest, email, password);
		await expectSignedIn(guest, email);

		await guest.goto(pages.account.url);
		await expect(guest.locator('a[href*="/privacy"]').first()).toBeVisible();

		// The switch on the section's own card in the dashboard.
		await page.goto(adminUrl('diluxone-users-account', 'sections') + '&section=privacy');

		const toggle = page.locator('a.diluxone-users-toggle.is-on');
		await expect(toggle).toBeVisible();
		await Promise.all([page.waitForLoadState('domcontentloaded'), toggle.click()]);
		await expect(page.locator('a.diluxone-users-toggle.is-on')).toHaveCount(0);

		await guest.goto(pages.account.url);
		await expect(guest.locator('a[href*="/privacy"]')).toHaveCount(0);
	});

	test('Notifications › The e-mails: the site’s own words reach the inbox', async ({
		page,
		guest,
		site,
		pages,
		options,
	}) => {
		await options.keep(['diluxone_users_mail_templates', 'diluxone_users_login_throttle']);
		await options.set({ diluxone_users_login_expiry: 7 });

		// Every e-mail is folded away until it is asked for, so the sign-in
		// one is opened before anything can be typed into it.
		await page.goto(adminUrl('diluxone-users-notices', 'templates'));
		await page
			.locator('details:has(#diluxone_users_mail_login_link_subject) > summary')
			.click();
		await page.locator('#diluxone_users_mail_login_link_subject').fill('Tu entrada al club');
		await page
			.locator('#diluxone_users_mail_login_link_body')
			.fill('Entrá: {link} — dura {minutes} minutos.');
		await savePanel(page);

		const email = freshEmail('words');
		await site.makeUser({ email });

		await guest.goto(pages.login.url);
		await guest.locator('input[name="diluxone_users_email"]').fill(email);
		await guest.locator('form.diluxone-users-form button[type="submit"]').click();
		await expect(guest.locator('.diluxone-users-login__email')).toContainText(email);

		const mail = await waitForMail(site, email);

		expect(mail.subject).toBe('Tu entrada al club');
		expect(mail.body.startsWith('Entrá: http')).toBe(true);
		// How long the link lasts is one setting read in two places — the
		// screen that sets it and the e-mail that announces it — and they
		// used to be able to disagree.
		expect(mail.body).toContain('dura 7 minutos');

		// And the link in it still works, which is the part a wording setting
		// is most likely to break.
		await guest.goto(linkIn(mail));
		await expectSignedIn(guest, email);
	});
});

/**
 * The saves that refuse, and the screen a report belongs on.
 *
 * Two of these are about a form that has to say no. A site can be locked out
 * of itself by a screen that saves what it was told without reading it —
 * registration open with every door shut, a second step on with nothing to
 * carry the code — and both used to save exactly that, in silence. Each is
 * asked twice: once the way a person meets it, where the browser stops the
 * press, and once with the script stepped over, because the script is a
 * courtesy and the server is the rule. The proof in both cases is the same
 * one: the settings are still what they were.
 *
 * The third is about where a thing lives. A list of who is signed in is not a
 * setting, and under a Save button it reads as one.
 */
test.describe('A screen that refuses what it cannot save', () => {
	/** Unticks every box of a group the browser is allowed to press. */
	async function untickAll(group: ReturnType<typeof needsOne>): Promise<void> {
		const boxes = group.locator('.du-choice > input[type="checkbox"]:not(:disabled)');

		for (const box of await boxes.all()) {
			await box.uncheck();
		}
	}

	test('Access › Registration: open with no door at all is not saved', async ({
		page,
		site,
		options,
	}) => {
		const doors = [
			'diluxone_users_login_register',
			'diluxone_users_sso_register',
			'diluxone_users_register_form',
		];

		// A known starting point, so "nothing was written" is a statement
		// about the save and not about a setting that was already off.
		await options.set({
			diluxone_users_login_register: 1,
			diluxone_users_sso_register: 0,
			diluxone_users_register_form: 0,
		});
		await options.keep(['users_can_register']);

		const before = await site.getOptions(doors);

		await page.goto(adminUrl('diluxone-users-login', 'register'));
		await page.locator('input[name="diluxone_users_register_open"][value="open"]').check();

		const group = needsOne(page, 'diluxone_users_login_register');

		await untickAll(group);

		// What a person meets: the press does not leave the page, and the
		// group is the thing marked.
		await saveButton(page).click();
		await expect(group).toHaveClass(/is-short/);

		// And with the script stepped over, the save says the same thing.
		await submitPanelWithoutScript(page);
		await expect(adminError(page)).toBeVisible();

		// And says only that. A screen that prints "Saved." over the sentence
		// explaining that nothing was written is a screen nobody believes.
		await expect(adminSaved(page)).toHaveCount(0);

		expect(await site.getOptions(doors)).toEqual(before);
	});

	test('Security › Two-step: on with no way of sending the code is not saved', async ({
		page,
		site,
		options,
	}) => {
		await options.set({
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
		});

		const before = await site.getOptions(['diluxone_users_2fa_methods']);

		await page.goto(adminUrl('diluxone-users-security', '2fa'));

		const group = needsOne(page, 'diluxone_users_2fa_methods[]');

		await untickAll(group);

		await saveButton(page).click();
		await expect(group).toHaveClass(/is-short/);

		await submitPanelWithoutScript(page);
		await expect(adminError(page)).toBeVisible();
		await expect(adminSaved(page)).toHaveCount(0);

		expect(await site.getOptions(['diluxone_users_2fa_methods'])).toEqual(before);
	});

	/*
	 * "Off" is the one answer none of them ticked is an answer to, and the
	 * rule has to let it through: a site turning the step off and untying its
	 * methods in one press is a form with nothing wrong in it.
	 */
	test('Security › Two-step: turning it off with no method left is saved', async ({
		page,
		site,
		options,
	}) => {
		await options.set({
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
		});

		await page.goto(adminUrl('diluxone-users-security', '2fa'));
		await page.locator('input[name="diluxone_users_2fa_mode"][value="off"]').check();

		await untickAll(needsOne(page, 'diluxone_users_2fa_methods[]'));
		await savePanel(page);

		await expect(adminError(page)).toHaveCount(0);
		expect(await site.getOptions(['diluxone_users_2fa_mode'])).toEqual({
			diluxone_users_2fa_mode: 'off',
		});
	});

	test('Who is signed in is a report, and it is not on the settings screen', async ({ page }) => {
		// The table is on Reports, where nothing is saved.
		await page.goto(adminUrl('diluxone-users-reports', 'sessions'));
		await expect(page.locator('table.diluxone-users-list')).toBeVisible();
		await expect(saveButton(page)).toHaveCount(0);

		// And Security keeps the rule it always had — the boxes that say how
		// long a session lasts — with no list of names under them.
		await page.goto(adminUrl('diluxone-users-security', 'sessions'));
		await expect(saveButton(page)).toBeVisible();
		await expect(page.locator('table.diluxone-users-list')).toHaveCount(0);
	});
});

/**
 * The role anybody who registers themselves becomes.
 *
 * It is the one setting on these screens where a wrong answer hands the site
 * away, and it is a drop-down, which is four keystrokes from being whatever
 * the browser felt like sending. So it is asked twice: what the screen offers,
 * and what the save accepts — the second being the one that matters, because
 * the first is only a courtesy to whoever is not trying.
 */
test.describe('Access › Registration: the role a stranger becomes', () => {
	const ROLE = 'diluxone_users_login_role';

	/** The drop-down, by its id: `submit_button()` aside, ids are the contract. */
	function roleSelect(page: Page): Locator {
		return page.locator(`#${ROLE}`);
	}

	test('the drop-down offers no role that can edit the site', async ({ page, options }) => {
		// From a known answer: the rule keeps whatever is saved in the list so
		// a site is never silently demoted, and a seeded `editor` would make
		// this pass or fail for a reason that is not the rule.
		await options.set({ [ROLE]: 'subscriber' });

		await page.goto(adminUrl('diluxone-users-login', 'register'));

		await expect(roleSelect(page)).toBeVisible();
		await expect(roleSelect(page).locator('option[value="subscriber"]')).toHaveCount(1);

		// `edit_posts` is the line the plugin draws, so the four WordPress
		// roles that hold it are all expected to be missing, not only the top.
		for (const role of ['administrator', 'editor', 'author', 'contributor']) {
			await expect(roleSelect(page).locator(`option[value="${role}"]`)).toHaveCount(0);
		}
	});

	test('administrator posted by hand is not what gets written', async ({
		page,
		site,
		options,
	}) => {
		await options.set({
			[ROLE]: 'subscriber',
			// One door open, so the guard above lets this save through and the
			// assertion is about the role and not about a refusal.
			diluxone_users_login_register: 1,
		});

		await page.goto(adminUrl('diluxone-users-login', 'register'));

		// The option was never drawn, so sending it means putting it there
		// first — which is exactly the move an inspector makes it take.
		await roleSelect(page).evaluate((select: HTMLSelectElement) => {
			select.append(new Option('administrator', 'administrator'));
			select.value = 'administrator';
		});

		await submitPanelWithoutScript(page);

		// The save ran and was happy: this is not a refusal writing nothing.
		await expect(adminSaved(page)).toBeVisible();
		await expect(adminError(page)).toHaveCount(0);

		expect(await site.getOptions([ROLE])).toEqual({ [ROLE]: 'subscriber' });
	});
});
