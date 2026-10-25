import { Browser, Locator, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { DARK_SITE, themeSwitch } from '../support/dark-mode';
import { accountSection } from '../support/ui';
import { formOf, reveal, signedIn } from '../support/my-account';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The phone field: the country and the number in one control, on every form
 * it is drawn in — the registration form, the account area, the step that
 * asks for the required fields after a sign-in and the profile in wp-admin.
 *
 * The box is measured against the e-mail input beside it, which is what
 * "looks like the rest of the form" means in numbers: the same height, the
 * same border and the same corners, in the light, in the dark and at a
 * phone's width.
 */

const PHONE = { key: 'e2e_phone', label: 'Phone', type: 'phone', options: ['AR'], required: 0, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };
const MAIL = { key: 'e2e_mail', label: 'Work mail', type: 'email', options: [], required: 0, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };
const NAME = { key: 'first_name', label: 'First name', type: 'text', options: [], required: 0, active: 1, group: 'main', edit: 'always', help: '', placeholder: '' };

/** Every field required: the registration form and the step draw only those. */
const REQUIRED = [NAME, { ...MAIL, required: 1 }, { ...PHONE, required: 1 }];

interface Box {
	height: number;
	border: string;
	radius: string;
	ground: string;
}

/** What a control looks like from outside: its height, its edge and its ground. */
function boxOf(locator: Locator): Promise<Box> {
	return locator.first().evaluate((element) => {
		const style = getComputedStyle(element);

		return {
			height: Math.round(element.getBoundingClientRect().height),
			border: `${style.borderTopWidth} ${style.borderTopStyle} ${style.borderTopColor}`,
			radius: style.borderTopLeftRadius,
			ground: style.backgroundColor,
		};
	});
}

/** The phone's box against the input it stands beside: one control, drawn like the others. */
async function expectOneControl(page: Page, phone: Locator, beside: Locator, what: string): Promise<void> {
	await expect(phone).toBeVisible();
	await expect(beside).toBeVisible();

	const box = await boxOf(phone);
	const other = await boxOf(beside);

	expect(box.height, `${what}: the height of the other inputs`).toBe(other.height);
	expect(box.border, `${what}: their border`).toBe(other.border);
	expect(box.radius, `${what}: their corners`).toBe(other.radius);

	// The two inside have no edge of their own: the box is the one control.
	for (const inner of [phone.locator('.diluxone-users-phone__number'), phone.locator('select.diluxone-users-phone__dial')]) {
		if ((await inner.count()) === 0 || !(await inner.isVisible())) {
			continue;
		}

		const own = await boxOf(inner);

		expect(own.border.startsWith('0px'), `${what}: nothing drawn around the inner control (${own.border})`).toBe(true);
		expect(own.height, `${what}: the inner control fits inside the box`).toBeLessThanOrEqual(box.height);
	}
}

/** A registration form asking for the phone. */
async function registration(page: Page, url: string): Promise<{ phone: Locator; mail: Locator }> {
	await page.goto(url);

	const form = page.locator('form.diluxone-users-form').filter({ has: page.locator('input[name="action"][value="diluxone_users_signup"]') });

	return { phone: form.locator('.diluxone-users-phone'), mail: form.locator('input[name="diluxone_users_email"]') };
}

/** The account area's details, with the phone and an e-mail field of the site's own. */
async function details(page: Page, accountUrl: string): Promise<{ phone: Locator; mail: Locator }> {
	await page.goto(accountSection(accountUrl, 'details'));
	await reveal(page, 'input[name="e2e_phone"]');

	const form = formOf(page, 'diluxone_users_fields_save');

	return { phone: form.locator('.diluxone-users-phone'), mail: form.locator('input[name="e2e_mail"]') };
}

/** The administrator's own profile, in a browser of their own. */
async function profile(browser: Browser, baseURL: string | undefined, width = 1280): Promise<{ page: Page; close: () => Promise<void> }> {
	const context = await browser.newContext({ baseURL, storageState: ADMIN_STATE, viewport: { width, height: 900 } });
	const page = await context.newPage();

	await page.goto('/wp-admin/profile.php');

	return { page, close: () => context.close() };
}

test.describe('The phone field is one control', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off' });
	});

	test('on the registration form: the height, border and corners of the e-mail input, light, dark and at a phone’s width', async ({ guest, pages, options }) => {
		await options.set({ ...DARK_SITE, diluxone_users_fields: REQUIRED, diluxone_users_register_form: 1 });

		const { phone, mail } = await registration(guest, pages.register.url);

		await expectOneControl(guest, phone, mail, 'light');

		await themeSwitch(guest, true);
		await expect.poll(async () => (await boxOf(phone)).ground, { message: 'the box follows the switch' }).toBe((await boxOf(mail)).ground);
		await expectOneControl(guest, phone, mail, 'dark');
		await themeSwitch(guest, false);

		await guest.setViewportSize({ width: 390, height: 844 });
		await expectOneControl(guest, phone, mail, '390px');

		const fits = await phone.evaluate((element) => element.getBoundingClientRect().right <= document.documentElement.clientWidth);
		expect(fits, 'nothing pushed past the edge of a phone').toBe(true);
	});

	test('in the account area and on the step that asks for the required fields after a sign-in', async ({ page, site, pages, options }) => {
		await options.set({ ...DARK_SITE, diluxone_users_fields: [NAME, MAIL, PHONE] });

		await signedIn(page, site, pages.login.url, 'phone-box');

		const area = await details(page, pages.account.url);
		await expectOneControl(page, area.phone, area.mail, 'account, light');

		await themeSwitch(page, true);
		await expect.poll(async () => (await boxOf(area.phone)).ground).toBe((await boxOf(area.mail)).ground);
		await expectOneControl(page, area.phone, area.mail, 'account, dark');
		await themeSwitch(page, false);

		await page.setViewportSize({ width: 390, height: 844 });
		await expectOneControl(page, area.phone, area.mail, 'account, 390px');
		await page.setViewportSize({ width: 1280, height: 900 });

		// Required now and missing: the step draws the two of them alone.
		await options.set({ diluxone_users_fields: REQUIRED });
		await page.goto(`${pages.account.url}${pages.account.url.includes('?') ? '&' : '?'}diluxone-users-complete=1`);

		const step = page.locator('.diluxone-users-complete form.diluxone-users-form');
		await expectOneControl(page, step.locator('.diluxone-users-phone'), step.locator('input[name="e2e_mail"]'), 'the step');
	});

	test('on the profile in wp-admin: drawn like WordPress’s own e-mail box, wide and at a phone’s width', async ({ browser, baseURL, options }) => {
		await options.set({ diluxone_users_fields: [NAME, PHONE] });

		for (const width of [1280, 390]) {
			const { page, close } = await profile(browser, baseURL, width);

			try {
				// The box is WordPress's own text box, the number, with the code
				// inside its left end: whatever WordPress draws its inputs with.
				const phone = page.locator('.diluxone-users-phone');
				const number = phone.locator('input.diluxone-users-phone__number');

				await expectOneControl(page, number, page.locator('input#email'), `profile at ${width}px`);

				const inside = await phone.evaluate((element) => {
					const box = element.querySelector('.diluxone-users-phone__number')!.getBoundingClientRect();
					const dial = element.querySelector('.diluxone-users-phone__dial:not([hidden])')!.getBoundingClientRect();

					return dial.top >= box.top && dial.bottom <= box.bottom && dial.left >= box.left && dial.right < box.right;
				});
				expect(inside, `profile at ${width}px: the code sits inside the number's box`).toBe(true);
			} finally {
				await close();
			}
		}
	});
});

