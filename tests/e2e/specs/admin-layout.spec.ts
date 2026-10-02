import type { Page } from '@playwright/test';
import { test, expect } from '../support/fixtures';
import { adminTabs, SCREENS } from '../support/screens';
import {
	ADMIN_RULES,
	FRONT_RULES,
	FRONT_WIDTHS,
	LayoutFinding,
	expectSoundLayout,
	layoutFindings,
} from '../support/layout';
import { accountSection, adminUrl, fillCredentials } from '../support/ui';
import { freshEmail } from '../support/api';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Every screen, measured.
 *
 * The suite next door proves each of these tabs answers, saves and says so.
 * It passed green through every visual bug this plugin has had, because
 * "answers 200 without a PHP notice" is a claim about the server and the bugs
 * were in the browser: a block on top of another block, a column of nothing
 * where a rail should be, a bordered box with nothing in it.
 *
 * What is asserted is in `support/layout.ts`, and it is deliberately not an
 * opinion about the design — only the handful of things that are never right
 * by accident. One test per tab, measured at four widths, so a failure names
 * the screen, the width and the element.
 */

test.use({ storageState: ADMIN_STATE });

test.describe('Every screen holds together', () => {
	for (const tab of adminTabs()) {
		test(tab.name, async ({ page }) => {
			await page.goto(tab.url);

			await expectSoundLayout(page);
		});
	}
});

/**
 * The button that saves a tab is beside it, on every tab that saves.
 *
 * It used to end the form, below forty settings. Now it heads the column
 * beside them, in view while they scroll, and it reaches its form by id. What
 * is asserted is the rule rather than a list of tabs: every form on a tab
 * that posts settings has its button in that box, none has one of its own
 * left at the foot, and the box is where the page can see it at the top.
 * The tabs whose forms are something else — a search, a tool's one action,
 * a filter — are told apart by what they send, not written down here.
 */
test.describe('Every tab saves from beside itself', () => {
	for (const tab of adminTabs()) {
		test(tab.name, async ({ page }) => {
			await page.goto(tab.url);

			const forms = await page.locator('.diluxone-users-admin form[method="post"]').evaluateAll((all: Element[]) =>
				all
					.map((form) => form as HTMLFormElement)
					// A form of settings, not a tool's one button or a row of the
					// list of sessions: those post to admin-post.php or carry
					// a tool's name, and their button is part of the row.
					.filter((form) => !(form.getAttribute('action') ?? '').includes('admin-post.php') && !form.querySelector('input[name="tool"]'))
					.filter((form) => !form.closest('dialog'))
					// The order of the account's sections is saved by the drop
					// that ends a drag; the button in the list is the way to do
					// it from the keyboard, and it belongs to the list.
					.filter((form) => !form.classList.contains('diluxone-users-endpoints__order'))
					.map((form) => ({
						id: form.id,
						// The rail is drawn inside the form on most tabs, so the box
						// can be too: only a button outside the box is its own.
						own: Array.from(form.querySelectorAll('[type="submit"]')).filter(
							(button) => !button.closest('[data-diluxone-users-save]')
						).length,
						beside: document.querySelectorAll(`[data-diluxone-users-save] [form="${form.id}"]`).length,
					}))
			);

			for (const form of forms) {
				expect(form.id, 'a form of settings has an id for its button to name').not.toBe('');
				expect(form.own, `${form.id} keeps no button of its own`).toBe(0);
				expect(form.beside, `${form.id} has its button in the box beside it`).toBe(1);
			}

			if (forms.length > 0) {
				await expect(page.locator('[data-diluxone-users-save]')).toBeInViewport();
			}
		});
	}
});

/**
 * The registry is the whole visual suite's idea of what exists, and a tab
 * nobody wrote down is a tab nothing looks at. So it is checked against the
 * screens themselves: the strip the dashboard draws is read, and a tab in it
 * that is not in the registry fails here rather than going unseen for a
 * release. A screen with one tab draws no strip — there is nothing to compare
 * and nothing to miss.
 */
test.describe('No tab escapes the suite', () => {
	for (const screen of Object.keys(SCREENS)) {
		test(screen, async ({ page }) => {
			await page.goto(adminUrl(screen));

			const drawn = await page
				.locator('.nav-tab-wrapper a.nav-tab')
				.evaluateAll((links: Element[]) =>
					links
						.map((link) => new URL((link as HTMLAnchorElement).href).searchParams.get('tab') ?? '')
						.filter((tab) => tab !== '')
				);

			// A screen of one tab draws no strip: one tab is not navigation.
			expect(drawn.slice().sort()).toEqual(SCREENS[screen].length > 1 ? SCREENS[screen].slice().sort() : []);
		});
	}
});

