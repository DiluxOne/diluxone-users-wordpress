import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminUrl, loginWay, savePanel, saveButton } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * The Summary of the Security screen, and whether it tells the truth.
 *
 * A summary is the one screen that can be wrong without looking wrong: it
 * draws no control, so nothing about it can fail to save, and a row that
 * quotes a default instead of the setting reads exactly like a row that
 * quotes the setting. That is the whole subject here — not that the table
 * exists, but that what it says changes when the site changes.
 *
 * So each of these moves a setting and then goes and reads the summary. Half
 * of them move it the way the plugin's own API does, which is the fastest way
 * to put the site in an awkward state; the other half move it the way a
 * person does, by pressing Save on the tab beside it, which is the half that
 * would catch a summary reading a different option from the one the form
 * writes.
 *
 * Not a word on this page is read. The site under test runs in Spanish and
 * the plugin ships eight locales, so a row is found by the address of the tab
 * it links to — the plugin's own slugs, which do not translate — and its
 * state is read from the class on the pill.
 */

test.use({ storageState: ADMIN_STATE });

const SECURITY = 'diluxone-users-security';

/** The summary table itself. */
function summary(page: Page): Locator {
	return page.locator('table.diluxone-users-summary');
}

/**
 * One row of it, by the tab its "change it" link points at.
 *
 * Several rows can send you to the same tab — the second step is three of
 * them — so the index says which, in the order the table draws them.
 */
function row(page: Page, tab: string, index = 0): Locator {
	return summary(page)
		.locator('tbody tr')
		.filter({ has: page.locator(`a[href*="page=${SECURITY}&tab=${tab}"]`) })
		.nth(index);
}

/** How that row says it is doing: the pill, by the state in its class. */
function pill(one: Locator): Locator {
	return one.locator('.diluxone-users-state');
}

test.describe('Security › Summary', () => {
	test('is the first tab, and it is what the screen opens on', async ({ page }) => {
		// No tab in the address: what a person gets from the menu.
		await page.goto(adminUrl(SECURITY));

		await expect(summary(page)).toBeVisible();

		const tabs = page.locator('.nav-tab-wrapper .nav-tab');

		await expect(tabs.first()).toHaveAttribute('href', new RegExp(`tab=summary$`));
		await expect(tabs.first()).toHaveClass(/nav-tab-active/);

		// It edits nothing, so it carries no Save button: a screen that offers
		// one and writes nothing is the lie this tab exists to avoid.
		await expect(saveButton(page)).toHaveCount(0);
	});

	test('the second step: the row is read from the setting, not from the default', async ({
		page,
		options,
	}) => {
		// Off, with nothing left to carry a code. The plugin's default is
		// "optional" with two methods, so a row that quoted the defaults would
		// stay green through all three of these.
		await options.set({
			diluxone_users_2fa_mode: 'off',
			diluxone_users_2fa_methods: [],
		});

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, '2fa', 0))).toHaveClass(/diluxone-users-state--off/);

		// On, and asked of everybody.
		await options.set({
			diluxone_users_2fa_mode: 'required',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_scope: 'all',
		});

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, '2fa', 0))).toHaveClass(/diluxone-users-state--active/);

		// And the state in between, which is the one worth having a summary
		// for: required of a list of roles that was never filled in reaches
		// nobody, and the screen it is set on cannot see that.
		await options.set({
			diluxone_users_2fa_scope: 'some',
			diluxone_users_2fa_roles: [],
		});

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, '2fa', 0))).toHaveClass(/diluxone-users-state--pending/);
	});

	test('passkeys: the rows follow the switch that is on another screen', async ({
		page,
		options,
	}) => {
		await options.set({ diluxone_users_passkey_enabled: 0 });

		await page.goto(adminUrl(SECURITY, 'summary'));

		// The two rows that link to the passkeys tab are the settings that
		// only bite while passkeys are offered.
		await expect(pill(row(page, 'passkeys', 0))).toHaveClass(/diluxone-users-state--off/);
		await expect(pill(row(page, 'passkeys', 1))).toHaveClass(/diluxone-users-state--off/);

		await options.set({
			diluxone_users_passkey_enabled: 1,
			diluxone_users_passkey_verify: 1,
		});

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, 'passkeys', 0))).toHaveClass(/diluxone-users-state--active/);
		await expect(pill(row(page, 'passkeys', 1))).toHaveClass(/diluxone-users-state--active/);

		// The fingerprint is a setting of its own, and switching it off must
		// not take the row above it with it.
		await options.set({ diluxone_users_passkey_verify: 0 });

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, 'passkeys', 0))).toHaveClass(/diluxone-users-state--active/);
		await expect(pill(row(page, 'passkeys', 1))).toHaveClass(/diluxone-users-state--off/);
	});

	test('saving how long a session lasts is what the summary then says', async ({
		page,
		options,
	}) => {
		await options.keep([
			'diluxone_users_session_long_days',
			'diluxone_users_session_short_days',
		]);

		// Pressed like a person, on the tab beside it. Two numbers nobody
		// would have as a default, so finding them in the summary can only
		// mean it read what was written.
		await page.goto(adminUrl(SECURITY, 'sessions'));
		await page.locator('input[name="diluxone_users_session_long_days"]').fill('21');
		await page.locator('input[name="diluxone_users_session_short_days"]').fill('3');
		await savePanel(page);

		await page.goto(adminUrl(SECURITY, 'summary'));

		const lasts = row(page, 'sessions', 0);

		await expect(lasts).toContainText('21');
		await expect(lasts).toContainText(/\b3\b/);

		// And again with different numbers, so this is a reading and not a
		// coincidence with whatever the site was left on.
		await page.goto(adminUrl(SECURITY, 'sessions'));
		await page.locator('input[name="diluxone_users_session_long_days"]').fill('45');
		await savePanel(page);

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(row(page, 'sessions', 0)).toContainText('45');
		await expect(row(page, 'sessions', 0)).not.toContainText('21');
	});

	test('behind a proxy: the summary names the header that is being read', async ({
		page,
		options,
	}) => {
		await options.keep(['diluxone_users_ip_header', 'diluxone_users_trusted_proxies']);

		// Nothing chosen: the plugin reads the connection, and the row says
		// so rather than showing the header it would fall back to.
		await options.set({ diluxone_users_ip_header: '', diluxone_users_trusted_proxies: '' });

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, 'proxy'))).toHaveClass(/diluxone-users-state--off/);
		await expect(row(page, 'proxy').locator('code')).toHaveCount(1);

		// Chosen on its own tab, by a person. The header's name is a proper
		// noun and is the one thing on this screen safe to read.
		await page.goto(adminUrl(SECURITY, 'proxy'));
		await page
			.locator('select[name="diluxone_users_ip_header"]')
			.selectOption('HTTP_CF_CONNECTING_IP');
		await savePanel(page);

		await page.goto(adminUrl(SECURITY, 'summary'));
		await expect(pill(row(page, 'proxy'))).toHaveClass(/diluxone-users-state--active/);
		await expect(row(page, 'proxy')).toContainText('CF-Connecting-IP');
	});
});