/** The picker of the phone on the registration form, for a visitor. */
async function picker(page: Page, url: string) {
	await page.goto(url);

	const box = page.locator('.diluxone-users-register .diluxone-users-phone');
	await expect(box).toHaveClass(/is-enhanced/);

	return {
		box,
		select: box.locator('select.diluxone-users-phone__dial'),
		button: box.getByRole('button', { name: /Country code/ }),
		search: box.locator('[role="combobox"]'),
		list: box.locator('[role="listbox"]'),
		options: box.getByRole('option'),
		status: box.locator('[role="status"]'),
		number: box.locator('input.diluxone-users-phone__number'),
	};
}

test.describe('The country picker', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_users_fields: REQUIRED, diluxone_users_register_form: 1 });
	});

	test('typing “arg” or “54” narrows the list to the countries that match, and says how many', async ({ guest, pages }) => {
		const p = await picker(guest, pages.register.url);

		await expect(p.select, 'the native list is kept, hidden, as what is posted').toBeHidden();
		await expect(p.button).toContainText('AR');
		await expect(p.button).toContainText('+54');

		await p.button.click();
		await expect(p.search).toBeFocused();
		await expect(p.button).toHaveAttribute('aria-expanded', 'true');
		await expect(p.search).toHaveAttribute('aria-expanded', 'true');

		const all = await p.options.count();
		expect(all, 'every country to start with').toBeGreaterThan(190);

		await p.search.fill('arg');
		await expect(p.options).toHaveCount(1);
		await expect(p.options.first()).toContainText('Argentina');
		await expect(p.status).toHaveText('Countries found: 1');

		await p.search.fill('54');
		await expect(p.options.filter({ hasText: 'Argentina' })).toHaveCount(1);
		for (const code of await p.options.locator('.diluxone-users-phone__option-code').allTextContents()) {
			expect(code.startsWith('+54'), `${code} starts with +54`).toBe(true);
		}
		expect(await p.options.count()).toBeLessThan(all);

		await p.search.fill('+54');
		await expect(p.options.filter({ hasText: 'Argentina' }), 'with its plus, too').toHaveCount(1);

		await p.search.fill('córdoba-nowhere');
		await expect(p.options).toHaveCount(0);
		await expect(p.status).toHaveText('No country matches that.');
	});

	test('the keyboard alone: open, arrows, Home and End, Enter to choose, Escape to close', async ({ guest, pages }) => {
		const p = await picker(guest, pages.register.url);

		// The roles a screen reader announces, and what they point at.
		await expect(p.search).toHaveAttribute('aria-controls', (await p.list.getAttribute('id'))!);
		await expect(p.button).toHaveAttribute('aria-haspopup', 'listbox');

		await p.button.focus();
		await guest.keyboard.press('ArrowDown');
		await expect(p.search, 'the arrow opens it, on the search').toBeFocused();

		const activeId = () => p.search.getAttribute('aria-activedescendant');
		const chosen = await p.options.filter({ has: guest.locator('xpath=self::*[@aria-selected="true"]') }).first();

		await expect(chosen).toContainText('Argentina');
		expect(await activeId(), 'the country chosen is where it starts').toBe(await chosen.getAttribute('id'));

		await guest.keyboard.press('ArrowDown');
		const second = await activeId();
		expect(second).not.toBe(await chosen.getAttribute('id'));
		await expect(guest.locator(`[id="${second}"]`)).toHaveClass(/is-active/);

		await guest.keyboard.press('ArrowUp');
		expect(await activeId()).toBe(await chosen.getAttribute('id'));

		await guest.keyboard.press('End');
		expect(await activeId()).toBe(await p.options.last().getAttribute('id'));
		await guest.keyboard.press('Home');
		expect(await activeId()).toBe(await p.options.first().getAttribute('id'));

		// Escape: closed, back on the button, nothing changed.
		await guest.keyboard.press('Escape');
		await expect(p.list).toBeHidden();
		await expect(p.button).toBeFocused();
		await expect(p.button).toHaveAttribute('aria-expanded', 'false');
		await expect(p.select).toHaveValue('AR');

		// Enter chooses, and never sends the form.
		await guest.keyboard.press('Enter');
		await expect(p.search).toBeFocused();
		await guest.keyboard.type('urug');
		await expect(p.options).toHaveCount(1);
		expect(await activeId()).toBe(await p.options.first().getAttribute('id'));
		await guest.keyboard.press('Enter');

		await expect(p.list).toBeHidden();
		await expect(p.button).toBeFocused();
		await expect(p.select, 'the hidden list is what is posted, and it was written').toHaveValue('UY');
		await expect(p.button).toContainText('UY');
		await expect(p.button).toContainText('+598');
		await expect(p.button).toHaveAttribute('aria-label', /Uruguay \+598/);
		expect(new URL(guest.url()).searchParams.get('diluxone-users'), 'still on the form').toBeNull();

		// Tab goes on to the number.
		await guest.keyboard.press('Tab');
		await expect(p.number).toBeFocused();
	});

	test('a pointer chooses too, and a click outside closes it', async ({ guest, pages }) => {
		const p = await picker(guest, pages.register.url);

		await p.button.click();
		await p.search.fill('chile');
		await p.options.filter({ hasText: 'Chile' }).click();
		await expect(p.select).toHaveValue('CL');
		await expect(p.list).toBeHidden();

		await p.button.click();
		await expect(p.list).toBeVisible();
		await guest.locator('body').click({ position: { x: 5, y: 5 } });
		await expect(p.list).toBeHidden();
	});
});

