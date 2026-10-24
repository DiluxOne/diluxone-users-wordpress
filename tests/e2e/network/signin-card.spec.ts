import { test, expect } from './support';
import { wp } from '../support/cli';
import { NETWORK_URL } from '../../../playwright.network.config';
import { ASTRA, activateTheme, astraDark, cardEdge } from '../support/themes';

/**
 * The centred card on a network: the same claims as the single site's suite,
 * on the hub, which is the site of the network that draws the sign-in page.
 * The theme is the hub's own, enabled on the network for as long as the
 * suite needs it and put back when it ends; these run one at a time.
 */

test.describe.configure({ mode: 'serial' });

const CARD = { diluxone_users_login_template: 'card', diluxone_users_styles: 1, diluxone_users_colors: 'theme' };

test.describe('The hub’s centred card on Astra, in dark mode', () => {
	const undo: Array<() => void> = [];

	test.beforeAll(() => {
		const cli = (args: string[]) => wp(args, `${NETWORK_URL}/`);

		undo.push(activateTheme(cli, ASTRA, true));
		undo.push(astraDark(cli));
	});

	test.afterAll(() => {
		for (const step of undo.reverse()) {
			step();
		}
	});

	test('C1: the card’s edge is at 3:1 or more against the page', async ({ guest, hub }) => {
		await hub.set(CARD);
		await guest.emulateMedia({ colorScheme: 'dark' });
		await guest.goto(hub.pages.login.url);

		await expect.poll(async () => (await cardEdge(guest)).ratio, { message: 'the edge against the page' }).toBeGreaterThanOrEqual(3);
	});
});