/**
 * The one screen of the plugin's own that a stranger sees.
 *
 * It is drawn by the site's theme rather than by the dashboard's stylesheet,
 * which is exactly why it is measured too: everything it inherits is
 * somebody else's decision, and the plugin's own blocks still have to stack
 * without touching and stay inside the page on a phone.
 */
test.describe('The public pages hold together', () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	test('the sign-in page, at every width', async ({ page, pages }) => {
		await page.goto(pages.login.url);

		await expectSoundLayout(page, FRONT_RULES, FRONT_WIDTHS);
	});

	test('the sign-in page in tabs, at every width', async ({ page, pages, options }) => {
		await options.set({ diluxone_users_login_layout: 'tabs', diluxone_users_sso_login: 1, diluxone_users_passkey_enabled: 1 });
		await page.goto(pages.login.url);

		await expectSoundLayout(page, FRONT_RULES, FRONT_WIDTHS);
	});

	test('the registration form, at every width', async ({ page, pages }) => {
		await page.goto(pages.register.url);

		await expectSoundLayout(page, FRONT_RULES, FRONT_WIDTHS);
	});

	test('the account area to a stranger, at every width', async ({ page, pages }) => {
		await page.goto(pages.account.url);

		await expectSoundLayout(page, FRONT_RULES, FRONT_WIDTHS);
	});

	/*
	 * The account itself, on both its menus: the one down the side is the one
	 * the 640 rule folds back on top, and every section is a different set of
	 * blocks to stack.
	 */
	for (const layout of ['tabs', 'side'] as const) {
		test(`the account area, menu ${layout}, every section at every width`, async ({ page, pages, site, options }) => {
			await options.set({ diluxone_users_account_layout: layout, diluxone_users_handle_enabled: 1 });

			const email = freshEmail('layout');
			const password = 'e2e-Layout-Password-1!';

			await site.makeUser({ email, password, name: 'Layout Person' });
			await page.goto('/wp-login.php?diluxone-users-admin=1');
			await fillCredentials(page, email, password);
			await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php')), page.locator('#wp-submit').click()]);

			for (const section of ['', 'details', 'accounts', 'security', 'notifications', 'privacy']) {
				await page.goto(section ? accountSection(pages.account.url, section) : pages.account.url);
				await expect(page.locator('.diluxone-users-account').first()).toBeVisible();

				await expectSoundLayout(page, FRONT_RULES, FRONT_WIDTHS);
			}
		});
	}
});

/**
 * A guard on the measuring itself.
 *
 * Every assertion above is "the list came back empty", and a list comes back
 * empty when the measuring is broken exactly as readily as when the screen is
 * right. A suite that has never been seen to fail is a suite nobody has any
 * reason to believe, so every one of the six rules is broken here on purpose,
 * on a real screen, and has to be seen.
 *
 * The break is a stylesheet added to the page rather than an edit to
 * `assets/`: the bug each one imitates is a CSS bug, the screen underneath is
 * the real one, and nothing is left behind for the next test to trip over.
 */
