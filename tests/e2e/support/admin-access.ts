import { Locator, Page, expect } from '@playwright/test';
import { adminUrl } from './ui';

/**
 * What the Access, Overview and framework specs of the admin share.
 *
 * Locators by name, value and class, never by a sentence: the single-site
 * suite runs in Spanish, the network one in English.
 */

/** The fake social network, configured and tested, so its button is drawn. */
export const MOCK_SSO = {
	diluxone_e2e_sso: 1,
	diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
};

/** The two fields WordPress brings, and nothing required: a form anybody can send. */
export const PLAIN_FIELDS = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, active: 1, group: 'main', edit: 'always' },
];

/** A tab of the Access screen. */
export function accessTab(tab: string): string {
	return adminUrl('diluxone-users-login', tab);
}

/** One input by its name and value. */
export function control(page: Page, name: string, value?: string): Locator {
	return page.locator(value === undefined ? `[name="${name}"]` : `[name="${name}"][value="${value}"]`);
}

/** The card (`label.du-choice`) that holds one input. */
export function card(page: Page, name: string, value?: string): Locator {
	return page.locator('label.du-choice').filter({ has: control(page, name, value) });
}

/** The state pill on a card, or anywhere inside a locator. */
export function pill(scope: Locator): Locator {
	return scope.locator('.diluxone-users-state').first();
}

/** Asserts a pill's state by its class: active, pending, off, unknown. */
export async function expectPill(scope: Locator, state: 'active' | 'pending' | 'off' | 'unknown'): Promise<void> {
	await expect(pill(scope)).toHaveClass(new RegExp(`diluxone-users-state--${state}\\b`));
}

/** The "not now" notes a tab prints in its main column. */
export function notNow(page: Page): Locator {
	return page.locator('.diluxone-users-studio__fields .diluxone-users-not-now');
}

/** The rail beside the settings. */
export function rail(page: Page): Locator {
	return page.locator('.diluxone-users-studio__aside');
}

/** A row of a summary table, found by the link it carries. */
export function summaryRow(page: Page, href: string): Locator {
	return page.locator('table.diluxone-users-summary tr').filter({ has: page.locator(`a[href*="${href}"]`) });
}

/** Numbers out of a text, whatever the language around them. */
export function numbersIn(text: string): number[] {
	return (text.match(/\d+/g) ?? []).map(Number);
}

/** Settings read through the side door, each as a number (a stored '1' and 1 are one answer). */
export async function storedNumbers(site: { getOptions(keys: string[]): Promise<Record<string, unknown>> }, keys: string[]): Promise<Record<string, number>> {
	const raw = await site.getOptions(keys);

	return Object.fromEntries(keys.map((key) => [key, Number(raw[key] ?? 0)]));
}
