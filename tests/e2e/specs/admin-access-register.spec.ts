import { test, expect, expectSignedOut, stateOf } from '../support/fixtures';
import { freshEmail } from '../support/api';
import { adminError, adminSaved, askForLink, needsOne, registerScreen, savePanel, ssoButton } from '../support/ui';
import { MOCK_SSO, PLAIN_FIELDS, accessTab, card, control, expectPill, notNow, rail, storedNumbers } from '../support/admin-access';
import { ADMIN_STATE } from '../../../playwright.config';

/**
 * Access › Registration, driven through its controls.
 *
 * Every door into an account is a box on this tab, and the answer above them
 * — open, or nobody registers — decides whether they count. Each test changes
 * the tab the way an administrator does, presses Save, reads the tab back
 * after the reload and the stored value through the side door, and then asks
 * the public side, as a stranger, whether the door is what the tab says.
 */

test.use({ storageState: ADMIN_STATE });

const DOORS = ['diluxone_users_login_register', 'diluxone_users_sso_register', 'diluxone_users_register_form'];

test.describe('Access › Registration', () => {
	test.beforeEach(async ({ options }) => {
		await options.set({ diluxone_users_fields: PLAIN_FIELDS });
		await options.keep([...DOORS, 'users_can_register', 'diluxone_users_register_page', 'diluxone_users_login_role', 'diluxone_users_rewrite_version']);
	});

	/*
	 * The answer that needs no door is the one a refusing rule is most likely
	 * to get wrong: every box unticked is exactly what "nobody registers"
	 * means, and the group that wants one ticked sits folded inside the other
	 * answer. So the doors are unticked by hand while open, then the answer
	 * is changed, and the press has to go through.
	 */
	test('“nobody can register” saves with every door unticked, and every door is shut', async ({ page, guest, site, pages, options }) => {
		await options.set({
			...MOCK_SSO,
			diluxone_users_login_method: 'both',
			diluxone_users_login_register: 1,
			diluxone_users_sso_register: 1,
			diluxone_users_register_form: 1,
			users_can_register: 1,
		});

		await page.goto(accessTab('register'));

		const group = needsOne(page, 'diluxone_users_login_register');

		for (const door of [...DOORS, 'diluxone_users_wp_register']) {
			await control(page, door).uncheck();
		}

		await control(page, 'diluxone_users_register_open', 'closed').check();

		// The group hangs off the answer that is no longer chosen: it is not
		// asked, so it is not marked, and the save goes through.
		await savePanel(page);
		await expect(group).not.toHaveClass(/is-short/);

		expect(await storedNumbers(site, [...DOORS, 'users_can_register'])).toEqual({
			diluxone_users_login_register: 0,
			diluxone_users_sso_register: 0,
			diluxone_users_register_form: 0,
			users_can_register: 0,
		});

		// The tab comes back saying what was saved.
		await page.reload();
		await expect(control(page, 'diluxone_users_register_open', 'closed')).toBeChecked();
		await expect(rail(page).locator('.du-notice, .notice').filter({ has: page.locator('a[href*="page=diluxone-users-fields"]') })).toHaveCount(1);

		// The registration page draws no form.
		await guest.goto(pages.register.url);
		await expect(registerScreen(guest)).toBeVisible();
		await expect(guest.locator('input[name="diluxone_users_email"]')).toHaveCount(0);

		// The e-mail link makes nobody an account.
		const stranger = freshEmail('closed-link');

		await askForLink(guest, pages.login.url, stranger);
		expect((await site.user(stranger)).exists, 'the link door created an account').toBe(false);
		expect(await site.mail(stranger)).toEqual([]);

		// Nor does a social account nobody knows.
		const social = freshEmail('closed-sso');

		await site.setIdentity({ sub: `mock|${social}`, email: social, email_verified: true });
		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();
		await guest.waitForURL(/diluxone-users=/);
		expect(stateOf(guest.url())).toBe('social');
		expect((await site.user(social)).exists, 'the social door created an account').toBe(false);

		// And WordPress's own form is closed.
		await guest.goto('/wp-login.php?action=register');
		await expect(guest.locator('#registerform')).toHaveCount(0);
	});

	test('the social door, unticked and ticked again through the tab', async ({ page, guest, site, pages, options }) => {
		await options.set({ ...MOCK_SSO, diluxone_users_sso_login: 1, diluxone_users_sso_register: 1, diluxone_users_login_register: 1, diluxone_users_2fa_mode: 'off' });

		await page.goto(accessTab('register'));
		await control(page, 'diluxone_users_register_open', 'open').check();
		await control(page, 'diluxone_users_sso_register').uncheck();
		await savePanel(page);

		await page.reload();
		await expect(control(page, 'diluxone_users_sso_register')).not.toBeChecked();
		expect(Number((await site.getOptions(['diluxone_users_sso_register'])).diluxone_users_sso_register)).toBe(0);

		const turnedAway = freshEmail('sso-off');

		await site.setIdentity({ sub: `mock|${turnedAway}`, email: turnedAway, email_verified: true });
		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();
		await guest.waitForURL(/diluxone-users=/);
		await expectSignedOut(guest);
		expect(stateOf(guest.url())).toBe('social');
		expect((await site.user(turnedAway)).exists, 'a social account created an account with the door shut').toBe(false);

		await control(page, 'diluxone_users_sso_register').check();
		await savePanel(page);
		expect(Number((await site.getOptions(['diluxone_users_sso_register'])).diluxone_users_sso_register)).toBe(1);

		const welcome = freshEmail('sso-on');

		await site.setIdentity({ sub: `mock|${welcome}`, email: welcome, email_verified: true });
		await guest.context().clearCookies();
		await guest.goto(pages.login.url);
		await ssoButton(guest, 'mock').click();
		await expect.poll(async () => (await site.user(welcome)).exists, { message: 'the open social door made no account' }).toBe(true);
	});

	test('WordPress’s own form: the box is WordPress’s switch, and wp-login.php follows it', async ({ page, guest, site, options }) => {
		await options.set({ diluxone_users_login_method: 'both', diluxone_users_wp_screens: 'auto', users_can_register: 0 });

		await page.goto(accessTab('register'));
		await expect(control(page, 'diluxone_users_wp_register')).toBeEnabled();
		await control(page, 'diluxone_users_register_open', 'open').check();
		await control(page, 'diluxone_users_wp_register').check();
		await savePanel(page);

		expect(Number((await site.getOptions(['users_can_register'])).users_can_register)).toBe(1);

		await page.reload();
		await expect(control(page, 'diluxone_users_wp_register')).toBeChecked();

		await page.goto('/wp-admin/options-general.php');
		await expect(page.locator('#users_can_register'), 'Settings › General is the same switch').toBeChecked();

		await guest.goto('/wp-login.php?action=register');
		await expect(guest.locator('#registerform')).toBeVisible();

		await page.goto(accessTab('register'));
		await control(page, 'diluxone_users_wp_register').uncheck();
		await savePanel(page);

		expect(Number((await site.getOptions(['users_can_register'])).users_can_register)).toBe(0);

		await guest.goto('/wp-login.php?action=register');
		await expect(guest.locator('#registerform')).toHaveCount(0);
	});

	/*
	 * With the link as the only way in, WordPress's form would hand out a
	 * password nobody can use: the box is locked, says why, and a save of the
	 * tab leaves WordPress's own stored answer alone — the lock says no on its
	 * own, and the day a password is a way in again the old answer is back.
	 */
	test('WordPress’s own form is locked while the link is the only way in, and the save leaves its answer alone', async ({ page, guest, site, options }) => {
		await options.set({ diluxone_users_login_method: 'link', users_can_register: 1, diluxone_users_login_register: 1 });

		await page.goto(accessTab('register'));

		await expect(control(page, 'diluxone_users_wp_register')).toBeDisabled();
		await expectPill(card(page, 'diluxone_users_wp_register'), 'off');
		await expect(control(page, 'diluxone_users_wp_register'), 'the lock reads as closed').not.toBeChecked();

		await control(page, 'diluxone_users_register_open', 'open').check();
		await savePanel(page);

		// While locked, WordPress reads it as closed.
		expect(Number((await site.getOptions(['users_can_register'])).users_can_register)).toBe(0);
		await guest.goto('/wp-login.php?action=register');
		await expect(guest.locator('#registerform')).toHaveCount(0);

		// Settings › General says so, next to its own box, with the way here.
		await page.goto('/wp-admin/options-general.php');
		await expect(page.locator('.notice-info a[href*="page=diluxone-users-login"][href*="tab=register"]')).toHaveCount(1);
		await expect(page.locator('#users_can_register')).not.toBeChecked();

		// The lock goes with its reason, and the answer stored under it is the
		// one that was there before the save.
		await options.set({ diluxone_users_login_method: 'both' });
		expect(Number((await site.getOptions(['users_can_register'])).users_can_register), 'the save wrote over the answer under the lock').toBe(1);

		await page.goto('/wp-admin/options-general.php');
		await expect(page.locator('.notice-info a[href*="tab=register"]'), 'the lock notice outlived the lock').toHaveCount(0);
		await expect(page.locator('#users_can_register')).toBeChecked();
	});

	test('the notes on the doors say what is missing, and the rail what the form asks for', async ({ page, options }) => {
		await options.set({
			diluxone_users_login_method: 'password',
			diluxone_e2e_sso: 0,
			diluxone_users_sso: {},
			diluxone_users_register_form: 1,
			diluxone_users_register_page: 0,
			diluxone_users_login_register: 1,
			diluxone_users_fields: [
				...PLAIN_FIELDS,
				{ key: 'e2e_club', label: 'E2E Club', type: 'text', required: 1, active: 1, group: 'main', edit: 'always' },
			],
		});

		await page.goto(accessTab('register'));

		// No link way in: the link door waits on it.
		await expectPill(card(page, 'diluxone_users_login_register'), 'pending');
		// No provider working: the social door waits on one.
		await expectPill(card(page, 'diluxone_users_sso_register'), 'pending');
		// The form with no page: its card and the line under the page say so.
		await expectPill(card(page, 'diluxone_users_register_form'), 'pending');
		await expect(page.locator('.du-choice-group').filter({ has: control(page, 'diluxone_users_register_form') }).locator('.diluxone-users-not-now')).toHaveCount(1);
		await expect(page.locator('a.button[href*="action=diluxone_users_create_page"][href*="diluxone_users_register_page"]')).toHaveCount(1);

		// The rail names the required field the form will ask for.
		await expect(rail(page)).toContainText('E2E Club');

		// With a page and the link back, the doors stop waiting.
		await options.set({ diluxone_users_login_method: 'both' });
		await page.reload();
		await expect(card(page, 'diluxone_users_login_register').locator('.diluxone-users-state')).toHaveCount(0);
		await expect(notNow(page)).toHaveCount(1);
	});

	/*
	 * Open with nothing ticked is refused (admin-settings.spec.ts). The other
	 * half of that rule is the sentence beside the group: hidden until it is
	 * true, shown by the press, and gone the moment one box is ticked again,
	 * before anything is pressed.
	 */
	test('open with every door unticked: the sentence shows on the press and goes with the first tick', async ({ page, site, options }) => {
		await options.set({ diluxone_users_login_register: 1, diluxone_users_sso_register: 0, diluxone_users_register_form: 0, users_can_register: 0 });

		await page.goto(accessTab('register'));

		const group = needsOne(page, 'diluxone_users_login_register');
		const said = group.locator('[data-diluxone-users-atleast-one-said]');

		await expect(said).toBeHidden();
		await control(page, 'diluxone_users_login_register').uncheck();
		await page.locator('[data-diluxone-users-save] .du-save__button').first().click();

		await expect(group).toHaveClass(/is-short/);
		await expect(said).toBeVisible();
		await expect(page, 'the press left the page').toHaveURL(/tab=register/);
		await expect(adminSaved(page)).toHaveCount(0);

		await control(page, 'diluxone_users_register_form').check();
		await expect(group).not.toHaveClass(/is-short/);
		await expect(said).toBeHidden();

		expect(Number((await site.getOptions(['diluxone_users_register_form'])).diluxone_users_register_form), 'nothing was saved by the blocked press').toBe(0);
		await expect(adminError(page)).toHaveCount(0);
	});
});