test.describe('The measuring itself can fail', () => {
	const WAYS = adminUrl('diluxone-users-login', 'ways');

	/** Breaks the screen the given way and hands back what was measured. */
	async function bend(page: Page, css: string): Promise<LayoutFinding[]> {
		await page.goto(WAYS);
		await page.addStyleTag({ content: css });

		return layoutFindings(page, ADMIN_RULES);
	}

	function kinds(findings: LayoutFinding[]): string[] {
		return findings.map((one) => one.kind);
	}

	/*
	 * The public pages are measured down to a phone, and that is only worth
	 * something if a page that does not fit a phone is seen not to: a form
	 * given a width only a laptop has, at 390.
	 */
	test('a public page wider than a phone', async ({ browser, pages }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: { width: 390, height: 844 } });
		const phone = await context.newPage();

		try {
			await phone.goto(pages.login.url);
			await phone.addStyleTag({ content: '.diluxone-users-login form { min-width: 600px; }' });

			expect(kinds(await layoutFindings(phone, FRONT_RULES))).toContain('overflow');
		} finally {
			await context.close();
		}
	});

	// The bug that started all this: a block drawn on top of the one above it.
	test('a block on top of another block', async ({ page }) => {
		const findings = await bend(
			page,
			'.diluxone-users-admin .diluxone-users-studio__fields > * + * { margin-top: -40px; }'
		);

		expect(kinds(findings)).toContain('overlap');
	});

	// The room every block leaves under it, taken away: nothing is on top of
	// anything, and the screen is a wall of text.
	test('two blocks with nothing between them', async ({ page }) => {
		const findings = await bend(
			page,
			'.diluxone-users-admin .diluxone-users-studio__aside > * { margin-bottom: 0; }'
		);

		expect(kinds(findings)).toContain('air');
	});

	test('something reaching past the edge of the screen', async ({ page }) => {
		const findings = await bend(
			page,
			'.diluxone-users-admin .diluxone-users-studio__fields { min-width: 2400px; }'
		);

		expect(kinds(findings)).toContain('overflow');
	});

	test('the rail underneath instead of beside', async ({ page }) => {
		const findings = await bend(page, '.diluxone-users-admin .diluxone-users-studio { display: block; }');

		expect(kinds(findings)).toContain('rail');
	});

	/*
	 * The rail's shape, broken the two ways it can be: a screen printing its
	 * own markup into the column, and the state pushed below the manual.
	 * Markup again rather than a stylesheet — both are things a screen does,
	 * not things a stylesheet does.
	 */
	test('a screen printing its own markup into the rail', async ({ page }) => {
		await page.goto(WAYS);
		await page.evaluate(() => {
			const mine = document.createElement('p');

			mine.textContent = 'a paragraph this screen felt like adding';
			document.querySelector('.diluxone-users-studio__aside')?.append(mine);
		});

		expect(kinds(await layoutFindings(page, ADMIN_RULES))).toContain('shape');
	});

	test('how the site stands, said after the manual', async ({ page }) => {
		await page.goto(WAYS);
		await page.evaluate(() => {
			const rail = document.querySelector('.diluxone-users-studio__aside');
			const state = document.createElement('div');

			state.className = 'du-state';
			state.textContent = 'right now, said last';
			rail?.append(state);
		});

		expect(kinds(await layoutFindings(page, ADMIN_RULES))).toContain('shape');
	});

	/*
	 * This one is markup rather than a stylesheet, because that is what the
	 * bug is: a box the screen drew and then had nothing to put in.
	 *
	 * And it is drawn with an empty paragraph inside it on purpose. The
	 * stylesheet already hides a box that is `:empty`, which covers the easy
	 * half; the half it cannot see is a box whose contents came out blank —
	 * a heading with nothing after it, a list of providers on a site with
	 * none — because in CSS's terms that box has children and is not empty.
	 * That is the one this rule is for.
	 */
	test('a bordered box with nothing in it', async ({ page }) => {
		await page.goto(WAYS);
		await page.evaluate(() => {
			const box = document.createElement('div');

			box.className = 'du-note';
			box.append(document.createElement('p'));
			box.style.height = '80px';
			document.querySelector('.diluxone-users-studio__aside')?.append(box);
		});

		expect(kinds(await layoutFindings(page, ADMIN_RULES))).toContain('blank');
	});

	/*
	 * A stylesheet again, and the exact shape of the bug: a component gives
	 * itself a `display`, which outranks the browser's own rule for the
	 * attribute, and the thing the script hid is on the screen after all.
	 * Three components in this plugin met that separately; consolidating
	 * their three answers into one broke the third of them, and nothing but
	 * a photograph noticed. This is the rule that would have.
	 */
	test('something still on screen with hidden on it', async ({ page }) => {
		await page.goto(WAYS);
		await page.evaluate(() => {
			const box = document.createElement('div');

			box.className = 'du-note';
			box.textContent = 'hidden, and on the screen anyway';
			box.hidden = true;
			document.querySelector('.diluxone-users-studio__aside')?.append(box);
		});

		// The component that outranks the attribute, imitated: the same weight
		// as the rule in the stylesheet, and written afterwards.
		await page.addStyleTag({ content: '.diluxone-users-admin .du-note { display: block; }' });

		expect(kinds(await layoutFindings(page, ADMIN_RULES))).toContain('hidden');
	});
});
