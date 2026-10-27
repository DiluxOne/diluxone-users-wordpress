import { test, expect } from '../support/fixtures';
import { freshEmail, waitForMail } from '../support/api';
import {
	adminError,
	adminSaved,
	askForLink,
	emailField,
	linkForm,
	loginWay,
	needsOne,
	openWay,
	passwordForm,
	saveButton,
	savePanel,
	sentScreen,
	submitPanelWithoutScript,
} from '../support/ui';
import { accessTab, control, notNow, storedNumbers } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Access › Ways in, every control driven through the tab.
 *
 * The link and the password are the two the form draws itself, and none of
 * them is not an answer; the other boxes ride on top. Each test ticks or
 * unticks what a person would, presses Save, and then reads the stored value
 * and the page a stranger signs in on.
 */

test.use({ storageState: ADMIN_STATE });

const PASSWORD = 'e2e-Ways-1!';

test.describe('Access › Ways in', () => {
	test.beforeEach(async ({ options }) => {
		await options.keep([
			'diluxone_users_login_method',
			'diluxone_users_login_expiry',
			'diluxone_users_login_throttle',
			'diluxone_users_handle_login',
			'diluxone_users_sso_login',
			'diluxone_users_pro_previews',
			'diluxone_users_wp_screens',
		]);
	});

	test('neither the link nor the password is refused, in the browser and by the save, and nothing is written', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both' });

		await page.goto(accessTab('ways'));
		await loginWay(page, 'link').uncheck();
		await loginWay(page, 'password').uncheck();

		const group = needsOne(page, 'diluxone_users_login_method[]');

		await saveButton(page).click();
		await expect(group).toHaveClass(/is-short/);
		await expect(group.locator('[data-diluxone-users-atleast-one-said]')).toBeVisible();
		await expect(page).toHaveURL(/tab=ways/);

		await submitPanelWithoutScript(page);
		await expect(adminError(page)).toBeVisible();
		await expect(adminSaved(page)).toHaveCount(0);

		expect((await site.getOptions(['diluxone_users_login_method'])).diluxone_users_login_method).toBe('both');

		// The screen shows what is stored, not what was refused.
		await expect(loginWay(page, 'link')).toBeChecked();
		await expect(loginWay(page, 'password')).toBeChecked();

		await guest.goto(pages.login.url);
		await expect(linkForm(guest)).toBeVisible();
		await openWay(guest, 'password');
		await expect(passwordForm(guest)).toBeVisible();
	});

	test('the wait between two requests is the wait the form keeps', async ({ page, guest, site, pages }) => {
		await page.goto(accessTab('ways'));
		await control(page, 'diluxone_users_login_throttle').fill('90');
		await savePanel(page);

		expect((await storedNumbers(site, ['diluxone_users_login_throttle'])).diluxone_users_login_throttle).toBe(90);
		await page.reload();
		await expect(control(page, 'diluxone_users_login_throttle')).toHaveValue('90');

		const email = freshEmail('throttle-ui');

		await site.makeUser({ email });
		await askForLink(guest, pages.login.url, email);
		await waitForMail(site, email);
		await askForLink(guest, pages.login.url, email);

		// The same screen both times, and one message: the second ask fell
		// inside the ninety seconds the tab saved.
		expect(await site.mail(email)).toHaveLength(1);
	});

	test('the public name as well as the address: ticked, the box takes a name and mails the account; unticked, it takes none', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_handle_enabled: 1, diluxone_users_handle_login: 0 });

		const email = freshEmail('handle-ui');

		await site.makeUser({ email, meta: { diluxone_users_handle: 'x' } });

		// The public name is the account's nicename: the address it is
		// reached at, and what the box looks up.
		const handle = String((await site.user(email)).nicename);

		expect(handle).not.toContain('@');

		await page.goto(accessTab('ways'));
		await expect(notNow(page).filter({ has: page.locator('a[href*="tab=handle"]') }), 'public names are on: nothing to point at').toHaveCount(0);
		await control(page, 'diluxone_users_handle_login').check();
		await savePanel(page);
		expect((await storedNumbers(site, ['diluxone_users_handle_login'])).diluxone_users_handle_login).toBe(1);

		await guest.goto(pages.login.url);
		await expect(emailField(guest)).toHaveAttribute('type', 'text');
		await emailField(guest).fill(handle);
		await linkForm(guest).locator('button[type="submit"]').click();
		await expect(sentScreen(guest)).toContainText(handle);

		const mail = await waitForMail(site, email);

		expect(mail.to.toLowerCase(), 'the link goes to the address on the account').toBe(email.toLowerCase());

		await page.goto(accessTab('ways'));
		await control(page, 'diluxone_users_handle_login').uncheck();
		await savePanel(page);
		expect((await storedNumbers(site, ['diluxone_users_handle_login'])).diluxone_users_handle_login).toBe(0);

		await site.clearMail();
		await guest.goto(pages.login.url);
		await expect(emailField(guest), 'the box asks for an address again').toHaveAttribute('type', 'email');

		// Sent anyway, with the browser's own check stepped over: a name is
		// not an address, and nothing goes out.
		await emailField(guest).evaluate((input: HTMLInputElement) => {
			input.type = 'text';
		});
		await emailField(guest).fill(handle);
		await Promise.all([guest.waitForURL(/diluxone-users=/), linkForm(guest).locator('button[type="submit"]').click()]);
		expect(new URL(guest.url()).searchParams.get('diluxone-users')).toBe('email');
		expect(await site.mail(email)).toEqual([]);
	});

	test('with public names off, the link’s card says so and points to where they are turned on', async ({ page, options }) => {
		await options.set({ diluxone_users_handle_enabled: 0 });

		await page.goto(accessTab('ways'));

		const note = notNow(page).filter({ has: page.locator('a[href*="page=diluxone-users-account"][href*="tab=handle"]') });

		await expect(note).toHaveCount(1);
		// It belongs to the link, and hangs off the link's card.
		await expect(page.locator('.du-choice-group').filter({ has: loginWay(page, 'link') }).locator('.diluxone-users-not-now')).toHaveCount(1);
	});

	/*
	 * Passkeys are Users+ Pro's. Without it they are not a box that cannot be
	 * ticked among the ones that can: one card after the real ways in says
	 * what it would add, with nothing in it that is a control, and the
	 * setting that turns the previews off takes it away.
	 */
	test('after the real ways in, the passkey box Users+ Pro brings: disabled, nameless, with the badge and the link, and gone with the previews', async ({ page, options }) => {
		await options.set({ diluxone_users_pro_previews: 1 });

		await page.goto(accessTab('ways'));

		const pro = page.locator('[data-diluxone-users-pro="login/ways/more"]');

		await expect(pro).toHaveCount(1);
		await expect(pro).toContainText('Sign in with a passkey');
		await expect(pro.locator('.diluxone-users-state--pro'), 'the badge').toHaveCount(1);
		await expect(pro.locator('a')).toHaveAttribute('href', /diluxone\.com\/wordpress-plugins\/users\/.*utm_medium=login/);
		await expect(pro.locator('input[type="checkbox"]'), 'the box it would be').toBeDisabled();
		await expect(pro.locator('[name]'), 'nothing in it posts').toHaveCount(0);
		await expect(control(page, 'diluxone_users_passkey_enabled'), 'no passkey box among the real ones').toHaveCount(0);

		const after = await control(page, 'diluxone_users_sso_login').evaluate(
			(box, card) => Boolean(box.compareDocumentPosition(card as Node) & Node.DOCUMENT_POSITION_FOLLOWING),
			await pro.elementHandle()
		);

		expect(after, 'the box comes after the real ways in').toBe(true);

		await options.set({ diluxone_users_pro_previews: 0 });
		await page.reload();
		await expect(page.locator('[data-diluxone-users-pro]')).toHaveCount(0);
		await expect(control(page, 'diluxone_users_sso_login')).toHaveCount(1);
	});

	/*
	 * wp-login.php's form takes a password and nothing else, so with the
	 * password gone it stops being a second door — and the save writes that,
	 * rather than leaving "a second screen" stored under a site it cannot
	 * apply to.
	 */
	test('unticking the password also takes wp-login.php off as a second screen', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_wp_screens: 'wp' });

		await guest.goto('/wp-login.php');
		await expect(guest.locator('#loginform'), 'the second screen, before the save').toBeVisible();

		await page.goto(accessTab('ways'));
		await loginWay(page, 'password').uncheck();
		await savePanel(page);

		expect(await site.getOptions(['diluxone_users_login_method', 'diluxone_users_wp_screens'])).toEqual({
			diluxone_users_login_method: 'link',
			diluxone_users_wp_screens: 'auto',
		});

		await guest.goto('/wp-login.php');
		expect(new URL(guest.url()).pathname).toBe(new URL(pages.login.url).pathname);
	});

	/*
	 * A setting a site pins from code wins over the screen. The tab says so,
	 * names the function that does it, and a save of a different answer
	 * leaves the pinned one in force on the page.
	 */
	test('a way in pinned from code: the tab names who pins it, and the page keeps the pinned answer whatever is saved', async ({ page, guest, site, pages, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_e2e_admin_access_force: { diluxone_users_login_method: 'link' } });

		await page.goto(accessTab('ways'));

		const forced = page.locator('.diluxone-users-forced');

		await expect(forced).toHaveCount(1);
		await expect(forced.locator('code')).toContainText('diluxone_e2e_admin_access_forced()');

		// The boxes show what is in force.
		await expect(loginWay(page, 'password')).not.toBeChecked();

		await loginWay(page, 'password').check();
		await savePanel(page);
		expect((await site.getOptions(['diluxone_users_login_method'])).diluxone_users_login_method).toBe('both');

		await guest.goto(pages.login.url);
		await expect(linkForm(guest)).toBeVisible();
		await expect(passwordForm(guest), 'the pinned answer is not what the page drew').toHaveCount(0);

		// Taken out of the code, the notice goes and the stored answer is back.
		await options.set({ diluxone_e2e_admin_access_force: {} });
		await page.reload();
		await expect(forced).toHaveCount(0);
		await expect(loginWay(page, 'password')).toBeChecked();
	});
});
