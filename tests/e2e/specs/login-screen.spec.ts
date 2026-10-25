import { test, expect, expectSignedOut, stateOf } from '../support/fixtures';
import { adminUrl, savePanel, navigated, ssoButton } from '../support/ui';
import { ADMIN_STATE } from '../../../playwright.config';
import type { Locator, Page } from '@playwright/test';

/**
 * The screen people actually meet, measured and read.
 *
 * Two things this suite could not see before. The first is geometry: the
 * boxes of the form, measured rather than photographed. (The split layout's
 * own geometry is Users+ Pro's, and its suite measures it.)
 *
 * The second is the wording. Every message the way in shows is now the site's
 * to rewrite, and a mechanism nobody proves end to end is a settings screen
 * that writes to a table. So the last two tests take the long way round:
 * type a sentence into the dashboard, press save, then fail a real sign-in
 * with a social network and read what a stranger is told.
 */

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
		await navigated(guest, () => ssoButton(guest, 'mock').click());

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
		await navigated(guest, () => ssoButton(guest, 'mock').click());

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
