import { test, expect, expectSignedIn, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail, linkIn, waitForMail } from '../support/api';
import { askForLink, emailField, linkForm, notice, openWay, sentScreen } from '../support/ui';

/**
 * The link by e-mail, end to end.
 *
 * This is the door the plugin exists for, and the one flow that cannot be
 * proved without a browser and a mailbox: the form posts to admin-post.php,
 * the answer is a redirect, the credential arrives by mail, and the session
 * only exists once a real cookie was set on a real browser.
 *
 * Covers T-LOGIN-04 of the plan, plus the answers around it — an address
 * nobody has seen before, a link opened twice, a link that went stale, and a
 * token pointed at the wrong account.
 */

test.describe('Signing in with the link that arrives by e-mail', () => {
	test('ask for it, open it, and the second time it is spent', async ({ page, site, pages }) => {
		const email = freshEmail('link');

		await site.makeUser({ email, name: 'Ada Lovelace' });

		await page.goto(pages.login.url);

		// The form the shortcode drew, not wp-login.php — and its tab pressed
		// first, because with three ways in the sign-in page arranges them as
		// tabs and the other two carry `hidden`. On a site with two it does
		// nothing.
		await openWay(page, 'email');
		await emailField(page).fill(email);
		await linkForm(page).locator('button[type="submit"]').click();

		// The screen says the same thing whatever happened, which is the point:
		// it is not a detector of who has an account.
		await expect(sentScreen(page)).toContainText(email);
		expect(stateOf(page.url())).toBe('sent');

		const mail = await waitForMail(site, email);
		const link = linkIn(mail);

		expect(link, 'the link carries who it is for and the token').toMatch(
			/diluxone_users_login=\d+.*diluxone_users_token=/
		);

		await page.goto(link);
		await expectSignedIn(page, email);

		// The token is burnt on use. A second visit has to say so rather than
		// silently opening a session for whoever found the mail later.
		await page.context().clearCookies();
		await page.goto(link);

		await expectSignedOut(page);
		await expect(notice(page, 'error')).toBeVisible();
		expect(stateOf(page.url())).toBe('expired');
	});

	test('an address nobody has seen before gets an account once its link is opened, and gets in', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_login_register: 1, diluxone_users_login_role: 'subscriber' });

		const email = freshEmail('new');

		expect((await site.user(email)).exists, 'nobody should have this address yet').toBe(false);

		await askForLink(page, pages.login.url, email);

		const link = linkIn(await waitForMail(site, email));

		// Asking proves nothing — anybody can type somebody else's address —
		// so the account waits for the link to be opened (#134).
		expect((await site.user(email)).exists, 'asking for the link must create no account').toBe(false);
		expect(link, 'the link carries a pending sign-up, never the address').toMatch(
			/diluxone_users_signup=[a-z0-9]+.*diluxone_users_token=/
		);
		expect(link).not.toContain(encodeURIComponent(email));

		await page.goto(link);
		await expectSignedIn(page, email);

		const made = await site.user(email);

		expect(made.exists, 'opening the link was supposed to create the account').toBe(true);
		expect(made.roles).toEqual(['subscriber']);
	});

	test('with self-registration off, an unknown address gets the same screen and no account', async ({
		page,
		site,
		pages,
		options,
	}) => {
		await options.set({ diluxone_users_login_register: 0, diluxone_users_register_form: 0 });

		const email = freshEmail('closed');

		// Identical answer: that is what stops the form being used to find out
		// who is registered here.
		await askForLink(page, pages.login.url, email);

		expect((await site.user(email)).exists, 'no account may be created').toBe(false);
		expect(await site.mail(email), 'and nothing may be sent').toEqual([]);
	});

	test('a link whose time ran out does not open a session', async ({ page, site, pages }) => {
		const email = freshEmail('stale');

		await site.makeUser({ email });
		await askForLink(page, pages.login.url, email);

		const link = linkIn(await waitForMail(site, email));

		// Rather than waiting fifteen minutes for it.
		await site.expire(email, 'link');

		await page.goto(link);

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('expired');
		await expect(notice(page, 'error')).toBeVisible();
	});

	test('a token that belongs to somebody else is refused', async ({ page, site, pages }) => {
		const mine = freshEmail('mine');
		const theirs = freshEmail('theirs');

		const me = await site.makeUser({ email: mine });
		await site.makeUser({ email: theirs });

		await askForLink(page, pages.login.url, theirs);

		const link = new URL(linkIn(await waitForMail(site, theirs)));

		// Their token, my user id.
		link.searchParams.set('diluxone_users_login', String(me.id));

		await page.goto(link.toString());

		await expectSignedOut(page);
		expect(stateOf(page.url())).toBe('expired');
	});

	test('asking twice in a row sends one e-mail, and says nothing about it', async ({ page, site, pages, options }) => {
		await options.set({ diluxone_users_login_throttle: 120 });

		const email = freshEmail('throttle');
		await site.makeUser({ email });

		await askForLink(page, pages.login.url, email);
		await askForLink(page, pages.login.url, email);

		// The second answer is identical and no second link went out: the wait
		// is a limit, not a message.
		expect(await site.mail(email)).toHaveLength(1);
	});
});
