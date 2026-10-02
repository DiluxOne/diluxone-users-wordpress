import { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { Site } from '../support/api';
import { accountSection, adminUrl, navigated, savePanel } from '../support/ui';
import { railNotice, setUserMeta, signInMember } from '../support/admin-content';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Account area › Public name, saved on its tab and proven on the account.
 *
 * Every rule on the tab — offered or not, the length, the spaces, how often it
 * may change, the names the site keeps — is set through the tab's own
 * controls and then tried by a subscriber on their details, where the stored
 * public name is read back through the side door.
 */

test.use({ storageState: ADMIN_STATE });

const RULES = [
	'diluxone_users_handle_enabled',
	'diluxone_users_handle_min',
	'diluxone_users_handle_max',
	'diluxone_users_handle_spaces',
	'diluxone_users_handle_cooldown',
	'diluxone_users_handle_reserved',
];

/** A short word nobody has, letters only. */
const fresh = (length = 6) =>
	Array.from({ length }, () => 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)]).join('');

function handleForm(page: Page) {
	return page.locator('form').filter({ has: page.locator('input[name="action"][value="diluxone_users_handle"]') });
}

/** Opens the details and brings the public-name form into view. */
async function openHandle(page: Page, accountUrl: string): Promise<void> {
	await page.goto(accountSection(accountUrl, 'details'));

	const closed = page.locator('details:not([open])').filter({ has: page.locator('input[name="diluxone_users_handle"]') });

	for (let n = await closed.count(); n > 0; n = await closed.count()) {
		await closed.first().locator('> summary').click();
	}

	await expect(page.locator('input[name="diluxone_users_handle"]')).toBeVisible();
}

/** Types a public name and saves it; answers with the state, '' when refused. */
async function choose(page: Page, accountUrl: string, name: string): Promise<string> {
	await openHandle(page, accountUrl);
	await handleForm(page).locator('input[name="diluxone_users_handle"]').fill(name);
	// Typing stops at maxlength; a value set in the DOM, like a hand-made
	// request, does not.
	await handleForm(page).locator('input[name="diluxone_users_handle"]').evaluate((input: HTMLInputElement, value) => {
		input.value = value;
	}, name);
	// The box carries the length as minlength/maxlength, a courtesy; the server
	// is the rule, so the browser's check is out of the way here.
	await handleForm(page).evaluate((form: HTMLFormElement) => {
		form.noValidate = true;
	});
	// It lands on the same section it left, so what is waited for is the next
	// document, not an address.
	await navigated(page, () => handleForm(page).locator('button[type="submit"]').click());

	return new URL(page.url()).searchParams.get('diluxone-users') ?? '';
}

async function stored(site: Site, email: string): Promise<string> {
	return (await site.user(email, ['diluxone_users_handle'])).fields.diluxone_users_handle;
}

const refusal = (page: Page) => page.locator('.diluxone-users-handle .diluxone-users-notice--error');

/** Opens the tab and saves the rules given, by their controls. */
async function setRules(page: Page, rules: { enabled?: boolean; min?: number; max?: number; spaces?: 'dash' | 'reject'; cooldown?: number; reserved?: string }) {
	await page.goto(adminUrl('diluxone-users-account', 'handle'));

	if (rules.enabled !== undefined) {
		await page.locator('input[type="checkbox"][name="diluxone_users_handle_enabled"]').setChecked(rules.enabled, { force: true });
	}

	if (rules.min !== undefined) {
		await page.locator('[name="diluxone_users_handle_min"]').fill(String(rules.min));
	}

	if (rules.max !== undefined) {
		await page.locator('[name="diluxone_users_handle_max"]').fill(String(rules.max));
	}

	if (rules.spaces !== undefined) {
		await page.locator(`input[name="diluxone_users_handle_spaces"][value="${rules.spaces}"]`).check({ force: true });
	}

	if (rules.cooldown !== undefined) {
		await page.locator('[name="diluxone_users_handle_cooldown"]').fill(String(rules.cooldown));
	}

	if (rules.reserved !== undefined) {
		await page.locator('[name="diluxone_users_handle_reserved"]').fill(rules.reserved);
	}

	await savePanel(page);
}

test.beforeEach(async ({ options }) => {
	await options.keep(RULES);
	await options.set({ diluxone_users_handle_enabled: 0, diluxone_users_handle_cooldown: 0, diluxone_users_handle_min: 3, diluxone_users_handle_max: 30, diluxone_users_handle_spaces: 'dash', diluxone_users_handle_reserved: '' });
});

