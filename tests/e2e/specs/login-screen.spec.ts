import { test, expect, expectSignedOut, stateOf } from '../support/fixtures';
import { adminUrl, savePanel, ssoButton } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';
import type { Locator, Page } from '@playwright/test';

/**
 * The screen people actually meet, measured and read.
 *
 * Two things this suite could not see before. The first is geometry: the
 * split layout stretched its blue panel to whatever height the form happened
 * to be, so on a laptop the one sentence the page came to say sat halfway
 * down a column twice the height of the window — below the fold, on a screen
 * that is nothing but that sentence and a form. Three suites were green while
 * that shipped, because none of them can see a page.
 *
 * The second is the wording. Every message the way in shows is now the site's
 * to rewrite, and a mechanism nobody proves end to end is a settings screen
 * that writes to a table. So the last two tests take the long way round:
 * type a sentence into the dashboard, press save, then fail a real sign-in
 * with a social network and read what a stranger is told.
 *
 * The widths are the ones the complaint came from — "monitores chicos que son
 * relativamente grandes". 1366×768 is the commonest laptop there is and 1280×720
 * the floor under it.
 */

const LAPTOPS = [
	{ width: 1366, height: 768 },
	{ width: 1280, height: 720 },
] as const;

/** A panel with something written on it, which is what makes it a panel. */
const PANEL = {
	diluxone_users_login_template: 'split',
	diluxone_users_login_panel_title: 'Una cuenta.\nSin contraseñas.',
	diluxone_users_login_panel_text: 'Entrá con tu correo, con una red o con una passkey.',
	diluxone_users_login_panel_points: 'Sin contraseñas que recordar\nUn segundo paso cuando hace falta\nTus sesiones, a la vista',
	diluxone_users_login_panel_foot: '© Un sitio cualquiera',
};

/** The blue panel and the form, as rectangles. */
async function splitBoxes(page: Page) {
	return page.evaluate(() => {
		const rect = (selector: string) => {
			const element = document.querySelector(selector);

			if (!element) {
				return null;
			}

			const box = element.getBoundingClientRect();

			return { top: box.top, bottom: box.bottom, left: box.left, right: box.right, height: box.height };
		};

		return {
			window: window.innerHeight,
			scrolled: window.scrollY,
			sideways: document.documentElement.scrollWidth - document.documentElement.clientWidth,
			frame: rect('.diluxone-users-login-frame--split'),
			panel: rect('.diluxone-users-login-frame__picture'),
			words: rect('.diluxone-users-login-frame__words'),
			form: rect('.diluxone-users-login-frame__box'),
			title: rect('.diluxone-users-login-frame__title'),
		};
	});
}