/**
 * Security › Two-step verification, and the one line on it that was misread.
 *
 * The rail beside those settings carries a pill about the second step asked
 * of somebody who arrives by an e-mail link. It used to be titled after the
 * arrival rather than after the question, so a site with the link switched on
 * and no second step to ask showed "the link — Off", and it was read the only
 * way it could be read: that the link itself had been turned off. The door is
 * not decided on this screen at all; it is decided on Access › Ways in.
 *
 * So what is asserted here is that the two screens cannot contradict each
 * other: the pill moves when the second step moves and stays put when the
 * door moves, which is the difference between reporting on the question and
 * reporting on the link.
 *
 * Nothing is read as a sentence. The state comes off the class, and the two
 * places where a word IS compared compare it with another word taken from the
 * running site — never with one written here, which would be English on a
 * site that runs in Spanish.
 */
test.describe('Security › Two-step verification: the rail beside it', () => {
	const ACCESS = 'diluxone-users-login';

	/** The one note in that rail, and the pill on it. */
	function railPill(page: Page): Locator {
		return page.locator('.diluxone-users-studio__aside .du-note').first().locator('.diluxone-users-state');
	}

	test('the pill answers about the second step, never about the link', async ({
		page,
		options,
	}) => {
		// The link is a door and it is open; the second step is on, and the
		// only thing that could carry its code is the same inbox the link was
		// just opened in — so the step is not asked of somebody who came in
		// that way. Two different facts, and the screen has to keep them apart.
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_2fa_mode: 'optional',
			diluxone_users_2fa_methods: ['email'],
			diluxone_users_2fa_link: 'auto',
		});

		await page.goto(adminUrl(ACCESS, 'ways'));
		await expect(loginWay(page, 'link')).toBeChecked();

		await page.goto(adminUrl(SECURITY, '2fa'));
		await expect(railPill(page)).toHaveClass(/diluxone-users-state--off/);

		const withTheDoorOpen = (await railPill(page).textContent())?.trim();

		// Shut the door and change nothing else. Nothing about the second step
		// has moved, so the pill must not move either: if it were an answer
		// about the link — which is how it was read — this is where it would.
		await options.set({ diluxone_users_login_method: 'password' });

		await page.goto(adminUrl(ACCESS, 'ways'));
		await expect(loginWay(page, 'link')).not.toBeChecked();

		await page.goto(adminUrl(SECURITY, '2fa'));
		await expect(railPill(page)).toHaveClass(/diluxone-users-state--off/);
		expect((await railPill(page).textContent())?.trim()).toBe(withTheDoorOpen);

		// And it does move when the thing it is about moves. The door is still
		// shut, so the second step is the only thing that changed.
		await options.set({ diluxone_users_2fa_link: 'always' });

		await page.goto(adminUrl(SECURITY, '2fa'));
		await expect(railPill(page)).toHaveClass(/diluxone-users-state--active/);
		expect((await railPill(page).textContent())?.trim()).not.toBe(withTheDoorOpen);
	});

	test('the word on it is the verb of the question, not the word for a switch', async ({
		page,
		options,
	}) => {
		// With the whole thing off, both places say "off" about the same site
		// in the same state: the summary row about two-step verification,
		// which IS a switch, and the rail's pill, which is about whether a
		// question gets asked. The same state, and they must not be the same
		// word — "Off" beside a line about the link is what caused this.
		await options.set({ diluxone_users_2fa_mode: 'off' });

		await page.goto(adminUrl(SECURITY, 'summary'));

		const theWordForASwitch = (await pill(row(page, '2fa', 0)).textContent())?.trim();

		await expect(pill(row(page, '2fa', 0))).toHaveClass(/diluxone-users-state--off/);

		await page.goto(adminUrl(SECURITY, '2fa'));

		await expect(railPill(page)).toHaveClass(/diluxone-users-state--off/);
		expect((await railPill(page).textContent())?.trim()).not.toBe(theWordForASwitch);
	});
});
