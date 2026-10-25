import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { DARK_GROUND, DARK_SITE, themeSwitch } from '../support/dark-mode';
import { emailField, passField } from '../support/ui';

/**
 * The sign-in form's fields when the site's own switch flips the scheme on an
 * open page.
 *
 * Seen on a live site: every part of the form followed the switch but the
 * e-mail field, which stayed the box of the scheme it was drawn in. The site
 * here is the e2e mu-plugin's dark stand-in (`diluxone_e2e_dark`), whose
 * switch is `is-dark` on <html>. The e-mail field is compared with the
 * password field beside it and with the tokens the form paints from.
 *
 * The browser's autofill cannot be triggered from Playwright, so the rule that
 * paints an autofilled field is read from the stylesheet instead: it has to
 * paint with the live tokens, never with a colour fixed at load.
 */

const BOTH = { diluxone_users_login_method: 'both', diluxone_users_login_layout: 'stack' };

interface Paint {
	background: string;
	color: string;
	border: string;
	scheme: string;
}

/** What a field is painted with, and the tokens it is meant to be painted from, as `rgb()`. */
async function paintOf(page: Page, selector: string): Promise<{ field: Paint; tokens: Omit<Paint, 'scheme'> }> {
	return page.locator(selector).first().evaluate((input) => {
		const style = getComputedStyle(input);
		// A token resolved by the browser itself: a probe painted with it.
		const resolve = (token: string, property: 'backgroundColor' | 'color' | 'borderTopColor'): string => {
			const probe = document.createElement('span');
			probe.style.setProperty(property === 'borderTopColor' ? 'border' : property === 'color' ? 'color' : 'background-color', property === 'borderTopColor' ? `1px solid var(${token})` : `var(${token})`);
			input.parentElement!.appendChild(probe);
			const value = getComputedStyle(probe)[property];
			probe.remove();
			return value;
		};

		return {
			field: { background: style.backgroundColor, color: style.color, border: style.borderTopColor, scheme: style.colorScheme },
			tokens: {
				background: resolve('--diluxone-users-surface', 'backgroundColor'),
				color: resolve('--diluxone-users-text', 'color'),
				border: resolve('--diluxone-users-border', 'borderTopColor'),
			},
		};
	});
}

/** The declarations of every stylesheet rule whose selector names an autofilled field of the plugin's. */
function autofillRules(page: Page): Promise<Array<{ selector: string; declarations: Record<string, string> }>> {
	return page.evaluate(() => {
		const found: Array<{ selector: string; declarations: Record<string, string> }> = [];

		for (const sheet of Array.from(document.styleSheets)) {
			let rules: CSSRuleList;
			try {
				rules = sheet.cssRules;
			} catch {
				continue;
			}
			for (const rule of Array.from(rules)) {
				if (!(rule instanceof CSSStyleRule) || !/autofill/.test(rule.selectorText) || !/diluxone-users/.test(rule.selectorText)) {
					continue;
				}
				const declarations: Record<string, string> = {};
				for (const name of ['box-shadow', '-webkit-text-fill-color', 'caret-color', 'border-color']) {
					declarations[name] = rule.style.getPropertyValue(name);
				}
				found.push({ selector: rule.selectorText, declarations });
			}
		}

		return found;
	});
}