test.describe('The sign-in page, split down the middle', () => {
	test.beforeEach(async ({ options }) => {
		await options.set(PANEL);
	});

	/**
	 * The one the complaint was about.
	 *
	 * "Visible without scrolling" is the whole of it: the assertion is taken
	 * with the page at the top, which is where somebody who has just arrived
	 * is. A heading that can be reached by scrolling is not a heading on a
	 * page whose only job is to be understood in one look.
	 */
	test('on a laptop, the panel’s heading is on screen before anybody scrolls', async ({ guest, pages, options }) => {
		// The longest the form gets: a passkey, the networks, an address and a
		// password. That is the case the panel was being stretched by.
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
		});

		for (const size of LAPTOPS) {
			await guest.setViewportSize(size);
			await guest.goto(pages.login.url);

			const seen = await splitBoxes(guest);
			const where = `${size.width}×${size.height}`;

			expect(seen.sideways, `${where}: nothing on this page may scroll sideways`).toBeLessThanOrEqual(1);
			expect(seen.title, `${where}: the panel draws no heading`).not.toBeNull();
			expect(seen.scrolled, `${where}: the page must be measured from the top`).toBe(0);

			expect(
				Math.round(seen.title!.bottom),
				`${where}: the whole heading has to be on screen without scrolling`
			).toBeLessThanOrEqual(seen.window);
			expect(Math.round(seen.title!.top), `${where}: and it starts on screen too`).toBeGreaterThanOrEqual(0);
		}
	});

	/**
	 * The panel is never taller than the window, and never shorter than what
	 * is written on it.
	 *
	 * Both halves matter. Taller than the window is the bug: a column of
	 * colour that cannot be seen at once, with its content spread through it.
	 * Shorter than its own words is the other way to get there — the words
	 * overflowed the panel by exactly its padding until the frame started
	 * counting its own borders, and the closing line was drawn on the page
	 * below the blue.
	 */
	test('the panel fits the window, and its words fit the panel', async ({ guest, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_passkey_enabled: 1 });

		for (const size of LAPTOPS) {
			await guest.setViewportSize(size);
			await guest.goto(pages.login.url);

			const seen = await splitBoxes(guest);
			const where = `${size.width}×${size.height}`;

			expect(
				Math.round(seen.panel!.height),
				`${where}: the panel is taller than the window it has to be read in`
			).toBeLessThanOrEqual(seen.window);

			expect(
				Math.round(seen.words!.bottom),
				`${where}: what is written on the panel runs past the bottom of it`
			).toBeLessThanOrEqual(Math.round(seen.panel!.bottom));
		}
	});

	/**
	 * With a short form the two columns are one height, exactly.
	 *
	 * This is the half that says "no deforming": the fix for a form longer
	 * than the window must not turn into a panel that is a stripe beside a
	 * form, or a form floating in a column of colour. When there is room for
	 * both, they are the same rectangle's height.
	 */
	test('with a short form the two columns agree on one height', async ({ guest, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'link',
			diluxone_users_passkey_enabled: 0,
			diluxone_e2e_sso: 0,
		});

		for (const size of LAPTOPS) {
			await guest.setViewportSize(size);
			await guest.goto(pages.login.url);

			const seen = await splitBoxes(guest);
			const where = `${size.width}×${size.height}`;

			expect(
				Math.abs(seen.panel!.height - seen.form!.height),
				`${where}: the blue panel and the form are different heights`
			).toBeLessThanOrEqual(1);

			expect(Math.round(seen.frame!.top), `${where}: the two columns start level`).toBe(
				Math.round(seen.panel!.top)
			);
		}
	});

	/**
	 * A form that is genuinely longer than the window scrolls; the panel does
	 * not go with it.
	 *
	 * Twelve networks, a passkey, an address and a password is a real screen
	 * and it is longer than a laptop. What must not happen is the panel — and
	 * with it the only sentence explaining what this page is — being carried
	 * off the top of the window by the form beside it.
	 *
	 * How far to scroll is read off the page rather than picked: to where the
	 * end of the form meets the bottom of the window, which is as far as
	 * anybody signing in ever goes. Past that the panel does leave, and it
	 * should — it keeps its own column and comes to rest at the end of it,
	 * which is the difference between a column that follows the page and one
	 * nailed over the top of it.
	 */
	test('the panel holds while a long form goes past it', async ({ guest, pages, options }) => {
		await options.set({
			diluxone_users_login_method: 'both',
			diluxone_users_passkey_enabled: 1,
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			// Stacked, said out loud. Left on 'auto' this many ways in go
			// behind tabs, which is the whole point of tabs — and then the
			// form is one panel tall and there is nothing for the panel
			// beside it to hold against. This test is about the column, so
			// it asks for the arrangement that gives it a long form.
			diluxone_users_login_layout: 'stack',
		});

		await guest.setViewportSize(LAPTOPS[0]);
		await guest.goto(pages.login.url);

		const atRest = await splitBoxes(guest);

		// Only worth asking when the form really is longer than the window;
		// otherwise there is nothing to scroll and the test proves nothing.
		expect(atRest.form!.height, 'this configuration should give a form taller than the window').toBeGreaterThan(
			atRest.window
		);

		const toTheEnd = Math.round(atRest.frame!.bottom - atRest.window);

		expect(toTheEnd, 'the form should reach below the fold for this to mean anything').toBeGreaterThan(
			atRest.window / 2
		);

		await guest.evaluate((y) => window.scrollTo(0, y), toTheEnd);
		await guest.waitForFunction((y) => Math.round(window.scrollY) >= y - 1, toTheEnd);

		const moved = await splitBoxes(guest);

		// A pixel of slack, and `abs` because a browser that lands on -0.4
		// rounds to a negative zero, which `toBe(0)` refuses.
		expect(
			Math.abs(Math.round(moved.panel!.top)),
			'the panel was carried off the top of the window by the form beside it'
		).toBeLessThanOrEqual(1);
		expect(
			Math.round(moved.title!.bottom),
			'and its heading is still readable at the end of the form'
		).toBeLessThanOrEqual(moved.window);
	});
});

/* ── What the screen says when it goes wrong ───────────────────────── */

/**
 * Opens the box one message lives in and hands back its text area.
 *
 * Every message on the tab is a `<details>` that starts shut, which is what
 * makes a screen of thirteen of them readable. Pressing an open one would
 * close it, so the state is asked first.
 */
async function messageField(page: Page, key: string): Promise<Locator> {
	const box = page.locator(`details[data-diluxone-users-rewritable="${key}"]`);

	await expect(box).toBeAttached();

	if (!(await box.evaluate((element: HTMLDetailsElement) => element.open))) {
		await box.locator('> summary').click();
	}

	const field = box.locator(`textarea[name="diluxone_users_message[${key}]"]`);

	await expect(field).toBeVisible();

	return field;
}

/** The message the sign-in page is showing, by which message it is. */
function shownMessage(page: Page, key: string): Locator {
	return page.locator(`[data-diluxone-users-message="${key}"]`);
}

