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