test.describe('Account area › Public name', () => {
	test('“let people choose”: on, the details offer it and it saves; off, it is gone and a request made by hand saves nothing', async ({
		page,
		guest,
		site,
		pages,
	}) => {
		const email = await signInMember(guest, site, pages.login.url, 'handle-switch');

		await page.goto(adminUrl('diluxone-users-account', 'handle'));
		await expect(railNotice(page, 'info'), 'off: the rail says nobody is choosing').toHaveCount(1);

		await guest.goto(accountSection(pages.account.url, 'details'));
		await expect(guest.locator('input[name="diluxone_users_handle"]')).toHaveCount(0);

		await setRules(page, { enabled: true });
		expect(Number((await site.getOptions(['diluxone_users_handle_enabled'])).diluxone_users_handle_enabled)).toBe(1);
		await expect(railNotice(page, 'info'), 'on: nothing to warn about').toHaveCount(0);

		await page.goto(adminUrl('diluxone-users-account', 'summary'));
		await expect(page.locator('tr').filter({ has: page.locator('a[href*="tab=handle"]') }).locator('.diluxone-users-summary__state .diluxone-users-state')).toHaveClass(/diluxone-users-state--active/);

		const name = fresh();

		expect(await choose(guest, pages.account.url, name)).toBe('saved');
		expect(await stored(site, email)).toBe(name);

		// The form's own nonce, kept from while it was on.
		const nonce = await handleForm(guest).locator('input[name="_wpnonce"]').inputValue();

		await setRules(page, { enabled: false });

		await guest.goto(accountSection(pages.account.url, 'details'));
		await expect(guest.locator('input[name="diluxone_users_handle"]'), 'off: the details do not offer it').toHaveCount(0);

		await guest.request.post('/wp-admin/admin-post.php', {
			form: { action: 'diluxone_users_handle', _wpnonce: nonce, diluxone_users_handle: fresh() },
		});
		expect(await stored(site, email), 'off: a public name sent by hand is not saved').toBe(name);
	});

	test('the length: shorter or longer than the tab says is refused and writes nothing; in between is kept', async ({ page, guest, site, pages }) => {
		await setRules(page, { enabled: true, min: 5, max: 8 });

		const kept = await site.getOptions(['diluxone_users_handle_min', 'diluxone_users_handle_max']);

		expect([Number(kept.diluxone_users_handle_min), Number(kept.diluxone_users_handle_max)]).toEqual([5, 8]);

		const email = await signInMember(guest, site, pages.login.url, 'handle-length');

		await openHandle(guest, pages.account.url);
		await expect(guest.locator('input[name="diluxone_users_handle"]'), 'the browser is told the same limits').toHaveAttribute('minlength', '5');
		await expect(guest.locator('input[name="diluxone_users_handle"]')).toHaveAttribute('maxlength', '8');

		expect(await choose(guest, pages.account.url, fresh(3))).toBe('');
		await expect(refusal(guest)).toBeVisible();
		expect(await stored(site, email)).toBe('');

		expect(await choose(guest, pages.account.url, fresh(10))).toBe('');
		await expect(refusal(guest)).toBeVisible();
		expect(await stored(site, email)).toBe('');

		const name = fresh(6);

		expect(await choose(guest, pages.account.url, name)).toBe('saved');
		expect(await stored(site, email)).toBe(name);
	});

	test('spaces: “dashes” turns them into dashes; “refuse” refuses and keeps the name there was', async ({ page, guest, site, pages }) => {
		await setRules(page, { enabled: true, spaces: 'dash' });

		const email = await signInMember(guest, site, pages.login.url, 'handle-spaces');
		const first = fresh(4);
		const last = fresh(5);

		expect(await choose(guest, pages.account.url, `${first} ${last}`)).toBe('saved');
		expect(await stored(site, email)).toBe(`${first}-${last}`);

		await setRules(page, { spaces: 'reject' });
		expect((await site.getOptions(['diluxone_users_handle_spaces'])).diluxone_users_handle_spaces).toBe('reject');

		expect(await choose(guest, pages.account.url, `${fresh(4)} ${fresh(4)}`)).toBe('');
		await expect(refusal(guest)).toBeVisible();
		expect(await stored(site, email), 'nothing written').toBe(`${first}-${last}`);
	});

	test('how often it can change: a second change inside the days is refused; once they have passed, it goes through', async ({ page, guest, site, pages }) => {
		await setRules(page, { enabled: true, cooldown: 30 });
		expect(Number((await site.getOptions(['diluxone_users_handle_cooldown'])).diluxone_users_handle_cooldown)).toBe(30);

		const email = await signInMember(guest, site, pages.login.url, 'handle-cooldown');
		const first = fresh();

		expect(await choose(guest, pages.account.url, first)).toBe('saved');

		// Straight after, the form says until when and offers no button.
		await openHandle(guest, pages.account.url);
		await expect(handleForm(guest).locator('button[type="submit"]')).toHaveCount(0);

		// Sent anyway: refused, and the name is the first one.
		const nonce = await handleForm(guest).locator('input[name="_wpnonce"]').inputValue();

		await guest.request.post('/wp-admin/admin-post.php', { form: { action: 'diluxone_users_handle', _wpnonce: nonce, diluxone_users_handle: fresh() } });
		expect(await stored(site, email)).toBe(first);

		// Thirty-one days later, as far as the account knows.
		await setUserMeta(guest.request, email, { diluxone_users_handle_changed: Math.floor(Date.now() / 1000) - 31 * 86400 });

		const second = fresh();

		expect(await choose(guest, pages.account.url, second)).toBe('saved');
		expect(await stored(site, email)).toBe(second);
	});

	test('the site’s own reserved names, by comma or by line, are refused whatever their case; a name beside them is not', async ({ page, guest, site, pages }) => {
		const one = fresh(5);
		const two = fresh(5);
		const three = fresh(5);

		await setRules(page, { enabled: true, reserved: `${one}, ${two}\n${three}` });

		const email = await signInMember(guest, site, pages.login.url, 'handle-reserved');

		for (const name of [three, two.toUpperCase(), one]) {
			expect(await choose(guest, pages.account.url, name)).toBe('');
			await expect(refusal(guest), `${name} is the site’s`).toBeVisible();
			expect(await stored(site, email)).toBe('');
		}

		expect(await choose(guest, pages.account.url, `${three}x`)).toBe('saved');
		expect(await stored(site, email)).toBe(`${three}x`);
	});
});