test.describe('The words the sign-in page shows when something fails', () => {
	test.use({ storageState: ADMIN_STATE });

	const MINE = 'Esa red no nos respondió. Probá con tu correo o escribinos a hola@ejemplo.test.';

	test.beforeEach(async ({ options }) => {
		// The tab writes this option itself, from a form: nothing here can
		// intercept that, so what it was is written down instead.
		await options.keep(['diluxone_users_login_messages']);

		await options.set({
			diluxone_e2e_sso: 1,
			diluxone_users_sso: { mock: { active: 1, id: 'e2e-client-id', secret: 'e2e-client-secret', tested: 1 } },
			diluxone_users_sso_login: 1,
			diluxone_users_2fa_mode: 'off',
		});
	});

	/**
	 * The whole way round: type it in the dashboard, then fail a real sign-in.
	 *
	 * Not `?diluxone-users=social` typed into the address bar — that would
	 * prove the template reads a query argument. This is the trip the mu-plugin
	 * answers as a network that refuses, which is the path a stranger takes,
	 * and the sentence read at the end of it is the one somebody wrote on a
	 * settings screen.
	 */
	test('a message rewritten in the dashboard is the one a stranger reads', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-login', 'messages'));

		const field = await messageField(page, 'login_social');
		const shipped = await field.inputValue();

		expect(shipped.trim(), 'the box opens with the plugin’s own words, never blank').not.toBe('');

		await field.fill(MINE);
		await savePanel(page);

		// It came back written, and the box on the reloaded screen holds it.
		await expect(await messageField(page, 'login_social')).toHaveValue(MINE);

		// Now the failure itself, in the other browser, with nobody signed in.
		await site.setIdentity({ deny: true });

		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();

		await expectSignedOut(guest);
		expect(stateOf(guest.url()), 'back at the sign-in page with the social state').toBe('social');

		await expect(shownMessage(guest, 'login_social')).toHaveText(MINE);
		await expect(shownMessage(guest, 'login_social')).toHaveClass(/diluxone-users-notice--error/);
	});

	/**
	 * And the way back, which is the half a settings screen usually forgets.
	 *
	 * The tick box is only drawn once there is something to undo, so this also
	 * proves the screen knows the difference between a site that wrote its own
	 * words and one that only read them.
	 */
	test('putting the plugin’s own words back is one tick box away', async ({ page, guest, site, pages }) => {
		await page.goto(adminUrl('diluxone-users-login', 'messages'));

		const field = await messageField(page, 'login_social');
		const shipped = (await field.inputValue()).trim();

		await expect(
			page.locator('input[name="diluxone_users_message_shipped[login_social]"]'),
			'with nothing rewritten there is nothing to put back'
		).toHaveCount(0);

		await field.fill(MINE);
		await savePanel(page);

		await messageField(page, 'login_social');

		const back = page.locator('input[name="diluxone_users_message_shipped[login_social]"]');

		await expect(back, 'once the site has written its own, the way back appears').toBeVisible();

		await back.check();
		await savePanel(page);

		await expect(await messageField(page, 'login_social')).toHaveValue(shipped);
		await expect(
			page.locator('input[name="diluxone_users_message_shipped[login_social]"]'),
			'and the way back is gone again, because there is nothing to undo'
		).toHaveCount(0);

		// The front end agrees, which is the only reading that counts.
		await site.setIdentity({ deny: true });

		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();

		await expect(shownMessage(guest, 'login_social')).toHaveText(shipped);
	});

	/**
	 * Saving without changing a word is not writing your own words.
	 *
	 * It is the rule that keeps a site receiving better wording from every
	 * update: somebody who opens the tab, reads a message and presses save has
	 * said nothing, and a screen that recorded that would quietly freeze them
	 * on today's sentence for ever.
	 */
	test('reading a message and pressing save records nothing', async ({ page }) => {
		await page.goto(adminUrl('diluxone-users-login', 'messages'));

		await messageField(page, 'login_social');
		await savePanel(page);

		await messageField(page, 'login_social');

		await expect(
			page.locator('input[name="diluxone_users_message_shipped[login_social]"]'),
			'the site was recorded as having rewritten a message it only looked at'
		).toHaveCount(0);
	});
});

/**
 * Every box on the sign-in page is as wide as the card.
 *
 * A width cap written for the account area's panels once reached the sign-in
 * form too, and left the address box a hundred pixels narrower than the
 * password box under it. The pictures did not see it: a thin border moving
 * is well under the share of pixels a picture tolerates. A measurement does.
 */
test.describe('The sign-in page’s boxes', () => {
	test('share one width, the address box and the password boxes alike', async ({ page, pages }) => {
		await page.goto(pages.login.url);

		const widths = await page
			.locator('.diluxone-users-login input[type="email"], .diluxone-users-login input[type="text"], .diluxone-users-login input[type="password"]')
			.evaluateAll((boxes) => boxes.filter((box) => (box as HTMLElement).offsetParent !== null).map((box) => Math.round(box.getBoundingClientRect().width)));

		expect(widths.length, 'the address box and the password form are both on the page').toBeGreaterThanOrEqual(3);
		expect(Math.max(...widths) - Math.min(...widths), `widths ${widths.join(', ')}`).toBeLessThanOrEqual(1);
	});
});