test.describe('The number as it is typed and saved', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_users_fields: [NAME, MAIL, PHONE] });
	});

	test('with Argentina chosen it is grouped as it is typed, the caret stays put, and it is saved as +54 and digits', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'phone-typed');
		const area = await details(page, pages.account.url);
		const number = area.phone.locator('input.diluxone-users-phone__number');

		await expect(area.phone.locator('select.diluxone-users-phone__dial')).toHaveValue('AR');

		await number.click();
		await number.pressSequentially('11234');
		await expect(number, 'grouped while it is typed').toHaveValue('11 234');
		await number.pressSequentially('56789');
		await expect(number).toHaveValue('11 2345 6789');

		// Back over a space takes the digit before it, and the caret stays
		// after the same digit when the groups move.
		await number.press('Home');
		for (let n = 0; n < 3; n++) {
			await number.press('ArrowRight');
		}
		await number.press('Backspace');
		await expect(number).toHaveValue('12 3456 789');
		expect(await number.evaluate((input: HTMLInputElement) => input.selectionStart)).toBe(1);
		await number.press('1');
		await expect(number).toHaveValue('11 2345 6789');
		expect(await number.evaluate((input: HTMLInputElement) => input.selectionStart)).toBe(2);

		await Promise.all([page.waitForURL(/diluxone-users=saved/), formOf(page, 'diluxone_users_fields_save').locator('button[type="submit"]').first().click()]);
		expect((await site.user(email, ['e2e_phone'])).fields.e2e_phone, 'the dialling code and the digits, nothing else').toBe('+541123456789');
	});

	test('a trunk prefix typed out of habit is shown apart and left out of what is saved', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'phone-trunk');
		const area = await details(page, pages.account.url);
		const number = area.phone.locator('input.diluxone-users-phone__number');

		await number.click();
		await number.pressSequentially('01123456789');
		await expect(number).toHaveValue('0 11 2345 6789');

		await Promise.all([page.waitForURL(/diluxone-users=saved/), formOf(page, 'diluxone_users_fields_save').locator('button[type="submit"]').first().click()]);
		expect((await site.user(email, ['e2e_phone'])).fields.e2e_phone).toBe('+541123456789');
	});
});