test.describe('The sign-in form’s fields when the theme switches the scheme', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ ...DARK_SITE, ...BOTH });
	});

	test('flipped after load, the e-mail field takes the new scheme like the password field, both ways', async ({ guest, pages }) => {
		await guest.emulateMedia({ colorScheme: 'light' });
		await guest.goto(pages.login.url);
		await expect(emailField(guest)).toBeVisible();
		await expect(passField(guest)).toBeVisible();

		const email = 'input[name="diluxone_users_email"]';
		const pass = 'input[name="pwd"]';
		const light = await paintOf(guest, email);

		for (const dark of [true, false]) {
			await themeSwitch(guest, dark);
			await expect.poll(() => guest.evaluate(() => getComputedStyle(document.body).backgroundColor), { message: 'the page took the switch' }).toBe(
				dark ? DARK_GROUND : 'rgb(255, 255, 255)'
			);

			// The tokens are measured again on the switch; wait for the field to
			// be painted from what they are now.
			await expect
				.poll(async () => {
					const { field, tokens } = await paintOf(guest, email);
					return [field.background, field.color, field.border].join(' ') === [tokens.background, tokens.color, tokens.border].join(' ');
				}, { message: 'the e-mail field paints from the live tokens' })
				.toBe(true);

			const now = await paintOf(guest, email);
			const beside = await paintOf(guest, pass);

			expect(now.field.background, 'the same ground as the password field').toBe(beside.field.background);
			expect(now.field.color, 'the same text as the password field').toBe(beside.field.color);
			expect(now.field.border, 'the same border as the password field').toBe(beside.field.border);
			expect(now.field.scheme, 'the browser’s own paint follows the page').not.toBe('light');

			if (dark) {
				expect(now.field.background, 'not the light box it was drawn as').not.toBe(light.field.background);
			} else {
				expect(now.field.background, 'back to the light one').toBe(light.field.background);
			}
		}
	});

	test('an autofilled field is painted from the live tokens, not from the browser’s own colours', async ({ guest, pages }) => {
		await guest.goto(pages.login.url);
		await expect(emailField(guest)).toBeVisible();

		const rules = await autofillRules(guest);

		for (const field of ['.diluxone-users-form input', '.diluxone-users-field input', '.login-password input']) {
			expect(rules.some((rule) => rule.selector.includes(field)), `an autofill rule for ${field}`).toBe(true);
		}
		for (const rule of rules) {
			expect(rule.declarations['box-shadow'], `${rule.selector}: the ground`).toContain('var(--diluxone-users-surface)');
			expect(rule.declarations['box-shadow'], `${rule.selector}: over the whole field`).toContain('inset');
			expect(rule.declarations['-webkit-text-fill-color'], `${rule.selector}: the text`).toBe('var(--diluxone-users-text)');
			expect(rule.declarations['caret-color'], `${rule.selector}: the caret`).toBe('var(--diluxone-users-text)');
			expect(rule.declarations['border-color'], `${rule.selector}: the border`).toBe('var(--diluxone-users-border)');
		}

		// And those tokens are the switch's: what they resolve to changes with it.
		const ground = () =>
			emailField(guest).evaluate((input) => {
				const probe = document.createElement('span');
				probe.style.boxShadow = '0 0 0 1px var(--diluxone-users-surface) inset';
				input.parentElement!.appendChild(probe);
				const value = getComputedStyle(probe).boxShadow;
				probe.remove();
				return value;
			});
		const before = await ground();

		await themeSwitch(guest, true);
		await expect.poll(ground, { message: 'the autofill ground follows the switch' }).not.toBe(before);
	});

	test('the phone on the registration form is one box that takes the switch like the e-mail field, both ways', async ({ guest, pages, options }) => {
		await options.set({
			diluxone_users_register_form: 1,
			diluxone_users_fields: [{ key: 'e2e_phone', label: 'Phone', type: 'phone', options: ['AR'], required: 1, active: 1, group: 'main', edit: 'always' }],
		});

		await guest.emulateMedia({ colorScheme: 'light' });
		await guest.goto(pages.register.url);

		const phone = '.diluxone-users-register .diluxone-users-phone';
		const email = '.diluxone-users-register input[name="diluxone_users_email"]';

		await expect(guest.locator(phone)).toBeVisible();

		for (const dark of [true, false]) {
			await themeSwitch(guest, dark);
			await expect
				.poll(async () => {
					const { field, tokens } = await paintOf(guest, phone);
					return [field.background, field.color, field.border].join(' ') === [tokens.background, tokens.color, tokens.border].join(' ');
				}, { message: 'the phone box paints from the live tokens' })
				.toBe(true);

			const box = await paintOf(guest, phone);
			const beside = await paintOf(guest, email);

			expect(box.field.background, 'the ground of the e-mail field').toBe(beside.field.background);
			expect(box.field.border, 'the border of the e-mail field').toBe(beside.field.border);
		}
	});
});
