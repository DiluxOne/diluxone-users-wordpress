import { Page } from '@playwright/test';
import { test, expect } from './support';
import { wp } from '../support/cli';
import { uploadImage } from '../support/signin';
import { ASTRA, activateTheme, astraDark, cardEdge, cardPieces, cardRhythm } from '../support/themes';
import { NETWORK_ADMIN_STATE, NETWORK_URL } from '../../../playwright.network.config';

/**
 * The centred card on a network: the same claims as the single site's suite,
 * on the hub, which is the site of the network that draws the sign-in page.
 * The theme is the hub's own, enabled on the network for as long as the
 * suite needs it and put back when it ends; these run one at a time.
 */

test.describe.configure({ mode: 'serial' });

const CARD = {
	diluxone_users_login_template: 'card',
	diluxone_users_styles: 1,
	diluxone_users_colors: 'theme',
	diluxone_users_login_method: 'link',
	diluxone_users_login_title: 'E2E card heading',
	diluxone_users_login_intro: 'E2E card introduction, long enough to run onto a second line inside the box',
	diluxone_users_login_legal: 'E2E card small print',
	diluxone_users_login_logo_show: 1,
};

/** WP-CLI on the hub. */
const hubCli = (args: string[]) => wp(args, `${NETWORK_URL}/`);

async function opened(page: Page, url: string): Promise<void> {
	await page.emulateMedia({ colorScheme: 'dark' });
	await page.goto(url);
	await expect(page.locator('.diluxone-users-login-frame--card .diluxone-users-login')).toBeVisible();
}

test.describe('The hub’s centred card on Astra, in dark mode', () => {
	const undo: Array<() => void> = [];

	test.beforeAll(() => {
		undo.push(activateTheme(hubCli, ASTRA, true));
		undo.push(astraDark(hubCli));
	});

	test.afterAll(() => {
		for (const step of undo.reverse()) {
			step();
		}
	});

	test('C1: the card’s edge is at 3:1 or more against the page', async ({ guest, hub }) => {
		await hub.set(CARD);
		await opened(guest, hub.pages.login.url);

		await expect.poll(async () => (await cardEdge(guest)).ratio, { message: 'the edge against the page' }).toBeGreaterThanOrEqual(3);
	});

	test('C2: the logo, the heading, the introduction and the form share one alignment, the centre', async ({ browser, guest, hub }) => {
		const image = await uploadImage(browser, hub.url, NETWORK_ADMIN_STATE);

		try {
			await hub.set({ ...CARD, diluxone_users_login_logo: image.id });
			await opened(guest, hub.pages.login.url);

			const pieces = await cardPieces(guest);

			expect(pieces.filter((piece) => piece.offset > 1).map((piece) => `${piece.what} is ${piece.offset.toFixed(1)}px off the centre`), 'centred in the box').toEqual([]);
			expect(pieces.filter((piece) => piece.what !== 'form' && piece.align !== 'center').map((piece) => `${piece.what}: ${piece.align}`), 'text-align: center').toEqual([]);
		} finally {
			await image.forget();
		}
	});

	test('C3: the e-mail note and the small print are at least a field’s gap apart', async ({ guest, hub }) => {
		await hub.set(CARD);
		await opened(guest, hub.pages.login.url);

		const rhythm = await cardRhythm(guest);

		expect(rhythm.field, 'a field keeps a gap under it').toBeGreaterThan(0);
		expect(rhythm.gap, 'from the note to the small print').toBeGreaterThanOrEqual(rhythm.field);
	});

	test('C3: a site that sets the field gap moves the card’s rhythm with it', async ({ guest, hub }) => {
		await hub.set(CARD);
		await opened(guest, hub.pages.login.url);
		await guest.addStyleTag({ content: ':root { --diluxone-users-field-gap: 40px; }' });

		const rhythm = await cardRhythm(guest);

		expect(rhythm.field, 'the site’s gap').toBe(40);
		expect(rhythm.gap, 'from the note to the small print').toBeGreaterThanOrEqual(40);
	});
});