test.describe('A number the wrong length for its country', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_2fa_mode: 'off', diluxone_users_fields: [NAME, MAIL, PHONE] });
	});

	test('sent past the browser, the server refuses the whole form and says so under the number', async ({ page, site, pages }) => {
		const { email } = await signedIn(page, site, pages.login.url, 'phone-short', { meta: { first_name: 'Ada', e2e_phone: '+541123456789' } });
		const area = await details(page, pages.account.url);
		const form = formOf(page, 'diluxone_users_fields_save');

		await form.evaluate((element: HTMLFormElement) => (element.noValidate = true));
		await form.locator('input[name="first_name"]').fill('Changed');
		await area.phone.locator('input.diluxone-users-phone__number').fill('11 2345');

		await Promise.all([page.waitForURL(/diluxone-users=phone/), form.locator('button[type="submit"]').first().click()]);

		expect(new URL(page.url()).searchParams.get('diluxone-users-field')).toBe('e2e_phone');
		expect(page.url(), 'the number never travels in the address').not.toContain('2345');

		await reveal(page, 'input[name="e2e_phone"]');
		const number = page.locator('input[name="e2e_phone"]');
		const error = page.locator('#e2e_phone-error');

		await expect(error).toBeVisible();
		await expect(error).toHaveText('This number is too short for the country chosen.');
		await expect(number).toHaveAttribute('aria-invalid', 'true');
		await expect(number).toHaveAttribute('aria-describedby', 'e2e_phone-error');
		await expect(page.locator('.diluxone-users-notice--error')).toContainText('Nothing was saved');

		const stored = (await site.user(email, ['first_name', 'e2e_phone'])).fields;
		expect(stored, 'nothing on the form was saved').toEqual({ first_name: 'Ada', e2e_phone: '+541123456789' });
	});

	test('too long, too: the browser stops it first with the same words, under the number', async ({ page, site, pages }) => {
		await signedIn(page, site, pages.login.url, 'phone-long');
		const area = await details(page, pages.account.url);
		const form = formOf(page, 'diluxone_users_fields_save');
		const number = area.phone.locator('input.diluxone-users-phone__number');

		await number.fill('11 2345 6789 0123');
		await form.locator('button[type="submit"]').first().click();

		const error = page.locator('#e2e_phone-error');
		await expect(error).toHaveText('This number is too long for the country chosen.');
		await expect(number).toHaveAttribute('aria-invalid', 'true');
		expect(new URL(page.url()).searchParams.get('diluxone-users'), 'never sent').toBeNull();

		// Put right, the error goes.
		await number.fill('11 2345 6789');
		await expect(error).toBeHidden();
		await expect(number).not.toHaveAttribute('aria-invalid', 'true');
	});

	test('on the registration form no account is made, and the error is under the number', async ({ guest, site, pages, options }) => {
		await options.set({ diluxone_users_fields: REQUIRED, diluxone_users_register_form: 1 });

		const email = `phone-reg-${Date.now()}@e2e.test`;
		const p = await picker(guest, pages.register.url);
		const form = guest.locator('form.diluxone-users-form').filter({ has: guest.locator('input[name="action"][value="diluxone_users_signup"]') });

		await form.evaluate((element: HTMLFormElement) => (element.noValidate = true));
		await form.locator('input[name="diluxone_users_email"]').fill(email);
		await form.locator('input[name="e2e_mail"]').fill('work@example.org');
		await p.number.fill('11 23');

		await Promise.all([guest.waitForURL(/diluxone-users=phone/), form.locator('button[type="submit"]').click()]);

		await expect(guest.locator('[data-diluxone-users-message="register_phone"]')).toBeVisible();
		await expect(guest.locator('#diluxone-users-e2e_phone-error')).toHaveText('This number is too short for the country chosen.');
		await expect(guest.locator('#diluxone-users-e2e_phone')).toHaveAttribute('aria-invalid', 'true');
		expect((await site.user(email)).exists, 'no account').toBe(false);
	});
});
