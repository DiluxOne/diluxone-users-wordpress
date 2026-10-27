import { test, expect, toTheHub, whoOn } from './support';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink, navigated } from '../support/ui';

/**
 * The required fields missing, asked on the hub after a sign-in from another
 * site of the network.
 *
 * The step is the hub's account page, where the network keeps its people's
 * details, and it carries the way back: saved, the person is on the site they
 * started from.
 */

const FIRST_NAME_REQUIRED = [
	{ key: 'first_name', label: 'First name', type: 'text', required: 1, group: 'main', active: 1 },
	{ key: 'last_name', label: 'Last name', type: 'text', required: 0, group: 'main', active: 1 },
];

test('signing in by link from /beta/ asks for the name on the hub, and the save goes back to /beta/', async ({ guest, hub, beta }) => {
	// An add-on that asks for them, as Users+ Pro does: the plugin alone never asks.
	await hub.set({ diluxone_users_fields: FIRST_NAME_REQUIRED, diluxone_users_2fa_mode: 'off', diluxone_e2e_ask_missing: 1 });

	const email = freshEmail('net-missing');

	await beta.site.makeUser({ email });

	const back = await toTheHub(guest, beta, hub);
	const asked = Date.now() / 1000;

	await askForLink(guest, guest.url(), email);
	await guest.goto(linkIn(await waitForMail(hub.site, email, { after: asked - 1 })));

	const step = new URL(guest.url());

	expect(step.href.startsWith(hub.pages.account.url), `the hub’s account page, at ${step.href}`).toBe(true);
	expect(step.searchParams.get('diluxone-users-complete')).toBe('1');
	expect(step.searchParams.get('redirect_to') ?? '', 'carrying the way back').toContain(back);

	const form = guest.locator('.diluxone-users-complete form.diluxone-users-form');

	await expect(form.locator('input[name="first_name"]')).toBeVisible();
	await expect(form.locator('input[name="last_name"]'), 'only what is missing').toHaveCount(0);

	// In the account area's frame, its title before its line (#139).
	const frame = guest.locator('.diluxone-users-account--contained .diluxone-users-account__body .diluxone-users-complete');
	await expect(frame).toHaveCount(1);
	const titleBox = await frame.locator('.diluxone-users-fields__title').boundingBox();
	const introBox = await frame.locator('.diluxone-users-complete__intro').boundingBox();
	expect(titleBox!.y, 'the title comes before the line').toBeLessThan(introBox!.y);
	expect((await form.boundingBox())!.width, 'held to the reading width').toBeLessThanOrEqual(981);

	await form.locator('input[name="first_name"]').fill('Ada');
	await navigated(guest, () => form.locator('button[type="submit"]').click());

	expect(guest.url().startsWith(back), `back on /beta/, at ${guest.url()}`).toBe(true);
	expect(await whoOn(guest, beta.url), 'signed in there').toBe(email);
});
